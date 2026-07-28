<?php

namespace Tests\Feature;

use App\Service\RedisService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class PermissionApiTest extends TestCase
{
    private const ROOT_USER_ID = 31;
    private const MANAGER_USER_ID = 40;
    private const LEGACY_ADMIN_USER_ID = 41;
    private const TARGET_USER_ID = 50;
    private const SECOND_TARGET_USER_ID = 51;
    private const GRANTER_USER_ID = 60;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertIsolatedServiceTargets();
        $this->clearTestTokens();
        DB::table('permission_change_logs')->delete();
        DB::table('user_permissions')->delete();
        DB::table('users')->delete();

        DB::table('users')->insert([
            [
                'id' => self::ROOT_USER_ID,
                'account' => 'root-admin',
                'pwd' => 'test-password',
                'status' => 1,
                'expired_at' => '2026-12-31 23:59:59',
                'is_admin' => 1,
            ],
            [
                'id' => self::MANAGER_USER_ID,
                'account' => 'permission-manager',
                'pwd' => 'test-password',
                'status' => 1,
                'expired_at' => '2026-11-30 23:59:59',
                'is_admin' => 0,
            ],
            [
                'id' => self::LEGACY_ADMIN_USER_ID,
                'account' => 'legacy-admin',
                'pwd' => 'test-password',
                'status' => 1,
                'expired_at' => null,
                'is_admin' => 1,
            ],
            [
                'id' => self::TARGET_USER_ID,
                'account' => 'cat2',
                'pwd' => 'test-password',
                'status' => 2,
                'expired_at' => '2026-08-20 12:34:56',
                'is_admin' => 0,
            ],
            [
                'id' => self::SECOND_TARGET_USER_ID,
                'account' => 'cat20',
                'pwd' => 'test-password',
                'status' => 1,
                'expired_at' => null,
                'is_admin' => 0,
            ],
            [
                'id' => self::GRANTER_USER_ID,
                'account' => 'grant-operator',
                'pwd' => 'test-password',
                'status' => 1,
                'expired_at' => null,
                'is_admin' => 0,
            ],
        ]);

        $this->insertGrant(
            self::ROOT_USER_ID,
            'permissions.manage',
            self::ROOT_USER_ID
        );
        $this->insertGrant(
            self::MANAGER_USER_ID,
            'permissions.manage',
            self::ROOT_USER_ID
        );
    }

    protected function tearDown(): void
    {
        $this->clearTestTokens();

        parent::tearDown();
    }

    public function test_catalog_returns_the_fixed_config_group_and_permission_order(): void
    {
        $response = $this->getJson(
            '/api/admin/permissions/catalog',
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame(['code', 'message', 'data'], array_keys($json));
        $this->assertSame(200, $json['code']);
        $this->assertSame('success', $json['message']);
        $this->assertSame(
            [
                'quotation',
                'users',
                'settings',
                'system',
                'platform',
                'permissions',
            ],
            array_column($json['data'], 'group')
        );
        $this->assertSame(
            [
                ['quotation.profit.view'],
                [
                    'users.view',
                    'users.create',
                    'users.edit',
                    'users.renew',
                    'users.force_logout',
                ],
                ['settings.market.view', 'settings.market.update'],
                [
                    'system.logs.view',
                    'system.server.view',
                    'system.server.restart',
                    'system.platform.restart',
                ],
                ['platform.address.configure'],
                ['permissions.manage'],
            ],
            array_map(function (array $group): array {
                return array_column($group['permissions'], 'code');
            }, $json['data'])
        );
        $this->assertSame(
            [
                'code',
                'name',
                'type',
                'depends_on',
                'sensitive',
            ],
            array_keys($json['data'][0]['permissions'][0])
        );
        $this->assertFalse($json['data'][0]['permissions'][0]['sensitive']);
    }

    public function test_user_list_only_exposes_contract_fields_and_uses_account_search(): void
    {
        $this->insertGrant(
            self::TARGET_USER_ID,
            'users.view',
            self::GRANTER_USER_ID
        );
        $this->insertGrant(
            self::TARGET_USER_ID,
            'users.edit',
            self::GRANTER_USER_ID
        );

        $response = $this->getJson(
            '/api/admin/permissions/users?account=cat2&page=1',
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame(['code', 'message', 'data'], array_keys($json));
        $this->assertSame(20, $json['data']['per_page']);
        $this->assertSame(2, $json['data']['total']);
        $this->assertSame(['cat2', 'cat20'], array_column($json['data']['data'], 'account'));
        $this->assertSame(
            [
                'id',
                'account',
                'status',
                'expired_at',
                'is_permission_root',
                'permission_count',
            ],
            array_keys($json['data']['data'][0])
        );
        $this->assertSame(self::TARGET_USER_ID, $json['data']['data'][0]['id']);
        $this->assertSame(2, $json['data']['data'][0]['status']);
        $this->assertSame(
            '2026-08-20 12:34:56',
            $json['data']['data'][0]['expired_at']
        );
        $this->assertFalse($json['data']['data'][0]['is_permission_root']);
        $this->assertSame(2, $json['data']['data'][0]['permission_count']);
    }

    public function test_user_list_marks_only_the_configured_root_and_accepts_allowed_page_sizes(): void
    {
        $response = $this->getJson(
            '/api/admin/permissions/users?account=root-admin&page_size=10',
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame(10, $json['data']['per_page']);
        $this->assertCount(1, $json['data']['data']);
        $this->assertTrue($json['data']['data'][0]['is_permission_root']);
    }

    public function test_invalid_user_list_page_size_returns_real_http_422(): void
    {
        $response = $this->getJson(
            '/api/admin/permissions/users?page_size=25',
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $this->assertStrictError($response, 422);
    }

    public function test_user_list_rejects_unknown_query_keys(): void
    {
        $response = $this->getJson(
            '/api/admin/permissions/users?status=1',
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $this->assertStrictError($response, 422);
    }

    public function test_user_detail_returns_permissions_and_granter_accounts(): void
    {
        $this->insertGrant(
            self::TARGET_USER_ID,
            'users.view',
            self::GRANTER_USER_ID,
            '2026-07-20 01:02:03'
        );
        $this->insertGrant(
            self::TARGET_USER_ID,
            'quotation.profit.view',
            self::ROOT_USER_ID,
            '2026-07-20 01:03:04'
        );

        $response = $this->getJson(
            '/api/admin/permissions/users/'.self::TARGET_USER_ID,
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $response->assertStatus(200);
        $response->assertExactJson([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'user' => [
                    'id' => self::TARGET_USER_ID,
                    'account' => 'cat2',
                    'is_permission_root' => false,
                ],
                'permissions' => [
                    'quotation.profit.view',
                    'users.view',
                ],
                'grants' => [
                    [
                        'permission_code' => 'quotation.profit.view',
                        'granted_by_account' => 'root-admin',
                        'updated_at' => '2026-07-20 01:03:04',
                    ],
                    [
                        'permission_code' => 'users.view',
                        'granted_by_account' => 'grant-operator',
                        'updated_at' => '2026-07-20 01:02:03',
                    ],
                ],
            ],
        ]);
    }

    public function test_missing_user_detail_returns_real_http_404(): void
    {
        $response = $this->getJson(
            '/api/admin/permissions/users/999999',
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $this->assertStrictError($response, 404);
    }

    public function test_user_detail_keeps_a_grant_after_its_granter_is_deleted(): void
    {
        $this->insertGrant(
            self::TARGET_USER_ID,
            'users.view',
            self::GRANTER_USER_ID,
            '2026-07-20 01:02:03'
        );
        DB::table('users')->where('id', self::GRANTER_USER_ID)->delete();

        $response = $this->getJson(
            '/api/admin/permissions/users/'.self::TARGET_USER_ID,
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $response->assertStatus(200);
        $this->assertSame(
            'users.view',
            $response->json('data.grants.0.permission_code')
        );
        $this->assertNull($response->json('data.grants.0.granted_by_account'));
    }

    public function test_save_uses_the_permission_service_complete_set_and_reports_forced_logout(): void
    {
        $targetToken = $this->issueToken(self::TARGET_USER_ID);

        $response = $this->putJson(
            '/api/admin/permissions/users/'.self::TARGET_USER_ID,
            ['permissions' => ['users.edit']],
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $response->assertStatus(200);
        $response->assertExactJson([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'permissions' => ['users.edit', 'users.view'],
                'granted' => ['users.edit', 'users.view'],
                'revoked' => [],
                'forced_logout' => true,
            ],
        ]);
        $this->assertSame(
            ['users.edit', 'users.view'],
            DB::table('user_permissions')
                ->where('user_id', self::TARGET_USER_ID)
                ->orderBy('permission_code')
                ->pluck('permission_code')
                ->all()
        );
        $this->assertFalse((bool) $this->redis()->get(
            'user_token_'.self::TARGET_USER_ID
        ));
        $this->assertFalse(\App\Model\Users::checkToken($targetToken));
    }

    public function test_profit_only_save_reports_no_forced_logout_and_preserves_token(): void
    {
        $targetToken = $this->issueToken(self::TARGET_USER_ID);

        $response = $this->putJson(
            '/api/admin/permissions/users/'.self::TARGET_USER_ID,
            ['permissions' => ['quotation.profit.view']],
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.forced_logout'));
        $this->assertSame(
            $targetToken,
            $this->redis()->get('user_token_'.self::TARGET_USER_ID)
        );
        $this->assertSame(
            (string) self::TARGET_USER_ID,
            \App\Model\Users::checkToken($targetToken)
        );
    }

    public function test_empty_complete_set_revokes_the_last_permission(): void
    {
        $this->insertGrant(
            self::TARGET_USER_ID,
            'users.view',
            self::GRANTER_USER_ID
        );

        $response = $this->putJson(
            '/api/admin/permissions/users/'.self::TARGET_USER_ID,
            ['permissions' => []],
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $response->assertStatus(200);
        $response->assertExactJson([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'permissions' => [],
                'granted' => [],
                'revoked' => ['users.view'],
                'forced_logout' => true,
            ],
        ]);
        $this->assertSame(
            0,
            DB::table('user_permissions')
                ->where('user_id', self::TARGET_USER_ID)
                ->count()
        );
    }

    /**
     * @dataProvider invalidPermissionPayloadProvider
     */
    public function test_invalid_permission_payloads_return_real_http_422(array $payload): void
    {
        $response = $this->putJson(
            '/api/admin/permissions/users/'.self::TARGET_USER_ID,
            $payload,
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $this->assertStrictError($response, 422);
        $this->assertSame(
            0,
            DB::table('user_permissions')
                ->where('user_id', self::TARGET_USER_ID)
                ->count()
        );
    }

    public function invalidPermissionPayloadProvider(): array
    {
        return [
            'missing required array' => [[]],
            'not an array' => [['permissions' => 'users.view']],
            'non-string item' => [['permissions' => [123]]],
            'duplicate item' => [[
                'permissions' => ['users.view', 'users.view'],
            ]],
            'unknown raw dotted code' => [[
                'permissions' => ['users.view.unknown'],
            ]],
        ];
    }

    public function test_save_for_missing_target_returns_real_http_404(): void
    {
        $response = $this->putJson(
            '/api/admin/permissions/users/999999',
            ['permissions' => ['users.view']],
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $this->assertStrictError($response, 404);
    }

    public function test_non_root_manager_cannot_change_permissions_manage(): void
    {
        $response = $this->putJson(
            '/api/admin/permissions/users/'.self::TARGET_USER_ID,
            ['permissions' => ['permissions.manage']],
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $this->assertStrictError($response, 403);
        $this->assertSame(
            0,
            DB::table('user_permissions')
                ->where('user_id', self::TARGET_USER_ID)
                ->count()
        );
    }

    public function test_root_can_grant_and_revoke_permissions_manage_through_the_api(): void
    {
        $grant = $this->putJson(
            '/api/admin/permissions/users/'.self::TARGET_USER_ID,
            ['permissions' => ['permissions.manage']],
            $this->authHeaders(self::ROOT_USER_ID)
        );

        $grant->assertStatus(200);
        $grant->assertExactJson([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'permissions' => ['permissions.manage'],
                'granted' => ['permissions.manage'],
                'revoked' => [],
                'forced_logout' => true,
            ],
        ]);

        $revoke = $this->putJson(
            '/api/admin/permissions/users/'.self::TARGET_USER_ID,
            ['permissions' => []],
            $this->authHeaders(self::ROOT_USER_ID)
        );

        $revoke->assertStatus(200);
        $revoke->assertExactJson([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'permissions' => [],
                'granted' => [],
                'revoked' => ['permissions.manage'],
                'forced_logout' => true,
            ],
        ]);
    }

    public function test_non_root_can_preserve_existing_manage_while_changing_business_permissions(): void
    {
        $this->insertGrant(
            self::TARGET_USER_ID,
            'permissions.manage',
            self::ROOT_USER_ID
        );

        $response = $this->putJson(
            '/api/admin/permissions/users/'.self::TARGET_USER_ID,
            ['permissions' => [
                'permissions.manage',
                'quotation.profit.view',
            ]],
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $response->assertStatus(200);
        $response->assertExactJson([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'permissions' => [
                    'permissions.manage',
                    'quotation.profit.view',
                ],
                'granted' => ['quotation.profit.view'],
                'revoked' => [],
                'forced_logout' => false,
            ],
        ]);
    }

    public function test_permission_routes_have_authentication_before_raw_dotted_authorization(): void
    {
        foreach ($this->permissionRouteContract() as $contract) {
            $route = Route::getRoutes()->match(request()->create(
                $contract[1],
                $contract[0]
            ));
            $middleware = array_values($route->gatherMiddleware());
            $authIndex = array_search('check_api', $middleware, true);
            $permissionIndex = array_search(
                'check_permission:permissions.manage',
                $middleware,
                true
            );

            $this->assertNotFalse($authIndex, $contract[1]);
            $this->assertNotFalse($permissionIndex, $contract[1]);
            $this->assertLessThan($permissionIndex, $authIndex, $contract[1]);
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_authentication_runs_before_permission_authorization(): void
    {
        $response = $this->getJson('/api/admin/permissions/catalog');

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $response->assertJson(['code' => 50008]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_every_permission_endpoint_returns_real_403_without_the_database_grant(): void
    {
        $headers = $this->authHeaders(self::LEGACY_ADMIN_USER_ID);

        foreach ($this->permissionRouteContract() as $contract) {
            [$method, $uri] = $contract;
            $response = $this->json(
                $method,
                $uri,
                $method === 'PUT' ? ['permissions' => []] : [],
                $headers
            );

            $response->assertStatus(403);
            $response->assertJson(['code' => 403]);
        }
    }

    public function test_no_batch_permission_route_exists(): void
    {
        $uris = [];
        foreach (Route::getRoutes() as $route) {
            if (strpos($route->uri(), 'api/admin/permissions') === 0) {
                $uris[] = $route->uri();
            }
        }

        $this->assertNotContains('api/admin/permissions/users/batch', $uris);
        $this->assertNotContains('api/admin/permissions/batch', $uris);
    }

    /**
     * @dataProvider invalidPermissionUserIdProvider
     */
    public function test_permission_user_routes_reject_invalid_ids_before_side_effects(
        string $method,
        string $id
    ): void {
        $headers = $this->authHeaders(self::MANAGER_USER_ID);
        $this->issueToken(self::TARGET_USER_ID);
        $before = $this->permissionMutationSnapshot();

        $response = $this->json(
            $method,
            '/api/admin/permissions/users/'.$id,
            $method === 'PUT' ? ['permissions' => []] : [],
            $headers
        );

        $response->assertStatus(422);
        $response->assertExactJson([
            'code' => 422,
            'message' => '用户ID参数无效',
            'data' => null,
        ]);
        $this->assertSame($before, $this->permissionMutationSnapshot());
    }

    public function invalidPermissionUserIdProvider(): array
    {
        $ids = [
            'zero' => '0',
            'leading zero' => '00',
            'negative sign' => '-1',
            'non digits' => 'abc',
            'mixed digits' => '12abc',
            'database integer overflow' => '2147483648',
            'platform integer overflow' => (string) PHP_INT_MAX.'0',
            'thirty digits' => str_repeat('9', 30),
        ];
        $cases = [];

        foreach (['GET', 'PUT'] as $method) {
            foreach ($ids as $label => $id) {
                $cases[strtolower($method).' '.$label] = [$method, $id];
            }
        }

        return $cases;
    }

    public function test_permission_user_routes_accept_database_integer_max_before_404(): void
    {
        foreach (['GET', 'PUT'] as $method) {
            $headers = $this->authHeaders(self::MANAGER_USER_ID);
            $this->issueToken(self::TARGET_USER_ID);
            $before = $this->permissionMutationSnapshot();

            $response = $this->json(
                $method,
                '/api/admin/permissions/users/2147483647',
                $method === 'PUT' ? ['permissions' => []] : [],
                $headers
            );

            $this->assertStrictError($response, 404);
            $this->assertSame($before, $this->permissionMutationSnapshot());
        }
    }

    private function permissionRouteContract(): array
    {
        return [
            ['GET', '/api/admin/permissions/catalog'],
            ['GET', '/api/admin/permissions/users'],
            ['GET', '/api/admin/permissions/users/'.self::TARGET_USER_ID],
            ['PUT', '/api/admin/permissions/users/'.self::TARGET_USER_ID],
            ['GET', '/api/admin/permissions/logs'],
        ];
    }

    private function insertGrant(
        int $userId,
        string $permissionCode,
        int $grantedBy,
        string $updatedAt = '2026-07-20 00:00:00'
    ): void {
        DB::table('user_permissions')->insert([
            'user_id' => $userId,
            'permission_code' => $permissionCode,
            'granted_by' => $grantedBy,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    private function authHeaders(int $userId): array
    {
        return ['X-Token' => $this->issueToken($userId)];
    }

    private function issueToken(int $userId): string
    {
        $token = 'permission-task-5-api-token-'.$userId;
        $redis = $this->redis();
        $redis->set($token, $userId);
        $redis->set('user_token_'.$userId, $token);

        return $token;
    }

    private function clearTestTokens(): void
    {
        $redis = $this->redis();

        foreach ([
            self::ROOT_USER_ID,
            self::MANAGER_USER_ID,
            self::LEGACY_ADMIN_USER_ID,
            self::TARGET_USER_ID,
            self::SECOND_TARGET_USER_ID,
            self::GRANTER_USER_ID,
        ] as $userId) {
            $redis->del('permission-task-5-api-token-'.$userId);
            $redis->del('user_token_'.$userId);
        }
    }

    private function permissionMutationSnapshot(): array
    {
        return [
            'users' => DB::table('users')->orderBy('id')->get()->map(function ($row): array {
                return (array) $row;
            })->all(),
            'grants' => DB::table('user_permissions')
                ->orderBy('user_id')
                ->orderBy('permission_code')
                ->get()
                ->map(function ($row): array {
                    return (array) $row;
                })->all(),
            'audit' => DB::table('permission_change_logs')
                ->orderBy('id')
                ->get()
                ->map(function ($row): array {
                    return (array) $row;
                })->all(),
            'actor_token' => $this->redis()->get(
                'user_token_'.self::MANAGER_USER_ID
            ),
            'target_token' => $this->redis()->get(
                'user_token_'.self::TARGET_USER_ID
            ),
        ];
    }

    private function redis(): \Redis
    {
        return RedisService::getInstance(1);
    }

    private function assertStrictError($response, int $status): void
    {
        $response->assertStatus($status);
        $json = $response->json();
        $this->assertSame(['code', 'message', 'data'], array_keys($json));
        $this->assertSame($status, $json['code']);
        $this->assertIsString($json['message']);
        $this->assertNull($json['data']);
    }

    private function assertIsolatedServiceTargets(): void
    {
        $mysql = config('database.connections.mysql');
        $isIsolated = app()->environment('testing')
            && config('database.default') === 'mysql'
            && (string) $mysql['url'] === ''
            && $mysql['host'] === 'mysql'
            && (string) $mysql['port'] === '3306'
            && $mysql['database'] === 'tool_permissions_test'
            && $mysql['username'] === 'test_runner'
            && (string) $mysql['unix_socket'] === ''
            && (string) config('database.redis.default.url') === ''
            && config('database.redis.default.host') === 'redis'
            && (string) config('database.redis.default.port') === '6379'
            && (string) config('database.redis.default.password') === '';

        if (!$isIsolated) {
            throw new RuntimeException(
                'Refusing destructive permission API test outside isolated services.'
            );
        }
    }
}
