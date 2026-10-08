<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ai;

use App\Ai\Client\CurlHttpTransport;
use App\Ai\Embedding\EmbeddingDocument;
use App\Ai\Embedding\EmbeddingFailed;
use App\Ai\Embedding\OllamaEmbeddingClient;
use App\Tests\Unit\Support\EmbeddingFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Plán 009, AC 35: skutečné embeddingy z lokální Ollamy (profil `ai-local`, model embeddinggemma, 768 dimenzí).
 * Spouští se ručně: `docker compose exec app vendor/bin/phpunit --group live`. Bez dostupné Ollamy se přeskočí;
 * `make test` skupinu `live` vylučuje (phpunit.xml.dist). Texty jsou titulky a perexy tří článků ze seedu.
 */
#[Group('live')]
final class OllamaEmbeddingLiveTest extends TestCase
{
    private static function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function client(): OllamaEmbeddingClient
    {
        return new OllamaEmbeddingClient(
            new CurlHttpTransport(timeoutSeconds: 120, allowPlainHttp: true),
            self::environment('OLLAMA_URL', 'http://ollama:11434'),
            self::environment('EMBED_MODEL', 'embeddinggemma'),
        );
    }

    public function test_sleep_question_is_nearest_to_sleep_article(): void
    {
        $client = self::client();
        try {
            $query = $client->embedQuery('Proč se mám před zkouškou pořádně vyspat?');
        } catch (EmbeddingFailed $exception) {
            self::markTestSkipped('Ollama není dostupná (' . $exception->getMessage() . ') – spusťte make ai-local.');
        }

        $documents = $client->embedDocuments([
            new EmbeddingDocument(
                'Docker pro vývojáře: proč na něm záleží',
                'Kontejnery zjednodušují vývojové prostředí. Podívejte se, jak vypadá běžný denní postup.',
            ),
            new EmbeddingDocument(
                'Nová studie: spánek ovlivňuje paměť víc, než se čekalo',
                'Ukázkový text o vědecké studii spánku. Ti, kdo spali pravidelně, si lépe pamatovali nové informace.',
            ),
            new EmbeddingDocument(
                'Jazykové modely v redakci: pomocník, ne autor',
                'Umělá inteligence umí navrhnout perex nebo štítky. Výsledek ale vždy musí zkontrolovat člověk.',
            ),
        ]);

        self::assertSame(768, $query->first()->dimensions());
        self::assertCount(3, $documents->vectors);
        self::assertGreaterThan(0, $documents->tokens);
        $distances = array_map(
            static fn($vector): float => EmbeddingFixtures::cosineDistance($query->first(), $vector),
            $documents->vectors,
        );
        fwrite(STDERR, sprintf("\nVzdálenosti (docker, spánek, modely): %s\n", implode(' / ', array_map(static fn(float $d): string => number_format($d, 3, ',', ''), $distances))));
        self::assertCount(3, $distances);
        self::assertLessThan($distances[0], $distances[1], 'Spánek musí být blíž než Docker.');
        self::assertLessThan($distances[2], $distances[1], 'Spánek musí být blíž než jazykové modely.');
    }
}
