<?php


namespace App\Service\Exchanges;


use Lin\Huobi\HuobiSpot;

class HuobiApi implements BaseExchange
{
    public function getDepth($currency_name, $quote_name, $limit = 10)
    {
        $huobi = new HuobiSpot();
        $depth = $huobi->market()->getDepth(['symbol' => strtolower($currency_name.$quote_name),'depth' => $limit]);
        $bids = [];
        $asks = [];
        if($depth['status'] == 'ok'){
            if(isset($depth['tick']['bids'])){
                foreach($depth['tick']['bids'] as $bid){
                    $bids[] = [
                        'price' => $bid[0],
                        'num' => $bid[1]
                    ];
                }

            }
            if(isset($depth['tick']['asks'])){
                foreach($depth['tick']['asks'] as $ask){
                    $asks[] = [
                        'price' => $ask[0],
                        'num' => $ask[1]
                    ];
                }

            }
        }
        $res = [
            'bids' => $bids,
            'asks' => $asks
        ];
        return $res;
    }
}
