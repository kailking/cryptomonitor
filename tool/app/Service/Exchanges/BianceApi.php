<?php


namespace App\Service\Exchanges;


use Lin\Binance\Binance;

class BianceApi implements BaseExchange
{
    public function getDepth($currency_name, $quote_name, $limit = 10)
    {
        $biance = new Binance();
        $depth = $biance->system()->getDepth(['symbol' => strtoupper($currency_name.$quote_name),'limit' => 10]);
        $bids = [];
        $asks = [];
        if(isset($depth['bids'])){
            foreach($depth['bids'] as $bid){
                $bids[] = [
                    'price' => $bid[0],
                    'num' => $bid[1]
                ];
            }
        }
        if(isset($depth['asks'])){
            foreach($depth['asks'] as $ask){
                $asks[] = [
                    'price' => $ask[0],
                    'num' => $ask[1]
                ];
            }
        }
        $res = [
            'bids' => $bids,
            'asks' => $asks
        ];
        return $res;
    }
}
