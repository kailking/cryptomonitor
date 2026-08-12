<?php

use Illuminate\Support\Facades\DB;
use App\Model\CurrencyQuotation;

defined('DECIMAL_SCALE') || define('DECIMAL_SCALE', 12);
bcscale(DECIMAL_SCALE);


function get_exchange_api($platform){
    switch ($platform){
        case CurrencyQuotation::PLATFORM_HUOBI:
            return new \App\Service\Exchanges\HuobiApi();
            break;
        case CurrencyQuotation::PLATFORM_BIANCE:
            return new \App\Service\Exchanges\BianceApi();
            break;
        case CurrencyQuotation::PLATFORM_OKEX:
            return new \App\Service\Exchanges\OkexApi();
            break;
        case CurrencyQuotation::PLATFORM_GATE:
            return new \App\Service\Exchanges\GateApi();
            break;
        case CurrencyQuotation::PLATFORM_MEXC:
            return new \App\Service\Exchanges\MexcApi();
            break;
        case CurrencyQuotation::PLATFORM_AEX:
            return new \App\Service\Exchanges\AexApi();
            break;
        case CurrencyQuotation::PLATFORM_KUCOIN:
            return new \App\Service\Exchanges\KucoinApi();
            break;
        default:
            throw new \Exception('system error with exchange api');
    }
}

//type 1续费
function system_log($type,$remark,$user_id = null){
    DB::table('system_log')->insert([
        'type' => $type,
        'remark' => $remark,
        'user_id' => $user_id,
        'created_at' => date('Y-m-d H:i:s')
    ]);
    return true;
}

function get_usdt_rate(){
    $redis = \App\Service\RedisService::getInstance(1);
    $rate = $redis->get('usdt_price');
    return $rate?$rate:7.27;
}
function get_platform_price($currency_match,$platform){
    $redis = \App\Service\RedisService::getInstance();
    $key = sprintf('%s_%s',$currency_match,$platform);
    $res =  $redis->get($key);
    if(!$res){
        $record = \App\Model\MarketDepth::where('symbol',$currency_match)->where('platform',$platform)->where('type',2)->first();
        if($record){
            $redis->set($key,$record->price,10);
            // $redis->expire($key,10);
            return $record->price;
        }
    }
    return $res;
}

function get_by_proxy($Url){
    // $Url = "https://pv.sohu.com/cityjson?ie=utf-8";

    // 设置代理服务器域名和端口，注意，具体的域名要依据据开通账号时分配的而定
    $proxyServer = "http-proxy-t3.dobel.cn:9180";

    // 代理账号密码信息
    $proxyUser   = "QQYHCSDC7D2LLN0";
    $proxyPass   = "2hWhcMnX";

    $ch = curl_init();


    // 设置代理服务器
    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    curl_setopt($ch, CURLOPT_PROXY, $proxyServer);

    // 设置隧道验证信息
    curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_PROXYUSERPWD, "{$proxyUser}:{$proxyPass}");


    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);

    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_URL, $Url);

    $result = curl_exec($ch);
    if ($result === false) {
        throw new \Exception(curl_error($ch), curl_errno($ch));
    }
    curl_close($ch);
    return $result;
}

function errorReturn($message,$code = 460)
{
    header('Content-Type:application/json');
    header('Access-Control-Allow-Origin:*');
    header('Access-Control-Allow-Methods:POST,GET,OPTIONS,DELETE');
    header('Access-Control-Allow-Headers:x-requested-with,content-type');
    header('Access-Control-Allow-Headers:x-requested-with,content-type,Authorization');
    if (is_string($message)){
        $message=str_replace('massage.', '', __("massage.$message"));
    }
    return response()->json(['type' => 'error','code'=>$code, 'message' => $message]);
}


 function successReturn($data ,$message = 'success',$code = 200, $type=0)
{
    header('Content-Type:application/json');
    header('Access-Control-Allow-Origin:*');
    header('Access-Control-Allow-Methods:POST,GET,OPTIONS,DELETE');
    header('Access-Control-Allow-Headers:x-requested-with,content-type');
    header('Access-Control-Allow-Headers:x-requested-with,content-type,Authorization');
    if (is_string($message)&&$type==0){
        $message=str_replace('massage.', '', __("massage.$message"));
    }

    return response()->json(['type' => 'ok','code'=>$code, 'message' => $message ,'data' => $data]);
}

function bc_add($left_operand, $right_operand, $out_scale = DECIMAL_SCALE)
{
    return bc_method('bcadd', $left_operand, $right_operand, $out_scale);
}

function bc_sub($left_operand, $right_operand, $out_scale = DECIMAL_SCALE)
{
    return bc_method('bcsub', $left_operand, $right_operand, $out_scale);
}

function bc_mul($left_operand, $right_operand, $out_scale = DECIMAL_SCALE)
{
    return bc_method('bcmul', $left_operand, $right_operand, $out_scale);
}

function bc_div($left_operand, $right_operand, $out_scale = DECIMAL_SCALE)
{
    return bc_method('bcdiv', $left_operand, $right_operand, $out_scale);
}

function bc_mod($left_operand, $right_operand, $out_scale = DECIMAL_SCALE)
{
    return bc_method('bcmod', $left_operand, $right_operand, $out_scale);
}

function bc_comp($left_operand, $right_operand)
{
    return bc_method('bccomp', $left_operand, $right_operand);
}

function bc_pow($left_operand, $right_operand)
{
    return bc_method('bcpow', $left_operand, $right_operand);
}

function bc_method($method_name, $left_operand, $right_operand, $out_scale = DECIMAL_SCALE)
{
    $left_operand = number_format($left_operand, DECIMAL_SCALE, '.', '');
    $method_name != 'bcpow' && $right_operand = number_format($right_operand, DECIMAL_SCALE, '.', '');
    $result = call_user_func($method_name, $left_operand, $right_operand);
    return $method_name != 'bccomp' ? number_format($result, $out_scale, '.', '') : $result;
}

