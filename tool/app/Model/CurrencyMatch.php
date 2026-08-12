<?php


namespace App\Model;


use App\Service\RedisService;
use Illuminate\Database\Eloquent\Model;

class CurrencyMatch extends Model
{
    public $table = 'currency_match';
    const UPDATED_AT = null;

    public static function getMatchBySymbol($symbol)
    {
        $redis = RedisService::getInstance(9);
        $match = $redis->get('currency_match_' . $symbol);
        if (!$match) {
            $match = CurrencyMatch::where('symbol', $symbol)->where('is_enabled', 1)->first();
            if (!$match) {
                return false;
            }
            $redis->set('currency_match_' . $symbol, json_encode($match->toArray()), 3600);
            return $match;
        } else {
            return json_decode($match);
        }
    }

    public static function initCurrencyMatchPlatform($match_id, $platform)
    {
        $match = CurrencyMatch::find($match_id);
        $bid = MarketDepth::firstOrNew([
            'symbol' => $match->symbol,
            'c_name' => $match->currency_name,
            'q_name' => $match->quote_name,
            'platform' => $platform,
            'type' => 1,
            'index' => 1
        ]);
        $bid->match_id = $match_id;
        $bid->save();

        $ask = MarketDepth::firstOrNew([
            'symbol' => $match->symbol,
            'c_name' => $match->currency_name,
            'q_name' => $match->quote_name,
            'platform' => $platform,
            'type' => 2,
            'index' => 1
        ]);
        $ask->match_id = $match_id;

        $ask->save();
        $ask_list = MarketDepth::join('currency_match','currency_match.id','=','match_id')
            ->where('c_name', $match->currency_name)
            ->where('is_enabled',1)
            ->where('type', 2)
            ->select(['market_depth.*'])
            ->get();
        foreach ($ask_list as $ask) {
            if($ask->platform == $platform && $ask->q_name == $match->quote_name){
                continue;
            }
            $diff = MarketDepthDiff::where('currency_name', $ask->c_name)
                ->where('quote_name', $ask->q_name)
                ->where('buy_platform', $ask->platform)
                ->where('sell_symbol', $match->symbol)
                ->where('sell_quote_name', $match->quote_name)
                ->where('sell_platform', $platform)
                ->first();
            if (!$diff) {
                MarketDepthDiff::insert([
                    'match_id' => $ask->match_id,
                    'currency_name' => $ask->c_name,
                    'quote_name' => $ask->q_name,
                    'symbol' => $ask->symbol,
                    'buy_platform' => $ask->platform,
                    'buy_price' => 0,
                    'buy_num' => 0,
                    'total_buy_price' => 0,
                    'sell_platform' => $platform,
                    'sell_quote_name' => $match->quote_name,
                    'sell_symbol' => $match->symbol,
                    'sell_match_id' => $match->id,
                    'sell_price' => 0,
                    'sell_num' => 0,
                    'total_sell_price' => 0,
                    'price_diff' => 0,
                ]);
            }
        }

        $bid_list = MarketDepth::join('currency_match','currency_match.id','=','match_id')
            ->where('c_name', $match->currency_name)
            ->where('type', 1)
            ->where('is_enabled',1)
            ->select(['market_depth.*'])
            ->get();
        foreach ($bid_list as $bid) {
            if($bid->platform == $platform && $bid->q_name == $match->quote_name){
                continue;
            }
            $diff = MarketDepthDiff::where('currency_name', $match->currency_name)
                ->where('quote_name', $match->quote_name)
                ->where('buy_platform', $platform)
                ->where('sell_symbol', $bid->symbol)
                ->where('sell_quote_name', $bid->q_name)
                ->where('sell_platform', $bid->platform)
                ->first();
            if (!$diff) {
                MarketDepthDiff::insert([
                    'match_id' => $match->id,
                    'currency_name' => $match->currency_name,
                    'quote_name' => $match->quote_name,
                    'symbol' => $match->symbol,
                    'buy_platform' => $platform,
                    'buy_price' => 0,
                    'buy_num' => 0,
                    'total_buy_price' => 0,
                    'sell_platform' => $bid->platform,
                    'sell_quote_name' => $bid->q_name,
                    'sell_symbol' => $bid->symbol,
                    'sell_match_id' => $bid->match_id,
                    'sell_price' => 0,
                    'sell_num' => 0,
                    'total_sell_price' => 0,
                    'price_diff' => 0,
                ]);
            }
        }


    }
}
