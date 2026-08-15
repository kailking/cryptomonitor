<?php

namespace Tests\Unit\MarketVolume\Providers;

use App\Service\MarketVolume\Contracts\MarketVolumeHttpClientInterface;
use App\Service\MarketVolume\Providers\BinanceProvider;
use App\Service\MarketVolume\Providers\BitgetProvider;
use App\Service\MarketVolume\Providers\BybitProvider;
use App\Service\MarketVolume\Providers\GateProvider;
use App\Service\MarketVolume\Providers\HtxProvider;
use App\Service\MarketVolume\Providers\KucoinProvider;
use App\Service\MarketVolume\Providers\MexcProvider;
use App\Service\MarketVolume\Providers\OkxProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class MajorProvidersTest extends TestCase
{
    /**
     * @dataProvider providerCases
     */
    public function testFetchesOneSpotUsdtSnapshot($providerClass, $fixture, $endpoint, $platformId, $providerName)
    {
        $http = new MajorProviderFakeHttpClient($this->fixture($fixture));
        $provider = new $providerClass($http);

        $this->assertSame([
            'BTCUSDT' => '12345.67',
            'ZEROUSDT' => '0',
        ], $provider->fetch());
        $this->assertSame($endpoint, $http->requestedUrl);
        $this->assertSame($platformId, $provider->platformId());
        $this->assertSame($providerName, $provider->providerName());
    }

    public function providerCases()
    {
        return [
            'htx' => [HtxProvider::class, 'htx.json', 'https://api.huobi.pro/market/tickers', 1, 'htx'],
            'binance' => [BinanceProvider::class, 'binance.json', 'https://api.binance.com/api/v3/ticker/24hr', 2, 'binance'],
            'okx' => [OkxProvider::class, 'okx.json', 'https://www.okx.com/api/v5/market/tickers?instType=SPOT', 3, 'okx'],
            'gate' => [GateProvider::class, 'gate.json', 'https://api.gateio.ws/api/v4/spot/tickers', 4, 'gate'],
            'mexc' => [MexcProvider::class, 'mexc.json', 'https://api.mexc.com/api/v3/ticker/24hr', 5, 'mexc'],
            'kucoin' => [KucoinProvider::class, 'kucoin.json', 'https://api.kucoin.com/api/v1/market/allTickers', 8, 'kucoin'],
            'bitget' => [BitgetProvider::class, 'bitget.json', 'https://api.bitget.com/api/v3/market/tickers?category=SPOT', 15, 'bitget'],
            'bybit' => [BybitProvider::class, 'bybit.json', 'https://api.bybit.com/v5/market/tickers?category=spot', 16, 'bybit'],
        ];
    }

    /**
     * @dataProvider invalidResponseCases
     */
    public function testRejectsBusinessErrorOrInvalidEnvelope($providerClass, array $payload)
    {
        $provider = new $providerClass(new MajorProviderFakeHttpClient($payload));

        $this->expectException(UnexpectedValueException::class);
        $provider->fetch();
    }

    public function invalidResponseCases()
    {
        return [
            'htx' => [HtxProvider::class, ['status' => 'error', 'data' => []]],
            'binance' => [BinanceProvider::class, ['code' => -1003, 'msg' => 'rate limited']],
            'okx' => [OkxProvider::class, ['code' => '50011', 'data' => []]],
            'gate' => [GateProvider::class, ['label' => 'TOO_MANY_REQUESTS']],
            'mexc' => [MexcProvider::class, ['code' => 700002, 'msg' => 'invalid request']],
            'kucoin' => [KucoinProvider::class, ['code' => '400000', 'data' => []]],
            'bitget' => [BitgetProvider::class, ['code' => '40015', 'data' => []]],
            'bybit' => [BybitProvider::class, ['retCode' => 10006, 'result' => []]],
        ];
    }

    private function fixture($name)
    {
        $path = dirname(__DIR__) . '/Fixtures/' . $name;
        $payload = json_decode(file_get_contents($path), true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), $name . ' must contain valid JSON');

        return $payload;
    }
}

class MajorProviderFakeHttpClient implements MarketVolumeHttpClientInterface
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
