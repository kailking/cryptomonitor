<?php

namespace App\Console\Commands;

use App\Service\RedisService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateMarketDepth extends Command
{
    protected $signature = 'update_depth_price';
    protected $description = '批量更新盘口卖一 + 计算5分钟涨跌幅（高性能版）';

    // 防抖阈值：价格变动 < 0.01% 不更新 market_depth
    const PRICE_EPS = 0.0001;

    // 5分钟涨跌幅阈值：abs(change) < 1 不记录（沿用你旧逻辑）
    const CHANGE_MIN_ABS = 1.0;

    // ===== 可调参数（不使用 typed property，兼容旧 PHP）=====
    private $sleepSec = 1;

    // market_depth 每批从 DB 拉多少行（避免一次取全表）
    private $fetchChunk = 1200;

    // 每次 UPDATE market_depth 的 ID 数量（几百条一批）
    private $updateChunk = 400;

    // ===== Redis 设置 =====
    private $depthDb = 3;   // 深度数据（python 写入）所在 DB
    private $mpDb    = 4;   // 5分钟价格缓存（本命令读写）所在 DB
    private $periodMin = 5; // 5分钟对比
    private $mpTtlSec  = 1200; // 价格 key 保存 20分钟

    // ===== 最小改动：防止整批 SQL 因为极端数值炸掉 =====
    // 你不知道 number 字段类型上限，我只能给一个“很大但不过分”的软上限；
    // 如果仍报 out of range，你需要根据表结构调小这个值。
    const MAX_PRICE  = 999999999999.999999;
    const MAX_NUMBER = 999999999999.999999;
    const MAX_ABS_CHANGE = 1000000; // 变动超过 100万% 当异常，直接跳过

    public function handle()
    {
        $redisDepth = RedisService::getInstance($this->depthDb);
        $redisMp    = RedisService::getInstance($this->mpDb);

        $this->info(sprintf(
            "begin: fetchChunk=%d, updateChunk=%d, sleep=%ds, period=%dm, depth_db=%d, mp_db=%d, mp_ttl=%ds",
            $this->fetchChunk,
            $this->updateChunk,
            $this->sleepSec,
            $this->periodMin,
            $this->depthDb,
            $this->mpDb,
            $this->mpTtlSec
        ));

        $lastId = 0;

        while (true) {
            sleep($this->sleepSec);

            // 1) 分批拉 market_depth，避免一次 get 全表
            $rows = DB::table('market_depth')
                ->join('currency_match', 'currency_match.id', '=', 'market_depth.match_id')
                ->where('market_depth.type', 2)
                ->where('currency_match.is_enabled', 1)
                // ->where('currency_match.is_enabled', 1)
                ->where('market_depth.id', '>', $lastId)
                ->orderBy('market_depth.id', 'asc')
                ->limit($this->fetchChunk)
                ->select([
                    'market_depth.id',
                    'market_depth.match_id',
                    'market_depth.symbol',
                    'market_depth.platform',
                    'market_depth.price',
                ])
                ->get();

            if ($rows->isEmpty()) {
                $lastId = 0;
                continue;
            }

            $lastId = (int)$rows->last()->id;

            // 2) 组 Redis Key（深度 asks）
            $keysById = [];
            foreach ($rows as $r) {
                $keysById[(int)$r->id] = "{$r->symbol}_{$r->platform}_2";
            }

            // 3) 批量 Redis GET（深度在 db=3）
            $redisDepth->multi(\Redis::PIPELINE);
            foreach ($keysById as $k) {
                $redisDepth->get($k);
            }
            $values = $redisDepth->exec();

            // 4) 解析深度，收集：
            //    A) 需要更新 market_depth 的 rows（受防抖影响）
            //    B) 需要写入 mp key 的 rows（只要深度有有效 price/num，就写；不要被防抖挡住，否则永远没有5分钟前对比）
            $updates = [];   // id => ['price'=>, 'num'=>]
            $mpRows  = [];   // id => ['match_id','symbol','platform','price']  (用于算 change)
            $nowKey  = date('H_i');
            $prevKey = date('H_i', strtotime("-{$this->periodMin} min"));

            $i = 0;
            foreach ($rows as $row) {
                $raw = $values[$i++] ?? null;
                if (!$raw) continue;

                $asks = json_decode($raw, true);
                if (empty($asks) || !is_array($asks) || empty($asks[0]) || !is_array($asks[0]) || count($asks[0]) < 2) {
                    continue;
                }

                $price = (float)$asks[0][0];
                $num   = (float)$asks[0][1];

                if ($price <= 0 || $num <= 0) continue;
                if (!is_finite($price) || !is_finite($num)) continue;

                // 最小改动：极端值跳过，避免整批 SQL out of range
                if ($price > self::MAX_PRICE || $num > self::MAX_NUMBER) {
                    continue;
                }

                $id = (int)$row->id;

                // mpRows：只要能拿到有效 price，就写入 db=4 的分钟key，用于5分钟对比
                $mpRows[$id] = [
                    'match_id'  => (int)$row->match_id,
                    'symbol'    => (string)$row->symbol,
                    'platform'  => (int)$row->platform,
                    'price'     => $price,
                ];

                // market_depth 更新：受防抖影响（和你原来一样）
                if ((float)$row->price > 0) {
                    $diff = abs($price - (float)$row->price) / (float)$row->price;
                    if ($diff < self::PRICE_EPS) {
                        continue;
                    }
                }

                $updates[$id] = ['price' => $price, 'num' => $num];
            }

            // 5) 写入 db=4 价格key + 读取 5分钟前价格（同一 pipeline）
            //    注意：读写都用 db=4（与 python 的 db=3 分离）
            if (!empty($mpRows)) {
                $idsForMp = array_keys($mpRows);

                $redisMp->multi(\Redis::PIPELINE);

                // 先 setex 当前分钟
                foreach ($idsForMp as $id) {
                    $redisMp->setex(
                        sprintf('m_p_%d_%s', $id, $nowKey),
                        $this->mpTtlSec,
                        (string)$mpRows[$id]['price']
                    );
                }

                // 再 get 5分钟前
                foreach ($idsForMp as $id) {
                    $redisMp->get(sprintf('m_p_%d_%s', $id, $prevKey));
                }

                $mpResp = $redisMp->exec();
                $n = count($idsForMp);
                $prevVals = array_slice($mpResp, $n); // 后半段是 get 的结果

                // 6) 计算涨跌幅，批量 upsert market_change
                $changeRows = [];
                foreach ($idsForMp as $idx2 => $id) {
                    $prev = $prevVals[$idx2] ?? null;

                    // 你要求：redis 没拿到数据就跳过
                    if ($prev === null || $prev === false || $prev === '') {
                        continue;
                    }

                    $prevPrice = (float)$prev;
                    if ($prevPrice <= 0 || !is_finite($prevPrice)) continue;

                    $curPrice = (float)$mpRows[$id]['price'];
                    if ($curPrice <= 0 || !is_finite($curPrice)) continue;

                    $pct = (($curPrice - $prevPrice) / $prevPrice) * 100.0;
                    if (!is_finite($pct)) continue;

                    if (abs($pct) < self::CHANGE_MIN_ABS) continue;
                    if (abs($pct) > self::MAX_ABS_CHANGE) continue;

                    $changeRows[] = [
                        'match_id'    => $mpRows[$id]['match_id'],
                        'symbol'      => $mpRows[$id]['symbol'],
                        'platform'    => $mpRows[$id]['platform'],
                        'period'      => (int)$this->periodMin,
                        'direction'   => $pct > 0 ? 1 : 2,
                        'change'      => abs($pct),
                        'price_begin' => $prevPrice,
                        'price_end'   => $curPrice,
                    ];
                }

                if (!empty($changeRows)) {
                    $this->bulkUpsertMarketChange($changeRows);
                }
            }

            // 7) market_depth 分批 UPDATE（几百条一批）
            if (!empty($updates)) {
                $this->batchUpdateMarketDepth($updates);
            }
        }
    }

    private function batchUpdateMarketDepth(array $updates)
    {
        $ids = array_keys($updates);
        if (empty($ids)) return;

        $chunks = array_chunk($ids, $this->updateChunk);

        foreach ($chunks as $idChunk) {
            $priceCase = 'CASE id ';
            $numCase   = 'CASE id ';

            foreach ($idChunk as $id) {
                $p = $this->toSqlNumber($updates[$id]['price']);
                $n = $this->toSqlNumber($updates[$id]['num']);

                // 最小改动：再次兜底，避免极端数导致整批失败
                if ((float)$updates[$id]['price'] > self::MAX_PRICE || (float)$updates[$id]['num'] > self::MAX_NUMBER) {
                    continue;
                }

                $priceCase .= "WHEN {$id} THEN {$p} ";
                $numCase   .= "WHEN {$id} THEN {$n} ";
            }

            $priceCase .= 'END';
            $numCase   .= 'END';

            $idList = implode(',', array_map('intval', $idChunk));

            DB::statement("
                UPDATE market_depth
                SET
                  price = {$priceCase},
                  number = {$numCase},
                  updated_at = NOW()
                WHERE id IN ({$idList})
            ");
        }
    }

    private function bulkUpsertMarketChange(array $rows)
    {
        // 依赖 market_change(symbol,platform,period) 唯一索引
        $now = date('Y-m-d H:i:s');

        $valuesSql = [];
        $bindings = [];

        foreach ($rows as $r) {
            // 兜底：异常值直接跳过，避免 change 爆表
            if (!isset($r['change']) || !is_finite((float)$r['change']) || (float)$r['change'] > self::MAX_ABS_CHANGE) {
                continue;
            }

            $valuesSql[] = "(?,?,?,?,?,?,?,?,?,?)";
            $bindings[] = (int)$r['match_id'];
            $bindings[] = (string)$r['symbol'];
            $bindings[] = (int)$r['platform'];
            $bindings[] = (int)$r['period'];
            $bindings[] = (int)$r['direction'];
            $bindings[] = (float)$r['change'];
            $bindings[] = (float)$r['price_begin'];
            $bindings[] = (float)$r['price_end'];
            $bindings[] = $now; // created_at
            $bindings[] = $now; // updated_at
        }

        if (empty($valuesSql)) return;

        $sql = "
            INSERT INTO market_change
              (match_id, symbol, platform, period, direction, `change`, price_begin, price_end, created_at, updated_at)
            VALUES
              " . implode(',', $valuesSql) . "
            ON DUPLICATE KEY UPDATE
              direction = VALUES(direction),
              `change` = VALUES(`change`),
              price_begin = VALUES(price_begin),
              price_end = VALUES(price_end),
              updated_at = VALUES(updated_at)
        ";

        DB::statement($sql, $bindings);
    }

    private function toSqlNumber($v)
    {
        // 防止科学计数法进 SQL（例如 2.12E-5）
        $s = sprintf('%.18F', (float)$v);
        $s = rtrim($s, '0');
        $s = rtrim($s, '.');
        if ($s === '' || $s === '-0') $s = '0';
        return $s;
    }
}
