<?php

declare(strict_types=1);

namespace App\Http;

use App\Container\Container;
use App\Http\Middleware\MiddlewarePipeline;

/**
 * Jádro HTTP: pipeline middleware a na jejím konci sestavení controlleru z kontejneru.
 * Trasu přiřadí RoutingMiddleware; Kernel je (vedle index.php a bin/konzole) jediné místo, které smí sahat do kontejneru.
 */
final readonly class Kernel
{
    public function __construct(
        private Container $container,
        private MiddlewarePipeline $pipeline,
    ) {}

    public function handle(Request $request): Response
    {
        return $this->pipeline->handle($request, $this->dispatch(...));
    }

    private function dispatch(Request $request): Response
    {
        $match = $request->route ?? throw new \LogicException('Požadavek nemá přiřazenou trasu (chybí RoutingMiddleware).');

        [$class, $method] = $match->handler;
        $action = [$this->container->get($class), $method];
        if (!is_callable($action)) {
            throw new \LogicException(sprintf('Controller %s nemá metodu %s().', $class, $method));
        }

        $response = $action($request);
        if (!$response instanceof Response) {
            throw new \LogicException(sprintf('Controller %s::%s() musí vrátit Response.', $class, $method));
        }

        return $response;
    }
}
