<?php

declare(strict_types=1);

namespace App\Ai\Editor;

use App\Ai\Examples\Example09AiEditor;
use App\Ai\Examples\ExampleResult;

/**
 * Návrh AI redaktoru ke schválení: téma, hotový koncept a výsledek pro zobrazení (průběh, nálezy, cena).
 * Je to jen data – žádný stav publikace ani odkaz na článek. Dá se uložit do session a zpět načíst;
 * `fromArray()` data z úložiště bere jako nedůvěryhodná.
 */
final readonly class DraftProposal
{
    public function __construct(
        public string $topic,
        public ArticleDraft $draft,
        public ExampleResult $result,
    ) {}

    /**
     * @return array{
     *     topic: string,
     *     draft: array{title: string, excerpt: string, body: string},
     *     result: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'topic' => $this->topic,
            'draft' => [
                'title' => $this->draft->title,
                'excerpt' => $this->draft->excerpt,
                'body' => $this->draft->body,
            ],
            'result' => $this->result->toArray(),
        ];
    }

    /**
     * @param array<mixed> $data
     * @return self|null null při neplatném tvaru, porušení pravidel konceptu nebo jiném `exampleId` než `09`
     */
    public static function fromArray(array $data): ?self
    {
        $topic = $data['topic'] ?? null;
        $draft = $data['draft'] ?? null;
        $result = $data['result'] ?? null;

        if (
            !is_string($topic)
            || mb_strlen($topic) > Example09AiEditor::TOPIC_MAX
            || !is_array($draft)
            || !is_array($result)
            || ArticleDraft::errors($draft) !== []
        ) {
            return null;
        }

        $result = ExampleResult::fromArray($result);
        if ($result === null || $result->exampleId !== '09') {
            return null;
        }

        // Hodnoty se nemění (už prošly `errors()`), aby `toArray()` → `fromArray()` vrátilo rovnocenný návrh.
        $title = $draft['title'] ?? '';
        $excerpt = $draft['excerpt'] ?? '';
        $body = $draft['body'] ?? '';

        return new self(
            $topic,
            new ArticleDraft(is_string($title) ? $title : '', is_string($excerpt) ? $excerpt : '', is_string($body) ? $body : ''),
            $result,
        );
    }
}
