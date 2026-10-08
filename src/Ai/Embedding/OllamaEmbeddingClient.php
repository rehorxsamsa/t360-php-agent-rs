<?php

declare(strict_types=1);

namespace App\Ai\Embedding;

use App\Ai\Client\HttpTransport;
use App\Ai\Client\TransportFailed;
use App\Domain\Ai\Embedding;

/**
 * Embeddingy z lokální Ollamy (`POST {url}/api/embed`, ADR-0009). Model embeddinggemma čeká předpony:
 * dotaz `task: search result | query: …`, dokument `title: … | text: …` (prázdný titulek = `none`).
 * Odpověď serveru je nedůvěryhodná: tvar i čísla se kontrolují a chyba je vždy {@see EmbeddingFailed}.
 */
final readonly class OllamaEmbeddingClient implements EmbeddingClient
{
    public function __construct(
        private HttpTransport $transport,
        private string $baseUrl,
        private string $model,
        private string $queryPrefix = 'task: search result | query: ',
        private string $documentFormat = 'title: %s | text: %s',
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function provider(): string
    {
        return EmbeddingProvider::Ollama->logName();
    }

    public function embedDocuments(array $documents): EmbeddingResult
    {
        return $this->embed(array_map(
            fn(EmbeddingDocument $document): string => sprintf(
                $this->documentFormat,
                trim($document->title) === '' ? 'none' : trim($document->title),
                $document->text,
            ),
            $documents,
        ));
    }

    public function embedQuery(string $query): EmbeddingResult
    {
        return $this->embed([$this->queryPrefix . $query]);
    }

    /** @param list<string> $inputs */
    private function embed(array $inputs): EmbeddingResult
    {
        if ($inputs === []) {
            return new EmbeddingResult([], 0, 0);
        }

        $body = json_encode(
            ['model' => $this->model, 'input' => $inputs, 'truncate' => true],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $startedAt = hrtime(true);
        try {
            $result = $this->transport->post(
                rtrim($this->baseUrl, '/') . '/api/embed',
                ['content-type' => 'application/json'],
                $body,
            );
        } catch (TransportFailed) {
            throw new EmbeddingFailed(sprintf(
                'Služba embeddingů (Ollama) neodpovídá na %s – spusťte ji: make ai-local.',
                $this->baseUrl,
            ));
        }
        $durationMs = intdiv(hrtime(true) - $startedAt, 1_000_000);

        if ($result->status === 404) {
            throw new EmbeddingFailed(sprintf('Model %s v Ollamě chybí – stáhněte ho: make ai-local.', $this->model));
        }

        if ($result->status < 200 || $result->status >= 300) {
            throw new EmbeddingFailed(sprintf('Ollama vrátila chybu (HTTP %d).', $result->status));
        }

        $data = $this->decode($result->body);
        $tokens = $data['prompt_eval_count'] ?? 0;

        return new EmbeddingResult(
            $this->parseVectors($data, count($inputs)),
            is_int($tokens) && $tokens > 0 ? $tokens : 0,
            $durationMs,
        );
    }

    /**
     * @param array<mixed> $data
     * @return list<Embedding>
     * @throws EmbeddingFailed
     */
    private function parseVectors(array $data, int $expected): array
    {
        $embeddings = $data['embeddings'] ?? null;
        if (!is_array($embeddings) || !array_is_list($embeddings) || count($embeddings) !== $expected) {
            throw $this->invalidResponse();
        }

        $vectors = [];
        foreach ($embeddings as $values) {
            if (!is_array($values) || $values === [] || !array_is_list($values)) {
                throw $this->invalidResponse();
            }

            $floats = [];
            foreach ($values as $value) {
                if (!is_int($value) && !is_float($value)) {
                    throw $this->invalidResponse();
                }
                $floats[] = (float) $value;
            }

            $vectors[] = new Embedding($floats);
        }

        return $vectors;
    }

    /**
     * @return array<mixed>
     * @throws EmbeddingFailed
     */
    private function decode(string $body): array
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $this->invalidResponse();
        }

        return is_array($data) ? $data : throw $this->invalidResponse();
    }

    private function invalidResponse(): EmbeddingFailed
    {
        return new EmbeddingFailed('Ollama vrátila neplatnou odpověď.');
    }
}
