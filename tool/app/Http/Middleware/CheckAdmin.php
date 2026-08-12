<?php


namespace App\Http\Middleware;


use App\Model\Users;
use Closure;

class CheckAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  $guard
     * @return mixed
     */
    public function handle($request, Closure $next, $guard = null)
    {
        $token = $request->header('X-Token');

        if (empty($token)){
            return errorReturn('请重新登录',50008);
        }

        $check = Users::checkToken($token);

        if (empty($check)){
            return errorReturn('请重新登录',50008);
        }

        $user_id = $request->attributes->get('user_id');

        if(!$user_id){
            return errorReturn('请重新登录',50008);
        }
        $user = Users::find($user_id);
        if(!$user || !$user->is_admin){
            return errorReturn('非法操作',401);
        }


        // $request->attributes->add(['user_id' => $user_id]);//添加参数
        // session(['user_id' => $user_id]);
        return $next($request)->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Credentials', 'true');
    }
}
