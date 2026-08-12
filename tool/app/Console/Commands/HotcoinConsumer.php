<?php


namespace App\Console\Commands;


use App\Model\CurrencyMatch;
use App\Model\MarketDepth;
use App\Service\RedisService;
use Illuminate\Console\Command;

class HotcoinConsumer extends Command
{
    protected $signature = 'start_hotcoin_consumer';
    protected $description = '消费hotcoin队列';


    public function handle()
    {

        $redis = RedisService::getInstance(3);
        $platform = 11;
        while (true) {
            $symbol = $redis->lPop('hotcoin_symbol_list');
            if (!$symbol) {
                //拿写锁
                $lock = $redis->setnx('write_hotcoin_lock', 1);
                $redis->expire('write_hotcoin_lock', 3);
                if ($lock) {
                    $match = CurrencyMatch::where('is_hotcoin', 1)->where('is_enabled', 1)->get();
                    foreach ($match as $m) {
                        $redis->rPush('hotcoin_symbol_list', sprintf('%s%s', $m->currency_name, $m->quote_name));
                    }

                    $redis->del('write_hotcoin_lock');
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
                        }
                        // echo sprintf('更新%s ask 第%s档',$symbol,$k).PHP_EOL;
                        MarketDepth::update_depth($symbol, $platform, bc_mul($ask[0],1,12), $ask[1], 2, 1);
                        break;
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
                        MarketDepth::update_depth($symbol, $platform, bc_mul($bid[0],1,12), $bid[1], 1, 1);
                        break;
                    }
                }
            }
        }


    }
}
