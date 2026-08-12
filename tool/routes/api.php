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
Route::post('user/login', 'Api\LoginController@login');//登录
Route::get('index/ip_check', 'Api\IndexController@ipCheck');//


Route::post('user/xxxx', 'Api\LoginController@xrap');//后台注册

Route::group(['middleware' => ['check_api']], function () {
    Route::get('user/info', 'Api\UserController@userInfo');//用户信息
    Route::post('user/update', 'Api\UserController@updateProfile');//用户更新信息

    Route::post('user/logout', 'Api\UserController@logout');//用户退出登录

    Route::get('index/currency_price', 'Api\IndexController@currencyPrice');//首页价格

    Route::get('symbols/options', 'Api\QuotationController@symbolOptions');//交易对选项

    Route::get('quotation/diff/list', 'Api\QuotationController@deepDiff');//用户信息
    Route::get('platform', 'Api\QuotationController@platform');//平台列表
    Route::post('user/filter', 'Api\UserController@saveFilter');//用户保存过滤条件
    Route::get('user/filter', 'Api\UserController@getFilter');//获取过滤条件


});

