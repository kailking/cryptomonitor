<?php

namespace App\Console\Commands\withdraw_info;

use App\Model\CurrencyQuotation;
use App\Model\PlatformWithdraw;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateLbankWithdraw extends Command
{
    protected $signature = 'update_lbank_withdraw {--try=3}';
    protected $description = 'Lbank: 逐币种查 assetConfigs → 更新 platform_withdraw';

    private const BASE_URL = 'https://api.lbkex.com';
    private const PATH     = '/v2/assetConfigs.do';

    public function handle()
    {
        $platform = CurrencyQuotation::PLATFORM_LBANK; // 10
        $tryMax   = max(1, (int)$this->option('try'));

        // 从 currency_match 取 Lbank 的币种列表（去重）
        $currencies = DB::table('currency_match')
            ->where('is_lbank', 1)
            ->pluck('currency_name')
            ->unique()
            ->values()
            ->toArray();

        if (empty($currencies)) {
            $this->error("currency_match 中无 Lbank 交易对，先跑 update_lbank_symbol");
            return 1;
        }

        $this->info("Lbank 共 " . count($currencies) . " 个币种，开始逐个查充提...");

        $client = new \GuzzleHttp\Client([
            'base_uri' => self::BASE_URL,
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0',
            ],
        ]);

        $updated = 0;
        $skipped = 0;
        $i = 0;

        foreach ($currencies as $currency) {
            $i++;
            $assetCode = strtolower($currency);

            // 请求单个币种的充提配置（带重试）
            $resp = $this->requestAssetConfigs($client, $assetCode, $tryMax);

            if (!$resp['ok']) {
                $skipped++;
                // 失败的币种，把所有链置 0（下架处理）
                PlatformWithdraw::updateRecord(
                    $currency, $platform, null, 0, 0, '0', 0, 0
                );
                continue;
            }

            $chains = $resp['json']['data'] ?? null;
            if (!is_array($chains) || empty($chains)) {
                // 没有链信息，置 0
                PlatformWithdraw::updateRecord(
                    $currency, $platform, null, 0, 0, '0', 0, 0
                );
                $skipped++;
                continue;
            }

            // 先把该币种所有链重置为 0（清除已下架的链）
            PlatformWithdraw::where('platform', $platform)
                ->where('currency_name', $currency)
                ->update(['is_withdraw' => 0, 'is_deposit' => 0]);

            foreach ($chains as $chain) {
                $chainName = strtoupper(trim($chain['chainName'] ?? ''));
                if ($chainName === '') {
                    $chainName = $currency;
                }

                $canDraw    = $chain['canDraw'] ?? false;
                $canDeposit = $chain['canDeposit'] ?? false;
                $isWithdraw = $this->to01($canDraw);
                $isDeposit  = $this->to01($canDeposit);

                // 手续费（assetFee 里）
                $feeInfo  = $chain['assetFee'] ?? [];
                $fee      = isset($feeInfo['feeAmt']) ? (string)$feeInfo['feeAmt'] : '0';
                $scale    = isset($feeInfo['scale']) ? (int)$feeInfo['scale'] : 0;

                $ok = PlatformWithdraw::updateRecord(
                    $currency,
                    $platform,
                    $chainName,
                    $isWithdraw,
                    $isDeposit,
                    $fee,
                    $scale,
                    0 // LBank 接口没有 minConfirm 字段
                );

                if ($ok) $updated++; else $skipped++;
            }

            // 每 50 个打印进度
            if ($i % 50 === 0) {
                $this->info("已处理 {$i}/" . count($currencies));
            }

            // 节流：LBank 限速 200次/10s = 20次/s，保守按 200ms（5次/s）避免 429
            // 1290 币种约 4 分钟跑完，15-30 分钟更新一次绰绰有余
            usleep(200000);
        }

        $this->info("Lbank withdraw done. updated={$updated}, skipped={$skipped}");
        return 0;
    }

    private function requestAssetConfigs(\GuzzleHttp\Client $client, string $assetCode, int $tryMax): array
    {
        $lastErr = '';
        for ($i = 1; $i <= $tryMax; $i++) {
            try {
                $resp = $client->get(self::PATH, [
                    'query' => ['assetCode' => $assetCode],
                ]);
                $code = $resp->getStatusCode();
                $raw  = (string)$resp->getBody();

                if ($code < 200 || $code >= 300) {
                    $lastErr = "HTTP {$code}";
                    sleep($i);
                    continue;
                }

                $json = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
                    $lastErr = 'JSON parse error';
                    sleep($i);
                    continue;
                }

                return ['ok' => true, 'json' => $json, 'err' => ''];
            } catch (\Throwable $e) {
                $lastErr = $e->getMessage();
                sleep($i);
            }
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
