<?php

namespace App\Service\MarketVolume\Providers;

use UnexpectedValueException;

class BinanceProvider extends AbstractMarketVolumeProvider
{
    private const ENDPOINT = 'https://api.binance.com/api/v3/ticker/24hr';

    public function platformId()
    {
        return 2;
    }

    public function providerName()
    {
        return 'binance';
    }

    public function fetch()
    {
        return $this->parseResponse($this->getJson(self::ENDPOINT));
    }

    public function parseResponse(array $payload)
    {
        if (!$this->isList($payload)) {
            throw new UnexpectedValueException('Binance ticker response is invalid');
        }

        $volumes = [];
        foreach ($payload as $ticker) {
            if (!is_array($ticker)) {
                continue;
            }

            $symbol = $this->normalizeSymbol($ticker['symbol'] ?? null);
            $volume = $this->normalizeDecimal($ticker['quoteVolume'] ?? null);
            if (!$this->isUsdtSymbol($symbol) || $volume === null) {
                continue;
            }

            $volumes[$symbol] = $volume;
        }

        return $volumes;
    }

    private function isList(array $payload)
    {
        return $payload === [] || array_keys($payload) === range(0, count($payload) - 1);
    }
}
