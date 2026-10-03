<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Domain\Tag\Tag;
use App\Domain\Tag\TagRepository;

/** Štítky v paměti; výchozí data z kontraktu plánu 005 (1 Bezpečnost, 2 Docker, 3 PHP). */
final class InMemoryTagRepository implements TagRepository
{
    /** @var list<Tag> v pořadí, v jakém je vrací all() */
    public array $tags;
    public int $allCalls = 0;

    /** @param list<Tag>|null $tags */
    public function __construct(?array $tags = null)
    {
        $this->tags = $tags ?? [
            new Tag(1, 'Bezpečnost', 'bezpecnost'),
            new Tag(2, 'Docker', 'docker'),
            new Tag(3, 'PHP', 'php'),
        ];
    }

    public function all(): array
    {
        ++$this->allCalls;

        return $this->tags;
    }
}
