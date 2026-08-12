<?php

namespace App\Console\Commands\symbols;

use App\Model\Currency;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateWeexSymbol extends Command
{
    // 签名改为 weex
    protected $signature = 'update_weex_symbol';
    protected $description = '更新Weex交易对 (防垃圾币大水漫灌版)';

    public function handle()
    {
        $this->comment("Weex sync begin...");
        
        $url = 'https://api-spot.weex.com/api/v3/exchangeInfo';
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

        if (empty($responseData['symbols']) || !is_array($responseData['symbols'])) {
            $this->error("Invalid API Response: missing 'symbols' array");
            return;
        }

        $symbolsData = $responseData['symbols'];
        $match_id_arr = [];

        foreach ($symbolsData as $value) {
            // 🚀 核心修复：必须同时满足 状态=TRADING 且 允许交易 且 允许展示！
            // 彻底过滤掉 Weex 隐藏的几百个内部垃圾币
            if (
                ($value['status'] ?? '') !== 'TRADING' || 
                empty($value['enableTrade']) || 
                empty($value['enableDisplay'])
            ) {
                continue;
            }

            // 只处理 USDT 结算的对子
            $quoteName = strtoupper($value['quoteAsset'] ?? '');
            if ($quoteName === 'USDT') {
                $baseName = strtoupper($value['baseAsset'] ?? '');
                if (empty($baseName)) {
                    continue;
                }

                $symbolName = $baseName . $quoteName; 
                $precision = (int)($value['quoteAssetPrecision'] ?? 8);

                // 1. 查找或更新现有交易对
                $match = CurrencyMatch::where('symbol', $symbolName)->first();

                if ($match) {
                    CurrencyMatch::where('id', $match->id)->update(['is_weex' => 1]);
                    $match_id_arr[] = $match->id;
                } else {
                    // ⚠️ 注意：这里沿用了你原脚本的逻辑，如果数据库没有，会自动插入新币！
                    // 如果你其实【不想】让 Weex 自动添加新币，只想更新老币，请直接把这个 else {} 块删掉！
                    
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
                        'is_weex' => 1,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    $match_id_arr[] = $match_id;
                }
            }
        }

        // 初始化平台关联
        foreach ($match_id_arr as $mid) {
            CurrencyMatch::initCurrencyMatchPlatform($mid, CurrencyQuotation::PLATFORM_WEEX);
        }

        // 下架逻辑：处理那些库里标记了 is_weex=1 但本次接口没返回的币
        $all_weex_ids = CurrencyMatch::where('is_weex', 1)->pluck('id')->toArray();
        $to_remove_ids = array_diff($all_weex_ids, $match_id_arr);

        if (!empty($to_remove_ids)) {
            CurrencyMatch::whereIn('id', $to_remove_ids)->update(['is_weex' => 0]);
            
            // 隐藏 market_depth_diff 中相关的 Weex 价差条目
            MarketDepthDiff::whereIn('match_id', $to_remove_ids)
                ->where('buy_platform', CurrencyQuotation::PLATFORM_WEEX)
                ->update(['is_show' => 0]);

            MarketDepthDiff::whereIn('sell_match_id', $to_remove_ids)
                ->where('sell_platform', CurrencyQuotation::PLATFORM_WEEX)
                ->update(['is_show' => 0]);
        }

        $this->comment("End. Processed " . count($match_id_arr) . " valid WEEX symbols.");
    }
}