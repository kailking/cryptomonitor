<?php

namespace App\Service\MarketVolume\Providers;

use UnexpectedValueException;

class HtxProvider extends AbstractMarketVolumeProvider
{
    private const ENDPOINT = 'https://api.huobi.pro/market/tickers';

    public function platformId()
    {
        return 1;
    }

    public function providerName()
    {
        return 'htx';
    }

    public function fetch()
    {
        return $this->parseResponse($this->getJson(self::ENDPOINT));
    }

    public function parseResponse(array $payload)
    {
        if (($payload['status'] ?? null) !== 'ok' || !isset($payload['data']) || !is_array($payload['data'])) {
            throw new UnexpectedValueException('HTX ticker response is invalid');
        }

        $volumes = [];
        foreach ($payload['data'] as $ticker) {
            if (!is_array($ticker)) {
                continue;
            }

            $symbol = $this->normalizeSymbol($ticker['symbol'] ?? null);
            $volume = $this->normalizeDecimal($ticker['vol'] ?? null);
            if (!$this->isUsdtSymbol($symbol) || $volume === null) {
                continue;
            }

            $volumes[$symbol] = $volume;
        }

        return $volumes;
    }
}
