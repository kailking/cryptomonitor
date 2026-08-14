<?php

namespace Tests\Unit;

use App\Model\PermissionChangeLog;
use App\Model\UserPermission;
use App\Model\Users;
use App\Services\PermissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use ReflectionClass;
use Tests\TestCase;

class PermissionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertIsolatedDatabaseTarget();
        DB::table('permission_change_logs')->delete();
        DB::table('user_permissions')->delete();
        DB::table('users')->delete();

        DB::table('users')->insert([
            ['id' => 31, 'account' => 'root-admin', 'pwd' => 'test-password', 'is_admin' => 1],
            ['id' => 32, 'account' => 'permission-admin', 'pwd' => 'test-password', 'is_admin' => 1],
            ['id' => 40, 'account' => 'target-user', 'pwd' => 'test-password', 'is_admin' => 0],
        ]);
        $this->insertGrant(31, 'permissions.manage');
    }

    public function test_catalog_is_the_exact_approved_fourteen_permission_contract(): void
    {
        $expected = [
            'quotation.profit.view' => ['查看主表盈亏', 'quotation', 'display', [], false],
            'users.view' => ['查看用户', 'users', 'page', [], true],
            'users.create' => ['创建用户', 'users', 'action', ['users.view'], true],
            'users.edit' => ['编辑用户', 'users', 'action', ['users.view'], true],
            'users.renew' => ['用户续费', 'users', 'action', ['users.view'], true],
            'users.force_logout' => ['强制用户下线', 'users', 'action', ['users.view'], true],
            'settings.market.view' => ['查看行情配置', 'settings', 'page', [], true],
            'settings.market.update' => ['修改行情配置', 'settings', 'action', ['settings.market.view'], true],
            'system.logs.view' => ['查看系统日志', 'system', 'page', [], true],
            'system.server.view' => ['查看服务器管理', 'system', 'page', [], true],
            'system.server.restart' => ['重启全部行情服务', 'system', 'action', ['system.server.view'], true],
            'system.platform.restart' => ['重启单个平台服务', 'system', 'action', ['system.server.view'], true],
            'platform.address.configure' => ['配置平台钱包地址', 'platform', 'action', [], true],
            'permissions.manage' => ['管理用户权限', 'permissions', 'page', [], true],
        ];

        $catalog = config('permissions.catalog');

        $this->assertSame(31, config('permissions.root_user_id'));
        $this->assertIsArray($catalog);
        $this->assertSame(array_keys($expected), array_keys($catalog));

        foreach ($expected as $code => $metadata) {
            $this->assertSame(
                [
                    'name' => $metadata[0],
                    'group' => $metadata[1],
                    'type' => $metadata[2],
                    'depends_on' => $metadata[3],
                    'sensitive' => $metadata[4],
                ],
                $catalog[$code],
                $code
            );
        }

        $this->assertSame(
            ['quotation.profit.view'],
            array_keys(array_filter($catalog, function (array $permission): bool {
                return $permission['sensitive'] === false;
            }))
        );
    }

    public function test_permissions_are_empty_by_default_and_is_admin_is_not_a_bypass(): void
    {
        $admin = Users::findOrFail(32);

        $this->assertSame([], $admin->permissionCodes());
        $this->assertFalse($admin->hasPermission('permissions.manage'));

        DB::table('user_permissions')->insert([
            'user_id' => 32,
            'permission_code' => 'quotation.profit.view',
            'granted_by' => 31,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(['quotation.profit.view'], $admin->permissionCodes());
        $this->assertTrue($admin->hasPermission('quotation.profit.view'));
        $this->assertFalse($admin->hasPermission('users.view'));
    }

    public function test_permission_models_map_only_the_approved_writable_fields_and_audit_is_append_only(): void
    {
        $grant = new UserPermission();
        $audit = new PermissionChangeLog();
        $auditReflection = new ReflectionClass($audit);

        $this->assertSame('user_permissions', $grant->getTable());
        $this->assertSame(['user_id', 'permission_code', 'granted_by'], $grant->getFillable());
        $this->assertSame('permission_change_logs', $audit->getTable());
        $this->assertSame(
            [
                'target_user_id',
                'target_account',
                'permission_code',
                'action',
                'operator_user_id',
                'operator_account',
            ],
            $audit->getFillable()
        );
        $this->assertNull($audit->getUpdatedAtColumn());
        $this->assertFalse($auditReflection->hasMethod('updateAudit'));
        $this->assertFalse($auditReflection->hasMethod('deleteAudit'));
        $this->assertNotSame(
            PermissionChangeLog::class,
            $auditReflection->getMethod('update')->getDeclaringClass()->getName()
        );
        $this->assertNotSame(
            PermissionChangeLog::class,
            $auditReflection->getMethod('delete')->getDeclaringClass()->getName()
        );
    }

    public function test_permission_audit_rejects_instance_update_and_preserves_the_row(): void
    {
        $audit = $this->createAuditLog();

        try {
            $audit->update(['target_account' => 'tampered']);
            $this->fail('Expected append-only update rejection.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->assertSame('target-user', $audit->fresh()->target_account);
    }

    public function test_permission_audit_rejects_dirty_save_and_preserves_the_row(): void
    {
        $audit = $this->createAuditLog();
        $audit->target_account = 'tampered';

        try {
            $audit->save();
            $this->fail('Expected append-only save rejection.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->assertSame('target-user', $audit->fresh()->target_account);
    }

    public function test_permission_audit_rejects_instance_delete_and_preserves_the_row(): void
    {
        $audit = $this->createAuditLog();

        try {
            $audit->delete();
            $this->fail('Expected append-only delete rejection.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->assertTrue($audit->fresh()->exists);
    }

    public function test_normalization_adds_dependencies_and_returns_a_deterministic_unique_set(): void
    {
        $normalized = $this->service()->normalizeRequestedPermissions(
            [],
            ['users.edit', 'quotation.profit.view', 'users.edit']
        );

        $this->assertSame(
            ['quotation.profit.view', 'users.edit', 'users.view'],
            $normalized
        );
    }

    public function test_explicit_parent_removal_cascades_even_when_the_child_is_requested(): void
    {
        $normalized = $this->service()->normalizeRequestedPermissions(
            ['users.edit', 'users.view'],
            ['users.edit']
        );

        $this->assertSame([], $normalized);
    }

    public function test_extreme_config_permission_adds_view_and_is_removed_with_view(): void
    {
        $granted = $this->service()->normalizeRequestedPermissions(
            [],
            ['quotation.extreme.config']
        );

        $this->assertSame(
            ['quotation.extreme.config', 'quotation.extreme.view'],
            $granted
        );

        $revoked = $this->service()->normalizeRequestedPermissions(
            $granted,
            ['quotation.extreme.config']
        );

        $this->assertSame([], $revoked);
    }

    public function test_dependency_addition_is_recursive_and_cycle_safe(): void
    {
        $catalog = config('permissions.catalog');
        $catalog['users.edit']['depends_on'] = ['settings.market.update'];
        $catalog['settings.market.view']['depends_on'] = ['users.edit'];
        config()->set('permissions.catalog', $catalog);

        $normalized = $this->service()->normalizeRequestedPermissions([], ['users.edit']);

        $this->assertSame(
            ['settings.market.update', 'settings.market.view', 'users.edit'],
            $normalized
        );
    }

    public function test_dependency_removal_is_recursive(): void
    {
        $catalog = config('permissions.catalog');
        $catalog['users.edit']['depends_on'] = ['users.view'];
        $catalog['users.renew']['depends_on'] = ['users.edit'];
        config()->set('permissions.catalog', $catalog);

        $normalized = $this->service()->normalizeRequestedPermissions(
            ['users.view', 'users.edit', 'users.renew'],
            ['users.edit', 'users.renew']
        );

        $this->assertSame([], $normalized);
    }

    public function test_dependency_removal_is_cycle_safe(): void
    {
        $catalog = config('permissions.catalog');
        $catalog['users.view']['depends_on'] = ['users.edit'];
        $catalog['users.edit']['depends_on'] = ['users.view'];
        config()->set('permissions.catalog', $catalog);

        $normalized = $this->service()->normalizeRequestedPermissions(
            ['users.view', 'users.edit'],
            ['users.edit']
        );

        $this->assertSame([], $normalized);
    }

    public function test_unknown_permission_codes_are_rejected(): void
    {
        try {
            $this->service()->normalizeRequestedPermissions([], ['users.view', 'unknown.code']);
            $this->fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['包含未知权限码：unknown.code'],
                $exception->errors()['permissions']
            );
        }
    }

    public function test_unknown_configured_dependency_fails_closed_during_normalization(): void
    {
        $catalog = config('permissions.catalog');
        $catalog['users.edit']['depends_on'] = ['ghost.permission'];
        config()->set('permissions.catalog', $catalog);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ghost.permission');

        $this->service()->normalizeRequestedPermissions([], ['users.edit']);
    }

    public function test_unknown_configured_dependency_fails_closed_even_when_not_requested(): void
    {
        $catalog = config('permissions.catalog');
        $catalog['users.edit']['depends_on'] = ['ghost.permission'];
        config()->set('permissions.catalog', $catalog);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ghost.permission');

        $this->service()->normalizeRequestedPermissions([], []);
    }

    public function test_unknown_configured_dependency_cannot_create_grants_or_audit(): void
    {
        $catalog = config('permissions.catalog');
        $catalog['users.edit']['depends_on'] = ['ghost.permission'];
        config()->set('permissions.catalog', $catalog);

        try {
            $this->service()->savePermissions(
                Users::findOrFail(31),
                Users::findOrFail(40),
                ['users.edit']
            );
            $this->fail('Expected unknown configured dependency rejection.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('ghost.permission', $exception->getMessage());
        }

        $this->assertSame(
            0,
            DB::table('user_permissions')->where('user_id', 40)->count()
        );
        $this->assertSame(0, DB::table('permission_change_logs')->count());
    }

    public function test_non_root_cannot_add_permission_management(): void
    {
        $this->insertGrant(32, 'permissions.manage');
        $this->expectException(AuthorizationException::class);

        $this->service()->savePermissions(
            Users::findOrFail(32),
            Users::findOrFail(40),
            ['permissions.manage']
        );
    }

    public function test_non_root_cannot_revoke_permission_management(): void
    {
        $this->insertGrant(32, 'permissions.manage');
        $this->insertGrant(40, 'permissions.manage');
        $this->expectException(AuthorizationException::class);

        $this->service()->savePermissions(
            Users::findOrFail(32),
            Users::findOrFail(40),
            []
        );
    }

    public function test_non_root_can_preserve_permission_management_while_changing_business_permissions(): void
    {
        $this->insertGrant(32, 'permissions.manage');
        $this->insertGrant(40, 'permissions.manage');

        $result = $this->service()->savePermissions(
            Users::findOrFail(32),
            Users::findOrFail(40),
            ['permissions.manage', 'quotation.profit.view']
        );

        $this->assertSame(
            ['permissions.manage', 'quotation.profit.view'],
            $result['normalized']
        );
    }

    public function test_root_can_grant_and_revoke_permission_management_for_a_non_root_target(): void
    {
        $root = Users::findOrFail(31);
        $target = Users::findOrFail(40);

        $granted = $this->service()->savePermissions(
            $root,
            $target,
            ['permissions.manage']
        );
        $revoked = $this->service()->savePermissions($root, $target, []);

        $this->assertSame(['permissions.manage'], $granted['normalized']);
        $this->assertSame([], $revoked['normalized']);
    }

    public function test_root_cannot_remove_its_own_permission_management(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->service()->savePermissions(
            Users::findOrFail(31),
            Users::findOrFail(31),
            []
        );
    }

    public function test_non_root_cannot_submit_any_permission_set_for_the_root_target(): void
    {
        $this->insertGrant(32, 'permissions.manage');
        $this->expectException(AuthorizationException::class);

        $this->service()->savePermissions(
            Users::findOrFail(32),
            Users::findOrFail(31),
            ['permissions.manage', 'quotation.profit.view']
        );
    }

    public function test_root_id_without_database_manage_grant_is_rejected(): void
    {
        DB::table('user_permissions')
            ->where('user_id', 31)
            ->where('permission_code', 'permissions.manage')
            ->delete();
        $this->expectException(AuthorizationException::class);

        $this->service()->savePermissions(
            Users::findOrFail(31),
            Users::findOrFail(40),
            ['quotation.profit.view']
        );
    }

    private function service(): PermissionService
    {
        return app(PermissionService::class);
    }

    private function insertGrant(int $userId, string $permissionCode): void
    {
        DB::table('user_permissions')->insert([
            'user_id' => $userId,
            'permission_code' => $permissionCode,
            'granted_by' => 31,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAuditLog(): PermissionChangeLog
    {
        return PermissionChangeLog::create([
            'target_user_id' => 40,
            'target_account' => 'target-user',
            'permission_code' => 'users.view',
            'action' => 'grant',
            'operator_user_id' => 31,
            'operator_account' => 'root-admin',
        ]);
    }

    private function assertIsolatedDatabaseTarget(): void
    {
        $mysql = config('database.connections.mysql');
        $isIsolated = app()->environment('testing')
            && config('database.default') === 'mysql'
            && (string) $mysql['url'] === ''
            && $mysql['host'] === 'mysql'
            && (string) $mysql['port'] === '3306'
            && $mysql['database'] === 'tool_permissions_test'
            && $mysql['username'] === 'test_runner'
            && (string) $mysql['unix_socket'] === '';

        if (!$isIsolated) {
            throw new \RuntimeException(
                'Refusing destructive permission service test outside the isolated database.'
            );
        }
    }
}
