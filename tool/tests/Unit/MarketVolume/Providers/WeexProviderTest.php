<?php

namespace Tests\Unit\MarketVolume\Providers;

use App\Service\MarketVolume\Contracts\MarketVolumeHttpClientInterface;
use App\Service\MarketVolume\Providers\WeexProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class WeexProviderTest extends TestCase
{
    public function testParsesOnlyValidUsdtQuoteTurnover()
    {
        $http = new WeexProviderFakeHttpClient($this->fixture('weex_tickers.json'));
        $provider = new WeexProvider($http);

        $this->assertSame(19, $provider->platformId());
        $this->assertSame('weex', $provider->providerName());
        $this->assertSame([
            'BTCUSDT' => '302604627.81261943',
            'ETHUSDT' => '0',
        ], $provider->fetch());
        $this->assertSame('https://api-spot.weex.com/api/v3/market/ticker/24hr', $http->requestedUrl);
    }

    public function testRejectsNonListEnvelope()
    {
        $this->expectException(UnexpectedValueException::class);

        (new WeexProvider(new WeexProviderFakeHttpClient(['data' => []])))->fetch();
    }

    private function fixture($name)
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/' . $name), true, 512, JSON_BIGINT_AS_STRING);
    }
}

class WeexProviderFakeHttpClient implements MarketVolumeHttpClientInterface
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
