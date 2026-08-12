<?php

namespace App\Console\Commands\withdraw_info;

use App\Model\CurrencyQuotation;
use App\Model\PlatformWithdraw;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;

class UpdateGateWithdraw extends Command
{
    protected $signature = 'update_gate_withdraw {--host=} {--try=3}';
    protected $description = 'Gate APIv4: 遍历 chains 数组获取多链充提状态并更新 platform_withdraw';

    private const DEFAULT_HOST = 'https://api.gateio.ws';
    private const PREFIX = '/api/v4';
    private const PATH_SPOT_CURRENCIES = '/spot/currencies';

    public function handle()
    {
        $platform = CurrencyQuotation::PLATFORM_GATE;
        // PlatformWithdraw::where('platform',$platform)->delete();exit;
        $host = trim((string)$this->option('host')) ?: self::DEFAULT_HOST;
        $host = rtrim($host, '/');
        $tryMax = max(1, (int)$this->option('try'));

        $client = new Client([
            'base_uri' => $host,
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        $path = self::PREFIX . self::PATH_SPOT_CURRENCIES;
        $ok = $this->requestJsonWithRetry($client, 'GET', $path, $tryMax);

        if (!$ok['ok']) {
            $this->error('请求 Gate 失败: ' . $ok['err']);
            return 2;
        }

        $data = $ok['json'];
        $updated = 0;
        $skipped = 0;

        foreach ($data as $item) {
            if (!is_array($item) || ($item['delisted'] ?? false)) {
                $skipped++;
                continue;
            }

            $currency = strtoupper((string)($item['currency'] ?? ''));
            if ($currency === '') continue;

            /**
             * 🚀 核心逻辑修改：
             * Gate 的数据结构中，chains 数组包含了真正的多链详情。
             */
            $chains = $item['chains'] ?? [];

            if (!empty($chains) && is_array($chains)) {
                // 情况 1：存在多链，遍历所有链
                foreach ($chains as $c) {
                    $network = strtoupper(trim((string)($c['name'] ?? '')));
                    
                    // 获取该链特定的状态
                    $isWithdraw = ($c['withdraw_disabled'] ?? false) ? 0 : 1;
                    $isDeposit  = ($c['deposit_disabled'] ?? false) ? 0 : 1;

                    PlatformWithdraw::updateRecord($currency, $platform, $network, $isWithdraw, $isDeposit);
                    $updated++;
                }
            } else {
                // 情况 2：兜底逻辑，如果 chains 为空，取外层单链数据
                $network = isset($item['chain']) ? strtoupper(trim((string)$item['chain'])) : null;
                $isWithdraw = ($item['withdraw_disabled'] ?? false) ? 0 : 1;
                $isDeposit  = ($item['deposit_disabled'] ?? false) ? 0 : 1;

                PlatformWithdraw::updateRecord($currency, $platform, $network, $isWithdraw, $isDeposit);
                $updated++;
            }
        }

        $this->info("Gate Sync Complete. Total Records: {$updated}, Skipped: {$skipped}");
        return 0;
    }

    private function requestJsonWithRetry(Client $client, string $method, string $url, int $tryMax): array
    {
        for ($i = 1; $i <= $tryMax; $i++) {
            try {
                $resp = $client->request($method, $url);
                $raw  = (string)$resp->getBody();
                return ['ok' => true, 'json' => json_decode($raw, true), 'err' => ''];
            } catch (\Throwable $e) {
                $lastErr = $e->getMessage();
                if ($i < $tryMax) sleep($i);
            }
        }
        return ['ok' => false, 'json' => null, 'err' => $lastErr];
    }
}