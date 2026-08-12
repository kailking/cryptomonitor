<?php

namespace App\Console\Commands\symbols;

use App\Model\Currency;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use Illuminate\Console\Command;

class UpdateCoinexSymbol extends Command
{
    protected $signature = 'update_coinex_symbol';
    protected $description = '更新 CoinEx 交易对';

    public function handle()
    {
        $this->comment("CoinEx sync begin...");

        // CoinEx V2 现货交易对列表
        $url = 'https://api.coinex.com/v2/spot/market';
        $cli = new \GuzzleHttp\Client([
            'timeout' => 60,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0',
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
            $this->error("Invalid API Response");
            return 1;
        }

        $matchIdArr = [];

        foreach ($responseData['data'] as $item) {
            // V2 返回 {market: "BTCUSDT", base_ccy: "BTC", quote_ccy: "USDT", status: "online", ...}
            $symbolStr = strtoupper($item['market'] ?? '');
            if ($symbolStr === '') continue;

            // 只处理在线状态
            if (($item['status'] ?? '') !== 'online') continue;

            // 从 market 拆 base/quote
            $baseName = strtoupper($item['base_ccy'] ?? '');
            $quoteName = strtoupper($item['quote_ccy'] ?? 'USDT');

            // 只处理 USDT 结算
            if ($quoteName !== 'USDT') continue;
            if (empty($baseName) || !preg_match('/^[A-Z0-9]+$/', $symbolStr)) continue;

            $match = CurrencyMatch::where('symbol', $symbolStr)->first();

            if ($match) {
                CurrencyMatch::where('id', $match->id)->update(['is_coinex' => 1]);
                $matchIdArr[] = $match->id;
            } else {
                $currency = Currency::where('name', $baseName)->first();
                $currencyId = $currency ? $currency->id : Currency::insertGetId([
                    'name' => $baseName, 'created_at' => date('Y-m-d H:i:s'),
                ]);

                $matchId = CurrencyMatch::insertGetId([
                    'currency_id' => $currencyId,
                    'quote_id' => 1,
                    'currency_name' => $baseName,
                    'quote_name' => 'USDT',
                    'symbol' => $symbolStr,
                    'price_precision' => 8,
                    'is_coinex' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $matchIdArr[] = $matchId;
            }
        }

        foreach ($matchIdArr as $mid) {
            CurrencyMatch::initCurrencyMatchPlatform($mid, CurrencyQuotation::PLATFORM_COINEX);
        }

        // 下架
        $allIds = CurrencyMatch::where('is_coinex', 1)->pluck('id')->toArray();
        $toRemove = array_diff($allIds, $matchIdArr);
        if (!empty($toRemove)) {
            CurrencyMatch::whereIn('id', $toRemove)->update(['is_coinex' => 0]);
            MarketDepthDiff::whereIn('match_id', $toRemove)->where('buy_platform', CurrencyQuotation::PLATFORM_COINEX)->update(['is_show' => 0]);
            MarketDepthDiff::whereIn('sell_match_id', $toRemove)->where('sell_platform', CurrencyQuotation::PLATFORM_COINEX)->update(['is_show' => 0]);
        }

        $this->comment("End. Processed " . count($matchIdArr) . " valid CoinEx symbols.");
    }
}
