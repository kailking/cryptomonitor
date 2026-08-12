<?php

namespace App\Console\Commands\withdraw_info;

use App\Model\Currency;
use App\Model\CurrencyQuotation;
use App\Model\PlatformWithdraw;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;

class UpdateNonkycWithdraw extends Command
{
    protected $signature = 'update_nonkyc_withdraw {--try=3}';
    protected $description = 'NonKYC: Get Currencies -> 更新 platform_withdraw（支持多链拆分与智能括号提取）';

    // NonKYC Public API
    private const BASE_URL = 'https://api.nonkyc.io';
    private const PATH = '/api/v2/asset/getlist';

    public function handle()
    {
        $platform = CurrencyQuotation::PLATFORM_NONKYC; // 确保平台ID正确，例如 18
        // PlatformWithdraw::where("platform",$platform)->delete();
        $tryMax  = max(1, (int)$this->option('try'));

        $client = new Client([
            'base_uri' => self::BASE_URL,
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ],
        ]);

        $this->info("Fetching NonKYC asset list...");
        $resp = $this->requestNonkyc($client, $tryMax);

        if (!$resp['ok']) {
            $this->error("NonKYC 请求失败: " . $resp['err']);
            return 1;
        }

        $data = $resp['json'];
        if (!is_array($data)) {
            $this->error("NonKYC 返回数据不是数组");
            return 2;
        }

        $ret = $this->updateFromData($data, $platform);
        
        $this->info("NonKYC withdraw info done. updated={$ret['updated']}, skipped={$ret['skipped']}");
        return 0;
    }

    private function requestNonkyc(Client $client, int $tryMax): array
    {
        $lastErr = '';

        for ($i = 1; $i <= $tryMax; $i++) {
            try {
                $resp = $client->request('GET', self::PATH);

                $code = (int)$resp->getStatusCode();
                $raw  = (string)$resp->getBody();

                if ($code < 200 || $code >= 300) {
                    $lastErr = "HTTP {$code}: " . mb_substr($raw, 0, 500);
                    sleep($i);
                    continue;
                }

                $json = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
                    $lastErr = 'JSON parse error: ' . json_last_error_msg();
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

    private function updateFromData(array $data, int $platform): array
    {
        $updated = 0;
        $skipped = 0;

        foreach ($data as $item) {
            if (!is_array($item)) {
                $skipped++;
                continue;
            }

            $rawTicker = isset($item['ticker']) ? trim((string)$item['ticker']) : '';
            if (!$rawTicker) {
                $skipped++;
                continue;
            }

            // 🚀 核心逻辑 1：优先处理 network 字段，正则提取括号内容
            $rawNetworkField = isset($item['network']) ? trim((string)$item['network']) : '';
            // 匹配诸如 "SOL MAIN CHAIN (SOL)" 或者 "Binance Smart Chain (BSC)"
            if (preg_match('/\((.*?)\)/', $rawNetworkField, $matches)) {
                $rawNetworkField = trim($matches[1]); // 提取到 "SOL" 或 "BSC"
            }

            // 🚀 核心逻辑 2：结合 ticker 拆分
            if (strpos($rawTicker, '-') !== false) {
                // 拆分为 USDT 和 BEP20
                $parts = explode('-', $rawTicker, 2);
                $currency = strtoupper($parts[0]);
                // 如果提取到了括号内容，优先用括号内容 (BSC)，否则用横杠后面的部分 (BEP20)
                $network = $rawNetworkField !== '' ? strtoupper($rawNetworkField) : strtoupper($parts[1]); 
            } else {
                // 没有横杠的主网币，例如 0G 或 SOL
                $currency = strtoupper($rawTicker);
                $network = $rawNetworkField !== '' ? strtoupper($rawNetworkField) : $currency;
            }

            // 充提状态
            $canWd  = $item['withdrawalActive'] ?? null;
            $canDep = $item['depositActive'] ?? null;
            
            if ($canWd === null || $canDep === null) {
                $skipped++;
                continue;
            }

            $isWithdraw = $this->to01($canWd);
            $isDeposit  = $this->to01($canDep);

            // 手续费
            $withdrawFee = isset($item['withdrawFee']) ? (string)$item['withdrawFee'] : '0';

            // 精度与确认数
            $withdrawPrecision = isset($item['withdrawDecimals']) ? (int)$item['withdrawDecimals'] : 0;
            $confirmNum = isset($item['confirmsRequired']) ? (int)$item['confirmsRequired'] : 0;

            // 写入数据库
            $ok = PlatformWithdraw::updateRecord(
                $currency, 
                $platform, 
                $network, 
                $isWithdraw, 
                $isDeposit,
                $withdrawFee,
                $withdrawPrecision,
                $confirmNum
            );

            if ($ok) {
                $updated++;
            } else {
                $skipped++;
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
}