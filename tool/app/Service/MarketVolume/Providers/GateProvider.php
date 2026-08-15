<?php

namespace App\Service\MarketVolume\Providers;

use UnexpectedValueException;

class GateProvider extends AbstractMarketVolumeProvider
{
    private const ENDPOINT = 'https://api.gateio.ws/api/v4/spot/tickers';

    public function platformId()
    {
        return 4;
    }

    public function providerName()
    {
        return 'gate';
    }

    public function fetch()
    {
        return $this->parseResponse($this->getJson(self::ENDPOINT));
    }

    public function parseResponse(array $payload)
    {
        if (!$this->isList($payload)) {
            throw new UnexpectedValueException('Gate ticker response is invalid');
        }

        $volumes = [];
        foreach ($payload as $ticker) {
            if (!is_array($ticker)) {
                continue;
            }

            $symbol = $this->normalizeSymbol($ticker['currency_pair'] ?? null);
            $volume = $this->normalizeDecimal($ticker['quote_volume'] ?? null);
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
