<?php


namespace App\Service\Exchanges;


interface BaseExchange
{
    const inverval = '15m';
    const size = 100;
    public function getDepth($currency_name,$quote_name,$limit = 10);

    public function getKline($currency_name,$quote_name);
}
