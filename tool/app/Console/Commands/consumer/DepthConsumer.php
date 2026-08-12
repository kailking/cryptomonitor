<?php
namespace App\Console\Commands\consumer;

use App\Service\RedisService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DepthConsumer extends Command
{
    protected $signature = 'start_depth_consumer 
        {--batch=2000} 
        {--update_batch=1000} 
        {--levels=5}';

    protected $description = '消费 diff 队列（pipeline + 批量更新，普通交易所，写入 total_deal_price）';

    /** 最小可成交金额（USDT） */
    private const MIN_USDT_NOTIONAL = 85.0;

    public function handle()
    {
        $redis9 = RedisService::getInstance(9); // diff 队列
        $redis3 = RedisService::getInstance(3); // 深度数据

        $batchSize   = (int)$this->option('batch') ?: 2000;
        $updateBatch = (int)$this->option('update_batch') ?: 1000;
        $levels      = max(1, (int)$this->option('levels'));

        // quote -> usdt mul 缓存
        $mulCache = [];

        while (true) {
            /** 1️⃣ 阻塞取一个，避免空转 */
            $first = $redis9->brPop(['diff_id_list'], 1);
            if (!$first) {
                usleep(5000);
                continue;
            }

            $ids = [(int)$first[1]];

            /** 2️⃣ 尽量批量取 */
            try {
                $more = $redis9->lPop('diff_id_list', $batchSize - 1);
            } catch (\Throwable $e) {
                $more = [];
                for ($i = 0; $i < $batchSize - 1; $i++) {
                    $x = $redis9->lPop('diff_id_list');
                    if (!$x) break;
                    $more[] = $x;
                }
            }

            if ($more) {
                foreach ((array)$more as $x) {
                    $ids[] = (int)$x;
                }
            }

            $ids = array_values(array_unique(array_filter($ids)));
            if (!$ids) continue;

            /** 3️⃣ 批量取 diff 行（只要必要字段） */
            $rows = DB::table('market_depth_diff')
                ->whereIn('id', $ids)
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

            if (!$rows) continue;

            /** 4️⃣ 构造 Redis keys */
            $need = [];
            $keys = [];

            foreach ($rows as $r) {
                $buySymbol  = $r->currency_name . $r->quote_name;
                $sellSymbol = $r->currency_name . $r->sell_quote_name;

                $buyKey  = "{$buySymbol}_{$r->buy_platform}_2";  // ask
                $sellKey = "{$sellSymbol}_{$r->sell_platform}_1"; // bid

                $need[$r->id] = [
                    'id' => (int)$r->id,
                    'quote_name' => $r->quote_name,
                    'sell_quote_name' => $r->sell_quote_name,
                    'buy_platform' => (int)$r->buy_platform,
                    'sell_platform' => (int)$r->sell_platform,
                    'buy_key' => $buyKey,
                    'sell_key' => $sellKey,
                ];

                $keys[$buyKey]  = true;
                $keys[$sellKey] = true;
            }

            $keys = array_keys($keys);

            /** 5️⃣ pipeline GET */
            $depthMap = $this->pipelineGet($redis3, $keys);

            /** 6️⃣ 计算价差 */
            $updates = [];

            foreach ($need as $id => $m) {
                $buyJson  = $depthMap[$m['buy_key']]  ?? null;
                $sellJson = $depthMap[$m['sell_key']] ?? null;

                if (!$buyJson || !$sellJson) continue;

                // 买：ask
                $buy = $this->parseBest(
                    $buyJson,
                    $m['quote_name'],
                    $m['buy_platform'],
                    $levels,
                    $mulCache
                );

                if (!$buy) continue;

                // 卖：bid
                $sell = $this->parseBest(
                    $sellJson,
                    $m['sell_quote_name'],
                    $m['sell_platform'],
                    $levels,
                    $mulCache
                );

                if (!$sell) continue;

                $buyUsdt  = $buy['usdt_price'];
                $sellUsdt = $sell['usdt_price'];

                if ($buyUsdt <= 0) continue;

                $priceDiff = (($sellUsdt - $buyUsdt) / $buyUsdt) * 100;

                // if ($priceDiff <= 0) continue;

                $tb = round($buyUsdt * $buy['num'], 2);
                $ts = round($sellUsdt * $sell['num'], 2);
                if($tb>1000 || $ts>1000){
                    $amount_level = 1;
                }else{
                    $amount_level = 0;
                }
                $updates[$id] = [
                    'buy_price' => $buy['price'],
                    'sell_price' => $sell['price'],
                    'buy_num' => $buy['num'],
                    'sell_num' => $sell['num'],
                    'total_buy_price' => $tb,
                    'total_sell_price' => $ts,
                    'total_deal_price' => $tb+$ts,
                    'price_diff' => $priceDiff,
                    'amount_level' => $amount_level
                ];
            }

            /** 7️⃣ 批量写库 */
            if ($updates) {
                foreach (array_chunk($updates, $updateBatch, true) as $sub) {
                    $this->batchUpdate($sub);
                }
            }
        }
    }

    /** ===== 解析前 N 档 ===== */
    private function parseBest(
        string $json,
        string $quoteName,
        int $platform,
        int $levels,
        array &$mulCache
    ): ?array {
        $arr = json_decode($json, true);
        if (!is_array($arr) || empty($arr)) return null;

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

            return [
                'price' => $price,
                'num' => $num,
                'usdt_price' => $usdtPrice,
            ];
        }

        return null;
    }

    /** ===== quote → USDT ===== */
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
        return $cache[$k];
    }

    /** ===== Redis pipeline GET ===== */
    private function pipelineGet(\Redis $redis, array $keys): array
    {
        if (!$keys) return [];

        $redis->multi(\Redis::PIPELINE);
        foreach ($keys as $k) {
            $redis->get($k);
        }
        $res = $redis->exec();

        $out = [];
        foreach ($keys as $i => $k) {
            $out[$k] = $res[$i] ?? null;
        }
        return $out;
    }

    /** ===== 批量 UPDATE ===== */
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
        foreach ($cols as $c) {
            $case[$c] = "CASE id ";
        }

        foreach ($updates as $id => $row) {
            $id = (int)$id;
            foreach ($cols as $c) {
                $v = (float)$row[$c];
                $case[$c] .= "WHEN {$id} THEN {$v} ";
            }
        }

        foreach ($cols as $c) {
            $case[$c] .= "END";
        }

        $idList = implode(',', $ids);

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
