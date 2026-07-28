<?php

namespace Tests\Feature;

use App\Model\Users;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DeviceLoginApiTest extends TestCase
{
    private const USER_ID = 211;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureLoginTables();
        DB::table('user_login_log')->where('user_id', self::USER_ID)->delete();
        DB::table('system_log')->where('user_id', self::USER_ID)->delete();
        DB::table('user_devices')->where('user_id', self::USER_ID)->delete();
        DB::table('users')->where('id', self::USER_ID)->delete();
        DB::table('users')->insert([
            'id' => self::USER_ID,
            'account' => 'login-device-user',
            'pwd' => Users::makePassword('test-password'),
            'status' => 1,
            'expired_at' => '2027-12-31 23:59:59',
            'is_admin' => 0,
        ]);

        Users::clearToken(self::USER_ID);
        Carbon::setTestNow(Carbon::parse('2026-07-22 12:00:00'));
    }

    protected function tearDown(): void
    {
        Users::clearToken(self::USER_ID);
        Carbon::setTestNow();
        DB::table('user_login_log')->where('user_id', self::USER_ID)->delete();
        DB::table('system_log')->where('user_id', self::USER_ID)->delete();
        DB::table('user_devices')->where('user_id', self::USER_ID)->delete();
        DB::table('users')->where('id', self::USER_ID)->delete();
        parent::tearDown();
    }

    public function test_login_sets_device_cookie_and_ignores_ip_or_fingerprint_only_changes(): void
    {
        $payload = [
            'username' => 'login-device-user',
            'password' => 'test-password',
            'salt' => 'fingerprint-one',
        ];

        $firstResponse = $this->withServerVariables([
            'REMOTE_ADDR' => '172.16.0.92',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/126.0',
        ])->post('/api/user/login', $payload);

        $firstResponse->assertStatus(200)->assertJsonPath('code', 200);
        $deviceCookie = collect($firstResponse->headers->getCookies())
            ->first(function ($cookie) {
                return $cookie->getName() === config('device_security.cookie_name');
            });

        $this->assertNotNull($deviceCookie);
        $this->assertTrue($deviceCookie->isHttpOnly());
        $this->assertSame('lax', $deviceCookie->getSameSite());
        $this->assertSame(
            0,
            DB::table('system_log')->where('user_id', self::USER_ID)->count()
        );

        Carbon::setTestNow(Carbon::parse('2026-07-22 12:05:00'));
        $payload['salt'] = 'fingerprint-two';
        $this->withUnencryptedCookie(
            $deviceCookie->getName(),
            $deviceCookie->getValue()
        )
            ->withServerVariables([
                'REMOTE_ADDR' => '10.0.0.8',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/126.0',
            ])
            ->post('/api/user/login', $payload)
            ->assertStatus(200)
            ->assertJsonPath('code', 200);

        $this->assertSame(
            0,
            DB::table('system_log')->where('user_id', self::USER_ID)->count()
        );
        $this->assertSame(
            2,
            (int) DB::table('user_login_log')
                ->where('user_id', self::USER_ID)
                ->count()
        );
        $this->assertSame(
            2,
            (int) DB::table('user_devices')->value('login_count')
        );
    }

    public function test_a_later_new_device_writes_one_short_security_log(): void
    {
        $firstToken = str_repeat('a', 64);
        $secondToken = str_repeat('b', 64);
        $payload = [
            'username' => 'login-device-user',
            'password' => 'test-password',
            'salt' => 'fingerprint-one',
        ];

        $this->withUnencryptedCookie(
            config('device_security.cookie_name'),
            $firstToken
        )
            ->withServerVariables([
                'REMOTE_ADDR' => '172.16.0.92',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/126.0',
            ])
            ->post('/api/user/login', $payload)
            ->assertStatus(200);

        Carbon::setTestNow(Carbon::parse('2026-07-22 13:00:00'));
        $this->withUnencryptedCookie(
            config('device_security.cookie_name'),
            $secondToken
        )
            ->withServerVariables([
                'REMOTE_ADDR' => '172.16.0.92',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 14) Chrome/126.0',
            ])
            ->post('/api/user/login', $payload)
            ->assertStatus(200);

        $log = DB::table('system_log')
            ->where('user_id', self::USER_ID)
            ->first();
        $this->assertNotNull($log);
        $this->assertSame(2, (int) $log->type);
        $this->assertSame(
            '[注意] 新设备首次登录 · Android / Chrome · 设备 A0FA',
            $log->remark
        );
        $this->assertStringNotContainsString($secondToken, $log->remark);
        $this->assertStringNotContainsString('172.16.0.92', $log->remark);
    }

    private function ensureLoginTables(): void
    {
        if (!Schema::hasTable('user_login_log')) {
            Schema::create('user_login_log', function (Blueprint $table): void {
                $table->increments('id');
                $table->integer('user_id');
                $table->dateTime('login_at');
                $table->string('login_ip', 100)->nullable();
                $table->string('browser_id', 255)->nullable();
            });
        }
        if (!Schema::hasTable('system_log')) {
            Schema::create('system_log', function (Blueprint $table): void {
                $table->increments('id');
                $table->integer('user_id')->nullable();
                $table->unsignedTinyInteger('type');
                $table->text('remark');
                $table->dateTime('created_at');
            });
        }
    }
}
