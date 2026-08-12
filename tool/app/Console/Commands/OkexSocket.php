<?php


namespace App\Console\Commands;


use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use Illuminate\Console\Command;

use App\Model\CurrencyQuotationDiff;
class OkexSocket extends Command
{
    protected $signature = 'websocket:okex {worker_command} {--mode=}';
    protected $description = '监听Okex价格';


    public function handle()
    {
        $okex=new \Lin\Okex\OkexWebSocket();

        $okex->config([
            //Do you want to enable local logging,default false
            'log'=>false,
            //Or set the log name
            //'log'=>['filename'=>'okex'],

            //Daemons address and port,default 0.0.0.0:2207
            //'global'=>'127.0.0.1:2208',

            //Heartbeat time,default 20 seconds
            //'ping_time'=>20,

            //Channel subscription monitoring time,2 seconds
            //'listen_time'=>2,

            //Channel data update time,0.1 seconds
            //'data_time'=>0.1,
        ]);

        $match = CurrencyMatch::where('is_okex',1)->get();
        $array = [];
        foreach($match as $m){
            $array[] = sprintf("index/candle60s:%s-%s",$m->currency_name,$m->quote_name);
        }
        $okex->subscribe($array);
        $okex->getSubscribes(function($data) {
           
            foreach ($data as $v){
                if(empty($v)) continue;
            
                $d = $v['data'];
             
                $a = $d[0];
               
                if(!isset($a['candle'])){
                    continue;
                }
                $data = $a['candle'];
                //  var_dump($data);continue;
                $symbol = str_replace("-","",$a['instrument_id']);
                // var_dump($symbol);continue;
                CurrencyQuotation::where('symbol',$symbol)->where('platform',CurrencyQuotation::PLATFORM_OKEX)->update([
                    'now_price' => $data[4],
                    'volume' => $data[5],
                    'updated_time' => time()
                ]);
                 CurrencyQuotationDiff::updateQuotationPrice($symbol,CurrencyQuotation::PLATFORM_OKEX,$data[4]);
                //                \Illuminate\Support\Facades\DB::table('manual_log')->insert(['content' => json_encode($v)]);
            }
        },true);
    }
}
