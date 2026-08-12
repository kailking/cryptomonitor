<?php

namespace App\Console\Commands\withdraw_info;

use App\Model\CurrencyQuotation;
use App\Model\PlatformWithdraw;
use Illuminate\Console\Command;

class UpdateCoinexWithdraw extends Command
{
    protected $signature = 'update_coinex_withdraw {--try=3}';
    protected $description = 'CoinEx: 一次性拉取所有充提配置 → 更新 platform_withdraw';

    // CoinEx V2 批量充提配置（一次返回所有币种）
    private const BASE_URL = 'https://api.coinex.com';
    private const PATH     = '/v2/assets/all-deposit-withdraw-config';

    public function handle()
    {
        $platform = CurrencyQuotation::PLATFORM_COINEX; // 9
        $tryMax   = max(1, (int)$this->option('try'));

        $client = new \GuzzleHttp\Client([
            'base_uri' => self::BASE_URL,
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0',
            ],
        ]);

        $this->info("Fetching CoinEx all deposit/withdraw config...");
        $resp = $this->requestWithRetry($client, $tryMax);

        if (!$resp['ok']) {
            $this->error("CoinEx 请求失败: " . $resp['err']);
            return 1;
        }

        $data = $resp['json']['data'] ?? null;
        if (!is_array($data)) {
            $this->error("CoinEx 返回数据格式错误");
            return 2;
        }

        $updated = 0;
        $skipped = 0;

        foreach ($data as $item) {
            $asset = $item['asset'] ?? null;
            if (!$asset) continue;

            $currency = strtoupper(trim($asset['ccy'] ?? ''));
            if ($currency === '') {
                $skipped++;
                continue;
            }

            $chains = $item['chains'] ?? [];
            if (empty($chains)) {
                // 无链信息，置 0
                PlatformWithdraw::updateRecord($currency, $platform, null, 0, 0, '0', 0, 0);
                $skipped++;
                continue;
            }

            // 先重置该币所有链为 0
            PlatformWithdraw::where('platform', $platform)
                ->where('currency_name', $currency)
                ->update(['is_withdraw' => 0, 'is_deposit' => 0]);

            foreach ($chains as $chain) {
                $chainName = strtoupper(trim($chain['chain'] ?? ''));
                if ($chainName === '') $chainName = $currency;

                $isWithdraw = $this->to01($chain['withdraw_enabled'] ?? false);
                $isDeposit  = $this->to01($chain['deposit_enabled'] ?? false);
                $fee        = isset($chain['withdrawal_fee']) ? (string)$chain['withdrawal_fee'] : '0';
                $precision  = isset($chain['withdrawal_precision']) ? (int)$chain['withdrawal_precision'] : 0;
                $confirm    = isset($chain['safe_confirmations']) ? (int)$chain['safe_confirmations'] : 0;

                $ok = PlatformWithdraw::updateRecord(
                    $currency, $platform, $chainName,
                    $isWithdraw, $isDeposit, $fee, $precision, $confirm
                );
                if ($ok) $updated++; else $skipped++;
            }
        }

        $this->info("CoinEx withdraw done. updated={$updated}, skipped={$skipped}");
        return 0;
    }

    private function requestWithRetry(\GuzzleHttp\Client $client, int $tryMax): array
    {
        $lastErr = '';
        for ($i = 1; $i <= $tryMax; $i++) {
            try {
                $resp = $client->get(self::PATH);
                $raw  = (string)$resp->getBody();
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    return ['ok' => true, 'json' => $json, 'err' => ''];
                }
                $lastErr = 'JSON parse error';
            } catch (\Throwable $e) {
                $lastErr = $e->getMessage();
            }
            sleep($i);
        }
        return ['ok' => false, 'json' => null, 'err' => $lastErr];
    }

    private function to01($v): int
    {
        if (is_bool($v)) return $v ? 1 : 0;
        if (is_numeric($v)) return ((int)$v) === 1 ? 1 : 0;
        $s = strtolower(trim((string)$v));
        return in_array($s, ['1', 'true', 'yes', 'y', 'on'], true) ? 1 : 0;
    }
}
