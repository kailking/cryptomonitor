<?php

namespace App\Console\Commands\symbols;

use App\Model\Currency;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateNonkycSymbol extends Command
{
    // 签名改为 nonkyc
    protected $signature = 'update_Nonkyc_Symbol';
    protected $description = '更新NonKYC交易对';

    public function handle()
    {
        $this->comment("NonKYC sync begin...");
        
        // 接口地址
        $url = 'https://api.nonkyc.io/api/v2/market/getlist';
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
            $symbolsData = json_decode($content, true);
        } catch (\Exception $e) {
            $this->error("API Request Error: " . $e->getMessage());
            return;
        }

        if (empty($symbolsData) || !is_array($symbolsData)) {
            $this->error("Invalid API Response");
            return;
        }

        $match_id_arr = [];

        foreach ($symbolsData as $value) {
            // 过滤逻辑：必须 isActive 为 true 且未暂停
            if (!isset($value['isActive']) || $value['isActive'] !== true || ($value['isPaused'] ?? false) === true) {
                continue;
            }

            // 只处理 USDT 结算的对子
            $secondaryTicker = strtoupper($value['secondaryTicker'] ?? '');
            // 兼容性处理：如果 secondaryTicker 不存在，从 symbol "IMX/USDT" 中拆分
            if (empty($secondaryTicker) && strpos($value['symbol'], '/') !== false) {
                $parts = explode('/', $value['symbol']);
                $secondaryTicker = strtoupper(trim($parts[1]));
            }

            if ($secondaryTicker == 'USDT') {
                $baseName = strtoupper($value['primaryTicker']);
                $quoteName = 'USDT';
                $symbolName = $baseName . $quoteName; // 拼接成 IMXUSDT 格式

                // 1. 查找或更新现有交易对
                $match = CurrencyMatch::where('symbol', $symbolName)->first();

                if ($match) {
                    CurrencyMatch::where('id', $match->id)->update(['is_nonkyc' => 1]);
                    $match_id_arr[] = $match->id;
                } else {
                    // 2. 查找或新增币种
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
                        'quote_id' => 1, // 假设 1 是 USDT 的 ID
                        'currency_name' => $baseName,
                        'quote_name' => $quoteName,
                        'symbol' => $symbolName,
                        'price_precision' => (int)($value['priceDecimals'] ?? 8),
                        'is_nonkyc' => 1,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    $match_id_arr[] = $match_id;
                }
            }
        }

        // 初始化平台关联（调用你现有的平台初始化逻辑）
        foreach ($match_id_arr as $mid) {
            CurrencyMatch::initCurrencyMatchPlatform($mid, CurrencyQuotation::PLATFORM_NONKYC);
        }

        // 下架逻辑：处理那些库里标记了 is_nonkyc=1 但本次接口没返回的币
        $all_nonkyc_ids = CurrencyMatch::where('is_nonkyc', 1)->pluck('id')->toArray();
        $to_remove_ids = array_diff($all_nonkyc_ids, $match_id_arr);

        if (!empty($to_remove_ids)) {
            CurrencyMatch::whereIn('id', $to_remove_ids)->update(['is_nonkyc' => 0]);
            
            // 隐藏 market_depth_diff 中相关的 NonKYC 价差条目
            MarketDepthDiff::whereIn('match_id', $to_remove_ids)
                ->where('buy_platform', CurrencyQuotation::PLATFORM_NONKYC)
                ->update(['is_show' => 0]);

            MarketDepthDiff::whereIn('sell_match_id', $to_remove_ids)
                ->where('sell_platform', CurrencyQuotation::PLATFORM_NONKYC)
                ->update(['is_show' => 0]);
        }

        $this->comment("End. Processed " . count($match_id_arr) . " symbols.");
    }
}