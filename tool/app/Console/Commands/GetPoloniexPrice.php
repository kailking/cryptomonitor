<?php


namespace App\Console\Commands;


use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepth;
use App\Service\RedisService;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class GetPoloniexPrice extends Command
{
    protected $signature = 'get_poloniex_price';
    protected $description = '手动获取抹茶价格';


    public function handle()
    {
        return 1;
        $symbols = CurrencyMatch::where('is_poloniex',1)->pluck('symbol')->toArray();
        // var_dump($symbols);exit;
        while(true){
        $url='https://poloniex.com/public?command=returnOrderBook&currencyPair=all&depth=1';
        $cli= new Client();
        $content=$cli->get($url)->getBody()->getContents();
        
        $content = json_decode($content, true);
        
        foreach($content as $symbol => $data){
            $s = explode('_',$symbol);
            $currencyName = strtoupper($s[1]);
            $quoteName = strtoupper($s[0]);
            if(in_array($currencyName.$quoteName,$symbols)){
                if(isset($data['asks'])){
                    $ask = $data['asks'][0];
                    MarketDepth::update_depth($currencyName.$quoteName, CurrencyQuotation::PLATFORM_POLONIEX, $ask[0], $ask[1], 2, 1);
                }
                if(isset($data['bids'])){
                    $bid = $data['bids'][0];
                    MarketDepth::update_depth($currencyName.$quoteName, CurrencyQuotation::PLATFORM_POLONIEX, $bid[0], $bid[1], 1, 1);
                }
            }
        }
        }
        
        

    }
}
