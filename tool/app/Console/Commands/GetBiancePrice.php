<?php


namespace App\Console\Commands;


use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use Illuminate\Console\Command;
// use Lin\Binance\BinanceWebSocket;
use Lin\Binance\Binance;
use App\Model\CurrencyQuotationDiff;

class GetBiancePrice extends Command
{
    protected $signature = 'get_biance_price';
    protected $description = '手动获取币安价格';


    public function handle()
    {
        
        $binance=new Binance();
        while(true){
          try {
            $result=$binance->system()->getTickerPrice([]);
            foreach ($result as $v){
               
                if(empty($v)) continue;
                 
                // var_dump($v);continue;

                $symbol = $v['symbol'];
                
                CurrencyQuotation::where('symbol',$symbol)->where('platform',CurrencyQuotation::PLATFORM_BIANCE)->update([
                    'now_price' => $v['price'],
                    'volume' => 0,
                    'updated_time' => time()
                ]);
                 CurrencyQuotationDiff::updateQuotationPrice($symbol,CurrencyQuotation::PLATFORM_BIANCE,$v['price']);
                //                \Illuminate\Support\Facades\DB::table('manual_log')->insert(['content' => json_encode($v)]);
                
            }
            
            echo sprintf('update biance price at %s',date('Y-m-d H:i:s')).PHP_EOL;
            // print_r($result);
            }catch (\Exception $e){
            print_r($e->getMessage());
            }
            sleep(10);
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
