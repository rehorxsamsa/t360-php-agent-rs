<?php

declare(strict_types=1);

namespace App\Http\Routing;

/**
 * Jednoduchý router: přesná shoda cesty (bez tolerance koncového lomítka),
 * zástupné části {name} odpovídají jednomu neprázdnému segmentu.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: array{class-string, string}}> */
    private array $routes = [];

    /**
     * @param array{class-string, string} $handler
     */
    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /**
     * @param array{class-string, string} $handler
     */
    public function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /**
     * @throws RouteNotFound cesta neexistuje
     * @throws MethodNotAllowed cesta existuje, ale pro jinou metodu
     */
    public function match(string $method, string $path): RouteMatch
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $method) {
                $allowed[$route['method']] = $route['method'];

                continue;
            }

            $parameters = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $parameters[$key] = rawurldecode($value);
                }
            }

            return new RouteMatch($route['handler'], $parameters);
        }

        if ($allowed !== []) {
            $methods = array_values($allowed);
            sort($methods);

            throw new MethodNotAllowed($methods);
        }

        throw new RouteNotFound(sprintf('Cesta %s neexistuje.', $path));
    }

    /**
     * @param array{class-string, string} $handler
     */
    private function add(string $method, string $path, array $handler): void
    {
        $this->routes[] = ['method' => $method, 'regex' => $this->compile($path), 'handler' => $handler];
    }

    /** Převede `/clanek/{slug}` na regulární výraz s pojmenovanou skupinou. */
    private function compile(string $path): string
    {
        $parts = preg_split('~\{([a-z_]+)\}~', $path, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new \InvalidArgumentException(sprintf('Neplatná cesta trasy: %s', $path));
        }

        $regex = '';
        foreach ($parts as $index => $part) {
            $regex .= $index % 2 === 0 ? preg_quote($part, '~') : '(?P<' . $part . '>[^/]+)';
        }

        return '~^' . $regex . '\z~';
    }
}
