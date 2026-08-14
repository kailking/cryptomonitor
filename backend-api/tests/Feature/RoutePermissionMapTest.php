<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Tests\TestCase;

class RoutePermissionMapTest extends TestCase
{
    public function test_admin_operations_use_exact_methods_and_granular_permissions(): void
    {
        $expected = [
            ['GET', 'api/user/list', 'users.view'],
            ['POST', 'api/admin/create_user', 'users.create'],
            ['POST', 'api/admin/edit_user', 'users.edit'],
            ['POST', 'api/user/remark', 'users.edit'],
            ['POST', 'api/admin/expire_user', 'users.renew'],
            ['POST', 'api/admin/expire_date_user', 'users.renew'],
            ['POST', 'api/admin/expire_batch_user', 'users.renew'],
            ['POST', 'api/admin/expire_batch_date_user', 'users.renew'],
            ['POST', 'api/admin/clear_token', 'users.force_logout'],
            ['POST', 'api/setting/diff/config', 'settings.market.view'],
            ['PUT', 'api/setting/diff/config/switch_show', 'settings.market.update'],
            ['POST', 'api/setting/diff/config/switch_show/batch', 'settings.market.update'],
            ['GET', 'api/system/log_type/list', 'system.logs.view'],
            ['GET', 'api/system/log/list', 'system.logs.view'],
            ['POST', 'api/setting/restart/server', 'system.server.restart'],
            ['POST', 'api/setting/restart/platform', 'system.platform.restart'],
            ['POST', 'api/platform/address/config', 'platform.address.configure'],
            ['POST', 'api/platform/address/refresh', 'platform.address.configure'],
            ['GET', 'api/market/change/list', 'quotation.extreme.view'],
            ['POST', 'api/user/change/block_id', 'quotation.extreme.view'],
            ['GET', 'api/quotation/change/config', 'quotation.extreme.config'],
            ['POST', 'api/user/change/block_id/batch', 'quotation.extreme.config'],
        ];

        foreach ($expected as list($method, $uri, $permission)) {
            $route = $this->routeFor($method, $uri);
            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            $middleware = $route->gatherMiddleware();
            $permissionMiddleware = 'check_permission:'.$permission;

            $this->assertSame([$method], $methods, $uri.' must expose only '.$method);
            $this->assertContains('check_api', $middleware, $uri);
            $this->assertContains($permissionMiddleware, $middleware, $uri);
            $this->assertNotContains('check_admin', $middleware, $uri);
            $this->assertLessThan(
                array_search($permissionMiddleware, $middleware, true),
                array_search('check_api', $middleware, true),
                $uri.' must authenticate before checking permission'
            );
        }
    }

    public function test_ordinary_user_routes_remain_check_api_only(): void
    {
        $ordinaryRoutes = [
            ['POST', 'api/user/block_id'],
            ['POST', 'api/user/block_id/batch'],
            ['GET', 'api/platform'],
            ['POST', 'api/quotation/diff/collect'],
            ['POST', 'api/user/filter'],
        ];

        foreach ($ordinaryRoutes as list($method, $uri)) {
            $middleware = $this->routeFor($method, $uri)->gatherMiddleware();

            $this->assertContains('check_api', $middleware, $uri);
            $this->assertNotContains('check_admin', $middleware, $uri);
            $this->assertSame(
                [],
                array_values(array_filter($middleware, function (string $item): bool {
                    return strpos($item, 'check_permission:') === 0;
                })),
                $uri.' must not require an administrator permission'
            );
        }
    }

    public function test_production_routes_no_longer_use_check_admin(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $this->assertNotContains(
                'check_admin',
                $route->gatherMiddleware(),
                $route->uri()
            );
        }
    }

    public function test_platform_restart_split_has_the_required_route_delta(): void
    {
        $this->assertCount(
            67,
            app('router')->getRoutes(),
            'The baseline was 66 routes; splitting platform restart adds exactly one route.'
        );
    }

    private function routeFor(string $method, string $uri): Route
    {
        foreach (app('router')->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        $this->fail($method.' '.$uri.' route is missing');
    }
}
