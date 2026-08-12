<?php


namespace App\Console\Commands\symbols;


use App\Model\Currency;
use App\Model\CurrencyMatch;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class UpdatePoloniexSymbol extends Command
{
    protected $signature = 'update_Poloniex_Symbol';
    protected $description = '更新poloniex交易对';


    public function handle()
    {
        $this->comment("begin");
        $url='https://poloniex.com/public?command=returnTicker';
        $cli= new Client();
        $content=$cli->get($url)->getBody()->getContents();
        $content = json_decode($content, true);
        $data = array_keys($content);
        foreach ($data as $key => $v) {
            $s = explode('_',$v);
            $currencyName = strtoupper($s[1]);
            $quoteName = strtoupper($s[0]);
            if($quoteName == 'USDT'){
                $match = CurrencyMatch::where('symbol',strtoupper($currencyName).strtoupper($quoteName))->first();
                if($match){
                    CurrencyMatch::where('id',$match->id)->update(['is_poloniex'=>1]);
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
                CurrencyMatch::insert([
                    'currency_id' => $currencyId,
                    'quote_id' => 1,
                    'currency_name' => $currencyName,
                    'quote_name' => 'USDT',
                    'symbol' => $currencyName.'USDT',
                    'price_precision' => 0,
                    'is_poloniex' => 1,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }
        }
        $this->comment("end");

    }
}
