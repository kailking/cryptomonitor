<?php

namespace App\Http\Middleware;

use App\Users;
use App\Token;
use Closure;
use Session;
use Illuminate\Support\Facades\Auth;

class CheckApi
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

        $check = \App\Model\Users::checkToken($token);

        if (empty($check)){
            return errorReturn('请重新登录',50008);
        }

        $request->attributes->add(['user_id' => $check]);

        // $request->attributes->add(['user_id' => $user_id]);//添加参数
        // session(['user_id' => $user_id]);
        return $next($request)->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Credentials', 'true');
    }
}
