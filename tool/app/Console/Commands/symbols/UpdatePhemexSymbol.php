<?php

namespace App\Console\Commands\symbols;

use App\Model\Currency;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdatePhemexSymbol extends Command
{
    protected $signature = 'update_phemex_symbol';
    protected $description = '更新 Phemex 交易对 (状态过滤版)';

    public function handle()
    {
        $this->comment("Phemex sync begin...");
        
        // Phemex 官方获取所有产品的接口
        $url = 'https://api.phemex.com/public/products';
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

        // 🚀 验证 Phemex 成功状态码 code 为 0，且确保存在 data.products 数组
        if (!isset($responseData['code']) || $responseData['code'] !== 0 || empty($responseData['data']['products']) || !is_array($responseData['data']['products'])) {
            $this->error("Invalid API Response: missing 'products' array or code is not 0");
            return;
        }

        $symbolsData = $responseData['data']['products'];
        $match_id_arr = [];

        foreach ($symbolsData as $value) {
            // 🚀 核心过滤：Phemex 中 type = 'Spot' 且 status = 'Listed' 才代表正常交易的现货
            $type = $value['type'] ?? '';
            $status = $value['status'] ?? '';
            
            if ($type !== 'Spot' || $status !== 'Listed') {
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

            // 保持与本地数据库一致的命名格式 (Phemex 现货原生 symbol 会带有 s 前缀如 sBTCUSDT，我们拼成标准的 BTCUSDT)
            $symbolName = $baseName . $quoteName; 
            // Phemex 返回的是精度放大倍数（如 8 表示 1e8），这里如果数据库存的是小数位数，可直接写入对应的整数
            $precision = (int)($value['priceScale'] ?? 8);

            // 1. 查找或更新现有交易对
            $match = CurrencyMatch::where('symbol', $symbolName)->first();

            if ($match) {
                // 标记 Phemex 标识
                CurrencyMatch::where('id', $match->id)->update(['is_phemex' => 1]);
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
                    'is_phemex' => 1, // 标记 Phemex 标识
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                $match_id_arr[] = $match_id;
            }
        }

        // 🚀 初始化平台关联 (使用 Phemex 常量)
        foreach ($match_id_arr as $mid) {
            CurrencyMatch::initCurrencyMatchPlatform($mid, CurrencyQuotation::PLATFORM_PHEMEX);
        }

        // 🚀 下架逻辑：处理那些库里标记了 is_phemex=1 但本次接口没返回 (或状态下线) 的币
        $all_phemex_ids = CurrencyMatch::where('is_phemex', 1)->pluck('id')->toArray();
        $to_remove_ids = array_diff($all_phemex_ids, $match_id_arr);

        if (!empty($to_remove_ids)) {
            CurrencyMatch::whereIn('id', $to_remove_ids)->update(['is_phemex' => 0]);
            
            // 隐藏 market_depth_diff 中相关的 Phemex 价差条目
            MarketDepthDiff::whereIn('match_id', $to_remove_ids)
                ->where('buy_platform', CurrencyQuotation::PLATFORM_PHEMEX)
                ->update(['is_show' => 0]);

            MarketDepthDiff::whereIn('sell_match_id', $to_remove_ids)
                ->where('sell_platform', CurrencyQuotation::PLATFORM_PHEMEX)
                ->update(['is_show' => 0]);
        }

        $this->comment("End. Processed " . count($match_id_arr) . " valid Phemex symbols.");
    }
}