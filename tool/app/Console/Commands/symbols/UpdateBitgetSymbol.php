<?php

namespace App\Console\Commands\symbols;

use App\Model\Currency;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateBitgetSymbol extends Command
{
    protected $signature = 'update_bitget_symbol';
    protected $description = '更新 Bitget 现货交易对（V2 /api/v2/spot/public/symbols）';

    public function handle()
    {
        $this->comment("begin");

        $url = 'https://api.bitget.com/api/v2/spot/public/symbols';
        $resp = $this->curlGetJson($url);
        
        if (!$resp) {
            $this->error("接口响应空（curl 失败或非 JSON）");
            return 1;
        }
        

        if (!isset($resp['code'])) {
            $this->error("接口响应无 code 字段：" . mb_substr(json_encode($resp, JSON_UNESCAPED_UNICODE), 0, 500));
            return 1;
        }

        $code = (string)$resp['code'];
        if (!in_array($code, ['00000', '0'], true)) {
            $this->error("Bitget 返回非成功 code={$code}, msg=" . ($resp['msg'] ?? ''));
            return 1;
        }

        $data = $resp['data'] ?? [];
        if (!is_array($data) || empty($data)) {
            $this->error("Bitget data 为空");
            return 1;
        }

        $match_id_arr = [];

        DB::beginTransaction();
        try {
            foreach ($data as $item) {
                // Bitget V2字段：symbol/baseCoin/quoteCoin/status/pricePlace...
                $symbol = strtoupper($item['symbol'] ?? '');
                $base   = strtoupper($item['baseCoin'] ?? '');
                $quote  = strtoupper($item['quoteCoin'] ?? '');
                $status = strtolower($item['status'] ?? '');

                if ($symbol === '' || $base === '' || $quote === '') continue;

                // 只要 USDT
                if ($quote !== 'USDT') continue;

                // 只要 online
                if ($status !== 'online') continue;

                $pricePrecision = isset($item['pricePlace']) ? (int)$item['pricePlace'] : 0;

                $match = CurrencyMatch::where('symbol', $symbol)->first();
                if ($match) {
                    CurrencyMatch::where('id', $match->id)->update([
                        'is_bitget' => 1,
                        'price_precision' => $pricePrecision,
                        // 'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $match_id_arr[] = $match->id;
                    continue;
                }

                $currency = Currency::where('name', $base)->first();
                $currencyId = $currency ? $currency->id : Currency::insertGetId(['name' => $base]);

                $match_id = CurrencyMatch::insertGetId([
                    'currency_id'     => $currencyId,
                    'quote_id'        => 1,
                    'currency_name'   => $base,
                    'quote_name'      => 'USDT',
                    'symbol'          => $symbol,
                    'price_precision' => $pricePrecision,
                    'is_bitget'       => 1,
                    'is_enabled'      => 1,
                    'created_at'      => date('Y-m-d H:i:s'),
                ]);

                $match_id_arr[] = $match_id;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // 初始化平台映射
        foreach ($match_id_arr as $mid) {
            CurrencyMatch::initCurrencyMatchPlatform($mid, CurrencyQuotation::PLATFORM_BITGET);
        }

        // disable 不再支持的
        $now_match = CurrencyMatch::where('is_bitget', 1)->pluck('id')->toArray();
        $id1 = MarketDepthDiff::where('buy_platform', CurrencyQuotation::PLATFORM_BITGET)->pluck('match_id')->toArray();
        $id2 = MarketDepthDiff::where('sell_platform', CurrencyQuotation::PLATFORM_BITGET)->pluck('sell_match_id')->toArray();

        $res = array_diff($now_match, $match_id_arr);
        $diff_id = array_diff(array_unique(array_merge($id1, $id2)), $match_id_arr);

        if (!empty($res)) {
            CurrencyMatch::whereIn('id', $res)->update(['is_bitget' => 0]);
        }

        if (!empty($diff_id)) {
            MarketDepthDiff::whereIn('match_id', $diff_id)
                ->where('buy_platform', CurrencyQuotation::PLATFORM_BITGET)
                ->update(['is_show' => 0]);

            MarketDepthDiff::whereIn('sell_match_id', $diff_id)
                ->where('sell_platform', CurrencyQuotation::PLATFORM_BITGET)
                ->update(['is_show' => 0]);
        }

        $this->comment("end. total symbols=" . count($data) . ", usdt online=" . count($match_id_arr));
        return 0;
    }

    private function curlGetJson(string $url, int $connectTimeout = 8, int $timeout = 20): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_HEADER         => false,

            // ✅ 你服务器 curl/证书环境不稳定：先关掉，保证能拿到数据
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,

            // ✅ 避免 IPv6 / 解析慢
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,

            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: UpdateBitgetSymbol/1.0',
            ],
        ]);

        $result = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $info   = curl_getinfo($ch);
        curl_close($ch);

        if ($result === false) {
            $this->error("curl failed errno={$errno}, err={$error}, http_code=" . ($info['http_code'] ?? 'NA'));
            return [];
        }

        $httpCode = (int)($info['http_code'] ?? 0);
        if ($httpCode < 200 || $httpCode >= 300) {
            $this->error("HTTP {$httpCode} url={$url} body=" . mb_substr($result, 0, 300));
            return [];
        }

        $json = json_decode($result, true);
        if (!is_array($json)) {
            $this->error("json_decode failed, body head=" . mb_substr($result, 0, 300));
            return [];
        }
        return $json;
    }
}
