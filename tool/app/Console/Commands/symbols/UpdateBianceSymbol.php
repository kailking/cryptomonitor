<?php


namespace App\Console\Commands\symbols;


use App\Model\Currency;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class UpdateBianceSymbol extends Command
{
    protected $signature = 'update_Biance_Symbol';
    protected $description = '更新币安交易对';


    public function handle()
    {
        $this->comment("begin");
        $url='https://api.binance.com/api/v3/exchangeInfo?showPermissionSets=false';
        $cli= new Client([
        'timeout' => 600, // 设置超时时间为 60 秒
        'connect_timeout' => 100, // 设置连接超时时间为 10 秒
    ]);
        $content=$cli->get($url)->getBody()->getContents();
// //        var_dump($content);exit;
        // $filePath = '/www/wwwroot/tool/binance.json';
        //     $jsonContent = file_get_contents($filePath);
        //     if ($jsonContent === false) {
        //         throw new Exception("无法读取文件: $filePath");
        //     }
        //     // 解码 JSON 数据为数组
        //     $content = json_decode($jsonContent, true);
        //     if (json_last_error() !== JSON_ERROR_NONE) {
        //         throw new Exception('无法将文件内容解析为 JSON: '. json_last_error_msg());
        //     }
        // $content = fopen('/www/wwwroot/tool/binance.json','r');
        $content = json_decode($content, true);
        $match_id_arr = [];

        foreach ($content['symbols'] as $key => $value) {
//                        var_dump($value);exit;
            if($value['status'] != 'TRADING'){
                continue;
            }
            $currencyName = strtoupper($value['baseAsset']);
            if(strtoupper($value['quoteAsset']) == 'USDT'){
                $match = CurrencyMatch::where('symbol',strtoupper($value['baseAsset']).strtoupper($value['quoteAsset']))->first();
                if($match){
                    CurrencyMatch::where('id',$match->id)->update(['is_biance'=>1]);
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
                    'quote_name' => strtoupper($value['quoteAsset']),
                    'symbol' => $currencyName.strtoupper($value['quoteAsset']),
                    'price_precision' => 0,
                    'is_biance' => 1,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                $match_id_arr[] = $match_id;

            }
        }
        foreach($match_id_arr as $mid){
            CurrencyMatch::initCurrencyMatchPlatform($mid,CurrencyQuotation::PLATFORM_BIANCE);
        }
        $now_match = CurrencyMatch::where('is_biance',1)->pluck('id')->toArray();
        $id1 = MarketDepthDiff::where('buy_platform',CurrencyQuotation::PLATFORM_BIANCE)->pluck('match_id')->toArray();
        $id2 = MarketDepthDiff::where('sell_platform',CurrencyQuotation::PLATFORM_BIANCE)->pluck('sell_match_id')->toArray();
        $res = array_diff($now_match,$match_id_arr);
        $diff_id = array_diff(array_unique(array_merge($id1,$id2)),$match_id_arr);
        CurrencyMatch::whereIn('id',$res)->update(['is_biance' => 0]);
        MarketDepthDiff::whereIn('match_id',$diff_id)->where('buy_platform',CurrencyQuotation::PLATFORM_BIANCE)->update(['is_show'=>0]);
        MarketDepthDiff::whereIn('sell_match_id',$diff_id)->where('sell_platform',CurrencyQuotation::PLATFORM_BIANCE)->update(['is_show'=>0]);

        $this->comment("end");

    }
}
