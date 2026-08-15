<?php

namespace Tests\Unit\MarketVolume\Providers;

use App\Service\MarketVolume\Contracts\MarketVolumeHttpClientInterface;
use App\Service\MarketVolume\Providers\CoinexProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class CoinexProviderTest extends TestCase
{
    public function testParsesOnlyValidUsdtQuoteTurnover()
    {
        $http = new CoinexProviderFakeHttpClient($this->fixture('coinex_tickers.json'));
        $provider = new CoinexProvider($http);

        $this->assertSame(9, $provider->platformId());
        $this->assertSame('coinex', $provider->providerName());
        $this->assertSame([
            'BTCUSDT' => '105060432.75',
            'ETHUSDT' => '0',
        ], $provider->fetch());
        $this->assertSame('https://api.coinex.com/v2/spot/ticker', $http->requestedUrl);
    }

    public function testRejectsFailedEnvelope()
    {
        $this->expectException(UnexpectedValueException::class);

        (new CoinexProvider(new CoinexProviderFakeHttpClient(['code' => 1, 'data' => []])))->fetch();
    }

    private function fixture($name)
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/' . $name), true, 512, JSON_BIGINT_AS_STRING);
    }
}

class CoinexProviderFakeHttpClient implements MarketVolumeHttpClientInterface
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
