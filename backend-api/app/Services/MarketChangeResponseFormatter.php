<?php

namespace App\Services;

use App\Model\CurrencyQuotation;

class MarketChangeResponseFormatter
{
    private const PRICE_SIGNIFICANT_DIGITS = 15;

    /**
     * Preserve the legacy MarketChange JSON scalar types while serving data
     * calculated by Go. Percentages remain fixed-precision strings, prices
     * remain plain decimal strings, and period remains minutes rather than
     * seconds.
     */
    public function format(array $item)
    {
        // Re-check at response time because volume has a business freshness
        // window shorter than the physical lifetime of its Redis keys.
        $volume = (new MarketVolumeFreshness())->extreme($item);
        $windowSeconds = isset($item['window_seconds'])
            ? (int) $item['window_seconds']
            : ((int) $item['period'] * 60);

        return array_merge([
            'id' => (int) $item['id'],
            'match_id' => (int) $item['match_id'],
            'symbol' => $item['currency_name'].'/'.$item['quote_name'],
            'platform' => (int) $item['platform'],
            'period' => (int) $item['period'],
            'window_seconds' => $windowSeconds,
            'window_text' => $windowSeconds === 30 ? '30秒' : '5分钟',
            'direction' => (int) $item['direction'],
            'change' => number_format((float) $item['change'], 4, '.', ''),
            // Go calculates prices with float64 and emits fixed-18 strings.
            // Canonicalize those strings without a PHP float conversion so
            // binary tails disappear while tiny leading-zero prices survive.
            'price_begin' => $this->formatPrice($item['price_begin']),
            'price_end' => $this->formatPrice($item['price_end']),
            'created_at' => (string) $item['created_at'],
            'updated_at' => (string) $item['updated_at'],
            'currency_name' => (string) $item['currency_name'],
            'quote_name' => (string) $item['quote_name'],
            'platform_text' => CurrencyQuotation::$platform_text[(int) $item['platform']] ?? '--',
        ], $volume);
    }

    /**
     * Return a plain decimal with no scientific notation and no insignificant
     * trailing zeroes. float64 carries about 15 trustworthy decimal digits;
     * rounding beyond that boundary removes representation noise such as
     * 0.007813500000000001. All work stays on digit strings, so values such as
     * 0.00000000025 and 0.000000000000000001 never underflow to zero.
     */
    private function formatPrice($value)
    {
        $price = (string) $value;
        if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $price, $matches) !== 1) {
            return $price;
        }

        $integer = $matches[1];
        $fraction = isset($matches[2]) ? $matches[2] : '';
        $digits = $integer.$fraction;
        $decimalPosition = strlen($integer);
        $firstNonZero = strspn($digits, '0');

        if ($firstNonZero === strlen($digits)) {
            return '0';
        }

        $lastNonZero = strlen(rtrim($digits, '0')) - 1;
        $significantDigits = $lastNonZero - $firstNonZero + 1;
        if ($significantDigits > self::PRICE_SIGNIFICANT_DIGITS) {
            $keepIndex = $firstNonZero + self::PRICE_SIGNIFICANT_DIGITS - 1;
            $roundUp = ((int) $digits[$keepIndex + 1]) >= 5;

            for ($i = $keepIndex + 1, $length = strlen($digits); $i < $length; $i++) {
                $digits[$i] = '0';
            }

            if ($roundUp) {
                $carry = true;
                for ($i = $keepIndex; $i >= 0; $i--) {
                    if ($digits[$i] === '9') {
                        $digits[$i] = '0';
                        continue;
                    }
                    $digits[$i] = (string) (((int) $digits[$i]) + 1);
                    $carry = false;
                    break;
                }
                if ($carry) {
                    $digits = '1'.$digits;
                    $decimalPosition++;
                }
            }
        }

        $integer = ltrim(substr($digits, 0, $decimalPosition), '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim(substr($digits, $decimalPosition), '0');

        return $fraction === '' ? $integer : $integer.'.'.$fraction;
    }
}
