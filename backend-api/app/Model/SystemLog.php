<?php


namespace App\Model;


use Illuminate\Database\Eloquent\Model;

class SystemLog extends Model
{
    public $table = 'system_log';

    public const TYPE_RESTART_SERVER = 3;
    public const TYPE_RESTART_PLATFORM = 4;

    public static $typeList = [
        1 => '续费',
        2 => '异常登录',
        self::TYPE_RESTART_SERVER => '重启全部行情服务',
        self::TYPE_RESTART_PLATFORM => '重启单个平台服务',
    ];

    public function getTypeTextAttribute(){
        $type = $this->attributes['type'] ?? null;

        return self::$typeList[$type] ?? '未知类型';
    }
}
