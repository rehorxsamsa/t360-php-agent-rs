<?php

declare(strict_types=1);

namespace App\Ai\Client;

final readonly class HttpResult
{
    /** @param array<string, string> $headers názvy hlaviček malými písmeny */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}
}
