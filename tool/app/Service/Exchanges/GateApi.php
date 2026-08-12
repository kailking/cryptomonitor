<?php


namespace App\Service\Exchanges;


use Lin\Gate\GateSpot;

class GateApi implements BaseExchange
{
    public function getDepth($currency_name, $quote_name, $limit = 10)
    {
        $gate=new GateSpot();
        $depth=$gate->market()->getOrderBook([
            'currency_pair'=>sprintf('%s_%s',strtoupper($currency_name),strtoupper($quote_name)),
            'limit' => $limit
        ]);
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
