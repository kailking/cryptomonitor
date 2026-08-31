<?php

namespace Tests\Unit;

use App\Exceptions\SpotListingProjectionUnavailableException;
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

    public function test_operations_returns_503_when_projection_is_unavailable(): void
    {
        $service = Mockery::mock(SpotListingDiscoveryService::class);
        $service->shouldReceive('operations')
            ->once()
            ->with([])
            ->andThrow(new SpotListingProjectionUnavailableException());
        $controller = new SpotListingController($service);

        set_error_handler(function ($severity, $message) {
            return strpos($message, 'Cannot modify header information') !== false;
        });
        try {
            $response = $controller->operations(Request::create(
                '/api/spot-listings/operations',
                'GET'
            ));
        } finally {
            restore_error_handler();
        }

        $payload = $response->getData(true);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('error', $payload['type']);
        $this->assertSame(50301, $payload['code']);
        $this->assertSame('新币雷达数据暂不可用', $payload['message']);
    }

    public function test_announcements_returns_503_when_projection_is_unavailable(): void
    {
        $service = Mockery::mock(SpotListingDiscoveryService::class);
        $service->shouldReceive('paginateAnnouncements')
            ->once()
            ->with([])
            ->andThrow(new SpotListingProjectionUnavailableException());
        $controller = new SpotListingController($service);

        set_error_handler(function ($severity, $message) {
            return strpos($message, 'Cannot modify header information') !== false;
        });
        try {
            $response = $controller->announcements(Request::create(
                '/api/spot-listings/announcements',
                'GET'
            ));
        } finally {
            restore_error_handler();
        }

        $payload = $response->getData(true);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('error', $payload['type']);
        $this->assertSame(50301, $payload['code']);
        $this->assertSame('新币雷达数据暂不可用', $payload['message']);
    }

    public function test_legacy_list_and_detail_routes_return_503_for_projection_failures(): void
    {
        $cases = [
            ['index', 'paginate', '/api/spot-listings', [], null, []],
            [
                'showAnnouncement',
                'announcementDetail',
                '/api/spot-listings/announcements/9',
                9,
                '9',
                [],
            ],
            ['show', 'detail', '/api/spot-listings/9', 9, '9', []],
        ];

        foreach ($cases as $case) {
            $service = Mockery::mock(SpotListingDiscoveryService::class);
            $service->shouldReceive($case[1])
                ->once()
                ->with($case[3])
                ->andThrow(new SpotListingProjectionUnavailableException());
            $controller = new SpotListingController($service);
            $request = Request::create($case[2], 'GET', $case[5]);

            set_error_handler(function ($severity, $message) {
                return strpos($message, 'Cannot modify header information') !== false;
            });
            try {
                $response = $case[4] === null
                    ? $controller->{$case[0]}($request)
                    : $controller->{$case[0]}($request, $case[4]);
            } finally {
                restore_error_handler();
            }

            $payload = $response->getData(true);
            $this->assertSame(503, $response->getStatusCode());
            $this->assertSame(50301, $payload['code']);
            $this->assertSame('新币雷达数据暂不可用', $payload['message']);
        }
    }
}
