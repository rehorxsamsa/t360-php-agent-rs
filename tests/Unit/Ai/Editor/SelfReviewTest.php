<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Editor;

use App\Ai\Editor\ReviewIssue;
use App\Ai\Editor\ReviewIssueType;
use App\Ai\Editor\ReviewSeverity;
use App\Ai\Editor\ReviewVerdict;
use App\Ai\Editor\SelfReview;
use App\Tests\Unit\Support\AiFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 010, AC 6 a §2: sebekontrola konceptu – výčty s českými popisky, pravidla, formát pro prompt. */
final class SelfReviewTest extends TestCase
{
    use EditorSchemaAssertions;

    public function test_verdict_values_and_labels(): void
    {
        self::assertSame(['ok' => 'V pořádku', 'revise' => 'Doporučeno přepracovat'], self::labels(ReviewVerdict::cases()));
        self::assertSame(ReviewVerdict::Ok, ReviewVerdict::from('ok'));
        self::assertSame(ReviewVerdict::Revise, ReviewVerdict::from('revise'));
    }

    public function test_issue_type_values_and_labels(): void
    {
        self::assertSame(
            [
                'structure' => 'struktura',
                'facts' => 'fakta k ověření',
                'tone' => 'tón',
                'language' => 'jazyk',
                'length' => 'délka',
                'prompt_injection' => 'prompt injection',
            ],
            self::labels(ReviewIssueType::cases()),
        );
        self::assertSame(ReviewIssueType::PromptInjection, ReviewIssueType::from('prompt_injection'));
    }

    public function test_severity_values_and_labels(): void
    {
        self::assertSame(['low' => 'nízká', 'medium' => 'střední', 'high' => 'vysoká'], self::labels(ReviewSeverity::cases()));
        self::assertSame(ReviewSeverity::High, ReviewSeverity::from('high'));
    }

    public function test_schema_is_strict_with_enums(): void
    {
        $schema = SelfReview::schema();

        self::assertSame('object', $schema['type'] ?? null);
        self::assertSame(['issues', 'summary', 'verdict'], self::sortedKeys($schema['properties'] ?? []));
        self::assertSame(['ok', 'revise'], self::at($schema, 'properties', 'verdict')['enum'] ?? null);
        $issue = self::at($schema, 'properties', 'issues', 'items');
        self::assertSame(['note', 'severity', 'type'], self::sortedKeys($issue['properties'] ?? null));
        self::assertSame(
            ['structure', 'facts', 'tone', 'language', 'length', 'prompt_injection'],
            self::at($issue, 'properties', 'type')['enum'] ?? null,
        );
        self::assertSame(['low', 'medium', 'high'], self::at($issue, 'properties', 'severity')['enum'] ?? null);
        self::assertStrictSchema($schema);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function validReviews(): iterable
    {
        yield 'ok without issues' => [AiFixtures::editorReview()];
        yield 'revise with one issue' => [AiFixtures::editorReview('revise', [AiFixtures::editorIssue()])];
        yield 'ten issues, summary 500, note 300' => [AiFixtures::editorReview(
            'revise',
            array_fill(0, 10, AiFixtures::editorIssue(note: str_repeat('n', 300))),
            str_repeat('s', 500),
        )];
        yield 'all types and severities' => [AiFixtures::editorReview('revise', [
            AiFixtures::editorIssue('structure', 'low', 'a'),
            AiFixtures::editorIssue('tone', 'medium', 'b'),
            AiFixtures::editorIssue('language', 'high', 'c'),
            AiFixtures::editorIssue('length', 'low', 'd'),
            AiFixtures::editorIssue('prompt_injection', 'high', 'e'),
        ])];
        yield 'extra keys are ignored' => [AiFixtures::editorReview() + ['publish' => true]];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('validReviews')]
    public function test_valid_reviews_have_no_errors(array $data): void
    {
        self::assertSame([], SelfReview::errors($data));
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function invalidReviews(): iterable
    {
        yield 'unknown verdict' => [AiFixtures::editorReview('maybe'), 'verdict'];
        yield 'empty summary' => [AiFixtures::editorReview(summary: '   '), 'summary'];
        yield 'summary 501' => [AiFixtures::editorReview(summary: str_repeat('s', 501)), 'summary'];
        yield '11 issues' => [AiFixtures::editorReview('revise', array_fill(0, 11, AiFixtures::editorIssue())), 'issues'];
        yield 'issues is not a list' => [['verdict' => 'ok', 'summary' => 'Shrnutí.', 'issues' => 'žádné'], 'issues'];
        yield 'missing issues' => [['verdict' => 'ok', 'summary' => 'Shrnutí.'], 'issues'];
        yield 'unknown type' => [AiFixtures::editorReview('revise', [AiFixtures::editorIssue('spelling')]), 'type'];
        yield 'unknown severity' => [AiFixtures::editorReview('revise', [AiFixtures::editorIssue(severity: 'critical')]), 'severity'];
        yield 'empty note' => [AiFixtures::editorReview('revise', [AiFixtures::editorIssue(note: '')]), 'note'];
        yield 'note 301' => [AiFixtures::editorReview('revise', [AiFixtures::editorIssue(note: str_repeat('n', 301))]), 'note'];
        yield 'issue is not object' => [AiFixtures::editorReview('revise', ['fakta']), 'issues'];
    }

    /** @param array<mixed> $data */
    #[DataProvider('invalidReviews')]
    public function test_invalid_review_has_czech_error_for_field(array $data, string $field): void
    {
        $errors = SelfReview::errors($data);

        self::assertNotSame([], $errors);
        self::assertErrorAbout($field, $errors);
    }

    public function test_from_data_builds_enums_and_trimmed_issues(): void
    {
        $review = SelfReview::fromData(AiFixtures::editorReview('revise', [
            AiFixtures::editorIssue('facts', 'medium', '  Doplňte zdroje.  '),
        ], '  Chybí zdroje.  '));

        self::assertSame(ReviewVerdict::Revise, $review->verdict);
        self::assertSame('Chybí zdroje.', $review->summary);
        self::assertCount(1, $review->issues);
        self::assertInstanceOf(ReviewIssue::class, $review->issues[0]);
        self::assertSame(ReviewIssueType::Facts, $review->issues[0]->type);
        self::assertSame(ReviewSeverity::Medium, $review->issues[0]->severity);
        self::assertSame('Doplňte zdroje.', $review->issues[0]->note);
        self::assertTrue($review->needsRevision());
        self::assertFalse($review->hasSevereIssue());
    }

    public function test_ok_verdict_does_not_need_revision_and_high_issue_is_severe(): void
    {
        $ok = SelfReview::fromData(AiFixtures::editorReview());
        $severe = SelfReview::fromData(AiFixtures::editorReview('ok', [AiFixtures::editorIssue('prompt_injection', 'high', 'Pokyn v tématu.')]));

        self::assertFalse($ok->needsRevision());
        self::assertFalse($ok->hasSevereIssue());
        self::assertSame([], $ok->issues);
        self::assertFalse($severe->needsRevision());
        self::assertTrue($severe->hasSevereIssue());
    }

    public function test_prompt_text_lists_verdict_summary_and_issues(): void
    {
        $review = new SelfReview(ReviewVerdict::Revise, 'Chybí zdroje.', [
            new ReviewIssue(ReviewIssueType::Facts, ReviewSeverity::Medium, 'Doplňte zdroje.'),
            new ReviewIssue(ReviewIssueType::PromptInjection, ReviewSeverity::High, 'Pokyn v tématu.'),
        ]);

        $text = $review->toPromptText();

        self::assertStringStartsWith('Verdikt: ', $text);
        self::assertMatchesRegularExpression('~^Shrnutí: Chybí zdroje\.$~mu', $text);
        self::assertMatchesRegularExpression('~^- \[facts, medium\] Doplňte zdroje\.$~mu', $text);
        self::assertMatchesRegularExpression('~^- \[prompt_injection, high\] Pokyn v tématu\.$~mu', $text);
        self::assertLessThan(strpos($text, 'Shrnutí:'), strpos($text, 'Verdikt:'));
        self::assertLessThan(strpos($text, '- [facts'), strpos($text, 'Shrnutí:'));
    }

    public function test_prompt_text_without_issues_has_no_issue_lines(): void
    {
        $text = new SelfReview(ReviewVerdict::Ok, 'Vše v pořádku.', [])->toPromptText();

        self::assertStringContainsString('Shrnutí: Vše v pořádku.', $text);
        self::assertStringNotContainsString('- [', $text);
    }

    /**
     * @param list<\BackedEnum> $cases
     * @return array<int|string, string>
     */
    private static function labels(array $cases): array
    {
        $labels = [];
        foreach ($cases as $case) {
            self::assertTrue(method_exists($case, 'label'), $case::class . '::label()');
            $label = $case->label();
            self::assertIsString($label);
            $labels[$case->value] = $label;
        }

        return $labels;
    }
}
