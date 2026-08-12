<?php

namespace App\Console\Commands\withdraw_info;

use App\Model\CurrencyQuotation;
use App\Model\PlatformWithdraw;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class UpdateMexcWithdraw extends Command
{
    protected $signature = 'update_mexc_withdraw';
    protected $description = '更新 MEXC 提现/充值通道（network 级别）';

    private const BASE_URL = 'https://api.mexc.com';

    public function handle()
{
    $apiKey = (string) env('MEXC_API_KEY', '');
    $apiSecret = (string) env('MEXC_API_SECRET', '');
    if ($apiKey === '' || $apiSecret === '') {
        $this->error('请先在 .env 中配置 MEXC_API_KEY / MEXC_API_SECRET');
        return 1;
    }

    $platform = CurrencyQuotation::PLATFORM_MEXC;

    $path = '/api/v3/capital/config/getall';

    $params = [
        'timestamp'  => $this->nowMs(),
        'recvWindow' => 5000,
    ];

    $query = $this->buildQuery($params);
    $sign  = hash_hmac('sha256', $query, $apiSecret);
    $url   = self::BASE_URL . $path . '?' . $query . '&signature=' . $sign;

    $client = new Client([
        'timeout' => 120,
    ]);

    try {
        $resp = $client->get($url, [
            'headers' => [
                'X-MEXC-APIKEY' => $apiKey,
            ],
        ]);
    } catch (\Throwable $e) {
        $this->error('请求 MEXC 失败: ' . $e->getMessage());
        return 2;
    }

    $body = (string)$resp->getBody();
    $json = json_decode($body, true);

    if (!is_array($json)) {
        $this->error('MEXC 返回非 JSON');
        $this->line($body);
        return 3;
    }

    $count = 0;

    foreach ($json as $item) {
        if (empty($item['coin']) || empty($item['networkList'])) {
            continue;
        }

        $currency = strtoupper($item['coin']);

        foreach ($item['networkList'] as $net) {
            if (!isset($net['network'])) {
                continue;
            }

            $network = strtoupper($net['network']);

            // MEXC 状态字段
            $isWithdraw = !empty($net['withdrawEnable']) ? 1 : 0;
            $isDeposit  = !empty($net['depositEnable'])  ? 1 : 0;

            // --- 根据 MEXC JSON 结构提取新增字段 ---
            $withdrawFee = $net['withdrawFee'] ?? null;               // "0.000014"
            $withdrawPrecision = $net['withdrawIntegerMultiple'] ?? null; // 提币精度单位
            $confirmNum = $net['minConfirm'] ?? null;                 // 3
            // --------------------------------------

            PlatformWithdraw::updateRecord(
                $currency,
                $platform,
                $network,
                $isWithdraw,
                $isDeposit,
                $withdrawFee,         // 传给扩展后的方法
                $withdrawPrecision,   // 传给扩展后的方法
                $confirmNum           // 传给扩展后的方法
            );

            $count++;
        }
    }

    $this->info("MEXC withdraw/deposit 更新完成，处理 network 数：{$count}");

    return 0;
}

    // ===== helpers =====

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function buildQuery(array $params): string
    {
        ksort($params);
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
