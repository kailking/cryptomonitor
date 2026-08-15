<?php

namespace App\Service\MarketVolume\Providers;

use UnexpectedValueException;

class PionexProvider extends AbstractMarketVolumeProvider
{
    private const ENDPOINT = 'https://api.pionex.com/api/v1/market/tickers?type=SPOT';

    public function platformId()
    {
        return 23;
    }

    public function providerName()
    {
        return 'pionex';
    }

    public function fetch()
    {
        return $this->parseResponse($this->getJson(self::ENDPOINT));
    }

    public function parseResponse(array $payload)
    {
        $tickers = $payload['data']['tickers'] ?? null;
        if (($payload['result'] ?? null) !== true || !is_array($tickers)) {
            throw new UnexpectedValueException('Pionex ticker response is invalid');
        }

        $volumes = [];
        foreach ($tickers as $ticker) {
            if (!is_array($ticker)) {
                continue;
            }

            $symbol = $this->normalizeSymbol($ticker['symbol'] ?? null);
            $volume = $this->normalizeDecimal($ticker['amount'] ?? null);
            if (!$this->isUsdtSymbol($symbol) || $volume === null) {
                continue;
            }

            $volumes[$symbol] = $volume;
        }

        return $volumes;
    }
}
