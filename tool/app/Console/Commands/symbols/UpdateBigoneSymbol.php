<?php


namespace App\Console\Commands\symbols;

use App\Model\Currency;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class UpdateBigoneSymbol extends Command
{
    protected $signature = 'update_Bigone_Symbol';
    protected $description = '更新bigone交易对';


    public function handle()
    {
        $this->comment("begin");
        
        CurrencyMatch::where('is_bigone',1)->update(['is_bigone' => 0]);
        $id1 = MarketDepthDiff::where('buy_platform',CurrencyQuotation::PLATFORM_BIGONE)->delete();
        $id2 = MarketDepthDiff::where('sell_platform',CurrencyQuotation::PLATFORM_BIGONE)->delete();
        exit;
        
        $url='https://big.one/api/v3/asset_pairs';
        $cli= new Client();
        $content=$cli->get($url)->getBody()->getContents();
        $content = json_decode($content, true);
        $match_id_arr = [];
        foreach ($content['data'] as $key => $v) {
            $s = explode('-',$v['name']);
            $currencyName = strtoupper($s[0]);
            $quoteName = strtoupper($s[1]);

            if($quoteName == 'USDT'){

                $match = CurrencyMatch::where('symbol',strtoupper($currencyName).strtoupper($quoteName))->first();
                if($match){
                    CurrencyMatch::where('id',$match->id)->update(['is_bigone'=>1]);
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
                    'is_bigone' => 1,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                $match_id_arr[] = $match_id;
            }
        }
        foreach($match_id_arr as $mid){
            CurrencyMatch::initCurrencyMatchPlatform($mid,CurrencyQuotation::PLATFORM_BIGONE);
        }
        $now_match = CurrencyMatch::where('is_bigone',1)->pluck('id')->toArray();
        $id1 = MarketDepthDiff::where('buy_platform',CurrencyQuotation::PLATFORM_BIGONE)->pluck('match_id')->toArray();
        $id2 = MarketDepthDiff::where('sell_platform',CurrencyQuotation::PLATFORM_BIGONE)->pluck('sell_match_id')->toArray();
        $res = array_diff($now_match,$match_id_arr);
        $diff_id = array_diff(array_unique(array_merge($id1,$id2)),$match_id_arr);
        CurrencyMatch::whereIn('id',$res)->update(['is_bigone' => 0]);
        MarketDepthDiff::whereIn('match_id',$diff_id)->where('buy_platform',CurrencyQuotation::PLATFORM_BIGONE)->update(['is_show'=>0]);
        MarketDepthDiff::whereIn('sell_match_id',$diff_id)->where('sell_platform',CurrencyQuotation::PLATFORM_BIGONE)->update(['is_show'=>0]);

        $this->comment("end");

    }
}
