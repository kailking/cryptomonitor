<?php

namespace App\Http\Middleware;

use App\Model\Users;
use Closure;
use Illuminate\Support\Facades\Log;

class CheckPermission
{
    public function handle($request, Closure $next, string $permissionCode)
    {
        $catalog = config('permissions.catalog');

        if (!is_array($catalog) || !array_key_exists($permissionCode, $catalog)) {
            Log::error('Unknown route permission code', [
                'permission_code' => $permissionCode,
            ]);

            return errorReturn('权限配置错误', 500, 500);
        }

        $userId = (int) $request->attributes->get('user_id');
        $user = Users::find($userId);

        if (!$user || !$user->hasPermission($permissionCode)) {
            return errorReturn('当前账号无此操作权限', 403, 403);
        }

        return $next($request);
    }
}
