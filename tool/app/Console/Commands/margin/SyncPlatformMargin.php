<?php

namespace App\Console\Commands\margin;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Client;

class SyncPlatformMargin extends Command
{
    protected $signature = 'sync:platform-margin';
    protected $description = '最终高可用版：全平台请求加固，带压缩传输与下架清理';

    const PLATFORM_HUOBI  = 1;
    const PLATFORM_BIANCE = 2;
    const PLATFORM_OKEX   = 3;
    const PLATFORM_GATE   = 4;
    const PLATFORM_MEXC   = 5;
    const PLATFORM_KUCOIN = 8;
    const PLATFORM_BITGET = 15;
    const PLATFORM_BYBIT  = 16;
    const PLATFORM_BITMART= 17;

    private $currencyMatchMap = [];
    private $client;
    private $processedIds = [];

    // 请求公共配置：所有请求都加到 60s+ 级别
    private $guzzleConfig = [
        'connect_timeout' => 20, // 连接建立等待 20s
        'read_timeout'    => 60, // 数据传输等待 60s
        'timeout'         => 90, // 总超时 90s
    ];

    public function __construct()
    {
        parent::__construct();
        $this->client = new Client([
            'verify'   => false,
            'headers'  => [
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept-Encoding' => 'gzip, deflate', // 强制开启压缩，大幅解决 Binance 超时问题
            ]
        ]);
    }

    public function handle()
    {
        $this->info(">>> 任务开始: " . date('Y-m-d H:i:s'));
        
        // 增加 PHP 脚本运行限制
        ini_set('memory_limit', '1024M'); 
        set_time_limit(600);

        $this->currencyMatchMap = DB::table('currency_match')->pluck('id', 'symbol')->toArray();
        $this->info("系统已加载 " . count($this->currencyMatchMap) . " 个有效交易对。");

        $methods = [
            'syncBinance', 'syncOKX', 'syncBybit', 'syncBitget', 
            'syncHTX', 'syncGate', 'syncMEXC', 'syncKucoin', 'syncBitmart'
        ];

        foreach ($methods as $method) {
            $this->info("--- 正在处理: {$method} ---");
            $this->$method();
        }

        $this->info(">>> 所有平台同步完成: " . date('Y-m-d H:i:s'));
        // --- 核心新增：打印统计报表 ---
        $this->printSummaryReport();
    }

    private function updateMargin($rawSymbol, $platform, $isMargin)
    {
        $cleanSymbol = strtoupper(str_replace(['-', '_', '/', ' '], '', $rawSymbol));
        if (!isset($this->currencyMatchMap[$cleanSymbol])) return;

        $currencyMatchId = $this->currencyMatchMap[$cleanSymbol];
        $this->processedIds[$platform][] = $currencyMatchId;

        DB::table('platform_margin')->updateOrInsert(
            ['currency_match_id' => $currencyMatchId, 'platform' => $platform],
            ['is_margin' => $isMargin ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')]
        );
    }

    private function cleanDefunctSymbols($platform)
    {
        if (empty($this->processedIds[$platform])) {
            $this->warn("平台 [{$platform}] 本次数据为空，跳过清理。");
            return;
        }
        $deleted = DB::table('platform_margin')
            ->where('platform', $platform)
            ->whereNotIn('currency_match_id', $this->processedIds[$platform])
            ->delete();
        if ($deleted > 0) $this->info("平台 [{$platform}] 清理了 {$deleted} 条下架数据。");
    }

    // --- 各交易所逻辑（全部应用全局超时加固） ---

    private function syncBinance() {
        try {
            // 过滤 SPOT 权限，进一步减少体积
            $res = $this->client->get("https://api.binance.com/api/v3/exchangeInfo?permissions=SPOT", $this->guzzleConfig);
            $data = json_decode($res->getBody(), true);
            foreach ($data['symbols'] as $s) {
                $this->updateMargin($s['symbol'], self::PLATFORM_BIANCE, ($s['isMarginTradingAllowed'] ?? false));
            }
            $this->cleanDefunctSymbols(self::PLATFORM_BIANCE);
        } catch (\Exception $e) { $this->error("Binance: " . $e->getMessage()); }
    }

    private function syncOKX() {
        try {
            $res = $this->client->get("https://www.okx.com/api/v5/public/instruments?instType=SPOT", $this->guzzleConfig);
            $data = json_decode($res->getBody(), true);
            foreach ($data['data'] as $s) {
                $isMargin = !empty($s['lever']) && floatval($s['lever']) > 1;
                $this->updateMargin($s['instId'], self::PLATFORM_OKEX, $isMargin);
            }
            $this->cleanDefunctSymbols(self::PLATFORM_OKEX);
        } catch (\Exception $e) { $this->error("OKX: " . $e->getMessage()); }
    }

    private function syncBybit() {
        try {
            $res = $this->client->get("https://api.bybit.com/v5/market/instruments-info?category=spot", $this->guzzleConfig);
            $data = json_decode($res->getBody(), true);
            foreach ($data['result']['list'] as $s) {
                $this->updateMargin($s['symbol'], self::PLATFORM_BYBIT, ($s['marginTrading'] !== 'none'));
            }
            $this->cleanDefunctSymbols(self::PLATFORM_BYBIT);
        } catch (\Exception $e) { $this->error("Bybit: " . $e->getMessage()); }
    }

    private function syncBitget() {
        $this->info("Fetching Bitget (Margin Common Currencies)...");
        try {
            // 这个接口专门返回支持杠杆交易的币对详情
            $res = $this->client->get("https://api.bitget.com/api/v2/margin/currencies", $this->guzzleConfig);
            $resData = json_decode($res->getBody(), true);
            
            if (!isset($resData['data']) || !is_array($resData['data'])) {
                $this->error("Bitget 响应格式错误或 Code 非 00000");
                return;
            }

            foreach ($resData['data'] as $s) {
                // 1. 基础检查：必须有 symbol
                if (!isset($s['symbol'])) continue;

                // 2. 状态检查：status 为 "1" 代表在线
                $isOnline = (isset($s['status']) && $s['status'] === '1');
                
                // 3. 杠杆能力检查：只要全仓或逐仓最大倍数 > 1，或者可借贷
                $hasLeverage = (
                    (isset($s['maxCrossedLeverage']) && floatval($s['maxCrossedLeverage']) > 1) ||
                    (isset($s['maxIsolatedLeverage']) && floatval($s['maxIsolatedLeverage']) > 1) ||
                    ($s['isBorrowable'] ?? false) === true
                );

                $isMargin = ($isOnline && $hasLeverage);

                // 更新数据库
                $this->updateMargin($s['symbol'], self::PLATFORM_BITGET, $isMargin);
            }

            // 清理脏数据
            $this->cleanDefunctSymbols(self::PLATFORM_BITGET);
            $this->info("Bitget 处理完成。");

        } catch (\Exception $e) { 
            $this->error("Bitget Error: " . $e->getMessage()); 
        }
    }

    private function syncHTX() {
        return true;
        $this->info("Fetching HTX (V2 Settings - Using 'lr' field)...");
        try {
            $res = $this->client->get("https://api.huobi.pro/v2/settings/common/symbols", $this->guzzleConfig);
            $resData = json_decode($res->getBody(), true);
            
            if (!isset($resData['data']) || !is_array($resData['data'])) {
                $this->error("HTX 返回格式非法");
                return;
            }

            $count = 0;
            $marginCount = 0;

            foreach ($resData['data'] as $s) {
                // 1. 获取 Base(bc) 和 Quote(qc) 拼接 Symbol
                // 注意：你提供的示例里是 bc 和 qc，之前是 sc 和 qu，这里做兼容处理
                $base = $s['bc'] ?? ($s['sc'] ?? '');
                $quote = $s['qc'] ?? ($s['qu'] ?? '');
                
                if (empty($base) || empty($quote)) continue;
                $sym = strtoupper($base . $quote);

                // 2. 状态检查：必须是交易对在线 (state = online)
                if (($s['state'] ?? '') !== 'online') continue;

                // 3. 杠杆逻辑修正：
                // lr: Leverage Ratio (杠杆倍数)
                // 示例中 "lr": 5 代表支持 5 倍杠杆。如果 > 1 则视为支持杠杆。
                $leverage = isset($s['lr']) ? floatval($s['lr']) : 0;
                $isMargin = ($leverage > 1);

                $this->updateMargin($sym, self::PLATFORM_HUOBI, $isMargin);

                $count++;
                if ($isMargin) $marginCount++;
            }

            // 执行清理逻辑
            $this->cleanDefunctSymbols(self::PLATFORM_HUOBI);
            
            $this->info("HTX 处理完成: 总处理 {$count} 个，标记杠杆 {$marginCount} 个。");

        } catch (\Exception $e) { 
            $this->error("HTX Error: " . $e->getMessage()); 
        }
    }

    private function syncGate() {
        $this->info("Fetching Gate.io (Borrowable Pairs)...");
        try {
            // 这个接口专门返回可借贷（即支持杠杆）的交易对
            $res = $this->client->get("https://api.gateio.ws/api/v4/margin/uni/currency_pairs", $this->guzzleConfig);
            $data = json_decode($res->getBody(), true);
            
            if (is_array($data)) {
                foreach ($data as $s) {
                    if (isset($s['currency_pair'])) {
                        // 只要出现在这个列表里，就是支持杠杆的
                        if($s['leverage'] > 1){
                            $this->updateMargin($s['currency_pair'], self::PLATFORM_GATE, true);
                        }
                       
                    }
                }
            }
            // 剩下的在 updateMargin 中没出现的会被 cleanDefunctSymbols 删掉/置为不支持
            $this->cleanDefunctSymbols(self::PLATFORM_GATE);
        } catch (\Exception $e) { $this->error("Gate Error: " . $e->getMessage()); }
    }

    private function syncMEXC() {
        try {
            $res = $this->client->get("https://api.mexc.com/api/v3/exchangeInfo", $this->guzzleConfig);
            $data = json_decode($res->getBody(), true);
            foreach ($data['symbols'] as $s) {
                $raw = $s['isMarginTradingAllowed'] ?? false;
                // 只要不是 false 且不是 'false'，且值为 true/1/'true'
                $isMargin = $raw?1:0;
                $this->updateMargin($s['symbol'], self::PLATFORM_MEXC, $isMargin);
            }
            $this->cleanDefunctSymbols(self::PLATFORM_MEXC);
        } catch (\Exception $e) { $this->error("MEXC: " . $e->getMessage()); }
    }

    private function syncKucoin() {
        try {
            $res = $this->client->get("https://api.kucoin.com/api/v1/symbols", $this->guzzleConfig);
            $data = json_decode($res->getBody(), true);
            foreach ($data['data'] as $s) {
                $this->updateMargin($s['symbol'], self::PLATFORM_KUCOIN, ($s['isMarginEnabled'] ?? false));
            }
            $this->cleanDefunctSymbols(self::PLATFORM_KUCOIN);
        } catch (\Exception $e) { $this->error("Kucoin: " . $e->getMessage()); }
    }

    private function syncBitmart() {
        return true;
        try {
            $apiKey = (string) env('BITMART_API_KEY', '');
            $apiSecret = (string) env('BITMART_API_SECRET', '');
            $memo = (string) env('BITMART_API_MEMO', '');
            $ts = (string)(time() * 1000); $path = "/spot/v1/margin/isolated/symbols";
            $sign = hash_hmac('sha256', $ts . "#" . $memo . "#GET" . $path, $apiSecret);
            $res = $this->client->get("https://api-cloud.bitmart.com" . $path, array_merge($this->guzzleConfig, [
                'headers' => ['X-BM-KEY' => $apiKey, 'X-BM-TIMESTAMP' => $ts, 'X-BM-SIGN' => $sign]
            ]));
            $data = json_decode($res->getBody(), true);
            $symbols = $data['data']['symbols'] ?? [];
            foreach ($symbols as $s) { $this->updateMargin($s, self::PLATFORM_BITMART, true); }
            $this->cleanDefunctSymbols(self::PLATFORM_BITMART);
        } catch (\Exception $e) { $this->error("Bitmart: " . $e->getMessage()); }
    }
    /**
     * 在脚本结尾打印各平台杠杆状态统计
     */
    private function printSummaryReport()
    {
        $this->info("\n" . str_repeat("=", 50));
        $this->info("📊 各平台杠杆状态统计报表 (系统内交易对)");
        $this->info(str_repeat("=", 50));

        // 定义平台名称映射
        $platformNames = [
            1 => 'HTX', 2 => 'Binance', 3 => 'OKX', 4 => 'Gate', 5 => 'MEXC',
            8 => 'Kucoin', 15 => 'Bitget', 16 => 'Bybit', 17 => 'Bitmart'
        ];

        // 获取统计数据
        $stats = DB::table('platform_margin')
            ->select('platform', 'is_margin', DB::raw('count(*) as total'))
            ->groupBy('platform', 'is_margin')
            ->get();

        $tableData = [];
        $dataMap = [];

        foreach ($stats as $stat) {
            $dataMap[$stat->platform][$stat->is_margin] = $stat->total;
        }

        foreach ($platformNames as $id => $name) {
            $canMargin = $dataMap[$id][1] ?? 0;
            $noMargin  = $dataMap[$id][0] ?? 0;
            
            // 获取示例 (只拿支持杠杆的前3个)
            $examples = DB::table('platform_margin as pm')
                ->join('currency_match as cm', 'pm.currency_match_id', '=', 'cm.id')
                ->where('pm.platform', $id)
                ->where('pm.is_margin', 1)
                ->limit(3)
                ->pluck('cm.symbol')
                ->implode(', ');

            $tableData[] = [
                'Platform'   => "({$id}) " . $name,
                'Support(1)' => $canMargin,
                'None(0)'    => $noMargin,
                'Examples'   => $examples ?: '--'
            ];
        }

        // 以表格形式美化输出
        $this->table(['平台ID/名称', '支持杠杆', '不支持杠杆', '示例(支持)'], $tableData);
        $this->info(str_repeat("=", 50) . "\n");
    }
}
