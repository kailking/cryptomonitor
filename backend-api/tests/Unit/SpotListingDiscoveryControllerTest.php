<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\SpotListingController;
use App\Services\SpotListingDiscoveryService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class SpotListingDiscoveryControllerTest extends TestCase
{
    public function test_operations_accepts_the_frontend_limit_contract(): void
    {
        $service = Mockery::mock(SpotListingDiscoveryService::class);
        $service->shouldReceive('operations')
            ->once()
            ->with(['limit' => '200'])
            ->andReturn([
                'server_time_ms' => 1,
                'operations' => [],
            ]);
        $controller = new SpotListingController($service);

        set_error_handler(function ($severity, $message) {
            return strpos($message, 'Cannot modify header information') !== false;
        });
        try {
            $response = $controller->operations(Request::create(
                '/api/spot-listings/operations',
                'GET',
                ['limit' => '200']
            ));
        } finally {
            restore_error_handler();
        }

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $response->getData(true)['data']['server_time_ms']);
    }

    public function test_operations_rejects_limits_outside_the_contract(): void
    {
        $service = Mockery::mock(SpotListingDiscoveryService::class);
        $service->shouldNotReceive('operations');
        $controller = new SpotListingController($service);

        set_error_handler(function ($severity, $message) {
            return strpos($message, 'Cannot modify header information') !== false;
        });
        try {
            $response = $controller->operations(Request::create(
                '/api/spot-listings/operations',
                'GET',
                ['limit' => '201']
            ));
        } finally {
            restore_error_handler();
        }

        $this->assertSame(422, $response->getStatusCode());
    }
}
