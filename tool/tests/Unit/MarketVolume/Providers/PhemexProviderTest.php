<?php

namespace Tests\Unit\MarketVolume\Providers;

use App\Service\MarketVolume\Contracts\MarketVolumeHttpClientInterface;
use App\Service\MarketVolume\Providers\PhemexProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class PhemexProviderTest extends TestCase
{
    public function testParsesFullSpotSnapshotAndScalesTurnoverExactly()
    {
        $http = new PhemexProviderFakeHttpClient($this->fixture('phemex_tickers.json'));
        $provider = new PhemexProvider($http);

        $this->assertSame(22, $provider->platformId());
        $this->assertSame('phemex', $provider->providerName());
        $this->assertSame([
            'BTCUSDT' => '302530401.59512835',
            'ETHUSDT' => '0.00000001',
            'DOGEUSDT' => '0',
            'BIGUSDT' => '1234567890123456789012.3456789',
        ], $provider->fetch());
        $this->assertSame('https://api.phemex.com/md/spot/ticker/24hr/all', $http->requestedUrl);
    }

    public function testRejectsEndpointErrorInsteadOfPublishingOldOrPartialData()
    {
        $this->expectException(UnexpectedValueException::class);

        (new PhemexProvider(new PhemexProviderFakeHttpClient(['error' => ['code' => 500], 'result' => []])))->fetch();
    }

    private function fixture($name)
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/' . $name), true, 512, JSON_BIGINT_AS_STRING);
    }
}

class PhemexProviderFakeHttpClient implements MarketVolumeHttpClientInterface
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
