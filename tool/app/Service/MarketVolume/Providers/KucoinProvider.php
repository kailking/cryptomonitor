<?php

namespace App\Service\MarketVolume\Providers;

use UnexpectedValueException;

class KucoinProvider extends AbstractMarketVolumeProvider
{
    private const ENDPOINT = 'https://api.kucoin.com/api/v1/market/allTickers';

    public function platformId()
    {
        return 8;
    }

    public function providerName()
    {
        return 'kucoin';
    }

    public function fetch()
    {
        return $this->parseResponse($this->getJson(self::ENDPOINT));
    }

    public function parseResponse(array $payload)
    {
        $tickers = $payload['data']['ticker'] ?? null;
        if ((string) ($payload['code'] ?? '') !== '200000' || !is_array($tickers)) {
            throw new UnexpectedValueException('KuCoin ticker response is invalid');
        }

        $volumes = [];
        foreach ($tickers as $ticker) {
            if (!is_array($ticker)) {
                continue;
            }

            $symbol = $this->normalizeSymbol($ticker['symbol'] ?? null);
            $volume = $this->normalizeDecimal($ticker['volValue'] ?? null);
            if (!$this->isUsdtSymbol($symbol) || $volume === null) {
                continue;
            }

            $volumes[$symbol] = $volume;
        }

        return $volumes;
    }
}
