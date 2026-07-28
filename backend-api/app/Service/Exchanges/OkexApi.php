<?php


namespace App\Service\Exchanges;


use Lin\Okex\OkexV5;
use RuntimeException;

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
        $credentials = config('services.okx');
        if (
            !is_array($credentials)
            || empty($credentials['api_key'])
            || empty($credentials['api_secret'])
            || empty($credentials['passphrase'])
        ) {
            throw new RuntimeException('OKX credentials are not configured');
        }

        $okex = $this->authenticatedClient($credentials);
        $list = $okex->asset()->getCurrencies();
        return $list;
    }

    protected function authenticatedClient(array $credentials)
    {
        return new OkexV5(
            $credentials['api_key'],
            $credentials['api_secret'],
            $credentials['passphrase']
        );
    }

    public function getKline($currency_name, $quote_name)
    {
        $okex=new OkexV5();
        $kline = $okex->market()->getCandles(['instId' => sprintf('%s-%s',strtoupper($currency_name),strtoupper($quote_name)),
            'bar' => self::inverval,
            'limit' => self::size
        ]);
        $data = [];
        $kline = $kline['data'];
        foreach($kline as $item){
            $data[] = [
                'id' => $item[0],
                'open' => $item[1],
                'close' => $item[4],
                'low' => $item[3],
                'high' => $item[2],
                'amount' => $item[6],
                'vol' => $item[5],
            ];
        }
        return $data;
        // TODO: Implement getKline() method.
    }
      public function getPriceChangePercent($currency_name, $quote_name)
    {
         $okex=new OkexV5();
        $res = $okex->market()->getTicker(['instId' => sprintf('%s-%s',strtoupper($currency_name),strtoupper($quote_name))]);
         if ($res['code'] == 0 && $res['data']) {
             $ticker=$res['data'][0];
            $value=round(($ticker['last'] - $ticker['open24h']) / $ticker['open24h'] * 100,2);
            return $value;
         }
         return "0.00";
        // TODO: Implement getKline() method.
    }

}
