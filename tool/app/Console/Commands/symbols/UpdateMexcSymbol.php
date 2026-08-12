<?php

namespace App\Console\Commands\symbols;

use App\Model\Currency;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use Illuminate\Console\Command;

class UpdateMexcSymbol extends Command
{
    protected $signature = 'update_mexc_symbol';
    protected $description = '更新抹茶交易对 (严格遵守 Spot V3 文档 status=1)';

    public function handle()
    {
        $this->comment(">>> MEXC Task Begin: " . date('Y-m-d H:i:s'));
        
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $base = 'https://api.mexc.com';

        try {
            // 1. 请求 exchangeInfo
            $exchangeInfoResp = $this->curlGetJson($base . '/api/v3/exchangeInfo');

            if (empty($exchangeInfoResp['symbols']) || !is_array($exchangeInfoResp['symbols'])) {
                $this->error("CRITICAL: API 未返回有效数据，终止运行以保护数据库！");
                return 1;
            }

            $symbols = $exchangeInfoResp['symbols'];
            $countFetched = count($symbols);
            $this->comment("Fetched symbols from API: " . $countFetched);

            // --- 核心保护：熔断逻辑 (防止误删全库) ---
            if ($countFetched < 500) { 
                $this->error("CRITICAL: 获取到的数量 ({$countFetched}) 远低于正常水平，停止更新！");
                return 1;
            }

            $match_id_arr = [];

            foreach ($symbols as $info) {
                // 严格遵守文档：status 为 "1" 代表开放交易
                // 部分接口返回 int 1，部分返回 string "1"，做兼容处理
                $status = isset($info['status']) ? (string)$info['status'] : '';
                if ($status !== '1') {
                    continue;
                }

                $currencyName = strtoupper($info['baseAsset'] ?? '');
                $quoteName    = strtoupper($info['quoteAsset'] ?? '');
                
                // 只要 USDT 计价对
                if ($quoteName !== 'USDT' || $currencyName === '') {
                    continue;
                }

                $symbolName = $currencyName . $quoteName;
                $pricePrecision = isset($info['quotePrecision']) ? (int)$info['quotePrecision'] : 8;

                // 2. 更新或创建记录
                $match = CurrencyMatch::where('symbol', $symbolName)->first();
                if ($match) {
                    CurrencyMatch::where('id',$match->id)->update([
                        'is_mexc' => 1
                    ]);
                    $match_id = $match->id;
                } else {
                    $currency = Currency::where('name',$currencyName)->first();
                    if($currency){
                        $currencyId = $currency->id;
                    }else{
                        $currencyId = Currency::insertGetId([
                            'name' => $currencyName
                        ]);
                    }

                    $match_id = CurrencyMatch::insertGetId([
                        'currency_id'     => $currencyId,
                        'quote_id'        => 1, // USDT 默认为 1
                        'currency_name'   => $currencyName,
                        'quote_name'      => 'USDT',
                        'symbol'          => $symbolName,
                        'price_precision' => $pricePrecision,
                        'is_mexc'         => 1,
                        'created_at'      => date('Y-m-d H:i:s'),
                    ]);
                }
                $match_id_arr[] = (int)$match_id;
            }

            // --- 3. 只有成功解析出的数量足够多，才处理清理下架逻辑 ---
            if (count($match_id_arr) > 500) {
                $this->comment("Updating platform mappings...");
                foreach (array_chunk($match_id_arr, 100) as $chunk) {
                    foreach ($chunk as $mid) {
                        CurrencyMatch::initCurrencyMatchPlatform($mid, CurrencyQuotation::PLATFORM_MEXC);
                    }
                }

                // 找出库里标记为 is_mexc=1 但本次接口没返回或 status 不为 1 的
                $mexc_match_ids_in_db = CurrencyMatch::where('is_mexc', 1)->pluck('id')->toArray();
                $to_disable = array_diff($mexc_match_ids_in_db, $match_id_arr);

                if (!empty($to_disable)) {
                    CurrencyMatch::whereIn('id', $to_disable)->update(['is_mexc' => 0]);
                    
                    // 下架相关的价差显示 (MarketDepthDiff)
                    MarketDepthDiff::whereIn('match_id', $to_disable)
                        ->where('buy_platform', CurrencyQuotation::PLATFORM_MEXC)
                        ->update(['is_show' => 0]);
                    MarketDepthDiff::whereIn('sell_match_id', $to_disable)
                        ->where('sell_platform', CurrencyQuotation::PLATFORM_MEXC)
                        ->update(['is_show' => 0]);
                    
                    $this->warn("Successfully cleaned up defunct symbols: " . count($to_disable));
                }
            }

        } catch (\Exception $e) {
            $this->error("Task Failed: " . $e->getMessage());
            return 1;
        }

        $this->comment(">>> MEXC Update Task End: " . date('Y-m-d H:i:s'));
        return 0;
    }

    private function curlGetJson(string $url, int $connectTimeout = 15, int $timeout = 60): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_CONNECTTIMEOUT   => $connectTimeout,
            CURLOPT_TIMEOUT          => $timeout,
            CURLOPT_SSL_VERIFYPEER   => false,
            CURLOPT_SSL_VERIFYHOST   => 0,
            CURLOPT_ENCODING         => 'gzip',
            CURLOPT_HTTPHEADER       => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (MexcUpdater/3.0)',
            ],
        ]);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) throw new \Exception("API HTTP Error: {$httpCode}");
        $json = json_decode($result, true);
        return is_array($json) ? $json : [];
    }
}