<?php


namespace App\Service\Exchanges;


use GuzzleHttp\Client;

class KucoinApi implements BaseExchange
{
    public function getDepth($currency_name, $quote_name, $limit = 10)
    {
        $client = new Client();
        $symbol = sprintf('%s-%s',strtoupper($currency_name),strtoupper($quote_name));
        $url = "https://api.kucoin.com/api/v1/market/orderbook/level2_100?symbol=$symbol";
        $response = $client->get($url);
        $res = json_decode($response->getBody()->getContents(),true);
        $bids =[];
        $asks = [];
        if(isset($res['data']['bids'])){
            $res['data']['bids'] = array_slice($res['data']['bids'],0,$limit);
            foreach($res['data']['bids'] as $bid){
                $bids[] = [
                    'price' => $bid[0],
                    'num' => $bid[1]
                ];
            }
        }
        if(isset($res['data']['asks'])){
            $res['data']['asks'] = array_slice($res['data']['asks'],0,$limit);
            foreach($res['data']['asks'] as $ask){
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
