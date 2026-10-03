<?php

declare(strict_types=1);

namespace App\Domain\Category;

final readonly class Category
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
    ) {}
}
