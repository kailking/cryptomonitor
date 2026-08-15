<?php

namespace App\Service\MarketVolume\Providers;

use UnexpectedValueException;

class PhemexProvider extends AbstractMarketVolumeProvider
{
    private const ENDPOINT = 'https://api.phemex.com/md/spot/ticker/24hr/all';
    private const USDT_VALUE_SCALE = 8;

    public function platformId()
    {
        return 22;
    }

    public function providerName()
    {
        return 'phemex';
    }

    public function fetch()
    {
        return $this->parseResponse($this->getJson(self::ENDPOINT));
    }

    public function parseResponse(array $payload)
    {
        if (!array_key_exists('error', $payload) || $payload['error'] !== null || !isset($payload['result']) || !$this->isList($payload['result'])) {
            throw new UnexpectedValueException('Phemex spot ticker response is invalid');
        }

        $volumes = [];
        foreach ($payload['result'] as $ticker) {
            if (!is_array($ticker)) {
                continue;
            }

            $nativeSymbol = $ticker['symbol'] ?? null;
            if (!is_string($nativeSymbol) || substr($nativeSymbol, 0, 1) !== 's') {
                continue;
            }

            $symbol = $this->normalizeSymbol(substr($nativeSymbol, 1));
            $volume = $this->scaleIntegerDecimal($ticker['turnoverEv'] ?? null, self::USDT_VALUE_SCALE);
            if (!$this->isUsdtSymbol($symbol) || $volume === null) {
                continue;
            }

            $volumes[$symbol] = $volume;
        }

        return $volumes;
    }

    private function scaleIntegerDecimal($value, $scale)
    {
        if (is_int($value)) {
            $digits = (string) $value;
        } elseif (is_string($value)) {
            $digits = trim($value);
        } else {
            return null;
        }

        if (!preg_match('/^\d+$/', $digits)) {
            return null;
        }

        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return $this->normalizeDecimal('0');
        }

        if (strlen($digits) <= $scale) {
            $decimal = '0.' . str_repeat('0', $scale - strlen($digits)) . $digits;
        } else {
            $decimal = substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
        }

        $decimal = rtrim(rtrim($decimal, '0'), '.');

        return $this->normalizeDecimal($decimal);
    }

    private function isList(array $payload)
    {
        return $payload === [] || array_keys($payload) === range(0, count($payload) - 1);
    }
}
