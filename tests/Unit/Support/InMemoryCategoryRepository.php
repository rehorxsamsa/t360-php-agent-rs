<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Domain\Category\Category;
use App\Domain\Category\CategoryRepository;

/** Rubriky v paměti; výchozí data z kontraktu plánu 005 (1 Technologie, 2 Věda a výzkum, 3 Zprávy). */
final class InMemoryCategoryRepository implements CategoryRepository
{
    /** @var list<Category> v pořadí, v jakém je vrací all() */
    public array $categories;
    public int $allCalls = 0;

    /** @param list<Category>|null $categories */
    public function __construct(?array $categories = null)
    {
        $this->categories = $categories ?? [
            new Category(1, 'Technologie', 'technologie'),
            new Category(2, 'Věda a výzkum', 'veda-a-vyzkum'),
            new Category(3, 'Zprávy', 'zpravy'),
        ];
    }

    public function all(): array
    {
        ++$this->allCalls;

        return $this->categories;
    }
}
