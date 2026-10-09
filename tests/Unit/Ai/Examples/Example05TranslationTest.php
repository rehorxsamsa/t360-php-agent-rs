<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Examples;

use App\Ai\Examples\ArticleSnapshot;
use App\Ai\Examples\InvalidExampleInput;
use App\Ai\Examples\InvalidModelOutput;
use App\Tests\Unit\Support\AiFixtures;

/** Plán 006, AC 18: příklad 05 – překlad CZ → EN s volbou modelu a kontrolou struktury Markdownu. */
final class Example05TranslationTest extends ExampleTestCase
{
    private const string SOURCE_BODY = "## Úvod\n\nKrátký úvod.\n\n## Postup\n\n- první\n- druhý\n- třetí\n\n```php\necho 1;\n```";
    private const string SAME_STRUCTURE = "## Introduction\n\nShort intro.\n\n## Steps\n\n- first\n- second\n- third\n\n```php\necho 1;\n```";
    private const string ONE_HEADING = "## Introduction\n\nShort intro.\n\nSteps:\n\n- first\n- second\n- third\n\n```php\necho 1;\n```";

    private static function translation(string $body = self::SAME_STRUCTURE, string $title = 'Docker for beginners', string $excerpt = 'Short excerpt.'): string
    {
        return self::json(['title' => $title, 'excerpt' => $excerpt, 'body' => $body]);
    }

    private static function source(): ArticleSnapshot
    {
        return AiFixtures::snapshot('Docker pro začátečníky', 'docker-pro-zacatecniky', 'Krátký perex.', self::SOURCE_BODY);
    }

    public function test_fields_keep_original_slug(): void
    {
        $this->llm->pushText(self::translation());

        $result = $this->runExample('05', self::source(), AiFixtures::SONNET);

        self::assertSame(
            [
                ['label' => 'Titulek (EN)', 'value' => 'Docker for beginners'],
                ['label' => 'Perex (EN)', 'value' => 'Short excerpt.'],
                ['label' => 'Slug', 'value' => 'docker-pro-zacatecniky'],
                ['label' => 'Text (EN, Markdown)', 'value' => self::SAME_STRUCTURE],
            ],
            $result->fields,
        );
        self::assertSame([], $result->warnings);
    }

    public function test_lost_heading_produces_structure_warning(): void
    {
        $this->llm->pushText(self::translation(self::ONE_HEADING));

        $result = $this->runExample('05', self::source(), AiFixtures::SONNET);

        self::assertSame(['Překlad nezachoval strukturu Markdownu: nadpisy 2 → 1.'], $result->warnings);
        self::assertSame(1, $result->calls);
    }

    public function test_cheap_model_can_be_chosen(): void
    {
        $this->llm->pushText(self::translation());

        $this->runExample('05', self::source(), AiFixtures::HAIKU);

        self::assertSame(AiFixtures::HAIKU, $this->lastRequest()->model);
    }

    /** PŘEDPOKLAD: prázdná volba modelu (výchozí ExampleContext, konzole bez --model) = AI_MODEL. */
    public function test_empty_model_choice_uses_default_model(): void
    {
        $this->llm->pushText(self::translation());

        $this->runExample('05', self::source(), '');

        self::assertSame(AiFixtures::SONNET, $this->lastRequest()->model);
    }

    public function test_model_outside_choices_is_rejected_without_calling_llm(): void
    {
        try {
            $this->runExample('05', self::source(), 'gpt-4o');
            self::fail('Očekávána výjimka InvalidExampleInput.');
        } catch (InvalidExampleInput $exception) {
            self::assertSame('Vyberte model ze seznamu.', $exception->getMessage());
        }

        self::assertSame([], $this->llm->requests);
    }

    /** Plán 012, AC 11: volby jsou Sonnet 5.5 a Haiku 5.5. */
    public function test_model_choices_are_sonnet_and_haiku_5_5(): void
    {
        self::assertSame(['claude-sonnet-5-5', 'claude-haiku-5-5'], $this->example('05')->modelChoices());
    }

    /** Plán 012, AC 11: legacy Haiku 4.5 není nakonfigurovaný levný model, takže ho výběr odmítne bez volání LLM. */
    public function test_legacy_haiku_is_rejected_without_calling_llm(): void
    {
        try {
            $this->runExample('05', self::source(), AiFixtures::LEGACY_HAIKU);
            self::fail('Očekávána výjimka InvalidExampleInput.');
        } catch (InvalidExampleInput $exception) {
            self::assertSame('Vyberte model ze seznamu.', $exception->getMessage());
        }

        self::assertSame([], $this->llm->requests);
    }

    public function test_model_choices_are_offered_only_by_translation(): void
    {
        self::assertSame([AiFixtures::SONNET, AiFixtures::HAIKU], $this->example('05')->modelChoices());
        foreach (['01', '02', '03', '04'] as $id) {
            self::assertSame([], $this->example($id)->modelChoices(), $id);
        }
    }

    public function test_invalid_translation_is_retried_then_fails(): void
    {
        $this->llm->pushText('není JSON', '{"title": 1}');

        $this->expectException(InvalidModelOutput::class);
        $this->expectExceptionMessage('Model ani na druhý pokus nevrátil platná data: ');

        $this->runExample('05', self::source(), AiFixtures::SONNET);
    }
}
