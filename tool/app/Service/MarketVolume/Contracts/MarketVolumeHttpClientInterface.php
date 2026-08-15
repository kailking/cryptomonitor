<?php

namespace App\Service\MarketVolume\Contracts;

interface MarketVolumeHttpClientInterface
{
    /**
     * Perform a GET request and decode a JSON object/array.
     *
     * Supported options: query, headers, connect_timeout, timeout and retries.
     *
     * @param string $url
     * @param array<string, mixed> $options
     * @return array
     */
    public function getJson($url, array $options = []);
}
