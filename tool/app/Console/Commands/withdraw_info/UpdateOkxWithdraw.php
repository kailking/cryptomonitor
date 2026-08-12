<?php

namespace App\Console\Commands\withdraw_info;

use App\Model\CurrencyQuotation;
use App\Model\PlatformWithdraw;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class UpdateOkxWithdraw extends Command
{
    protected $signature = 'update_okx_withdraw {--timeout=20} {--retries=3}';
    protected $description = 'OKX V5: GET /api/v5/asset/currencies (更新 platform_withdraw: chain/canWd/canDep)';

    private const BASE_URL       = 'https://www.okx.com';
    private const REQUEST_PATH   = '/api/v5/asset/currencies';

    public function handle()
    {
        $apiKey = (string) env('OKX_API_KEY', '');
        $apiSecret = (string) env('OKX_API_SECRET', '');
        $passphrase = (string) env('OKX_API_PASSPHRASE', '');
        if ($apiKey === '' || $apiSecret === '' || $passphrase === '') {
            $this->error('请先在 .env 中配置 OKX API_KEY / API_SECRET / PASSPHRASE。');
            return 1;
        }

        $timeout = (int)$this->option('timeout');
        if ($timeout <= 0) $timeout = 20;

        $retries = (int)$this->option('retries');
        if ($retries < 0) $retries = 0;

        $client = new Client([
            'base_uri' => self::BASE_URL,
            'timeout' => $timeout,
            'connect_timeout' => 5,
            'http_errors' => false,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);

        $method = 'GET';
        $path   = self::REQUEST_PATH;
        $url    = $path; 

        $attempt = 0;
        $bodyStr = ''; 

        while (true) {
            $attempt++;
            $ts = $this->iso8601MsZ();
            $prehash = $ts . $method . $path . $bodyStr;
            $sign = base64_encode(hash_hmac('sha256', $prehash, $apiSecret, true));

            try {
                $resp = $client->request($method, $url, [
                    'headers' => [
                        'OK-ACCESS-KEY' => $apiKey,
                        'OK-ACCESS-SIGN' => $sign,
                        'OK-ACCESS-TIMESTAMP' => $ts,
                        'OK-ACCESS-PASSPHRASE' => $passphrase,
                    ],
                ]);

                $status = (int)$resp->getStatusCode();
                $raw = (string)$resp->getBody();

                if ($status < 200 || $status >= 300) {
                    $this->error("OKX HTTP错误: {$status}");
                    return 2;
                }

                $json = json_decode($raw, true);
                if (!is_array($json) || !isset($json['data'])) {
                    $this->error('OKX 返回数据异常');
                    return 3;
                }

                $platform = CurrencyQuotation::PLATFORM_OKEX;
                $ok = 0;
                $skip = 0;

                foreach ($json['data'] as $item) {
                    if (!is_array($item)) { $skip++; continue; }

                    $ccy = isset($item['ccy']) ? strtoupper((string)$item['ccy']) : '';
                    if ($ccy === '') { $skip++; continue; }

                    $chainRaw = isset($item['chain']) ? (string)$item['chain'] : '';
                    $network = $this->normalizeChainToNetwork($chainRaw);

                    $isWithdraw = $this->to01($item['canWd'] ?? 0);
                    $isDeposit  = $this->to01($item['canDep'] ?? 0);

                    // --- 根据 OKX 文档提取新增字段 ---
                    $withdrawFee = $item['fee'] ?? null;                  // 固定的提币手续费
                    $withdrawPrecision = $item['wdTickSz'] ?? null;        // 提币精度（小数点位数）
                    $confirmNum = $item['minDepArrivalConfirm'] ?? null;  // 充值入账确认数
                    // -----------------------------

                    $ret = PlatformWithdraw::updateRecord(
                        $ccy, 
                        $platform, 
                        $network, 
                        $isWithdraw, 
                        $isDeposit,
                        $withdrawFee,         // 传给扩展后的方法
                        $withdrawPrecision,   // 传给扩展后的方法
                        $confirmNum           // 传给扩展后的方法
                    );

                    if ($ret) $ok++; else $skip++;
                }

                $this->info("done. updated={$ok}, skipped={$skip}");
                return 0;

            } catch (\Exception $e) {
                if ($attempt > $retries) {
                    $this->error('请求 OKX 失败: ' . $e->getMessage());
                    return 5;
                }
                sleep(min(5, $attempt));
            }
        }
    }

    private function iso8601MsZ()
    {
        // 例如：2020-12-08T09:08:57.715Z
        $micro = microtime(true);
        $ms = (int)(($micro - floor($micro)) * 1000);
        $dt = gmdate('Y-m-d\TH:i:s', (int)$micro);
        return sprintf('%s.%03dZ', $dt, $ms);
    }

    private function to01($v)
    {
        if (is_bool($v)) return $v ? 1 : 0;
        if (is_numeric($v)) return ((int)$v) ? 1 : 0;
        $s = strtolower(trim((string)$v));
        return in_array($s, ['1', 'true', 'yes', 'y'], true) ? 1 : 0;
    }

    /**
     * 你之前 OKEX 逻辑：把 chain 里 "-" 后面的部分当 network
     * - "USDT-TRC20" => "TRC20"
     * - "BTC-Bitcoin" => "Bitcoin"
     * - "ETH-ERC20" => "ERC20"
     * 若没有 "-" 则 network = null
     */
    private function normalizeChainToNetwork($chainRaw)
    {
        $chainRaw = trim((string)$chainRaw);
        if ($chainRaw === '') return null;

        $parts = explode('-', $chainRaw, 2);
        if (count($parts) === 2 && $parts[1] !== '') {
            return $parts[1];
        }
        return null;
    }
}
