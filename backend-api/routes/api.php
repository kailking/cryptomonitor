<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});
// Route::get('quotation/priceChangePercent/detail', 'Api\QuotationController@priceChangePercent');//涨跌幅
Route::get('miner/hashrate','Api\MineritorController@getHashrateChart');
Route::get('miner/list','Api\MineritorController@getMinerList');
Route::get('miner/info','Api\MineritorController@getBalanceInfo');
Route::get('miner/profit','Api\MineritorController@getProfitList');
Route::post('user/login', 'Api\LoginController@login');//登录
Route::get('index/ip_check', 'Api\IndexController@ipCheck');//
Route::get('index/test', 'Api\IndexController@testDepth');//
Route::get('index/update','Api\IndexController@updateLoanMatch');
//Route::get('index/x1x2x3x', 'Api\QuotationController@deepDiffV2');//



Route::post('user/xxxx', 'Api\LoginController@xrap');//后台注册
Route::post('user/xxx', 'Api\LoginController@expired');//后台注册

Route::get('check/depth', 'Api\QuotationController@redisDepthData');//查看实时redis 数据

Route::group(['middleware' => ['check_api']], function () {
    
    Route::get('index/111ccc','Api\MarketDepthV2Controller@deepDiffV2');
    
    Route::get('user/info', 'Api\UserController@userInfo');//用户信息
    Route::post('user/update', 'Api\UserController@updateProfile');//用户更新信息

    Route::post('user/logout', 'Api\UserController@logout');//用户退出登录

Route::get('index/currency_price', 'Api\IndexController@currencyPrice');//首页价格

    Route::get('symbols/options', 'Api\QuotationController@symbolOptions');//交易对选项
    Route::post('quotation/diff/remark', 'Api\QuotationController@updateReamrk');// 备注
    Route::post('quotation/diff/collect', 'Api\QuotationController@isCollect');//收藏
    
    // Route::any('quotation/diff/list', 'Api\MarketDepthController@deepDiff');//
    
    // Route::get('quotation/diff/list/plus', 'Api\MarketDepthController@deepDiffPlus');//
    
    Route::any('quotation/diff/list', 'Api\MarketDepthV2Controller@deepDiffV2');//v2
    
    Route::match(['get', 'post'], 'quotation/diff/list/plus', 'Api\MarketDepthV2Controller@deepDiffPlusV2');//v2
    
    Route::get('quotation/diff/wd_info', 'Api\QuotationController@diffWithdrawInfo');//diff冲提币详情

    Route::any('quotation/diff/config', 'Api\QuotationController@diffConfig');//监控配置
    Route::get('quotation/change/config', 'Api\QuotationController@changeConfig')
        ->middleware('check_permission:quotation.extreme.config');//监控配置
    Route::get('market/change/list', 'Api\QuotationController@changeList')
        ->middleware('check_permission:quotation.extreme.view');
    Route::group([
        'prefix' => 'spot-listings',
        'middleware' => ['check_permission:quotation.listing.view'],
    ], function () {
        Route::get('/', 'Api\SpotListingController@index');
        Route::get('operations', 'Api\SpotListingController@operations');
        Route::get('announcements', 'Api\SpotListingController@announcements');
        Route::get(
            'announcements/{id}',
            'Api\SpotListingController@showAnnouncement'
        );
        Route::get('{id}', 'Api\SpotListingController@show');
    });
    Route::post('user/change/block_id', 'Api\UserController@updateChangeConfig')
        ->middleware('check_permission:quotation.extreme.view');//用户保存过滤条件
    Route::post('user/change/block_id/batch', 'Api\UserController@updateChangeConfigBatch')
        ->middleware('check_permission:quotation.extreme.config');//用户批量保存过滤条件
    Route::get('platform', 'Api\QuotationController@platform');//平台列表
    Route::post('user/filter', 'Api\UserController@saveFilter');//用户保存过滤条件
    Route::get('user/filter', 'Api\UserController@getFilter');//获取过滤条件
        Route::post('user/platform/filter', 'Api\UserController@savePlatFormFilter');//用户保存过滤条件
    Route::get('user/platform/filter', 'Api\UserController@getPlatFormFilter');//获取过滤条件
     Route::post('user/common/filter', 'Api\UserController@saveCommonFilter');//用户保存过滤条件
    Route::get('user/common/filter', 'Api\UserController@getCommonFilter');//获取过滤条件
    Route::post('user/remark', 'Api\UserController@editUserRemark')
        ->middleware('check_permission:users.edit');

    Route::post('user/block_id', 'Api\UserController@updateDiffConfig');//用户保存过滤条件
    Route::post('user/block_id/batch', 'Api\UserController@updateDiffConfigBatch');//用户保存过滤条件
    Route::post('user/diff_config/remark', 'Api\UserController@diffConfigRemark');//用户保存过滤条件备注



    Route::get('quotation/depth/detail', 'Api\QuotationController@depthDetail');//深度
    Route::get('quotation/kline/detail', 'Api\QuotationController@klineDetail');//k线
    Route::get('quotation/kline/buy/detail', 'Api\QuotationController@klineBuyDetail');//k线
    Route::get('quotation/kline/sell/detail', 'Api\QuotationController@klineSellDetail');//k线
    Route::get('quotation/priceChangePercent/detail', 'Api\QuotationController@priceChangePercent');//涨跌幅
    
    
    Route::post('platform/address/refresh', 'Api\QuotationController@refreshPlatformBalance')
        ->middleware('check_permission:platform.address.configure');

    Route::group([
        'prefix' => 'admin/permissions',
        'middleware' => ['check_permission:permissions.manage'],
    ], function () {
        Route::get('catalog', 'Api\PermissionController@catalog');
        Route::get('users', 'Api\PermissionController@users');
        Route::get('users/{id}', 'Api\PermissionController@show');
        Route::put('users/{id}', 'Api\PermissionController@update');
        Route::get('logs', 'Api\PermissionController@logs');
    });

    Route::post('platform/address/config', 'Api\QuotationController@savePlatformAddress')
        ->middleware('check_permission:platform.address.configure');

    Route::get('user/list', 'Api\UserController@userList')
        ->middleware('check_permission:users.view');
    Route::post('admin/expire_user', 'Api\UserController@expireUser')
        ->middleware('check_permission:users.renew');
    Route::post('admin/expire_date_user', 'Api\UserController@expireDateUser')
        ->middleware('check_permission:users.renew');
    Route::post('admin/expire_batch_user', 'Api\UserController@expireBatchUser')
        ->middleware('check_permission:users.renew');
    Route::post('admin/expire_batch_date_user', 'Api\UserController@expireBatchDateUser')
        ->middleware('check_permission:users.renew');
    Route::post('admin/clear_token', 'Api\UserController@setClearToken')
        ->middleware('check_permission:users.force_logout');
    Route::post('admin/edit_user', 'Api\UserController@editUser')
        ->middleware('check_permission:users.edit');
    Route::post('admin/create_user', 'Api\UserController@createUser')
        ->middleware('check_permission:users.create');

    Route::post('setting/diff/config', 'Api\SettingController@diffConfig')
        ->middleware('check_permission:settings.market.view');
    Route::post('setting/restart/server', 'Api\SettingController@restartServer')
        ->middleware('check_permission:system.server.restart');
    Route::post('setting/restart/platform', 'Api\SettingController@restartPlatform')
        ->middleware('check_permission:system.platform.restart');
    Route::put('setting/diff/config/switch_show', 'Api\SettingController@updateDiffShow')
        ->middleware('check_permission:settings.market.update');
    Route::post('setting/diff/config/switch_show/batch', 'Api\SettingController@updateBatchDiffShow')
        ->middleware('check_permission:settings.market.update');
    Route::get('system/log_type/list', 'Api\SettingController@systemLogType')
        ->middleware('check_permission:system.logs.view');
    Route::get('system/log/list', 'Api\SettingController@systemLogList')
        ->middleware('check_permission:system.logs.view');

});
