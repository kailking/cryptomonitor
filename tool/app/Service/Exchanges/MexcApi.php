<?php


namespace App\Service\Exchanges;


use GuzzleHttp\Client;
use Lin\Mxc\MxcSpot;

class MexcApi implements BaseExchange
{
    public function getDepth($currency_name, $quote_name, $limit = 10)
    {

        $client = new Client();
        $symbol = sprintf('%s_%s',strtoupper($currency_name),strtoupper($quote_name));
        $url = "https://www.mexc.com/open/api/v2/market/depth?symbol=$symbol&depth=$limit";
        $response = $client->get($url);
        $res = json_decode($response->getBody()->getContents(),true);
        $bids =[];
        $asks = [];
        if(isset($res['data']['bids'])){
            foreach($res['data']['bids'] as $bid){
                $bids[] = [
                    'price' => $bid['price'],
                    'num' => $bid['quantity']
                ];
            }
        }
        if(isset($res['data']['asks'])){
            foreach($res['data']['asks'] as $ask){
                $asks[] = [
                    'price' => $ask['price'],
                    'num' => $ask['quantity']
                ];
            }
        }
        $res = [
            'bids' => $bids,
            'asks' => $asks
        ];
        return $res;
//        var_dump($depth);exit;
    }
}
