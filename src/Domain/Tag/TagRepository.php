<?php

declare(strict_types=1);

namespace App\Domain\Tag;

interface TagRepository
{
    /** @return list<Tag> seřazené podle názvu (česká kolace) */
    public function all(): array;
}
