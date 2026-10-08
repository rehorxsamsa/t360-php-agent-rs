<?php

declare(strict_types=1);

namespace App\Ai\Embedding;

/** Text určený k zaindexování: titulek (smí být prázdný) a vlastní text. */
final readonly class EmbeddingDocument
{
    public function __construct(
        public string $title,
        public string $text,
    ) {}
}
