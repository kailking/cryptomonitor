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

    public static function updateRecord($currency_name,$platform,$network = null,$is_withdraw = 0,$is_deposit = 0){
        $currency  = Currency::where('name',$currency_name)->first();
        if(!$currency){
            return false;
        }else{
            $currency_id = $currency->id;
        }
        $check = self::where('currency_id',$currency_id)
            ->where('platform',$platform);
        if($network){
            $check = $check->where('network',$network);
        }
        $check = $check->first();
        if(!$check){
            PlatformWithdraw::insertGetId([
                'currency_id' => $currency_id,
                'currency_name' => strtoupper($currency_name),
                'platform' => $platform,
                'network' => $network,
                'is_withdraw' => $is_withdraw,
                'is_deposit' => $is_deposit,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            return true;
        }else{
            $msg = [];
            if($check->is_withdraw != $is_withdraw){

                DB::table('platform_withdraw_log')->insert([
                    'pw_id' => $check->id,
                    'type' => 1,
                    'status' => $is_withdraw,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                $msg[] = sprintf('交易所【%s】已更新%s提币通道，当前状态为：%s',CurrencyQuotation::$platform_text[$platform],$network?strtoupper($currency_name)."-$network":strtoupper($currency_name),self::$statusText[$is_withdraw]);
            }
            if($check->is_deposit != $is_deposit){

                DB::table('platform_withdraw_log')->insert([
                    'pw_id' => $check->id,
                    'type' => 2,
                    'status' => $is_deposit,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                $msg[] = sprintf('交易所【%s】已更新%s充值通道，当前状态为：%s',CurrencyQuotation::$platform_text[$platform],$network?strtoupper($currency_name)."-$network":strtoupper($currency_name),self::$statusText[$is_withdraw]);
            }
            PlatformWithdraw::where('id',$check->id)->update([
                'is_withdraw' => $is_withdraw,
                'is_deposit' => $is_deposit,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            //todo 加入更新推送队列

//            $msg && var_dump($msg);
        }
        return true;
    }


}
