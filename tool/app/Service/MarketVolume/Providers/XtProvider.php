<?php

namespace App\Service\MarketVolume\Providers;

use UnexpectedValueException;

class XtProvider extends AbstractMarketVolumeProvider
{
    private const ENDPOINT = 'https://sapi.x.group/v4/public/ticker/24h?tags=spot';
    private const COMPATIBILITY_ENDPOINT = 'https://sapi.xt.com/v4/public/ticker/24h?tags=spot';

    public function platformId()
    {
        return 21;
    }

    public function providerName()
    {
        return 'xt';
    }

    public function fetch()
    {
        try {
            $payload = $this->getJson(self::ENDPOINT);
        } catch (\Exception $exception) {
            // XT moved its documentation and primary API host to x.group, but
            // the legacy official host is still active in regions where the
            // new hostname cannot complete TLS. Only transport failures fall
            // back; a reachable but invalid response remains fail-closed.
            $payload = $this->getJson(self::COMPATIBILITY_ENDPOINT);
        }

        return $this->parseResponse($payload);
    }

    public function parseResponse(array $payload)
    {
        if ((string) ($payload['rc'] ?? '') !== '0' || !isset($payload['result']) || !is_array($payload['result'])) {
            throw new UnexpectedValueException('XT ticker response is invalid');
        }

        $volumes = [];
        foreach ($payload['result'] as $ticker) {
            if (!is_array($ticker)) {
                continue;
            }

            $symbol = $this->normalizeSymbol($ticker['s'] ?? null);
            $volume = $this->normalizeDecimal($ticker['v'] ?? null);
            if (!$this->isUsdtSymbol($symbol) || $volume === null) {
                continue;
            }

            $volumes[$symbol] = $volume;
        }

        return $volumes;
    }
}
