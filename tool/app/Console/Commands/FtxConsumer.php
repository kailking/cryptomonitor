<?php


namespace App\Console\Commands;

use App\Service\RedisService;

use Illuminate\Console\Command;
use App\Model\MarketDepth;
use App\Model\CurrencyMatch;

class FtxConsumer extends Command
{
    protected $signature = 'start_ftx_consumer';
    protected $description = '消费ftx队列';


    public function handle()
    {

        $redis = RedisService::getInstance(4);
        $platform = 12;
//        $symbol = 'BTCUSD';
//        $bidData = $redis->get(sprintf('%s_%s_1', $symbol, $platform));
//        $bidData = json_decode($bidData,true);

        while (true) {
            $symbol = $redis->lPop('ftx_symbol_list');
            if (!$symbol) {
                //拿写锁
                $lock = $redis->setnx('write_ftx_lock', 1);
                $redis->expire('write_ftx_lock', 3);
                if ($lock) {
                    $match = CurrencyMatch::where('is_ftx', 1)->where('is_enabled', 1)->get();
                    foreach ($match as $m) {
                        $redis->rPush('ftx_symbol_list', sprintf('%s%s', $m->currency_name, $m->quote_name));
                    }

                    $redis->del('write_ftx_lock');
                } else {
                    sleep(3);
                    continue;
                }
            } else {
                 $match = CurrencyMatch::getMatchBySymbol($symbol);
                if(!$match){
                    continue;
                }
                $mul = 1;
                if ($match) {
                    switch ($match->quote_name){
                        case 'USD':
                            $mul = get_platform_price('USDTUSD',$platform);
                            if($mul<=0){
                                $mul = 1;
                            }
                            break;
                        default:
                            $mul = 1;
                    }
                }
                $askData = $redis->get(sprintf('%s_%s_2', $symbol, $platform));
                if ($askData) {
                    $askData = json_decode($askData, true);
                    ksort($askData);
                    $askData = array_slice($askData,0,10);
                    $k = 0;
                    foreach($askData as $price => $num){
                        $k ++;
                        if(bc_div(bc_mul($price,$num) , $mul) < 85){
                            continue;
                        }
                        // echo sprintf('更新%s ask 第%s档',$symbol,$k).PHP_EOL;
                        MarketDepth::update_depth($symbol, $platform, $price,$num, 2, 1);
                        break;
                    }
                }
                $bidData = $redis->get(sprintf('%s_%s_1', $symbol, $platform));
                if ($bidData) {
                    $bidData = json_decode($bidData, true);
                    krsort($bidData);
                    $bidData = array_slice($bidData,0,10);
                    $k = 0;
                    foreach($bidData as $price => $num){
                        $k ++;
                        if(bc_div(bc_mul($price,$num) , $mul) < 85){
                            continue;
                        }
                        // echo sprintf('更新%s bid 第%s档',$symbol,$k).PHP_EOL;
                        MarketDepth::update_depth($symbol, $platform, $price,$num, 1, 1);
                        break;
                    }
                }
            }
        }


    }
}
