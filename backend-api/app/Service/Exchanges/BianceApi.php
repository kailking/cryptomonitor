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

    public function getKline($currency_name, $quote_name)
    {
        $huobi = new Binance();
        $kline = $huobi->system()->getKlines(['symbol' => strtoupper($currency_name.$quote_name)
            ,'interval' => '15m',
            'limit' => self::size
            ]);
        /*
         * [
            1499040000000,      // 开盘时间
            "0.01634790",       // 开盘价
            "0.80000000",       // 最高价
            "0.01575800",       // 最低价
            "0.01577100",       // 收盘价(当前K线未结束的即为最新价)
            "148976.11427815",  // 成交量
            1499644799999,      // 收盘时间
            "2434.19055334",    // 成交额
            308,                // 成交笔数
            "1756.87402397",    // 主动买入成交量
            "28.46694368",      // 主动买入成交额
            "17928899.62484339" // 请忽略该参数
          ]
         */
        $data = [];
        foreach($kline as $item){
            $data[] = [
                'id' => $item[0],
                'open' => $item[1],
                'close' => $item[4],
                'low' => $item[3],
                'high' => $item[2],
                'amount' => $item[7],
                'vol' => $item[5],
            ];
        }
        return $data;
    }
     public function getPriceChangePercent($currency_name, $quote_name)
    {
        $huobi = new Binance();
        $res = $huobi->system()->get24hr(['symbol' => strtolower($currency_name.$quote_name)]);
        $value=round($res['priceChangePercent'],2);
        return $value;
        // TODO: Implement getKline() method.
    }
}
