<?php

namespace App\Console\Commands;

use App\Service\RedisService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;

class GetCurrencyPrice extends Command
{
    protected $signature = 'update_index_price';
    protected $description = '通过币安 API 更新平台首页价格';

    public function handle()
    {
        $redis = RedisService::getInstance(1);
        
        // 初始化 Guzzle 客户端
        $cli = new Client([
            'timeout' => 10,
            'verify'  => false, // 如果遇到 SSL 解析问题可保持 false
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            ],
        ]);

        // 1. 获取币安价格 (BTC, ETH)
        // 币安 V3 接口支持单个或多个 symbol 查询
        try {
            $prices = $this->fetchBinancePrices($cli, ['BTCUSDT', 'ETHUSDT']);
            
            if (isset($prices['BTCUSDT'])) {
                $redis->set('btc_price', sprintf('%.2f', $prices['BTCUSDT']));
                $this->info("BTC Price Updated: " . $prices['BTCUSDT']);
            }
            if (isset($prices['ETHUSDT'])) {
                $redis->set('eth_price', sprintf('%.2f', $prices['ETHUSDT']));
                $this->info("ETH Price Updated: " . $prices['ETHUSDT']);
            }
        } catch (\Exception $e) {
            $this->error("Binance API Error: " . $e->getMessage());
        }

        // 2. 非小号首页信息（保持原逻辑，获取 USDT/CNY 汇率）
        $url = 'https://dncapi.flink1.com/api/home/global?webp=1';
        try {
            $response = $cli->get($url);
            $content = json_decode($response->getBody()->getContents(), true);
            if (isset($content['msg']) && $content['msg'] === 'success') {
                $data = $content['data'] ?? [];
                if (array_key_exists('usdt_price_cny', $data)) {
                    $redis->set('usdt_price', (float)$data['usdt_price_cny']);
                }
            }
        } catch (\Exception $e) {
            $this->error("Feixiaohao API Error: " . $e->getMessage());
        }
    }

    /**
     * 调用币安 V3 接口获取最新价格
     */
    private function fetchBinancePrices(Client $cli, array $symbols): array
    {
        // 备用域名：如果 api.binance.com 报错，可换成 api1.binance.com 或 api2.binance.com
        $baseUrl = "https://api.binance.com/api/v3/ticker/price";
        
        // 构建查询参数：["BTCUSDT","ETHUSDT"] -> ["BTCUSDT","ETHUSDT"]
        $formattedSymbols = '["' . implode('","', $symbols) . '"]';
        
        try {
            $response = $cli->get($baseUrl, [
                'query' => ['symbols' => $formattedSymbols]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            $result = [];
            if (is_array($data)) {
                foreach ($data as $item) {
                    $result[$item['symbol']] = (float)$item['price'];
                }
            }
            return $result;
            
        } catch (GuzzleException $e) {
            // 记录日志，但不抛出异常中断 handle
            \Log::error("Binance Price Fetch Failed: " . $e->getMessage());
            return [];
        }
    }
}