<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Model\PermissionChangeLog;
use App\Model\UserPermission;
use App\Model\Users;
use App\Services\PermissionService;
use App\Support\CanonicalUserId;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PermissionController extends Controller
{
    private $permissionService;

    public function __construct(PermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    public function catalog()
    {
        $groups = [];
        $groupIndexes = [];

        foreach (config('permissions.catalog') as $permissionCode => $permission) {
            $group = $permission['group'];
            if (!array_key_exists($group, $groupIndexes)) {
                $groupIndexes[$group] = count($groups);
                $groups[] = [
                    'group' => $group,
                    'permissions' => [],
                ];
            }

            $groups[$groupIndexes[$group]]['permissions'][] = [
                'code' => $permissionCode,
                'name' => $permission['name'],
                'type' => $permission['type'],
                'depends_on' => array_values($permission['depends_on']),
                'sensitive' => (bool) $permission['sensitive'],
            ];
        }

        return $this->success($groups);
    }

    public function users(Request $request)
    {
        $unknownQueryError = $this->unknownQueryError(
            $request,
            ['account', 'page', 'page_size']
        );
        if ($unknownQueryError !== null) {
            return $unknownQueryError;
        }

        $validator = Validator::make($request->query(), [
            'account' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', Rule::in([10, 20, 50])],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $page = (int) $request->query('page', 1);
        $pageSize = (int) $request->query('page_size', 20);
        $query = Users::query()
            ->select(['id', 'account', 'remark', 'status', 'expired_at'])
            ->withCount('permissionGrants');

        $account = $request->query('account');
        if ($account !== null && $account !== '') {
            $query->where('account', 'like', '%'.$account.'%');
        }

        $paginator = $query
            ->orderBy('account')
            ->orderBy('id')
            ->paginate($pageSize, ['*'], 'page', $page);
        $rootId = (int) config('permissions.root_user_id');
        $rows = array_map(function (Users $user) use ($rootId): array {
            return [
                'id' => (int) $user->id,
                'account' => $user->account,
                'remark' => $user->remark,
                'status' => (int) $user->status,
                'expired_at' => $user->expired_at,
                'is_permission_root' => (int) $user->id === $rootId,
                'permission_count' => (int) $user->permission_grants_count,
            ];
        }, $paginator->items());

        return $this->success($this->paginationData($paginator, $rows));
    }

    public function show($id)
    {
        $id = CanonicalUserId::parse($id);
        if ($id === null) {
            return $this->error('用户ID参数无效', 422);
        }

        $user = Users::find($id);
        if (!$user) {
            return $this->error('找不到该用户', 404);
        }

        $grants = UserPermission::query()
            ->leftJoin('users as granter', 'granter.id', '=', 'user_permissions.granted_by')
            ->where('user_permissions.user_id', $user->id)
            ->orderBy('user_permissions.permission_code')
            ->get([
                'user_permissions.permission_code',
                'granter.account as granted_by_account',
                'user_permissions.updated_at',
            ])
            ->map(function ($grant): array {
                return [
                    'permission_code' => $grant->permission_code,
                    'granted_by_account' => $grant->granted_by_account,
                    'updated_at' => $grant->updated_at->format('Y-m-d H:i:s'),
                ];
            })
            ->all();

        return $this->success([
            'user' => [
                'id' => (int) $user->id,
                'account' => $user->account,
                'is_permission_root' =>
                    (int) $user->id === (int) config('permissions.root_user_id'),
            ],
            'permissions' => array_column($grants, 'permission_code'),
            'grants' => $grants,
        ]);
    }

    public function update(Request $request, $id)
    {
        $id = CanonicalUserId::parse($id);
        if ($id === null) {
            return $this->error('用户ID参数无效', 422);
        }

        $validator = Validator::make($request->all(), [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $target = Users::find($id);
        if (!$target) {
            return $this->error('找不到该用户', 404);
        }

        $actor = Users::find((int) $request->attributes->get('user_id'));
        if (!$actor) {
            return $this->error('当前账号无此操作权限', 403);
        }

        try {
            $result = $this->permissionService->savePermissions(
                $actor,
                $target,
                $request->input('permissions')
            );
        } catch (ValidationException $exception) {
            return $this->error(
                $exception->validator->errors()->first(),
                422
            );
        } catch (AuthorizationException $exception) {
            return $this->error($exception->getMessage(), 403);
        } catch (ModelNotFoundException $exception) {
            return $this->error('找不到该用户', 404);
        }

        return $this->success([
            'permissions' => $result['normalized'],
            'granted' => $result['granted'],
            'revoked' => $result['revoked'],
            'forced_logout' => $this->hasSensitiveDifference(
                $result['granted'],
                $result['revoked']
            ),
        ]);
    }

    public function logs(Request $request)
    {
        $unknownQueryError = $this->unknownQueryError($request, [
            'target_account',
            'operator_account',
            'permission_code',
            'action',
            'created_from',
            'created_to',
            'page',
            'page_size',
        ]);
        if ($unknownQueryError !== null) {
            return $unknownQueryError;
        }

        $validator = Validator::make($request->query(), [
            'target_account' => ['nullable', 'string', 'max:100'],
            'operator_account' => ['nullable', 'string', 'max:100'],
            'permission_code' => [
                'nullable',
                'string',
                Rule::in(array_keys(config('permissions.catalog'))),
            ],
            'action' => ['nullable', Rule::in(['grant', 'revoke'])],
            'created_from' => ['nullable', 'date', 'before_or_equal:created_to'],
            'created_to' => ['nullable', 'date', 'after_or_equal:created_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', Rule::in([10, 20, 50])],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $page = (int) $request->query('page', 1);
        $pageSize = (int) $request->query('page_size', 20);
        $createdFrom = $this->normalizeAuditDateTime(
            $request->query('created_from')
        );
        $createdTo = $this->normalizeAuditDateTime(
            $request->query('created_to')
        );
        $query = PermissionChangeLog::query();

        if ($request->query('target_account') !== null) {
            $query->where(
                'target_account',
                'like',
                '%'.$request->query('target_account').'%'
            );
        }
        if ($request->query('operator_account') !== null) {
            $query->where(
                'operator_account',
                'like',
                '%'.$request->query('operator_account').'%'
            );
        }
        if ($request->query('permission_code') !== null) {
            $query->where('permission_code', $request->query('permission_code'));
        }
        if ($request->query('action') !== null) {
            $query->where('action', $request->query('action'));
        }
        if ($createdFrom !== null) {
            $query->where('created_at', '>=', $createdFrom);
        }
        if ($createdTo !== null) {
            $query->where('created_at', '<=', $createdTo);
        }

        $paginator = $query
            ->orderByDesc('id')
            ->paginate($pageSize, [
                'id',
                'target_user_id',
                'target_account',
                'permission_code',
                'action',
                'operator_user_id',
                'operator_account',
                'created_at',
            ], 'page', $page);
        $rows = array_map(function (PermissionChangeLog $log): array {
            return [
                'id' => (int) $log->id,
                'target_user_id' => (int) $log->target_user_id,
                'target_account' => $log->target_account,
                'permission_code' => $log->permission_code,
                'action' => $log->action,
                'operator_user_id' => (int) $log->operator_user_id,
                'operator_account' => $log->operator_account,
                'created_at' => $log->created_at->format('Y-m-d H:i:s'),
            ];
        }, $paginator->items());

        return $this->success($this->paginationData($paginator, $rows));
    }

    private function normalizeAuditDateTime($value)
    {
        if ($value === null) {
            return null;
        }

        $timezone = config('app.timezone');

        return Carbon::parse($value, $timezone)
            ->setTimezone($timezone)
            ->format('Y-m-d H:i:s');
    }

    private function unknownQueryError(Request $request, array $allowed)
    {
        $unknown = array_values(array_diff(
            array_keys($request->query()),
            $allowed
        ));
        if ($unknown === []) {
            return null;
        }

        return $this->error(
            '不支持的查询参数：'.implode(', ', $unknown),
            422
        );
    }

    private function hasSensitiveDifference(array $granted, array $revoked): bool
    {
        $catalog = config('permissions.catalog');

        foreach (array_merge($granted, $revoked) as $permissionCode) {
            if (($catalog[$permissionCode]['sensitive'] ?? null) === true) {
                return true;
            }
        }

        return false;
    }

    private function paginationData($paginator, array $rows): array
    {
        return [
            'current_page' => (int) $paginator->currentPage(),
            'data' => $rows,
            'last_page' => (int) $paginator->lastPage(),
            'per_page' => (int) $paginator->perPage(),
            'total' => (int) $paginator->total(),
        ];
    }

    private function validationError($validator)
    {
        return $this->error($validator->errors()->first(), 422);
    }

    private function success(array $data)
    {
        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $data,
        ], 200);
    }

    private function error(string $message, int $status)
    {
        return response()->json([
            'code' => $status,
            'message' => $message,
            'data' => null,
        ], $status);
    }
}
