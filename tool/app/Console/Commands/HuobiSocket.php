<?php


namespace App\Console\Commands;


use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;

use App\Model\CurrencyQuotationDiff;
use Illuminate\Console\Command;
use Lin\Huobi\HuobiWebSocket;

class HuobiSocket extends Command
{
    protected $signature = 'websocket:huobi {worker_command} {--mode=}';
    protected $description = '监听火币价格';


    public function handle()
    {

        $huobi = new HuobiWebSocket();

        $huobi->config([
            //Do you want to enable local logging,default false
            'log'=>false,
            //Or set the log name
            //'log' => ['filename' => 'spot'],

            //Daemons address and port,default 0.0.0.0:2211
            //'global'=>'127.0.0.1:2211',

            //Channel subscription monitoring time,2 seconds
            //'listen_time'=>2,

            //Channel data update time,default 0.5 seconds
            //'data_time'=>0.5,

            //Set up subscription platform, default 'spot'
            'platform' => 'spot', //options value 'spot' 'future' 'swap' 'linear' 'option'
            //Or you can set it like this
            /*
            'platform'=>[
                'type'=>'spot',
                'market'=>'ws://api.huobi.pro/ws',//Market Data Request and Subscription
                'order'=>'ws://api.huobi.pro/ws/v2',//Order Push Subscription
                //'market'=>'ws://api-aws.huobi.pro/ws',
                //'order'=>'ws://api-aws.huobi.pro/ws/v2',
            ],
            */
        ]);
        $match = CurrencyMatch::where('is_huobi',1)->get();
        $array = [];
        foreach($match as $m){
           // 'market.btcusdt.kline.1min',

            $array[] = sprintf('market.%s.kline.1day',strtolower($m->symbol));
        }
        $huobi->subscribe($array);
        $huobi->getSubscribes(function($data) {
            foreach ($data as $v){
                $ch = explode('.',$v['ch']);
                $data = $v['tick'];
                $symbol = strtoupper($ch[1]);
                CurrencyQuotation::where('symbol',$symbol)->where('platform',CurrencyQuotation::PLATFORM_HUOBI)->update([
                    'now_price' => $data['close'],
                    'volume' => $data['amount'],
                    'updated_time' => time()
                ]);
                CurrencyQuotationDiff::updateQuotationPrice($symbol,CurrencyQuotation::PLATFORM_HUOBI,$data['close']);
//                \Illuminate\Support\Facades\DB::table('manual_log')->insert(['content' => json_encode($v)]);
            }
        },true);

    }
}
