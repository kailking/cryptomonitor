<?php


namespace App\Console\Commands\symbols;


use App\Model\Currency;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class UpdateHuobiSymbol extends Command
{
    protected $signature = 'update_Huobi_Symbol';
    protected $description = '更新火币交易对';


    public function handle()
    {
        $this->comment("begin");
        $url='api.huobi.br.com/v1/common/symbols';
        $cli= new Client();
        $content=$cli->get($url)->getBody()->getContents();
        $content = json_decode($content, true);
        $match_id_arr = [];

        foreach ($content['data'] as $key => $value) {
            $currencyName = strtoupper($value['base-currency']);
            if($value['state'] != 'online'){
                continue;
            }
            if(strtoupper($value['quote-currency']) == 'USDT'){
                $match = CurrencyMatch::where('symbol',strtoupper($value['base-currency']).strtoupper($value['quote-currency']))->first();
                if($match){
                    CurrencyMatch::where('id',$match->id)->update(['is_huobi'=>1]);
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
                    'quote_name' => strtoupper($value['quote-currency']),
                    'symbol' => $currencyName.strtoupper($value['quote-currency']),
                    'price_precision' => $value['price-precision'],
                    'is_huobi' => 1,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                $match_id_arr[] = $match_id;

            }
        }
        foreach($match_id_arr as $mid){
            CurrencyMatch::initCurrencyMatchPlatform($mid,CurrencyQuotation::PLATFORM_HUOBI);
        }
        $now_match = CurrencyMatch::where('is_huobi',1)->pluck('id')->toArray();
        $id1 = MarketDepthDiff::where('buy_platform',CurrencyQuotation::PLATFORM_HUOBI)->pluck('match_id')->toArray();
        $id2 = MarketDepthDiff::where('sell_platform',CurrencyQuotation::PLATFORM_HUOBI)->pluck('sell_match_id')->toArray();
        $res = array_diff($now_match,$match_id_arr);
        $diff_id = array_diff(array_unique(array_merge($id1,$id2)),$match_id_arr);
        CurrencyMatch::whereIn('id',$res)->update(['is_huobi' => 0]);
        MarketDepthDiff::whereIn('match_id',$diff_id)->where('buy_platform',CurrencyQuotation::PLATFORM_HUOBI)->update(['is_show'=>0]);
        MarketDepthDiff::whereIn('sell_match_id',$diff_id)->where('sell_platform',CurrencyQuotation::PLATFORM_HUOBI)->update(['is_show'=>0]);

        $this->comment("end");

    }
}
