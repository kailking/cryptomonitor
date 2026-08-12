<?php

namespace App\Console\Commands\withdraw_info;

use App\Model\CurrencyQuotation;
use App\Model\PlatformWithdraw;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class UpdatePhemexWithdraw extends Command
{
    protected $signature = 'update_phemex_withdraw {--try=3}';
    protected $description = 'Phemex Spot: 全币种充提同步（适配单币种结构 + 严格限流版）';

    private const BASE_URL = 'https://api.phemex.com';
    private const WITHDRAW_PATH = '/phemex-withdraw/wallets/api/asset/info';
    private const DEPOSIT_PATH = '/phemex-deposit/wallets/api/chainCfg';
    private const PRODUCTS_PATH = '/public/products';

    public function handle()
    {
        $platform = CurrencyQuotation::PLATFORM_PHEMEX ?? 22;
        $tryMax = max(1, (int)$this->option('try'));

        $apiKey = env('PHEMEX_KEY');
        $apiSecret = env('PHEMEX_SECRET');

        if (!$apiKey || !$apiSecret) {
            $this->error("未在 .env 中检测到 PHEMEX_KEY 或 PHEMEX_SECRET");
            return 1;
        }

        $client = new Client([
            'base_uri' => self::BASE_URL,
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);

        // 1. 获取币种列表
        $this->info("正在获取 Phemex 现货币种列表...");
        $coins = $this->getAvailableCoins($client);
        if (empty($coins)) {
            $this->error("无法获取币种列表。");
            return 1;
        }

        $withdrawRawList = [];
        $depositRawList = [];

        // 2. 逐个币种扫描
        $totalCoins = count($coins);
        $this->info("开始扫描 {$totalCoins} 个币种...");
        $bar = $this->output->createProgressBar($totalCoins);
        
        foreach ($coins as $coin) {
            $startTime = microtime(true);

            // 🚀 获取提现数据
            $wd = $this->fetchDataWithAuth($client, self::WITHDRAW_PATH, $apiKey, $apiSecret, $tryMax, ['currency' => $coin]);
            if (!empty($wd)) {
                // 如果返回的是单个对象（单币种模式），封装进数组
                $withdrawRawList[] = isset($wd['currency']) ? $wd : $wd[0];
            }

            // 🚀 获取充值数据
            $dp = $this->fetchDataWithAuth($client, self::DEPOSIT_PATH, $apiKey, $apiSecret, $tryMax, ['currency' => $coin]);
            if (!empty($dp)) {
                // 充值接口针对单币种可能返回的是 [ChainObj, ChainObj] 数组
                if (isset($dp[0])) {
                    $depositRawList = array_merge($depositRawList, $dp);
                } else {
                    $depositRawList[] = $dp;
                }
            }

            // 🚀 严格频率控制 (1.5秒跑一个币，约 80次/min)
            $elapsed = microtime(true) - $startTime;
            $sleepNeeded = 1.5 - $elapsed;
            if ($sleepNeeded > 0) {
                usleep($sleepNeeded * 1000000);
            }

            $bar->advance();
        }
        $bar->finish();
        $this->line("");

        // 3. 合并与更新
        $this->info("正在写入数据库...");
        $mergedData = $this->mergeChainData($withdrawRawList, $depositRawList);
        $ret = $this->updateToDatabase($mergedData, $platform);

        $this->info("Phemex 同步成功！总计处理: {$ret['count']} 条链。");
        return 0;
    }

    /**
     * 签名请求封装
     */
    private function fetchDataWithAuth($client, $path, $key, $secret, $try, $params)
    {
        $queryString = http_build_query($params);
        for ($i = 1; $i <= $try; $i++) {
            try {
                $expiry = time() + 60;
                $payload = $path . $queryString . $expiry;
                $signature = hash_hmac('sha256', $payload, $secret);

                $resp = $client->request('GET', $path, [
                    'query'   => $params,
                    'headers' => [
                        'x-phemex-access-token'      => $key,
                        'x-phemex-request-expiry'    => $expiry,
                        'x-phemex-request-signature' => $signature,
                    ]
                ]);

                if ($resp->getStatusCode() == 429) {
                    $this->error("\n🚨 触发 429 限频，强制终止！");
                    exit(1);
                }

                $json = json_decode((string)$resp->getBody(), true);
                if (isset($json['code']) && $json['code'] === 0) {
                    return $json['data'] ?? [];
                }
            } catch (\Exception $e) {
                if ($i == $try) return [];
                sleep(1);
            }
        }
        return [];
    }

    private function getAvailableCoins($client)
    {
        $resp = $client->get(self::PRODUCTS_PATH);
        $json = json_decode((string)$resp->getBody(), true);
        $coins = [];
        if (isset($json['data']['products'])) {
            foreach ($json['data']['products'] as $p) {
                if (($p['type'] ?? '') === 'Spot' && ($p['status'] ?? '') === 'Listed') {
                    $coins[] = $p['baseCurrency'];
                }
            }
        }
        return array_values(array_unique($coins));
    }

    private function mergeChainData(array $withdrawData, array $depositData): array
    {
        $merged = [];

        // 提现解析 (Data 是币种对象的集合)
        foreach ($withdrawData as $coin) {
            $currency = strtoupper($coin['currency'] ?? '');
            if (!$currency) continue;
            foreach ($coin['chainInfos'] ?? [] as $chain) {
                $net = strtoupper($chain['chainName'] ?? $currency);
                $merged[$currency][$net] = [
                    'withdraw' => (isset($chain['status']) && $chain['status'] === 'Active') ? 1 : 0,
                    'deposit'  => 0,
                    'fee'      => $chain['withdrawFeeRv'] ?? '0',
                    'confirms' => 0
                ];
            }
        }

        // 充值解析 (Data 是各条链对象的集合)
        foreach ($depositData as $chain) {
            $currency = strtoupper($chain['currency'] ?? '');
            if (!$currency) continue;
            $net = strtoupper($chain['chainName'] ?? $currency);
            
            if (!isset($merged[$currency][$net])) {
                $merged[$currency][$net] = ['withdraw' => 0, 'deposit' => 0, 'fee' => '0', 'confirms' => 0];
            }
            $merged[$currency][$net]['deposit'] = (isset($chain['status']) && $chain['status'] === 'Active') ? 1 : 0;
            $merged[$currency][$net]['confirms'] = (int)($chain['confirmations'] ?? 0);
        }

        return $merged;
    }

    private function updateToDatabase(array $mergedData, int $platform): array
    {
        $count = 0;
        foreach ($mergedData as $currency => $chains) {
            foreach ($chains as $network => $info) {
                PlatformWithdraw::updateRecord(
                    $currency, $platform, $network, 
                    $info['withdraw'], $info['deposit'], 
                    $info['fee'], 0, $info['confirms']
                );
                $count++;
            }
        }
        return ['count' => $count];
    }
}