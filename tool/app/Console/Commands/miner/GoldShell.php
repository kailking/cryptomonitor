<?php
namespace App\Console\Commands\miner;

use App\Service\RedisService;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class GoldShell extends Command
{
    protected $signature = 'goldsell_hub';
    protected $description = '查询金贝矿机状态';
    public function handle()
    {
        
        //001地址
        $worker_name='AE BOX-0001';
        $url="https://hub.goldshell.com/api/v1/machines/28:E2:97:4D:26:18/rate/line";
        $this->goldShellMethod($worker_name,$url);
        //AE BOX-0002
        //002地址
        $worker_name_2='AE BOX-0002';
        $url_2="https://hub.goldshell.com/api/v1/machines/28:E2:97:2D:FC:4C/rate/line";
        $this->goldShellMethod($worker_name_2,$url_2);
        //AE BOX-0003
        //003地址
        $worker_name_3='AE BOX-0003';
        $url_3="https://hub.goldshell.com/api/v1/machines/2A:40:53:7E:F7:13/rate/line";
        $this->goldShellMethod($worker_name_3,$url_3);
        
        //site_key=0x4AAAAAAA4p6-RqHMVlNPRf   cloundflate
//         // PEM格式的公钥
// $publicKeyPem = <<<EOD
// -----BEGIN PUBLIC KEY-----
// MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA9SVs7PFykBnCmKIaZ4ZI
// avR12ZMJ34HPBLcJ9mGsxy5weFvG9e8V1bNAzOZ1noktOA9tTnh0WdUStohp7KdV
// r2sEqciyxNffZXSgvq/sbHz4bhS8DeHfErK6bXk5Q5a+5XDUJ3wuJo/krr3dXaVA
// sbzlO/tQCEVLT6KGMH0I2jaHEa/KQ14OIpvSZ3g1M2RdyTCnD7pOeEBpbJUgK9iN
// Db+a88ggpf7x2C0g33tneg8o0NVrgliCzSIdtN/+E8faLe1K2d+RMRk9ewI0uP9f
// hPAvUGROrHClForqAAu29eeAzScAzcGZFfBRyAgI0u+oxDccNDESF0hdx1cYX9Rk
// lQIDAQAB
// -----END PUBLIC KEY-----
// EOD;
//         $url='https://hub.goldshell.com/api/v1/user/login';
//         // 加载公钥
//         $publicKeyResource = openssl_pkey_get_public($publicKeyPem);
//         if (!$publicKeyResource) {
//             echo "无法加载公钥";
//         }
        
//         // 待加密的数据
//         $dataToEncrypt = "etcferrari#123";
        
//         // 加密数据
//         $encryptedData = '';
//         if (!openssl_public_encrypt($dataToEncrypt, $encryptedData, $publicKeyResource)) {
//             echo "加密失败";
//         }
        
//         // 将加密后的数据编码为Base64
//         $encryptedBase64 = base64_encode($encryptedData);
//          $client= new Client();
//          $response = $client->request('POST', $url, [
//             'headers'=>[
//             'cf-turnstile-response'=>'1',
//             ],
//             'json' => [
//                 'email' => "lifefabric@outlook.com",
//                 'password' => $encryptedBase64,
//             ]
//         ]);
//          echo $response->getBody();

            
    }
    function goldShellMethod($worker_name,$url){
         //算力图每条数据2分钟
         $redis = RedisService::getInstance();
         $redis_key='goldshell_login_expired';
        $login_expired=$redis->get($redis_key);//开始不存在key  $login_expired:1 token过期，2跳过
        if(!$login_expired==1 || !$login_expired==2)$login_expired=1;
          $client = new Client();
        $interval_count=8;
         $login_token="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczpcL1wvd3d3LmdvbGRzaGVsbC5jb20iLCJpYXQiOjE3Mzk5NjM1ODAsIm5iZiI6MTczOTk2MzU4MCwiZXhwIjoxNzQwMzIzNTgwLCJzdWIiOiI1NTgxNSIsImVtYWlsIjoibGlmZWZhYnJpY0BvdXRsb29rLmNvbSIsInVzZXJuYW1lIjoibGlmZWZhYnJpYyIsInJhbmQiOjc2NjQ5MDgzfQ.EEDO1YRGLArt8oq58ZQ1jDpP9qwTglmPkOxqC33uvWM";
          try{
            $content=$client-> get($url,[
                'headers' => [
                    'Token' =>$login_token
                ]
            ])->getBody()->getContents();    
             $content = json_decode($content, true);
             if($content['code']==0){
                 $redis->set($redis_key,1);
                 $count=0;
                 $reversedArray=array_reverse($content['data']['list']);
                 $reversedArray = array_slice($reversedArray, 0, $interval_count);
                 foreach ($reversedArray as $v){
                     if($v<=10)$count++;
                     if($count==$interval_count){
                         echo 'Goldshell 异常: '. $worker_name. PHP_EOL;
                         $this->sendFeishuNotification($worker_name,false);
                         return;
                     }
                 }
                 echo $worker_name."正常执行". PHP_EOL;
             }
             if($content['code']==401 && $login_expired==1){
                  echo $worker_name."GoldShell Token过期". PHP_EOL;
                  $redis->set($redis_key,2);
                  $this->sendFeishuNotification($worker_name,true);
             }
        } catch (\Exception $error) {
                  echo $worker_name.': '. $error->getMessage(). PHP_EOL;
        }
    }
    function sendFeishuNotification($worker_name,$login_expired) {
        // $webhook = 'https://open.feishu.cn/open-apis/bot/v2/hook/96b517d5-2192-4da7-8bb2-47cccf6f04e2';
        $webhook='https://open.feishu.cn/open-apis/bot/v2/hook/f795eb02-a6c1-4532-8d71-23588ec10aa3';
        $text='';
        if($login_expired)$text= "GoldShell Token 过期";
        else $text= "Goldshell 告警：\nMiner: {$worker_name}\n已超过16分钟算力低于10";
        $message = [
            'msg_type' => 'text',
            'content' => [
    //                'text' => '测试消息'
                'text' =>$text
            ]
        ];
        $client = new Client();
        try {
            $response = $client->post($webhook, [
                'headers' => [
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode($message)
            ]);
            $result = json_decode($response->getBody(), true);
            echo '飞书通知发送结果: '. json_encode($result). PHP_EOL;
        } catch (\Exception $error) {
            echo '发送飞书通知失败: '. $error->getMessage(). PHP_EOL;
        }
    }
}