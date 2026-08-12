<?php

namespace App\Console\Commands\symbols;

use App\Model\Currency;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class UpdatePionexSymbol extends Command
{
    protected $signature = 'update_pionex_symbol';
    protected $description = '更新Pionex交易对';

    private const BASE_URL = 'https://api.pionex.com';
    private const PATH     = '/api/v1/common/symbols';

    public function handle()
    {
        $this->comment("Pionex sync begin...");

        $cli = new Client([
            'base_uri'        => self::BASE_URL,
            'timeout'         => 60,
            'connect_timeout' => 10,
            'http_errors'     => false,
            'headers'         => [
                'Accept'     => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
            ],
        ]);

        try {
            $resp    = $cli->get(self::PATH, ['query' => ['type' => 'SPOT']]);
            $content = (string)$resp->getBody();
            $data    = json_decode($content, true);
        } catch (\Exception $e) {
            $this->error("API Request Error: " . $e->getMessage());
            return 1;
        }

        $symbols = $data['data']['symbols'] ?? null;
        if (!is_array($symbols)) {
            $this->error("Invalid API Response: " . substr($content, 0, 300));
            return 1;
        }

        $matchIds = [];

        foreach ($symbols as $item) {
            // 只处理启用的 SPOT USDT 对
            if (empty($item['enable'])) {
                continue;
            }
            if (strtoupper($item['type'] ?? '') !== 'SPOT') {
                continue;
            }

            $quoteName = strtoupper($item['quoteCurrency'] ?? '');
            if ($quoteName !== 'USDT') {
                continue;
            }

            $baseName  = strtoupper($item['baseCurrency'] ?? '');
            if (empty($baseName)) {
                continue;
            }

            // Pionex 格式 BTC_USDT → 统一存储为 BTCUSDT
            $symbolName = $baseName . $quoteName;
            $precision  = (int)($item['quotePrecision'] ?? 8);

            $match = CurrencyMatch::where('symbol', $symbolName)->first();

            if ($match) {
                CurrencyMatch::where('id', $match->id)->update(['is_pionex' => 1]);
                $matchIds[] = $match->id;
            } else {
                $currency = Currency::where('name', $baseName)->first();
                if ($currency) {
                    $currencyId = $currency->id;
                } else {
                    $currencyId = Currency::insertGetId([
                        'name'       => $baseName,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }

                $matchId = CurrencyMatch::insertGetId([
                    'currency_id'     => $currencyId,
                    'quote_id'        => 1,
                    'currency_name'   => $baseName,
                    'quote_name'      => $quoteName,
                    'symbol'          => $symbolName,
                    'price_precision' => $precision,
                    'is_pionex'       => 1,
                    'created_at'      => date('Y-m-d H:i:s'),
                ]);
                $matchIds[] = $matchId;
            }
        }

        // 初始化平台套利对
        foreach ($matchIds as $mid) {
            CurrencyMatch::initCurrencyMatchPlatform($mid, CurrencyQuotation::PLATFORM_PIONEX);
        }

        // 下架逻辑：本次接口未返回的币置为 is_pionex=0 并隐藏 market_depth_diff
        $allPionexIds = CurrencyMatch::where('is_pionex', 1)->pluck('id')->toArray();
        $toRemoveIds  = array_diff($allPionexIds, $matchIds);

        if (!empty($toRemoveIds)) {
            CurrencyMatch::whereIn('id', $toRemoveIds)->update(['is_pionex' => 0]);

            MarketDepthDiff::whereIn('match_id', $toRemoveIds)
                ->where('buy_platform', CurrencyQuotation::PLATFORM_PIONEX)
                ->update(['is_show' => 0]);

            MarketDepthDiff::whereIn('sell_match_id', $toRemoveIds)
                ->where('sell_platform', CurrencyQuotation::PLATFORM_PIONEX)
                ->update(['is_show' => 0]);

            $this->comment("Delisted " . count($toRemoveIds) . " symbols.");
        }

        $this->comment("End. Processed " . count($matchIds) . " valid Pionex symbols.");
        return 0;
    }
}
