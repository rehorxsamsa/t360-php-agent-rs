<?php

declare(strict_types=1);

namespace App\Domain\Article;

/** Publikovaný článek čekající na (re)indexaci; `sourceHash` počítá SQL (SHA2 titulku, perexu a textu). */
final readonly class IndexableArticle
{
    public function __construct(
        public int $id,
        public string $title,
        public string $excerpt,
        public string $body,
        public string $sourceHash,
    ) {}
}
