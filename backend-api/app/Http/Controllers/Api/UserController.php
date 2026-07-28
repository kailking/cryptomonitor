<?php


namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Model\Users;
use App\Services\PermissionService;
use App\Support\CanonicalUserId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class UserController extends Controller
{
    private $permissionService;

    public function __construct(PermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    public function logout(Request $request){
        $user_id = $request->get('user_id');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        Users::clearToken($user_id);
        return successReturn(null,'登出成功');
    }
    public function userInfo(Request $request){
        $user_id = $request->get('user_id');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        //        echo 133;exit;
        $user = Users::find($user_id);

        $data = [
            'block_platform'=>$user->block_platform,
            'roles' => [$user->is_admin?'admin':'editor'],
            'name' => $user->account,
            'expired_at'=> $user->expired_at
        ];
        $data['permissions'] = $user->permissionCodes();
        $data['is_permission_root'] =
            (int) $user->id === (int) config('permissions.root_user_id');

        return successReturn($data);
    }

    public function diffConfigRemark(Request $request){
        $user_id = $request->attributes->get('user_id');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        $diff_id = $request->get('id');
        $remark = $request->get('remark');
        DB::table('user_diff_filter')
            ->where('diff_id',$diff_id)
            ->where('user_id',$user_id)
            ->update(['remark' => $remark]);
        $child_id = Users::where('pid',$user_id)->pluck('id')->toArray();
        if($child_id){
            DB::table('user_diff_filter')
                ->where('diff_id',$diff_id)
                ->whereIn('user_id',$child_id)
                ->update(['remark' => $remark]);
        }
        return successReturn([]);
    }
     public function updateDiffConfigBatch(Request $request){
        $user_id = $request->attributes->get('user_id');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        $diff_id_arr = $request->get('id');
        $is_delete = $request->get('is_delete');
        $diff_ids = array_filter(explode(',', $diff_id_arr));   // [1,2,3,4]
         foreach ($diff_ids as $diff_id){
             $check = DB::table('user_diff_filter')
            ->where('diff_id',$diff_id)
            ->where('user_id',$user_id)
            ->first();
            if($is_delete){
                if($check){
                    DB::table('user_diff_filter')->where('id',$check->id)->delete();
                }
            }else{
                 if(!$check){
                     DB::table('user_diff_filter')->insert([
                        'diff_id'=>$diff_id,
                        'user_id' => $user_id,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                }
            }
           
            //同步
            $child_id = Users::where('pid',$user_id)->pluck('id')->toArray();
            if($child_id){
                if($is_delete){
                    DB::table('user_diff_filter')->where('diff_id',$diff_id)->whereIn('user_id',$child_id)->delete();
                }else{
                    foreach($child_id as $uid){
                        $check = DB::table('user_diff_filter')
                            ->where('diff_id',$diff_id)
                            ->where('user_id',$uid)
                            ->first();
                        if(!$check){
                            DB::table('user_diff_filter')->insert([
                                'diff_id'=>$diff_id,
                                'user_id' => $uid,
                                'created_at' => date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                }
            }
         }
        return successReturn('success');
    }
    public function updateDiffConfig(Request $request){
        $user_id = $request->attributes->get('user_id');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        $diff_id = $request->get('id');

        $check = DB::table('user_diff_filter')
            ->where('diff_id',$diff_id)
            ->where('user_id',$user_id)
            ->first();
        $is_delete = false;
        if($check){
            $is_delete = true;
            DB::table('user_diff_filter')->where('id',$check->id)->delete();
        }else{
            DB::table('user_diff_filter')->insert([
                'diff_id'=>$diff_id,
                'user_id' => $user_id,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
        //同步
        $child_id = Users::where('pid',$user_id)->pluck('id')->toArray();
        if($child_id){
            if($is_delete){
                DB::table('user_diff_filter')->where('diff_id',$diff_id)->whereIn('user_id',$child_id)->delete();
            }else{
                foreach($child_id as $uid){
                    $check = DB::table('user_diff_filter')
                        ->where('diff_id',$diff_id)
                        ->where('user_id',$uid)
                        ->first();
                    if(!$check){
                        DB::table('user_diff_filter')->insert([
                            'diff_id'=>$diff_id,
                            'user_id' => $uid,
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                }
            }
        }
        return successReturn('success');
    }
    public function updateChangeConfigBatch(Request $request){
        $user_id = $request->attributes->get('user_id');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        $change_id_arr = $request->get('id');
        $idArr = array_filter(explode(',', $change_id_arr));   // [1,2,3,4]
        $is_delete = $request->get('is_delete');
        foreach ($idArr as $change_id){
            $check = DB::table('market_change_user_filter')
                ->where('change_id',$change_id)
                ->where('user_id',$user_id)
                ->first();
            if($is_delete){
                if($check){
                   DB::table('market_change_user_filter')->where('id',$check->id)->delete();
                }
            }else{
                 if(!$check){
                      DB::table('market_change_user_filter')->insert([
                        'change_id'=>$change_id,
                        'user_id' => $user_id,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                }
            }
           
            //同步
            $child_id = Users::where('pid',$user_id)->pluck('id')->toArray();
            if($child_id){
                if($is_delete){
                    DB::table('market_change_user_filter')->where('change_id',$change_id)->whereIn('user_id',$child_id)->delete();
                }else{
                    foreach($child_id as $uid){
                        $check = DB::table('market_change_user_filter')
                            ->where('change_id',$change_id)
                            ->where('user_id',$uid)
                            ->first();
                        if(!$check){
                            DB::table('market_change_user_filter')->insert([
                                'change_id'=>$change_id,
                                'user_id' => $uid,
                                'created_at' => date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                }
            }
        }
        return successReturn('success');
    }
    public function updateChangeConfig(Request $request){
        $user_id = $request->attributes->get('user_id');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        $change_id = $request->get('id');

        $check = DB::table('market_change_user_filter')
            ->where('change_id',$change_id)
            ->where('user_id',$user_id)
            ->first();
        $is_delete = false;
        if($check){
            $is_delete = true;
            DB::table('market_change_user_filter')->where('id',$check->id)->delete();
        }else{
            DB::table('market_change_user_filter')->insert([
                'change_id'=>$change_id,
                'user_id' => $user_id,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
        //同步
        $child_id = Users::where('pid',$user_id)->pluck('id')->toArray();
        if($child_id){
            if($is_delete){
                DB::table('market_change_user_filter')->where('change_id',$change_id)->whereIn('user_id',$child_id)->delete();
            }else{
                foreach($child_id as $uid){
                    $check = DB::table('market_change_user_filter')
                        ->where('change_id',$change_id)
                        ->where('user_id',$uid)
                        ->first();
                    if(!$check){
                        DB::table('market_change_user_filter')->insert([
                            'change_id'=>$change_id,
                            'user_id' => $uid,
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                }
            }
        }
        return successReturn('success');
    }
    public function updateProfile(Request $request){
        $action = $request->get('action');
        $user_id = $request->attributes->get('user_id');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        $body = $request->all();
        $user = Users::find($user_id);
        switch ($action){
            case 'password':
                $rule = [
                    'oldPassword' => 'required|string',
                    'newPassword' => 'required|string',
                    'rePassword' => 'required|string',
                ];
                $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $rule);
                if ($validator->fails()) {
                   return errorReturn('参数错误',460);
                }

                if(Users::makePassword($body['oldPassword']) != $user->pwd){
                    return errorReturn('请输入正确的密码',460);
                }
                Users::where('id',$user->id)->update([
                    'pwd' => Users::makePassword($body['newPassword'])
                ]);
                Users::clearToken($user->id);
                break;

        }
        return successReturn('success');
    }
    public function setClearToken(Request $request){
        $id = $this->targetId($request->input('id'));
        if ($id === null) {
            return $this->invalidTargetIdResponse();
        }
        if ($response = $this->rootMutationDeniedResponse($request, [$id])) {
            return $response;
        }
        $user = Users::find($id);
        if(!$user){
            return errorReturn('找不到该用户');
        }
        if(!$request->attributes->get('user_id')){
            return errorReturn('请重新登录',50008);
        }
        Users::clearToken($user->id);
        return successReturn('success');
    }
    public function getPlatFormFilter(Request $request){
         $user_id = $request->get('user_id');
         $key = $request->get('key');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        $check = DB::table('user_filter')->where('user_id',$user_id)->where('key',$key)->first();
        if($check){
            $data = json_decode($check->content,true);
        }else{
           
            $data = [];
        }
        return successReturn($data);
    }
    public function savePlatFormFilter(Request $request) {
         $user_id = $request->get('user_id');
         $key = $request->get('key');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        
        $platform_keys = $request->get('platform_keys');
        $platform_keys = array_unique($platform_keys);
         $check = DB::table('user_filter')->where('user_id',$user_id)->where('key',$key)->first();
        if($check){
            DB::table('user_filter')->where('id',$check->id)->update(['content' => json_encode($platform_keys)]);
        }else{
            $sav['user_id'] = $user_id;
            $sav['key'] = $key;
            $sav['content'] = json_encode($platform_keys);
            DB::table('user_filter')->insert($sav);
        }
        return successReturn(null,'保存成功');
    }
    public function getCommonFilter(Request $request){
         $user_id = $request->get('user_id');
         $key = $request->get('key');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        
        $check = DB::table('user_filter')->where('user_id',$user_id)->where('key',$key)->first();
        if($check){
            $data = json_decode($check->content,true);
        }else{
            $data = [];
        }
        return successReturn($data);
    }
    public function saveCommonFilter(Request $request) {
         $user_id = $request->get('user_id');
         $key = $request->get('key');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        $data = $request->get('object');
         $check = DB::table('user_filter')->where('user_id',$user_id)->where('key',$key)->first();
        if($check){
            DB::table('user_filter')->where('id',$check->id)->update(['content' => json_encode($data)]);
        }else{
            $sav['user_id'] = $user_id;
            $sav['key'] = $key;
            $sav['content'] = json_encode($data);
            DB::table('user_filter')->insert($sav);
        }
        return successReturn(null,'保存成功');
    }
    public function getFilter(Request $request){
        $user_id = $request->get('user_id');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        $check = DB::table('user_filter')->where('user_id',$user_id)->where('key','match_diff')->first();
        if($check){
            $data = json_decode($check->content,true);
            if(!isset($data['select_symbol'])){
                $data['select_symbol'] = [];
            }
            if(!isset($data['quote_name'])){
                $data['quote_name'] = [];
            }
        }else{
            $data = [
                'is_margin'=>'',
                'total_price'=>'',
                'second'=>5000,
                'refresh_button'=>1,
                'block_ids' => [],
                'block_symbol' => [],
                'platform' => [],
                'diff_price' => '',
                'select_symbol' => [],
                'quote_name' => [],
                'margin_status'=>'',
            ];
        }
        return successReturn($data);
    }

    public function saveFilter(Request $request) {
        $blockId = $request->get('block_ids');
        $blockSymbol = $request->get('block_symbol');
        $platform = $request->get('platform');
        $is_margin = $request->get('is_margin');
        $diffPrice = $request->get('diff_price');
        $select_symbol = $request->get('select_symbol');
        $quote_name = $request->get('quote_name');
        $margin_status = $request->get('margin_status');
        $second = $request->get('second');
        $refresh_button = $request->get('refresh_button');
        $user_id = $request->get('user_id');
        $total_price=$request->get('total_price');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        if($platform){
            foreach ($platform as &$v){
                $v = (int)$v;
            }
        }
        $data = [
            'is_margin'=>$is_margin?$is_margin:'',
            'total_price'=>$total_price?$total_price:'',
            'second'=>$second?$second:5000,
            'refresh_button'=>$refresh_button?$refresh_button:1,
            'block_ids' => $blockId?$blockId:[],
            'block_symbol' => $blockSymbol?$blockSymbol:[],
            'platform' => $platform?$platform:[],
            'diff_price' => $diffPrice?$diffPrice:'',
            'select_symbol' => $select_symbol?$select_symbol:[],
            'quote_name' => $quote_name?$quote_name:[],
            'margin_status'=>$margin_status
        ];
        $check = DB::table('user_filter')->where('user_id',$user_id)->where('key','match_diff')->first();
        if($check){
            DB::table('user_filter')->where('id',$check->id)->update(['content' => json_encode($data)]);
        }else{
            $sav['user_id'] = $user_id;
            $sav['key'] = 'match_diff';
            $sav['content'] = json_encode($data);
            DB::table('user_filter')->insert($sav);
        }
        return successReturn(null,'保存成功');

    }

    public function userList(Request $request){

        $account = $request->get('account');
        $page     = $request->get('page') ?? 1;
        $pageSize = $request->get('page_size') ?? 500;
        $list = Users::where('id','>',0);
        if($account){
            $list = $list->where('account','like','%'.$account.'%');
        }
        $list = $list
            ->orderBy('account')
            ->paginate($pageSize, ['*'], 'page', $page);
        $list->makeHidden(['last_login_ip']);    
        $item = $list->getCollection();
        $rootId = (int) config('permissions.root_user_id');
        $item->each(function($i) use ($rootId){
            $i->append(['status_text']);
            $i->is_permission_root = (int) $i->id === $rootId;
            return $i;
        });
        $list->setCollection($item);
        return successReturn($list);

    }

    public function editUser(Request $request){
        $id = $this->targetId($request->input('id'));
        if ($id === null) {
            return $this->invalidTargetIdResponse();
        }
        if ($response = $this->rootMutationDeniedResponse($request, [$id])) {
            return $response;
        }
        $status = $request->get("status");
        $pwd = $request->get('pwd');
        $expire_at = $request->get('expired_at');
        $block_platform = $request->get('block_platform',null);
        $user = Users::find($id);
        if(!$user){
            return errorReturn('找不到该用户');
        }
        
        $updateData = [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
            // 'expired_at' => date('Y-m-d H:i:s',strtotime($expire_at)),
            'block_platform' => empty($block_platform)?null:$block_platform
        ];
        if($pwd){
            if(strlen($pwd) < 6){
                return errorReturn('密码长度少于6位');
            }
            $updateData['pwd'] = Users::makePassword($pwd);
        }
        Users::where('id',$id)->update($updateData);
        Users::clearToken($id);
        return successReturn('success');
    }
    public function editUserRemark(Request $request){
        $id = $this->targetId($request->input('id'));
        if ($id === null) {
            return $this->invalidTargetIdResponse();
        }
        if ($response = $this->rootMutationDeniedResponse($request, [$id])) {
            return $response;
        }
        $remark = $request->get("remark");
        
        $user = Users::find($id);
        if(!$user){
            return errorReturn('找不到该用户');
        }
        $updateData = [
            'remark' => $remark,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        Users::where('id',$id)->update($updateData);
        return successReturn('success');
    }
      public function expireDateUser(Request $request){
        $id = $this->targetId($request->input('id'));
        if ($id === null) {
            return $this->invalidTargetIdResponse();
        }
        if ($response = $this->rootMutationDeniedResponse($request, [$id])) {
            return $response;
        }
        $date = $request->get('date');

        $user = Users::find($id);
        if(!$user){
            return errorReturn('找不到该用户');
        }
        $admin = Users::find($request->get('user_id'));
        Users::where('id',$user->id)->update([
            'expired_at' => $date
        ]);
      
            system_log(1,sprintf('用户%s续费变更，失效时间，操作人:%s',$user->account,$date,$admin->account),$user->id);
       
        return successReturn('success');
    }
     public function expireBatchDateUser(Request $request){
        $date = $request->get('date');
        $idArr = $this->targetIds($request->input('id'));
        if ($idArr === null) {
            return $this->invalidTargetIdResponse();
        }
        if ($response = $this->rootMutationDeniedResponse($request, $idArr)) {
            return $response;
        }

        return DB::transaction(function () use ($date, $idArr, $request) {
            $userList = $this->lockedBatchUsers($idArr);
            if ($userList === null) {
                return errorReturn('找不到该用户');
            }
            $admin = Users::find($request->get('user_id'));
            foreach ($userList as $user){
                Users::where('id',$user->id)->update([
                    'expired_at' => $date
                ]);

                system_log(1,sprintf('用户%s续费变更，失效时间，操作人:%s',$user->account,$date,$admin->account),$user->id);
            }
            return successReturn('success');
        });
    }
  public function expireBatchUser(Request $request){
    $month = $this->renewalMonth($request->input('month'));
    if ($month === null) {
        return $this->invalidRenewalMonthResponse();
    }
    $idArr = $this->targetIds($request->input('id'));
    if ($idArr === null) {
        return $this->invalidTargetIdResponse();
    }
    if ($response = $this->rootMutationDeniedResponse($request, $idArr)) {
        return $response;
    }

    return DB::transaction(function () use ($month, $idArr, $request) {
        $userList = $this->lockedBatchUsers($idArr);
        if ($userList === null) {
            return errorReturn('找不到该用户');
        }
        $admin = Users::find($request->get('user_id'));

        foreach ($userList as $user){
            $expire = $user->expired_at;

            // 获取基准时间（当前时间或过期时间，取较晚的）
            $baseTime = ($expire < date('Y-m-d H:i:s')) ? time() : strtotime($user->expired_at);

            // 获取基准年月
            $baseYear = date('Y', $baseTime);
            $baseMonth = date('n', $baseTime);

            // 计算目标年月
            $targetMonthTotal = $baseMonth + $month;
            $targetYear = $baseYear + intval(($targetMonthTotal - 1) / 12);
            $targetMonth = (($targetMonthTotal - 1) % 12) + 1;
            $targetMonthStr = str_pad($targetMonth, 2, '0', STR_PAD_LEFT);

            // 目标月1号 00:00:00
            $res = "{$targetYear}-{$targetMonthStr}-01 00:00:00";

            Users::where('id',$user->id)->update([
                'expired_at' => $res
            ]);

            for($i=0;$i<$month;$i++){
                system_log(1,sprintf('用户%s续费，时长%s月，操作人:%s',$user->account,1,$admin->account),$user->id);
            }
        }

        return successReturn('success');
    });
}
  public function expireUser(Request $request){
    $month = $this->renewalMonth($request->input('month'));
    if ($month === null) {
        return $this->invalidRenewalMonthResponse();
    }
    $id = $this->targetId($request->input('id'));
    if ($id === null) {
        return $this->invalidTargetIdResponse();
    }
    if ($response = $this->rootMutationDeniedResponse($request, [$id])) {
        return $response;
    }
    $user = Users::find($id);
    if(!$user){
        return errorReturn('找不到该用户');
    }
    $admin = Users::find($request->get('user_id'));
    $expire = $user->expired_at;
    
    // 获取基准时间（当前时间或过期时间，取较晚的）
    $baseTime = ($expire < date('Y-m-d H:i:s')) ? time() : strtotime($user->expired_at);
    
    // 获取基准年月
    $baseYear = date('Y', $baseTime);
    $baseMonth = date('n', $baseTime);
    
    // 计算目标年月
    $targetMonthTotal = $baseMonth + $month;
    $targetYear = $baseYear + intval(($targetMonthTotal - 1) / 12);
    $targetMonth = (($targetMonthTotal - 1) % 12) + 1;
    $targetMonthStr = str_pad($targetMonth, 2, '0', STR_PAD_LEFT);
    
    // 目标月1号 00:00:00
    $res = "{$targetYear}-{$targetMonthStr}-01 00:00:00";

    Users::where('id',$user->id)->update([
        'expired_at' => $res
    ]);
    
    for($i=0;$i<$month;$i++){
        system_log(1,sprintf('用户%s续费，时长%s月，操作人:%s',$user->account,1,$admin->account),$user->id);
    }
    
    return successReturn('success');
}

    public function createUser(Request $request){
        $account = $request->get('account');
        $pwd = $request->get('pwd');
        $status = $request->get('status')??1;

        if(!$account || !$pwd){
            return errorReturn('参数错误');
        }

        $check = Users::where('account',$account)->first();
        if($check){
            return errorReturn('账号已存在');
        }

        if (mb_strlen($pwd) < 6 || mb_strlen($pwd) > 16) {
            return errorReturn('密码只能在6-16位之间');
        }
        Users::insert([
            'account' => $account,
            'pwd' => Users::makePassword($pwd),
            'status' => $status,
            'created_at' => date('Y-m-d H:i:s'),
            'expired_at' => date('Y-m-d H:i:s',strtotime('+1 month'))
        ]);
        return successReturn('success');

    }

    private function rootMutationDeniedResponse(Request $request, array $targetIds)
    {
        try {
            $this->permissionService->assertRootAccountMutationAllowed(
                (int) $request->attributes->get('user_id'),
                $targetIds
            );
        } catch (AuthorizationException $exception) {
            return errorReturn('根账号受保护', 403, 403);
        }

        return null;
    }

    private function targetId($id)
    {
        return CanonicalUserId::parse($id);
    }

    private function targetIds($ids)
    {
        if (!is_string($ids) || $ids === '') {
            return null;
        }

        $targetIds = [];
        foreach (explode(',', $ids) as $id) {
            $targetId = $this->targetId($id);
            if ($targetId === null) {
                return null;
            }

            if (!in_array($targetId, $targetIds, true)) {
                $targetIds[] = $targetId;
            }
        }

        return $targetIds;
    }

    private function lockedBatchUsers(array $targetIds)
    {
        $users = Users::whereIn('id', $targetIds)
            ->lockForUpdate()
            ->get();
        $actualIds = $users->pluck('id')
            ->map(function ($id): int {
                return (int) $id;
            })
            ->all();
        $expectedIds = $targetIds;
        sort($actualIds, SORT_NUMERIC);
        sort($expectedIds, SORT_NUMERIC);

        return $actualIds === $expectedIds ? $users : null;
    }

    private function invalidTargetIdResponse()
    {
        return errorReturn('用户ID参数无效', 422, 422);
    }

    private function renewalMonth($month)
    {
        return is_int($month) && in_array($month, [1, 3, 6, 12], true)
            ? $month
            : null;
    }

    private function invalidRenewalMonthResponse()
    {
        return errorReturn('月份参数无效', 422, 422);
    }

}
