<?php

namespace Tests\Feature;

use App\Service\RedisService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The legacy response helper writes raw PHP headers.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UserInfoPermissionsTest extends TestCase
{
    private const ROOT_USER_ID = 31;
    private const GRANTED_USER_ID = 40;
    private const UNGRANTED_USER_ID = 41;
    private const LEGACY_ADMIN_USER_ID = 42;

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
                'expired_at' => '2026-12-31 23:59:59',
                'is_admin' => 1,
                'block_platform' => 'binance,okex',
            ],
            [
                'id' => self::GRANTED_USER_ID,
                'account' => 'granted-user',
                'pwd' => 'test-password',
                'expired_at' => '2026-11-30 23:59:59',
                'is_admin' => 0,
                'block_platform' => 'huobi',
            ],
            [
                'id' => self::UNGRANTED_USER_ID,
                'account' => 'ungranted-user',
                'pwd' => 'test-password',
                'expired_at' => null,
                'is_admin' => 0,
                'block_platform' => null,
            ],
            [
                'id' => self::LEGACY_ADMIN_USER_ID,
                'account' => 'legacy-admin',
                'pwd' => 'test-password',
                'expired_at' => '2027-01-31 00:00:00',
                'is_admin' => 1,
                'block_platform' => 'gate',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->clearTestTokens();

        parent::tearDown();
    }

    public function test_granted_user_receives_sorted_database_permission_snapshot(): void
    {
        $this->insertGrant(self::GRANTED_USER_ID, 'users.view');
        $this->insertGrant(self::GRANTED_USER_ID, 'quotation.profit.view');

        $this->assertUserInfo(self::GRANTED_USER_ID, [
            'block_platform' => 'huobi',
            'roles' => ['editor'],
            'name' => 'granted-user',
            'expired_at' => '2026-11-30 23:59:59',
            'permissions' => ['quotation.profit.view', 'users.view'],
            'is_permission_root' => false,
        ]);
    }

    public function test_ungranted_user_receives_an_empty_permission_snapshot(): void
    {
        $this->assertUserInfo(self::UNGRANTED_USER_ID, [
            'block_platform' => null,
            'roles' => ['editor'],
            'name' => 'ungranted-user',
            'expired_at' => null,
            'permissions' => [],
            'is_permission_root' => false,
        ]);
    }

    public function test_permission_root_flag_does_not_imply_permission_grants(): void
    {
        $this->assertUserInfo(self::ROOT_USER_ID, [
            'block_platform' => 'binance,okex',
            'roles' => ['admin'],
            'name' => 'root-admin',
            'expired_at' => '2026-12-31 23:59:59',
            'permissions' => [],
            'is_permission_root' => true,
        ]);
    }

    public function test_legacy_admin_role_does_not_imply_permission_grants(): void
    {
        $this->assertUserInfo(self::LEGACY_ADMIN_USER_ID, [
            'block_platform' => 'gate',
            'roles' => ['admin'],
            'name' => 'legacy-admin',
            'expired_at' => '2027-01-31 00:00:00',
            'permissions' => [],
            'is_permission_root' => false,
        ]);
    }

    private function assertUserInfo(int $userId, array $data): void
    {
        $response = $this->getJson(
            '/api/user/info',
            ['X-Token' => $this->issueToken($userId)]
        );

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $response->assertExactJson([
            'type' => 'ok',
            'code' => 200,
            'message' => 'success',
            'data' => $data,
        ]);
    }

    private function insertGrant(int $userId, string $permissionCode): void
    {
        DB::table('user_permissions')->insert([
            'user_id' => $userId,
            'permission_code' => $permissionCode,
            'granted_by' => self::ROOT_USER_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function issueToken(int $userId): string
    {
        $token = 'permission-task-7-user-info-token-'.$userId;
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
            self::GRANTED_USER_ID,
            self::UNGRANTED_USER_ID,
            self::LEGACY_ADMIN_USER_ID,
        ] as $userId) {
            $redis->del('permission-task-7-user-info-token-'.$userId);
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
                'Refusing destructive user info test outside isolated services.'
            );
        }
    }
}
