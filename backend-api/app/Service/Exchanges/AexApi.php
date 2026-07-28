<?php


namespace App\Service\Exchanges;


use GuzzleHttp\Client;

class AexApi implements BaseExchange
{
    public function getDepth($currency_name, $quote_name, $limit = 10)
    {
        $client = new Client();
        $quoteName = strtolower($quote_name);
        $currencyName = strtolower($currency_name);
        $url = "https://api.aex.zone/v3/depth.php?mk_type=$quoteName&coinname=$currencyName";
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

    public function getKline($currency_name, $quote_name)
    {
        return [];
        // TODO: Implement getKline() method.
    }
}
