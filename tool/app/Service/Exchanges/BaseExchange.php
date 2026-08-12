<?php


namespace App\Service\Exchanges;


interface BaseExchange
{
    public function getDepth($currency_name,$quote_name,$limit = 10);
}
