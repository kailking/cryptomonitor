<?php


namespace App\Service\Exchanges;


use Lin\Okex\OkexV5;

class OkexApi implements BaseExchange
{
    public function getDepth($currency_name, $quote_name, $limit = 10)
    {
        $okex=new OkexV5();
        $res = $okex->market()->getBooks(['instId' => sprintf('%s-%s',strtoupper($currency_name),strtoupper($quote_name)),'sz' => $limit]);
        $asks = [];
        $bids = [];
        if(isset($res['data'][0]['asks'])){
            foreach($res['data'][0]['asks'] as $ask){
                $asks[] = [
                    'price' => $ask[0],
                    'num' => $ask[1]
                ];
            }
        }
        if(isset($res['data'][0]['bids'])){
            foreach($res['data'][0]['bids'] as $bid){
                $bids[] = [
                    'price' => $bid[0],
                    'num' => $bid[1]
                ];
            }
        }
        $return = [
            'bids' => $bids,
            'asks' => $asks
        ];
        return $return;
    }

    public function getCurrencyList(){
        $okex = new OkexV5(env('OKX_API_KEY'), env('OKX_API_SECRET'), env('OKX_API_PASSPHRASE'));
        $list = $okex->asset()->getCurrencies();
        return $list;
    }
}
