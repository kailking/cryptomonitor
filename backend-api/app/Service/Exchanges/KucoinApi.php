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

    /**
     * @param $currency_name
     * @param $quote_name
     * @return array
     * @author Jerry
     * https://www.kucoin.com/docs/rest/spot-trading/market-data/get-klines
     */
    public function getKline($currency_name, $quote_name)
    {
        $client = new Client();
        $symbol = sprintf('%s-%s',strtoupper($currency_name),strtoupper($quote_name));
        $url = "https://api.kucoin.com/api/v1/market/candles?symbol=$symbol".'&type='.'15min';
        $response = $client->get($url);
        $kline = json_decode($response->getBody()->getContents(),true);
        /**
         *  [
            "1545904980", //Start time of the candle cycle
            "0.058", //opening price
            "0.049", //closing price
            "0.058", //highest price
            "0.049", //lowest price
            "0.018", //Transaction volume
            "0.000945" //Transaction amount
            ],
         */
        $kline = $kline['data'];
        $data = [];
        foreach($kline as $item){
            $data[] = [
                'id' => $item[0],
                'open' => $item[1],
                'close' => $item[2],
                'low' => $item[4],
                'high' => $item[3],
                'amount' => $item[6],
                'vol' => $item[5],
            ];
        }
        return $data;
    }
}
