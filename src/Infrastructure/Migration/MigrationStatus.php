<?php

declare(strict_types=1);

namespace App\Infrastructure\Migration;

final readonly class MigrationStatus
{
    /**
     * @param string $name název souboru bez .php
     * @param string|null $executedAt čas provedení, null = čeká na spuštění
     */
    public function __construct(
        public string $name,
        public ?string $executedAt,
    ) {}
}
