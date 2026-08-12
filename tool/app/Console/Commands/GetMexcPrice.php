<?php


namespace App\Console\Commands;


use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepth;
use App\Service\RedisService;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class GetMexcPrice extends Command
{
    protected $signature = 'get_mexc_price {is_proxy}';
    protected $description = '手动获取抹茶价格';


    public function handle()
    {
        $redis = RedisService::getInstance(0);
        $is_proxy = $this->argument('is_proxy');

        while (true) {
            try {
                if($redis->get('write_mex_lock')){
                    sleep(1);
                    continue;
                }
                $symbol = $redis->lPop('mexc_symbol_list');
                if (!$symbol) {
//                    echo '开始写锁';
                    //拿写锁
                    $lock = $redis->setnx('write_mexc_lock', 1);
                    $redis->expire('write_mexc_lock', 10);
                    if ($lock) {
                        $match = CurrencyMatch::where('is_mexc', 1)->where('is_enabled', 1)->get();
                        foreach ($match as $m) {
                            $redis->rPush('mexc_symbol_list', sprintf('%s_%s', $m->currency_name, $m->quote_name));
                        }
                        $redis->del('write_mexc_lock');
                    }else{
                        sleep(3);
                        continue;
                    }
                } else {
                    $targetUrl = "https://www.mexc.com/open/api/v2/market/depth?symbol=$symbol&depth=1";

                    if($is_proxy == 1){
                        $ch = curl_init($targetUrl);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $proxy_port = '6460';
                        $proxy_ip = 'u6286.b5.t.16yun.cn';
                        $loginpassw = '16NWPBFD:785041';
                        curl_setopt($ch, CURLOPT_PROXYPORT, $proxy_port);
                        curl_setopt($ch, CURLOPT_PROXYTYPE, 'HTTP');
                        curl_setopt($ch, CURLOPT_PROXY, $proxy_ip);
                        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $loginpassw);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
                        //print_r($options);
                        $result = curl_exec($ch);
                        if ($result === false) {
                            throw new \Exception(curl_error($ch), curl_errno($ch));
                        }
                        curl_close($ch);
                        $res = json_decode($result, true);
                    }else{
                        $client = new Client(['verify' =>false]);
                        $response  = $client->get($targetUrl);
                        $res = json_decode($response->getBody()->getContents(),true);
                    }

                    if ($res['code'] == 200) {
                        if($symbol == 'HOT_USDT'){
                            echo date('Y-m-d H:i:s').'更新抹茶价格'.$symbol.PHP_EOL;
                        }

                        $data = $res['data'];
                        $symbol1 = str_replace("_", "", $symbol);
                        if(isset($data['bids'][0])){
                            $bids = $data['bids'][0];
                            MarketDepth::update_depth($symbol1, CurrencyQuotation::PLATFORM_MEXC, $bids['price'], $bids['quantity'], 1, 1);
                        }
                        if(isset($data['asks'][0])){
                            $ask = $data['asks'][0];
                            MarketDepth::update_depth($symbol1, CurrencyQuotation::PLATFORM_MEXC, $ask['price'], $ask['quantity'], 2, 1);
                        }
                        }
                    // $redis->rPush('mexc_symbol_list', $symbol);
                }
            } catch (\Exception $e) {
                $redis->lPush('mexc_symbol_list', $symbol);
                echo $e->getMessage().PHP_EOL;
            }
        }


    }
}
