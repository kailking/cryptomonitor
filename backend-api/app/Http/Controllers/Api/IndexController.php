<?php


namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepth;
use App\Model\Users;

use App\Service\Exchanges\BianceApi;
use App\Service\Exchanges\GateApi;
use App\Service\RedisService;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Model\MarketDepthDiff;

class IndexController extends Controller
{
    public function currencyPrice(){

        $redis = RedisService::getInstance(1);

        $data = [
            'usdt_price' => $redis->get('usdt_price'),
            'btc_price' => $redis->get('btc_price'),
            'eth_price' => $redis->get('eth_price')
        ];
        return successReturn($data);

    }


    public function updateLoanMatch(){
        $redis = RedisService::getInstance(0);
        //gate
        $url = 'http://www.gate.io/cn/cross_margin/assets';
        $cli= new Client();
        $content=$cli->get($url)->getBody()->getContents();
        $res = json_decode($content, true);
        $key = 'loan_symbol_'.CurrencyQuotation::PLATFORM_GATE;
        $redis->del($key);
        //        return successReturn($res);
        foreach($res as $k => $value){
            $redis->sAdd($key,strtoupper($k));
        }
        // binance
        $url = 'https://www.binance.com/bapi/margin/v1/friendly/margin/asset/all';
        $cli= new Client();
        $content=$cli->get($url)->getBody()->getContents();
        $res = json_decode($content, true);
        $key = 'loan_symbol_'.CurrencyQuotation::PLATFORM_BIANCE;
        foreach($res['data'] as $k => $value){
            $redis->sAdd($key,strtoupper($value['assetName']));
        }

        //kucoin  https://api.kucoin.com/api/v1/currencies
        /**
         * [
        {
        "currency": "CSP",
        "name": "CSP",
        "fullName": "Caspian",
        "precision": 8,
        "confirms": 12,
        "contractAddress": "0xa6446d655a0c34bc4f05042ee88170d056cbaf45",
        "withdrawalMinSize": "2000",
        "withdrawalMinFee": "1000",
        "isWithdrawEnabled": true,
        "isDepositEnabled": true,
        "isMarginEnabled": false,
        "isDebitEnabled": false
        },
         */

        $res = json_decode($res,true);
        $key = 'loan_symbol_'.CurrencyQuotation::PLATFORM_KUCOIN;
        $redis->del($key);
        foreach($res['data'] as $k => $value){
            if(isset($value['symbol'])){
                $symbol = $value['symbol'];
                $symbol = explode('-',$symbol);
                $redis->sAdd($key,strtoupper($symbol[0]));
            }

        }

        //mexc 抓去用户杠杆账户接口
        $res = '';
        $res = json_decode($res,true);
        $key = 'loan_symbol_'.CurrencyQuotation::PLATFORM_MEXC;
        $redis->del($key);
        foreach($res['data']['assets'] as $k => $value){
            if(isset($value['symbol'])){
                $symbol = $value['symbol'];
                $symbol = explode('/',$symbol);
                $redis->sAdd($key,strtoupper($symbol[0]));
            }
        }
    }

    public function ipCheck(Request $request){
        $ip = request()->getClientIp();
        echo $ip;exit;
    }

    public function testDepth(){
        
        // $diff_ids = MarketDepthDiff::where('buy_platform',CurrencyQuotation::PLATFORM_POLONIEX)
        //     ->orWhere('sell_platform',CurrencyQuotation::PLATFORM_POLONIEX)->pluck('id')->toArray();
        // DB::table('user_diff_filter')->whereIn('diff_id',$diff_ids)->delete();
        // MarketDepth::where('platform',CurrencyQuotation::PLATFORM_POLONIEX)->delete();
        // MarketDepthDiff::where('buy_platform',CurrencyQuotation::PLATFORM_POLONIEX)
        // ->orWhere('sell_platform',CurrencyQuotation::PLATFORM_POLONIEX)
        //     ->delete();
    }
}
