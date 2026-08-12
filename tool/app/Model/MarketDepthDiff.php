<?php


namespace App\Model;


use App\Service\RedisService;
use Illuminate\Database\Eloquent\Model;

class MarketDepthDiff extends Model
{
    public $table = 'market_depth_diff';
    const CREATED_AT =  null;

    public static function getDiffById($id){
        $redis = RedisService::getInstance(9);
        $match = $redis->get('diff_cache_'.$id);
        if(!$match){
            $match = MarketDepthDiff::where('id',$id)->first();
            if(!$match){
                return false;
            }
            $redis->set('diff_cache_'.$id,json_encode($match->toArray()),3600);
            return $match;
        }else{
            return json_decode($match);
        }
    }

     public function getMarginStatusAttribute(){
            if(in_array($this->attributes['sell_platform'],[6,7,10,11,12])){
                return 0;
            }else{
                $redis = RedisService::getInstance(5);
                $key = sprintf('platform_margin_'.$this->attributes['sell_platform']);
                if(!$redis->exists($key)){
                    return 0;
                }
                if($redis->sIsMember($key,$this->attributes['sell_symbol'])){
                    return 1;
                }else{
                    return 2;
                }
            }
        }
    public function getPlatformBuyAttribute(){
        return  CurrencyQuotation::$platform_text[$this->attributes['buy_platform']];
    }
    public function getPlatformSellAttribute(){
        return CurrencyQuotation::$platform_text[$this->attributes['sell_platform']];
    }

    public function getBuyPriceFmtAttribute(){
        $res  = number_format($this->attributes['buy_price'],12);
        return preg_replace("/\.?0*$/",'',$res);

    }
    public function getSellPriceFmtAttribute(){
        $res =  number_format($this->attributes['sell_price'],12);
        return preg_replace("/\.?0*$/",'',$res);
    }

    public function getBuyUsdtPriceAttribute(){
        switch ($this->attributes['quote_name']){
            case 'BTC':
                $mul = get_platform_price('BTCUSDT',$this->attributes['buy_platform']);
            break;
            case 'ETH':
                $mul = get_platform_price('ETHUSDT',$this->attributes['buy_platform']);
            break;
            default:
                $mul = 1;
        }
        return $mul*$this->attributes['buy_price'];
    }
    public function getSellUsdtPriceAttribute(){
        switch ($this->attributes['sell_quote_name']){
            case 'BTC':
                $mul = get_platform_price('BTCUSDT',$this->attributes['sell_platform']);
                break;
            case 'ETH':
                $mul = get_platform_price('ETHUSDT',$this->attributes['sell_platform']);
                break;
            default:
                $mul = 1;
        }
        return $mul*$this->attributes['sell_price'];
    }

    public function getBuyPriceRmbAttribute(){
        $res = bc_mul($this->attributes['total_buy_price'],get_usdt_rate());
        return intval($res);
//        return sprintf('%0.2f',$res);
//        return str_replace('0+?$','',$res);
    }
    public function getSellPriceRmbAttribute(){
        $res = bc_mul($this->attributes['total_sell_price'],get_usdt_rate());
        return intval($res);

//        return sprintf('%0.2f',$res);

//        return str_replace('0+?$','',$res);


    }

    public function getShowTextAttribute(){
        if($this->attributes['is_show'] == 1){
            return '正常';
        }else{
            return '隐藏';
        }
    }
}
