<?php

namespace App\Service\MarketVolume\Providers;

use UnexpectedValueException;

class OkxProvider extends AbstractMarketVolumeProvider
{
    private const ENDPOINT = 'https://www.okx.com/api/v5/market/tickers?instType=SPOT';

    public function platformId()
    {
        return 3;
    }

    public function providerName()
    {
        return 'okx';
    }

    public function fetch()
    {
        return $this->parseResponse($this->getJson(self::ENDPOINT));
    }

    public function parseResponse(array $payload)
    {
        if ((string) ($payload['code'] ?? '') !== '0' || !isset($payload['data']) || !is_array($payload['data'])) {
            throw new UnexpectedValueException('OKX ticker response is invalid');
        }

        $volumes = [];
        foreach ($payload['data'] as $ticker) {
            if (!is_array($ticker) || strtoupper((string) ($ticker['instType'] ?? 'SPOT')) !== 'SPOT') {
                continue;
            }

            $symbol = $this->normalizeSymbol($ticker['instId'] ?? null);
            $volume = $this->normalizeDecimal($ticker['volCcy24h'] ?? null);
            if (!$this->isUsdtSymbol($symbol) || $volume === null) {
                continue;
            }

            $volumes[$symbol] = $volume;
        }

        return $volumes;
    }
}
