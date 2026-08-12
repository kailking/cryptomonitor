<?php


namespace App\Console\Commands\symbols;


use App\Model\Currency;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class UpdateDfSymbol extends Command
{
    protected $signature = 'update_df_symbol';
    protected $description = '更新df交易对';


    public function handle()
    {
        $this->comment("begin");
        $url='https://ascendex.com/api/pro/v1/cash/products';
        $cli= new Client();
        $content=$cli->get($url)->getBody()->getContents();
        //        var_dump($content);exit;
        $content = json_decode($content, true);
        $match_id_arr = [];

        foreach ($content['data'] as $key => $value) {
            //                        var_dump($value);exit;
            if($value['statusCode'] != 'Normal'){
                continue;
            }
            //  var_dump($value['name']);exit;
            $symbol = explode('/',$value['symbol']);

            $currencyName = strtoupper($symbol[0]);
            $quoteName = strtoupper($symbol[1]);
            if($quoteName == 'USDT'){
                $match = CurrencyMatch::where('symbol',$currencyName.$quoteName)->first();
                if($match){
                    CurrencyMatch::where('id',$match->id)->update(['is_df'=>1]);
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
                    'quote_name' => $quoteName,
                    'symbol' => $currencyName.$quoteName,
                    'price_precision' => 0,
                    'is_ftx' => 1,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                $match_id_arr[] = $match_id;

            }
        }
        foreach($match_id_arr as $mid){
            CurrencyMatch::initCurrencyMatchPlatform($mid,CurrencyQuotation::PLATFORM_DF);
        }
        $now_match = CurrencyMatch::where('is_df',1)->pluck('id')->toArray();
        $id1 = MarketDepthDiff::where('buy_platform',CurrencyQuotation::PLATFORM_DF)->pluck('match_id')->toArray();
        $id2 = MarketDepthDiff::where('sell_platform',CurrencyQuotation::PLATFORM_DF)->pluck('sell_match_id')->toArray();
        $res = array_diff($now_match,$match_id_arr);
        $diff_id = array_diff(array_unique(array_merge($id1,$id2)),$match_id_arr);
        CurrencyMatch::whereIn('id',$res)->update(['is_df' => 0]);
        MarketDepthDiff::whereIn('match_id',$diff_id)->where('buy_platform',CurrencyQuotation::PLATFORM_DF)->update(['is_show'=>0]);
        MarketDepthDiff::whereIn('sell_match_id',$diff_id)->where('sell_platform',CurrencyQuotation::PLATFORM_DF)->update(['is_show'=>0]);

        $this->comment("end");

    }
}
