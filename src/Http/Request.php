<?php

declare(strict_types=1);

namespace App\Http;

final readonly class Request
{
    public function __construct(
        public string $method,
        public string $path,
    ) {}

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
