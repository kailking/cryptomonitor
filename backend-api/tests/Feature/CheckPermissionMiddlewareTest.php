<?php

namespace Tests\Feature;

use App\Service\RedisService;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use RuntimeException;
use Tests\TestCase;

class CheckPermissionMiddlewareTest extends TestCase
{
    private const REGULAR_USER_ID = 40;
    private const ADMIN_USER_ID = 41;

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
                'id' => 31,
                'account' => 'root-admin',
                'pwd' => 'test-password',
                'is_admin' => 1,
            ],
            [
                'id' => self::REGULAR_USER_ID,
                'account' => 'regular-user',
                'pwd' => 'test-password',
                'is_admin' => 0,
            ],
            [
                'id' => self::ADMIN_USER_ID,
                'account' => 'legacy-admin',
                'pwd' => 'test-password',
                'is_admin' => 1,
            ],
        ]);

        Route::middleware([
            'check_api',
            'check_permission:users.view',
        ])->get('/_test/permissions/users', function () {
            return response()->json([
                'user_id' => request()->attributes->get('user_id'),
            ]);
        });

        Route::middleware([
            'check_api',
            'check_permission:permission.unknown',
        ])->get('/_test/permissions/unknown', function () {
            return response()->json(['unexpected' => true]);
        });

        Route::get('/_test/permissions/legacy-error', function () {
            return errorReturn('legacy');
        });
    }

    protected function tearDown(): void
    {
        $this->clearTestTokens();

        parent::tearDown();
    }

    public function test_check_api_attribute_allows_a_database_grant(): void
    {
        DB::table('user_permissions')->insert([
            'user_id' => self::REGULAR_USER_ID,
            'permission_code' => 'users.view',
            'granted_by' => 31,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson(
            '/_test/permissions/users',
            ['X-Token' => $this->issueToken(self::REGULAR_USER_ID)]
        );

        $response->assertStatus(200);
        $response->assertExactJson(['user_id' => (string) self::REGULAR_USER_ID]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_user_without_a_grant_receives_real_http_403(): void
    {
        $response = $this->getJson(
            '/_test/permissions/users',
            ['X-Token' => $this->issueToken(self::REGULAR_USER_ID)]
        );

        $response->assertStatus(403);
        $response->assertJson([
            'type' => 'error',
            'code' => 403,
        ]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_is_admin_does_not_bypass_a_missing_grant(): void
    {
        $response = $this->getJson(
            '/_test/permissions/users',
            ['X-Token' => $this->issueToken(self::ADMIN_USER_ID)]
        );

        $response->assertStatus(403);
        $response->assertJson([
            'type' => 'error',
            'code' => 403,
        ]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_unknown_permission_fails_closed_with_http_500_and_error_log(): void
    {
        $logHandler = new TestHandler();
        Log::swap(new IlluminateLogger(
            new MonologLogger('permission-middleware-test', [$logHandler]),
            app('events')
        ));

        $response = $this->getJson(
            '/_test/permissions/unknown',
            ['X-Token' => $this->issueToken(self::REGULAR_USER_ID)]
        );

        $response->assertStatus(500);
        $response->assertJson([
            'type' => 'error',
            'code' => 500,
        ]);
        $this->assertStringNotContainsString('permission.unknown', $response->getContent());
        $this->assertTrue(
            $logHandler->hasRecord([
                'message' => 'Unknown route permission code',
                'context' => ['permission_code' => 'permission.unknown'],
            ], MonologLogger::ERROR),
            json_encode($logHandler->getRecords())
        );
    }

    /**
     * The legacy helper writes raw PHP headers, so this contract must run before
     * PHPUnit's parent process has emitted its progress output.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_legacy_error_return_keeps_http_200_and_existing_json_shape(): void
    {
        $response = $this->getJson('/_test/permissions/legacy-error');

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $response->assertExactJson([
            'type' => 'error',
            'code' => 460,
            'message' => 'legacy',
        ]);
    }

    private function issueToken(int $userId): string
    {
        $token = 'permission-task-4-token-'.$userId;
        $redis = $this->redis();
        $redis->set($token, $userId);
        $redis->set('user_token_'.$userId, $token);

        return $token;
    }

    private function clearTestTokens(): void
    {
        $redis = $this->redis();

        foreach ([self::REGULAR_USER_ID, self::ADMIN_USER_ID] as $userId) {
            $redis->del('permission-task-4-token-'.$userId);
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
                'Refusing destructive middleware test outside isolated services.'
            );
        }
    }
}
