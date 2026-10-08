<?php

declare(strict_types=1);

namespace App\Ai\Embedding;

/** Výpočet embeddingů selhal. Zpráva je česky, srozumitelná a neobsahuje tajemství ani tělo požadavku. */
final class EmbeddingFailed extends \RuntimeException
{
    /** Model vrací jiný počet dimenzí, než má sloupec v databázi (změna modelu = nová migrace). */
    public static function dimensionMismatch(int $actual, int $expected): self
    {
        return new self(sprintf(
            'Model embeddingů vrací %d dimenzí, tabulka article_embeddings čeká %d – jiný model vyžaduje novou migraci.',
            $actual,
            $expected,
        ));
    }
}
