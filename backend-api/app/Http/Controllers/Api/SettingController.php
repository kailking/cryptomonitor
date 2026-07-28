<?php


namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Model\CurrencyQuotation;
use App\Model\MarketDepthDiff;
use App\Model\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Service\RedisService;

class SettingController extends Controller
{
    public function restartServer(Request $request){
        system_log(
            SystemLog::TYPE_RESTART_SERVER,
            '请求重启全部行情服务',
            (int) $request->attributes->get('user_id')
        );
        if (RedisService::getInstance(0)->set('restart_system', 1) === false) {
            throw new \RuntimeException('写入全局重启指令失败');
        }

        return successReturn([]);
    }

    public function restartPlatform(Request $request){
        $platform = $request->input('platform');
        if (!$this->isKnownPlatform($platform)) {
            return errorReturn('平台参数无效', 422, 422);
        }

        $platform = (int) $platform;
        system_log(
            SystemLog::TYPE_RESTART_PLATFORM,
            '请求重启平台：'.$platform,
            (int) $request->attributes->get('user_id')
        );
        if (
            RedisService::getInstance(0)
                ->rPush('restart_platform', $platform) === false
        ) {
            throw new \RuntimeException('写入平台重启指令失败');
        }

        return successReturn([]);
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

        $list = MarketDepthDiff::join('currency_match','currency_match.id','=','match_id')
            ->where('currency_match.is_enabled',1);
        if($symbol){
            $list->where(function($query) use($symbol){
                return $query->where('market_depth_diff.currency_name',strtoupper($symbol))->orWhere('market_depth_diff.id',$symbol);
            });
        }
        if(is_numeric($status)){
           $list = $list->where('is_show',$status);
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
        $item->each(function($i){
            $i->symbol = $i->currency_name;
            $i->append(['platform_buy','platform_sell','show_text']);
            return $i;
        });
        $list->setCollection($item);
        return successReturn($list);
    }

    public function updateDiffShow(Request $request){
        $id = $request->get('id');
        
        $user_id = $request->attributes->get('user_id');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        // if($user_id != 1 && $user_id != 31){
        //      return errorReturn('功能暂停');
        // }
       
       
        $diff = MarketDepthDiff::find($id);
        if(!$diff){
            return errorReturn('记录不存在');
        }
        if($diff->is_show == 1){
            $show = 0;
        }else{
            $show = 1;
        }
        
        MarketDepthDiff::where('id',$id)->update(['is_show' => $show]);
        return successReturn('success');
    }
    public function updateBatchDiffShow(Request $request){
         $ids  = $request->get('id');        // "1,2,3,4"
        $idArr = array_filter(explode(',', $ids));   // [1,2,3,4]
        $is_show=$request->get('is_show');
         $diffList = MarketDepthDiff::whereIn('id', $idArr)->get(); // 返回集合
        if(!$diffList){
            return errorReturn('记录不存在');
        }
        $user_id = $request->attributes->get('user_id');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        // if($user_id != 1 && $user_id != 31){
        //      return errorReturn('功能暂停');
        // }
       
       foreach ($diffList as $diff){
        MarketDepthDiff::where('id',$diff->id)->update(['is_show' => $is_show]);
       }
       return successReturn('success');
    }
    public function systemLogType(Request $request){
        $list = SystemLog::$typeList;

        $data = [];
        foreach($list as $k => $item){
            $data[] = ['key'=> $k,'item' => $item];
        }
        return successReturn(array_values($data));
    }

    public function systemLogList(Request $request){
        $page     = $request->get('page') ?? 1;
        $pageSize = $request->get('page_size') ?? 50;
        $search = $request->get('search');
        $type = $request->get('type');
        $start_time = $request->get('timestamp_start');
        $end_time = $request->get('timestamp_end');

        $list = SystemLog::join('users','users.id','=','user_id');
        if($type){
            $list = $list->where('type',$type);
        }
        if($search){
            $list = $list->where('account','like','%'.$search.'%');
        }
        if($start_time){
            $list = $list->where('system_log.created_at','>',$start_time);
        }
        if($end_time){
            $list = $list->where('system_log.created_at','<',$end_time);
        }
        $list = $list
            ->select(['system_log.*','account'])
            ->orderBy('system_log.id','desc')
            ->paginate($pageSize, ['*'], 'page', $page);
        $item = $list->getCollection();
        $item->each(function($i){
            $i->append(['type_text']);
            return $i;
        });
        $list->setCollection($item);
        return successReturn($list);
    }

    private function isKnownPlatform($platform): bool
    {
        if (
            !is_int($platform)
            && !(is_string($platform) && ctype_digit($platform))
        ) {
            return false;
        }

        return array_key_exists(
            (int) $platform,
            CurrencyQuotation::$platform_text
        );
    }
}
