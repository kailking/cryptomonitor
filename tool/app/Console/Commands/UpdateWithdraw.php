<?php


namespace App\Console\Commands;


use App\Model\Currency;
use App\Model\CurrencyQuotation;
use App\Model\PlatformWithdraw;
use App\Service\Exchanges\OkexApi;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class UpdateWithdraw extends Command
{
    protected $signature = 'update_platform_withdraw';
    protected $description = '更新交易所冲提币信息';


    public function handle()
    {
        // //df
        // $url = 'https://ascendex.com/api/pro/v2/assets';
        // $cli= new Client();
        // $content=$cli->get($url)->getBody()->getContents();
        // $res = json_decode($content, true);
        // $platform = CurrencyQuotation::PLATFORM_DF;
        // foreach($res['data'] as $item){

        //     foreach($item['blockChain'] as $c){
        //         $chain = $c['chainName'];
        //         PlatformWithdraw::updateRecord(strtoupper($item['assetCode']),$platform,$chain,$c['allowWithdraw']==true?1:0,$c['allowDeposit'] == true?1:0);
        //     }
        // }
        //mexc
        // $url = 'https://www.mexc.com/open/api/v2/market/coin/list';
        // $ch = curl_init($url);
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        // curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // curl_setopt($ch, CURLOPT_PROXYTYPE, 'HTTP');
        // curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        // //print_r($options);
        // $result = curl_exec($ch);
        // if ($result === false) {
        //     throw new \Exception(curl_error($ch), curl_errno($ch));
        // }
        // curl_close($ch);
        // $res = json_decode($result, true);
        // if(isset($res['data'])){
        //     $platform = CurrencyQuotation::PLATFORM_MEXC;
        //     //先清空所有
        //     foreach($res['data'] as $currency){
        //         foreach($currency['coins'] as $item){
        //             PlatformWithdraw::updateRecord(strtoupper($currency['currency']),$platform,$item['chain'],$item['is_withdraw_enabled']==true?1:0,$item['is_deposit_enabled']==true?1:0);
        //         }
        //     }
        // }
        //huobi
        $url = 'https://api.huobi.pro/v2/settings/common/currencies';
        $cli= new Client();
        $content=$cli->get($url)->getBody()->getContents();
        $res = json_decode($content, true);
        if(isset($res['data'])){
            $platform = CurrencyQuotation::PLATFORM_HUOBI;
            //先清空所有
            foreach($res['data'] as $item){
                if($item['at'] != 1){
                    continue;
                }
                PlatformWithdraw::updateRecord(strtoupper($item['cc']),$platform,null,$item['wed']==true?1:0,$item['de']==true?1:0);
            }
        }
        //binance
        $url = 'https://api.binance.com/sapi/v1/capital/config/getall';



        //kucoin
        $url = 'https://api.kucoin.com/api/v1/currencies';
        $cli= new Client();
        $content=$cli->get($url)->getBody()->getContents();
        $res = json_decode($content, true);
        if(isset($res['data'])){
            $platform = CurrencyQuotation::PLATFORM_KUCOIN;
            //先清空所有
            foreach($res['data'] as $item){
                PlatformWithdraw::updateRecord(strtoupper($item['currency']),$platform,null,$item['isWithdrawEnabled']==true?1:0,$item['isDepositEnabled']==true?1:0);
            }
        }

        //gate
        $url = 'https://api.gateio.ws/api/v4/spot/currencies';

        $cli= new Client();
        $content=$cli->get($url)->getBody()->getContents();
        $res = json_decode($content, true);
        $platform = CurrencyQuotation::PLATFORM_GATE;
        //先清空所有
        foreach($res as $item){
            if($item['delisted'] == true){
                continue;
            }
            PlatformWithdraw::updateRecord(strtoupper($item['currency']),$platform,null,$item['withdraw_disabled']==false?1:0,$item['deposit_disabled']==false?1:0);
        }

        // //ftx
        // $url = 'https://ftx.com/api/wallet/coins';
        // $cli= new Client();
        // $content=$cli->get($url)->getBody()->getContents();
        // $res = json_decode($content, true);
        // $platform = CurrencyQuotation::PLATFORM_FTX;
        // foreach($res['result'] as $item){
        //     PlatformWithdraw::updateRecord(strtoupper($item['id']),$platform,null,$item['canWithdraw']==true?1:0,$item['canDeposit']==true?1:0);
        // }

        // //coinex
        // $url = 'https://api.coinex.com/v1/common/asset/config';
        // $cli= new Client();
        // $content=$cli->get($url)->getBody()->getContents();
        // $res = json_decode($content, true);
        // $platform = CurrencyQuotation::PLATFORM_COINEX;
        // foreach($res['data'] as $item){
        //     PlatformWithdraw::updateRecord(strtoupper($item['asset']),$platform,$item['chain'],$item['can_withdraw']==true?1:0,$item['can_deposit']==true?1:0);
        // }

        //okex
        $client = new OkexApi();
        $list = $client->getCurrencyList();
        $platform = CurrencyQuotation::PLATFORM_OKEX;
        foreach($list['data'] as $k => $item){
            $chain = $item['chain'];
            $chain = explode('-',$chain);
            if(isset($chain[1])){
                $chain = $chain[1];
            }else{
                $chain = null;
            }
            PlatformWithdraw::updateRecord(strtoupper($item['ccy']),$platform,$chain,$item['canWd']==true?1:0,$item['canDep']==true?1:0);
        }

        //lbank

        // $url = 'https://api.lbkex.com/v2/withdrawConfigs.do';
        // $cli= new Client();
        // $content=$cli->get($url)->getBody()->getContents();
        // $res = json_decode($content, true);
        // $platform = CurrencyQuotation::PLATFORM_LBANK;
        // foreach($res['data'] as $item){
        //     if(isset($item['chain'])){
        //         $chain = strtoupper($item['chain']);
        //     }else{
        //         $chain = null;
        //     }
        //     PlatformWithdraw::updateRecord(strtoupper($item['assetCode']),$platform,$chain,$item['canWithDraw']==true?1:0,0);
        // }

    }
}
