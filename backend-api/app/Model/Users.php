<?php


namespace App\Model;


use App\Service\RedisService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Users extends Model
{
    public $table = 'users';

    public $hidden = [
        'pwd'
    ];

    public function permissionGrants()
    {
        return $this->hasMany(UserPermission::class, 'user_id');
    }

    public function permissionCodes(): array
    {
        return $this->permissionGrants()
            ->orderBy('permission_code')
            ->pluck('permission_code')
            ->all();
    }

    public function hasPermission(string $permissionCode): bool
    {
        return $this->permissionGrants()
            ->where('permission_code', $permissionCode)
            ->exists();
    }

    public function getStatusTextAttribute(){
        if($this->attributes['status'] == 1){
            return '正常';
        }else{
            return '封禁';
        }
    }
    
    public static function makePassword($password){
        $salt = 'tool';
        $passwordChars = str_split($password);
        foreach ($passwordChars as $char) {
            $salt .= md5($char);
        }
        return md5($salt);
    }


    public static function setToken($user_id)
    {
        //        $token = new static();
        $token_str = md5($user_id . time() . mt_rand(0, 99999));
        //
        $redis = RedisService::getInstance(1);

        //设置用户token
        $redis->set('user_token_'.$user_id,$token_str);
        $redis->expireAt('user_token_'.$user_id,strtotime('+72 hour'));

        //设置单例
        $redis->set($token_str,$user_id);
        $redis->expireAt($token_str,strtotime('+72 hour'));

        return $token_str;
    }


    public static function checkToken($token){
        $redis = RedisService::getInstance(1);
        $user_id = $redis->get($token);
        if(!$user_id){
            return false;
        }
        $user_token = $redis->get('user_token_'.$user_id);
        if(!$user_token || $user_token != $token){
            return false;
        }
        return $user_id;
    }

    public static function clearToken($user_id){
        $redis = RedisService::getInstance(1);

        //设置用户token
        $redis->del('user_token_'.$user_id);
        return true;
    }

    public static function copy_parent_config($user_id){
        $user = self::find($user_id);
        if(!$user || !$user->pid){
            return true;
        }
        //diff复制
        DB::table('user_diff_filter')
            ->where('user_id',$user_id)
            ->delete();
        $diff_ids = DB::table('user_diff_filter')
            ->where('user_id',$user->pid)
            ->pluck('diff_id')->toArray();
        foreach($diff_ids as $diff_id){
            DB::table('user_diff_filter')->insert([
                'diff_id'=>$diff_id,
                'user_id' => $user_id,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
        return true;
    }
}
