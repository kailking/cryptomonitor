<?php

namespace App\Service\MarketVolume\Contracts;

interface MarketVolumeProviderInterface
{
    /**
     * @return int
     */
    public function platformId();

    /**
     * Stable provider name used in metadata and logs.
     *
     * @return string
     */
    public function providerName();

    /**
     * Fetch a complete spot USDT snapshot.
     *
     * The returned map is keyed by normalized symbols (for example BTCUSDT),
     * and each value is the native 24-hour USDT quote turnover represented as
     * a non-negative decimal string.
     *
     * @return array<string, string>
     */
    public function fetch();
}
