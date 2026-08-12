<?php

namespace App\Console\Commands\symbols;

use App\Model\Currency;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateCoinWSymbol extends Command
{
    protected $signature = 'update_coinw_symbol';
    protected $description = '更新CoinW交易对 (状态过滤版)';

    public function handle()
    {
        exit;
        MarketDepthDiff::where('buy_platform', CurrencyQuotation::PLATFORM_COINW)
                ->update(['is_show' => 0]);
        MarketDepthDiff::where('sell_platform', CurrencyQuotation::PLATFORM_COINW)
                ->update(['is_show' => 0]);
                
        CurrencyMatch::where('is_coinw',1)->update(['is_coinw'=> 0]);
            exit;
        $this->comment("CoinW sync begin...");
        
        $url = 'https://api.coinw.com/api/v1/public?command=returnSymbol';
        $cli = new Client([
            'timeout' => 60, 
            'connect_timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0'
            ]
        ]);

        try {
            $response = $cli->get($url);
            $content = $response->getBody()->getContents();
            $responseData = json_decode($content, true);
        } catch (\Exception $e) {
            $this->error("API Request Error: " . $e->getMessage());
            return;
        }

        // 验证 CoinW 成功状态码为 "200"
        if (($responseData['code'] ?? '') !== "200" || empty($responseData['data']) || !is_array($responseData['data'])) {
            $this->error("Invalid API Response: missing 'data' array or code is not 200");
            return;
        }

        $symbolsData = $responseData['data'];
        $match_id_arr = [];

        foreach ($symbolsData as $value) {
            // 🚀 核心过滤：CoinW 中 state = 1 代表可正常交易
            if (($value['state'] ?? 0) != 1) {
                continue;
            }

            // 只处理 USDT 结算的对子
            $quoteName = strtoupper($value['currencyQuote'] ?? '');
            if ($quoteName !== 'USDT') {
                continue;
            }

            $baseName = strtoupper($value['currencyBase'] ?? '');
            if (empty($baseName)) {
                continue;
            }

            // 保持与本地数据库一致的命名格式 (如 BTCUSDT)
            $symbolName = $baseName . $quoteName; 
            $precision = (int)($value['pricePrecision'] ?? 8);

            // 1. 查找或更新现有交易对
            $match = CurrencyMatch::where('symbol', $symbolName)->first();

            if ($match) {
                CurrencyMatch::where('id', $match->id)->update(['is_coinw' => 1]);
                $match_id_arr[] = $match->id;
            } else {
                // 2. 查找或新增基础币种
                $currency = Currency::where('name', $baseName)->first();
                if ($currency) {
                    $currencyId = $currency->id;
                } else {
                    $currencyId = Currency::insertGetId([
                        'name' => $baseName,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                }

                // 3. 插入新交易对
                $match_id = CurrencyMatch::insertGetId([
                    'currency_id' => $currencyId,
                    'quote_id' => 1, 
                    'currency_name' => $baseName,
                    'quote_name' => $quoteName,
                    'symbol' => $symbolName,
                    'price_precision' => $precision,
                    'is_coinw' => 1,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                $match_id_arr[] = $match_id;
            }
        }

        // 🚀 初始化平台关联 (使用你定义的常量)
        foreach ($match_id_arr as $mid) {
            CurrencyMatch::initCurrencyMatchPlatform($mid, CurrencyQuotation::PLATFORM_COINW);
        }

        // 🚀 下架逻辑：处理那些库里标记了 is_coinw=1 但本次接口没返回 (或 state != 1) 的币
        $all_coinw_ids = CurrencyMatch::where('is_coinw', 1)->pluck('id')->toArray();
        $to_remove_ids = array_diff($all_coinw_ids, $match_id_arr);

        if (!empty($to_remove_ids)) {
            CurrencyMatch::whereIn('id', $to_remove_ids)->update(['is_coinw' => 0]);
            
            // 隐藏 market_depth_diff 中相关的 CoinW 价差条目 (使用你定义的常量)
            MarketDepthDiff::whereIn('match_id', $to_remove_ids)
                ->where('buy_platform', CurrencyQuotation::PLATFORM_COINW)
                ->update(['is_show' => 0]);

            MarketDepthDiff::whereIn('sell_match_id', $to_remove_ids)
                ->where('sell_platform', CurrencyQuotation::PLATFORM_COINW)
                ->update(['is_show' => 0]);
        }

        $this->comment("End. Processed " . count($match_id_arr) . " valid CoinW symbols.");
    }
}