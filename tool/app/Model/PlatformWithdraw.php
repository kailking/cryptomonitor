<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PlatformWithdraw extends Model
{
    public $table = 'platform_withdraw';
    
    public static $statusText = [
        0 => '关闭',
        1 => '开启'
    ];

    public static function updateRecord(
    $currency_name, 
    $platform, 
    $network = null, 
    $is_withdraw = 0, 
    $is_deposit = 0, 
    $withdraw_fee = null, 
    $withdraw_precision = null, 
    $confirm_num = null
) {
    // 【重点】1. 统一处理 network：将空字符串转换为 null
    // 这样可以确保无论 API 返回的是 "" 还是 null，查询和插入都保持一致
    $network = (trim($network) === '') ? null : $network;

    // 获取币种 ID
    $currency = Currency::where('name', $currency_name)->first();
    if (!$currency) {
        return false;
    }
    $currency_id = $currency->id;

    // 2. 检查记录是否存在
    $query = self::where('currency_id', $currency_id)
        ->where('platform', $platform);

    // 这里的逻辑现在变严谨了，因为 $network 已经被标准化
    if (is_null($network)) {
        $query = $query->whereNull('network');
    } else {
        $query = $query->where('network', $network);
    }

    $check = $query->first();

    $now = date('Y-m-d H:i:s');

    if (!$check) {
        // 3. 不存在则插入
        self::insertGetId([
            'currency_id'        => $currency_id,
            'currency_name'      => strtoupper($currency_name),
            'platform'           => $platform,
            'network'            => $network, // 这里存入的将是严格的 null
            'is_withdraw'        => $is_withdraw,
            'is_deposit'         => $is_deposit,
            'withdraw_fee'       => $withdraw_fee,
            'withdraw_precision' => $withdraw_precision,
            'confirm_num'        => $confirm_num,
            'created_at'         => $now,
            'updated_at'         => $now
        ]);
        return true;
    } else {
        // 4. 对比并记录日志（逻辑不变...）
        // $msg = [];
        // if ($check->is_withdraw != $is_withdraw) {
        //     DB::table('platform_withdraw_log')->insert([
        //         'pw_id'      => $check->id,
        //         'type'       => 1,
        //         'status'     => $is_withdraw,
        //         'created_at' => $now
        //     ]);
        // }

        // if ($check->is_deposit != $is_deposit) {
        //     DB::table('platform_withdraw_log')->insert([
        //         'pw_id'      => $check->id,
        //         'type'       => 2,
        //         'status'     => $is_deposit,
        //         'created_at' => $now
        //     ]);
        // }

        // 5. 执行更新
        self::where('id', $check->id)->update([
            'is_withdraw'        => $is_withdraw,
            'is_deposit'         => $is_deposit,
            'withdraw_fee'       => $withdraw_fee,
            'withdraw_precision' => $withdraw_precision,
            'confirm_num'        => $confirm_num,
            'updated_at'         => $now
        ]);
    }
    return true;
}
}