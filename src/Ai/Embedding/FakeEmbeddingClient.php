<?php

declare(strict_types=1);

namespace App\Ai\Embedding;

use App\Domain\Ai\Embedding;
use App\Domain\Article\ArticleEmbeddingRepository;

/**
 * Falešný klient embeddingů bez sítě a bez modelu. Vektor je „pytel kmenů“: slova textu (malými písmeny,
 * aspoň 3 znaky, bez stop-slov ze `STOP_WORDS`) se zkrátí na prvních 5 znaků, každý kmen přičte 1 na pozici
 * `crc32(kmen) % 768` a vektor se normalizuje na délku 1. Stop-slova (tázací slova a spojky typu „jak“, „víte“)
 * se přeskakují, protože při 768 dimenzích by si náhodně „sedla“ na kmen z jiného článku a vyrobila by falešnou
 * shodu; skutečný model je navíc také ignoruje. Podobné texty tak mají společné kmeny, a proto malou kosinovou vzdálenost;
 * texty bez společného slova jsou kolmé (vzdálenost 1). Nejde o význam, jen o shodu slov, ale stačí
 * k předvedení celého řetězce. Deterministický: stejný text dá vždy stejný vektor.
 */
final readonly class FakeEmbeddingClient implements EmbeddingClient
{
    public const string MODEL = 'fake-hash-768';

    /** @var list<string> Slova (malými písmeny, aspoň 3 znaky), která se do vektoru nepočítají. */
    public const array STOP_WORDS = [
        'jak', 'jaký', 'jaká', 'jaké', 'jací', 'jakou', 'kdo', 'kdy', 'kde', 'kam', 'proč', 'což',
        'který', 'která', 'které', 'kteří', 'víte', 'vím', 'znáte', 'umíte', 'můžete', 'máte',
        'jsou', 'jsem', 'jste', 'být', 'byl', 'byla', 'bylo', 'jako', 'také', 'nebo', 'ale', 'pro', 'při',
    ];

    private const int STEM_LENGTH = 5;
    private const int MIN_WORD_LENGTH = 3;
    private const int CHARS_PER_TOKEN = 4;

    public function model(): string
    {
        return self::MODEL;
    }

    public function provider(): string
    {
        return EmbeddingProvider::Fake->logName();
    }

    public function embedDocuments(array $documents): EmbeddingResult
    {
        $startedAt = hrtime(true);
        $inputs = array_map(
            static fn(EmbeddingDocument $document): string => $document->title . "\n" . $document->text,
            $documents,
        );

        return $this->result($inputs, $startedAt);
    }

    public function embedQuery(string $query): EmbeddingResult
    {
        return $this->result([$query], hrtime(true));
    }

    /** @param list<string> $inputs */
    private function result(array $inputs, int $startedAt): EmbeddingResult
    {
        $vectors = array_map($this->vector(...), $inputs);
        $chars = (int) array_sum(array_map(static fn(string $input): int => mb_strlen($input), $inputs));

        return new EmbeddingResult(
            $vectors,
            intdiv($chars + self::CHARS_PER_TOKEN - 1, self::CHARS_PER_TOKEN),
            intdiv(hrtime(true) - $startedAt, 1_000_000),
        );
    }

    private function vector(string $text): Embedding
    {
        $dimensions = ArticleEmbeddingRepository::DIMENSIONS;
        $values = array_fill(0, $dimensions, 0.0);

        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $hits = 0;
        foreach ($words as $word) {
            if (mb_strlen($word) < self::MIN_WORD_LENGTH || in_array($word, self::STOP_WORDS, true)) {
                continue;
            }

            $values[crc32(mb_substr($word, 0, self::STEM_LENGTH)) % $dimensions] += 1.0;
            $hits++;
        }

        if ($hits === 0) {
            $values[0] = 1.0;
        }

        $length = sqrt(array_sum(array_map(static fn(float $value): float => $value * $value, $values)));

        return new Embedding(array_values(array_map(static fn(float $value): float => $value / $length, $values)));
    }
}
