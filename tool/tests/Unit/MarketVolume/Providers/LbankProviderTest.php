<?php

namespace Tests\Unit\MarketVolume\Providers;

use App\Service\MarketVolume\Contracts\MarketVolumeHttpClientInterface;
use App\Service\MarketVolume\Providers\LbankProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class LbankProviderTest extends TestCase
{
    public function testParsesOnlyValidUsdtQuoteTurnover()
    {
        $http = new LbankProviderFakeHttpClient($this->fixture('lbank_tickers.json'));
        $provider = new LbankProvider($http);

        $this->assertSame(10, $provider->platformId());
        $this->assertSame('lbank', $provider->providerName());
        $this->assertSame([
            'BTCUSDT' => '966280941.61',
            'ETHUSDT' => '0',
        ], $provider->fetch());
        $this->assertSame('https://api.lbkex.com/v2/ticker/24hr.do?symbol=all', $http->requestedUrl);
    }

    public function testRejectsFailedEnvelope()
    {
        $this->expectException(UnexpectedValueException::class);

        (new LbankProvider(new LbankProviderFakeHttpClient(['result' => false, 'error_code' => 100, 'data' => []])))->fetch();
    }

    private function fixture($name)
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/' . $name), true, 512, JSON_BIGINT_AS_STRING);
    }
}

class LbankProviderFakeHttpClient implements MarketVolumeHttpClientInterface
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
