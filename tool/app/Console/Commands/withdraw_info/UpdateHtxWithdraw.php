<?php

namespace App\Console\Commands\withdraw_info;

use App\Model\CurrencyQuotation;
use App\Model\PlatformWithdraw;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class UpdateHtxWithdraw extends Command
{
    protected $signature = 'update_htx_withdraw';
    protected $description = 'HTX(火币) 更新充提状态 (V2 API)';

    private const BASE_URLS = [
        'https://api.huobi.pro',
        'https://api.htx.com',
        'https://api-aws.huobi.pro',
    ];

    private const TIMEOUT = 30;

    public function handle()
    {
        $platformId = CurrencyQuotation::PLATFORM_HUOBI; 
        $this->info("Start updating HTX withdraw info (Platform ID: {$platformId})...");

        $client = new Client([
            'timeout'          => self::TIMEOUT,
            'connect_timeout'  => 10,
            'http_errors'      => false,
            'verify'           => false, 
            'force_ip_resolve' => 'v4', 
            'headers'          => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept'     => 'application/json',
            ],
        ]);

        $data = null;
        $usedUrl = '';
        $errorMsg = '';

        foreach (self::BASE_URLS as $base) {
            $url = $base . '/v2/reference/currencies';
            try {
                $this->comment("Trying: {$url} ...");
                $resp = $client->get($url);
                $code = $resp->getStatusCode();
                
                if ($code !== 200) {
                    $errorMsg = "HTTP $code";
                    continue;
                }

                $json = json_decode((string)$resp->getBody(), true);
                if (!isset($json['code']) || $json['code'] != 200 || empty($json['data'])) {
                    $errorMsg = "Invalid JSON response";
                    continue;
                }

                $data = $json['data'];
                $usedUrl = $url;
                break; 

            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
                continue;
            }
        }

        if (!$data) {
            $this->error("Failed to fetch HTX data. Last Error: {$errorMsg}");
            return 1;
        }

        $this->info("Successfully fetched data from {$usedUrl}. Items: " . count($data));

        $count = 0;
        $skipped = 0;
        foreach ($data as $item) {
            $currency = strtoupper($item['currency'] ?? '');
            if (empty($currency) || empty($item['chains'])) {
                $skipped++;
                continue;
            }
            // var_dump($currency);exit;

            foreach ($item['chains'] as $chain) {
                // 保留你原有的 baseChain 逻辑
                $chainName = strtoupper($chain['baseChain'] ?? '');
                
                $canDeposit = ($chain['depositStatus'] ?? '') === 'allowed' ? 1 : 0;
                $canWithdraw = ($chain['withdrawStatus'] ?? '') === 'allowed' ? 1 : 0;

                // --- 仅根据你提供的 JSON 结构新增以下三个字段的提取 ---
                $withdrawFee = $chain['transactFeeWithdraw'] ?? null;
                $withdrawPrecision = $chain['withdrawPrecision'] ?? null;
                $confirmNum = $chain['numOfFastConfirmations'] ?? null;
                // --------------------------------------------------

                try {
                    // 适配更新后的 updateRecord 方法签名
                    PlatformWithdraw::updateRecord(
                        $currency, 
                        $platformId, 
                        $chainName, 
                        $canWithdraw, 
                        $canDeposit,
                        $withdrawFee,        // 新增字段 1
                        $withdrawPrecision,  // 新增字段 2
                        $confirmNum          // 新增字段 3
                    );
                    $count++;
                } catch (\Exception $e) {
                    // $this->warn("Write DB error: {$currency}-{$chainName}");
                }
            }
        }

        $this->info("Done! Updated: {$count}, Skipped: {$skipped}");
        return 0;
    }
}