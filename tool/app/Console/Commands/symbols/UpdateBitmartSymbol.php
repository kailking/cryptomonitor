<?php

namespace App\Console\Commands\symbols;

use App\Model\Currency;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use Illuminate\Console\Command;

class UpdateBitmartSymbol extends Command
{
    protected $signature = 'update_bitmart_symbol';
    protected $description = '更新 BitMart 交易对（Spot v1 symbols）';

    public function handle()
    {
        $this->comment("begin");

        $url = 'https://api-cloud.bitmart.com/spot/v1/symbols';
        $resp = $this->curlGetJson($url);

        // BitMart: code=1000 表示 OK
        if (!isset($resp['code']) || (int)$resp['code'] !== 1000) {
            throw new \Exception('bitmart symbols 请求失败: ' . json_encode($resp, JSON_UNESCAPED_UNICODE));
        }

        $symbols = $resp['data']['symbols'] ?? [];
        if (!is_array($symbols) || empty($symbols)) {
            $this->comment("symbols empty, end");
            return 0;
        }

        $match_id_arr = [];

        foreach ($symbols as $s) {
            if (!is_string($s) || $s === '') continue;

            $s = strtoupper(trim($s)); // BTC_USDT
            // 只接受 A-Z0-9_，避免奇怪字符
            // if (!preg_match('/^[A-Z0-9_]+$/', $s)) continue;

            $parts = explode('_', $s);
            if (count($parts) !== 2) continue;

            $currencyName = $parts[0];
            $quoteName    = $parts[1];

            // 只要 USDT
            if ($quoteName !== 'USDT') continue;

            $symbolName = $currencyName . $quoteName;

            // 已存在：更新标记
            $match = CurrencyMatch::where('symbol', $symbolName)->first();
            if ($match) {
                CurrencyMatch::where('id', $match->id)->update(['is_bitmart' => 1]);
                $match_id_arr[] = $match->id;
                continue;
            }

            // currency 表不存在就创建
            $currency = Currency::where('name', $currencyName)->first();
            $currencyId = $currency ? $currency->id : Currency::insertGetId(['name' => $currencyName]);

            // 新增 match
            $match_id = CurrencyMatch::insertGetId([
                'currency_id'     => $currencyId,
                'quote_id'        => 1,
                'currency_name'   => $currencyName,
                'quote_name'      => 'USDT',
                'symbol'          => $symbolName,
                'price_precision' => 0, // 这个接口没给精度，先按 0
                'is_bitmart'      => 1,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);

            $match_id_arr[] = $match_id;
        }

        // 初始化平台映射
        foreach ($match_id_arr as $mid) {
            CurrencyMatch::initCurrencyMatchPlatform($mid, CurrencyQuotation::PLATFORM_BITMART);
        }

        // disable match / diff（与你其他脚本一致）
        $now_match = CurrencyMatch::where('is_bitmart', 1)->pluck('id')->toArray();

        $id1 = MarketDepthDiff::where('buy_platform', CurrencyQuotation::PLATFORM_BITMART)->pluck('match_id')->toArray();
        $id2 = MarketDepthDiff::where('sell_platform', CurrencyQuotation::PLATFORM_BITMART)->pluck('sell_match_id')->toArray();

        $res = array_diff($now_match, $match_id_arr);
        $diff_id = array_diff(array_unique(array_merge($id1, $id2)), $match_id_arr);

        if (!empty($res)) {
            CurrencyMatch::whereIn('id', $res)->update(['is_bitmart' => 0]);
        }

        if (!empty($diff_id)) {
            MarketDepthDiff::whereIn('match_id', $diff_id)
                ->where('buy_platform', CurrencyQuotation::PLATFORM_BITMART)
                ->update(['is_show' => 0]);

            MarketDepthDiff::whereIn('sell_match_id', $diff_id)
                ->where('sell_platform', CurrencyQuotation::PLATFORM_BITMART)
                ->update(['is_show' => 0]);
        }

        $this->comment("end");
        return 0;
    }

    private function curlGetJson(string $url, int $connectTimeout = 10, int $timeout = 30): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST    => "GET",
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_CONNECTTIMEOUT   => $connectTimeout,
            CURLOPT_TIMEOUT          => $timeout,
            CURLOPT_SSL_VERIFYPEER   => true,
            CURLOPT_SSL_VERIFYHOST   => 2,
            CURLOPT_HTTPHEADER       => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (UpdateBitmartSymbol/1.0)',
            ],
            CURLOPT_HEADER           => false,
            CURLOPT_FOLLOWLOCATION   => true,
            CURLOPT_MAXREDIRS        => 3,
        ]);

        $result = curl_exec($ch);
        if ($result === false) {
            $err = curl_error($ch);
            $no  = curl_errno($ch);
            curl_close($ch);
            throw new \Exception("curl error ($no): $err; url={$url}", $no);
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
