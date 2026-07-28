<?php

namespace Tests\Feature;

use App\Model\Users;
use App\Services\DeviceLoginService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeviceLoginServiceTest extends TestCase
{
    private const FIRST_USER_ID = 201;
    private const SECOND_USER_ID = 202;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('user_devices')
            ->whereIn('user_id', [self::FIRST_USER_ID, self::SECOND_USER_ID])
            ->delete();
        DB::table('users')
            ->whereIn('id', [self::FIRST_USER_ID, self::SECOND_USER_ID])
            ->delete();
        DB::table('users')->insert([
            [
                'id' => self::FIRST_USER_ID,
                'account' => 'device-user-one',
                'pwd' => 'test-password',
                'status' => 1,
                'expired_at' => '2027-12-31 23:59:59',
                'is_admin' => 0,
            ],
            [
                'id' => self::SECOND_USER_ID,
                'account' => 'device-user-two',
                'pwd' => 'test-password',
                'status' => 1,
                'expired_at' => '2027-12-31 23:59:59',
                'is_admin' => 0,
            ],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-22 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::table('user_devices')
            ->whereIn('user_id', [self::FIRST_USER_ID, self::SECOND_USER_ID])
            ->delete();
        DB::table('users')
            ->whereIn('id', [self::FIRST_USER_ID, self::SECOND_USER_ID])
            ->delete();
        parent::tearDown();
    }

    public function test_first_device_becomes_a_quiet_baseline_and_ip_changes_do_not_alert(): void
    {
        $service = app(DeviceLoginService::class);
        $user = Users::findOrFail(self::FIRST_USER_ID);
        $token = str_repeat('a', 64);

        $firstAlert = $service->recordSuccessfulLogin(
            $this->request($token, '203.0.113.10'),
            $user,
            'fingerprint-one',
            $token
        );

        Carbon::setTestNow(Carbon::parse('2026-07-22 12:05:00'));
        $secondAlert = $service->recordSuccessfulLogin(
            $this->request($token, '198.51.100.20'),
            $user,
            'fingerprint-two',
            $token
        );

        $this->assertNull($firstAlert);
        $this->assertNull($secondAlert);
        $device = DB::table('user_devices')->first();
        $this->assertSame(hash('sha256', $token), $device->device_token_hash);
        $this->assertStringNotContainsString($token, json_encode($device));
        $this->assertSame(2, (int) $device->login_count);
        $this->assertSame('198.51.100.20', $device->last_proxy_ip);
        $this->assertSame('Windows / Chrome', $device->device_label);
    }

    public function test_a_second_device_after_the_rapid_window_is_a_compact_notice(): void
    {
        $service = app(DeviceLoginService::class);
        $user = Users::findOrFail(self::FIRST_USER_ID);
        $firstToken = str_repeat('a', 64);
        $secondToken = str_repeat('b', 64);

        $service->recordSuccessfulLogin(
            $this->request($firstToken),
            $user,
            'fingerprint-one',
            $firstToken
        );
        Carbon::setTestNow(Carbon::parse('2026-07-22 13:00:00'));

        $alert = $service->recordSuccessfulLogin(
            $this->request($secondToken),
            $user,
            'fingerprint-two',
            $secondToken
        );

        $this->assertSame(
            '[注意] 新设备首次登录 · Windows / Chrome · 设备 A0FA',
            $alert
        );
        $this->assertStringNotContainsString($secondToken, $alert);
    }

    public function test_switching_back_to_another_device_within_ten_minutes_is_risky(): void
    {
        $service = app(DeviceLoginService::class);
        $user = Users::findOrFail(self::FIRST_USER_ID);
        $firstToken = str_repeat('a', 64);
        $secondToken = str_repeat('b', 64);

        $service->recordSuccessfulLogin(
            $this->request($firstToken),
            $user,
            null,
            $firstToken
        );
        Carbon::setTestNow(Carbon::parse('2026-07-22 13:00:00'));
        $service->recordSuccessfulLogin(
            $this->request($secondToken),
            $user,
            null,
            $secondToken
        );
        Carbon::setTestNow(Carbon::parse('2026-07-22 13:05:00'));

        $alert = $service->recordSuccessfulLogin(
            $this->request($firstToken),
            $user,
            null,
            $firstToken
        );

        $this->assertSame(
            '[风险] 10分钟内切换设备 · 当前设备 FFE0',
            $alert
        );
    }

    public function test_three_devices_in_twenty_four_hours_are_high_risk(): void
    {
        $service = app(DeviceLoginService::class);
        $user = Users::findOrFail(self::FIRST_USER_ID);
        $tokens = [str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64)];

        $service->recordSuccessfulLogin(
            $this->request($tokens[0]),
            $user,
            null,
            $tokens[0]
        );
        Carbon::setTestNow(Carbon::parse('2026-07-22 13:00:00'));
        $service->recordSuccessfulLogin(
            $this->request($tokens[1]),
            $user,
            null,
            $tokens[1]
        );
        Carbon::setTestNow(Carbon::parse('2026-07-22 14:00:00'));

        $alert = $service->recordSuccessfulLogin(
            $this->request($tokens[2]),
            $user,
            null,
            $tokens[2]
        );

        $this->assertSame(
            '[高风险] 24小时内使用3台设备 · 当前设备 52B6',
            $alert
        );
    }

    public function test_one_device_used_by_two_accounts_is_high_risk_and_throttled(): void
    {
        $service = app(DeviceLoginService::class);
        $token = str_repeat('d', 64);

        $service->recordSuccessfulLogin(
            $this->request($token),
            Users::findOrFail(self::FIRST_USER_ID),
            null,
            $token
        );

        $alert = $service->recordSuccessfulLogin(
            $this->request($token),
            Users::findOrFail(self::SECOND_USER_ID),
            null,
            $token
        );
        Carbon::setTestNow(Carbon::parse('2026-07-22 12:05:00'));
        $repeatedAlert = $service->recordSuccessfulLogin(
            $this->request($token),
            Users::findOrFail(self::SECOND_USER_ID),
            null,
            $token
        );

        $this->assertSame(
            '[高风险] 同一设备关联2个账号 · 设备 D913',
            $alert
        );
        $this->assertNull($repeatedAlert);
    }

    public function test_cookie_is_server_issued_http_only_and_same_site_lax(): void
    {
        $service = app(DeviceLoginService::class);
        $request = $this->request('not-a-valid-token');

        $token = $service->resolveToken($request);
        $cookie = $service->makeCookie($token);

        $this->assertRegExp('/\A[a-f0-9]{64}\z/', $token);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertFalse($cookie->isSecure());
        $this->assertSame('/', $cookie->getPath());
    }

    private function request(
        string $token,
        string $remoteAddress = '172.16.0.92'
    ): Request {
        return Request::create(
            '/api/user/login',
            'POST',
            [],
            [config('device_security.cookie_name') => $token],
            [],
            [
                'REMOTE_ADDR' => $remoteAddress,
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/126.0',
            ]
        );
    }
}
