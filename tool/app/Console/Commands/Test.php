<?php


namespace App\Console\Commands;


use App\Model\MarketDepth;
use App\Model\MarketDepthDiff;
use App\Model\CurrencyMatch;
use App\Model\CurrencyQuotation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Service\TokenBalanceService;

class Test extends Command
{
    protected $signature = 'start_test';
    protected $description = '测试任务';


    public function handle()
    {
        
        
        // exit;
        //下架交易所
        CurrencyMatch::where('is_nonkyc',1)->update(['is_nonkyc'=> 0]);
        $key = array_keys(CurrencyQuotation::$platform_text);
        var_dump($key);
        $ex = MarketDepthDiff::whereNotIn('buy_platform',$key)->orWhereNotIn('sell_platform',$key)->delete();
        $res = MarketDepth::whereNotIn('platform',$key)->delete();
        var_dump($res);
        exit;
        $service = new TokenBalanceService();
        //eth
        $address = '0x1AB4973a48dc892Cd9971ECE8e01DcC7688f8F23';
        $contract = '0x17205fab260a7a6383a81452cE6315A39370Db97';
        
        // $res = $service->getEthBalance($address,$contract);
        
        //bsc 
        $bsc_address = '0x8894E0a0c962CB723c1976a4421c95949bE2D4E3';
        $bsc_contract = '0x4d5AC5cc4f8aBdf2EC2Cb986C00C382369f787D4';
        // $res = $service->getBscBalance($bsc_address,$bsc_contract);
        
        
        $sol_address= 'u6PJ8DtQuPFnfmwHbGFULQ4u4EgjDiyYKjVEsynXq2w';
        
        $sol_contract = 'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB';
        $res = $service->getSolBalance($sol_address,$sol_contract);
        var_dump($res);
        exit;
        
       $ids = DB::table('user_diff_filter')->where('user_id', 3)->pluck('diff_id')->toArray();
    //   var_dump($ids);exit;
       $buy = MarketDepthDiff::whereIn('id',$ids)->update(['is_show' => 0]);
    //   var_dump($buy);
    }
}
