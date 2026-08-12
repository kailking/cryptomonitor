<?php

namespace App\Console\Commands\withdraw_info;

use App\Model\CurrencyQuotation;
use App\Model\PlatformWithdraw;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;

class UpdateBybitWithdraw extends Command
{
    protected $signature = 'update_bybit_withdraw {--host=https://api.bybit.com} {--try=3} {--coin=}';
    protected $description = 'Bybit V5: /v5/asset/coin/query-info 同步币种链(network)充提状态到 platform_withdraw';

    private const PATH = '/v5/asset/coin/query-info';
    private const RECV_WINDOW = '5000';

    public function handle()
    {
        if (env('BYBIT_API_KEY', '') === '' || env('BYBIT_API_SECRET', '') === '') {
            $this->error('请先在 .env 中配置 BYBIT_API_KEY / BYBIT_API_SECRET。');
            return 1;
        }

        // 你系统里 Bybit 的 platform 常量（不叫 PLATFORM_BYBIT 就改）
        $platform = CurrencyQuotation::PLATFORM_BYBIT;

        $host = trim((string)$this->option('host'));
        $host = rtrim($host ?: 'https://api.bybit.com', '/');

        $tryMax = max(1, (int)$this->option('try'));
        $coinOpt = trim((string)$this->option('coin')); // 可选：只同步某个币（比如 USDT）

        $client = new Client([
            'base_uri' => $host,
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        $query = [];
        if ($coinOpt !== '') {
            $query['coin'] = strtoupper($coinOpt);
        }

        $resp = $this->requestBybitGetWithRetry($client, self::PATH, $query, $tryMax);
        if (!$resp['ok']) {
            $this->error('请求 Bybit 失败: ' . $resp['err']);
            return 2;
        }

        $json = $resp['json'];
        if (!is_array($json)) {
            $this->error('Bybit 返回不是 JSON');
            return 3;
        }

        // Bybit v5: retCode=0 才算成功
        $retCode = (int)($json['retCode'] ?? -1);
        if ($retCode !== 0) {
            $this->error('Bybit retCode != 0: ' . $retCode . ' retMsg=' . (string)($json['retMsg'] ?? ''));
            return 4;
        }

        $rows = $json['result']['rows'] ?? null;
        if (!is_array($rows)) {
            $this->warn('Bybit result.rows 为空或不是数组。');
            return 0;
        }

        $updated = 0;
        $skipped = 0;
        
        foreach ($rows as $item) {
            if (!is_array($item)) { $skipped++; continue; }

            $coin = isset($item['coin']) ? strtoupper((string)$item['coin']) : null;
            if (!$coin) { $skipped++; continue; }

            // 文档一般是 chains: [{ chainType, chain, depositEnable, withdrawEnable, ... }]
            $chains = $item['chains'] ?? [];
            if (!is_array($chains) || empty($chains)) {
                $skipped++;
                continue;
            }

            foreach ($chains as $ch) {
                if (!is_array($ch)) { $skipped++; continue; }

                // network：优先用 chainType（比如 TRC20 / ERC20），没有就用 chain
                $network = null;
                if (isset($ch['chainType']) && trim((string)$ch['chainType']) !== '') {
                    $network = strtoupper(trim((string)$ch['chainType']));
                } elseif (isset($ch['chain']) && trim((string)$ch['chain']) !== '') {
                    $network = strtoupper(trim((string)$ch['chain']));
                }

                $withdrawEnable = $ch['chainWithdraw'] ?? null;
                $depositEnable  = $ch['chainDeposit'] ?? null;

                if ($network === null || $withdrawEnable === null || $depositEnable === null) {
                    $skipped++;
                    continue;
                }

                $isWithdraw = $this->to01($withdrawEnable);
                $isDeposit  = $this->to01($depositEnable);

                // --- 手续费逻辑：处理固定费与百分比费 ---
                $fixedFee = $ch['withdrawFee'] ?? '0';
                $percentFee = $ch['withdrawPercentageFee'] ?? '0';
                
                $withdrawFeeStr = '';
                if (floatval($percentFee) > 0) {
                    // 百分比转换为可读字符串 (例如 0.001 -> 0.1%)
                    $percentDisplay = (floatval($percentFee) * 100) . '%';
                    if (floatval($fixedFee) > 0) {
                        $withdrawFeeStr = $fixedFee . ' + ' . $percentDisplay;
                    } else {
                        $withdrawFeeStr = $percentDisplay;
                    }
                } else {
                    $withdrawFeeStr = $fixedFee;
                }

                // --- 精度与确认数 ---
                $withdrawPrecision = $ch['minAccuracy'] ?? null; 
                $confirmNum = $ch['confirmation'] ?? null;
                $confirmNum = empty($confirmNum)?null:$confirmNum;
                // 写入 platform_withdraw
                $ok = PlatformWithdraw::updateRecord(
                    $coin, 
                    $platform, 
                    $network, 
                    $isWithdraw, 
                    $isDeposit, 
                    $withdrawFeeStr, 
                    $withdrawPrecision, 
                    $confirmNum
                );
                
                if ($ok) $updated++;
            }
        }

        $this->info("Bybit done. updated={$updated}, skipped={$skipped}");
        return 0;
    }

    /**
     * Bybit V5 签名（GET）：
     * sign = HMAC_SHA256( timestamp + apiKey + recvWindow + queryString, apiSecret )
     * headers:
     *  X-BAPI-API-KEY
     *  X-BAPI-SIGN
     *  X-BAPI-TIMESTAMP
     *  X-BAPI-RECV-WINDOW
     */
    private function requestBybitGetWithRetry(Client $client, string $path, array $query, int $tryMax): array
    {
        $lastErr = '';

        // 生成 queryString：按 key 排序 + RFC3986
        $queryString = $this->buildQueryString($query);
        $url = $path . ($queryString !== '' ? ('?' . $queryString) : '');

        for ($i = 1; $i <= $tryMax; $i++) {
            $ts = (string)$this->nowMs();
            $recvWindow = self::RECV_WINDOW;

            $apiKey = (string) env('BYBIT_API_KEY', '');
            $apiSecret = (string) env('BYBIT_API_SECRET', '');
            $signPayload = $ts . $apiKey . $recvWindow . $queryString;
            $sign = hash_hmac('sha256', $signPayload, $apiSecret);

            $headers = [
                'X-BAPI-API-KEY' => $apiKey,
                'X-BAPI-SIGN' => $sign,
                'X-BAPI-TIMESTAMP' => $ts,
                'X-BAPI-RECV-WINDOW' => $recvWindow,
            ];

            try {
                $resp = $client->request('GET', $url, [
                    'headers' => $headers,
                ]);

                $code = (int)$resp->getStatusCode();
                $raw  = (string)$resp->getBody();

                if ($code < 200 || $code >= 300) {
                    $lastErr = "HTTP {$code}: " . mb_substr($raw, 0, 500);
                    sleep($i);
                    continue;
                }

                $json = json_decode($raw, true);
                if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
                    $lastErr = 'JSON parse error: ' . json_last_error_msg() . ' raw=' . mb_substr($raw, 0, 200);
                    sleep($i);
                    continue;
                }

                return ['ok' => true, 'json' => $json, 'err' => ''];
            } catch (GuzzleException $e) {
                $lastErr = $e->getMessage();
                sleep($i);
            } catch (\Throwable $e) {
                $lastErr = $e->getMessage();
                sleep($i);
            }
        }

        return ['ok' => false, 'json' => null, 'err' => $lastErr];
    }

    private function buildQueryString(array $params): string
    {
        if (empty($params)) return '';

        ksort($params);
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function to01($v): int
    {
        if (is_bool($v)) return $v ? 1 : 0;
        if (is_numeric($v)) return ((int)$v) === 1 ? 1 : 0;
        $s = strtolower(trim((string)$v));
        return in_array($s, ['1', 'true', 'yes', 'y', 'on'], true) ? 1 : 0;
    }
}
