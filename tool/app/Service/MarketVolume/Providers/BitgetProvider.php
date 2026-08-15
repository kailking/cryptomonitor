<?php

namespace App\Service\MarketVolume\Providers;

use UnexpectedValueException;

class BitgetProvider extends AbstractMarketVolumeProvider
{
    private const ENDPOINT = 'https://api.bitget.com/api/v3/market/tickers?category=SPOT';

    public function platformId()
    {
        return 15;
    }

    public function providerName()
    {
        return 'bitget';
    }

    public function fetch()
    {
        return $this->parseResponse($this->getJson(self::ENDPOINT));
    }

    public function parseResponse(array $payload)
    {
        if ((string) ($payload['code'] ?? '') !== '00000' || !isset($payload['data']) || !is_array($payload['data'])) {
            throw new UnexpectedValueException('Bitget ticker response is invalid');
        }

        $volumes = [];
        foreach ($payload['data'] as $ticker) {
            if (!is_array($ticker) || strtoupper((string) ($ticker['category'] ?? 'SPOT')) !== 'SPOT') {
                continue;
            }

            $symbol = $this->normalizeSymbol($ticker['symbol'] ?? null);
            $volume = $this->normalizeDecimal($ticker['turnover24h'] ?? null);
            if (!$this->isUsdtSymbol($symbol) || $volume === null) {
                continue;
            }

            $volumes[$symbol] = $volume;
        }

        return $volumes;
    }
}
