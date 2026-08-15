<?php

namespace App\Service\MarketVolume\Providers;

use UnexpectedValueException;

class WeexProvider extends AbstractMarketVolumeProvider
{
    private const ENDPOINT = 'https://api-spot.weex.com/api/v3/market/ticker/24hr';

    public function platformId()
    {
        return 19;
    }

    public function providerName()
    {
        return 'weex';
    }

    public function fetch()
    {
        return $this->parseResponse($this->getJson(self::ENDPOINT));
    }

    public function parseResponse(array $payload)
    {
        if (!$this->isList($payload)) {
            throw new UnexpectedValueException('WEEX ticker response is invalid');
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
