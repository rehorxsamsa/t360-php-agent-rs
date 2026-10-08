<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Ai\Embedding\EmbeddingFailed;
use App\Ai\Embedding\EmbeddingResult;
use App\Domain\Ai\Embedding;

/**
 * Testovací data embeddingů (plán 009, §6): vektory, výsledky a výjimky. Konstruktory, které plán
 * upřesňuje jen signaturou (`EmbeddingResult`, `EmbeddingFailed`), jsou soustředěné sem.
 */
final class EmbeddingFixtures
{
    public const string FAKE_MODEL = 'fake-hash-768';
    public const int DIMENSIONS = 768;

    /** Jednotkový vektor s jedničkou na pozici `$index`. */
    public static function unit(int $index = 0, int $dimensions = self::DIMENSIONS): Embedding
    {
        $values = array_fill(0, $dimensions, 0.0);
        $values[$index] = 1.0;

        return new Embedding(array_values($values));
    }

    /**
     * Vektor v rovině složek 0 a 1 s kosinovou vzdáleností `$distance` od `unit(0)` (0 = shoda, 1 = kolmý).
     */
    public static function atDistance(float $distance, int $dimensions = self::DIMENSIONS): Embedding
    {
        $cos = 1.0 - $distance;
        $values = array_fill(0, $dimensions, 0.0);
        $values[0] = $cos;
        $values[1] = sqrt(max(0.0, 1.0 - $cos * $cos));

        return new Embedding($values);
    }

    /** Vektor se stejnou hodnotou ve všech složkách (rozlišení pořadí vektorů v odpovědi). */
    public static function filled(float $value, int $dimensions = self::DIMENSIONS): Embedding
    {
        return new Embedding(array_fill(0, $dimensions, $value));
    }

    /** @param list<Embedding> $vectors */
    public static function result(array $vectors, int $tokens = 3, int $durationMs = 1): EmbeddingResult
    {
        return new EmbeddingResult(vectors: $vectors, tokens: $tokens, durationMs: $durationMs);
    }

    /** PŘEDPOKLAD (plán: `final class extends \RuntimeException`): konstruktor se zprávou. */
    public static function failed(string $message = 'Služba embeddingů (Ollama) neodpovídá na http://ollama:11434 – spusťte ji: make ai-local.'): EmbeddingFailed
    {
        return new EmbeddingFailed($message);
    }

    /** Kosinová vzdálenost (referenční výpočet pro testy). */
    public static function cosineDistance(Embedding $a, Embedding $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($a->values as $i => $value) {
            $other = (float) ($b->values[$i] ?? 0.0);
            $dot += (float) $value * $other;
            $normA += (float) $value * (float) $value;
            $normB += $other * $other;
        }
        if ($normA == 0.0 || $normB == 0.0) {
            return 1.0;
        }

        return 1.0 - $dot / (sqrt($normA) * sqrt($normB));
    }

    public static function length(Embedding $embedding): float
    {
        $sum = 0.0;
        foreach ($embedding->values as $value) {
            $sum += (float) $value * (float) $value;
        }

        return sqrt($sum);
    }
}
