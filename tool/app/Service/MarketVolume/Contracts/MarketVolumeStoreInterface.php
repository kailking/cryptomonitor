<?php

namespace App\Service\MarketVolume\Contracts;

interface MarketVolumeStoreInterface
{
    /**
     * Validate that the selected Redis database belongs to this feature.
     *
     * @return void
     */
    public function ensureNamespace();

    /**
     * Atomically replace one platform's complete snapshot.
     *
     * @param int $platformId
     * @param string $providerName
     * @param array<string, string> $volumes
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function publish($platformId, $providerName, array $volumes, array $context = []);
}
