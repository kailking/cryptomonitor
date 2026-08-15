<?php

namespace App\Service\MarketVolume\Providers;

use UnexpectedValueException;

class LbankProvider extends AbstractMarketVolumeProvider
{
    private const ENDPOINT = 'https://api.lbkex.com/v2/ticker/24hr.do?symbol=all';

    public function platformId()
    {
        return 10;
    }

    public function providerName()
    {
        return 'lbank';
    }

    public function fetch()
    {
        return $this->parseResponse($this->getJson(self::ENDPOINT));
    }

    public function parseResponse(array $payload)
    {
        $success = ($payload['result'] ?? null) === true || ($payload['result'] ?? null) === 'true';
        if (!$success || (string) ($payload['error_code'] ?? '') !== '0' || !isset($payload['data']) || !is_array($payload['data'])) {
            throw new UnexpectedValueException('LBank ticker response is invalid');
        }

        $volumes = [];
        foreach ($payload['data'] as $item) {
            if (!is_array($item) || !isset($item['ticker']) || !is_array($item['ticker'])) {
                continue;
            }

            $symbol = $this->normalizeSymbol($item['symbol'] ?? null);
            $volume = $this->normalizeDecimal($item['ticker']['turnover'] ?? null);
            if (!$this->isUsdtSymbol($symbol) || $volume === null) {
                continue;
            }

            $volumes[$symbol] = $volume;
        }

        return $volumes;
    }
}
