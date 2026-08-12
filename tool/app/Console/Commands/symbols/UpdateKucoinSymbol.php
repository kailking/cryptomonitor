<?php


namespace App\Console\Commands\symbols;


use App\Model\Currency;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class UpdateKucoinSymbol extends Command
{
    protected $signature = 'update_Kucoin_Symbol';
    protected $description = '更新kucoin交易对';


    public function handle()
    {
        $this->comment("begin");
         $url='https://api.kucoin.com/api/v1/symbols';
         $cli= new Client();
         $content=$cli->get($url)->getBody()->getContents();
        //  var_dump($content);exit;
         $content = json_decode($content, true);
         
        $match_id_arr = [];
        foreach ($content['data'] as $key => $v) {

             $currencyName = strtoupper($v['baseCurrency']);
             if(in_array($currencyName,['WAXP','SUN','GALA','REV','OXEN','BSV','TITAN','KNC'])){
                 continue;
             }
             $quoteName = strtoupper($v['quoteCurrency']);
             if($quoteName == 'USDT'){
                 $match = CurrencyMatch::where('symbol',strtoupper($currencyName).strtoupper($quoteName))->first();
                 if($match){
                     CurrencyMatch::where('id',$match->id)->update(['is_kucoin'=>1]);
                     $match_id_arr[] = $match->id;
                     continue;
                 }
                 $currency = Currency::where('name',$currencyName)->first();
                 if($currency){
                     $currencyId = $currency->id;
                 }else{
                     $currencyId = Currency::insertGetId([
                         'name' => $currencyName
                     ]);
                 }
                 $match_id = CurrencyMatch::insertGetId([
                     'currency_id' => $currencyId,
                     'quote_id' => 1,
                     'currency_name' => $currencyName,
                     'quote_name' => 'USDT',
                     'symbol' => $currencyName.'USDT',
                     'price_precision' => 0,
                     'is_kucoin' => 1,
                     'created_at' => date('Y-m-d H:i:s')
                 ]);
                 $match_id_arr[] = $match_id;

             }
         }
        foreach($match_id_arr as $mid){
            CurrencyMatch::initCurrencyMatchPlatform($mid,CurrencyQuotation::PLATFORM_KUCOIN);
        }
        //disable match
        $now_match = CurrencyMatch::where('is_kucoin',1)->pluck('id')->toArray();
        $id1 = MarketDepthDiff::where('buy_platform',CurrencyQuotation::PLATFORM_KUCOIN)->pluck('match_id')->toArray();
        $id2 = MarketDepthDiff::where('sell_platform',CurrencyQuotation::PLATFORM_KUCOIN)->pluck('sell_match_id')->toArray();
        $res = array_diff($now_match,$match_id_arr);
        $diff_id = array_diff(array_unique(array_merge($id1,$id2)),$match_id_arr);
        CurrencyMatch::whereIn('id',$res)->update(['is_kucoin' => 0]);
        MarketDepthDiff::whereIn('match_id',$diff_id)->where('buy_platform',CurrencyQuotation::PLATFORM_KUCOIN)->update(['is_show'=>0]);
        MarketDepthDiff::whereIn('sell_match_id',$diff_id)->where('sell_platform',CurrencyQuotation::PLATFORM_KUCOIN)->update(['is_show'=>0]);


        $this->comment("end");

    }
}
