<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Editor;

use App\Ai\Editor\ArticleDraft;
use App\Tests\Unit\Support\AiFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 010, AC 6 a §2: koncept článku – limity, mezititulky, žádný stav, formát pro prompt. */
final class ArticleDraftTest extends TestCase
{
    use EditorSchemaAssertions;

    private const string SUBHEADING_ERROR = 'body: alespoň 2 mezititulky ##';

    /**
     * Text dané délky se dvěma mezititulky `## `.
     */
    private static function body(int $length, int $subheadings = 2): string
    {
        $text = '';
        for ($i = 1; $i <= $subheadings; $i++) {
            $text .= ($i > 1 ? "\n\n" : '') . '## Mezititulek ' . $i . "\n\n";
        }
        $missing = $length - mb_strlen($text);

        return $text . str_repeat('a', max(0, $missing));
    }

    public function test_limits_are_public_constants(): void
    {
        self::assertSame(10, ArticleDraft::TITLE_MIN);
        self::assertSame(200, ArticleDraft::TITLE_MAX);
        self::assertSame(50, ArticleDraft::EXCERPT_MIN);
        self::assertSame(300, ArticleDraft::EXCERPT_MAX);
        self::assertSame(600, ArticleDraft::BODY_MIN);
        self::assertSame(4000, ArticleDraft::BODY_MAX);
        self::assertSame(2, ArticleDraft::MIN_SUBHEADINGS);
    }

    public function test_schema_is_strict_object_with_title_excerpt_and_body(): void
    {
        $schema = ArticleDraft::schema();

        self::assertSame('object', $schema['type'] ?? null);
        self::assertSame(['body', 'excerpt', 'title'], self::sortedKeys($schema['properties'] ?? []));
        foreach (['title', 'excerpt', 'body'] as $key) {
            self::assertSame('string', self::at($schema, 'properties', $key)['type'] ?? null, $key);
        }
        $description = self::at($schema, 'properties', 'body')['description'] ?? null;
        self::assertIsString($description);
        self::assertStringContainsString('##', $description, 'Popis body zmiňuje mezititulky ##.');
        self::assertArrayNotHasKey('status', self::at($schema, 'properties'));
        self::assertStrictSchema($schema);
    }

    public function test_fixture_draft_is_valid_and_matches_contract(): void
    {
        $draft = AiFixtures::editorDraft();
        $excerpt = $draft['excerpt'];
        self::assertIsString($excerpt);

        self::assertSame([], ArticleDraft::errors($draft));
        self::assertSame('Docker v malé redakci', $draft['title']);
        self::assertGreaterThanOrEqual(60, mb_strlen($excerpt));
        self::assertLessThanOrEqual(100, mb_strlen($excerpt));
        self::assertSame(AiFixtures::EDITOR_DRAFT_BODY, $draft['body']);
        self::assertGreaterThanOrEqual(600, mb_strlen(AiFixtures::EDITOR_DRAFT_BODY));
        self::assertSame(2, preg_match_all('~^## ~mu', AiFixtures::EDITOR_DRAFT_BODY));
        self::assertSame([], ArticleDraft::errors(AiFixtures::editorRevisedDraft()));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function validBoundaries(): iterable
    {
        yield 'minimum' => [['title' => str_repeat('t', 10), 'excerpt' => str_repeat('p', 50), 'body' => self::body(600)]];
        yield 'maximum' => [['title' => str_repeat('ť', 200), 'excerpt' => str_repeat('ř', 300), 'body' => self::body(4000, 5)]];
        yield 'extra state keys are ignored' => [AiFixtures::editorDraft() + ['status' => 'published', 'publish' => true, 'published_at' => '2026-10-08T12:00']];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('validBoundaries')]
    public function test_valid_boundaries_have_no_errors(array $data): void
    {
        self::assertSame([], ArticleDraft::errors($data));
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function invalidDrafts(): iterable
    {
        yield 'title 9' => [AiFixtures::editorDraft(['title' => str_repeat('t', 9)]), 'title'];
        yield 'title 201' => [AiFixtures::editorDraft(['title' => str_repeat('t', 201)]), 'title'];
        yield 'title not string' => [AiFixtures::editorDraft(['title' => ['x']]), 'title'];
        yield 'excerpt 49' => [AiFixtures::editorDraft(['excerpt' => str_repeat('p', 49)]), 'excerpt'];
        yield 'excerpt 301' => [AiFixtures::editorDraft(['excerpt' => str_repeat('p', 301)]), 'excerpt'];
        yield 'body 599' => [AiFixtures::editorDraft(['body' => self::body(599)]), 'body'];
        yield 'body 4001' => [AiFixtures::editorDraft(['body' => self::body(4001)]), 'body'];
        yield 'missing body' => [['title' => 'Docker v malé redakci', 'excerpt' => str_repeat('p', 60)], 'body'];
    }

    /** @param array<mixed> $data */
    #[DataProvider('invalidDrafts')]
    public function test_invalid_draft_has_czech_error_for_field(array $data, string $field): void
    {
        $errors = ArticleDraft::errors($data);

        self::assertNotSame([], $errors);
        self::assertErrorAbout($field . ':', $errors);
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesWithTooFewSubheadings(): iterable
    {
        yield 'one subheading' => [self::body(700, 1)];
        yield 'no subheading' => [str_repeat('Odstavec bez nadpisu. ', 40)];
        yield 'h3 does not count' => [str_replace('## Mezititulek 2', '### Mezititulek 2', self::body(700))];
        yield 'missing space does not count' => [str_replace('## Mezititulek 2', '##Mezititulek 2', self::body(700))];
        yield 'heading inside line does not count' => [str_replace("\n\n## Mezititulek 2", ' a ## Mezititulek 2', self::body(700))];
    }

    #[DataProvider('bodiesWithTooFewSubheadings')]
    public function test_body_needs_two_lines_starting_with_double_hash(string $body): void
    {
        $errors = array_map(trim(...), ArticleDraft::errors(AiFixtures::editorDraft(['body' => $body])));

        self::assertContains(self::SUBHEADING_ERROR, $errors);
    }

    public function test_from_data_trims_and_keeps_only_title_excerpt_body(): void
    {
        $draft = ArticleDraft::fromData(['title' => '  Docker v malé redakci ', 'excerpt' => ' ' . str_repeat('p', 60) . ' ', 'body' => "\n" . self::body(700) . "\n", 'status' => 'published']);

        self::assertSame('Docker v malé redakci', $draft->title);
        self::assertSame(str_repeat('p', 60), $draft->excerpt);
        self::assertSame(self::body(700), $draft->body);

        $properties = array_map(
            static fn(\ReflectionProperty $property): string => $property->getName(),
            new \ReflectionClass(ArticleDraft::class)->getProperties(),
        );
        sort($properties);
        self::assertSame(['body', 'excerpt', 'title'], $properties, 'Koncept nenese žádný stav (LLM06).');
    }

    public function test_prompt_text_is_title_excerpt_and_body(): void
    {
        $draft = new ArticleDraft('Docker v malé redakci', 'Perex konceptu.', "## A\n\nText.");

        self::assertSame("Titulek: Docker v malé redakci\nPerex: Perex konceptu.\n\n## A\n\nText.", $draft->toPromptText());
    }

    public function test_draft_is_final_readonly(): void
    {
        $class = new \ReflectionClass(ArticleDraft::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }
}
