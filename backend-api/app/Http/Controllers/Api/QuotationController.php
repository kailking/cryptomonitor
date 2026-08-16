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
use Illuminate\Support\Facades\Cache;
use App\Model\Users;
use App\Exceptions\MarketChangeRedisUnavailableException;
use App\Services\MarketChangeDataSource;
use App\Services\MarketChangeSymbolNormalizer;

class QuotationController extends Controller
{
    private static $lastMarketChangeRedisErrorAt = 0;

    public function redisDepthData(Request $request)
    {
        $symbol = $request->get('symbol','BTCUSDT');
        $platform_input = $request->get('platform');
    
        $platform = CurrencyQuotation::$platform_text;
        $redis = RedisService::getInstance(3);
        $res = [];
        if($platform_input){
            $platform = [
                    $platform_input => $platform[$platform_input]
                ];
        }
        foreach($platform as $k => $value){
            $bid_key = "{$symbol}_{$k}_1";
            $ask_key = "{$symbol}_{$k}_2";
            $bids = $redis->get($bid_key);
            $asks = $redis->get($ask_key);
            $res[] = [
                'platform' => $value,
                'bids' => $bids,
                'asks' => $asks
            ];
            
            
            
        }
        return successReturn($res);
    }


    public function symbolOptions(){
        $symbols = CurrencyMatch::where('id','>',0)->where('is_enabled',1)->pluck('symbol')->toArray();
        return successReturn($symbols);
    }

    public function depthDetail(Request $request){
        $diffId = $request->get('id');
        if(!$diffId){
            return errorReturn('required diff id');
        }
        $diff = MarketDepthDiff::find($diffId);
        if(!$diff){
            return errorReturn('参数错误');
        }

        $buy = get_exchange_api($diff->buy_platform);
        if($buy){
            $buyDepth = $buy->getDepth($diff->currency_name,$diff->quote_name,10);
        }else{
            $buyDepth = [];
        }
        $sell = get_exchange_api($diff->sell_platform);
        if($sell){
            $sellDepth = $sell->getDepth($diff->sell_match->currency_name,$diff->sell_match->quote_name,10);
        }else{
            $sellDepth = [];
        }
//        $buy = get_exchange_api(5);
//        $sell = get_exchange_api(CurrencyQuotation::PLATFORM_MEXC);

        $sellDepth = $sell->getDepth($diff->sell_match->currency_name,$diff->sell_match->quote_name,10);

        return successReturn(['buy' => $buyDepth['asks'],'sell' => $sellDepth['bids']]);

    }

    public function klineDetail(Request $request)
    {
        $diffId = $request->get('id');
        if(!$diffId){
            return errorReturn('required diff id');
        }
        $diff = MarketDepthDiff::find($diffId);
        if(!$diff){
            return errorReturn('参数错误');
        }
        $kline = $sell_kline = [];
        $buy = get_exchange_api($diff->buy_platform);
        if($buy){
            $kline = $buy->getKline($diff->currency_name,$diff->quote_name);
        }
        $sell = get_exchange_api($diff->sell_platform);
        if($sell){
            $sell_kline = $sell->getKline($diff->sell_match->currency_name,$diff->sell_match->quote_name);
        }
        return successReturn(['buy' => $kline,'sell' => $sell_kline]);
    }
    public function klineBuyDetail(Request $request)
    {
        $diffId = $request->get('id');
        if(!$diffId){
            return errorReturn('required diff id');
        }
        $diff = MarketDepthDiff::find($diffId);
        if(!$diff){
            return errorReturn('参数错误');
        }
        $kline =[];
        $buy = get_exchange_api($diff->buy_platform);
        if($buy){
            $kline = $buy->getKline($diff->currency_name,$diff->quote_name);
        }
        
        return successReturn($kline);
    }
     public function klineSellDetail(Request $request)
    {
        $diffId = $request->get('id');
        if(!$diffId){
            return errorReturn('required diff id');
        }
        $diff = MarketDepthDiff::find($diffId);
        if(!$diff){
            return errorReturn('参数错误');
        }
        $kline = [];
        
        $sell = get_exchange_api($diff->sell_platform);
        if($sell){
            $kline = $sell->getKline($diff->sell_match->currency_name,$diff->sell_match->quote_name);
        }
        return successReturn($kline);
    }
     public function priceChangePercent(Request $request)
    {
        $diffId = $request->get('id');
        if(!$diffId){
            return errorReturn('required diff id');
        }
        $diff = MarketDepthDiff::find($diffId);
        if(!$diff){
            return errorReturn('参数错误');
        }
        $kline = $sell_kline = [];
        $buy = get_exchange_api($diff->buy_platform);
        if($buy){
            $kline = $buy->getPriceChangePercent($diff->currency_name,$diff->quote_name);
        }
        $sell = get_exchange_api($diff->sell_platform);
        if($sell){
            $sell_kline = $sell->getPriceChangePercent($diff->sell_match->currency_name,$diff->sell_match->quote_name);
        }
        return successReturn(['buy' => $kline,'sell' => $sell_kline]);
    }

    public function changeList(Request $request, MarketChangeDataSource $dataSource){
        $user_id = $request->attributes->get('user_id');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        try {
            return successReturn($dataSource->list($request, $user_id));
        } catch (MarketChangeRedisUnavailableException $e) {
            if ($this->shouldReportMarketChangeRedisError()) {
                report($e);
            }
            return errorReturn('极端行情实时数据暂不可用，请稍后重试', 50301, 503);
        } catch (\InvalidArgumentException $e) {
            return errorReturn($e->getMessage(), 422, 422);
        }
    }

    private function shouldReportMarketChangeRedisError()
    {
        $interval = max(1, (int) config('market_change.error_log_interval_seconds', 10));
        try {
            // Use the file store explicitly so a Redis outage cannot disable
            // the throttle that protects the application log.
            return Cache::store('file')->add(
                'market_change:redis_unavailable:report_lock',
                time(),
                $interval
            );
        } catch (\Throwable $e) {
            if ((time() - self::$lastMarketChangeRedisErrorAt) < $interval) {
                return false;
            }
            self::$lastMarketChangeRedisErrorAt = time();
            return true;
        }
    }


    public function deepDiffV2(Request $request){
//        $page     = $request->get('page') ?? 1;
//        $pageSize = $request->get('page_size') ?? 50;
        $diff_price = $request->get('diff_price')??0;
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
     public function changeConfig(Request $request){
        $page     = $request->get('page') ?? 1;
        $pageSize = $request->get('page_size') ?? 50;
        $symbol = $request->get('symbol');
        
        $platform = $request->get('platform');

        $user_id = $request->attributes->get('user_id');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        $user_data=DB::table('users')->where('id',$user_id)->first();

        $list = MarketChange::join('currency_match','currency_match.id','=','market_change.match_id')
            ->where('currency_match.is_enabled',1)
            ->where('market_change.period', 5);
        if($symbol){
            $list = $list->where(
                'market_change.symbol',
                'like',
                '%' . MarketChangeSymbolNormalizer::upper($symbol) . '%'
            );
        }
        if($user_data && $user_data->block_platform){
            $blockedPlatforms = array_values(array_filter(array_map('intval', explode(',', $user_data->block_platform))));
            if ($blockedPlatforms) {
                $list->whereNotIn('market_change.platform', $blockedPlatforms);
            }
        }
        if($platform){
            $list->where('market_change.platform', (int) $platform);
        }
        $status = $request->get('status');
        if ((string) $status === '1') {
            $list->whereExists(function ($query) use ($user_id) {
                $query->select(DB::raw(1))->from('market_change_user_filter')
                    ->whereColumn('market_change_user_filter.change_id', 'market_change.id')
                    ->where('market_change_user_filter.user_id', $user_id);
            });
        } elseif ((string) $status === '2') {
            $list->whereNotExists(function ($query) use ($user_id) {
                $query->select(DB::raw(1))->from('market_change_user_filter')
                    ->whereColumn('market_change_user_filter.change_id', 'market_change.id')
                    ->where('market_change_user_filter.user_id', $user_id);
            });
        }
        $list = $list
            ->orderBy('market_change.id')
            ->select([
                'market_change.*',
                'currency_match.currency_name',
                'currency_match.quote_name'
            ])
            ->paginate($pageSize, ['*'], 'page', $page);
        $item = $list->getCollection();
        $change_ids = array_column($item->toArray(),'id');
        $user_block_list = DB::table('market_change_user_filter')->where('user_id',$user_id)->whereIn('change_id',$change_ids)->select(['change_id'])->get();
        $user_block_arr = [];
        foreach($user_block_list as $it){
            $user_block_arr[$it->change_id] = $it;
        }
        $item->each(function($i)use($user_block_arr){
            $i->append(['platform_text']);
            if(array_key_exists($i->id,$user_block_arr)){
                $i->block_status =true;
            }else{
                $i->block_status = false;
            }
            return $i;
        });
        $list->setCollection($item);
        return successReturn($list);
    }
//     public function diffConfig(Request $request){
//         $page     = $request->get('page') ?? 1;
//         $pageSize = $request->get('page_size') ?? 50;
//         $symbol = $request->get('symbol');
//         $status = $request->get('status');
//         $platform = $request->get('platform');

//         $user_id = $request->attributes->get('user_id');
//         if(!$user_id){
//             return errorReturn('请重新登录',50008);
//         }
//         $user_block = DB::table('user_diff_filter')->where('user_id',$user_id)->pluck('diff_id')->toArray();

//         $list = MarketDepthDiff::join('currency_match','currency_match.id','=','match_id')->where('currency_match.is_enabled',1);
            
//         if($symbol){
//             $list = $list->where('market_depth_diff.currency_name',strtoupper($symbol));
//         }
//         if($status){
//             if($status == 1){
//                 $list = $list->whereIn('market_depth_diff.id',$user_block);
//             }else{
//                 $list = $list->whereNotIn('market_depth_diff.id',$user_block);
//             }
//         }
//         if($platform){
//             $list = $list->where(function ($query) use ($platform){
//                 return $query->where('buy_platform',$platform)->orWhere('sell_platform',$platform);
//             });
//         }
//         $list = $list
//             ->select([
//             'market_depth_diff.*',
//         ])->addSelect([
//     'remark' => DB::table('market_depth_remark')
//         ->select('remark')
//         ->whereColumn('market_depth_remark.diff_id', 'market_depth_diff.id')
//         ->where('market_depth_remark.user_id', $user_id)
//         ->limit(1)
// ])->paginate($pageSize, ['*'], 'page', $page);
//         $item = $list->getCollection();
//         $diff_ids = array_column($item->toArray(),'id');
//         $user_block_list = DB::table('user_diff_filter')->where('user_id',$user_id)->whereIn('diff_id',$diff_ids)->select(['diff_id'])->get();
//         $user_block_arr = [];
//         foreach($user_block_list as $it){
//             $user_block_arr[$it->diff_id] = $it;
//         }
//         $item->each(function($i)use($user_block,$user_block_arr){
//             $i->symbol = $i->currency_name;
//             $i->append(['platform_buy','platform_sell','show_text']);
//             if(array_key_exists($i->id,$user_block_arr)){
//                 $i->block_status =true;
//                 // $i->remark = $user_block_arr[$i->id]->remark;
//             }else{
//                 $i->block_status = false;
//                 // $i->remark = null;
//             }
//             return $i;
//         });
//         $list->setCollection($item);
//         return successReturn($list);
//     }
    public function diffConfig(Request $request)
{
    $page     = $request->get('page', 1);
    $pageSize = $request->get('page_size', 50);
    $symbol   = $request->get('symbol');
    $status   = $request->get('status');
    $platform = $request->get('platform');
    
    $userId = $request->attributes->get('user_id');
    if (!$userId) {
        return errorReturn('请重新登录', 50008);
    }
   $user_data=DB::table('users')->where('id',$userId)->first();
    // 1. 初始化查询，预选字段
    $query = MarketDepthDiff::query()
        ->join('currency_match', 'currency_match.id', '=', 'market_depth_diff.match_id')
        ->where('currency_match.is_enabled', 1)
        
        ->select(['market_depth_diff.*']);
    if($user_data->block_platform){
          $query->where(function ($q) use ($user_data) {
            $q->where('buy_platform', '!=', $user_data->block_platform)
              ->where('sell_platform', '!=', $user_data->block_platform);
        });
    }
    // 2. 动态筛选
    if ($symbol) {
        $query->where('market_depth_diff.currency_name', strtoupper($symbol));
    }

    if ($platform) {
        $query->where(function ($q) use ($platform) {
            $q->where('buy_platform', $platform)->orWhere('sell_platform', $platform);
        });
    }

    // 3. 状态筛选与排序优化
    if ($status == 1) {
        // 筛选已拦截：使用 Join 方便排序
        $query->join('user_diff_filter', function ($join) use ($userId) {
            $join->on('user_diff_filter.diff_id', '=', 'market_depth_diff.id')
                 ->where('user_diff_filter.user_id', '=', $userId);
        })->orderByDesc('user_diff_filter.created_at');
    } else {
        if ($status == 2) { // 假设 2 为未拦截，使用常规模态查询
            $query->whereNotExists(function ($q) use ($userId) {
                $q->select(DB::raw(1))
                  ->from('user_diff_filter')
                  ->whereColumn('user_diff_filter.diff_id', 'market_depth_diff.id')
                  ->where('user_id', $userId);
            });
        }
        // 默认排序（可根据需求修改）
        $query->orderByDesc('market_depth_diff.id');
    }

    // 4. 注入子查询字段 (Remark)
    $query->addSelect([
        'remark' => DB::table('market_depth_remark')
            ->select('remark')
            ->whereColumn('diff_id', 'market_depth_diff.id')
            ->where('user_id', $userId)
            ->limit(1)
    ]);

    // 5. 分页查询
    $list = $query->paginate($pageSize, ['*'], 'page', $page);

    // 6. 批量处理集合数据（减少内存中重复查询）
    $items = $list->getCollection();
    $diffIds = $items->pluck('id')->toArray();

    // 批量获取当前页数据的拦截状态，避免在 Each 循环里查库或读取全量 $user_block
    $currentBlockIds = DB::table('user_diff_filter')
        ->where('user_id', $userId)
        ->whereIn('diff_id', $diffIds)
        ->pluck('diff_id')
        ->flip(); // 使用 flip 提高查询效率 (isset 比 in_array 快)

    $items->transform(function ($item) use ($currentBlockIds) {
        $item->symbol = $item->currency_name;
        $item->block_status = isset($currentBlockIds[$item->id]);
        
        // Append 操作（如非必要，建议在 Model 的 $appends 中定义，或者按需处理）
        $item->append(['platform_buy', 'platform_sell', 'show_text']);
        
        return $item;
    });

    return successReturn($list);
}
    public function diffWithdrawInfo(Request $request)
    {
        $id = $request->get('id');
        $model = MarketDepthDiff::find($id);

        if (!$model) {
            return errorReturn('data not found');
        }
        $buy_list = [];

        // ==========================================
        // 1. 获取买方平台的充提列表
        // ==========================================
        $list = DB::table('platform_withdraw')
            ->where('currency_name', $model->currency_name)
            ->where('platform', $model->buy_platform)
            ->get();

        foreach ($list as $item) {
            // 🚀 核心增加：通过联合键关联查询 platform_address 全表数据
            // 注意：为了防止 network 为 NULL 导致查询匹配失败，统一转为空字符串进行匹配
            $platform_address = DB::table('platform_address')
                ->where('currency_name', $item->currency_name)
                ->where('platform', $item->platform)
                ->where('network', $item->network ?? '')
                ->first();

            $buy_list[] = [
                'currency_name'      => $item->currency_name,
                'chain'              => $item->network,
                'wd_status'          => $item->is_withdraw,
                'wd_status_text'     => $item->is_withdraw ? '正常' : '关闭',
                'withdraw_fee'       => $item->withdraw_fee,
                'withdraw_precision' => $item->withdraw_precision,
                'confirm_num'        => $item->confirm_num,
                'updated_at'         => $item->updated_at,
                'platform'           => $item->platform,
                // 🚀 将关联查到的地址记录直接挂载（如果没有配置地址则为 null）
                'platform_address'   => $platform_address 
            ];
        }

        $sell_list = [];
        
        // ==========================================
        // 2. 获取卖方平台的充提列表
        // ==========================================
        // Lbank 存币无数据
        if ($model->sell_platform == CurrencyQuotation::PLATFORM_LBANK) {
            // 保持原样不处理
        } else {
            $list = DB::table('platform_withdraw')
                ->where('currency_name', $model->currency_name)
                ->where('platform', $model->sell_platform)
                ->get();
                
            foreach ($list as $item) {
                // 🚀 核心增加：同样通过联合键关联查询卖方平台的地址数据
                $platform_address = DB::table('platform_address')
                    ->where('currency_name', $item->currency_name)
                    ->where('platform', $item->platform)
                    ->where('network', $item->network ?? '')
                    ->first();

                $sell_list[] = [
                    'currency_name'      => $item->currency_name,
                    'chain'              => $item->network,
                    'wd_status'          => $item->is_deposit, // 卖方看充值状态
                    'wd_status_text'     => $item->is_deposit ? '正常' : '关闭',
                    'withdraw_fee'       => $item->withdraw_fee,
                    'withdraw_precision' => $item->withdraw_precision,
                    'confirm_num'        => $item->confirm_num,
                    'updated_at'         => $item->updated_at,
                    'platform'           => $item->platform,
                    // 🚀 将关联查到的地址记录直接挂载
                    'platform_address'   => $platform_address 
                ];
            }
        }

        return successReturn([
            'buy_list'  => $buy_list,
            'sell_list' => $sell_list
        ]);
    }
   /**
     * 保存交易所钱包地址记录 (彻底解耦版：联合键主导，自动 Upsert)
     * @param Request $request
     */
    public function savePlatformAddress(Request $request)
    {
        // 核心联合主键 (替代了之前的 withdraw_id 和 id)
        $currencyName = strtoupper(trim((string)$request->get('currency_name', '')));
        $platform = $request->get('platform');
        $network = trim((string)$request->get('network', '')); // 无链币种传空字符串
        
        // 业务配置参数
        $network_type = $request->get('network_type'); // 1=ETH, 2=BSC, 3=SOL
        $address = trim((string)$request->get('address', ''));
        $contract = trim((string)$request->get('contract', '')); // 允许为空

        // 基础参数校验 (注意 platform 可能是 0，所以用 !== null 判断)
        if ($currencyName === '' || $platform === null || !$network_type || $address === '') {
            return errorReturn('缺少必要参数: currency_name, platform, network_type 或 address');
        }

        // 1. 根据联合键查找数据库中是否已经有配置了
        $record = DB::table('platform_address')
            ->where('currency_name', $currencyName)
            ->where('platform', $platform)
            ->where('network', $network)
            ->first();

        if ($record) {
            // ==========================================
            // 📝 存在，走编辑逻辑 (Update)
            // ==========================================
            $updateData = [
                'network_type' => $network_type,
                'address'      => $address,
                'contract'     => $contract,
                'updated_at'   => date('Y-m-d H:i:s')
            ];

            // 🚨 核心风控：如果修改了地址或合约，强制清空老余额
            if ($record->address !== $address || $record->contract !== $contract) {
                $updateData['balance'] = null;
            }

            DB::table('platform_address')->where('id', $record->id)->update($updateData);

            return successReturn('钱包地址配置已更新');

        } else {
            // ==========================================
            // ➕ 不存在，走新增逻辑 (Insert)
            // ==========================================
            DB::table('platform_address')->insert([
                'currency_name' => $currencyName,
                'platform'      => $platform,
                'network'       => $network,
                'network_type'  => $network_type,
                'address'       => $address,
                'contract'      => $contract,
                'balance'       => null, // 初始必须为空
                'updated_at'    => date('Y-m-d H:i:s')
            ]);

            return successReturn('钱包地址配置已新增');
        }
    }
    /**
     * 调用 Service 去链上查询并更新最新余额 (联合键主导版)
     * @param Request $request
     */
    public function refreshPlatformBalance(Request $request)
    {
        // 核心联合主键
        $currencyName = strtoupper(trim((string)$request->get('currency_name', '')));
        $platform = $request->get('platform');
        $network = trim((string)$request->get('network', '')); 

        if ($currencyName === '' || $platform === null) {
            return errorReturn('缺少必要参数: currency_name, platform');
        }

        // 1. 通过联合键获取地址配置信息
        $record = DB::table('platform_address')
            ->where('currency_name', $currencyName)
            ->where('platform', $platform)
            ->where('network', $network)
            ->first();

        if (!$record) {
            return errorReturn('未找到该通道的钱包地址配置记录，请先去配置');
        }

        // 2. 实例化我们封装好的链上查询服务
        $service = new \App\Service\TokenBalanceService();
        $balance = null;

        try {
            $contractAddress = !empty($record->contract) ? trim($record->contract) : null;

            // 3. 路由查询
            switch ($record->network_type) {
                case 1: // ETH
                    $balance = $service->getEthBalance($record->address, $contractAddress);
                    break;
                case 2: // BSC
                    $balance = $service->getBscBalance($record->address, $contractAddress);
                    break;
                case 3: // SOL
                    $balance = $service->getSolBalance($record->address, $contractAddress);
                    break;
                default:
                    return errorReturn('不支持的 network_type: ' . $record->network_type);
            }

            if ($balance === null) {
                return errorReturn('链上节点未返回有效余额数据，请重试');
            }

            // 4. 更新数据库 (用查出来的主键去更新最高效)
            DB::table('platform_address')->where('id', $record->id)->update([
                'balance'    => $balance,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // 5. 成功返回
            return successReturn([
                'balance' => $balance,
                'msg'     => '余额刷新成功'
            ]);

        } catch (\Exception $e) {
            // 🚨 终极拦截：拒绝 0 掩盖报错
            return errorReturn('查询失败(节点通讯异常): ' . $e->getMessage());
        }
    }
    
    
     public function isCollect(Request $request){
        $user_id = $request->get('user_id');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        $diff_id=$request->get('diff_id');
        $collect_id = $request->get('id');
        $buy_platform=$request->get('buy_platform');
        $sell_platform=$request->get('sell_platform');
        $match_id=$request->get('match_id');
        $sell_match_id=$request->get('sell_match_id');
        $status = $request->get('status');
        $check=DB::table('market_depth_collect')
            ->where('diff_id',$diff_id)
            ->where('user_id',$user_id)
            ->first();
        if($check){
            DB::table('market_depth_collect')
            ->where('id',$check->id)
            ->update(['status' => $status]);
        }else{
            $res= DB::table('market_depth_collect')->insertGetId([
                 'status'=>1,
                 'diff_id'=>$diff_id,
                 'buy_platform'=>$buy_platform,
                 'sell_platform'=>$sell_platform,
                 'match_id'=>$match_id,
                 'sell_match_id'=>$sell_match_id,
                 'user_id'=>$user_id
             ]);
             return successReturn($res);
        }
    
        return successReturn('success');
    }
     public function updateReamrk(Request $request){
        $user_id = $request->get('user_id');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        $diff_id = $request->get('diff_id');
        $buy_platform=$request->get('buy_platform');
        $sell_platform=$request->get('sell_platform');
        $match_id=$request->get('match_id');
        $sell_match_id=$request->get('sell_match_id');
        $remark = $request->get('remark');
        $check=DB::table('market_depth_remark')
            ->where('diff_id',$diff_id)
            ->where('user_id',$user_id)
            ->first();
        if($check){
            DB::table('market_depth_remark')
            ->where('id',$check->id)
            ->update(['remark' => $remark]);
        }else{
            $res= DB::table('market_depth_remark')->insertGetId([
                'diff_id'=>$diff_id,
                 'remark'=>$remark,
                 'buy_platform'=>$buy_platform,
                 'sell_platform'=>$sell_platform,
                 'match_id'=>$match_id,
                 'sell_match_id'=>$sell_match_id,
                 'user_id'=>$user_id
             ]);
             return successReturn($res);
        }
    
        return successReturn('success');
    }
  public function deepDiff(Request $request)
{
    $page     = (int)($request->get('page') ?? 1);
    $pageSize = (int)($request->get('page_size') ?? 50);

    $diff_price   = $request->get('diff_price');
    $symbol       = $request->get('symbol');
    $platform     = $request->get('platform');
    $block_symbol = $request->get('block_symbol');
    // $block_symbol_temp = $request->get('block_symbol_temp');
    // $buy_platform_temp = $request->get('buy_platform_temp');
    // $sell_platform_temp = $request->get('sell_platform_temp');
    $total_price  = $request->get('total_price');
    $quote_name   = $request->get('quote_name');
    $is_margin    = $request->get('is_margin'); // 新增参数
    $block_id_temp = $request->get('block_id_temp');
    
    $user_id = $request->attributes->get('user_id');
    if(!$user_id) return errorReturn('请重新登录', 50008);

    $q = MarketDepthDiff::query()
        ->join('currency_match','currency_match.id','=','market_depth_diff.match_id')
        ->where('currency_match.is_enabled',1)
        ->whereBetween('market_depth_diff.price_diff', [0.05, 2000])
        ->where('market_depth_diff.is_show',1)
        ->whereNotExists(function($query) use($user_id){
            $query->select(DB::raw(1))
                ->from('user_diff_filter')
                ->whereColumn('user_diff_filter.diff_id', 'market_depth_diff.id')
                ->where('user_diff_filter.user_id',$user_id);
        });

    // ===== ✅ 新增 is_margin 过滤逻辑 =====
    if ($is_margin == 1) {
        $q->whereExists(function ($query) {
            $query->select(DB::raw(1))
                ->from('platform_margin')
                ->whereColumn('platform_margin.currency_match_id', 'market_depth_diff.match_id')
                ->whereColumn('platform_margin.platform', 'market_depth_diff.sell_platform')
                ->where('platform_margin.is_margin', 1);
        });
    }

    if($diff_price) $q->where('market_depth_diff.price_diff','>', $diff_price);
    if($symbol) $q->where('market_depth_diff.currency_name', strtoupper($symbol));
    if($platform) {
        $q->whereNotIn('market_depth_diff.buy_platform', (array)$platform)
          ->whereNotIn('market_depth_diff.sell_platform', (array)$platform);
    }
    if($quote_name) {
        $q->whereNotIn('market_depth_diff.quote_name', (array)$quote_name)
          ->whereNotIn('market_depth_diff.sell_quote_name', (array)$quote_name);
    }
    if($total_price) {
        $q->where(function ($query) use($total_price) {
            $query->where('market_depth_diff.total_sell_price','>', $total_price)
          ->orWhere('market_depth_diff.total_buy_price','>', $total_price);
        });
        
    }
    if($block_symbol) $q->whereNotIn('market_depth_diff.symbol', (array)$block_symbol);
    
    if($block_id_temp) $q->whereNotIn('market_depth_diff.id',(array)$block_id_temp);
    // if($block_symbol_temp){
    //     $q->where(function($query) use ($block_symbol_temp, $buy_platform_temp, $sell_platform_temp) {
    //         $query->whereNotIn('market_depth_diff.symbol', $block_symbol_temp)
    //               ->orWhereNotIn('market_depth_diff.buy_platform', $buy_platform_temp)
    //               ->orWhereNotIn('market_depth_diff.sell_platform', $sell_platform_temp);
    //     });
    // }

    $list = $q->orderByDesc('market_depth_diff.amount_level')
        ->orderByDesc('market_depth_diff.price_diff')
        ->select(['market_depth_diff.*'])
        ->paginate($pageSize, ['*'], 'page', $page);

    $items = $list->getCollection();
    if ($items->isEmpty()) return successReturn($list);

    $ids = $items->pluck('id')->toArray();
    $matchIds = $items->pluck('match_id')->unique()->toArray();
    $sellPlatforms = $items->pluck('sell_platform')->unique()->toArray();
    $currencyNames = $items->pluck('currency_name')->map(function($n){ return strtoupper($n); })->unique()->toArray();
    $allPlatforms = collect($items->pluck('buy_platform'))->merge($items->pluck('sell_platform'))->unique()->toArray();

    $collectMap = DB::table('market_depth_collect')->where('user_id', $user_id)->whereIn('diff_id', $ids)->get()->keyBy('diff_id');
    $remarkMap = DB::table('market_depth_remark')->where('user_id', $user_id)->whereIn('diff_id', $ids)->get()->keyBy('diff_id');

    $marginMap = []; 
    $marginRows = DB::table('platform_margin')->whereIn('currency_match_id', $matchIds)->whereIn('platform', $sellPlatforms)->where('is_margin', 1)->get();
    foreach ($marginRows as $m) $marginMap[$m->currency_match_id][$m->platform] = true;

    $currencyIds = DB::table('currency')->whereIn('name', $currencyNames)->pluck('id', 'name');
    $pwMap = [];
    if ($currencyIds->isNotEmpty()) {
        $pwRows = DB::table('platform_withdraw')->whereIn('currency_id', $currencyIds->values()->toArray())->whereIn('platform', $allPlatforms)->get();
        foreach ($pwRows as $r) $pwMap[$r->currency_id][$r->platform][] = ['network' => $r->network, 'is_withdraw' => (int)$r->is_withdraw, 'is_deposit' => (int)$r->is_deposit];
    }

    $summaryFn = function($rows) {
        $anyW = 0; $anyD = 0;
        foreach ($rows as $x) { if ($x['is_withdraw']) $anyW = 1; if ($x['is_deposit']) $anyD = 1; }
        return ['any_withdraw' => $anyW, 'any_deposit' => $anyD, 'networks' => $rows];
    };

    $items->transform(function ($i) use ($collectMap, $remarkMap, $marginMap, $currencyIds, $pwMap, $summaryFn) {
        $i->is_collect = isset($collectMap[$i->id]) ? $collectMap[$i->id]->status : 0;
        $i->collect_id = isset($collectMap[$i->id]) ? $collectMap[$i->id]->id : null;
        $i->remark = isset($remarkMap[$i->id]) ? $remarkMap[$i->id]->remark : null;
        $i->remark_id = isset($remarkMap[$i->id]) ? $remarkMap[$i->id]->id : null;
        $i->sell_platform_margin = isset($marginMap[$i->match_id][$i->sell_platform]);
        $cname = strtoupper($i->currency_name);
        $cid = isset($currencyIds[$cname]) ? $currencyIds[$cname] : 0;
        $buyRows = ($cid && isset($pwMap[$cid][$i->buy_platform])) ? $pwMap[$cid][$i->buy_platform] : [];
        $sellRows = ($cid && isset($pwMap[$cid][$i->sell_platform])) ? $pwMap[$cid][$i->sell_platform] : [];
        $i->buy_withdraw_info = $buyRows; $i->sell_withdraw_info = $sellRows;
        $i->buy_withdraw_summary = $summaryFn($buyRows); $i->sell_withdraw_summary = $summaryFn($sellRows);
        $i->symbol = $i->currency_name;
        $i->append(['platform_buy','platform_sell','buy_price_rmb','sell_price_rmb','buy_price_fmt','sell_price_fmt','margin_status']);
        $i->price_diff = $i->price_diff . ' %';
        $i->buy_num = sprintf('%.4f', $i->buy_num);
        $i->sell_num = sprintf('%.4f', $i->sell_num);
        return $i;
    });

    return successReturn($list->setCollection($items));
}

    
    public function deepDiffPlus(Request $request)
{
    $page     = (int)($request->get('page') ?? 1);
    $pageSize = (int)($request->get('page_size') ?? 50);

    $diff_price   = $request->get('diff_price');
    $symbol       = $request->get('symbol');
    $platform     = $request->get('platform');
    $block_symbol = $request->get('block_symbol');
    $block_symbol_temp = $request->get('block_symbol_temp');
    $buy_platform_temp = $request->get('buy_platform_temp');
    $sell_platform_temp = $request->get('sell_platform_temp');
    $total_price  = $request->get('total_price');
    $quote_name   = $request->get('quote_name');
    $is_margin    = $request->get('is_margin'); // 新增参数
    $block_id_temp = $request->get('block_id_temp');
    
    $user_id = $request->attributes->get('user_id');
    if(!$user_id) return errorReturn('请重新登录', 50008);

    $q = MarketDepthDiff::query()
        ->join('currency_match','currency_match.id','=','market_depth_diff.match_id')
        ->where('currency_match.is_enabled',1)
        ->whereBetween('market_depth_diff.price_diff_plus', [0.05, 2000])
        ->where('market_depth_diff.is_show',1)
        ->whereNotExists(function($query) use($user_id){
            $query->select(DB::raw(1))
                ->from('user_diff_filter')
                ->whereColumn('user_diff_filter.diff_id', 'market_depth_diff.id')
                ->where('user_diff_filter.user_id',$user_id);
        });

    // ===== ✅ 新增 is_margin 过滤逻辑 =====
    if ($is_margin == 1) {
        $q->whereExists(function ($query) {
            $query->select(DB::raw(1))
                ->from('platform_margin')
                ->whereColumn('platform_margin.currency_match_id', 'market_depth_diff.match_id')
                ->whereColumn('platform_margin.platform', 'market_depth_diff.sell_platform')
                ->where('platform_margin.is_margin', 1);
        });
    }

    if($diff_price) $q->where('market_depth_diff.price_diff_plus','>', $diff_price);
    if($symbol) $q->where('market_depth_diff.currency_name', strtoupper($symbol));
    if($platform) {
        $q->whereNotIn('market_depth_diff.buy_platform', (array)$platform)
          ->whereNotIn('market_depth_diff.sell_platform', (array)$platform);
    }
    if($quote_name) {
        $q->whereNotIn('market_depth_diff.quote_name', (array)$quote_name)
          ->whereNotIn('market_depth_diff.sell_quote_name', (array)$quote_name);
    }
    if($total_price) {
        $q->where(function ($query) use($total_price) {
            $query->where('market_depth_diff.total_sell_plus','>', $total_price)
          ->orWhere('market_depth_diff.total_buy_plus','>', $total_price);
        });
        
    }
    if($block_symbol) $q->whereNotIn('market_depth_diff.symbol', (array)$block_symbol);
    if($block_id_temp) $q->whereNotIn('market_depth_diff.id',(array)$block_id_temp);
    // if($block_symbol_temp){
    //     $q->where(function($query) use ($block_symbol_temp, $buy_platform_temp, $sell_platform_temp) {
    //         $query->whereNotIn('market_depth_diff.symbol', $block_symbol_temp)
    //               ->orWhereNotIn('market_depth_diff.buy_platform', $buy_platform_temp)
    //               ->orWhereNotIn('market_depth_diff.sell_platform', $sell_platform_temp);
    //     });
    // }

    $list = $q->orderByDesc('market_depth_diff.amount_level_plus')
        ->orderByDesc('market_depth_diff.price_diff_plus')
        ->select(['market_depth_diff.*'])
        ->paginate($pageSize, ['*'], 'page', $page);

    $items = $list->getCollection();
    if ($items->isEmpty()) return successReturn($list);

    $ids = $items->pluck('id')->toArray();
    $matchIds = $items->pluck('match_id')->unique()->toArray();
    $sellPlatforms = $items->pluck('sell_platform')->unique()->toArray();
    $currencyNames = $items->pluck('currency_name')->map(function($n){ return strtoupper($n); })->unique()->toArray();
    $allPlatforms = collect($items->pluck('buy_platform'))->merge($items->pluck('sell_platform'))->unique()->toArray();

    $collectMap = DB::table('market_depth_collect')->where('user_id', $user_id)->whereIn('diff_id', $ids)->get()->keyBy('diff_id');
    $remarkMap = DB::table('market_depth_remark')->where('user_id', $user_id)->whereIn('diff_id', $ids)->get()->keyBy('diff_id');

    $marginMap = []; 
    $marginRows = DB::table('platform_margin')->whereIn('currency_match_id', $matchIds)->whereIn('platform', $sellPlatforms)->where('is_margin', 1)->get();
    foreach ($marginRows as $m) $marginMap[$m->currency_match_id][$m->platform] = true;

    $currencyIds = DB::table('currency')->whereIn('name', $currencyNames)->pluck('id', 'name');
    $pwMap = [];
    if ($currencyIds->isNotEmpty()) {
        $pwRows = DB::table('platform_withdraw')->whereIn('currency_id', $currencyIds->values()->toArray())->whereIn('platform', $allPlatforms)->get();
        foreach ($pwRows as $r) $pwMap[$r->currency_id][$r->platform][] = ['network' => $r->network, 'is_withdraw' => (int)$r->is_withdraw, 'is_deposit' => (int)$r->is_deposit];
    }

    $summaryFn = function($rows) {
        $anyW = 0; $anyD = 0;
        foreach ($rows as $x) { if ($x['is_withdraw']) $anyW = 1; if ($x['is_deposit']) $anyD = 1; }
        return ['any_withdraw' => $anyW, 'any_deposit' => $anyD, 'networks' => $rows];
    };

    $items->transform(function ($i) use ($collectMap, $remarkMap, $marginMap, $currencyIds, $pwMap, $summaryFn) {
        $i->is_collect = isset($collectMap[$i->id]) ? $collectMap[$i->id]->status : 0;
        $i->collect_id = isset($collectMap[$i->id]) ? $collectMap[$i->id]->id : null;
        $i->remark = isset($remarkMap[$i->id]) ? $remarkMap[$i->id]->remark : null;
        $i->remark_id = isset($remarkMap[$i->id]) ? $remarkMap[$i->id]->id : null;
        $i->sell_platform_margin = isset($marginMap[$i->match_id][$i->sell_platform]);
        $cname = strtoupper($i->currency_name);
        $cid = isset($currencyIds[$cname]) ? $currencyIds[$cname] : 0;
        $buyRows = ($cid && isset($pwMap[$cid][$i->buy_platform])) ? $pwMap[$cid][$i->buy_platform] : [];
        $sellRows = ($cid && isset($pwMap[$cid][$i->sell_platform])) ? $pwMap[$cid][$i->sell_platform] : [];
        $i->buy_withdraw_info = $buyRows; $i->sell_withdraw_info = $sellRows;
        $i->buy_withdraw_summary = $summaryFn($buyRows); $i->sell_withdraw_summary = $summaryFn($sellRows);
        $i->symbol = $i->currency_name;
        $i->append(['platform_buy','platform_sell','buy_price_rmb','sell_price_rmb','buy_price_fmt','sell_price_fmt','margin_status']);
        $i->price_diff_plus = $i->price_diff_plus . ' %';
        $i->buy_num_plus  = sprintf('%.4f', (float)$i->buy_num_plus);
        $i->sell_num_plus = sprintf('%.4f', (float)$i->sell_num_plus);
        return $i;
    });

    return successReturn($list->setCollection($items));
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
        $userId = $request->attributes->get('user_id');
        $user_data=DB::table('users')->where('id', $userId)->first();
        
        $list = CurrencyQuotation::$platform_text;
        $block_platform = [];
        if($user_data->block_platform){
            $block_platform = explode(',',$user_data->block_platform);
        }
        $data = [];
        foreach($list as $k => $item){

            if($k == 7 || in_array($k,$block_platform)){
                continue;
            }
            $data[] = ['key'=> $k,'item' => $item];
        }
        return successReturn(array_values($data));
    }
}
