<?php


namespace App\Console\Commands;
use App\Service\RedisService;

use Illuminate\Console\Command;
use App\Model\MarketDepth;
use App\Model\CurrencyMatch;

class OkexConsumer extends Command
{
    protected $signature = 'start_okex_consumer';
    protected $description = '消费okex队列';


    public function handle()
    {

        $redis = RedisService::getInstance(3);
        $platform = 3;
        while(true){
            $symbol = $redis->lPop('okex_symbol_list');
            if (!$symbol) {
                //                    echo '开始写锁';
                //拿写锁
                $lock = $redis->setnx('write_okex_lock', 1);
                $redis->expire('write_okex_lock', 3);
                if ($lock) {
                    // for($i=0 ;$i<10;$i++){
                    $match = CurrencyMatch::where('is_okex', 1)->where('is_enabled', 1)->get();
                    foreach ($match as $m) {
                        $redis->rPush('okex_symbol_list', sprintf('%s%s', $m->currency_name, $m->quote_name));
                    }
                    // }

                    $redis->del('write_okex_lock');
                }else{
                    sleep(3);
                    continue;
                }
            }else{
                $match = CurrencyMatch::getMatchBySymbol($symbol);
                if(!$match){
                    continue;
                }
                $mul = 1;
                if ($match) {
                    switch ($match->quote_name){
                        case 'BTC':
                            $mul = get_platform_price('BTCUSDT',$platform);
                            break;
                        case 'ETH':
                            $mul = get_platform_price('ETHUSDT',$platform);
                            break;
                        default:
                            $mul = 1;
                    }
                }
                $askData = $redis->get(sprintf('%s_%s_2', $symbol, $platform));
                if ($askData) {
                    $res = json_decode($askData, true);
                    foreach($res as $k => $ask){
                        if(bc_mul($ask[0],$ask[1]) * $mul < 85){
                            continue;
                        }else{
                            //  echo sprintf('更新%s ask 第%s档',$symbol,$k).PHP_EOL;
                            MarketDepth::update_depth($symbol, $platform, $ask[0], $ask[1], 2, 1);
                            break;
                        }

                    }
                }
                $bidData = $redis->get(sprintf('%s_%s_1', $symbol, $platform));
                if ($bidData) {
                    $res = json_decode($bidData, true);
                    foreach($res  as $k => $bid){
                        if(bc_mul($bid[0],$bid[1]) * $mul < 85){
                            continue;
                        }
                        // echo sprintf('更新%s bid 第%s档',$symbol,$k).PHP_EOL;
                        MarketDepth::update_depth($symbol, $platform, $bid[0], $bid[1], 1, 1);
                        break;
                    }
                }
            }
        }


    }
}
