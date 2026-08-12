<?php


namespace App\Console\Commands\symbols;


use App\Model\Currency;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use App\Service\RedisService;

class UpdateAexSymbol extends Command
{
    protected $signature = 'update_aex_symbol';
    protected $description = '更新安银交易对';


    public function handle()
    {
        $mul = 1;
        $redis = RedisService::getInstance(3);
        $data = $redis->get(sprintf('%s_%s_%d', 'BTCUSDT', 13, 1));
        if ($data) {
                $res = json_decode($data, true);
                foreach ($res as $k => $ask) {
                    
                        $price = $ask[0];
                        $num = $ask[1];
                
                    $usdtPrice = bc_mul($price,$mul);

                }
            }
        
        $res = bc_mul((float)$price,(float)$mul);
        var_dump($res);exit;
        MarketDepthDiff::where('buy_platform',CurrencyQuotation::PLATFORM_AEX)->delete();
        MarketDepthDiff::where('sell_platform',CurrencyQuotation::PLATFORM_AEX)->delete();
        exit;
       $this->comment("begin");
        $url='https://api.aex.zone/v3/allpair.php';
        $cli= new Client();
        $content=$cli->get($url)->getBody()->getContents();
        $content = json_decode($content, true);
        $match_id_arr = [];
        foreach ($content['data'] as $key => $value) {
            $currencyName = strtoupper($value['coin']);
            $quoteName = strtoupper($value['market']);
            if($quoteName == 'USDT'){
                $match = CurrencyMatch::where('symbol',strtoupper($currencyName).strtoupper($quoteName))->first();
                if($match){
                    CurrencyMatch::where('id',$match->id)->update(['is_aex'=>1]);
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
                    'price_precision' => $value['limits']['PricePrecision'],
                    'is_aex' => 1,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                $match_id_arr[] = $match_id;
            }
        }
        foreach($match_id_arr as $mid){
            CurrencyMatch::initCurrencyMatchPlatform($mid,CurrencyQuotation::PLATFORM_AEX);
        }
        //disable match
        $now_match = CurrencyMatch::where('is_aex',1)->pluck('id')->toArray();
        $id1 = MarketDepthDiff::where('buy_platform',CurrencyQuotation::PLATFORM_AEX)->pluck('match_id')->toArray();
        $id2 = MarketDepthDiff::where('sell_platform',CurrencyQuotation::PLATFORM_AEX)->pluck('sell_match_id')->toArray();
        $res = array_diff($now_match,$match_id_arr);
        $diff_id = array_diff(array_unique(array_merge($id1,$id2)),$match_id_arr);
        CurrencyMatch::whereIn('id',$res)->update(['is_aex' => 0]);
        MarketDepthDiff::whereIn('match_id',$diff_id)->where('buy_platform',CurrencyQuotation::PLATFORM_AEX)->update(['is_show'=>0]);
        MarketDepthDiff::whereIn('sell_match_id',$diff_id)->where('sell_platform',CurrencyQuotation::PLATFORM_AEX)->update(['is_show'=>0]);

        $this->comment("end");
    }
}
