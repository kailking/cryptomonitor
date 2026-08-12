<?php


namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Model\Users;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoginController extends Controller
{
    public function login(Request $request){
        $account = $request->get('username');
        $pwd = $request->get('password');
        if(!$account || !$pwd){
            return errorReturn('参数错误');
        }
        $salt = $request->get('salt')??null;

        $user = Users::where('account',$account)->first();
        if(!$user){
            return errorReturn('用户不存在');
        }
        if(Users::makePassword($pwd) != $user->pwd){
            return errorReturn('密码错误');
        }
        if($user->expired_at < date('Y-m-d H:i:s')){
            return errorReturn('账号已过期,请联系管理员。');
        }
        if($user->status != 1){
            return errorReturn('账号异常');
        }
        $ip = request()->getClientIp();
        //创建token
        Users::where('id',$user->id)->update([
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
        ]);
        if($salt){
            $last_login = DB::table('user_login_log')->where([
                'user_id' => $user->id])->orderBy('id','desc')->first();
            if($last_login){
                if($last_login->login_ip != $ip && $last_login->browser_id != $salt){
                    system_log(2,sprintf('用户%s登陆异常，ip与指纹同时更新。(上次登录ip:%s,上次登录指纹%s,本次登录ip:%s,本次登录指纹:%s)',$user->account,$last_login->login_ip,$last_login->browser_id,$ip,$salt),$user->id);
                }
            }
        }

        DB::table('user_login_log')->insert([
            'user_id' => $user->id,
            'login_at'=> date('Y-m-d H:i:s'),
            'login_ip' => $ip,
            'browser_id' => $salt
        ]);

        $token = Users::setToken($user->id);
        return successReturn(['token'=>$token]);

    }



    public function xrap(Request $request){
        $account = $request->get('account');
        $pwd = $request->get('pwd');


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
            'created_at' => date('Y-m-d H:i:s'),
            'expired_at' => date('Y-m-d H:i:s',strtotime('+1 month'))
        ]);


        return successReturn('创建成功');
    }

    public function expired(Request $request){
        $account = $request->get('account');
        $code = $request->header('code');
        if($code != '0880'){
            exit;
        }
        $check = Users::where('account',$account)->first();
        if(!$check){
            return errorReturn('账号不存在');
        }
        $expire = $check->expired_at;
        if($expire < date('Y-m-d H:i:s')){
            $res = date('Y-m-d H:i:s',strtotime('+1 month'));
        }else{
            $res = date('Y-m-d H:i:s',strtotime('+1 month',strtotime($check->expired_at)));
        }

        Users::where('id',$check->id)->update([
            'expired_at' => $res
        ]);
        return successReturn('更新成功');

    }
}
