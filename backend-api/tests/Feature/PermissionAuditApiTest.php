<?php

namespace Tests\Feature;

use App\Service\RedisService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class PermissionAuditApiTest extends TestCase
{
    private const ROOT_USER_ID = 31;
    private const MANAGER_USER_ID = 40;
    private const LEGACY_ADMIN_USER_ID = 41;
    private const TARGET_USER_ID = 50;
    private const DELETED_USER_ID = 70;

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
                'is_admin' => 1,
            ],
            [
                'id' => self::MANAGER_USER_ID,
                'account' => 'permission-manager',
                'pwd' => 'test-password',
                'is_admin' => 0,
            ],
            [
                'id' => self::LEGACY_ADMIN_USER_ID,
                'account' => 'legacy-admin',
                'pwd' => 'test-password',
                'is_admin' => 1,
            ],
            [
                'id' => self::TARGET_USER_ID,
                'account' => 'cat2',
                'pwd' => 'test-password',
                'is_admin' => 0,
            ],
        ]);

        DB::table('user_permissions')->insert([
            'user_id' => self::MANAGER_USER_ID,
            'permission_code' => 'permissions.manage',
            'granted_by' => self::ROOT_USER_ID,
            'created_at' => '2026-07-20 00:00:00',
            'updated_at' => '2026-07-20 00:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        $this->clearTestTokens();

        parent::tearDown();
    }

    public function test_audit_filters_use_closed_time_bounds_and_stable_descending_pagination(): void
    {
        $outsideBefore = $this->insertLog(
            'cat2',
            'permission-manager',
            'users.view',
            'grant',
            '2026-07-20 09:59:59'
        );
        $atStart = $this->insertLog(
            'cat2',
            'permission-manager',
            'permissions.manage',
            'grant',
            '2026-07-20 10:00:00'
        );
        $atEnd = $this->insertLog(
            'cat2',
            'permission-manager',
            'permissions.manage',
            'grant',
            '2026-07-20 11:00:00'
        );
        $outsideAfter = $this->insertLog(
            'cat2',
            'permission-manager',
            'permissions.manage',
            'grant',
            '2026-07-20 11:00:01'
        );
        $this->insertLog(
            'other-target',
            'permission-manager',
            'permissions.manage',
            'grant',
            '2026-07-20 10:30:00'
        );
        $this->insertLog(
            'cat2',
            'other-operator',
            'permissions.manage',
            'grant',
            '2026-07-20 10:30:00'
        );
        $this->insertLog(
            'cat2',
            'permission-manager',
            'permissions.manage',
            'revoke',
            '2026-07-20 10:30:00'
        );

        $query = http_build_query([
            'target_account' => 'cat2',
            'operator_account' => 'permission-manager',
            'permission_code' => 'permissions.manage',
            'action' => 'grant',
            'created_from' => '2026-07-20 10:00:00',
            'created_to' => '2026-07-20 11:00:00',
            'page' => 1,
            'page_size' => 10,
        ]);
        $response = $this->getJson(
            '/api/admin/permissions/logs?'.$query,
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame(['code', 'message', 'data'], array_keys($json));
        $this->assertSame(2, $json['data']['total']);
        $this->assertSame(
            [$atEnd, $atStart],
            array_column($json['data']['data'], 'id')
        );
        $this->assertNotContains(
            $outsideBefore,
            array_column($json['data']['data'], 'id')
        );
        $this->assertNotContains(
            $outsideAfter,
            array_column($json['data']['data'], 'id')
        );
        $this->assertSame(
            [
                'id',
                'target_user_id',
                'target_account',
                'permission_code',
                'action',
                'operator_user_id',
                'operator_account',
                'created_at',
            ],
            array_keys($json['data']['data'][0])
        );
        $this->assertSame(
            '2026-07-20 11:00:00',
            $json['data']['data'][0]['created_at']
        );
    }

    public function test_audit_account_filters_are_parameter_bound(): void
    {
        $this->insertLog(
            'cat2',
            'permission-manager',
            'users.view',
            'grant',
            '2026-07-20 10:00:00'
        );

        $response = $this->getJson(
            '/api/admin/permissions/logs?target_account='
            .urlencode("cat2' OR 1=1 --"),
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('data.total'));
    }

    /**
     * @dataProvider equivalentTimezoneBoundsProvider
     */
    public function test_timezone_bounds_are_converted_to_equivalent_application_instants(
        string $createdFrom,
        string $createdTo
    ): void {
        $outsideBefore = $this->insertLog(
            'cat2',
            'permission-manager',
            'permissions.manage',
            'grant',
            '2026-07-20 09:59:59'
        );
        $atStart = $this->insertLog(
            'cat2',
            'permission-manager',
            'permissions.manage',
            'grant',
            '2026-07-20 10:00:00'
        );
        $atEnd = $this->insertLog(
            'cat2',
            'permission-manager',
            'permissions.manage',
            'grant',
            '2026-07-20 11:00:00'
        );
        $outsideAfter = $this->insertLog(
            'cat2',
            'permission-manager',
            'permissions.manage',
            'grant',
            '2026-07-20 11:00:01'
        );

        $response = $this->getJson(
            '/api/admin/permissions/logs?'.http_build_query([
                'created_from' => $createdFrom,
                'created_to' => $createdTo,
                'page_size' => 10,
            ]),
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $response->assertStatus(200);
        $ids = array_column($response->json('data.data'), 'id');
        $this->assertSame([$atEnd, $atStart], $ids);
        $this->assertNotContains($outsideBefore, $ids);
        $this->assertNotContains($outsideAfter, $ids);
    }

    public function equivalentTimezoneBoundsProvider(): array
    {
        return [
            'UTC Z is converted to Asia Shanghai' => [
                '2026-07-20T02:00:00Z',
                '2026-07-20T03:00:00Z',
            ],
            'positive and negative offsets represent the same local bounds' => [
                '2026-07-20T10:00:00+08:00',
                '2026-07-19T22:00:00-05:00',
            ],
        ];
    }

    public function test_audit_pagination_crosses_pages_without_duplicates_or_omissions(): void
    {
        $insertedIds = [];
        for ($index = 0; $index < 12; $index++) {
            $insertedIds[] = $this->insertLog(
                'cat2',
                'permission-manager',
                'users.view',
                'grant',
                '2026-07-20 10:00:00'
            );
        }

        $first = $this->getJson(
            '/api/admin/permissions/logs?page=1&page_size=10',
            $this->authHeaders(self::MANAGER_USER_ID)
        );
        $second = $this->getJson(
            '/api/admin/permissions/logs?page=2&page_size=10',
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertSame(1, $first->json('data.current_page'));
        $this->assertSame(2, $second->json('data.current_page'));
        $this->assertSame(2, $first->json('data.last_page'));
        $this->assertSame(2, $second->json('data.last_page'));
        $this->assertSame(10, $first->json('data.per_page'));
        $this->assertSame(10, $second->json('data.per_page'));
        $this->assertSame(12, $first->json('data.total'));
        $this->assertSame(12, $second->json('data.total'));

        $firstIds = array_column($first->json('data.data'), 'id');
        $secondIds = array_column($second->json('data.data'), 'id');
        $this->assertSame(
            array_reverse(array_slice($insertedIds, 2)),
            $firstIds
        );
        $this->assertSame(
            array_reverse(array_slice($insertedIds, 0, 2)),
            $secondIds
        );
        $this->assertSame([], array_intersect($firstIds, $secondIds));
        $this->assertSame(
            array_reverse($insertedIds),
            array_merge($firstIds, $secondIds)
        );
    }

    public function test_audit_rejects_unknown_query_keys(): void
    {
        $response = $this->getJson(
            '/api/admin/permissions/logs?target_user_id=50',
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $response->assertStatus(422);
        $json = $response->json();
        $this->assertSame(['code', 'message', 'data'], array_keys($json));
        $this->assertSame(422, $json['code']);
        $this->assertNull($json['data']);
    }

    public function test_audit_snapshot_remains_visible_after_both_users_are_deleted(): void
    {
        DB::table('users')->insert([
            'id' => self::DELETED_USER_ID,
            'account' => 'deleted-target',
            'pwd' => 'test-password',
            'is_admin' => 0,
        ]);
        $logId = DB::table('permission_change_logs')->insertGetId([
            'target_user_id' => self::DELETED_USER_ID,
            'target_account' => 'deleted-target',
            'permission_code' => 'users.edit',
            'action' => 'grant',
            'operator_user_id' => self::ROOT_USER_ID,
            'operator_account' => 'deleted-operator',
            'created_at' => '2026-07-20 12:00:00',
        ]);
        DB::table('users')
            ->whereIn('id', [self::DELETED_USER_ID, self::ROOT_USER_ID])
            ->delete();

        $response = $this->getJson(
            '/api/admin/permissions/logs?target_account=deleted-target',
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $response->assertStatus(200);
        $this->assertSame($logId, $response->json('data.data.0.id'));
        $this->assertSame(
            'deleted-target',
            $response->json('data.data.0.target_account')
        );
        $this->assertSame(
            'deleted-operator',
            $response->json('data.data.0.operator_account')
        );
    }

    /**
     * @dataProvider invalidAuditQueryProvider
     */
    public function test_invalid_audit_queries_return_real_http_422(array $query): void
    {
        $response = $this->getJson(
            '/api/admin/permissions/logs?'.http_build_query($query),
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $response->assertStatus(422);
        $json = $response->json();
        $this->assertSame(['code', 'message', 'data'], array_keys($json));
        $this->assertSame(422, $json['code']);
        $this->assertNull($json['data']);
    }

    public function invalidAuditQueryProvider(): array
    {
        return [
            'unsupported action' => [['action' => 'delete']],
            'unknown dotted permission code' => [[
                'permission_code' => 'permissions.manage.unknown',
            ]],
            'unsupported page size' => [['page_size' => 25]],
            'invalid start time' => [['created_from' => 'not-a-date']],
            'invalid end time' => [['created_to' => 'not-a-date']],
        ];
    }

    public function test_audit_route_is_get_only_and_has_no_update_or_delete_route(): void
    {
        $matching = [];
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'api/admin/permissions/logs') {
                $matching[] = $route->methods();
            }
        }

        $this->assertSame([['GET', 'HEAD']], $matching);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_is_admin_does_not_bypass_a_missing_audit_grant(): void
    {
        $response = $this->getJson(
            '/api/admin/permissions/logs',
            $this->authHeaders(self::LEGACY_ADMIN_USER_ID)
        );

        $response->assertStatus(403);
        $response->assertJson(['code' => 403]);
    }

    private function insertLog(
        string $targetAccount,
        string $operatorAccount,
        string $permissionCode,
        string $action,
        string $createdAt
    ): int {
        return DB::table('permission_change_logs')->insertGetId([
            'target_user_id' => self::TARGET_USER_ID,
            'target_account' => $targetAccount,
            'permission_code' => $permissionCode,
            'action' => $action,
            'operator_user_id' => self::MANAGER_USER_ID,
            'operator_account' => $operatorAccount,
            'created_at' => $createdAt,
        ]);
    }

    private function authHeaders(int $userId): array
    {
        return ['X-Token' => $this->issueToken($userId)];
    }

    private function issueToken(int $userId): string
    {
        $token = 'permission-task-5-audit-token-'.$userId;
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
            self::DELETED_USER_ID,
        ] as $userId) {
            $redis->del('permission-task-5-audit-token-'.$userId);
            $redis->del('user_token_'.$userId);
        }
    }

    private function redis(): \Redis
    {
        return RedisService::getInstance(1);
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
                'Refusing destructive permission audit API test outside isolated services.'
            );
        }
    }
}
