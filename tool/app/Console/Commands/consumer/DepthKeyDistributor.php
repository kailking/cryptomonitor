<?php

namespace App\Console\Commands\consumer;

use App\Model\MarketDepthDiff;
use App\Service\RedisService;
use Illuminate\Console\Command;

class DepthKeyDistributor extends Command
{
    protected $signature = 'depth_key_distributor
        {--chunk=2000 : 每次push多少个key}
        {--low=20000 : 队列低水位，低于就开始补}
        {--high=80000 : 队列高水位，高于就暂停补}
        {--sleep_ms=200 : 队列过长时sleep毫秒}
        {--queue=depth_key_list : 队列key名（redis9）}
        {--flush=1 : 启动时是否清空队列(1/0)}';

    protected $description = '持续生产 depth_key_list（启动读一次diff，生成去重depth keys，维持队列水位；启动可清空队列）';

    public function handle()
    {
        $chunk   = (int)$this->option('chunk') ?: 2000;
        $low     = (int)$this->option('low') ?: 20000;
        $high    = (int)$this->option('high') ?: 80000;
        $sleepMs = (int)$this->option('sleep_ms') ?: 200;
        $queue   = (string)$this->option('queue') ?: 'depth_key_list';
        $flush   = (int)$this->option('flush') ?: 0;

        if ($low <= 0 || $high <= 0 || $low >= $high) {
            $this->error('invalid water marks: require 0 < low < high');
            return 1;
        }

        $redis9 = RedisService::getInstance(9);

        // 0) 启动清空队列（你要求）
        if ($flush === 1) {
            $redis9->del($queue);
            $this->info("queue cleared: {$queue}");
        }

        /**
         * 1) 启动时一次性读 diff（只读一次）
         * 注意：保持你旧 distributor 的过滤：is_show=1 且 (buy_platform<>11 OR sell_platform<>11)
         */
        $rows = MarketDepthDiff::where('is_show', 1)
            ->where(function ($q) {
                $q->where('buy_platform', '<>', 11)
                  ->orWhere('sell_platform', '<>', 11);
            })
            ->get(['currency_name', 'quote_name', 'sell_quote_name', 'buy_platform', 'sell_platform'])
            ->all();

        if (!$rows) {
            $this->error('no diff rows found');
            return 1;
        }

        // 2) 生成去重 depth keys（普通交易所：db3 key = SYMBOL_PLATFORM_SIDE）
        $keySet = [];
        foreach ($rows as $r) {
            $currency = (string)$r->currency_name;
            $buyQuote = (string)$r->quote_name;
            $sellQuote = (string)$r->sell_quote_name;

            $buyPlatform  = (int)$r->buy_platform;
            $sellPlatform = (int)$r->sell_platform;

            if ($currency === '' || $buyQuote === '' || $sellQuote === '') {
                continue;
            }

            // buy: ask side=2
            $buySymbol = $currency . $buyQuote;
            $buyKey = "{$buySymbol}_{$buyPlatform}_2";
            $keySet[$buyKey] = true;

            // sell: bid side=1
            $sellSymbol = $currency . $sellQuote;
            $sellKey = "{$sellSymbol}_{$sellPlatform}_1";
            $keySet[$sellKey] = true;
        }

        $keys = array_keys($keySet);
        $total = count($keys);

        if ($total === 0) {
            $this->error('no depth keys generated');
            return 1;
        }

        $this->info("loaded depth keys: {$total} (queue={$queue})");

        // 3) 循环灌入（round-robin）
        $idx = 0;

        while (true) {
            $len = (int)$redis9->lLen($queue);

            if ($len >= $high) {
                usleep($sleepMs * 1000);
                continue;
            }

            $need = ($len < $low) ? ($high - $len) : min($chunk, ($high - $len));
            if ($need <= 0) {
                usleep($sleepMs * 1000);
                continue;
            }

            $count = min($need, $chunk);
            $toPush = [];

            for ($i = 0; $i < $count; $i++) {
                $toPush[] = $keys[$idx];
                $idx++;
                if ($idx >= $total) $idx = 0;
            }

            $redis9->multi(\Redis::PIPELINE);
            foreach ($toPush as $k) {
                $redis9->lPush($queue, $k);
            }
            $redis9->exec();

            usleep(5000); // 5ms
        }
    }
}
