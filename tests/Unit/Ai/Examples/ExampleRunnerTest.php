<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Examples;

use App\Ai\Examples\DemoArticles;
use App\Ai\Examples\ExampleContext;
use App\Ai\Examples\ExampleRunner;
use App\Ai\Examples\InvalidExampleInput;
use App\Ai\PromptData;
use App\Domain\Article\AdminArticleSummary;
use App\Domain\Article\ArticleStatus;
use App\Tests\Unit\Support\AiFixtures;
use App\Tests\Unit\Support\InMemoryArticleAdminRepository;
use PHPUnit\Framework\Attributes\DataProvider;

/** Plán 006, AC 19: výběr zdroje článku a limity délky textu před voláním LLM. */
final class ExampleRunnerTest extends ExampleTestCase
{
    private function runner(): ExampleRunner
    {
        return $this->container->get(ExampleRunner::class);
    }

    private function userMessage(): string
    {
        return $this->lastRequest()->messages[0]['content'];
    }

    public function test_demo_source_uses_standard_demo_article(): void
    {
        $this->llm->pushText('Perex.');

        $result = $this->runner()->run('01', 'demo', new ExampleContext(7));

        self::assertSame('01', $result->exampleId);
        self::assertPrefix(PromptData::article(DemoArticles::standard()), $this->userMessage());
    }

    public function test_demo_injection_source_uses_injection_article(): void
    {
        $this->llm->pushText('Perex.');

        $this->runner()->run('01', 'demo-injection', new ExampleContext(7));

        self::assertPrefix(PromptData::article(DemoArticles::injection()), $this->userMessage());
    }

    public function test_numeric_source_loads_article_for_editing(): void
    {
        $this->articles->add(InMemoryArticleAdminRepository::editable(
            5,
            title: 'Článek z databáze',
            slug: 'clanek-z-databaze',
            excerpt: 'Perex z DB.',
            body: 'Text z DB.',
        ));
        $this->llm->pushText('Perex.');

        $this->runner()->run('01', '5', new ExampleContext(7));

        self::assertPrefix(
            PromptData::article(AiFixtures::snapshot('Článek z databáze', 'clanek-z-databaze', 'Perex z DB.', 'Text z DB.')),
            $this->userMessage(),
        );
        self::assertSame(7, $this->lastRequest()->userId);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSources(): iterable
    {
        yield 'empty' => [''];
        yield 'letters' => ['abc'];
        yield 'leading zero' => ['05'];
        yield 'negative' => ['-5'];
        yield 'zero' => ['0'];
    }

    #[DataProvider('invalidSources')]
    public function test_invalid_source_is_rejected_without_calling_llm(string $source): void
    {
        $this->articles->add(InMemoryArticleAdminRepository::editable(5, body: 'Text.'));

        $this->assertRejected('01', $source, 'Vyberte článek.');
    }

    public function test_missing_article_is_rejected(): void
    {
        $this->assertRejected('01', '404', 'Článek 404 neexistuje.');
    }

    public function test_article_without_text_is_rejected(): void
    {
        $this->articles->add(InMemoryArticleAdminRepository::editable(5, body: ''));

        $this->assertRejected('02', '5', 'Článek nemá text.');
    }

    /** @return iterable<string, array{string}> */
    public static function examples01To04(): iterable
    {
        foreach (['01', '02', '03', '04'] as $id) {
            yield $id => [$id];
        }
    }

    #[DataProvider('examples01To04')]
    public function test_text_over_30000_characters_is_rejected(string $id): void
    {
        $this->articles->add(InMemoryArticleAdminRepository::editable(5, body: str_repeat('č', 30001)));

        $this->assertRejected($id, '5', 'Text článku je pro AI ukázku příliš dlouhý (max. 30 000 znaků).');
    }

    public function test_text_of_30000_characters_is_accepted(): void
    {
        $this->articles->add(InMemoryArticleAdminRepository::editable(5, body: str_repeat('č', 30000)));
        $this->llm->pushText('Perex.');

        $this->runner()->run('01', '5', new ExampleContext(7));

        self::assertCount(1, $this->llm->requests);
    }

    public function test_translation_text_over_10000_characters_is_rejected(): void
    {
        $this->articles->add(InMemoryArticleAdminRepository::editable(5, body: str_repeat('ř', 10001)));

        $this->assertRejected('05', '5', 'Text článku je pro AI ukázku příliš dlouhý (max. 10 000 znaků).', AiFixtures::SONNET);
    }

    public function test_translation_text_of_10000_characters_is_accepted(): void
    {
        $this->articles->add(InMemoryArticleAdminRepository::editable(5, body: str_repeat('ř', 10000)));
        $this->llm->pushText(self::json(['title' => 'T', 'excerpt' => 'E', 'body' => 'B']));

        $this->runner()->run('05', '5', new ExampleContext(7, AiFixtures::SONNET));

        self::assertCount(1, $this->llm->requests);
    }

    public function test_article_choices_are_first_fifty_from_admin_list(): void
    {
        $zone = new \DateTimeZone('Europe/Prague');
        $summary = new AdminArticleSummary(5, 'Článek', 'clanek', ArticleStatus::Draft, 'Technologie', null, new \DateTimeImmutable('2026-10-01 09:00:00', $zone), null);
        $this->articles->summaries = [$summary];

        $choices = $this->runner()->articleChoices();

        self::assertSame([$summary], $choices);
        self::assertSame([['limit' => 50, 'offset' => 0]], $this->articles->listCalls);
    }

    private function assertRejected(string $exampleId, string $source, string $message, string $model = ''): void
    {
        try {
            $this->runner()->run($exampleId, $source, new ExampleContext(7, $model));
            self::fail('Očekávána výjimka InvalidExampleInput.');
        } catch (InvalidExampleInput $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertSame([], $this->llm->requests, 'LLM nesmí být volán.');
    }
}
