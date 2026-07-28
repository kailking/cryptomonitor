<?php

namespace App\Service\Exchanges;

use GuzzleHttp\Client;

class Ascend implements BaseExchange
{
    public function getDepth($currency_name, $quote_name, $limit = 10)
    {
        $client = new Client();
        $symbol = sprintf('%s/%s',strtoupper($currency_name),strtoupper($quote_name));
        $url = "https://ascendex.com/api/pro/v1/depth?symbol=$symbol";
        $response = $client->get($url);
        $res = json_decode($response->getBody()->getContents(),true);
        $bids =[];
        $asks = [];
        $res = $res['data'];
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

    public function getKline($currency_name, $quote_name)
    {
        ///api/pro/v1/barhist
        $client = new Client();
        $symbol = sprintf('%s/%s',strtoupper($currency_name),strtoupper($quote_name));
        $url = "https://ascendex.com/api/pro/v1/barhist?symbol=$symbol&interval=15&n=100";
        $response = $client->get($url);
        $res = json_decode($response->getBody()->getContents(),true);
        $kline = $res['data'];
        $data = [];
        foreach($kline as $k){
            $item = $k['data'];
            $data[] = [
                'id' => $item['ts'],
                'open' => $item['o'],
                'close' => $item['c'],
                'low' => $item['l'],
                'high' => $item['h'],
                'amount' => null,
                'vol' => $item['v'],
            ];
        }
        return $data;
    }
}
