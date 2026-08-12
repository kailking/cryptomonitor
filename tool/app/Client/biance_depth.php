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
use Lin\Binance\BinanceWebSocket;

use App\Model\MarketDepth;

$binance = new BinanceWebSocket();

        $binance->config([
            //Do you want to enable local logging,default false
            'log'=>false,
            //Or set the log name
            // 'log'=>['filename'=>'spot'],

            //Daemons address and port,default 0.0.0.0:2208
            //'global'=>'127.0.0.1:2208',

            //Heartbeat time,default 20 seconds
            'ping_time'=>1,

            //Channel subscription monitoring time,2 seconds
            //'listen_time'=>2,

            //Channel data update time,0.1 seconds
            //'data_time'=>0.1,

            //baseurl
            'baseurl'=>'ws://stream.binance.com:9443',//default
            //'baseurl'=>'ws://fstream.binance.com',
            //'baseurl'=>'ws://dstream.binance.com',

        ]);
        $match = CurrencyMatch::where('is_biance',1)->where('is_enabled',1)->get();
        $array = [];
        foreach($match as $m){
            // 'btcusdt@kline_1min',
            //<symbol>@miniTicker
            // $array[] = 'btcusdt@depth';
            $array[] = sprintf('%s@bookTicker',strtolower($m->symbol));
        }
        $binance->subscribe($array);
        $binance->getSubscribes(function($data) {
            foreach ($data as $v){

                if(empty($v)) continue;
                 try{
                    //   var_dump($v);continue;

                $ch = explode('@',$v['stream']);
                if($ch[1] != 'bookTicker'){
                    // var_dump($ch[1]);
                    continue;
                }

                $data = $v['data'];
                $symbol = strtoupper($ch[0]);

                MarketDepth::update_depth($symbol,CurrencyQuotation::PLATFORM_BIANCE,$data['b'],$data['B'],1,1);

                MarketDepth::update_depth($symbol,CurrencyQuotation::PLATFORM_BIANCE,$data['a'],$data['A'],2,1);
                 }catch(\Exception $e){
                    continue;
                 }
                }
        },true);


