<?php


namespace App\Model;


use App\Service\RedisService;
use Illuminate\Database\Eloquent\Model;

class MarketDepth extends Model
{
    public $table = 'market_depth';
    protected $guarded = [];


    public function getUsdtPriceAttribute(){
        switch ($this->attributes['q_name']){
            case 'BTC':
                $mul = get_platform_price('BTCUSDT',$this->attributes['platform']);
                break;
            case 'ETH':
                $mul = get_platform_price('ETHUSDT',$this->attributes['platform']);
                break;
            default:
                $mul = 1;
        }
        return $mul*$this->attributes['price'];
    }

    public static function update_depth_v2($depth_id,$price,$number,$type = 1,$index = 1){

        $model = self::find($depth_id);
        if(!$model){
            return false;
        }
        $platform = $model->platform;
        $symbol = $model->symbol;
        if($price <= 0 || $number <= 0){
            return false;
        }
        MarketDepth::where('id',$depth_id)->update([
            'price' => $price,
            'number' => $number,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if(in_array($model->symbol,['BTCUSDT','ETHUSDT','USDTUSD'])){
            $redis_1 = \App\Service\RedisService::getInstance();
            $key = sprintf('%s_%s',$model->symbol,$model->platform);
            $redis_1->set($key,$price,10);
        }
        //更新diff
        if($index != 1){
            return true;
        }
        //本平台涨跌幅
        $redis = RedisService::getInstance(3);

        $key = sprintf('m_p_%s_%s',$model->id,date('H_i'));
        $redis->set($key,$price,3600);
        if(true){
            //1分钟对比
            $min_arr = [5];
            foreach($min_arr as $min){
                $value = $redis->get(sprintf('m_p_%s_%s',$model->id,date('H_i',strtotime("-$min min"))));
                if($value){
                    $price_dif = bc_div(bc_sub($price,$value),$value)*100;
                    if(abs($price_dif) < 1){
                        continue;
                    }
                    $record = MarketChange::where('symbol',$symbol)->where('platform',$platform)->where('period',$min)->first();
                    if(!$record){
                        MarketChange::insert([
                            'match_id' => $model->match_id,
                            'symbol' => $symbol,
                            'platform' => $platform,
                            'period' => $min,
                            'direction' => $price_dif>0?1:2,
                            'change' => abs($price_dif),
                            'price_begin' => $value,
                            'price_end' => $price,
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                    }else{
                        MarketChange::where('id',$record->id)->update([
                            'change' => abs($price_dif),
                            'direction' => $price_dif>0?1:2,
                            'price_begin' => $value,
                            'price_end' => $price,
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                }
            }
        }
        //        switch ($type){
        //            case 1:
        //                //更新买一
        //                $diffList = MarketDepthDiff::where('sell_symbol', $symbol)
        //                    ->where('sell_platform', $platform)
        //                    ->where('is_show',1)
        //                    ->get();
        //                //redis 读取
        //
        //                foreach($diffList as $se){
        //                    $se->sell_price = $price;
        //                    $se->sell_num = $number;
        //                    switch ($match->quote_name){
        //                        case 'BTC':
        //                            $mul = get_platform_price('BTCUSDT',$platform);
        //                            break;
        //                        case 'ETH':
        //                            $mul = get_platform_price('ETHUSDT',$platform);
        //                            break;
        //                        default:
        //                            $mul = 1;
        //                    }
        //                    $usdt_price = $price*$mul;
        //
        //                    $se->price_diff = $se->buy_usdt_price <= 0 ? 0:bc_div(bc_sub($usdt_price,$se->buy_usdt_price),$se->buy_usdt_price)*100;
        //                    $se->total_sell_price = bc_mul($usdt_price,$number,2);
        //                    $se->updated_at = date('Y-m-d H:i:s');
        //                    $se->sell_updated_time = time();
        //                    $se->save();
        //                }
        //                break;
        //            case 2:
        //                //找出这个币的所有买单
        //                $marketList = self::where('c_name',$match->currency_name)
        //                    ->where('type',1)
        //                    ->where('price','>',0)
        //                    ->get();
        //
        //                //找出这个交易对的所有diff
        //                $diffList = MarketDepthDiff::where('symbol', $symbol)
        //                    ->where('buy_platform',$platform)
        //                    ->get();
        //                $dif_array = [];
        //                foreach($diffList as $diff){
        //                    $key = sprintf('%s-%s-%s-%s',$diff->match_id,$diff->buy_platform,$diff->sell_match_id,$diff->sell_platform);
        //                    $dif_array[$key] = $diff;
        //                }
        //                foreach($marketList as $mk) {
        //                    //过滤掉同交易所 同quote的币种
        //
        //                    if($mk->platform == $platform && $mk->q_name == $match->quote_name){
        //                        continue;
        //                    }
        //                    if(array_key_exists(sprintf('%s-%s-%s-%s',$match->id,$platform,$mk->match_id,$mk->platform),$dif_array)){
        //                        $diff = $dif_array[sprintf('%s-%s-%s-%s',$match->id,$platform,$mk->match_id,$mk->platform)];
        //                        if($diff->is_show == 0){
        //                            continue;
        //                        }
        //                        switch ($match->quote_name){
        //                            case 'BTC':
        //                                $mul = get_platform_price('BTCUSDT',$platform);
        //                                break;
        //                            case 'ETH':
        //                                $mul = get_platform_price('ETHUSDT',$platform);
        //                                break;
        //                            default:
        //                                $mul = 1;
        //                        }
        //                        $diff->buy_price = $price;
        //                        $diff->buy_num = $number;
        //                        $usdt_price = $price*$mul;
        //                        $diff->total_buy_price = bc_mul($usdt_price,$number,2);
        //                        $diff->price_diff = $diff->sell_usdt_price <= 0? 0:bc_div(bc_sub($diff->sell_usdt_price,$usdt_price),$usdt_price)*100;
        //                        $diff->updated_at = date('Y-m-d H:i:s');
        //                        $diff->buy_updated_time = time();
        //                        $diff->save();
        //                    }else{
        //                        switch ($match->quote_name){
        //                            case 'BTC':
        //                                $mul = get_platform_price('BTCUSDT',$platform);
        //                                break;
        //                            case 'ETH':
        //                                $mul = get_platform_price('ETHUSDT',$platform);
        //                                break;
        //                            default:
        //                                $mul = 1;
        //                        }
        //
        //                        $usdt_price = $mul*$price;
        //                        MarketDepthDiff::insert([
        //                            'match_id' => $match->id,
        //                            'currency_name' => $match->currency_name,
        //                            'quote_name' => $match->quote_name,
        //                            'symbol' => $symbol,
        //                            'buy_platform' => $platform,
        //                            'buy_price' => $price,
        //                            'buy_num' => $number,
        //                            'total_buy_price' => bc_mul($usdt_price,$number,2),
        //                            'sell_platform' => $mk->platform,
        //                            'sell_quote_name' => $mk->q_name,
        //                            'sell_symbol' => $mk->symbol,
        //                            'sell_match_id' => $mk->match_id,
        //                            'sell_price' => $mk->price,
        //                            'sell_num' => $mk->number,
        //                            'total_sell_price' => bc_mul($mk->usdt_price,$mk->number,2),
        //                            'price_diff' => bc_div(bc_sub($mk->usdt_price,$usdt_price),$usdt_price)*100
        //                        ]);
        //                    }
        //
        //                }
        //
        //
        //
        //
        //                break;
        //        }
        //

    }

    public static function update_depth($symbol,$platform,$price,$number,$type = 1,$index = 1){
        return true;
        $match = CurrencyMatch::getMatchBySymbol($symbol);
        if(!$match){
            return false;
        }
        if($price <= 0 || $number <= 0){
            return false;
        }

        $model = self::firstOrNew([
            'symbol' => $symbol,
            'c_name' => $match->currency_name,
            'q_name' => $match->quote_name,
            'platform' => $platform,
            'type' => $type,
            'index' => $index
        ]);
        $model->c_name = $match->currency_name;
        $model->q_name = $match->quote_name;
        $model->match_id = $match->id;
        $model->price = $price;
        $model->number = $number;
        $model->updated_at = date('Y-m-d H:i:s');
        $model->save();
        //更新diff
        if($index != 1){
            return true;
        }
        // return true;
        switch ($type){
            case 1:
                //更新买一
                $diffList = MarketDepthDiff::where('sell_symbol', $symbol)
                    ->where('sell_platform', $platform)
                    ->where('is_show',1)
                    ->get();
                foreach($diffList as $se){
                    $se->sell_price = $price;
                    $se->sell_num = $number;
                    switch ($match->quote_name){
                        case 'BTC':
                            $mul = get_platform_price('BTCUSDT',$platform);
                            break;
                        case 'ETH':
                            $mul = get_platform_price('ETHUSDT',$platform);
                            break;
                        default:
                            $mul = 1;
                    }
                    $usdt_price = $price*$mul;

                    $se->price_diff = $se->buy_usdt_price <= 0 ? 0:bc_div(bc_sub($usdt_price,$se->buy_usdt_price),$se->buy_usdt_price)*100;
                    if($se->price_diff < 0.5){
                        continue;
                    }
                    $se->total_sell_price = bc_mul($usdt_price,$number,2);
                    $se->updated_at = date('Y-m-d H:i:s');
                    $se->sell_updated_time = time();
                    $se->save();
                }
                break;
            case 2:
                //找出这个币的所有买单
                $marketList = self::where('c_name',$match->currency_name)
                    ->where('type',1)
                    ->where('price','>',0)
                    ->get();

                //找出这个交易对的所有diff
                $diffList = MarketDepthDiff::where('symbol', $symbol)
                    ->where('buy_platform',$platform)
                    ->get();
                $dif_array = [];
                foreach($diffList as $diff){
                    $key = sprintf('%s-%s-%s-%s',$diff->match_id,$diff->buy_platform,$diff->sell_match_id,$diff->sell_platform);
                    $dif_array[$key] = $diff;
                }
                foreach($marketList as $mk) {
                    //过滤掉同交易所 同quote的币种

                    if($mk->platform == $platform && $mk->q_name == $match->quote_name){
                        continue;
                    }
                    if(array_key_exists(sprintf('%s-%s-%s-%s',$match->id,$platform,$mk->match_id,$mk->platform),$dif_array)){
                        $diff = $dif_array[sprintf('%s-%s-%s-%s',$match->id,$platform,$mk->match_id,$mk->platform)];
                        if($diff->is_show == 0){
                            continue;
                        }
                        switch ($match->quote_name){
                            case 'BTC':
                                $mul = get_platform_price('BTCUSDT',$platform);
                                break;
                            case 'ETH':
                                $mul = get_platform_price('ETHUSDT',$platform);
                                break;
                            default:
                                $mul = 1;
                        }
                        $diff->buy_price = $price;
                        $diff->buy_num = $number;
                        $usdt_price = $price*$mul;
                        $diff->total_buy_price = bc_mul($usdt_price,$number,2);
                        $diff->price_diff = $diff->sell_usdt_price <= 0? 0:bc_div(bc_sub($diff->sell_usdt_price,$usdt_price),$usdt_price)*100;
                        if($diff->price_diff < 0.5){
                            continue;
                        }
                        $diff->updated_at = date('Y-m-d H:i:s');
                        $diff->buy_updated_time = time();
                        $diff->save();
                    }else{
                        switch ($match->quote_name){
                            case 'BTC':
                                $mul = get_platform_price('BTCUSDT',$platform);
                                break;
                            case 'ETH':
                                $mul = get_platform_price('ETHUSDT',$platform);
                                break;
                            default:
                                $mul = 1;
                        }

                        $usdt_price = $mul*$price;
                        MarketDepthDiff::insert([
                            'match_id' => $match->id,
                            'currency_name' => $match->currency_name,
                            'quote_name' => $match->quote_name,
                            'symbol' => $symbol,
                            'buy_platform' => $platform,
                            'buy_price' => $price,
                            'buy_num' => $number,
                            'total_buy_price' => bc_mul($usdt_price,$number,2),
                            'sell_platform' => $mk->platform,
                            'sell_quote_name' => $mk->q_name,
                            'sell_symbol' => $mk->symbol,
                            'sell_match_id' => $mk->match_id,
                            'sell_price' => $mk->price,
                            'sell_num' => $mk->number,
                            'total_sell_price' => bc_mul($mk->usdt_price,$mk->number,2),
                            'price_diff' => bc_div(bc_sub($mk->usdt_price,$usdt_price),$usdt_price)*100
                        ]);
                    }

                }
                //本平台涨跌幅
                $redis = RedisService::getInstance(3);

                $key = sprintf('m_p_%s_%s',$model->id,date('H_i'));
                $redis->set($key,$price,3600);
                if(in_array($platform,[1,2,4,8,12])){
                    //1分钟对比
                    $min_arr = [5];
                    foreach($min_arr as $min){
                        $value = $redis->get(sprintf('m_p_%s_%s',$model->id,date('H_i',strtotime("-$min min"))));
                        if($value){
                            $price_dif = bc_div(bc_sub($price,$value),$value)*100;
                            if($price < 3){
                                continue;
                            }
                            $record = MarketChange::where('symbol',$symbol)->where('platform',$platform)->where('period',$min)->first();
                            if(!$record){
                                MarketChange::insert([
                                    'match_id' => $model->match_id,
                                    'symbol' => $symbol,
                                    'platform' => $platform,
                                    'period' => $min,
                                    'direction' => $price_dif>0?1:2,
                                    'change' => abs($price_dif),
                                    'price_begin' => $value,
                                    'price_end' => $price,
                                    'created_at' => date('Y-m-d H:i:s')
                                ]);
                            }else{
                                MarketChange::where('id',$record->id)->update([
                                    'change' => abs($price_dif),
                                    'direction' => $price_dif>0?1:2,
                                    'price_begin' => $value,
                                    'price_end' => $price,
                                    'updated_at' => date('Y-m-d H:i:s')
                                ]);
                            }
                        }
                    }
                }



                break;
        }


    }
}
