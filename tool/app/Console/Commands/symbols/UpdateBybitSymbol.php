<?php

namespace App\Console\Commands\symbols;

use App\Model\Currency;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use Illuminate\Console\Command;

class UpdateBybitSymbol extends Command
{
    protected $signature = 'update_bybit_symbol';
    protected $description = '更新 Bybit 交易对 (V5 Spot)';

    public function handle()
    {
        $this->comment("begin");

        // ✅ base 不传参：固定
        $base = 'https://api.bybit.com';

        // ✅ 固定 limit（一次执行内循环翻页）
        $limit = 500;

        $matchIdArr = [];

        $cursor = null;
        $page = 0;

        while (true) {
            $page++;

            $url = $base . '/v5/market/instruments-info?category=spot&limit=' . $limit;
            if ($cursor) {
                $url .= '&cursor=' . urlencode($cursor);
            }

            $resp = $this->curlGetJson($url, 8, 30);

            if (!isset($resp['retCode'])) {
                throw new \Exception('bybit resp missing retCode: ' . mb_substr(json_encode($resp, JSON_UNESCAPED_UNICODE), 0, 500));
            }
            if ((int)$resp['retCode'] !== 0) {
                throw new \Exception('bybit retCode != 0: ' . json_encode($resp, JSON_UNESCAPED_UNICODE));
            }

            $result = $resp['result'] ?? [];
            $list = $result['list'] ?? [];

            if (!is_array($list) || empty($list)) {
                // 没数据就结束
                break;
            }

            foreach ($list as $v) {
                // status: Trading / PreLaunch / Settling / Closed ...
                $status = (string)($v['status'] ?? '');
                if ($status !== 'Trading') continue;

                $currencyName = strtoupper((string)($v['baseCoin'] ?? ''));
                $quoteName    = strtoupper((string)($v['quoteCoin'] ?? ''));
                if ($currencyName === '' || $quoteName === '') continue;

                // 只要 USDT
                if ($quoteName !== 'USDT') continue;

                // 避免奇怪字符
                if (!preg_match('/^[A-Z0-9]+$/', $currencyName . $quoteName)) continue;

                // price_precision：优先 tickSize -> 小数位数
                $pricePrecision = 0;
                if (isset($v['priceFilter']['tickSize'])) {
                    $pricePrecision = $this->tickSizeToPrecision((string)$v['priceFilter']['tickSize']);
                }

                $symbol = $currencyName . $quoteName;

                // === 你原有逻辑（保持一致） ===
                $match = CurrencyMatch::where('symbol', $symbol)->first();
                if ($match) {
                    CurrencyMatch::where('id', $match->id)->update(['is_bybit' => 1]);
                    $matchIdArr[] = $match->id;
                    continue;
                }

                $currency = Currency::where('name', $currencyName)->first();
                $currencyId = $currency ? $currency->id : Currency::insertGetId(['name' => $currencyName]);

                $matchId = CurrencyMatch::insertGetId([
                    'currency_id'     => $currencyId,
                    'quote_id'        => 1,
                    'currency_name'   => $currencyName,
                    'quote_name'      => 'USDT',
                    'symbol'          => $symbol,
                    'price_precision' => $pricePrecision,
                    'is_bybit'        => 1,
                    'created_at'      => date('Y-m-d H:i:s'),
                ]);

                $matchIdArr[] = $matchId;
            }

            // ✅ 翻页（一次执行内把全量拉完）
            $next = $result['nextPageCursor'] ?? null;
            if (!$next || !is_string($next) || $next === $cursor) {
                break;
            }
            $cursor = $next;

            // 防死循环保护
            if ($page > 5000) {
                $this->error("too many pages, break");
                break;
            }
        }

        $matchIdArr = array_values(array_unique(array_filter($matchIdArr)));

        // 初始化平台映射
        foreach ($matchIdArr as $mid) {
            CurrencyMatch::initCurrencyMatchPlatform($mid, CurrencyQuotation::PLATFORM_BYBIT);
        }

        // disable match / diff（沿用你现有逻辑）
        $nowMatch = CurrencyMatch::where('is_bybit', 1)->pluck('id')->toArray();
        $id1 = MarketDepthDiff::where('buy_platform', CurrencyQuotation::PLATFORM_BYBIT)->pluck('match_id')->toArray();
        $id2 = MarketDepthDiff::where('sell_platform', CurrencyQuotation::PLATFORM_BYBIT)->pluck('sell_match_id')->toArray();

        $res = array_diff($nowMatch, $matchIdArr);
        $diffId = array_diff(array_unique(array_merge($id1, $id2)), $matchIdArr);

        if (!empty($res)) {
            CurrencyMatch::whereIn('id', $res)->update(['is_bybit' => 0]);
        }

        if (!empty($diffId)) {
            MarketDepthDiff::whereIn('match_id', $diffId)
                ->where('buy_platform', CurrencyQuotation::PLATFORM_BYBIT)
                ->update(['is_show' => 0]);

            MarketDepthDiff::whereIn('sell_match_id', $diffId)
                ->where('sell_platform', CurrencyQuotation::PLATFORM_BYBIT)
                ->update(['is_show' => 0]);
        }

        $this->comment("end. total USDT Trading symbols: " . count($matchIdArr));
        return 0;
    }

    private function tickSizeToPrecision(string $tickSize): int
    {
        $tickSize = trim($tickSize);
        if ($tickSize === '' || $tickSize === '0') return 0;

        $pos = strpos($tickSize, '.');
        if ($pos === false) return 0;

        $dec = rtrim(substr($tickSize, $pos + 1), '0');
        return max(0, strlen($dec));
    }

    private function curlGetJson(string $url, int $connectTimeout = 8, int $timeout = 30): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST    => "GET",
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_CONNECTTIMEOUT   => $connectTimeout,
            CURLOPT_TIMEOUT          => $timeout,

            CURLOPT_SSL_VERIFYPEER   => true,
            CURLOPT_SSL_VERIFYHOST   => 2,
            CURLOPT_IPRESOLVE        => CURL_IPRESOLVE_V4,

            CURLOPT_HTTPHEADER       => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (UpdateBybitSymbol/1.0)',
            ],

            CURLOPT_HEADER           => false,
            CURLOPT_FOLLOWLOCATION   => true,
            CURLOPT_MAXREDIRS        => 3,
        ]);

        $result = curl_exec($ch);
        if ($result === false) {
            $err = curl_error($ch);
            $no  = curl_errno($ch);
            $info = curl_getinfo($ch);
            curl_close($ch);

            throw new \Exception(
                "curl error ($no): $err; url={$url}; http_code=" . ($info['http_code'] ?? 'NA') .
                "; connect=" . ($info['connect_time'] ?? 'NA') .
                "; total=" . ($info['total_time'] ?? 'NA'),
                $no
            );
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception("HTTP {$httpCode} for {$url}; body=" . mb_substr($result, 0, 300));
        }

        $json = json_decode($result, true);
        return is_array($json) ? $json : [];
    }
}
