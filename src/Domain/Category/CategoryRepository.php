<?php

declare(strict_types=1);

namespace App\Domain\Category;

interface CategoryRepository
{
    /** @return list<Category> seřazené podle názvu (česká kolace) */
    public function all(): array;
}
