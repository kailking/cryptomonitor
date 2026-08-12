<?php

namespace App\Console\Commands\withdraw_info;

use App\Model\CurrencyQuotation;
use App\Model\PlatformWithdraw;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class UpdateBitmartWithdraw extends Command
{
    protected $signature = 'update_bitmart_withdraw {--try=3} {--debug}';
    protected $description = 'BitMart Account V1: Sync deposit/withdraw status and fees';

    private const BASE_URL = 'https://api-cloud.bitmart.com';
    // 接口已更新为 account 路径
    private const PATH = '/account/v1/currencies'; 

    public function handle()
    {
        $platform = defined('App\Model\CurrencyQuotation::PLATFORM_BITMART') 
            ? CurrencyQuotation::PLATFORM_BITMART 
            : 17;

        $isDebug = $this->option('debug');
        $tryMax = max(1, (int)$this->option('try'));

        $client = new Client([
            'base_uri'        => self::BASE_URL,
            'timeout'         => 20,
            'connect_timeout' => 10,
            'http_errors'     => false,
            'headers'         => ['Accept' => 'application/json'],
        ]);

        $json = null;
        for ($i = 1; $i <= $tryMax; $i++) {
            try {
                $this->comment("Fetching BitMart currencies (Attempt $i)...");
                $resp = $client->get(self::PATH);
                $body = (string)$resp->getBody();
                $json = json_decode($body, true);
                
                // 打印原始数据方便你调试，看到输出后记得删掉这两行
                // var_dump($body); exit; 

                if ($resp->getStatusCode() === 200 && ($json['code'] ?? 0) == 1000) break;
                sleep(1);
            } catch (\Exception $e) { 
                $this->error("Request error: " . $e->getMessage());
                sleep(1); 
            }
        }

        // BitMart 习惯将列表放在 data.currencies 或 data.list
        $list = $json['data']['currencies'] ?? $json['data'] ?? [];
        if (empty($list)) {
            $this->error("BitMart 未获取到有效数据，请检查接口权限或结构。");
            return 0;
        }

        $this->info("Got " . count($list) . " items. Processing...");

        $updated = 0;
        $skipped = 0;
        $notFoundInDb = 0; 

        foreach ($list as $item) {
            // --- 你的调试埋点 ---
            // var_dump($item); exit;

            $rawCurrency = strtoupper($item['currency'] ?? '');
            $network = strtoupper($item['network'] ?? '');
            
            if (!$rawCurrency) {
                $skipped++;
                continue;
            }

            // --- 剥离逻辑 (兼容 PHP 7.2) ---
            $coin = $rawCurrency;
            if ($network !== '') {
                $netLen = strlen($network);
                // 检查是否以 network 结尾
                if (substr($rawCurrency, -$netLen) === $network) {
                    $coin = rtrim(substr($rawCurrency, 0, -$netLen), '-');
                }
            }
            if ($coin === '') $coin = $rawCurrency;
            
            // 状态判断：优先使用接口返回的 bool 值
            $isWithdraw = ($item['withdraw_enabled'] ?? false) ? 1 : 0;
            $isDeposit  = ($item['deposit_enabled'] ?? false) ? 1 : 0;
            
            // 手续费
            $withdrawFee = $item['withdraw_fee'] ?? null;

            try {
                $res = PlatformWithdraw::updateRecord(
                    $coin,
                    $platform,
                    $network,
                    $isWithdraw,
                    $isDeposit,
                    $withdrawFee,
                    null, // precision
                    null  // confirm_num
                );
                
                if ($res) {
                    $updated++;
                } else {
                    $notFoundInDb++;
                    if ($isDebug) $this->warn("币种库缺少: {$coin}");
                }
            } catch (\Exception $e) {
                $skipped++;
                if ($isDebug) $this->error("Error {$coin}: " . $e->getMessage());
            }
        }

        $this->info("------------------------------------------");
        $this->info("完成! 更新成功: $updated");
        $this->error("币种库(Currency表)找不到: $notFoundInDb");
        $this->warn("其它跳过/错误: $skipped");
        
        return 0;
    }
}