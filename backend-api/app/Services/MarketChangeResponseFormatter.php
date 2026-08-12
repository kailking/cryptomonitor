<?php

namespace App\Services;

use App\Model\CurrencyQuotation;

class MarketChangeResponseFormatter
{
    /**
     * Preserve the legacy MarketChange JSON scalar types while serving data
     * calculated by Go. In particular, percentages and prices remain fixed-
     * precision strings and period remains minutes rather than seconds.
     */
    public function format(array $item)
    {
        return [
            'id' => (int) $item['id'],
            'match_id' => (int) $item['match_id'],
            'symbol' => $item['currency_name'].'/'.$item['quote_name'],
            'platform' => (int) $item['platform'],
            'period' => (int) $item['period'],
            'direction' => (int) $item['direction'],
            'change' => number_format((float) $item['change'], 4, '.', ''),
            // Go emits canonical fixed-18 strings. Direct passthrough avoids
            // losing tiny-price precision through a PHP float conversion.
            'price_begin' => (string) $item['price_begin'],
            'price_end' => (string) $item['price_end'],
            'created_at' => (string) $item['created_at'],
            'updated_at' => (string) $item['updated_at'],
            'currency_name' => (string) $item['currency_name'],
            'quote_name' => (string) $item['quote_name'],
            'platform_text' => CurrencyQuotation::$platform_text[(int) $item['platform']] ?? '--',
        ];
    }
}
