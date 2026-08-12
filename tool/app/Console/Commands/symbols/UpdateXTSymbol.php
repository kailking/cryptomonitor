<?php

namespace App\Console\Commands\symbols;

use App\Model\Currency;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateXTSymbol extends Command
{
    protected $signature = 'update_xt_symbol';
    protected $description = '更新 XT 交易对 (状态过滤版)';

    public function handle()
    {
        $this->comment("XT sync begin...");
        
        $url = 'https://sapi.xt.com/v4/public/symbol';
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

        // 🚀 验证 XT 成功状态码 rc 为 0，且确保存在 result.symbols 数组
        if (!isset($responseData['rc']) || $responseData['rc'] !== 0 || empty($responseData['result']['symbols']) || !is_array($responseData['result']['symbols'])) {
            $this->error("Invalid API Response: missing 'symbols' array or rc is not 0");
            return;
        }

        $symbolsData = $responseData['result']['symbols'];
        $match_id_arr = [];

        foreach ($symbolsData as $value) {
            // 🚀 核心过滤：XT 中 state = 'ONLINE' 且 tradingEnabled = true 才代表正常交易
            $state = $value['state'] ?? '';
            $tradingEnabled = $value['tradingEnabled'] ?? false;
            
            if ($state !== 'ONLINE' || $tradingEnabled !== true) {
                continue;
            }

            // 只处理 USDT 结算的对子
            $quoteName = strtoupper($value['quoteCurrency'] ?? '');
            if ($quoteName !== 'USDT') {
                continue;
            }

            $baseName = strtoupper($value['baseCurrency'] ?? '');
            if (empty($baseName)) {
                continue;
            }

            // 保持与本地数据库一致的命名格式 (如 BTCUSDT)
            $symbolName = $baseName . $quoteName; 
            $precision = (int)($value['pricePrecision'] ?? 8);

            // 1. 查找或更新现有交易对
            $match = CurrencyMatch::where('symbol', $symbolName)->first();

            if ($match) {
                // 标记 XT 标识
                CurrencyMatch::where('id', $match->id)->update(['is_xt' => 1]);
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
                    'is_xt' => 1, // 标记 XT 标识
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                $match_id_arr[] = $match_id;
            }
        }

        // 🚀 初始化平台关联 (使用 XT 常量)
        foreach ($match_id_arr as $mid) {
            CurrencyMatch::initCurrencyMatchPlatform($mid, CurrencyQuotation::PLATFORM_XT);
        }

        // 🚀 下架逻辑：处理那些库里标记了 is_xt=1 但本次接口没返回 (或状态下线) 的币
        $all_xt_ids = CurrencyMatch::where('is_xt', 1)->pluck('id')->toArray();
        $to_remove_ids = array_diff($all_xt_ids, $match_id_arr);

        if (!empty($to_remove_ids)) {
            CurrencyMatch::whereIn('id', $to_remove_ids)->update(['is_xt' => 0]);
            
            // 隐藏 market_depth_diff 中相关的 XT 价差条目
            MarketDepthDiff::whereIn('match_id', $to_remove_ids)
                ->where('buy_platform', CurrencyQuotation::PLATFORM_XT)
                ->update(['is_show' => 0]);

            MarketDepthDiff::whereIn('sell_match_id', $to_remove_ids)
                ->where('sell_platform', CurrencyQuotation::PLATFORM_XT)
                ->update(['is_show' => 0]);
        }

        $this->comment("End. Processed " . count($match_id_arr) . " valid XT symbols.");
    }
}