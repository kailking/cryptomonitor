<?php

namespace Tests\Unit;

use App\Exceptions\MarketChangeRedisUnavailableException;
use App\Http\Controllers\Api\QuotationController;
use App\Services\MarketChangeDataSource;
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
}
