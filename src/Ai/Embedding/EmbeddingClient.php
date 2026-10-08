<?php

declare(strict_types=1);

namespace App\Ai\Embedding;

/**
 * Port pro výpočet embeddingů (vektorů významu textu), ADR-0009. Rozlišuje dokument a dotaz, protože
 * oba poskytovatelé s nimi zacházejí jinak (u Ollamy s modelem embeddinggemma se liší předpona textu).
 */
interface EmbeddingClient
{
    /** Prostor vektorů: ukládá se k řádku indexu a porovnávat lze jen vektory stejného modelu. */
    public function model(): string;

    /** Název poskytovatele: `fake` nebo `ollama` (viz {@see EmbeddingProvider::logName()}). */
    public function provider(): string;

    /**
     * @param list<EmbeddingDocument> $documents
     * @return EmbeddingResult vektory ve stejném pořadí jako dokumenty
     * @throws EmbeddingFailed
     */
    public function embedDocuments(array $documents): EmbeddingResult;

    /**
     * @return EmbeddingResult s právě jedním vektorem
     * @throws EmbeddingFailed
     */
    public function embedQuery(string $query): EmbeddingResult;
}
