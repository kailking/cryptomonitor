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

use App\Model\MarketDepth;
use Illuminate\Console\Command;
use Lin\Huobi\HuobiWebSocket;

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
$match = CurrencyMatch::where('is_huobi',1)->where('is_enabled',1)->get();
$array = [];
// $array[] = 'market.btcusdt.depth.step0';
foreach($match as $m){
    // 'market.btcusdt.kline.1min',
    //market.$symbol.depth.$type

    $array[] = sprintf('market.%s.mbp.refresh.5',strtolower($m->symbol));
}
$huobi->subscribe($array);
$redis = \App\Service\RedisService::getInstance(3);

$huobi->getSubscribes(function($data)use($redis) {
    foreach ($data as $v){
        $ch = explode('.',$v['ch']);

        if(!in_array('mbp',$ch)){
            continue;
        }

        $d = $v['tick'];

        if(isset($d['bids'])){
            $bids = array_slice($d['bids'],0,5);
            $redis->set(sprintf('%s_%s_%s',strtoupper($ch[1]),CurrencyQuotation::PLATFORM_HUOBI,1),
                json_encode($bids),60);
        }
        if(isset($d['asks'])){
            $asks = array_slice($d['asks'],0,5);
            if($ch[1] == 'btcusdt'){
                // var_dump($asks);
            }
            $redis->set(sprintf('%s_%s_%s',strtoupper($ch[1]),CurrencyQuotation::PLATFORM_HUOBI,2),
                json_encode($asks),60);
        }
    }
},true);

