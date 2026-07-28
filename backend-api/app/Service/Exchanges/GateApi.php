<?php


namespace App\Service\Exchanges;


use Lin\Gate\GateSpot;

class GateApi implements BaseExchange
{
    public function getDepth($currency_name, $quote_name, $limit = 10)
    {
        $gate=new GateSpot();
        $depth=$gate->market()->getOrderBook([
            'currency_pair'=>sprintf('%s_%s',strtoupper($currency_name),strtoupper($quote_name)),
            'limit' => $limit
        ]);
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

    /**
     * @param $currency_name
     * @param $quote_name
     * @return mixed
     * @author Jerry
     * https://www.gate.io/docs/developers/apiv4/zh_CN/#%E5%B8%82%E5%9C%BA-k-%E7%BA%BF%E5%9B%BE
     */
    public function getKline($currency_name, $quote_name)
    {
        $gate=new GateSpot();
        $kline = $gate->market()->getCandlesticks([
            'currency_pair'=>sprintf('%s_%s',strtoupper($currency_name),strtoupper($quote_name)),
            'interval' => self::inverval,
            'limit' => self::size
        ]);
        $data = [];
        foreach($kline as $item){
            $data[] = [
                'id' => $item[0],
                'open' => $item[5],
                'close' => $item[2],
                'low' => $item[4],
                'high' => $item[3],
                'amount' => $item[6],
                'vol' => $item[1],
            ];
        }
        /*
         * [
                "1738748700",
                "914454.52629800",
                "97403.7",
                "97454.9",
                "97274.2",
                "97369.5",
                "9.39110000",
                "true"
            ],
         * 每个时间粒度的 K 线数据，从左到右依次为:
        - 秒(s)精度的 Unix 时间戳
        - 计价货币交易额
        - 收盘价
        - 最高价
        - 最低价
        - 开盘价
        - 基础货币交易量
        - 窗口是否关闭，true 代表此段K线蜡烛图数据结束，false 代表此段K线蜡烛图数据尚未结束
         */
        return $data;
    }
       public function getPriceChangePercent($currency_name, $quote_name)
    {
        $gate=new GateSpot();
        $res = $gate->market()->getTickers([ 'currency_pair'=>sprintf('%s_%s',strtoupper($currency_name),strtoupper($quote_name))]);
         if ($res[0]) {
             $ticker=$res['data'][0];
            $value=round($ticker['change_percentage'],2);
            return $value;
         }
         return "0.00";
        // TODO: Implement getKline() method.
    }
}
