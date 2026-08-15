<?php

namespace App\Service\MarketVolume\Providers;

use UnexpectedValueException;

class CoinexProvider extends AbstractMarketVolumeProvider
{
    private const ENDPOINT = 'https://api.coinex.com/v2/spot/ticker';

    public function platformId()
    {
        return 9;
    }

    public function providerName()
    {
        return 'coinex';
    }

    public function fetch()
    {
        return $this->parseResponse($this->getJson(self::ENDPOINT));
    }

    public function parseResponse(array $payload)
    {
        if ((string) ($payload['code'] ?? '') !== '0' || !isset($payload['data']) || !is_array($payload['data'])) {
            throw new UnexpectedValueException('CoinEx ticker response is invalid');
        }

        $volumes = [];
        foreach ($payload['data'] as $ticker) {
            if (!is_array($ticker)) {
                continue;
            }

            $symbol = $this->normalizeSymbol($ticker['market'] ?? null);
            $volume = $this->normalizeDecimal($ticker['value'] ?? null);
            if (!$this->isUsdtSymbol($symbol) || $volume === null) {
                continue;
            }

            $volumes[$symbol] = $volume;
        }

        return $volumes;
    }
}
