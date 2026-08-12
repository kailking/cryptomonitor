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

use App\Model\MarketDepth;

$okex=new \Lin\Okex\OkexWebSocketV5();


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

$match = CurrencyMatch::where('is_okex',1)->where('is_enabled',1)->get();
$array = [];
foreach($match as $m){
    // $array[] = sprintf("index/candle60s:%s-%s",$m->currency_name,$m->quote_name);
    //["channel"=>"books","instId"=>"BTC-USDT"],
    $array[] = ["channel"=>"books5","instId"=>sprintf("%s-%s",$m->currency_name,$m->quote_name)];
}
$okex->subscribe($array);
$redis = \App\Service\RedisService::getInstance(3);
$okex->getSubscribes(function($data)use($redis) {
    // var_dump($data);

    foreach ($data as $v){

        if(empty($v)) continue;

        if($v['arg']['channel'] != 'books5'){
            continue;
        }
        if(!isset($v['data'][0])){
            continue;
        }

        $a = $v['data'][0];


        if(!isset($a['asks']) && !isset($a['bids'])){
            continue;
        }

        // $ask = $a['ask'];
        //  var_dump($data);continue;
        $symbol = str_replace("-","",$v['arg']['instId']);
        // var_dump($symbol);continue;

        if(isset($a['asks'])){
            $ask = [];
            foreach($a['asks'] as $item){
                $ask[] = [$item[0],$item[1]];
            }
            // $ask = $a['asks'][0];
            $redis->set(sprintf('%s_%s_%s',strtoupper($symbol),CurrencyQuotation::PLATFORM_OKEX,2),
                json_encode($ask),60);
            //                    MarketDepth::update_depth(strtoupper($symbol),CurrencyQuotation::PLATFORM_OKEX,$ask[0],$ask[1],2,1);
        }
        if(isset($a['bids'])){
            $bid = [];
            foreach($a['bids'] as $item){
                $bid[] = [$item[0],$item[1]];
            }
            $redis->set(sprintf('%s_%s_%s',strtoupper($symbol),CurrencyQuotation::PLATFORM_OKEX,1),
                json_encode($bid),60);
            //                    MarketDepth::update_depth(strtoupper($symbol),CurrencyQuotation::PLATFORM_OKEX,$bid[0],$bid[1],1,1);
        }



    }
},true);
