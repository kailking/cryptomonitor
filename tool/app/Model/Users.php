<?php


namespace App\Model;


use App\Service\RedisService;
use Illuminate\Database\Eloquent\Model;

class Users extends Model
{
    public $table = 'users';

    public $hidden = [
        'pwd'
    ];

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
        $redis->expireAt('user_token_'.$user_id,strtotime('+5 hour'));

        //设置单例
        $redis->set($token_str,$user_id);
        $redis->expireAt($token_str,strtotime('+5 hour'));

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
}
