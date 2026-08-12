<?php


namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Model\Users;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
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

        return successReturn([
            'roles' => [$user->is_admin?'admin':'editor'],
            'name' => $user->account,
            'expired_at'=> $user->expired_at
        ]);
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
        if($check){
            DB::table('user_diff_filter')->where('id',$check->id)->delete();
        }else{
            DB::table('user_diff_filter')->insert([
                'diff_id'=>$diff_id,
                'user_id' => $user_id,
                'created_at' => date('Y-m-d H:i:s')
            ]);
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
                'block_ids' => [],
                'block_symbol' => [],
                'platform' => [],
                'diff_price' => '',
                'select_symbol' => [],
                'quote_name' => []
            ];
        }
        return successReturn($data);
    }

    public function saveFilter(Request $request) {
        $blockId = $request->get('block_ids');
        $blockSymbol = $request->get('block_symbol');
        $platform = $request->get('platform');
        $diffPrice = $request->get('diff_price');
        $select_symbol = $request->get('select_symbol');
        $quote_name = $request->get('quote_name');
        $user_id = $request->get('user_id');
        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        if($platform){
            foreach ($platform as &$v){
                $v = (int)$v;
            }
        }
        $data = [
            'block_ids' => $blockId?$blockId:[],
            'block_symbol' => $blockSymbol?$blockSymbol:[],
            'platform' => $platform?$platform:[],
            'diff_price' => $diffPrice?$diffPrice:'',
            'select_symbol' => $select_symbol?$select_symbol:[],
            'quote_name' => $quote_name?$quote_name:[]
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
        $pageSize = $request->get('page_size') ?? 50;

        $list = Users::where('id','>',0);
        if($account){
            $list = $list->where('account','like','%'.$account.'%');
        }
        $list = $list
            ->paginate($pageSize, ['*'], 'page', $page);
        $item = $list->getCollection();
        $item->each(function($i){
            $i->append(['status_text']);
            return $i;
        });
        $list->setCollection($item);
        return successReturn($list);

    }

    public function editUser(Request $request){
        $id = $request->get('id');
        $status = $request->get("status");
        $pwd = $request->get('pwd');

        $user = Users::find($id);
        if(!$user){
            return errorReturn('找不到该用户');
        }
        $updateData = [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        if($pwd){
            if(strlen($pwd) < 6){
                return errorReturn('密码长度少于6位');
            }
            $updateData['pwd'] = Users::makePassword($pwd);
        }
        Users::where('id',$id)->update($updateData);
        return successReturn('success');
    }

    public function expireUser(Request $request){
        $id = $request->get('id');
        $month = $request->get('month');

        $user = Users::find($id);
        if(!$user){
            return errorReturn('找不到该用户');
        }
        $admin = Users::find($request->get('user_id'));
        $expire = $user->expired_at;
        if($expire < date('Y-m-d H:i:s')){
            $res = date('Y-m-d H:i:s',strtotime("+$month month"));
        }else{
            $res = date('Y-m-d H:i:s',strtotime("+{$month} month",strtotime($user->expired_at)));
        }

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


}
