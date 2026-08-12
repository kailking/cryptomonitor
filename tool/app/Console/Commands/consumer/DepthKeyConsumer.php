<?php

namespace App\Console\Commands\consumer;

use App\Service\RedisService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DepthKeyConsumer extends Command
{
    protected $signature = 'start_depth_key_consumer
        {--queue=depth_key_list : 消费的key队列（redis9）}
        {--batch=2000 : 每批最多取多少个key}
        {--update_batch=1000 : 每次批量更新多少条diff}
        {--levels=5 : 扫前几档}
        {--sleep_us=2000 : 空转微睡(微秒)}';

    protected $description = '消费 depth_key 队列：每个 key 只 parse 一次 best，然后批量更新其关联 diff（更快）';

    /** 最小可成交金额（USDT） */
    private const MIN_USDT_NOTIONAL = 1;

    public function handle()
    {
        $queue       = (string)$this->option('queue') ?: 'depth_key_list';
        $batchKeys   = (int)$this->option('batch') ?: 2000;
        $updateBatch = (int)$this->option('update_batch') ?: 1000;
        $levels      = max(1, (int)$this->option('levels'));
        $sleepUs     = (int)$this->option('sleep_us') ?: 2000;

        $redis9 = RedisService::getInstance(9); // 队列
        $redis3 = RedisService::getInstance(3); // 深度数据（普通）

        // ===== 启动：一次性加载 diff，并构建索引 =====
        $this->info("loading diff index ...");

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

        if (!$diffRows) {
            $this->error("no diff rows found");
            return 1;
        }

        // diffMeta: id => {buy_key, sell_key, quote_name, sell_quote_name, buy_platform, sell_platform}
        $diffMeta = [];
        // keyToIds: depth_key => [diff_id...]
        $keyToIds = [];

        foreach ($diffRows as $r) {
            $id = (int)$r->id;
            if ($id <= 0) continue;

            $currency = (string)$r->currency_name;
            $buyQuote = (string)$r->quote_name;
            $sellQuote = (string)$r->sell_quote_name;

            $buyPlatform  = (int)$r->buy_platform;
            $sellPlatform = (int)$r->sell_platform;

            if ($currency === '' || $buyQuote === '' || $sellQuote === '') continue;

            $buySymbol  = $currency . $buyQuote;
            $sellSymbol = $currency . $sellQuote;

            // buy: ask side=2
            $buyKey  = "{$buySymbol}_{$buyPlatform}_2";
            // sell: bid side=1
            $sellKey = "{$sellSymbol}_{$sellPlatform}_1";

            $diffMeta[$id] = [
                'id' => $id,
                'buy_key' => $buyKey,
                'sell_key' => $sellKey,
                'quote_name' => $buyQuote,
                'sell_quote_name' => $sellQuote,
                'buy_platform' => $buyPlatform,
                'sell_platform' => $sellPlatform,
            ];

            $keyToIds[$buyKey][]  = $id;
            $keyToIds[$sellKey][] = $id;
        }

        $this->info("diff index loaded: diffs=" . count($diffMeta) . ", keys=" . count($keyToIds) . " queue={$queue}");

        // quote->mul 缓存（跨循环复用）
        $mulCache = [];

        while (true) {
            // 1) 阻塞取一个 key
            $first = $redis9->brPop([$queue], 1);
            if (!$first) {
                usleep($sleepUs);
                continue;
            }

            $keys = [(string)$first[1]];

            // 2) 批量再取更多 key
            $more = null;
            try {
                $more = $redis9->lPop($queue, $batchKeys - 1);
            } catch (\Throwable $e) {
                $more = [];
                for ($i = 0; $i < $batchKeys - 1; $i++) {
                    $x = $redis9->lPop($queue);
                    if (!$x) break;
                    $more[] = $x;
                }
            }

            if ($more) {
                if (!is_array($more)) $more = [$more];
                foreach ($more as $x) $keys[] = (string)$x;
            }

            $keys = array_values(array_unique(array_filter($keys)));
            if (!$keys) continue;

            // 3) pipeline GET 这一批 keys 的深度
            $depthMap = $this->pipelineGet($redis3, $keys); // key => json|null

            // 4) 对每个 key：只 parse 一次 best（每批局部缓存）
            $bestMap = []; // key => ['price','num','usdt_price'] or null

            foreach ($keys as $k) {
                $json = $depthMap[$k] ?? null;
                if (!$json) {
                    $bestMap[$k] = null;
                    continue;
                }

                // key 形如: BTCUSDT_2_2 => symbol=BTCUSDT, platform=2, side=2
                $parts = explode('_', $k);
                if (count($parts) < 3) {
                    $bestMap[$k] = null;
                    continue;
                }

                $side = (int)$parts[count($parts) - 1];
                $platform = (int)$parts[count($parts) - 2];
                // symbol 中可能包含 '_'? 你这套没有，所以安全：把前面拼回去
                $symbol = implode('_', array_slice($parts, 0, count($parts) - 2));

                // ⚠️ best 的 quoteName 取决于“这条 diff 的 quote”，仅从 key 无法直接知道
                // 所以：我们在计算 diff 时再用对应 diff 的 quoteName 来做 mul
                // 这里先只 json_decode 保存原数组，避免重复 decode：
                $arr = json_decode($json, true);
                if (!is_array($arr) || empty($arr)) {
                    $bestMap[$k] = null;
                    continue;
                }
                $bestMap[$k] = $arr; // 暂存数组，后面按不同 quote/platform 取 best（但仍然不会重复 decode）
            }

            // 5) 收集本批 key 影响的 diff_ids（去重）
            $dirtyIds = [];
            foreach ($keys as $k) {
                if (!isset($keyToIds[$k])) continue;
                foreach ($keyToIds[$k] as $id) {
                    $dirtyIds[$id] = true;
                }
            }
            if (!$dirtyIds) continue;

            $dirtyIds = array_keys($dirtyIds);

            // 6) 计算并收集 updates（重点：对同一个 key 的数组，不重复 json_decode）
            $updates = [];

            // keyBestCache：key|quote|platform|levels => bestResult
            // 因为同一个 key 可能被不同 quote（BTC/ETH/USDT）引用，mul 不同
            $keyBestCache = [];

            foreach ($dirtyIds as $id) {
                $id = (int)$id;
                if (!isset($diffMeta[$id])) continue;
                $m = $diffMeta[$id];

                $buyKey  = $m['buy_key'];
                $sellKey = $m['sell_key'];

                $buyArr  = $bestMap[$buyKey]  ?? null;
                $sellArr = $bestMap[$sellKey] ?? null;

                if (!$buyArr || !$sellArr) continue;

                $buy = $this->bestFromArrCached($buyKey, $buyArr, $m['quote_name'], (int)$m['buy_platform'], $levels, $mulCache, $keyBestCache);
                if (!$buy) continue;

                $sell = $this->bestFromArrCached($sellKey, $sellArr, $m['sell_quote_name'], (int)$m['sell_platform'], $levels, $mulCache, $keyBestCache);
                if (!$sell) continue;

                $buyUsdt  = (float)$buy['usdt_price'];
                $sellUsdt = (float)$sell['usdt_price'];
                // if ($buyUsdt <= 0) continue;

                $priceDiff = (($sellUsdt - $buyUsdt) / $buyUsdt) * 100.0;

                $tb = round($buyUsdt * (float)$buy['num'], 2);
                $ts = round($sellUsdt * (float)$sell['num'], 2);

                $amount_level = ($tb > 1000 || $ts > 1000) ? 1 : 0;

                $updates[$id] = [
                    'buy_price' => (float)$buy['price'],
                    'sell_price' => (float)$sell['price'],
                    'buy_num' => (float)$buy['num'],
                    'sell_num' => (float)$sell['num'],
                    'total_buy_price' => (float)$tb,
                    'total_sell_price' => (float)$ts,
                    'total_deal_price' => (float)($tb + $ts),
                    'price_diff' => (float)$priceDiff,
                    'amount_level' => (float)$amount_level,
                ];
            }

            // 7) 批量写库
            if ($updates) {
                foreach (array_chunk($updates, $updateBatch, true) as $sub) {
                    $this->batchUpdate($sub);
                }
            }
        }
    }

    /**
     * 同一个 key 的数组已经 decode 好了，这里只做：
     * - 扫前 levels
     * - mul 计算
     * - 门槛判断
     *
     * 加缓存：key|quote|platform|levels，避免同批重复计算
     */
    private function bestFromArrCached(
        string $key,
        array $arr,
        string $quoteName,
        int $platform,
        int $levels,
        array &$mulCache,
        array &$keyBestCache
    ): ?array {
        $cacheKey = $key . '|' . $platform . '|' . $quoteName . '|' . $levels;
        if (array_key_exists($cacheKey, $keyBestCache)) {
            return $keyBestCache[$cacheKey];
        }

        $mul = $this->getMul($quoteName, $platform, $mulCache);

        $max = min($levels, count($arr));
        for ($i = 0; $i < $max; $i++) {
            $lvl = $arr[$i];
            if (!is_array($lvl) || count($lvl) < 2) continue;

            $price = (float)$lvl[0];
            $num   = (float)$lvl[1];
            if ($price <= 0 || $num <= 0) continue;

            $usdtPrice = $price * $mul;
            if ($usdtPrice * $num < self::MIN_USDT_NOTIONAL) {
                continue;
            }

            $keyBestCache[$cacheKey] = [
                'price' => $price,
                'num' => $num,
                'usdt_price' => $usdtPrice,
            ];
            return $keyBestCache[$cacheKey];
        }

        $keyBestCache[$cacheKey] = null;
        return null;
    }

    /** quote → USDT */
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

    /** Redis pipeline GET */
    private function pipelineGet(\Redis $redis, array $keys): array
    {
        if (!$keys) return [];

        $redis->multi(\Redis::PIPELINE);
        foreach ($keys as $k) $redis->get($k);
        $res = $redis->exec();

        $out = [];
        foreach ($keys as $i => $k) {
            $out[$k] = $res[$i] ?? null;
        }
        return $out;
    }

    /** 批量 UPDATE（与你现在一致） */
    private function batchUpdate(array $updates): void
    {
        $ids = array_keys($updates);
        if (!$ids) return;

        $cols = [
            'buy_price','sell_price','buy_num','sell_num',
            'total_buy_price','total_sell_price',
            'total_deal_price','price_diff','amount_level'
        ];

        $case = [];
        foreach ($cols as $c) $case[$c] = "CASE id ";

        foreach ($updates as $id => $row) {
            $id = (int)$id;
            foreach ($cols as $c) {
                $v = (float)$row[$c];
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
              updated_at = NOW()
            WHERE id IN ({$idList})
        ");
    }
}
