<?php

namespace Tests\Unit;

use App\Exceptions\MarketChangeRedisUnavailableException;
use App\Http\Controllers\Api\QuotationController;
use App\Services\MarketChangeDataSource;
use App\Services\MarketChangeResponseFormatter;
use App\Services\MarketChangeRedisGenerationService;
use App\Services\MarketVolumeFreshness;
use Illuminate\Http\Request;
use Tests\TestCase;

class MarketChangeControllerContractTest extends TestCase
{
    public function test_redis_unavailable_is_an_explicit_retryable_503_not_an_empty_success(): void
    {
        $source = $this->createMock(MarketChangeDataSource::class);
        $source->expects($this->once())
            ->method('list')
            ->willThrowException(new MarketChangeRedisUnavailableException('redis unavailable'));
        $request = Request::create('/market/change/list', 'GET', ['direction' => 1]);
        $request->attributes->set('user_id', 123);

        set_error_handler(function ($severity, $message) {
            return strpos($message, 'Cannot modify header information') !== false;
        });
        try {
            $response = (new QuotationController())->changeList($request, $source);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('error', $response->getData(true)['type']);
        $this->assertSame(50301, $response->getData(true)['code']);
        $this->assertNotSame([], $response->getData(true));
    }

    public function test_invalid_window_is_returned_as_an_explicit_422(): void
    {
        $source = $this->createMock(MarketChangeDataSource::class);
        $source->expects($this->once())
            ->method('list')
            ->willThrowException(new \InvalidArgumentException(
                'window_seconds must be 30 or 300.'
            ));
        $request = Request::create('/market/change/list', 'GET', [
            'direction' => 1,
            'window_seconds' => 10,
        ]);
        $request->attributes->set('user_id', 123);

        set_error_handler(function ($severity, $message) {
            return strpos($message, 'Cannot modify header information') !== false;
        });
        try {
            $response = (new QuotationController())->changeList($request, $source);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('error', $response->getData(true)['type']);
        $this->assertSame(422, $response->getData(true)['code']);
        $this->assertSame(
            'window_seconds must be 30 or 300.',
            $response->getData(true)['message']
        );
    }

    public function test_array_window_query_is_a_422_instead_of_a_php_500(): void
    {
        config()->set('market_change.source', 'redis');
        $source = new MarketChangeDataSource(
            $this->createMock(MarketChangeRedisGenerationService::class),
            new MarketChangeResponseFormatter(),
            new MarketVolumeFreshness()
        );
        $request = Request::create('/market/change/list', 'GET', [
            'direction' => 1,
            'window_seconds' => ['30'],
        ]);
        $request->attributes->set('user_id', 123);

        set_error_handler(function ($severity, $message) {
            return strpos($message, 'Cannot modify header information') !== false;
        });
        try {
            $response = (new QuotationController())->changeList($request, $source);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('error', $response->getData(true)['type']);
        $this->assertSame(422, $response->getData(true)['code']);
        $this->assertSame(
            'window_seconds must be 30 or 300.',
            $response->getData(true)['message']
        );
    }
}
