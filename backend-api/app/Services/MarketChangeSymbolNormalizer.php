<?php

namespace App\Services;

class MarketChangeSymbolNormalizer
{
    /**
     * Uppercase market symbols without ever applying byte-oriented strtoupper
     * to UTF-8 input. The fallback changes ASCII letters only and therefore
     * preserves symbols such as "老子USDT" when mbstring is unavailable.
     */
    public static function upper($value)
    {
        $value = (string) $value;
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($value, 'UTF-8');
        }

        return strtr($value, array_combine(
            range('a', 'z'),
            range('A', 'Z')
        ));
    }

    public static function contains($haystack, $needle)
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        if ($needle === '') {
            return true;
        }
        if (function_exists('mb_stripos')) {
            return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
        }

        return strpos(self::upper($haystack), self::upper($needle)) !== false;
    }
}
