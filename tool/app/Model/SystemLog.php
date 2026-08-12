<?php


namespace App\Model;


use Illuminate\Database\Eloquent\Model;

class SystemLog extends Model
{
    public $table = 'system_log';

    public static $typeList = [
        1 => '续费',
        2 => '异常登录'
    ];

    public function getTypeTextAttribute(){
        return self::$typeList[$this->attributes['type']];
    }
}
