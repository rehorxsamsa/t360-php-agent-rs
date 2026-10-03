<?php

declare(strict_types=1);

namespace App\Domain\Tag;

final readonly class Tag
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
    ) {}
}
