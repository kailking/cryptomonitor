<?php

namespace App\Services;

use App\Model\Users;
use Carbon\Carbon;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

class DeviceLoginService
{
    private $cookies;

    public function __construct(CookieJar $cookies)
    {
        $this->cookies = $cookies;
    }

    public function resolveToken(Request $request): string
    {
        $token = (string) $request->cookie($this->cookieName(), '');

        if (preg_match('/\A[a-f0-9]{64}\z/', $token) === 1) {
            return $token;
        }

        return bin2hex(random_bytes(32));
    }

    public function makeCookie(string $token): Cookie
    {
        return $this->cookies->make(
            $this->cookieName(),
            $token,
            (int) config('device_security.cookie_lifetime_minutes'),
            '/',
            null,
            (bool) config('device_security.cookie_secure'),
            true,
            false,
            'lax'
        );
    }

    public function recordSuccessfulLogin(
        Request $request,
        Users $user,
        ?string $browserId,
        string $token
    ): ?string {
        $tokenHash = hash('sha256', $token);
        $now = Carbon::now();

        return DB::transaction(function () use (
            $request,
            $user,
            $browserId,
            $tokenHash,
            $now
        ) {
            DB::table('users')
                ->where('id', $user->id)
                ->lockForUpdate()
                ->first();

            $devices = DB::table('user_devices')
                ->where('user_id', $user->id)
                ->orderBy('last_seen_at', 'desc')
                ->lockForUpdate()
                ->get();

            $current = $devices->first(function ($device) use ($tokenHash) {
                return hash_equals($device->device_token_hash, $tokenHash);
            });
            $previous = $devices->first();
            $isNewDevice = $current === null;
            $deviceLabel = $this->describeUserAgent(
                (string) $request->userAgent()
            );
            $deviceCode = strtoupper(substr($tokenHash, 0, 4));

            $recentBoundary = $now->copy()->subHours(
                (int) config('device_security.recent_window_hours')
            );
            $recentHashes = [];
            foreach ($devices as $device) {
                if (Carbon::parse($device->last_seen_at)->gte($recentBoundary)) {
                    $recentHashes[$device->device_token_hash] = true;
                }
            }
            $recentHashes[$tokenHash] = true;
            $recentDeviceCount = count($recentHashes);

            $otherAccountCount = DB::table('user_devices')
                ->where('device_token_hash', $tokenHash)
                ->where('user_id', '<>', $user->id)
                ->distinct()
                ->count('user_id');
            $sharedAccountCount = $otherAccountCount + 1;

            $rapidSwitchMinutes = (int) config(
                'device_security.rapid_switch_minutes'
            );
            $rapidSwitch = $previous !== null
                && !hash_equals($previous->device_token_hash, $tokenHash)
                && Carbon::parse($previous->last_seen_at)->gte(
                    $now->copy()->subMinutes($rapidSwitchMinutes)
                );

            [$alertCode, $alert] = $this->buildAlert(
                $isNewDevice,
                $devices->count(),
                $rapidSwitch,
                $recentDeviceCount,
                $sharedAccountCount,
                $deviceLabel,
                $deviceCode
            );

            if ($this->isCoolingDown($current, $alertCode, $now)) {
                $alertCode = null;
                $alert = null;
            }

            $values = [
                'device_label' => $deviceLabel,
                'browser_id' => $this->nullableLimit($browserId, 128),
                'user_agent' => $this->nullableLimit($request->userAgent(), 500),
                'last_proxy_ip' => $this->nullableLimit($request->getClientIp(), 100),
                'last_seen_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ];

            if ($alertCode !== null) {
                $values['last_alert_code'] = $alertCode;
                $values['last_alert_at'] = $now->toDateTimeString();
            }

            if ($current !== null) {
                $values['login_count'] = (int) $current->login_count + 1;
                DB::table('user_devices')
                    ->where('id', $current->id)
                    ->update($values);
            } else {
                DB::table('user_devices')->insert(array_merge($values, [
                    'user_id' => $user->id,
                    'device_token_hash' => $tokenHash,
                    'first_seen_at' => $now->toDateTimeString(),
                    'login_count' => 1,
                    'created_at' => $now->toDateTimeString(),
                ]));
            }

            return $alert;
        });
    }

    private function buildAlert(
        bool $isNewDevice,
        int $knownDeviceCount,
        bool $rapidSwitch,
        int $recentDeviceCount,
        int $sharedAccountCount,
        string $deviceLabel,
        string $deviceCode
    ): array {
        if ($sharedAccountCount > 1) {
            return [
                'shared_accounts',
                sprintf(
                    '[高风险] 同一设备关联%d个账号 · 设备 %s',
                    $sharedAccountCount,
                    $deviceCode
                ),
            ];
        }

        $threshold = (int) config('device_security.recent_device_threshold');
        if ($recentDeviceCount >= $threshold) {
            return [
                'many_recent_devices',
                sprintf(
                    '[高风险] 24小时内使用%d台设备 · 当前设备 %s',
                    $recentDeviceCount,
                    $deviceCode
                ),
            ];
        }

        if ($rapidSwitch) {
            return [
                'rapid_switch',
                sprintf(
                    '[风险] %d分钟内切换设备 · 当前设备 %s',
                    (int) config('device_security.rapid_switch_minutes'),
                    $deviceCode
                ),
            ];
        }

        if ($isNewDevice && $knownDeviceCount > 0) {
            return [
                'new_device',
                sprintf(
                    '[注意] 新设备首次登录 · %s · 设备 %s',
                    $deviceLabel,
                    $deviceCode
                ),
            ];
        }

        return [null, null];
    }

    private function isCoolingDown($current, ?string $alertCode, Carbon $now): bool
    {
        if (
            $current === null
            || $alertCode === null
            || $current->last_alert_code !== $alertCode
            || $current->last_alert_at === null
        ) {
            return false;
        }

        return Carbon::parse($current->last_alert_at)->gte(
            $now->copy()->subMinutes(
                (int) config('device_security.alert_cooldown_minutes')
            )
        );
    }

    private function describeUserAgent(string $userAgent): string
    {
        $operatingSystem = '未知系统';
        if (stripos($userAgent, 'Android') !== false) {
            $operatingSystem = 'Android';
        } elseif (preg_match('/iPhone|iPad|iPod/i', $userAgent) === 1) {
            $operatingSystem = 'iOS';
        } elseif (stripos($userAgent, 'Windows') !== false) {
            $operatingSystem = 'Windows';
        } elseif (stripos($userAgent, 'Macintosh') !== false) {
            $operatingSystem = 'macOS';
        } elseif (stripos($userAgent, 'Linux') !== false) {
            $operatingSystem = 'Linux';
        }

        $browser = '未知浏览器';
        if (preg_match('/Edg\//i', $userAgent) === 1) {
            $browser = 'Edge';
        } elseif (preg_match('/Chrome\/|CriOS\//i', $userAgent) === 1) {
            $browser = 'Chrome';
        } elseif (preg_match('/Firefox\/|FxiOS\//i', $userAgent) === 1) {
            $browser = 'Firefox';
        } elseif (
            stripos($userAgent, 'Safari/') !== false
            && stripos($userAgent, 'Version/') !== false
        ) {
            $browser = 'Safari';
        }

        return $operatingSystem.' / '.$browser;
    }

    private function nullableLimit($value, int $limit): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return substr((string) $value, 0, $limit);
    }

    private function cookieName(): string
    {
        return (string) config('device_security.cookie_name');
    }
}
