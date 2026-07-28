<?php

namespace App\Services;

use App\Model\PermissionChangeLog;
use App\Model\UserPermission;
use App\Model\Users;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class PermissionService
{
    public function assertRootAccountMutationAllowed(
        int $actorId,
        array $targetIds
    ): void {
        $rootId = $this->rootUserId();

        if ($actorId !== $rootId && in_array($rootId, $targetIds, true)) {
            throw new AuthorizationException('根账号受保护');
        }
    }

    public function normalizeRequestedPermissions(array $current, array $requested): array
    {
        $catalogConfig = config('permissions.catalog');
        $this->assertCatalogDependenciesKnown($catalogConfig);
        $catalog = array_keys($catalogConfig);
        $unknown = array_values(array_diff($requested, $catalog));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'permissions' => ['包含未知权限码：'.implode(', ', $unknown)],
            ]);
        }

        $current = array_values(array_unique(array_intersect($current, $catalog)));
        $requested = array_values(array_unique($requested));
        $explicitlyRemoved = array_diff($current, $requested);

        foreach ($explicitlyRemoved as $parent) {
            $requested = $this->removeDependents($requested, $parent);
        }

        foreach ($requested as $permissionCode) {
            $requested = $this->addDependencies($requested, $permissionCode);
        }

        sort($requested);
        if (array_diff($requested, $catalog) !== []) {
            throw new LogicException(
                '权限归一化结果包含目录外权限码。'
            );
        }

        return $requested;
    }

    public function savePermissions(Users $actor, Users $target, array $requested): array
    {
        $actorId = (int) $actor->id;
        $targetId = (int) $target->id;
        $lockedTargetId = null;
        $result = DB::transaction(function () use (
            $actorId,
            $targetId,
            $requested,
            &$lockedTargetId
        ) {
            list($lockedActor, $lockedTarget) = $this->lockSaveUsers(
                $actorId,
                $targetId
            );
            $lockedTargetId = (int) $lockedTarget->id;

            $current = UserPermission::where('user_id', $lockedTarget->id)
                ->orderBy('permission_code')
                ->lockForUpdate()
                ->pluck('permission_code')
                ->all();

            $normalized = $this->normalizeRequestedPermissions($current, $requested);
            $this->assertPermissionManageChangeAllowed(
                $lockedActor,
                $current,
                $normalized
            );
            $this->assertRootTargetAllowed(
                $lockedActor,
                $lockedTarget,
                $normalized
            );

            $granted = array_values(array_diff($normalized, $current));
            $revoked = array_values(array_diff($current, $normalized));

            $this->insertGrantsAndLogs(
                $lockedActor,
                $lockedTarget,
                $granted
            );
            $this->deleteGrantsAndInsertLogs(
                $lockedActor,
                $lockedTarget,
                $revoked
            );

            return compact('normalized', 'granted', 'revoked');
        });

        if ($this->containsSensitiveDifference($result['granted'], $result['revoked'])) {
            Users::clearToken($lockedTargetId);
        }

        return $result;
    }

    private function lockSaveUsers(int $actorId, int $targetId): array
    {
        $userIds = array_values(array_unique([$actorId, $targetId]));
        sort($userIds, SORT_NUMERIC);
        $users = Users::whereIn('id', $userIds)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get()
            ->keyBy(function (Users $user): int {
                return (int) $user->id;
            });

        $lockedTarget = $users->get($targetId);
        if (!$lockedTarget) {
            $exception = new \Illuminate\Database\Eloquent\ModelNotFoundException();
            $exception->setModel(Users::class, [$targetId]);
            throw $exception;
        }

        $lockedActor = $users->get($actorId);
        if (!$lockedActor || !$lockedActor->hasPermission('permissions.manage')) {
            throw new AuthorizationException('当前账号无此操作权限。');
        }

        return [$lockedActor, $lockedTarget];
    }

    private function addDependencies(
        array $requested,
        string $permissionCode,
        array $visited = []
    ): array {
        if (isset($visited[$permissionCode])) {
            return $requested;
        }

        $visited[$permissionCode] = true;
        $catalog = config('permissions.catalog');
        $permission = $catalog[$permissionCode] ?? [];

        foreach ($permission['depends_on'] ?? [] as $dependency) {
            if (!array_key_exists($dependency, $catalog)) {
                throw new LogicException(
                    '权限配置包含未知依赖：'
                    .$permissionCode.' -> '.$dependency
                );
            }

            if (!in_array($dependency, $requested, true)) {
                $requested[] = $dependency;
            }

            $requested = $this->addDependencies($requested, $dependency, $visited);
        }

        return $requested;
    }

    private function removeDependents(
        array $requested,
        string $parent,
        array $visited = []
    ): array {
        if (isset($visited[$parent])) {
            return $requested;
        }

        $visited[$parent] = true;

        foreach (config('permissions.catalog') as $permissionCode => $permission) {
            if (!in_array($parent, $permission['depends_on'], true)) {
                continue;
            }

            $requested = array_values(array_diff($requested, [$permissionCode]));
            $requested = $this->removeDependents(
                $requested,
                $permissionCode,
                $visited
            );
        }

        return $requested;
    }

    private function assertPermissionManageChangeAllowed(
        Users $actor,
        array $current,
        array $normalized
    ): void {
        $currentHasPermission = in_array('permissions.manage', $current, true);
        $normalizedHasPermission = in_array('permissions.manage', $normalized, true);

        if (
            $currentHasPermission !== $normalizedHasPermission
            && (int) $actor->id !== $this->rootUserId()
        ) {
            throw new AuthorizationException(
                '只有根管理员可以新增或取消权限管理权限。'
            );
        }
    }

    private function assertRootTargetAllowed(
        Users $actor,
        Users $target,
        array $normalized
    ): void {
        if ((int) $target->id !== $this->rootUserId()) {
            return;
        }

        if (!in_array('permissions.manage', $normalized, true)) {
            throw new AuthorizationException('根管理员必须保留权限管理权限。');
        }

        if ((int) $actor->id !== $this->rootUserId()) {
            throw new AuthorizationException('非根管理员不能修改根管理员权限。');
        }
    }

    private function insertGrantsAndLogs(
        Users $actor,
        Users $target,
        array $granted
    ): void {
        foreach ($granted as $permissionCode) {
            UserPermission::create([
                'user_id' => $target->id,
                'permission_code' => $permissionCode,
                'granted_by' => $actor->id,
            ]);

            $this->insertAuditLog($actor, $target, $permissionCode, 'grant');
        }
    }

    private function deleteGrantsAndInsertLogs(
        Users $actor,
        Users $target,
        array $revoked
    ): void {
        foreach ($revoked as $permissionCode) {
            UserPermission::where('user_id', $target->id)
                ->where('permission_code', $permissionCode)
                ->delete();

            $this->insertAuditLog($actor, $target, $permissionCode, 'revoke');
        }
    }

    private function insertAuditLog(
        Users $actor,
        Users $target,
        string $permissionCode,
        string $action
    ): void {
        PermissionChangeLog::create([
            'target_user_id' => $target->id,
            'target_account' => $target->account,
            'permission_code' => $permissionCode,
            'action' => $action,
            'operator_user_id' => $actor->id,
            'operator_account' => $actor->account,
        ]);
    }

    private function containsSensitiveDifference(array $granted, array $revoked): bool
    {
        $catalog = config('permissions.catalog');

        foreach (array_merge($granted, $revoked) as $permissionCode) {
            if (($catalog[$permissionCode]['sensitive'] ?? null) === true) {
                return true;
            }
        }

        return false;
    }

    private function rootUserId(): int
    {
        return (int) config('permissions.root_user_id');
    }

    private function assertCatalogDependenciesKnown(array $catalog): void
    {
        foreach ($catalog as $permissionCode => $permission) {
            foreach ($permission['depends_on'] ?? [] as $dependency) {
                if (!array_key_exists($dependency, $catalog)) {
                    throw new LogicException(
                        '权限配置包含未知依赖：'
                        .$permissionCode.' -> '.$dependency
                    );
                }
            }
        }
    }
}
