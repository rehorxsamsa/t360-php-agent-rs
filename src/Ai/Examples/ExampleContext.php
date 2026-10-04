<?php

declare(strict_types=1);

namespace App\Ai\Examples;

/** Kdo příklad spouští a s jakou volbou. `model` se používá jen u příkladu 05 (prázdný = výchozí). */
final readonly class ExampleContext
{
    public function __construct(
        public ?int $userId,
        public string $model = '',
    ) {}
}
