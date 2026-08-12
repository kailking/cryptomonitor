<?php

namespace App\Console\Commands\consumer;

use App\Model\MarketDepthDiff;
use App\Service\RedisService;
use Illuminate\Console\Command;

class DepthSymbolDistributor extends Command
{
    protected $signature = 'depth_symbol_distributor
        {--chunk=2000 : 每次push多少个symbol}
        {--low=10000 : 队列低水位，低于就开始补}
        {--high=30000 : 队列高水位，高于就暂停补}
        {--sleep_ms=200 : 队列过长时sleep毫秒}
        {--queue=depth_symbol_list : 队列key名（redis9）}
        {--flush=1 : 启动时是否清空队列(1/0)}';

    protected $description = '生产 symbol 队列（启动读一次diff，生成去重symbols；维持队列水位；启动可清空队列）';

    public function handle()
    {
        $chunk   = (int)$this->option('chunk') ?: 2000;
        $low     = (int)$this->option('low') ?: 20000;
        $high    = (int)$this->option('high') ?: 80000;
        $sleepMs = (int)$this->option('sleep_ms') ?: 200;
        $queue   = (string)$this->option('queue') ?: 'depth_symbol_list';
        $flush   = (int)$this->option('flush') ?: 0;

        if ($low <= 0 || $high <= 0 || $low >= $high) {
            $this->error('invalid water marks: require 0 < low < high');
            return 1;
        }

        $redis9 = RedisService::getInstance(9);

        if ($flush === 1) {
            $redis9->del($queue);
            $this->info("queue cleared: {$queue}");
        }

        // 启动读一次 diff（与你之前逻辑一致）
        $rows = MarketDepthDiff::where('is_show', 1)
            ->where(function ($q) {
                $q->where('buy_platform', '<>', 11)
                  ->orWhere('sell_platform', '<>', 11);
            })
            ->get(['currency_name', 'quote_name', 'sell_quote_name'])
            ->all();

        if (!$rows) {
            $this->error('no diff rows found');
            return 1;
        }

        // 生成去重 symbol：currency+quote 与 currency+sell_quote 都算
        $symSet = [];
        foreach ($rows as $r) {
            $currency = (string)$r->currency_name;
            $buyQuote = (string)$r->quote_name;
            $sellQuote = (string)$r->sell_quote_name;

            if ($currency === '' || $buyQuote === '' || $sellQuote === '') continue;

            $symSet[$currency . $buyQuote] = true;
            $symSet[$currency . $sellQuote] = true;
        }

        $symbols = array_keys($symSet);
        $total = count($symbols);

        if ($total === 0) {
            $this->error('no symbols generated');
            return 1;
        }

        $this->info("loaded symbols: {$total} (queue={$queue})");

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
                $toPush[] = $symbols[$idx];
                $idx++;
                if ($idx >= $total) $idx = 0;
            }

            $redis9->multi(\Redis::PIPELINE);
            foreach ($toPush as $s) {
                $redis9->lPush($queue, $s);
            }
            $redis9->exec();

            usleep(5000);
        }
    }
}
