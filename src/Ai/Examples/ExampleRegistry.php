<?php

declare(strict_types=1);

namespace App\Ai\Examples;

/** Přehled AI příkladů 01–05 (příklady 06–10 přibudou v M7). */
final readonly class ExampleRegistry
{
    /** @var list<AiExample> */
    private array $examples;

    public function __construct(
        Example01Excerpt $excerpt,
        Example02Seo $seo,
        Example03Classification $classification,
        Example04Review $review,
        Example05Translation $translation,
    ) {
        $this->examples = [$excerpt, $seo, $classification, $review, $translation];
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
