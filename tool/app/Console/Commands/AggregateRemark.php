<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AggregateRemark extends Command
{
    /**
     * 命令名称：php artisan remark:aggregate {--target=1}
     * --target 参数指定汇总到哪个 user_id，默认是 1
     */
    protected $signature = 'remark:aggregate {--target=79}';
    protected $description = '按 diff_id 汇总所有用户的备注到指定账户';

    public function handle()
    {
        $targetUserId = (int)$this->option('target');
        $this->info("开始执行备注汇总，目标账户 ID: {$targetUserId}");

        // 1. 获取所有有备注的记录（排除备注为空的）
        // 按照 diff_id 排序，方便后续逻辑处理
        $rows = DB::table('market_depth_remark')
            ->whereNotNull('remark')
            ->where('remark', '<>', '')
            ->where('user_id','<>',$targetUserId)
            ->orderBy('diff_id')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn("没有发现任何备注数据。");
            return 0;
        }

        // 2. 在内存中按 diff_id 进行合并
        $aggregated = [];
        foreach ($rows as $row) {
            $did = $row->diff_id;
            if (!isset($aggregated[$did])) {
                $aggregated[$did] = [
                    'remarks' => [],
                    'base_info' => $row // 保留一份基础信息，用于新增记录时填充平台ID
                ];
            }
            $aggregated[$did]['remarks'][] = trim($row->remark);
        }

        $count = 0;
        foreach ($aggregated as $diffId => $data) {
            // 对备注去重，防止同一个人的重复备注被拼接多次
            $uniqueRemarks = array_unique($data['remarks']);
            $combinedRemark = implode(' | ', $uniqueRemarks);

            // 3. 检查目标账户是否已有该 diff_id 的记录
            $existing = DB::table('market_depth_remark')
                ->where('user_id', $targetUserId)
                ->where('diff_id', $diffId)
                ->first();

            if ($existing) {
                // 如果存在，直接更新备注
                DB::table('market_depth_remark')
                    ->where('id', $existing->id)
                    ->update([
                        'remark' => $combinedRemark,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            } else {
                // 如果不存在，插入新纪录，并继承原记录的平台和 match 信息
                $base = $data['base_info'];
                DB::table('market_depth_remark')->insert([
                    'user_id'       => $targetUserId,
                    'remark'        => $combinedRemark,
                    'buy_platform'  => $base->buy_platform,
                    'sell_platform' => $base->sell_platform,
                    'match_id'      => $base->match_id,
                    'sell_match_id' => $base->sell_match_id,
                    'diff_id'       => $base->diff_id,
                    // 'created_at'    => date('Y-m-d H:i:s'),
                    // 'updated_at'    => date('Y-m-d H:i:s')
                ]);
            }
            $count++;
            $this->line("已汇总 diff_id: {$diffId}");
        }

        $this->info("汇总完成！共处理了 {$count} 组 diff_id 数据。");
        return 0;
    }
}