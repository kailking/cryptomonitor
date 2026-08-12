<?php


namespace App\Console\Commands;
use App\Service\RedisService;

use Illuminate\Console\Command;
use App\Model\MarketDepth;

class BiboxQueue extends Command
{
    protected $signature = 'start_bibox_queue';
    protected $description = 'bibox消费深度队列';


    public function handle()
    {

        $redis = RedisService::getInstance(4);
        $platform = 9;

        while(true){
            $res = $redis->lPop('platform_9_queue');
            if(empty($res)){
                usleep(100000);
                //  sleep(1);
            }else{
                $res = json_decode($res,true);
                try {
                    //bid
                    if($res['type'] == 1){
                        $check = $redis->sIsMember(sprintf('%s_%d_bids',$res['symbol'],$platform),$res['price']);
                        if($check){
                            $nu = $redis->get(sprintf('%s_%d_bid_%s',$res['symbol'],$platform,$res['price']));
                            if($res['dir'] == 'add'){
                                $nu = bc_add($nu,$res['num']);
                            }else{
                                $nu = bc_sub($nu,$res['num']);
                            }
                            if($nu <= 0){
                                $redis->sRem(sprintf('%s_%d_bids',$res['symbol'],$platform),$res['price']);
                                $redis->del(sprintf('%s_%d_bid_%s',$res['symbol'],$platform,$res['price']));
                            }else{
                                $redis->set(sprintf('%s_%d_bid_%s',$res['symbol'],$platform,$res['price']),$nu);
                            }
                        }else{
                            $redis->sadd(sprintf('%s_%d_bids',$res['symbol'],$platform),$res['price']);
                            $redis->set(sprintf('%s_%d_bid_%s',$res['symbol'],$platform,$res['price']),$res['num']);
                        }

                    }else{
                        $check = $redis->sIsMember(sprintf('%s_%d_asks',$res['symbol'],$platform),$res['price']);
                        if($check){
                            $nu = $redis->get(sprintf('%s_%d_ask_%s',$res['symbol'],$platform,$res['price']));
                            if($res['dir'] == 'add'){
                                $nu = bc_add($nu,$res['num']);
                            }else{
                                $nu = bc_sub($nu,$res['num']);
                            }
                            if($nu <= 0){
                                $redis->sRem(sprintf('%s_%d_asks',$res['symbol'],$platform),$res['price']);
                                $redis->del(sprintf('%s_%d_ask_%s',$res['symbol'],$platform,$res['price']));
                            }else{
                                $redis->set(sprintf('%s_%d_ask_%s',$res['symbol'],$platform,$res['price']),$nu);
                            }
                        }else{
                            $redis->sadd(sprintf('%s_%d_asks',$res['symbol'],$platform),$res['price']);
                            $redis->set(sprintf('%s_%d_ask_%s',$res['symbol'],$platform,$res['price']),$res['num']);
                        }

                    }
                    // if($res['symbol'] == 'ETCUSDT'){
                    //     echo sprintf('更新%s 价格：%s 数量',$res['symbol'],$res['price']);
                    // }
                }catch(\Exception $e){
//                    throw $e;
                     echo $e->getMessage().PHP_EOL;
                }

            }

        }



        // $binance = new BinanceWebSocket();

        // $binance->config([
        //     //Do you want to enable local logging,default false
        //     'log'=>false,
        //     //Or set the log name
        //     // 'log'=>['filename'=>'spot'],

        //     //Daemons address and port,default 0.0.0.0:2208
        //     //'global'=>'127.0.0.1:2208',

        //     //Heartbeat time,default 20 seconds
        //     //'ping_time'=>20,

        //     //Channel subscription monitoring time,2 seconds
        //     //'listen_time'=>2,

        //     //Channel data update time,0.1 seconds
        //     //'data_time'=>0.1,

        //     //baseurl
        //     'baseurl'=>'ws://stream.binance.com:9443',//default
        //     //'baseurl'=>'ws://fstream.binance.com',
        //     //'baseurl'=>'ws://dstream.binance.com',

        // ]);
        // $match = CurrencyMatch::where('is_biance',1)->get();
        // $array = [];
        // foreach($match as $m){
        //     // 'btcusdt@kline_1min',
        //     //<symbol>@miniTicker
        //     // $array[] = 'btcusdt@depth';
        //     $array[] = sprintf('%s@miniTicker',strtolower($m->symbol));
        // }
        // $binance->subscribe($array);
        // $binance->getSubscribes(function($data) {
        //     foreach ($data as $v){

        //         if(empty($v)) continue;

        //         // var_dump($v);continue;

        //         $ch = explode('@',$v['stream']);
        //         $data = $v['data'];
        //         $symbol = strtoupper($ch[0]);

        //         CurrencyQuotation::where('symbol',$symbol)->where('platform',CurrencyQuotation::PLATFORM_BIANCE)->update([
        //             'now_price' => $data['c'],
        //             'volume' => $data['v'],
        //             'updated_time' => time()
        //         ]);
        //          CurrencyQuotationDiff::updateQuotationPrice($symbol,CurrencyQuotation::PLATFORM_BIANCE,$data['c']);
        //         //                \Illuminate\Support\Facades\DB::table('manual_log')->insert(['content' => json_encode($v)]);
        //     }
        // },true);

    }
}
