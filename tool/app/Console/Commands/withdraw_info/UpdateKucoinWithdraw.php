<?php

namespace App\Console\Commands\withdraw_info;

use App\Model\Currency;
use App\Model\CurrencyQuotation;
use App\Model\PlatformWithdraw;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;

class UpdateKucoinWithdraw extends Command
{
    protected $signature = 'update_kucoin_withdraw {--try=3} {--batch=80}';
    protected $description = 'KuCoin: Get Currencies (ua) -> 更新 platform_withdraw（chainName / 手续费 / 精度 / 确认数）';

    // KuCoin Public API Host
    private const BASE_URL = 'https://api.kucoin.com';

    // 文档路径
    private const PATH = '/api/ua/v1/asset/currencies';

    public function handle()
    {
        $platform = CurrencyQuotation::PLATFORM_KUCOIN;

        $tryMax  = max(1, (int)$this->option('try'));
        $batchSz = max(10, (int)$this->option('batch'));

        $client = new Client([
            'base_uri' => self::BASE_URL,
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        // 1) 先尝试不带任何 query 拉取全量数据
        $first = $this->requestKucoin($client, [], $tryMax);
        if ($first['ok'] && $this->isKucoinOk($first['json'])) {
            $data = $first['json']['data'] ?? null;
            if (is_array($data)) {
                $ret = $this->updateFromData($data, $platform);
                $this->info("KuCoin done (no-query). updated={$ret['updated']}, skipped={$ret['skipped']}");
                return 0;
            }
        }

        // 2) 如果不带参数不行，则用本地币种分批请求
        $this->warn('KuCoin 不带参数请求未得到有效 data，改为分批 currencyList 请求。');

        $symbols = Currency::query()->pluck('name')->toArray();
        if (!$symbols) {
            $this->error('本地 Currency 表为空，无法分批请求 currencyList。');
            return 2;
        }

        $updatedAll = 0;
        $skippedAll = 0;

        $chunks = array_chunk($symbols, $batchSz);
        foreach ($chunks as $idx => $chunk) {
            $params = [
                'currencyList' => strtoupper(implode(',', array_map('strval', $chunk))),
            ];

            $resp = $this->requestKucoin($client, $params, $tryMax);
            if (!$resp['ok']) {
                $this->warn("batch " . ($idx + 1) . "/" . count($chunks) . " 请求失败：" . $resp['err']);
                continue;
            }

            if (!$this->isKucoinOk($resp['json'])) {
                $this->warn("batch " . ($idx + 1) . "/" . count($chunks) . " 返回异常：" . $this->jsonPreview($resp['json']));
                continue;
            }

            $data = $resp['json']['data'] ?? null;
            if (!is_array($data)) {
                $this->warn("batch " . ($idx + 1) . "/" . count($chunks) . " data 不是数组");
                continue;
            }

            $ret = $this->updateFromData($data, $platform);
            $updatedAll += $ret['updated'];
            $skippedAll += $ret['skipped'];

            usleep(80 * 1000); // 频率限制规避
        }

        $this->info("KuCoin done. updated={$updatedAll}, skipped={$skippedAll}");
        return 0;
    }

    private function requestKucoin(Client $client, array $query, int $tryMax): array
    {
        $lastErr = '';

        for ($i = 1; $i <= $tryMax; $i++) {
            try {
                $resp = $client->request('GET', self::PATH, [
                    'query' => $query,
                ]);

                $code = (int)$resp->getStatusCode();
                $raw  = (string)$resp->getBody();

                if ($code < 200 || $code >= 300) {
                    $lastErr = "HTTP {$code}: " . mb_substr($raw, 0, 500);
                    sleep($i);
                    continue;
                }

                $json = json_decode($raw, true);
                if (!is_array($json)) {
                    $lastErr = 'JSON parse error';
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

    private function isKucoinOk($json): bool
    {
        if (!is_array($json)) return false;
        $code = $json['code'] ?? null;
        return (string)$code === '200000';
    }

    private function updateFromData(array $data, int $platform): array
    {
        $updated = 0;
        $skipped = 0;

        foreach ($data as $item) {
            if (!is_array($item)) { $skipped++; continue; }

            $currency = isset($item['currency']) ? strtoupper((string)$item['currency']) : null;
            if (!$currency) { $skipped++; continue; }

            $items = $item['items'] ?? null;
            if (!is_array($items) || empty($items)) { $skipped++; continue; }

            foreach ($items as $it) {
                if (!is_array($it)) { $skipped++; continue; }

                $chainName = isset($it['chainName']) ? strtoupper(trim((string)$it['chainName'])) : null;
                $canWd = $it['isWithdrawEnabled'] ?? null;
                $canDep = $it['isDepositEnabled'] ?? null;

                if ($canWd === null || $canDep === null) { $skipped++; continue; }

                $isWithdraw = $this->to01($canWd);
                $isDeposit  = $this->to01($canDep);

                // --- 核心手续费逻辑：费率优先 ---
                $feeRate = (float)($it['withdrawFeeRate'] ?? 0);
                $minFee = $it['minWithdrawFee'] ?? 0;

                if ($feeRate > 0) {
                    // 费率大于0，存百分比格式，如 "0.2%"
                    $withdrawFee = ($feeRate * 100) . '%';
                } else {
                    // 费率为0，存固定最小手续费
                    $withdrawFee = $minFee;
                }

                // 提取精度与确认数
                $withdrawPrecision = $it['withdrawPrecision'] ?? null;
                $confirmNum = $it['confirms'] ?? null;

                $network = $chainName !== '' ? $chainName : null;

                // 调用扩展后的 updateRecord 方法
                $ok = PlatformWithdraw::updateRecord(
                    $currency, 
                    $platform, 
                    $network, 
                    $isWithdraw, 
                    $isDeposit,
                    (string)$withdrawFee,
                    $withdrawPrecision,
                    $confirmNum
                );

                if ($ok) $updated++; else $skipped++;
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    private function to01($v): int
    {
        if (is_bool($v)) return $v ? 1 : 0;
        if (is_numeric($v)) return ((int)$v) === 1 ? 1 : 0;
        $s = strtolower(trim((string)$v));
        return in_array($s, ['1', 'true', 'yes', 'y', 'on'], true) ? 1 : 0;
    }

    private function jsonPreview($json): string
    {
        $s = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($s) ? mb_substr($s, 0, 300) : '';
    }
}