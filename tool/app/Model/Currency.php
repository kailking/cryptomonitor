<?php


namespace App\Model;


use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    public $table = 'currency';
    const UPDATED_AT = null;

    public static function initCurrency($name){
        $currencyName = strtoupper($name);
        $currency = Currency::where('name',$currencyName)->first();
        if($currency){
            return $currency->id;
        }else{
            return Currency::insertGetId([
                'name' => $currencyName
            ]);
        }
    }
}
