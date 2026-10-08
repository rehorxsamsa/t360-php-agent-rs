<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Embedding;

use App\Ai\Embedding\EmbeddingClient;
use App\Ai\Embedding\EmbeddingDocument;
use App\Ai\Embedding\EmbeddingFailed;
use App\Ai\Embedding\OllamaEmbeddingClient;
use App\Tests\Unit\Support\AiFixtures;
use App\Tests\Unit\Support\ScriptedHttpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 009, AC 2–4: adaptér Ollamy (`POST /api/embed`) nad skriptovaným transportem, bez sítě. */
final class OllamaEmbeddingClientTest extends TestCase
{
    private const string URL = 'http://ollama:11434';
    private const string INVALID = 'Ollama vrátila neplatnou odpověď.';

    private ScriptedHttpTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new ScriptedHttpTransport();
    }

    private function client(): OllamaEmbeddingClient
    {
        return new OllamaEmbeddingClient($this->transport, self::URL, 'embeddinggemma');
    }

    /** @return list<float> */
    private static function vector(float $value, int $dimensions = 768): array
    {
        return array_fill(0, $dimensions, $value);
    }

    /** @param list<list<float>> $embeddings */
    private function pushEmbeddings(array $embeddings, ?int $promptEvalCount = 9): void
    {
        $body = ['model' => 'embeddinggemma', 'embeddings' => $embeddings, 'total_duration' => 1000, 'load_duration' => 10];
        if ($promptEvalCount !== null) {
            $body['prompt_eval_count'] = $promptEvalCount;
        }
        $this->transport->push(ScriptedHttpTransport::json(200, $body));
    }

    /** @return array<mixed> */
    private function sentBody(int $index = 0): array
    {
        $body = json_decode($this->transport->requests[$index]['body'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    // ---------------------------------------------------------------- AC 2: tvar požadavku

    public function test_identifies_model_and_provider(): void
    {
        $client = $this->client();

        self::assertInstanceOf(EmbeddingClient::class, $client);
        self::assertSame('embeddinggemma', $client->model());
        self::assertSame('ollama', $client->provider());
    }

    public function test_documents_are_sent_in_one_request_with_document_format(): void
    {
        $this->pushEmbeddings([self::vector(0.1), self::vector(0.2)]);

        $this->client()->embedDocuments([new EmbeddingDocument('T', 'X'), new EmbeddingDocument('', 'Y')]);

        self::assertCount(1, $this->transport->requests);
        $request = $this->transport->requests[0];
        self::assertSame('http://ollama:11434/api/embed', $request['url']);
        self::assertFalse($request['stream']);
        self::assertSame('application/json', array_change_key_case($request['headers'])['content-type'] ?? null);
        self::assertEquals(
            ['model' => 'embeddinggemma', 'input' => ['title: T | text: X', 'title: none | text: Y'], 'truncate' => true],
            $this->sentBody(),
        );
    }

    public function test_query_is_sent_with_search_prefix(): void
    {
        $this->pushEmbeddings([self::vector(0.1)]);

        $this->client()->embedQuery('Q');

        self::assertSame('http://ollama:11434/api/embed', $this->transport->requests[0]['url']);
        self::assertEquals(
            ['model' => 'embeddinggemma', 'input' => ['task: search result | query: Q'], 'truncate' => true],
            $this->sentBody(),
        );
    }

    public function test_custom_prefix_and_document_format_are_constructor_parameters(): void
    {
        $this->pushEmbeddings([self::vector(0.1)]);
        $this->pushEmbeddings([self::vector(0.1)]);
        $client = new OllamaEmbeddingClient($this->transport, self::URL, 'nomic-embed-text', 'search_query: ', 'search_document: %s %s');

        $client->embedQuery('Q');
        $client->embedDocuments([new EmbeddingDocument('T', 'X')]);

        self::assertSame(['search_query: Q'], $this->sentBody(0)['input'] ?? null);
        self::assertSame(['search_document: T X'], $this->sentBody(1)['input'] ?? null);
        self::assertSame('nomic-embed-text', $this->sentBody(1)['model'] ?? null);
    }

    public function test_empty_document_list_does_not_call_transport(): void
    {
        $result = $this->client()->embedDocuments([]);

        self::assertSame([], $result->vectors);
        self::assertSame([], $this->transport->requests);
    }

    // ---------------------------------------------------------------- AC 3: odpověď

    public function test_response_vectors_keep_input_order_with_tokens_and_duration(): void
    {
        $this->pushEmbeddings([self::vector(0.1), self::vector(0.2)], 9);

        $result = $this->client()->embedDocuments([new EmbeddingDocument('T', 'X'), new EmbeddingDocument('', 'Y')]);

        self::assertCount(2, $result->vectors);
        self::assertSame(768, $result->vectors[0]->dimensions());
        self::assertEqualsWithDelta(0.1, (float) $result->vectors[0]->values[0], 1e-12);
        self::assertEqualsWithDelta(0.2, (float) $result->vectors[1]->values[767], 1e-12);
        self::assertSame(9, $result->tokens);
        self::assertGreaterThanOrEqual(0, $result->durationMs);
    }

    public function test_missing_prompt_eval_count_means_zero_tokens(): void
    {
        $this->pushEmbeddings([self::vector(0.3)], null);

        $result = $this->client()->embedQuery('Q');

        self::assertSame(0, $result->tokens);
        self::assertEqualsWithDelta(0.3, (float) $result->first()->values[5], 1e-12);
    }

    // ---------------------------------------------------------------- AC 4: chyby

    public function test_transport_failure_says_ollama_is_not_running(): void
    {
        $this->transport->push(AiFixtures::transportFailed(false));

        $this->assertFails('Služba embeddingů (Ollama) neodpovídá na http://ollama:11434 – spusťte ji: make ai-local.', fn() => $this->client()->embedQuery('Q'));
    }

    public function test_timeout_is_also_reported_as_not_responding(): void
    {
        $this->transport->push(AiFixtures::transportFailed(true));

        $this->assertFails('Služba embeddingů (Ollama) neodpovídá na http://ollama:11434 – spusťte ji: make ai-local.', fn() => $this->client()->embedQuery('Q'));
    }

    public function test_http_404_says_model_is_missing(): void
    {
        $this->transport->push(ScriptedHttpTransport::json(404, ['error' => 'model "embeddinggemma" not found, try pulling it first']));

        $this->assertFails('Model embeddinggemma v Ollamě chybí – stáhněte ho: make ai-local.', fn() => $this->client()->embedQuery('Q'));
    }

    public function test_other_http_error_reports_status(): void
    {
        $this->transport->push(ScriptedHttpTransport::json(500, ['error' => 'internal']));

        $this->assertFails('Ollama vrátila chybu (HTTP 500).', fn() => $this->client()->embedQuery('Q'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBodies(): iterable
    {
        $vector = json_encode(array_fill(0, 768, 0.1), JSON_THROW_ON_ERROR);

        yield 'not JSON' => ['<html>bad gateway</html>'];
        yield 'JSON scalar' => ['42'];
        yield 'missing embeddings' => ['{"model":"embeddinggemma"}'];
        yield 'embeddings not a list' => ['{"embeddings":"x"}'];
        yield 'fewer vectors than inputs' => ['{"embeddings":[' . $vector . ']}'];
        yield 'more vectors than inputs' => ['{"embeddings":[' . $vector . ',' . $vector . ',' . $vector . ']}'];
        yield 'empty vector' => ['{"embeddings":[' . $vector . ',[]]}'];
        yield 'vector not a list' => ['{"embeddings":[' . $vector . ',"abc"]}'];
        yield 'non-numeric component' => ['{"embeddings":[' . $vector . ',[0.1,"x",0.3]]}'];
        yield 'null component' => ['{"embeddings":[' . $vector . ',[0.1,null]]}'];
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_response_is_reported_as_invalid(string $body): void
    {
        $this->transport->push(ScriptedHttpTransport::raw(200, $body, ['content-type' => 'application/json']));

        $this->assertFails(self::INVALID, fn() => $this->client()->embedDocuments([new EmbeddingDocument('T', 'X'), new EmbeddingDocument('', 'Y')]));
    }

    private function assertFails(string $message, callable $action): void
    {
        try {
            $action();
            self::fail('Očekávána výjimka EmbeddingFailed.');
        } catch (EmbeddingFailed $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
