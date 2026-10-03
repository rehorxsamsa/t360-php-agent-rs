<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Routing;

use App\Http\Controller\HealthController;
use App\Http\Routing\MethodNotAllowed;
use App\Http\Routing\RouteNotFound;
use App\Http\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function test_matches_static_route_with_handler_and_empty_parameters(): void
    {
        $router = new Router();
        $router->get('/zdravi', [HealthController::class, '__invoke']);

        $match = $router->match('GET', '/zdravi');

        self::assertSame([HealthController::class, '__invoke'], $match->handler);
        self::assertSame([], $match->parameters);
    }

    public function test_extracts_named_parameter(): void
    {
        $router = new Router();
        $router->get('/clanek/{slug}', [HealthController::class, '__invoke']);

        self::assertSame(['slug' => 'prvni-clanek'], $router->match('GET', '/clanek/prvni-clanek')->parameters);
    }

    public function test_decodes_percent_encoded_parameter(): void
    {
        $router = new Router();
        $router->get('/clanek/{slug}', [HealthController::class, '__invoke']);

        self::assertSame(['slug' => 'a b'], $router->match('GET', '/clanek/a%20b')->parameters);
    }

    #[DataProvider('nonMatchingPathProvider')]
    public function test_parameter_does_not_match_slash_or_empty_segment(string $path): void
    {
        $router = new Router();
        $router->get('/clanek/{slug}', [HealthController::class, '__invoke']);

        $this->expectException(RouteNotFound::class);

        $router->match('GET', $path);
    }

    /** @return iterable<string, array{string}> */
    public static function nonMatchingPathProvider(): iterable
    {
        yield 'extra segment' => ['/clanek/a/b'];
        yield 'empty segment' => ['/clanek/'];
    }

    public function test_wrong_method_throws_method_not_allowed_with_sorted_allowed_methods(): void
    {
        $router = new Router();
        $router->post('/x', [HealthController::class, '__invoke']);
        $router->get('/x', [HealthController::class, '__invoke']);

        try {
            $router->match('PUT', '/x');
            self::fail('Očekávána výjimka MethodNotAllowed.');
        } catch (MethodNotAllowed $exception) {
            self::assertSame(['GET', 'POST'], $exception->allowedMethods);
        }
    }

    public function test_post_route_matches_post(): void
    {
        $router = new Router();
        $router->post('/x', [HealthController::class, '__invoke']);

        self::assertSame([], $router->match('POST', '/x')->parameters);
    }

    #[DataProvider('unknownPathProvider')]
    public function test_unknown_path_or_trailing_slash_throws_route_not_found(string $path): void
    {
        $router = new Router();
        $router->get('/zdravi', [HealthController::class, '__invoke']);

        $this->expectException(RouteNotFound::class);

        $router->match('GET', $path);
    }

    /** @return iterable<string, array{string}> */
    public static function unknownPathProvider(): iterable
    {
        yield 'trailing slash' => ['/zdravi/'];
        yield 'unknown' => ['/neexistuje'];
    }

    public function test_unknown_path_with_any_method_is_not_found_rather_than_not_allowed(): void
    {
        $router = new Router();
        $router->get('/zdravi', [HealthController::class, '__invoke']);

        $this->expectException(RouteNotFound::class);

        $router->match('POST', '/neexistuje');
    }
}
