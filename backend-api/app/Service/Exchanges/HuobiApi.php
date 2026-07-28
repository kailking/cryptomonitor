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

    /**
     * @param $currency_name
     * @param $quote_name
     * @author Jerry
     * https://www.htx.com/zh-cn/opend/newApiPages/?id=7ec3ff18-7773-11ed-9966-0242ac110003
     */
    public function getKline($currency_name, $quote_name)
    {
        $huobi = new HuobiSpot();
        $kline = $huobi->market()->getHistoryKline(['symbol' => strtolower($currency_name.$quote_name),'period' => '15min'
            ,'size' => self::size]);
        $data = [];
        $kline = $kline['data'];
        foreach($kline as &$item){
//            $data[] = [
//                'id' => $item[0],
//                'open' => $item[5],
//                'close' => $item[2],
//                'low' => $item[4],
//                'high' => $item[3],
//                'amount' => $item[6],
//                'vol' => $item[1],
//            ];
            unset($item['count']);
        }
        return $kline;
        // TODO: Implement getKline() method.
    }
    public function getPriceChangePercent($currency_name, $quote_name)
    {
        $huobi = new HuobiSpot();
        $res = $huobi->getDetailMerged(['symbol' => strtolower($currency_name.$quote_name)]);
        $tick=$res['tick'];
        $value=round( ($tick['close']-$tick['open'])/$tick['open']*100,2);
        return $value;
        // TODO: Implement getKline() method.
    }
}
