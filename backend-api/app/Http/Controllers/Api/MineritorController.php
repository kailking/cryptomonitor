<?php


namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Service\Exchanges\Miner\MinerMonitor;
use Illuminate\Http\Request;

class MineritorController extends Controller
{
     public function getHashrateChart(Request $request){
         $is_hashrate=$symbol=$request->get('is_hashrate');
         $symbol=$request->get('symbol');
        $address=$request->get('address');
          $interval=$request->get('interval');
        $data=MinerMonitor::getHashrateChart($symbol,$address,$interval,$is_hashrate);
         return successReturn($data);
    }
    public function getMinerList(Request $request){
       
        $symbol=$request->get('symbol');
        $address=$request->get('address');
        $status=$request->get('status');
        $page=$request->get('page');
        $limit=$request->get('limit');
        $data=MinerMonitor::getMinerList($symbol,$address,$page,$limit,$status);
        return successReturn($data);
    }
    public function getBalanceInfo(Request $request){
        $symbol=$request->get('symbol');
        $address=$request->get('address');
        $data=MinerMonitor::getBalanceInfo($symbol,$address);
        return successReturn($data);
    }
    public function getProfitList(Request $request){
        $symbol=$request->get('symbol');
        $address=$request->get('address');
        $page=$request->get('page');
        $limit=$request->get('limit');
        $data=MinerMonitor::getProfitList($symbol,$address,$page,$limit);
        return successReturn($data);
    }
}
