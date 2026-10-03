<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;

/**
 * Cibulový řetěz middleware: první v seznamu je nejvíc vnější.
 */
final readonly class MiddlewarePipeline
{
    /**
     * @param list<Middleware> $middleware
     */
    public function __construct(private array $middleware) {}

    /**
     * @param callable(Request): Response $handler koncový handler uprostřed řetězu
     */
    public function handle(Request $request, callable $handler): Response
    {
        $next = $handler;
        foreach (array_reverse($this->middleware) as $middleware) {
            $next = static fn(Request $request): Response => $middleware->process($request, $next);
        }

        return $next($request);
    }
}
