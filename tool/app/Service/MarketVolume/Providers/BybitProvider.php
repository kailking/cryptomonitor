<?php

namespace App\Service\MarketVolume\Providers;

use UnexpectedValueException;

class BybitProvider extends AbstractMarketVolumeProvider
{
    private const ENDPOINT = 'https://api.bybit.com/v5/market/tickers?category=spot';

    public function platformId()
    {
        return 16;
    }

    public function providerName()
    {
        return 'bybit';
    }

    public function fetch()
    {
        return $this->parseResponse($this->getJson(self::ENDPOINT));
    }

    public function parseResponse(array $payload)
    {
        $result = $payload['result'] ?? null;
        if ((int) ($payload['retCode'] ?? -1) !== 0 || !is_array($result) || !isset($result['list']) || !is_array($result['list'])) {
            throw new UnexpectedValueException('Bybit ticker response is invalid');
        }
        if (isset($result['category']) && strtolower((string) $result['category']) !== 'spot') {
            throw new UnexpectedValueException('Bybit ticker response is not spot');
        }

        $volumes = [];
        foreach ($result['list'] as $ticker) {
            if (!is_array($ticker)) {
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
