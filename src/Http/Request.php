<?php

declare(strict_types=1);

namespace App\Http;

final readonly class Request
{
    /**
     * @param array<string, string> $routeParameters hodnoty ze zástupných částí trasy (doplní Kernel)
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $routeParameters = [],
    ) {}

    /**
     * @param array<string, string> $parameters
     */
    public function withRouteParameters(array $parameters): self
    {
        return new self($this->method, $this->path, $parameters);
    }

    /** @throws \LogicException parametr trasa nedefinuje (chyba programátora) */
    public function routeParameter(string $name): string
    {
        return $this->routeParameters[$name]
            ?? throw new \LogicException(sprintf('Parametr trasy "%s" neexistuje.', $name));
    }

    /** Jediné místo, které čte $_SERVER. */
    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        $method = is_string($method) ? strtoupper($method) : 'GET';
        $uri = is_string($uri) ? $uri : '/';

        $queryStart = strpos($uri, '?');
        $path = $queryStart === false ? $uri : substr($uri, 0, $queryStart);

        return new self($method, $path === '' ? '/' : $path);
    }
}
