<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Ai\Embedding\EmbeddingClient;
use App\Ai\Embedding\EmbeddingDocument;
use App\Ai\Embedding\EmbeddingResult;

/**
 * Skriptovaný klient embeddingů (plán 009, §6): fronta výsledků/výjimek společná pro `embedDocuments`
 * i `embedQuery`, zaznamenává vstupy. S `delegatingTo()` po vyčerpání fronty předá volání vnitřnímu klientovi
 * (např. FakeEmbeddingClient) a převezme i jeho `model()` a `provider()`. Prázdná fronta bez vnitřního klienta
 * = chyba testu.
 */
final class ScriptedEmbeddingClient implements EmbeddingClient
{
    /** @var list<EmbeddingResult|\Throwable> */
    private array $queue = [];

    /** @var list<list<EmbeddingDocument>> dávky dokumentů v pořadí volání */
    public array $documentBatches = [];

    /** @var list<string> dotazy v pořadí volání */
    public array $queries = [];

    public function __construct(
        private readonly string $model = EmbeddingFixtures::FAKE_MODEL,
        private readonly string $provider = 'fake',
        private readonly ?EmbeddingClient $fallback = null,
    ) {}

    public static function delegatingTo(EmbeddingClient $inner): self
    {
        return new self($inner->model(), $inner->provider(), $inner);
    }

    public function push(EmbeddingResult|\Throwable ...$items): self
    {
        foreach ($items as $item) {
            $this->queue[] = $item;
        }

        return $this;
    }

    public function calls(): int
    {
        return count($this->documentBatches) + count($this->queries);
    }

    public function model(): string
    {
        return $this->model;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function embedDocuments(array $documents): EmbeddingResult
    {
        $this->documentBatches[] = $documents;

        return $this->next(static fn(EmbeddingClient $inner): EmbeddingResult => $inner->embedDocuments($documents));
    }

    public function embedQuery(string $query): EmbeddingResult
    {
        $this->queries[] = $query;

        return $this->next(static fn(EmbeddingClient $inner): EmbeddingResult => $inner->embedQuery($query));
    }

    /** @param \Closure(EmbeddingClient): EmbeddingResult $delegate */
    private function next(\Closure $delegate): EmbeddingResult
    {
        $next = array_shift($this->queue);
        if ($next === null) {
            if ($this->fallback === null) {
                throw new \LogicException('Skriptovaný klient embeddingů nemá další odpověď.');
            }

            return $delegate($this->fallback);
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
