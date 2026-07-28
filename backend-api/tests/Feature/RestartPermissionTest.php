<?php

namespace Tests\Feature;

use App\Model\SystemLog;
use App\Service\RedisService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RestartPermissionTest extends TestCase
{
    private const ROOT_USER_ID = 31;
    private const LEGACY_ADMIN_USER_ID = 40;
    private const SERVER_OPERATOR_USER_ID = 41;
    private const PLATFORM_OPERATOR_USER_ID = 42;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertIsolatedServiceTargets();
        $this->clearTestState();
        $this->createSystemLogFixture();
        DB::table('permission_change_logs')->delete();
        DB::table('user_permissions')->delete();
        DB::table('users')->delete();

        DB::table('users')->insert([
            $this->userRow(self::ROOT_USER_ID, 'root-admin', 1),
            $this->userRow(self::LEGACY_ADMIN_USER_ID, 'legacy-admin', 1),
            $this->userRow(self::SERVER_OPERATOR_USER_ID, 'server-operator', 0),
            $this->userRow(self::PLATFORM_OPERATOR_USER_ID, 'platform-operator', 0),
        ]);
        $this->insertGrant(
            self::SERVER_OPERATOR_USER_ID,
            'system.server.restart'
        );
        $this->insertGrant(
            self::SERVER_OPERATOR_USER_ID,
            'system.logs.view'
        );
        $this->insertGrant(
            self::PLATFORM_OPERATOR_USER_ID,
            'system.platform.restart'
        );
        $this->insertGrant(
            self::PLATFORM_OPERATOR_USER_ID,
            'system.logs.view'
        );
    }

    protected function tearDown(): void
    {
        $this->clearTestState();
        Schema::dropIfExists('system_log');

        parent::tearDown();
    }

    public function test_legacy_admin_without_server_restart_permission_gets_real_403(): void
    {
        $response = $this->postJson(
            '/api/setting/restart/server',
            [],
            $this->authHeaders(self::LEGACY_ADMIN_USER_ID)
        );

        $this->assertPermissionDenied($response);
        $this->assertRedisUntouched();
        $this->assertSame(0, DB::table('system_log')->count());
    }

    public function test_server_restart_permission_only_sets_global_flag_and_log(): void
    {
        $headers = $this->authHeaders(self::SERVER_OPERATOR_USER_ID);
        $response = $this->postJson(
            '/api/setting/restart/server',
            ['platform' => 2],
            $headers
        );

        $response->assertStatus(200);
        $response->assertJson(['type' => 'ok', 'code' => 200]);
        $this->assertSame('1', $this->redis()->get('restart_system'));
        $this->assertSame(0, $this->redis()->lLen('restart_platform'));
        $this->assertDatabaseHas('system_log', [
            'type' => 3,
            'remark' => '请求重启全部行情服务',
            'user_id' => self::SERVER_OPERATOR_USER_ID,
        ]);
        $this->assertRestartVisibleInLogList(
            $headers,
            self::SERVER_OPERATOR_USER_ID,
            'server-operator',
            3,
            '重启全部行情服务',
            '请求重启全部行情服务'
        );
    }

    public function test_server_permission_does_not_authorize_platform_restart(): void
    {
        $response = $this->postJson(
            '/api/setting/restart/platform',
            ['platform' => 2],
            $this->authHeaders(self::SERVER_OPERATOR_USER_ID)
        );

        $this->assertPermissionDenied($response);
        $this->assertRedisUntouched();
        $this->assertSame(0, DB::table('system_log')->count());
    }

    public function test_platform_permission_does_not_authorize_server_restart(): void
    {
        $response = $this->postJson(
            '/api/setting/restart/server',
            [],
            $this->authHeaders(self::PLATFORM_OPERATOR_USER_ID)
        );

        $this->assertPermissionDenied($response);
        $this->assertRedisUntouched();
        $this->assertSame(0, DB::table('system_log')->count());
    }

    public function test_platform_restart_permission_only_queues_known_platform_and_log(): void
    {
        $headers = $this->authHeaders(self::PLATFORM_OPERATOR_USER_ID);
        $response = $this->postJson(
            '/api/setting/restart/platform',
            ['platform' => 2],
            $headers
        );

        $response->assertStatus(200);
        $response->assertJson(['type' => 'ok', 'code' => 200]);
        $this->assertSame(0, $this->redis()->exists('restart_system'));
        $this->assertSame(['2'], $this->redis()->lRange('restart_platform', 0, -1));
        $this->assertDatabaseHas('system_log', [
            'type' => 4,
            'remark' => '请求重启平台：2',
            'user_id' => self::PLATFORM_OPERATOR_USER_ID,
        ]);
        $this->assertRestartVisibleInLogList(
            $headers,
            self::PLATFORM_OPERATOR_USER_ID,
            'platform-operator',
            4,
            '重启单个平台服务',
            '请求重启平台：2'
        );
    }

    public function test_system_log_declares_integer_restart_type_contract(): void
    {
        $this->assertTrue(defined(SystemLog::class.'::TYPE_RESTART_SERVER'));
        $this->assertTrue(defined(SystemLog::class.'::TYPE_RESTART_PLATFORM'));
        $this->assertSame(3, constant(SystemLog::class.'::TYPE_RESTART_SERVER'));
        $this->assertSame(4, constant(SystemLog::class.'::TYPE_RESTART_PLATFORM'));
    }

    public function test_unknown_system_log_type_accessor_uses_stable_fallback(): void
    {
        $log = new SystemLog();
        $log->setRawAttributes(['type' => 99]);

        $this->assertSame('未知类型', $log->type_text);
    }

    public function test_unknown_system_log_type_remains_visible_in_real_list(): void
    {
        DB::table('system_log')->insert([
            'type' => 99,
            'remark' => '未知类型历史日志',
            'user_id' => self::SERVER_OPERATOR_USER_ID,
            'created_at' => '2026-07-20 00:00:00',
        ]);

        $response = $this->getJson(
            '/api/system/log/list',
            $this->authHeaders(self::SERVER_OPERATOR_USER_ID)
        );

        $response->assertStatus(200);
        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame(99, $rows[0]['type']);
        $this->assertSame('未知类型', $rows[0]['type_text']);
        $this->assertSame('server-operator', $rows[0]['account']);
        $this->assertSame('未知类型历史日志', $rows[0]['remark']);
    }

    /**
     * @dataProvider restartEndpointProvider
     */
    public function test_log_failure_returns_500_without_touching_redis(
        string $uri,
        array $payload,
        int $userId
    ): void {
        $headers = $this->authHeaders($userId);
        Schema::drop('system_log');

        $response = $this->postJson($uri, $payload, $headers);

        $response->assertStatus(500);
        $this->assertRedisUntouched();
    }

    public function restartEndpointProvider(): array
    {
        return [
            'server' => [
                '/api/setting/restart/server',
                [],
                self::SERVER_OPERATOR_USER_ID,
            ],
            'platform' => [
                '/api/setting/restart/platform',
                ['platform' => 2],
                self::PLATFORM_OPERATOR_USER_ID,
            ],
        ];
    }

    public function test_redis_failure_returns_500_and_preserves_request_log(): void
    {
        $this->redis()->set('restart_platform', 'wrong-type');

        $response = $this->postJson(
            '/api/setting/restart/platform',
            ['platform' => 2],
            $this->authHeaders(self::PLATFORM_OPERATOR_USER_ID)
        );

        $response->assertStatus(500);
        $this->assertSame('wrong-type', $this->redis()->get('restart_platform'));
        $this->assertDatabaseHas('system_log', [
            'type' => 4,
            'remark' => '请求重启平台：2',
            'user_id' => self::PLATFORM_OPERATOR_USER_ID,
        ]);
    }

    /**
     * @dataProvider invalidPlatformProvider
     */
    public function test_platform_restart_rejects_missing_or_unknown_platform(
        array $payload
    ): void {
        $response = $this->postJson(
            '/api/setting/restart/platform',
            $payload,
            $this->authHeaders(self::PLATFORM_OPERATOR_USER_ID)
        );

        $response->assertStatus(422);
        $response->assertJson([
            'type' => 'error',
            'code' => 422,
            'message' => '平台参数无效',
        ]);
        $this->assertRedisUntouched();
        $this->assertSame(0, DB::table('system_log')->count());
    }

    public function invalidPlatformProvider(): array
    {
        return [
            'missing' => [[]],
            'unknown' => [['platform' => 999]],
            'inactive platform constant' => [['platform' => 6]],
            'malformed' => [['platform' => '2abc']],
        ];
    }

    private function userRow(int $id, string $account, int $isAdmin): array
    {
        return [
            'id' => $id,
            'account' => $account,
            'pwd' => 'test-password',
            'status' => 1,
            'expired_at' => '2026-12-31 23:59:59',
            'is_admin' => $isAdmin,
        ];
    }

    private function insertGrant(int $userId, string $permissionCode): void
    {
        DB::table('user_permissions')->insert([
            'user_id' => $userId,
            'permission_code' => $permissionCode,
            'granted_by' => self::ROOT_USER_ID,
            'created_at' => '2026-07-20 00:00:00',
            'updated_at' => '2026-07-20 00:00:00',
        ]);
    }

    private function createSystemLogFixture(): void
    {
        Schema::dropIfExists('system_log');
        Schema::create('system_log', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedTinyInteger('type');
            $table->string('remark', 255);
            $table->integer('user_id')->nullable();
            $table->dateTime('created_at')->nullable();
        });
    }

    private function assertRestartVisibleInLogList(
        array $headers,
        int $userId,
        string $account,
        int $type,
        string $typeText,
        string $remark
    ): void {
        $response = $this->getJson('/api/system/log/list', $headers);

        $response->assertStatus(200);
        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame($userId, $rows[0]['user_id']);
        $this->assertSame($account, $rows[0]['account']);
        $this->assertSame($type, $rows[0]['type']);
        $this->assertSame($typeText, $rows[0]['type_text']);
        $this->assertSame($remark, $rows[0]['remark']);
    }

    private function authHeaders(int $userId): array
    {
        return ['X-Token' => $this->issueToken($userId)];
    }

    private function issueToken(int $userId): string
    {
        $token = 'permission-task-6-restart-token-'.$userId;
        $redis = RedisService::getInstance(1);
        $redis->set($token, $userId);
        $redis->set('user_token_'.$userId, $token);

        return $token;
    }

    private function clearTestState(): void
    {
        $restartRedis = $this->redis();
        $restartRedis->del('restart_system');
        $restartRedis->del('restart_platform');

        $authRedis = RedisService::getInstance(1);
        foreach ([
            self::ROOT_USER_ID,
            self::LEGACY_ADMIN_USER_ID,
            self::SERVER_OPERATOR_USER_ID,
            self::PLATFORM_OPERATOR_USER_ID,
        ] as $userId) {
            $authRedis->del('permission-task-6-restart-token-'.$userId);
            $authRedis->del('user_token_'.$userId);
        }
    }

    private function redis(): \Redis
    {
        return RedisService::getInstance(0);
    }

    private function assertPermissionDenied($response): void
    {
        $response->assertStatus(403);
        $response->assertJson([
            'type' => 'error',
            'code' => 403,
            'message' => '当前账号无此操作权限',
        ]);
    }

    private function assertRedisUntouched(): void
    {
        $this->assertSame(0, $this->redis()->exists('restart_system'));
        $this->assertSame(0, $this->redis()->lLen('restart_platform'));
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
                'Refusing destructive restart test outside isolated services.'
            );
        }
    }
}
