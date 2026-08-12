<?php


namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use App\Model\CurrencyQuotationDiff;
use App\Model\MarketChange;
use App\Model\MarketDepthDiff;
use App\Service\RedisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class QuotationController extends Controller
{

    public function symbolOptions(){
        $symbols = CurrencyMatch::where('id','>',0)->where('is_enabled',1)->pluck('symbol')->toArray();
        return successReturn($symbols);
    }

    public function depthDetail(Request $request){
        $diffId = $request->get('id');
        $diff = MarketDepthDiff::find($diffId);
        if(!$diff){
            return errorReturn('参数错误');
        }

        $buy = get_exchange_api(1);
        $sell = get_exchange_api(8);

        $buyDepth = $buy->getDepth('BTC','USDT',10);
        $sellDepth = $sell->getDepth('BTC','USDT',10);

        return successReturn(['buy' => $buyDepth,'sell' => $sellDepth]);

    }

    public function changeList(Request $request){
        $page     = $request->get('page') ?? 1;
        $pageSize = $request->get('page_size') ?? 50;
        $platform = $request->get('platform');
        $change = $request->get('change');

        $list = MarketChange::join('currency_match','currency_match.id','=','match_id')
            ->where('currency_match.is_enabled',1)->where('change','>',0);

        if($platform){
            $list = $list->whereNotIn('market_change.platform',$platform);
        }
        if($change>0){
            $list = $list->where('change','>',$change);
        }
        $list = $list->orderBy('change','desc')
            ->select(['market_change.*','currency_match.currency_name','currency_match.quote_name'])
            ->paginate($pageSize, ['*'], 'page', $page);
        $item = $list->getCollection();
        $item->each(function($i){
            $i->symbol = $i->currency_name.'/'.$i->quote_name;
            $i->append(['platform_text']);
            return $i;
        });
        $list->setCollection($item);
        return successReturn($list);

    }


    public function deepDiffV2(Request $request){
//        $page     = $request->get('page') ?? 1;
//        $pageSize = $request->get('page_size') ?? 50;
        $diff_price = $request->get('diff_price')??'1';
        $symbol = $request->get('symbol');
        $platform = $request->get('platform');
        $block_symbol = $request->get('block_symbol');
        $block_id = $request->get('block_ids');
        $total_price = $request->get('total_price');
        $where = [];

        $list = MarketDepthDiff::join('currency_match','currency_match.id','=','match_id')
            ->where('currency_match.is_enabled',1)->where('price_diff','>',0);

        if($diff_price){
            $list = $list->where('price_diff','>',$diff_price);
        }
        if($symbol){
            $list = $list->where('market_depth_diff.symbol',strtoupper($symbol));
        }
        if($platform){
            $list = $list->where(function ($query) use ($platform){
                return $query->whereNotIn('buy_platform',$platform)->whereNotIn('sell_platform',$platform);
            });
        }
        if($total_price){
            $usdt_price = bc_div($total_price,get_usdt_rate());
            $list = $list->where(function ($query) use ($usdt_price){
                return $query->where('total_sell_price','>',$usdt_price)->where('total_buy_price','>',$usdt_price);
            });
        }
        if($block_id){
            $list = $list->whereNotIn('market_depth_diff.id',$block_id);
        }
        if($block_symbol){
            $list = $list->whereNotIn('market_depth_diff.symbol',$block_symbol);
        }
        $list = $list->orderBy('price_diff','desc')
            ->select(['market_depth_diff.*'])
            ->get();
//            ->paginate($pageSize, ['*'], 'page', $page);
//        $item = $list->getCollection();
        $res = [];
        $redis = RedisService::getInstance(0);
        $list->each(function($i)use($redis,&$res){
            $is_loan = $redis->sIsMember('loan_symbol_'.$i->sell_platform,$i->currency_name);

            $i->symbol = $i->currency_name.'/'.$i->quote_name;
            $i->append(['platform_buy','platform_sell','buy_price_rmb','sell_price_rmb','buy_price_fmt','sell_price_fmt']);
            $i->price_diff = $i->price_diff.' %';
            $i->buy_num = sprintf('%.4f',$i->buy_num);
            $i->sell_num = sprintf('%.4f',$i->sell_num);
            if($is_loan){
                $res[] = $i;
            }
            return $i;
        });
//        $list->setCollection($item);
        return successReturn($res);
    }

    public function diffConfig(Request $request){
        $page     = $request->get('page') ?? 1;
        $pageSize = $request->get('page_size') ?? 50;
        $symbol = $request->get('symbol');
        $status = $request->get('status');
        $platform = $request->get('platform');

        $user_id = $request->attributes->get('user_id');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        $user_block = DB::table('user_diff_filter')->where('user_id',$user_id)->pluck('diff_id')->toArray();

        $list = MarketDepthDiff::join('currency_match','currency_match.id','=','match_id')
            ->where('currency_match.is_enabled',1);
        if($symbol){
            $list = $list->where('market_depth_diff.currency_name',strtoupper($symbol));
        }
        if($status){
            if($status == 1){
                $list = $list->whereIn('market_depth_diff.id',$user_block);
            }else{
                $list = $list->whereNotIn('market_depth_diff.id',$user_block);
            }
        }
        if($platform){
            $list = $list->where(function ($query) use ($platform){
                return $query->where('buy_platform',$platform)->orWhere('sell_platform',$platform);
            });
        }
        $list = $list
            ->select(['market_depth_diff.*'])
            ->paginate($pageSize, ['*'], 'page', $page);
        $item = $list->getCollection();
        $item->each(function($i)use($user_block){
            $i->symbol = $i->currency_name;
            $i->append(['platform_buy','platform_sell','show_text']);
            $i->block_status = in_array($i->id,$user_block);
            return $i;
        });
        $list->setCollection($item);
        return successReturn($list);
    }

    public function deepDiff(Request $request){
        $page     = $request->get('page') ?? 1;
        $pageSize = $request->get('page_size') ?? 50;
        $diff_price = $request->get('diff_price');
        $symbol = $request->get('symbol');
        $platform = $request->get('platform');
        $block_symbol = $request->get('block_symbol');
        $block_id = $request->get('block_ids');
        $total_price = $request->get('total_price');
        $quote_name = $request->get('quote_name');
        $where = [];
        $user_id = $request->attributes->get('user_id');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        $list = MarketDepthDiff::join('currency_match','currency_match.id','=','match_id')
            ->where('currency_match.is_enabled',1)
            ->where('price_diff','>',0)
            ->where('is_show',1)
            ->whereNotExists(function($query) use($user_id){
                $query->select(DB::raw(1))
                    ->from('user_diff_filter')
                    ->whereRaw('user_diff_filter.diff_id = market_depth_diff.id')
                    ->where('user_diff_filter.user_id',$user_id);
            })
        ;

        if($diff_price){
            $list = $list->where('price_diff','>',$diff_price);
        }
        if($symbol){
            $list = $list->where('market_depth_diff.currency_name',strtoupper($symbol));
        }
        if($platform){
            $list = $list->where(function ($query) use ($platform){
                return $query->whereNotIn('buy_platform',$platform)->whereNotIn('sell_platform',$platform);
            });
        }
        if($quote_name){
            $list = $list->where(function ($query) use ($quote_name){
                return $query->whereNotIn('market_depth_diff.quote_name',$quote_name)->whereNotIn('sell_quote_name',$quote_name);
            });
        }
        if($total_price){
            $usdt_price = bc_div($total_price,get_usdt_rate());
            $list = $list->where(function ($query) use ($usdt_price){
                return $query->where('total_sell_price','>',$usdt_price)->where('total_buy_price','>',$usdt_price);
            });
        }

        if($block_symbol){
            $list = $list->whereNotIn('market_depth_diff.symbol',$block_symbol);
        }
        $list = $list->orderBy('price_diff','desc')
            ->select(['market_depth_diff.*'])
            ->paginate($pageSize, ['*'], 'page', $page);
        $item = $list->getCollection();
        $item->each(function($i){
            $i->symbol = $i->currency_name;
            $i->append(['platform_buy','platform_sell','buy_price_rmb','sell_price_rmb','buy_price_fmt','sell_price_fmt']);
            $i->price_diff = $i->price_diff.' %';
            $i->buy_num = sprintf('%.4f',$i->buy_num);
            $i->sell_num = sprintf('%.4f',$i->sell_num);
            return $i;
        });
        $list->setCollection($item);
        
        return successReturn($list);
    }

    public function quotationDiff(Request $request){
        $page     = $request->get('page') ?? 1;
        $pageSize = $request->get('page_size') ?? 50;
        $diff_price = $request->get('diff_price');
        $symbol = $request->get('symbol');
        $platform = $request->get('platform');
        $where = [];

        $list = CurrencyQuotationDiff::where('price_diff','>',0);

        if($diff_price){
            $list = $list->where('price_diff','>',$diff_price);
        }
        if($symbol){
            $list = $list->where('symbol',strtoupper($symbol));
        }
        if($platform){

            $list = $list->where(function ($query) use ($platform){
                return $query->whereNotIn('first_quotation_platform',$platform)->whereNotIn('second_quotation_platform',$platform);
            });
        }
        $list = $list->orderBy('price_diff','desc')
            ->paginate($pageSize, ['*'], 'page', $page);
        $item = $list->getCollection();
        $item->each(function($i){
            $i->append(['platform_buy','platform_sell','price_sell','price_buy']);
            $i->price_diff = $i->price_diff.' %';
            return $i;
        });
        $list->setCollection($item);
        return successReturn($list);
    }

    public function platform(Request $request){
        $list = CurrencyQuotation::$platform_text;

        $data = [];
        foreach($list as $k => $item){
            $data[] = ['key'=> $k,'item' => $item];
        }
        return successReturn(array_values($data));
    }
}
