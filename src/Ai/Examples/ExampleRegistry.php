<?php

declare(strict_types=1);

namespace App\Ai\Examples;

/**
 * Přehled AI příkladů. `all()`/`get()` vracejí jen „článkové“ příklady 01–05 (spouští je `ExampleRunner`),
 * `listing()` všechny příklady 01–09 pro přehled v administraci (06–09 mají vlastní stránky a vstup).
 */
final readonly class ExampleRegistry
{
    /** @var list<AiExample> */
    private array $examples;

    /** @var list<ExampleDescription> */
    private array $listing;

    public function __construct(
        Example01Excerpt $excerpt,
        Example02Seo $seo,
        Example03Classification $classification,
        Example04Review $review,
        Example05Translation $translation,
        Example06WritingAssistant $writing,
        Example07AskNewsroom $askNewsroom,
        Example08SemanticSearch $semanticSearch,
        Example09AiEditor $aiEditor,
    ) {
        $this->examples = [$excerpt, $seo, $classification, $review, $translation];
        $this->listing = [...$this->examples, $writing, $askNewsroom, $semanticSearch, $aiEditor];
    }

    /** @return list<ExampleDescription> příklady 01–09 seřazené podle čísla */
    public function listing(): array
    {
        return $this->listing;
    }

    /** @return list<AiExample> seřazené podle čísla */
    public function all(): array
    {
        return $this->examples;
    }

    /** Přesná shoda čísla (`01`); `1` ani `001` příklad nenajdou. */
    public function get(string $id): ?AiExample
    {
        foreach ($this->examples as $example) {
            if ($example->id() === $id) {
                return $example;
            }
        }

        return null;
    }
}
