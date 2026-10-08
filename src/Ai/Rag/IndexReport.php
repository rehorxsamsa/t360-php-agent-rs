<?php

declare(strict_types=1);

namespace App\Ai\Rag;

/** Výsledek jednoho běhu indexace: co se zaindexovalo, odebralo a co ještě čeká. */
final readonly class IndexReport
{
    public function __construct(
        public int $indexed,
        public int $removed,
        public int $remaining,
        public int $tokens,
        public int $durationMs,
        public string $model,
        public string $provider,
    ) {}
}
