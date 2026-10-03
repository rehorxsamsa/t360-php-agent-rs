<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Middleware;

use App\Http\Middleware\RoutingMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\MethodNotAllowed;
use App\Http\Routing\RouteNotFound;
use App\Http\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RoutingMiddlewareTest extends TestCase
{
    private function middleware(): RoutingMiddleware
    {
        $router = new Router();
        $router->get('/clanek/{id}', [\stdClass::class, 'bar']);

        return new RoutingMiddleware($router);
    }

    public function test_matched_route_is_attached_to_request_passed_to_next(): void
    {
        $seen = null;

        $this->middleware()->process(new Request('GET', '/clanek/5'), static function (Request $request) use (&$seen): Response {
            $seen = $request;

            return Response::text('ok');
        });

        self::assertInstanceOf(Request::class, $seen);
        self::assertSame([\stdClass::class, 'bar'], $seen->route?->handler);
        self::assertSame('5', $seen->routeParameter('id'));
    }

    public function test_unknown_path_throws_route_not_found_and_next_not_called(): void
    {
        $called = false;

        try {
            $this->middleware()->process(new Request('GET', '/nic'), static function () use (&$called): Response {
                $called = true;

                return Response::text('ok');
            });
            self::fail('Očekávána výjimka RouteNotFound.');
        } catch (RouteNotFound) {
            self::assertFalse($called);
        }
    }

    public function test_wrong_method_throws_method_not_allowed(): void
    {
        $this->expectException(MethodNotAllowed::class);

        $this->middleware()->process(new Request('POST', '/clanek/5'), static fn(): Response => Response::text('ok'));
    }
}
