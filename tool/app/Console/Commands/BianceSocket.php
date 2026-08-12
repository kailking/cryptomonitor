<?php


namespace App\Console\Commands;


use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use Illuminate\Console\Command;
use Lin\Binance\BinanceWebSocket;

use App\Model\MarketDepth;
use Illuminate\Support\Facades\DB;

class BianceSocket extends Command
{
    protected $signature = 'check_biance_update';
    protected $description = '监听币安价格更新';


    public function handle()
    {
        $checkList = MarketDepth::where('symbol','BTCUSDT')
            ->where('type',2)
            ->where('index',1)
        ->where('updated_at','<',date('Y-m-d H:i:s',strtotime('-1 min')))->get();

        if($checkList){
            foreach($checkList as $check){
                echo sprintf('%s交易对%s 最后更新时间%s 已超过一分钟未更新',CurrencyQuotation::$platform_text[$check->platform],$check->symbol,$check->updated_at).PHP_EOL;
                DB::table('manual_log')->insert([
                    'content' => sprintf('%s交易对%s 最后更新时间%s 已超过一分钟未更新',CurrencyQuotation::$platform_text[$check->platform],$check->symbol,$check->updated_at),
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }

        }else{
            echo '服务器正常'.PHP_EOL;
        }
    }
}
