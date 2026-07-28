<?php


namespace App\Model;


use Illuminate\Database\Eloquent\Model;

class CurrencyQuotationDiff extends Model
{
    public $table = 'currency_quotation_diff';

    public function getPlatformBuyAttribute(){
        if($this->attributes['first_quotation_price'] < $this->attributes['second_quotation_price']){
            return CurrencyQuotation::$platform_text[$this['first_quotation_platform']];
        }else{
            return CurrencyQuotation::$platform_text[$this['second_quotation_platform']];
        }
    }
    public function getPlatformSellAttribute(){
        if($this->attributes['first_quotation_price'] < $this->attributes['second_quotation_price']){
            return CurrencyQuotation::$platform_text[$this['second_quotation_platform']];
        }else{
            return CurrencyQuotation::$platform_text[$this['first_quotation_platform']];
        }
    }
    public function getPriceSellAttribute(){
        if($this->attributes['first_quotation_price'] < $this->attributes['second_quotation_price']){
            return $this->attributes['second_quotation_price'];
        }else{
            return $this->attributes['first_quotation_price'];
        }
    }
    public function getPriceBuyAttribute(){
        if($this->attributes['first_quotation_price'] < $this->attributes['second_quotation_price']){
            return $this->attributes['first_quotation_price'];
        }else{
            return $this->attributes['second_quotation_price'];
        }
    }



    public static function updateQuotationPrice($symbol,$platform,$price)
    {
        $quotation = CurrencyQuotation::where('symbol',$symbol)->where('platform',$platform)->first();
        if(!$quotation){
            return false;
        }
        if($price<=0){
            return false;
        }
        $quotationList = CurrencyQuotation::where('symbol',$symbol)->where('platform','<>',$platform)->where('now_price','>',0)->get();
        if(!$quotationList){
            return false;
        }
        foreach($quotationList as $qo){
            if($qo->platform < $quotation->platform){
                $first_id = $qo->id;
                $first_platform = $qo->platform;
                $first_price = $qo->now_price;
                $second_id = $quotation->id;
                $second_platform = $quotation->platform;
                $second_price = $price;
            }else{
                $first_id = $quotation->id;
                $first_platform = $quotation->platform;
                $first_price = $price;
                $second_id = $qo->id;
                $second_platform = $qo->platform;
                $second_price = $qo->now_price;
            }
            if(bc_sub($first_price,$second_price)>0){
                $diff = bc_div(bc_sub($first_price,$second_price),$second_price);
            }else{
                $diff = bc_div(bc_sub($second_price,$first_price),$first_price);
            }
            $check = CurrencyQuotationDiff::where('first_quotation_id',$first_id)->where('second_quotation_id',$second_id)->first();
            if($check){
                CurrencyQuotationDiff::where('id',$check->id)->update([
                    'price_diff' => $diff*100,
                    'first_quotation_price' => $first_price,
                    'second_quotation_price' => $second_price,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }else{
                CurrencyQuotationDiff::insert([
                    'match_id' => $quotation->match_id,
                    'symbol' => $quotation->symbol,
                    'currency_name' => $quotation->currency_name,
                    'quote_name' => $quotation->quote_name,
                    'first_quotation_id' => $first_id,
                    'second_quotation_id' => $second_id,
                    'first_quotation_platform' => $first_platform,
                    'second_quotation_platform' => $second_platform,
                    'first_quotation_price' => $first_price,
                    'second_quotation_price' => $second_price,
                    'price_diff' => $diff*100,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
        }
    }
}
