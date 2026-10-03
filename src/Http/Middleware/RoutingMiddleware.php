<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\Router;

/**
 * Přiřadí požadavku trasu. Neexistující cesta (RouteNotFound) a špatná metoda (MethodNotAllowed)
 * vyletí k ErrorHandlerMiddleware – díky tomu CSRF a autorizace běží až u existující trasy.
 */
final readonly class RoutingMiddleware implements Middleware
{
    public function __construct(private Router $router) {}

    public function process(Request $request, callable $next): Response
    {
        return $next($request->withRoute($this->router->match($request->method, $request->path)));
    }
}
