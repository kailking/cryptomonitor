<?php


namespace App\Model;


use Illuminate\Database\Eloquent\Model;

class CurrencyQuotation extends Model
{
    public $table = 'currency_quotation';
    const UPDATED_AT = null;

    const PLATFORM_HUOBI = 1;
    const PLATFORM_BIANCE = 2;
    const PLATFORM_OKEX = 3;
    const PLATFORM_GATE = 4;
    const PLATFORM_MEXC = 5;
    const PLATFORM_AEX = 6;
    const PLATFORM_POLONIEX = 7;
    const PLATFORM_KUCOIN = 8;
    const PLATFORM_COINEX = 9;
    const PLATFORM_LBANK = 10;
    const PLATFORM_HOTCOIN = 11;
    const PLATFORM_FTX = 12;
    const PLATFORM_DF = 13;

    const PLATFORM_BIGONE = 14;
    const PLATFORM_BITGET = 15;
    const PLATFORM_BYBIT = 16;
    const PLATFORM_BITMART= 17;
    const PLATFORM_NONKYC = 18;
    const PLATFORM_WEEX = 19;
    const PLATFORM_COINW = 20;
    const PLATFORM_XT = 21;
    const PLATFORM_PHEMEX = 22;
    const PLATFORM_PIONEX = 23;

       public static $platform_text = [
            1 => '火币',
            2 => '币安',
            3 => 'Okex',
            4 => 'Gate',
            5 => 'Mexc',
            // 6 => 'Aex' ,
            // 7 => 'Poloniex',
            8 => 'Kucoin',
            9 => 'CoinEx',
            10 => 'Lbank',
            // 11 => 'Hotcoin',
            // 12 => 'Ftx',
            // 13 => 'AscendEX',
            // 14 => '币格',
            15 => 'BitGet',
            16 => 'ByBit',
            // 17 => '币市',
            // 18 => 'NonKYC',
            19 => 'Weex',
            // 20 => '币赢',
            21 => 'XT',
            22 => 'Phemex',
            23 => '派网'
        ];



}
