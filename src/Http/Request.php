<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Routing\RouteMatch;

final readonly class Request
{
    /**
     * @param array<string, string> $routeParameters hodnoty ze zástupných částí trasy (doplní RoutingMiddleware)
     * @param array<string, string> $body řetězcové hodnoty z POST formuláře (ne-řetězce se zahazují)
     * @param array<string, string> $query řetězcové hodnoty z query stringu (ne-řetězce se zahazují)
     * @param array<string, list<string>> $bodyLists jednoúrovňová pole řetězců z POST (`tags[]`), bez klíčů
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $routeParameters = [],
        public array $body = [],
        public ?string $clientIp = null,
        public ?RouteMatch $route = null,
        public array $query = [],
        public array $bodyLists = [],
    ) {}

    /**
     * @param array<string, string> $parameters
     */
    public function withRouteParameters(array $parameters): self
    {
        return new self($this->method, $this->path, $parameters, $this->body, $this->clientIp, $this->route, $this->query, $this->bodyLists);
    }

    public function withRoute(RouteMatch $route): self
    {
        return new self($this->method, $this->path, $route->parameters, $this->body, $this->clientIp, $route, $this->query, $this->bodyLists);
    }

    /** Hodnota pole formuláře; chybějící pole vrací prázdný řetězec. */
    public function input(string $name): string
    {
        return $this->body[$name] ?? '';
    }

    /**
     * Seznam hodnot pole formuláře (`name[]`); chybějící pole nebo jednoduchá hodnota vrací prázdný seznam.
     *
     * @return list<string>
     */
    public function inputList(string $name): array
    {
        return $this->bodyLists[$name] ?? [];
    }

    /** Hodnota parametru query stringu; chybějící (nebo pole) vrací prázdný řetězec. */
    public function queryParameter(string $name): string
    {
        return $this->query[$name] ?? '';
    }

    /** @throws \LogicException parametr trasa nedefinuje (chyba programátora) */
    public function routeParameter(string $name): string
    {
        return $this->routeParameters[$name]
            ?? throw new \LogicException(sprintf('Parametr trasy "%s" neexistuje.', $name));
    }

    /** Jediné místo, které čte $_SERVER, $_GET a $_POST. */
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
        $bodyLists = [];
        foreach ($_POST as $name => $value) {
            if (is_string($value)) {
                $body[(string) $name] = $value;
            } elseif (is_array($value)) {
                $list = self::stringList($value);
                if ($list !== null) {
                    $bodyLists[(string) $name] = $list;
                }
            }
        }

        $query = [];
        foreach ($_GET as $name => $value) {
            if (is_string($value)) {
                $query[(string) $name] = $value;
            }
        }

        return new self(
            $method,
            $path === '' ? '/' : $path,
            body: $body,
            clientIp: is_string($address) ? $address : null,
            query: $query,
            bodyLists: $bodyLists,
        );
    }

    /**
     * Jen pole, jehož všechny položky jsou řetězce (vnořená pole celé pole zneplatní); klíče se zahodí.
     *
     * @param array<mixed> $values
     * @return list<string>|null
     */
    private static function stringList(array $values): ?array
    {
        $list = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                return null;
            }
            $list[] = $value;
        }

        return $list;
    }
}
