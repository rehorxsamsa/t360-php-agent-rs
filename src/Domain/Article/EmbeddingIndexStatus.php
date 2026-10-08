<?php

declare(strict_types=1);

namespace App\Domain\Article;

/** Stav indexu: kolik je publikovaných článků a kolik z nich má aktuální vektor. */
final readonly class EmbeddingIndexStatus
{
    public function __construct(
        public int $published,
        public int $upToDate,
    ) {}

    /** Počet článků čekajících na indexaci. */
    public function pending(): int
    {
        return max(0, $this->published - $this->upToDate);
    }
}
