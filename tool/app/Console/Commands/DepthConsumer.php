<?php


namespace App\Console\Commands;

use App\Model\CurrencyQuotation;
use App\Service\RedisService;

use Illuminate\Console\Command;
use App\Model\MarketDepth;
use App\Model\MarketDepthDiff;
use Illuminate\Support\Facades\DB;

class DepthConsumer extends Command
{
    protected $signature = 'start_depth_consumer';
    protected $description = '消费深度队列';


    public function handle()
    {

        $redis_9 = RedisService::getInstance(9);
        return true;
        while(true){
            $id = $redis_9->lPop('diff_id_list');
            if (!$id) {
                // echo date('Y-m-d H:i:s').PHP_EOL;
                //                    echo '开始写锁';
                //拿写锁
                $lock = $redis_9->setnx('write_diff_id_lock', 3);
                if ($lock) {
                    $redis_9->expire('write_diff_id_lock', 5);
                    // for($i=0 ;$i<10;$i++){
                    $diff_ids = MarketDepthDiff::where('is_show', 1)->where(function($query){
                        return $query->where('buy_platform',"<>",11)->orWhere('sell_platform','<>',11);
                    })->pluck('id')->toArray();
                    $diff_ids_array = array_chunk($diff_ids,500);
                    foreach($diff_ids_array as $diff_ids){
                        $res =  $redis_9->lPush('diff_id_list', ...$diff_ids);
                    }
                    // }
                    $redis_9->del('write_diff_id_lock');
                }else{
                    sleep(3);
                    continue;
                }
            }
            // $data = MarketDepthDiff::where('id',$id)->first()->toArray();
            $data = MarketDepthDiff::getDiffById($id);
            if (!$data) {
                continue;
            }
            // $data = json_decode($data);

            $sell_data = self::getOrders($data->currency_name,$data->sell_quote_name,$data->sell_platform,1);
            if(empty($sell_data)){
                continue;
            }
            $buy_data = self::getOrders($data->currency_name,$data->quote_name,$data->buy_platform,2);
            if( empty($buy_data)){
                continue;
            }

            $price_diff = $sell_data[2] <= 0? 0:bc_div(bc_sub($sell_data[2],$buy_data[2]),$buy_data[2])*100;
            if($price_diff >0.3){
                DB::table('market_depth_diff')->where('id', $id)->update([
                'buy_price' => $buy_data[0],
                'sell_price' => $sell_data[0],
                'buy_num' => $buy_data[1],
                'sell_num' => $sell_data[1],
                'total_buy_price' => bc_mul($buy_data[2],$buy_data[1],2),
                'total_sell_price' => bc_mul($sell_data[2],$sell_data[1],2),
                'price_diff' => $price_diff,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            }
            
        }


    }

    //bid 1 ask 2
    private static function getOrders($currencyName, $quoteName, $platform, $dir)
    {
        $symbol = $currencyName . $quoteName;
        switch ($quoteName) {
            case 'BTC':
                $mul = get_platform_price('BTCUSDT', $platform);
                break;
            case 'ETH':
                $mul = get_platform_price('ETHUSDT', $platform);
                break;
            default:
                $mul = 1;
        }
        if ($platform == CurrencyQuotation::PLATFORM_FTX || $platform == CurrencyQuotation::PLATFORM_DF) {
            $redis = RedisService::getInstance(4);
            switch ($quoteName){
                case 'USD':
                    $mul = get_platform_price('USDTUSD',$platform);
                    if($mul<=0){
                        $mul = 1;
                    }
                    break;
                default:
                    $mul = 1;
            }
            if ($dir == 2) {
                $askData = $redis->get(sprintf('%s_%s_2', $symbol, $platform));
                if ($askData) {
                    $askData = json_decode($askData, true);
                    ksort($askData);
                    $askData = array_slice($askData, 0, 10);
                    foreach ($askData as $price => $num) {
                        $usdtPrice = bc_div($price,$mul);
                        if (bc_mul($usdtPrice,$num) < 85) {
                            continue;
                        }
                        return [$price, $num,$usdtPrice];
                    }
                }
            } else {
                $bidData = $redis->get(sprintf('%s_%s_1', $symbol, $platform));
                if ($bidData) {
                    $bidData = json_decode($bidData, true);
                    krsort($bidData);
                    $bidData = array_slice($bidData, 0, 10);
                    foreach ($bidData as $price => $num) {
                        $usdtPrice = bc_div($price,$mul);
                        if (bc_mul($usdtPrice,$num) < 85) {
                            continue;
                        }
                        return [$price, $num,$usdtPrice];
                    }
                }
            }
        } else {
            $redis = RedisService::getInstance(3);
            $data = $redis->get(sprintf('%s_%s_%d', $symbol, $platform, $dir));
            if ($data) {
                $res = json_decode($data, true);
                foreach ($res as $k => $ask) {
                    if($platform == CurrencyQuotation::PLATFORM_AEX){
                        $price = $ask[1];
                        $num = $ask[0];
                    }else{
                        $price = $ask[0];
                        $num = $ask[1];
                    }
                    $usdtPrice = bc_mul($price,$mul);

                    if ($usdtPrice*$num < 85) {
                        continue;
                    } else {
                        return [$price, $num,$usdtPrice];
                        // echo sprintf('更新%s ask 第%s档',$symbol,$k).PHP_EOL;

                        break;
                    }

                }
            }
        }
        return null;
    }


}
