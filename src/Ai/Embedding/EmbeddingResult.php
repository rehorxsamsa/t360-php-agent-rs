<?php

declare(strict_types=1);

namespace App\Ai\Embedding;

use App\Domain\Ai\Embedding;

/** Výsledek výpočtu embeddingů: vektory, spotřeba tokenů a doba trvání. */
final readonly class EmbeddingResult
{
    /** @param list<Embedding> $vectors */
    public function __construct(
        public array $vectors,
        public int $tokens,
        public int $durationMs,
    ) {}

    public function first(): Embedding
    {
        return $this->vectors[0] ?? throw new \LogicException('Výsledek neobsahuje žádný vektor.');
    }
}
