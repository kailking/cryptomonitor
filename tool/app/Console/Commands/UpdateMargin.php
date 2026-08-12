<?php


namespace App\Console\Commands;


use App\Model\CurrencyQuotation;
use App\Service\RedisService;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class UpdateMargin extends Command
{
    protected $signature = 'update_platform_margin';
    protected $description = '更新交易所杠杆信息';


    public function handle()
    {
//
        //
        //币安
        $redis = RedisService::getInstance(5);
        $url = 'https://api.binance.com/api/v3/exchangeInfo';
        $cli= new Client();
        $platform = CurrencyQuotation::PLATFORM_BIANCE;
        $key = sprintf('platform_margin_'.$platform);
        $content=$cli->get($url)->getBody()->getContents();
        $res = json_decode($content, true);
        if(isset($res['symbols'])){
            $redis->del($key);
            foreach($res['symbols'] as $item){
                if($item['isMarginTradingAllowed']){
                    $redis->sAdd($key,$item['symbol']);
                }
            }
        }
        //火币
        $url = 'https://api.huobi.br.com/v2/settings/common/symbols';
        $cli= new Client();
        $platform = CurrencyQuotation::PLATFORM_HUOBI;
        $key = sprintf('platform_margin_'.$platform);
        $content=$cli->get($url)->getBody()->getContents();
        $res = json_decode($content, true);
        if(isset($res['data'])){
            $redis->del($key);
            foreach($res['data'] as $item){
                if($item['lr']){
                    $redis->sAdd($key,strtoupper($item['sc']));
                }
            }
        }

        //okex

        $url='https://www.okex.com/api/v5/public/instruments?instType=MARGIN';
        $cli= new Client();
        $content=$cli->get($url)->getBody()->getContents();
        $content = json_decode($content, true);
        $platform = CurrencyQuotation::PLATFORM_OKEX;
        $key = sprintf('platform_margin_'.$platform);
        $redis->del($key);
        foreach ($content['data'] as $value) {
            if(strtoupper($value['quoteCcy']) == 'USDT' && $value['lever'] > 0){
                $symbol = strtoupper($value['baseCcy']).strtoupper($value['quoteCcy']);
                $redis->sAdd($key,$symbol);
            }
        }

        //gate
        $url = 'https://api.gateio.ws/api/v4/margin/currency_pairs';
        $cli= new Client();
        $content=$cli->get($url)->getBody()->getContents();
        $content = json_decode($content, true);
        $platform = CurrencyQuotation::PLATFORM_GATE;
        $key = sprintf('platform_margin_'.$platform);
        $redis->del($key);
        foreach($content as $value){
            $symbol = strtoupper($value['base']).strtoupper($value['quote']);
            $redis->sAdd($key,$symbol);
        }
        // echo 333;exit;
        //mexc
        $url = 'https://www.mexc.com/api/platform/margin/common/symbols';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_PROXYTYPE, 'HTTP');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        //print_r($options);
        $result = curl_exec($ch);
        if ($result === false) {
            throw new \Exception(curl_error($ch), curl_errno($ch));
        }
        curl_close($ch);
        $content = json_decode($result, true);
        $platform = CurrencyQuotation::PLATFORM_MEXC;
        $key = sprintf('platform_margin_'.$platform);
        $redis->del($key);
        if(isset($content['data'])){
            $redis->del($key);
            foreach($content['data'] as $value){
                if($value['ratio'] <= 0){
                    continue;
                }
                $symbol = str_replace('_','',$value['symbol']);
                $redis->sAdd($key,$symbol);
            }
        }

        //aex 未知


        //kucoin
        $url='https://api.kucoin.com/api/v1/symbols';
        $cli= new Client();
        $content=$cli->get($url)->getBody()->getContents();
        $content = json_decode($content, true);
        $platform = CurrencyQuotation::PLATFORM_KUCOIN;
        $key = sprintf('platform_margin_'.$platform);
        $redis->del($key);
        foreach ($content['data'] as $v) {
            $currencyName = strtoupper($v['baseCurrency']);
            $quoteName = strtoupper($v['quoteCurrency']);
            if($quoteName == 'USDT' && $v['isMarginEnabled']){
                $redis->sAdd($key,$currencyName.$quoteName);
            }
        }
//

        //coin ex
        $url = 'https://api.coinex.com/v1/margin/market';
        $cli= new Client();
        $content=$cli->get($url)->getBody()->getContents();
        $content = json_decode($content, true);
        $platform = CurrencyQuotation::PLATFORM_COINEX;
        $key = sprintf('platform_margin_'.$platform);
        if(isset($content['data'])){
            $redis->del($key);
            $symbols = array_keys($content['data']);
            $redis->sAdd($key,...$symbols);
        }

        //lbank 未知

        //hot coin 未知

        //ftx 无杠杆

        //DF

        $url='https://ascendex.com/api/pro/v1/margin/products';
        $cli= new Client();
        $content=$cli->get($url)->getBody()->getContents();
        //        var_dump($content);exit;
        $content = json_decode($content, true);
        $platform = CurrencyQuotation::PLATFORM_DF;
        $key = sprintf('platform_margin_'.$platform);
        $redis->del($key);
        foreach ($content['data'] as $value) {
            //                        var_dump($value);exit;
            if ($value['statusCode'] != 'Normal') {
                continue;
            }
            //  var_dump($value['name']);exit;
            $symbol = str_replace('/', '', $value['symbol']);
            $redis->sAdd($key,$symbol);
        }

    }
}
