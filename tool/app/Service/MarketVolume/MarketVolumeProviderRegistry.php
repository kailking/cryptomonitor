<?php

namespace App\Service\MarketVolume;

use App\Service\MarketVolume\Contracts\MarketVolumeHttpClientInterface;
use App\Service\MarketVolume\Contracts\MarketVolumeProviderInterface;

class MarketVolumeProviderRegistry
{
    /** @var array<int, mixed> */
    private $providerMap;

    /** @var MarketVolumeHttpClientInterface */
    private $http;

    /** @var array<int, MarketVolumeProviderInterface> */
    private $instances = [];

    public function __construct(array $providerMap, MarketVolumeHttpClientInterface $http)
    {
        $this->providerMap = $providerMap;
        $this->http = $http;
    }

    /**
     * @param array<int> $platformIds
     * @return void
     */
    public function validate(array $platformIds)
    {
        $missing = [];
        foreach ($platformIds as $platformId) {
            $platformId = (int) $platformId;
            if (!array_key_exists($platformId, $this->providerMap)) {
                $missing[] = $platformId;
                continue;
            }
            $this->get($platformId);
        }

        if (!empty($missing)) {
            throw new \LogicException('Missing market-volume providers for platform IDs: '.implode(',', $missing));
        }
    }

    /**
     * @param int $platformId
     * @return MarketVolumeProviderInterface
     */
    public function get($platformId)
    {
        $platformId = (int) $platformId;
        if (isset($this->instances[$platformId])) {
            return $this->instances[$platformId];
        }

        if (!array_key_exists($platformId, $this->providerMap)) {
            throw new \OutOfBoundsException('No market-volume provider for platform '.$platformId.'.');
        }

        $definition = $this->providerMap[$platformId];
        if ($definition instanceof MarketVolumeProviderInterface) {
            $provider = $definition;
        } elseif (is_callable($definition) && !is_string($definition)) {
            $provider = call_user_func($definition, $this->http);
        } elseif (is_string($definition) && class_exists($definition)) {
            $provider = new $definition($this->http);
        } else {
            throw new \LogicException('Invalid market-volume provider definition for platform '.$platformId.'.');
        }

        if (!$provider instanceof MarketVolumeProviderInterface) {
            throw new \LogicException('Provider for platform '.$platformId.' does not implement MarketVolumeProviderInterface.');
        }
        if ((int) $provider->platformId() !== $platformId) {
            throw new \LogicException('Provider platform ID mismatch for configured platform '.$platformId.'.');
        }

        $this->instances[$platformId] = $provider;

        return $provider;
    }
}
