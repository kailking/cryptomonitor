<?php


namespace App\Model;


use Illuminate\Database\Eloquent\Model;

class MarketChange extends Model
{
    public $table = 'market_change';

    public function getPlatformTextAttribute(){
        return  CurrencyQuotation::$platform_text[$this->attributes['platform']];
    }
}
