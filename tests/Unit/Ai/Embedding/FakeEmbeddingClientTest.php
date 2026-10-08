<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Embedding;

use App\Ai\Embedding\EmbeddingClient;
use App\Ai\Embedding\EmbeddingDocument;
use App\Ai\Embedding\FakeEmbeddingClient;
use App\Domain\Ai\Embedding;
use App\Domain\Article\ArticleEmbeddingRepository;
use App\Tests\Unit\Support\AiFixtures;
use App\Tests\Unit\Support\EmbeddingFixtures;
use PHPUnit\Framework\TestCase;

/** Plán 009, AC 1: deterministický falešný klient embeddingů (závazný algoritmus, bez sítě). */
final class FakeEmbeddingClientTest extends TestCase
{
    /**
     * Referenční implementace algoritmu AC 1: mb_strtolower → slova (\p{L}\p{N}) s délkou ≥ 3 a mimo stop-slova
     * (`FakeEmbeddingClient::STOP_WORDS`) → kmen = prvních 5 znaků → v[crc32(kmen) % 768] += 1 → normalizace na
     * délku 1; text bez započítaných slov → v[0] = 1.
     *
     * @return list<float>
     */
    private static function reference(string $text): array
    {
        $vector = array_fill(0, 768, 0.0);
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
        $any = false;
        foreach ($words as $word) {
            if (mb_strlen($word) < 3 || in_array($word, FakeEmbeddingClient::STOP_WORDS, true)) {
                continue;
            }
            $vector[crc32(mb_substr($word, 0, 5)) % 768] += 1.0;
            $any = true;
        }
        if (!$any) {
            $vector[0] = 1.0;

            return array_values($vector);
        }
        $length = sqrt(array_sum(array_map(static fn(float $v): float => $v * $v, $vector)));

        return array_values(array_map(static fn(float $v): float => $v / $length, $vector));
    }

    /** @param list<float> $expected */
    private static function assertVector(array $expected, Embedding $actual): void
    {
        self::assertCount(768, $actual->values);
        foreach ($expected as $i => $value) {
            self::assertEqualsWithDelta($value, (float) $actual->values[$i], 1e-12, 'složka ' . $i);
        }
    }

    public function test_identifies_model_and_provider(): void
    {
        $client = new FakeEmbeddingClient();

        self::assertInstanceOf(EmbeddingClient::class, $client);
        self::assertSame('fake-hash-768', $client->model());
        self::assertSame('fake-hash-768', FakeEmbeddingClient::MODEL);
        self::assertSame('fake', $client->provider());
        self::assertSame(768, ArticleEmbeddingRepository::DIMENSIONS);
    }

    public function test_query_vector_follows_binding_algorithm(): void
    {
        $text = 'Jak spánek ovlivňuje paměť? Spánek a 2026!';

        $result = new FakeEmbeddingClient()->embedQuery($text);

        self::assertCount(1, $result->vectors);
        self::assertVector(self::reference($text), $result->first());
    }

    public function test_document_vector_is_title_newline_text_without_ollama_prefixes(): void
    {
        $document = new EmbeddingDocument('Nová studie: spánek', 'Vědci popsali, jak spánek ovlivňuje paměť.');

        $result = new FakeEmbeddingClient()->embedDocuments([$document]);

        self::assertCount(1, $result->vectors);
        self::assertVector(self::reference("Nová studie: spánek\nVědci popsali, jak spánek ovlivňuje paměť."), $result->first());
    }

    public function test_same_input_twice_gives_identical_vectors(): void
    {
        $client = new FakeEmbeddingClient();

        self::assertSame($client->embedQuery('Docker sjednocuje prostředí.')->first()->values, $client->embedQuery('Docker sjednocuje prostředí.')->first()->values);
        $documents = [new EmbeddingDocument('A', 'Docker'), new EmbeddingDocument('B', 'Kvasinky')];
        self::assertEquals($client->embedDocuments($documents), $client->embedDocuments($documents));
    }

    public function test_every_vector_has_768_components_and_unit_length(): void
    {
        $client = new FakeEmbeddingClient();
        $vectors = [
            ...$client->embedDocuments([
                new EmbeddingDocument('Docker pro vývojáře', 'Docker sjednocuje prostředí.'),
                new EmbeddingDocument('', 'spánek ovlivňuje paměť'),
                new EmbeddingDocument('', ''),
            ])->vectors,
            $client->embedQuery('Jak spánek ovlivňuje paměť?')->first(),
            $client->embedQuery('?!')->first(),
        ];

        self::assertCount(5, $vectors);
        foreach ($vectors as $vector) {
            self::assertSame(768, $vector->dimensions());
            self::assertEqualsWithDelta(1.0, EmbeddingFixtures::length($vector), 1e-9);
        }
    }

    public function test_text_without_words_maps_to_first_component(): void
    {
        foreach (['', '?!', 'a b c', 'já tě', 'Co víte o jak?'] as $text) {
            $values = new FakeEmbeddingClient()->embedQuery($text)->first()->values;

            self::assertEqualsWithDelta(1.0, (float) $values[0], 1e-12, var_export($text, true));
            self::assertEqualsWithDelta(1.0, array_sum(array_map(static fn($v): float => abs((float) $v), $values)), 1e-12);
        }
    }

    public function test_query_and_document_with_same_words_have_zero_distance(): void
    {
        $client = new FakeEmbeddingClient();

        $query = $client->embedQuery('Docker')->first();
        $document = $client->embedDocuments([new EmbeddingDocument('Docker', '')])->first();

        self::assertEqualsWithDelta(0.0, EmbeddingFixtures::cosineDistance($query, $document), 1e-9);
    }

    public function test_words_sharing_five_letter_stem_match(): void
    {
        $client = new FakeEmbeddingClient();

        // „Dockeru“ i „docker“ mají kmen „docke“; „to“ je kratší než 3 znaky a nepočítá se.
        self::assertEqualsWithDelta(
            0.0,
            EmbeddingFixtures::cosineDistance($client->embedQuery('O Dockeru to')->first(), $client->embedQuery('docker')->first()),
            1e-9,
        );
    }

    public function test_stop_words_do_not_contribute_to_the_vector(): void
    {
        $client = new FakeEmbeddingClient();

        // „víte“ a „jak“ jsou stop-slova: kolize jejich hashe s kmenem v jiném textu už nevyrobí falešnou shodu.
        self::assertEquals(
            $client->embedQuery('kvasinky')->first()->values,
            $client->embedQuery('Co víte o kvasinkách? Jak, jak!')->first()->values,
        );
        // Stop-slovo uvnitř slova ani jiný tvar slova se nevynechává.
        self::assertNotEquals(
            $client->embedQuery('Jaký')->first()->values,
            $client->embedQuery('Jakýsi')->first()->values,
        );
        foreach (FakeEmbeddingClient::STOP_WORDS as $word) {
            self::assertSame($word, mb_strtolower($word), $word);
            self::assertGreaterThanOrEqual(3, mb_strlen($word), $word);
        }
    }

    public function test_tokens_are_estimated_from_all_inputs(): void
    {
        $client = new FakeEmbeddingClient();

        self::assertSame(intdiv(mb_strlen('Docker') + 3, 4), $client->embedQuery('Docker')->tokens);
        self::assertSame(
            intdiv(mb_strlen("Titulek\nText článku") + mb_strlen("\nŘ") + 3, 4),
            $client->embedDocuments([new EmbeddingDocument('Titulek', 'Text článku'), new EmbeddingDocument('', 'Ř')])->tokens,
        );
        self::assertGreaterThanOrEqual(0, $client->embedQuery('Docker')->durationMs);
    }

    public function test_empty_document_list_gives_no_vectors(): void
    {
        $result = new FakeEmbeddingClient()->embedDocuments([]);

        self::assertSame([], $result->vectors);
        self::assertSame(0, $result->tokens);
    }

    public function test_source_never_touches_network(): void
    {
        $source = (string) file_get_contents(AiFixtures::root() . '/src/Ai/Embedding/FakeEmbeddingClient.php');

        self::assertStringNotContainsString('HttpTransport', $source);
        self::assertStringNotContainsString('curl_', $source);
        self::assertStringNotContainsString('file_get_contents', $source);
        self::assertStringNotContainsString('fsockopen', $source);
    }
}
