<?php

namespace App\Console\Commands\consumer;

use App\Service\RedisService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DepthSymbolConsumer extends Command
{
    protected $signature = 'start_depth_symbol_consumer
        {--workers=24 : 总进程数(建议24)}
        {--worker_id=0 : 当前进程编号(0..workers-1) 仅用于日志}
        {--queue=depth_symbol_list : 队列key名（redis9）}
        {--batch=500 : 每轮最多pop多少个symbol}
        {--brpop_timeout=1 : BRPOP阻塞秒数(0=一直阻塞)}
        {--update_batch=1000 : 每次批量更新多少条diff}
        {--levels=5 : 扫前几档(plus统计默认用这个)}
        {--cycle_sleep_us=2000 : 每轮微睡(微秒)}';

    protected $description = '队列消费者：从 redis9 list pop symbol；批量读 redis3 depth；两边完整才更新 diff；1档字段+5档plus字段；mul 热更新只做批内缓存';

    private const MIN_USDT_NOTIONAL = 0;

    // 金额阈值：amount_level(_plus) = 1
    private const AMOUNT_LEVEL_USDT = 1000.0;

    public function handle()
    {
        $workers  = max(1, (int)$this->option('workers'));
        $workerId = (int)$this->option('worker_id');
        if ($workerId < 0) $workerId = 0;
        if ($workerId >= $workers) $workerId = $workers - 1;

        $queue        = (string)$this->option('queue') ?: 'depth_symbol_list';
        $batchSyms    = max(1, (int)$this->option('batch'));
        $brTimeout    = (int)$this->option('brpop_timeout');
        if ($brTimeout < 0) $brTimeout = 1;

        $updateBatch  = max(1, (int)$this->option('update_batch'));
        $levels       = max(1, (int)$this->option('levels'));
        $cycleSleepUs = max(0, (int)$this->option('cycle_sleep_us'));

        $redis3 = RedisService::getInstance(3); // depth keys
        $redis9 = RedisService::getInstance(9); // queue

        // 1) 启动：加载 diff 全量索引（不做 shard）
        [$diffMeta, $symbolToIds] = $this->loadIndexAll();

        if (!$diffMeta || !$symbolToIds) {
            $this->error("no diff index built");
            return 1;
        }

        $this->info("index loaded: diffs=" . count($diffMeta) .
            ", symbolMap=" . count($symbolToIds) .
            ", worker={$workerId}/{$workers}, queue={$queue}");

        while (true) {
            // 2) pop 一批 symbols
            $syms = $this->popBatchSymbols($redis9, $queue, $batchSyms, $brTimeout);
            if (!$syms) {
                if ($cycleSleepUs > 0) usleep($cycleSleepUs);
                continue;
            }

            $syms = array_values(array_unique($syms));
            if (!$syms) {
                if ($cycleSleepUs > 0) usleep($cycleSleepUs);
                continue;
            }

            // 3) dirty diff ids
            $dirtyIds = [];
            foreach ($syms as $s) {
                if (!isset($symbolToIds[$s])) continue;
                foreach ($symbolToIds[$s] as $id) $dirtyIds[$id] = true;
            }
            if (!$dirtyIds) {
                if ($cycleSleepUs > 0) usleep($cycleSleepUs);
                continue;
            }
            $dirtyIds = array_keys($dirtyIds);

            // 4) collect needed keys
            $needKeys = [];
            foreach ($dirtyIds as $id) {
                $m = $diffMeta[(int)$id] ?? null;
                if (!$m) continue;
                $needKeys[$m['buy_key']] = true;
                $needKeys[$m['sell_key']] = true;
            }
            $keys = array_keys($needKeys);
            if (!$keys) {
                if ($cycleSleepUs > 0) usleep($cycleSleepUs);
                continue;
            }

            // 5) pipeline get
            $depthMap = $this->pipelineGet($redis3, $keys);

            // 6)补一轮缺失
            $missing = [];
            foreach ($keys as $k) {
                if (!isset($depthMap[$k]) || $depthMap[$k] === null || $depthMap[$k] === '') {
                    $missing[] = $k;
                }
            }
            if ($missing) {
                $moreMap = $this->pipelineGet($redis3, $missing);
                foreach ($moreMap as $k => $v) {
                    if ($v !== null && $v !== '') $depthMap[$k] = $v;
                }
            }

            // 7) decode cache
            $arrCache = [];
            foreach ($keys as $k) {
                $json = $depthMap[$k] ?? null;
                if (!$json) { $arrCache[$k] = null; continue; }
                $arr = json_decode($json, true);
                $arrCache[$k] = (is_array($arr) && !empty($arr)) ? $arr : null;
            }

            // 批内缓存
            $mulCache  = [];
            $bestCache = [];
            $plusCache = []; // 5档统计缓存：同一个 key+levels 只算一次

            // 8) build updates
            $updates = [];
            foreach ($dirtyIds as $id) {
                $id = (int)$id;
                $m = $diffMeta[$id] ?? null;
                if (!$m) continue;

                $buyKey  = $m['buy_key'];   // ask
                $sellKey = $m['sell_key'];  // bid

                $buyArr  = $arrCache[$buyKey] ?? null;
                $sellArr = $arrCache[$sellKey] ?? null;

                // 强制完整
                if (!$buyArr || !$sellArr) continue;

                $buyPlatform  = (int)$m['buy_platform'];
                $sellPlatform = (int)$m['sell_platform'];

                // ====== 1档(best) ======
                $buyBest = $this->bestFromArrCached(
                    $buyKey, $buyArr, (string)$m['quote_name'], $buyPlatform, $levels, $mulCache, $bestCache
                );
                if (!$buyBest) continue;

                $sellBest = $this->bestFromArrCached(
                    $sellKey, $sellArr, (string)$m['sell_quote_name'], $sellPlatform, $levels, $mulCache, $bestCache
                );
                if (!$sellBest) continue;

                $buyUsdtBest  = (float)$buyBest['usdt_price'];
                $sellUsdtBest = (float)$sellBest['usdt_price'];
                if ($buyUsdtBest <= 0) continue;

                $priceDiffBest = (($sellUsdtBest - $buyUsdtBest) / $buyUsdtBest) * 100.0;

                $tbBest = round($buyUsdtBest * (float)$buyBest['num'], 2);
                $tsBest = round($sellUsdtBest * (float)$sellBest['num'], 2);
                $amountLevelBest = ($tbBest > self::AMOUNT_LEVEL_USDT || $tsBest > self::AMOUNT_LEVEL_USDT) ? 1 : 0;

                // ====== plus(前N档加权均价+总量) ======
                $buyL = $this->effectivePlusLevels($buyPlatform, $levels);
                $sellL = $this->effectivePlusLevels($sellPlatform, $levels);

                $buyPlus = $this->calcPlusCached(
                    $buyKey, $buyArr, (string)$m['quote_name'], $buyPlatform, $buyL, $mulCache, $plusCache
                );
                $sellPlus = $this->calcPlusCached(
                    $sellKey, $sellArr, (string)$m['sell_quote_name'], $sellPlatform, $sellL, $mulCache, $plusCache
                );

                if (!$buyPlus || !$sellPlus) continue;

                $buyUsdtAvg  = (float)$buyPlus['usdt_avg_price'];
                $sellUsdtAvg = (float)$sellPlus['usdt_avg_price'];
                if ($buyUsdtAvg <= 0) continue;

                $priceDiffPlus = (($sellUsdtAvg - $buyUsdtAvg) / $buyUsdtAvg) * 100.0;

                $tbPlus = round($buyUsdtAvg * (float)$buyPlus['total_num'], 2);
                $tsPlus = round($sellUsdtAvg * (float)$sellPlus['total_num'], 2);
                $amountLevelPlus = ($tbPlus > self::AMOUNT_LEVEL_USDT || $tsPlus > self::AMOUNT_LEVEL_USDT) ? 1 : 0;

                $updates[$id] = [
                    // 1档（原字段）
                    'buy_price' => (float)$buyBest['price'],
                    'sell_price' => (float)$sellBest['price'],
                    'buy_num' => (float)$buyBest['num'],
                    'sell_num' => (float)$sellBest['num'],
                    'total_buy_price' => (float)$tbBest,
                    'total_sell_price' => (float)$tsBest,
                    'total_deal_price' => (float)($tbBest + $tsBest),
                    'price_diff' => (float)$priceDiffBest,
                    'amount_level' => (int)$amountLevelBest,

                    // plus（前N档统计字段）
                    'buy_price_plus' => (float)$buyPlus['avg_price'],
                    'sell_price_plus' => (float)$sellPlus['avg_price'],
                    'buy_num_plus' => (float)$buyPlus['total_num'],
                    'sell_num_plus' => (float)$sellPlus['total_num'],
                    'total_buy_plus' => (float)$tbPlus,
                    'total_sell_plus' => (float)$tsPlus,
                    'total_deal_plus' => (float)($tbPlus + $tsPlus), // 若你没有此字段可删掉
                    'price_diff_plus' => (float)$priceDiffPlus,
                    'amount_level_plus' => (int)$amountLevelPlus,
                ];
            }

            // 9) batch update
            if ($updates) {
                foreach (array_chunk($updates, $updateBatch, true) as $sub) {
                    $this->batchUpdate($sub);
                }
            }

            if ($cycleSleepUs > 0) usleep($cycleSleepUs);
        }
    }

    private function popBatchSymbols(\Redis $redis9, string $queue, int $batch, int $brTimeout): array
    {
        $out = [];

        $ret = $redis9->brPop([$queue], $brTimeout);
        if (is_array($ret) && count($ret) >= 2) {
            $out[] = (string)$ret[1];
        } else {
            return [];
        }

        for ($i = 1; $i < $batch; $i++) {
            $v = $redis9->rPop($queue);
            if ($v === false || $v === null || $v === '') break;
            $out[] = (string)$v;
        }

        return $out;
    }

    private function loadIndexAll(): array
    {
        $diffRows = DB::table('market_depth_diff')
            ->where('is_show', 1)
            ->where(function ($q) {
                $q->where('buy_platform', '<>', 11)
                  ->orWhere('sell_platform', '<>', 11);
            })
            ->select([
                'id',
                'currency_name',
                'quote_name',
                'sell_quote_name',
                'buy_platform',
                'sell_platform',
            ])
            ->get()
            ->all();

        if (!$diffRows) throw new \RuntimeException("no diff rows found");

        $diffMeta = [];
        $symbolToIds = [];

        foreach ($diffRows as $r) {
            $id = (int)$r->id;
            if ($id <= 0) continue;

            $currency  = (string)$r->currency_name;
            $buyQuote  = (string)$r->quote_name;
            $sellQuote = (string)$r->sell_quote_name;
            if ($currency === '' || $buyQuote === '' || $sellQuote === '') continue;

            $buyPlatform  = (int)$r->buy_platform;
            $sellPlatform = (int)$r->sell_platform;

            $buySymbol  = $currency . $buyQuote;
            $sellSymbol = $currency . $sellQuote;

            $buyKey  = "{$buySymbol}_{$buyPlatform}_2";   // ask
            $sellKey = "{$sellSymbol}_{$sellPlatform}_1"; // bid

            $diffMeta[$id] = [
                'id' => $id,
                'buy_symbol' => $buySymbol,
                'sell_symbol' => $sellSymbol,
                'buy_key' => $buyKey,
                'sell_key' => $sellKey,
                'quote_name' => $buyQuote,
                'sell_quote_name' => $sellQuote,
                'buy_platform' => $buyPlatform,
                'sell_platform' => $sellPlatform,
            ];

            $symbolToIds[$buySymbol][]  = $id;
            $symbolToIds[$sellSymbol][] = $id;
        }

        return [$diffMeta, $symbolToIds];
    }

    private function pipelineGet(\Redis $redis, array $keys): array
    {
        if (!$keys) return [];
        $redis->multi(\Redis::PIPELINE);
        foreach ($keys as $k) $redis->get($k);
        $res = $redis->exec();

        $out = [];
        foreach ($keys as $i => $k) $out[$k] = $res[$i] ?? null;
        return $out;
    }

    private function bestFromArrCached(
        string $key,
        array $arr,
        string $quoteName,
        int $platform,
        int $levels,
        array &$mulCache,
        array &$bestCache
    ): ?array {
        $ck = $key . '|' . $platform . '|' . $quoteName . '|' . $levels . '|best';
        if (array_key_exists($ck, $bestCache)) return $bestCache[$ck];

        $mul = $this->getMul($quoteName, $platform, $mulCache);

        $max = min($levels, count($arr));
        for ($i = 0; $i < $max; $i++) {
            $lvl = $arr[$i];
            if (!is_array($lvl) || count($lvl) < 2) continue;

            $price = (float)$lvl[0];
            $num   = (float)$lvl[1];
            if ($price <= 0 || $num <= 0) continue;

            $usdtPrice = $price * $mul;
            if ($usdtPrice * $num < self::MIN_USDT_NOTIONAL) continue;

            $bestCache[$ck] = [
                'price' => $price,
                'num' => $num,
                'usdt_price' => $usdtPrice,
            ];
            return $bestCache[$ck];
        }

        $bestCache[$ck] = null;
        return null;
    }

    /**
     * plus 统计：前N档加权平均价 + 总量
     * 返回：
     * - avg_price: 原计价币的加权均价
     * - total_num: 总量
     * - usdt_avg_price: USDT 口径均价（avg_price * mul）
     */
    private function calcPlusCached(
        string $key,
        array $arr,
        string $quoteName,
        int $platform,
        int $levels,
        array &$mulCache,
        array &$plusCache
    ): ?array {
        $ck = $key . '|' . $platform . '|' . $quoteName . '|' . $levels . '|plus';
        if (array_key_exists($ck, $plusCache)) return $plusCache[$ck];

        $mul = $this->getMul($quoteName, $platform, $mulCache);

        $max = min($levels, count($arr));
        $sumQty = 0.0;
        $sumPQ  = 0.0;

        for ($i = 0; $i < $max; $i++) {
            $lvl = $arr[$i];
            if (!is_array($lvl) || count($lvl) < 2) continue;

            $p = (float)$lvl[0];
            $q = (float)$lvl[1];
            if ($p <= 0 || $q <= 0) continue;

            // 若你想加 MIN_USDT_NOTIONAL 过滤，也可以加在这里（按单档 notional 过滤）
            $sumQty += $q;
            $sumPQ  += ($p * $q);
        }

        if ($sumQty <= 0 || $sumPQ <= 0) {
            $plusCache[$ck] = null;
            return null;
        }

        $avg = $sumPQ / $sumQty;

        $plusCache[$ck] = [
            'avg_price' => $avg,
            'total_num' => $sumQty,
            'usdt_avg_price' => $avg * $mul,
        ];
        return $plusCache[$ck];
    }

    /**
     * Bybit 只有 1 档：platform=16（你 Bybit 的 PLATFORM=16）
     * 如果以后还有 1 档平台，把 platform id 加进来即可
     */
    private function is_one_level_platform(int $platform): bool
    {
        return $platform === 16;
    }

    private function effectivePlusLevels(int $platform, int $levels): int
    {
        if ($this->is_one_level_platform($platform)) return 1;
        return max(1, $levels);
    }

    private function getMul(string $quote, int $platform, array &$cache): float
    {
        $k = "{$platform}|{$quote}";
        if (!isset($cache[$k])) {
            switch ($quote) {
                case 'BTC':
                    $cache[$k] = (float)get_platform_price('BTCUSDT', $platform);
                    break;
                case 'ETH':
                    $cache[$k] = (float)get_platform_price('ETHUSDT', $platform);
                    break;
                default:
                    $cache[$k] = 1.0;
            }
            if ($cache[$k] <= 0) $cache[$k] = 1.0;
        }
        return (float)$cache[$k];
    }

    private function batchUpdate(array $updates): void
    {
        $ids = array_keys($updates);
        if (!$ids) return;

        // ✅ 注意：你表里没有 total_deal_plus 的话，把它从 cols 和 SQL 里删掉
        $cols = [
            'buy_price','sell_price','buy_num','sell_num',
            'total_buy_price','total_sell_price',
            'total_deal_price','price_diff','amount_level',

            'buy_price_plus','sell_price_plus','buy_num_plus','sell_num_plus',
            'total_buy_plus','total_sell_plus',
            'price_diff_plus','amount_level_plus',
            // 'total_deal_plus',
        ];

        $case = [];
        foreach ($cols as $c) $case[$c] = "CASE id ";

        foreach ($updates as $id => $row) {
            $id = (int)$id;
            foreach ($cols as $c) {
                if (!array_key_exists($c, $row)) continue;
                $v = $row[$c];

                // tinyint 用 int；其余 float
                if (strpos($c, 'amount_level') !== false) {
                    $v = (int)$v;
                } else {
                    $v = (float)$v;
                }

                $case[$c] .= "WHEN {$id} THEN {$v} ";
            }
        }

        foreach ($cols as $c) $case[$c] .= "END";

        $idList = implode(',', array_map('intval', $ids));

        DB::statement("
            UPDATE market_depth_diff SET
              buy_price = {$case['buy_price']},
              sell_price = {$case['sell_price']},
              buy_num = {$case['buy_num']},
              sell_num = {$case['sell_num']},
              total_buy_price = {$case['total_buy_price']},
              total_sell_price = {$case['total_sell_price']},
              total_deal_price = {$case['total_deal_price']},
              price_diff = {$case['price_diff']},
              amount_level = {$case['amount_level']},

              buy_price_plus = {$case['buy_price_plus']},
              sell_price_plus = {$case['sell_price_plus']},
              buy_num_plus = {$case['buy_num_plus']},
              sell_num_plus = {$case['sell_num_plus']},
              total_buy_plus = {$case['total_buy_plus']},
              total_sell_plus = {$case['total_sell_plus']},
              price_diff_plus = {$case['price_diff_plus']},
              amount_level_plus = {$case['amount_level_plus']},

              updated_at = NOW()
            WHERE id IN ({$idList})
        ");
    }
}
