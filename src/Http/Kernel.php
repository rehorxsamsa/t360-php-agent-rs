<?php

declare(strict_types=1);

namespace App\Http;

use App\Container\Container;
use App\Http\Middleware\MiddlewarePipeline;
use App\Http\Routing\Router;

/**
 * Jádro HTTP: pipeline middleware, uprostřed router a sestavení controlleru z kontejneru.
 * Kernel je (vedle index.php a bin/konzole) jediné místo, které smí sahat do kontejneru.
 */
final readonly class Kernel
{
    public function __construct(
        private Router $router,
        private Container $container,
        private MiddlewarePipeline $pipeline,
    ) {}

    public function handle(Request $request): Response
    {
        return $this->pipeline->handle($request, $this->dispatch(...));
    }

    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request->method, $request->path);

        [$class, $method] = $match->handler;
        $action = [$this->container->get($class), $method];
        if (!is_callable($action)) {
            throw new \LogicException(sprintf('Controller %s nemá metodu %s().', $class, $method));
        }

        $response = $action($request->withRouteParameters($match->parameters));
        if (!$response instanceof Response) {
            throw new \LogicException(sprintf('Controller %s::%s() musí vrátit Response.', $class, $method));
        }

        return $response;
    }
}
