<?php


/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| our application. We just need to utilize it! We'll simply require it
| into the script here so that we don't have to worry about manual
| loading any of our classes later on. It feels great to relax.
|
*/

require __DIR__.'/../../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Turn On The Lights
|--------------------------------------------------------------------------
|
| We need to illuminate PHP development, so let us turn on the lights.
| This bootstraps the framework and gets it ready for use, then it
| will load up this application so that we can run it and send
| the responses back to the browser and delight our users.
|
*/

$app = require_once __DIR__.'/../../bootstrap/app.php';

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
|
| Once we have the application, we can handle the incoming request
| through the kernel, and send the associated response back to
| the client's browser allowing them to enjoy the creative
| and wonderful application we have prepared for them.
|
*/

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);



use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use Illuminate\Console\Command;

use App\Model\CurrencyQuotationDiff;

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
