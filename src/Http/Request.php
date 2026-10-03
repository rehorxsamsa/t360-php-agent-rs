<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Routing\RouteMatch;

final readonly class Request
{
    /**
     * @param array<string, string> $routeParameters hodnoty ze zástupných částí trasy (doplní RoutingMiddleware)
     * @param array<string, string> $body řetězcové hodnoty z POST formuláře (ne-řetězce se zahazují)
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $routeParameters = [],
        public array $body = [],
        public ?string $clientIp = null,
        public ?RouteMatch $route = null,
    ) {}

    /**
     * @param array<string, string> $parameters
     */
    public function withRouteParameters(array $parameters): self
    {
        return new self($this->method, $this->path, $parameters, $this->body, $this->clientIp, $this->route);
    }

    public function withRoute(RouteMatch $route): self
    {
        return new self($this->method, $this->path, $route->parameters, $this->body, $this->clientIp, $route);
    }

    /** Hodnota pole formuláře; chybějící pole vrací prázdný řetězec. */
    public function input(string $name): string
    {
        return $this->body[$name] ?? '';
    }

    /** @throws \LogicException parametr trasa nedefinuje (chyba programátora) */
    public function routeParameter(string $name): string
    {
        return $this->routeParameters[$name]
            ?? throw new \LogicException(sprintf('Parametr trasy "%s" neexistuje.', $name));
    }

    /** Jediné místo, které čte $_SERVER a $_POST. */
    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $address = $_SERVER['REMOTE_ADDR'] ?? null;

        $method = is_string($method) ? strtoupper($method) : 'GET';
        $uri = is_string($uri) ? $uri : '/';

        $queryStart = strpos($uri, '?');
        $path = $queryStart === false ? $uri : substr($uri, 0, $queryStart);

        $body = [];
        foreach ($_POST as $name => $value) {
            if (is_string($value)) {
                $body[(string) $name] = $value;
            }
        }

        return new self(
            $method,
            $path === '' ? '/' : $path,
            body: $body,
            clientIp: is_string($address) ? $address : null,
        );
    }
}
