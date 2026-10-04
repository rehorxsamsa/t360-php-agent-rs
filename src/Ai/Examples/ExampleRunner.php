<?php

declare(strict_types=1);

namespace App\Ai\Examples;

use App\Domain\Article\AdminArticleSummary;
use App\Domain\Article\ArticleAdminRepository;

/**
 * Jediný vstupní bod pro spuštění příkladu z HTTP i z konzole: najde článek, zkontroluje
 * délku textu (cena roste s délkou) a teprve potom zavolá příklad. Při chybě vstupu se LLM nevolá.
 */
final readonly class ExampleRunner
{
    private const int CHOICES_LIMIT = 50;
    private const int MAX_BODY_LENGTH = 30000;
    private const int MAX_BODY_LENGTH_TRANSLATION = 10000;

    public function __construct(
        private ExampleRegistry $registry,
        private ArticleAdminRepository $articles,
    ) {}

    /** @return list<AdminArticleSummary> články nabízené ve výběru (nejnověji upravené) */
    public function articleChoices(): array
    {
        return $this->articles->list(self::CHOICES_LIMIT, 0);
    }

    /**
     * @param string $articleSource `demo`, `demo-injection`, nebo ID článku (kladné celé číslo bez nul na začátku)
     * @throws InvalidExampleInput
     */
    public function loadArticle(string $exampleId, string $articleSource): ArticleSnapshot
    {
        $article = match ($articleSource) {
            DemoArticles::STANDARD => DemoArticles::standard(),
            DemoArticles::INJECTION => DemoArticles::injection(),
            default => $this->loadFromDatabase($articleSource),
        };

        if (trim($article->body) === '') {
            throw new InvalidExampleInput('Článek nemá text.');
        }

        $limit = $exampleId === '05' ? self::MAX_BODY_LENGTH_TRANSLATION : self::MAX_BODY_LENGTH;
        if (mb_strlen($article->body) > $limit) {
            throw new InvalidExampleInput(sprintf(
                'Text článku je pro AI ukázku příliš dlouhý (max. %s znaků).',
                number_format($limit, 0, ',', ' '),
            ));
        }

        return $article;
    }

    /**
     * @throws InvalidExampleInput neznámý příklad, článek nebo model
     * @throws InvalidModelOutput
     * @throws \App\Ai\LlmCallFailed
     * @throws \App\Ai\AiBudgetExceeded
     */
    public function run(string $exampleId, string $articleSource, ExampleContext $context): ExampleResult
    {
        $example = $this->registry->get($exampleId) ?? throw new InvalidExampleInput('Neznámý příklad.');

        return $example->run($this->loadArticle($exampleId, $articleSource), $context);
    }

    private function loadFromDatabase(string $articleSource): ArticleSnapshot
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/', $articleSource) !== 1) {
            throw new InvalidExampleInput('Vyberte článek.');
        }

        $article = $this->articles->findForEditing((int) $articleSource);
        if ($article === null) {
            throw new InvalidExampleInput(sprintf('Článek %s neexistuje.', $articleSource));
        }

        return new ArticleSnapshot($article->title, $article->slug, $article->excerpt, $article->body);
    }
}
