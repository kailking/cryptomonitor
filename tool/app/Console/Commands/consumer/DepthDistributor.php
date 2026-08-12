<?php

namespace App\Console\Commands\consumer;

use App\Model\MarketDepthDiff;
use App\Service\RedisService;
use Illuminate\Console\Command;

class DepthDistributor extends Command
{
    protected $signature = 'depth_distributor
        {--chunk=2000 : 每次push多少个id}
        {--low=20000 : 队列低水位，低于就开始补}
        {--high=80000 : 队列高水位，高于就暂停补}
        {--sleep_ms=200 : 队列过长时sleep毫秒}';

    protected $description = '持续生产 diff_id_list（启动读一次ids，维持队列水位）';

    public function handle()
    {
        $chunk = (int)$this->option('chunk') ?: 2000;
        $low   = (int)$this->option('low') ?: 20000;
        $high  = (int)$this->option('high') ?: 80000;
        $sleepMs = (int)$this->option('sleep_ms') ?: 200;

        if ($low <= 0 || $high <= 0 || $low >= $high) {
            $this->error('invalid water marks: require 0 < low < high');
            return 1;
        }

        // 1) 启动时一次性读 ids（只读一次）
        $diffIds = MarketDepthDiff::where('is_show', 1)
            ->where(function($q){
                $q->where('buy_platform', '<>', 11)
                    ->orWhere('sell_platform', '<>', 11);
            })
            ->pluck('id')
            ->toArray();

        $total = count($diffIds);
        if ($total === 0) {
            $this->error('no diff ids found');
            return 1;
        }
        $this->info("loaded diff ids: {$total}");

        $redis9 = RedisService::getInstance(9);

        // 灌入游标：循环走一遍又一遍
        $idx = 0;

        while (true) {
            $len = $redis9->lLen('diff_id_list');

            // 2) 队列太长就sleep，让消费者消化
            if ($len >= $high) {
                usleep($sleepMs * 1000);
                continue;
            }

            // 3) 队列长度在 [low, high) 区间：可以慢慢补（也可以直接补到high）
            // 我这里的策略：只要 < low 就积极补，否则轻补
            $need = ($len < $low) ? ($high - $len) : min($chunk, ($high - $len));
            if ($need <= 0) {
                usleep($sleepMs * 1000);
                continue;
            }

            // 4) 取 need 个 id（循环）
            // 注意：LPUSH 会反序，不影响“只要被消费就行”
            $toPush = [];
            $count = min($need, $chunk);

            for ($i=0; $i<$count; $i++) {
                $toPush[] = $diffIds[$idx];
                $idx++;
                if ($idx >= $total) {$idx = 0;};
            }

            // 5) pipeline 批量 LPUSH
            $redis9->multi(\Redis::PIPELINE);
            foreach ($toPush as $id) {
                $redis9->lPush('diff_id_list', (int)$id);
            }
            $redis9->exec();

            // 6) 小睡一下，避免生产者把redis打满（可按需调小/调大）
            usleep(5000); // 5ms

            // exit;
        }
    }
}
