<?php

namespace Tests\Unit\MarketVolume\Providers;

use App\Service\MarketVolume\Contracts\MarketVolumeHttpClientInterface;
use App\Service\MarketVolume\Providers\XtProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class XtProviderTest extends TestCase
{
    public function testParsesOnlyValidUsdtQuoteTurnover()
    {
        $http = new XtProviderFakeHttpClient($this->fixture('xt_tickers.json'));
        $provider = new XtProvider($http);

        $this->assertSame(21, $provider->platformId());
        $this->assertSame('xt', $provider->providerName());
        $this->assertSame([
            'BTCUSDT' => '232229846.1260615',
            'ETHUSDT' => '0',
        ], $provider->fetch());
        $this->assertSame('https://sapi.x.group/v4/public/ticker/24h?tags=spot', $http->requestedUrl);
        $this->assertSame([
            'https://sapi.x.group/v4/public/ticker/24h?tags=spot',
        ], $http->requestedUrls);
    }

    public function testFallsBackToLegacyOfficialHostAfterTransportFailure()
    {
        $http = new XtProviderFakeHttpClient($this->fixture('xt_tickers.json'), true);
        $provider = new XtProvider($http);

        $this->assertArrayHasKey('BTCUSDT', $provider->fetch());
        $this->assertSame([
            'https://sapi.x.group/v4/public/ticker/24h?tags=spot',
            'https://sapi.xt.com/v4/public/ticker/24h?tags=spot',
        ], $http->requestedUrls);
    }

    public function testRejectsFailedEnvelope()
    {
        $this->expectException(UnexpectedValueException::class);

        (new XtProvider(new XtProviderFakeHttpClient(['rc' => 1, 'result' => []])))->fetch();
    }

    private function fixture($name)
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/' . $name), true, 512, JSON_BIGINT_AS_STRING);
    }
}

class XtProviderFakeHttpClient implements MarketVolumeHttpClientInterface
{
    private $payload;

    public $requestedUrl;

    public $requestedUrls = [];

    private $failFirstRequest;

    public function __construct(array $payload, $failFirstRequest = false)
    {
        $this->payload = $payload;
        $this->failFirstRequest = (bool) $failFirstRequest;
    }

    public function getJson($url, array $options = [])
    {
        $this->requestedUrl = $url;
        $this->requestedUrls[] = $url;
        if ($this->failFirstRequest && count($this->requestedUrls) === 1) {
            throw new \RuntimeException('simulated TLS failure');
        }

        return $this->payload;
    }
}
