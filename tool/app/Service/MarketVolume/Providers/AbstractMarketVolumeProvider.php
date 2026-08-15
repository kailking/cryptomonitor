<?php

namespace App\Service\MarketVolume\Providers;

use App\Service\MarketVolume\Contracts\MarketVolumeHttpClientInterface;
use App\Service\MarketVolume\Contracts\MarketVolumeProviderInterface;

abstract class AbstractMarketVolumeProvider implements MarketVolumeProviderInterface
{
    /** @var MarketVolumeHttpClientInterface */
    protected $http;

    public function __construct(MarketVolumeHttpClientInterface $http)
    {
        $this->http = $http;
    }

    protected function getJson($url, array $options = [])
    {
        return $this->http->getJson($url, $options);
    }

    /**
     * Normalize common exchange symbol separators.
     *
     * @param mixed $symbol
     * @return string
     */
    protected function normalizeSymbol($symbol)
    {
        if (!is_string($symbol) && !is_numeric($symbol)) {
            return '';
        }

        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $symbol)));
    }

    /**
     * @param mixed $symbol
     * @return bool
     */
    protected function isUsdtSymbol($symbol)
    {
        $symbol = $this->normalizeSymbol($symbol);

        return strlen($symbol) > 4 && substr($symbol, -4) === 'USDT';
    }

    /**
     * Convert a non-negative ordinary decimal to a canonical string.
     * Scientific notation is deliberately rejected because exchange-specific
     * scaling must be handled explicitly by the provider.
     *
     * @param mixed $value
     * @return string|null
     */
    protected function normalizeDecimal($value)
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            if (is_nan($value) || is_infinite($value) || $value < 0) {
                return null;
            }
            // Casting uses PHP's shortest configured decimal representation
            // and avoids exposing binary floating-point tails such as
            // 12345.67000000000007. Expand only exponent notation produced by
            // the JSON decoder; exponent strings supplied by an API remain
            // invalid and must be handled explicitly by that provider.
            $value = $this->expandFloat((string) $value);
        } elseif (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (!preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            return null;
        }

        list($integer, $fraction) = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? $integer : $integer.'.'.$fraction;
    }

    /**
     * @param string $value
     * @return string
     */
    private function expandFloat($value)
    {
        if (stripos($value, 'e') === false) {
            return $value;
        }

        if (!preg_match('/^(\d+)(?:\.(\d+))?[eE]([+-]?\d+)$/', $value, $matches)) {
            return $value;
        }

        $integer = $matches[1];
        $fraction = isset($matches[2]) ? $matches[2] : '';
        $exponent = (int) $matches[3];
        $digits = $integer.$fraction;
        $decimalPosition = strlen($integer) + $exponent;

        if ($decimalPosition <= 0) {
            return '0.'.str_repeat('0', -$decimalPosition).$digits;
        }
        if ($decimalPosition >= strlen($digits)) {
            return $digits.str_repeat('0', $decimalPosition - strlen($digits));
        }

        return substr($digits, 0, $decimalPosition).'.'.substr($digits, $decimalPosition);
    }
}
