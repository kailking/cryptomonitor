<?php

namespace App\Console\Commands\symbols;

use App\Model\Currency;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use Illuminate\Console\Command;

class UpdateLbankSymbol extends Command
{
    protected $signature = 'update_lbank_symbol';
    protected $description = '更新 Lbank 交易对';

    public function handle()
    {
        $this->comment("Lbank sync begin...");

        $url = 'https://www.lbkex.net/v2/currencyPairs.do';
        $cli = new \GuzzleHttp\Client([
            'timeout' => 60,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
            ],
        ]);

        try {
            $response = $cli->get($url);
            $content = $response->getBody()->getContents();
            $responseData = json_decode($content, true);
        } catch (\Exception $e) {
            $this->error("API Request Error: " . $e->getMessage());
            return 1;
        }

        if (empty($responseData['data']) || !is_array($responseData['data'])) {
            $this->error("Invalid API Response: missing 'data' array");
            return 1;
        }

        $symbolsData = $responseData['data'];
        $matchIdArr = [];

        foreach ($symbolsData as $value) {
            // data 里每个元素是 "btc_usdt" 这种字符串
            $symbol = explode('_', $value);
            if (count($symbol) < 2) {
                continue;
            }

            $baseName = strtoupper($symbol[0]);
            $quoteName = strtoupper($symbol[1]);

            // 只处理 USDT 结算的对子
            if ($quoteName !== 'USDT') {
                continue;
            }

            if (empty($baseName) || !preg_match('/^[A-Z0-9]+$/', $baseName . $quoteName)) {
                continue;
            }

            $symbolName = $baseName . $quoteName;
            $precision = 8;

            // 1. 查找或更新现有交易对
            $match = CurrencyMatch::where('symbol', $symbolName)->first();

            if ($match) {
                CurrencyMatch::where('id', $match->id)->update(['is_lbank' => 1]);
                $matchIdArr[] = $match->id;
            } else {
                // 2. 查找或新增基础币种
                $currency = Currency::where('name', $baseName)->first();
                if ($currency) {
                    $currencyId = $currency->id;
                } else {
                    $currencyId = Currency::insertGetId([
                        'name' => $baseName,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }

                // 3. 插入新交易对
                $matchId = CurrencyMatch::insertGetId([
                    'currency_id' => $currencyId,
                    'quote_id' => 1,
                    'currency_name' => $baseName,
                    'quote_name' => $quoteName,
                    'symbol' => $symbolName,
                    'price_precision' => $precision,
                    'is_lbank' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $matchIdArr[] = $matchId;
            }
        }

        // 初始化平台关联
        foreach ($matchIdArr as $mid) {
            CurrencyMatch::initCurrencyMatchPlatform($mid, CurrencyQuotation::PLATFORM_LBANK);
        }

        // 下架逻辑：处理那些库里标记了 is_lbank=1 但本次接口没返回的币
        $allLbankIds = CurrencyMatch::where('is_lbank', 1)->pluck('id')->toArray();
        $toRemoveIds = array_diff($allLbankIds, $matchIdArr);

        if (!empty($toRemoveIds)) {
            CurrencyMatch::whereIn('id', $toRemoveIds)->update(['is_lbank' => 0]);

            MarketDepthDiff::whereIn('match_id', $toRemoveIds)
                ->where('buy_platform', CurrencyQuotation::PLATFORM_LBANK)
                ->update(['is_show' => 0]);

            MarketDepthDiff::whereIn('sell_match_id', $toRemoveIds)
                ->where('sell_platform', CurrencyQuotation::PLATFORM_LBANK)
                ->update(['is_show' => 0]);
        }

        $this->comment("End. Processed " . count($matchIdArr) . " valid Lbank symbols.");
    }
}
