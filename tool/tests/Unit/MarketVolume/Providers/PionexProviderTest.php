<?php

namespace Tests\Unit\MarketVolume\Providers;

use App\Service\MarketVolume\Contracts\MarketVolumeHttpClientInterface;
use App\Service\MarketVolume\Providers\PionexProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class PionexProviderTest extends TestCase
{
    public function testParsesOnlyValidSpotUsdtQuoteTurnover()
    {
        $http = new PionexProviderFakeHttpClient($this->fixture('pionex_tickers.json'));
        $provider = new PionexProvider($http);

        $this->assertSame(23, $provider->platformId());
        $this->assertSame('pionex', $provider->providerName());
        $this->assertSame([
            'BTCUSDT' => '1903858080.64650464',
            'ETHUSDT' => '0',
        ], $provider->fetch());
        $this->assertSame('https://api.pionex.com/api/v1/market/tickers?type=SPOT', $http->requestedUrl);
    }

    public function testRejectsFailedEnvelope()
    {
        $this->expectException(UnexpectedValueException::class);

        (new PionexProvider(new PionexProviderFakeHttpClient(['result' => false, 'data' => ['tickers' => []]])))->fetch();
    }

    private function fixture($name)
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/' . $name), true, 512, JSON_BIGINT_AS_STRING);
    }
}

class PionexProviderFakeHttpClient implements MarketVolumeHttpClientInterface
{
    private $payload;

    public $requestedUrl;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function getJson($url, array $options = [])
    {
        $this->requestedUrl = $url;

        return $this->payload;
    }
}
