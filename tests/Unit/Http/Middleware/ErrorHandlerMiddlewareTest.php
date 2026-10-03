<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Middleware;

use App\Http\Middleware\ErrorHandlerMiddleware;
use App\Http\PageNotFound;
use App\Http\Request;
use App\Http\Routing\MethodNotAllowed;
use App\Http\Routing\RouteNotFound;
use App\Http\View\TemplateRenderer;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerMiddlewareTest extends TestCase
{
    private function middleware(): ErrorHandlerMiddleware
    {
        return new ErrorHandlerMiddleware(new TemplateRenderer(__DIR__ . '/../../../../templates'));
    }

    public function test_route_not_found_becomes_404(): void
    {
        $response = $this->middleware()->process(
            new Request('GET', '/x'),
            static fn() => throw new RouteNotFound('x'),
        );

        self::assertSame(404, $response->status);
        self::assertStringContainsString('Stránka nenalezena', $response->body);
    }

    public function test_page_not_found_becomes_same_404_as_route_not_found(): void
    {
        $fromRoute = $this->middleware()->process(
            new Request('GET', '/x'),
            static fn() => throw new RouteNotFound('x'),
        );
        $fromController = $this->middleware()->process(
            new Request('GET', '/x'),
            static fn() => throw new PageNotFound(),
        );

        self::assertSame(404, $fromController->status);
        self::assertStringContainsString('Stránka nenalezena', $fromController->body);
        self::assertSame($fromRoute->body, $fromController->body);
    }

    public function test_method_not_allowed_becomes_405_with_allow_header(): void
    {
        $response = $this->middleware()->process(
            new Request('PUT', '/x'),
            static fn() => throw new MethodNotAllowed(['GET', 'POST']),
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET, POST', $response->headers['Allow'] ?? null);
    }

    public function test_response_passes_through_untouched(): void
    {
        $expected = \App\Http\Response::text('ok');

        $response = $this->middleware()->process(new Request('GET', '/'), static fn() => $expected);

        self::assertSame($expected, $response);
    }
}
