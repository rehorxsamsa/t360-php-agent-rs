<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Middleware;

use App\Http\Middleware\Middleware;
use App\Http\Middleware\MiddlewarePipeline;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;

final class MiddlewarePipelineTest extends TestCase
{
    /** @param list<string> $log */
    private function recording(string $name, array &$log): Middleware
    {
        return new class ($name, $log) implements Middleware {
            // Pole je sdílené odkazem s testem, který ho čte; PHPStan to nevidí.
            /** @param list<string> $log */
            public function __construct(private readonly string $name, private array &$log) {} // @phpstan-ignore property.onlyWritten

            public function process(Request $request, callable $next): Response
            {
                $this->log[] = $this->name . ' před';
                $response = $next($request);
                $this->log[] = $this->name . ' po';

                return $response;
            }
        };
    }

    public function test_calls_middleware_in_onion_order_around_handler(): void
    {
        $log = [];
        $pipeline = new MiddlewarePipeline([$this->recording('A', $log), $this->recording('B', $log)]);

        $response = $pipeline->handle(new Request('GET', '/'), static function () use (&$log): Response {
            $log[] = 'handler';

            return Response::text('ok');
        });

        self::assertSame(['A před', 'B před', 'handler', 'B po', 'A po'], $log);
        self::assertSame('ok', $response->body);
    }

    public function test_middleware_that_skips_next_short_circuits_handler(): void
    {
        $handlerCalled = false;
        $blocker = new class implements Middleware {
            public function process(Request $request, callable $next): Response
            {
                return Response::text('zablokováno', 403);
            }
        };
        $pipeline = new MiddlewarePipeline([$blocker]);

        $response = $pipeline->handle(new Request('GET', '/'), static function () use (&$handlerCalled): Response {
            $handlerCalled = true;

            return Response::text('ok');
        });

        self::assertSame(403, $response->status);
        self::assertFalse($handlerCalled);
    }

    public function test_empty_pipeline_calls_handler_directly(): void
    {
        $response = new MiddlewarePipeline([])->handle(
            new Request('GET', '/'),
            static fn(): Response => Response::text('přímo'),
        );

        self::assertSame('přímo', $response->body);
    }
}
