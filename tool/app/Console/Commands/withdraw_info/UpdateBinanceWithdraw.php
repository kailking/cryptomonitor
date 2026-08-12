<?php

namespace App\Console\Commands\withdraw_info;

use App\Model\CurrencyQuotation;
use App\Model\PlatformWithdraw;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class UpdateBinanceWithdraw extends Command
{
    protected $signature = 'update_binance_withdraw';
    protected $description = 'BINANCE: Get /sapi/v1/capital/config/getall -> 更新 platform_withdraw (network/withdrawEnable/depositEnable)';

    private const BASE_URL = 'https://api.binance.com';

    public function handle()
    {
        $apiKey = (string) env('BINANCE_API_KEY', '');
        $apiSecret = (string) env('BINANCE_API_SECRET', '');
        if ($apiKey === '' || $apiSecret === '') {
            $this->error('请先在 .env 中配置 BINANCE_API_KEY / BINANCE_API_SECRET。');
            return 1;
        }

        $platform = defined(CurrencyQuotation::class.'::PLATFORM_BIANCE')
            ? CurrencyQuotation::PLATFORM_BIANCE
            : 2; 

        $path = '/sapi/v1/capital/config/getall';

        $params = [
            'timestamp'  => (string) $this->nowMs(),
            'recvWindow' => '5000',
        ];

        $queryString = $this->buildQueryString($params);
        $signature   = hash_hmac('sha256', $queryString, $apiSecret);
        $url         = self::BASE_URL . $path . '?' . $queryString . '&signature=' . $signature;

        $cli = new Client([
            'timeout'           => 120,
            'connect_timeout'   => 10,
            'http_errors'       => false,
            'headers'           => [
                'X-MBX-APIKEY' => $apiKey,
                'Accept'       => 'application/json',
            ],
        ]);

        try {
            $resp = $cli->get($url);
        } catch (\Exception $e) {
            $this->error('请求 Binance 失败: ' . $e->getMessage());
            return 2;
        }

        $status = $resp->getStatusCode();
        $body   = (string) $resp->getBody();

        if ($status < 200 || $status >= 300) {
            $this->error("HTTP错误: {$status}");
            $this->line(mb_substr($body, 0, 800));
            return 3;
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            $this->error('返回不是 JSON 数组');
            $this->line(mb_substr($body, 0, 800));
            return 4;
        }

        $countCoins = 0;
        $countRows  = 0;
        $countSkip  = 0;

        foreach ($json as $item) {
            if (!is_array($item)) { $countSkip++; continue; }

            $coin = $item['coin'] ?? null;
            if (!$coin) { $countSkip++; continue; }
            $coin = strtoupper((string)$coin);

            $networks = $item['networkList'] ?? null;
            if (!is_array($networks) || empty($networks)) {
                $countSkip++;
                continue;
            }

            $countCoins++;

            foreach ($networks as $n) {
                if (!is_array($n)) { $countSkip++; continue; }

                $network = $n['network'] ?? null;
                if ($network === null || $network === '') { $countSkip++; continue; }

                $withdrawEnable = $n['withdrawEnable'] ?? null;
                $depositEnable  = $n['depositEnable'] ?? null;

                if ($withdrawEnable === null && $depositEnable === null) {
                    $countSkip++;
                    continue;
                }

                $isWithdraw = $this->to01($withdrawEnable);
                $isDeposit  = $this->to01($depositEnable);

                // --- 根据币安 JSON 结构提取新增字段 ---
                $withdrawFee = $n['withdrawFee'] ?? null;              // "10"
                $withdrawPrecision = $n['withdrawIntegerMultiple'] ?? null; // "0.01"
                $confirmNum = $n['minConfirm'] ?? null;                // 5
                // ------------------------------------

                // 写入 platform_withdraw
                PlatformWithdraw::updateRecord(
                    $coin, 
                    $platform, 
                    (string)$network, 
                    $isWithdraw, 
                    $isDeposit,
                    $withdrawFee,        // 新增字段
                    $withdrawPrecision,  // 新增字段
                    $confirmNum          // 新增字段
                );
                
                $countRows++;
            }
        }

        $this->info("done. coins={$countCoins}, rows={$countRows}, skipped={$countSkip}");
        return 0;
    }

    private function nowMs()
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function buildQueryString(array $params)
    {
        ksort($params);
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function to01($v)
    {
        if ($v === null) return 0;
        if (is_bool($v)) return $v ? 1 : 0;
        if (is_numeric($v)) return ((int)$v) ? 1 : 0;
        $s = strtolower(trim((string)$v));
        return in_array($s, ['1','true','yes','y'], true) ? 1 : 0;
    }
}
