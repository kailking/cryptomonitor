<?php

namespace App\Console\Commands\miner;

use App\Service\RedisService;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class MinerMonitor extends Command
{
    protected $signature = 'miner_monitor';
    protected $description = '查询矿机状态';


    public function handle()
    {
        // $feishu_url = 'https://open.feishu.cn/open-apis/bot/v2/hook/96b517d5-2192-4da7-8bb2-47cccf6f04e2';//monitor
        $feishu_url="https://open.feishu.cn/open-apis/bot/v2/hook/1b05f98f-ecaa-42eb-8a0f-9332f5895841";//青龙
//        $info = sprintf('矿工告警：\n矿工ID: ${worker.worker_name}\n当前状态: ${formatStatus(worker.status)}\n已超过10分钟未活跃');
        $info = '测试消息';
        $url='https://www.dxpool.com/api/address-mining/aleo/miner/aleo1k3kz6mfvdh4dsju03x7tqj7admgu5a9tav70vz0rmf0j5mvpayqqm7dw7s/workers?order=&page_size=100&offset=0';
        $cli= new Client();
        $content=$cli->get($url)->getBody()->getContents();
        //        var_dump($content);exit;
        $content = json_decode($content, true);
        $redis = RedisService::getInstance();

        if(array_key_exists('items',$content)){
            foreach($content['items'] as $item){
                $workerid = $item['worker_id'];
                $key = sprintf('miner_worker_'.$workerid);
                $res = $redis->get($key);
                if(!$res){
                    $warning_count = 0;
                    $is_fail = $this->is_minor_fail($item['accept_15m']['value'],$item['status']);
                    if($is_fail){
                        $warning_count += 1;
                        echo '矿机异常: '. $workerid. PHP_EOL;
                    }
                    $data = [
                        'worker_id' => $workerid,
                        'accept_15m' => $item['accept_15m']['value'],
                        'status' => $item['status'],
                        'warning_count' => $warning_count,
                    ];
                }else{
                    $data = json_decode($res,true);
                    $warning_count = $data['warning_count'];
                    $is_fail = $this->is_minor_fail($item['accept_15m']['value'],$item['status']);
                    if($is_fail){
                        $warning_count += 1;
                        echo '矿机异常: '. $workerid. PHP_EOL;
                    }else{
                        $warning_count = 0;
                    }
                    $data = [
                        'worker_id' => $workerid,
                        'accept_15m' => $item['accept_15m']['value'],
                        'status' => $item['status'],
                        'warning_count' => $warning_count,
                    ];

                }
                echo json_encode($data).PHP_EOL;
                $redis->set($key,json_encode($data));
                if ($warning_count != 0 && $warning_count % 35 === 0) {
                    //发送飞书
                    $this->sendFeishuNotification($workerid,$item['status']);
                }
            }
        }
        //accept_15m 小于10  status 《》 1
    }
function is_minor_fail($accept_value,$status){
    if($accept_value<10 || $status != 1){
        return true;
    }else{
        return false;
    }
}

function sendFeishuNotification($worker_name,$worker_status) {
    // $webhook = 'https://open.feishu.cn/open-apis/bot/v2/hook/96b517d5-2192-4da7-8bb2-47cccf6f04e2';
    $webhook='https://open.feishu.cn/open-apis/bot/v2/hook/1b05f98f-ecaa-42eb-8a0f-9332f5895841';
    $text='';
    if($worker_status==1)$text= "矿工提醒：\n矿工ID: {$worker_name}\n当前状态: ". $worker_status . "\n已超过35分钟算力低于10";
    else $text= "矿工告警：\n矿工ID: {$worker_name}\n当前状态: ". $worker_status . "\n已超过35分钟未活跃";
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
