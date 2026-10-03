<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Article;

use App\Application\Article\ArticleInput;
use App\Application\Article\ArticleInputValidator;
use App\Application\Article\InvalidArticleInput;
use App\Domain\Article\ArticleData;
use App\Domain\Article\ArticleStatus;
use App\Tests\Unit\Support\ArticleInputs;
use App\Tests\Unit\Support\FixedClock;
use App\Tests\Unit\Support\InMemoryCategoryRepository;
use App\Tests\Unit\Support\InMemoryTagRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 005, AC 4–7: validace formuláře článku (rubriky 1–3, štítky 1–3, hodiny 2026-10-03 12:00). */
final class ArticleInputValidatorTest extends TestCase
{
    private const string INVALID_UTF8 = "\xC3\x28";

    /** @param array{title?: string, slug?: string, excerpt?: string, body?: string, categoryId?: string,
     *     status?: string, publishedAt?: string, tagIds?: list<string>} $overrides */
    private static function input(array $overrides = []): ArticleInput
    {
        return ArticleInputs::valid($overrides);
    }

    private function validator(?FixedClock $clock = null): ArticleInputValidator
    {
        return new ArticleInputValidator(
            new InMemoryCategoryRepository(),
            new InMemoryTagRepository(),
            $clock ?? FixedClock::at('2026-10-03 12:00:00'),
        );
    }

    /**
     * @param array{title?: string, slug?: string, excerpt?: string, body?: string, categoryId?: string,
     *     status?: string, publishedAt?: string, tagIds?: list<string>} $overrides
     */
    private function validate(array $overrides = []): ArticleData
    {
        return $this->validator()->validate(self::input($overrides));
    }

    /**
     * @param array{title?: string, slug?: string, excerpt?: string, body?: string, categoryId?: string,
     *     status?: string, publishedAt?: string, tagIds?: list<string>} $overrides
     * @return array<string, string>
     */
    private function errors(array $overrides): array
    {
        try {
            $this->validate($overrides);
        } catch (InvalidArticleInput $exception) {
            return $exception->errors;
        }

        self::fail('Očekávána výjimka InvalidArticleInput.');
    }

    public function test_valid_form_becomes_article_data(): void
    {
        $data = $this->validate();

        self::assertSame('Nový článek', $data->title);
        self::assertSame('novy-clanek', $data->slug);
        self::assertSame('Perex.', $data->excerpt);
        self::assertSame("Ahoj **světe**", $data->body);
        self::assertSame(1, $data->categoryId);
        self::assertSame([2, 3], $data->tagIds);
        self::assertSame(ArticleStatus::Draft, $data->status);
        self::assertNull($data->publishedAt);
    }

    public function test_tag_ids_are_sorted_and_deduplicated(): void
    {
        self::assertSame([2, 3], $this->validate(['tagIds' => ['3', '2', '3']])->tagIds);
    }

    public function test_title_and_excerpt_are_trimmed_but_body_is_not(): void
    {
        $data = $this->validate(['title' => "  Nový článek \n", 'excerpt' => '  Perex.  ', 'body' => "  Text  \n"]);

        self::assertSame('Nový článek', $data->title);
        self::assertSame('Perex.', $data->excerpt);
        self::assertSame("  Text  \n", $data->body);
    }

    public function test_manual_slug_is_normalized_like_title(): void
    {
        self::assertSame('muj-vlastni-slug', $this->validate(['slug' => 'Můj Vlastní Slug!'])->slug);
    }

    public function test_empty_slug_from_title_without_letters_falls_back_to_clanek(): void
    {
        self::assertSame('clanek', $this->validate(['slug' => '', 'title' => '!!!'])->slug);
    }

    public function test_published_without_date_uses_clock_time_rounded_to_minute(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-10-03 12:00:37.123456', new \DateTimeZone('Europe/Prague')));

        $data = $this->validator($clock)->validate(self::input(['status' => 'published', 'publishedAt' => '']));

        self::assertSame(ArticleStatus::Published, $data->status);
        self::assertNotNull($data->publishedAt);
        self::assertSame('2026-10-03 12:00:00.000000', $data->publishedAt->format('Y-m-d H:i:s.u'));
        self::assertSame('Europe/Prague', $data->publishedAt->getTimezone()->getName());
    }

    public function test_future_publication_date_is_valid_scheduled_article(): void
    {
        $data = $this->validate(['status' => 'published', 'publishedAt' => '2026-11-01T09:30']);

        self::assertNotNull($data->publishedAt);
        self::assertSame('2026-11-01 09:30:00', $data->publishedAt->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Prague', $data->publishedAt->getTimezone()->getName());
    }

    public function test_publication_date_with_seconds_is_valid(): void
    {
        $data = $this->validate(['status' => 'published', 'publishedAt' => '2026-11-01T09:30:15']);

        self::assertNotNull($data->publishedAt);
        self::assertSame('2026-11-01 09:30', $data->publishedAt->format('Y-m-d H:i'));
    }

    public function test_draft_keeps_filled_publication_date(): void
    {
        $data = $this->validate(['status' => 'draft', 'publishedAt' => '2026-11-01T09:30']);

        self::assertSame(ArticleStatus::Draft, $data->status);
        self::assertNotNull($data->publishedAt);
        self::assertSame('2026-11-01 09:30', $data->publishedAt->format('Y-m-d H:i'));
    }

    public function test_archived_status_is_accepted(): void
    {
        self::assertSame(ArticleStatus::Archived, $this->validate(['status' => 'archived'])->status);
    }

    public function test_empty_excerpt_tags_and_draft_body_are_valid(): void
    {
        $data = $this->validate(['excerpt' => '', 'tagIds' => [], 'body' => '', 'status' => 'draft']);

        self::assertSame('', $data->excerpt);
        self::assertSame([], $data->tagIds);
        self::assertSame('', $data->body);
    }

    public function test_values_at_maximum_length_are_valid(): void
    {
        $data = $this->validate([
            'title' => str_repeat('ž', 200),
            'slug' => str_repeat('a', 220),
            'excerpt' => str_repeat('é', 500),
            'body' => str_repeat('a', 100_000),
        ]);

        self::assertSame(200, mb_strlen($data->title));
        self::assertSame(500, mb_strlen($data->excerpt));
        self::assertSame(100_000, strlen($data->body));
        self::assertLessThanOrEqual(220, strlen($data->slug));
    }

    public function test_length_limits_are_exposed_as_constants(): void
    {
        self::assertSame(200, ArticleInputValidator::TITLE_MAX);
        self::assertSame(220, ArticleInputValidator::SLUG_INPUT_MAX);
        self::assertSame(500, ArticleInputValidator::EXCERPT_MAX);
        self::assertSame(100_000, ArticleInputValidator::BODY_MAX);
    }

    /**
     * @return iterable<string, array{array<string, string|list<string>>, string, string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'blank title' => [['title' => '   '], 'title', 'Vyplňte titulek.'];
        yield 'title too long (multibyte)' => [['title' => str_repeat('ž', 201)], 'title', 'Titulek může mít nejvýše 200 znaků.'];
        yield 'slug too long' => [['slug' => str_repeat('a', 221)], 'slug', 'Adresa (slug) může mít nejvýše 220 znaků.'];
        yield 'excerpt too long' => [['excerpt' => str_repeat('é', 501)], 'excerpt', 'Perex může mít nejvýše 500 znaků.'];
        yield 'body too long' => [['body' => str_repeat('a', 100_001)], 'body', 'Text může mít nejvýše 100 000 znaků.'];
        yield 'published without body' => [['status' => 'published', 'body' => "  \n"], 'body', 'Publikovaný článek musí mít text.'];
        yield 'category empty' => [['categoryId' => ''], 'category_id', 'Vyberte rubriku.'];
        yield 'category not a number' => [['categoryId' => 'abc'], 'category_id', 'Vyberte rubriku.'];
        yield 'category zero' => [['categoryId' => '0'], 'category_id', 'Vyberte rubriku.'];
        yield 'category unknown' => [['categoryId' => '99'], 'category_id', 'Vyberte rubriku.'];
        yield 'tag unknown' => [['tagIds' => ['99']], 'tags', 'Vybraný štítek neexistuje.'];
        yield 'tag not a number' => [['tagIds' => ['x']], 'tags', 'Vybraný štítek neexistuje.'];
        yield 'status empty' => [['status' => ''], 'status', 'Vyberte stav článku.'];
        yield 'status unknown' => [['status' => 'smazano'], 'status', 'Vyberte stav článku.'];
        yield 'date words' => [['publishedAt' => 'zitra'], 'published_at', 'Zadejte platné datum a čas publikace.'];
        yield 'date february 30' => [['publishedAt' => '2026-02-30T10:00'], 'published_at', 'Zadejte platné datum a čas publikace.'];
        yield 'date with space instead of T' => [['publishedAt' => '2026-10-03 10:00'], 'published_at', 'Zadejte platné datum a čas publikace.'];
        yield 'date hour 25' => [['publishedAt' => '2026-10-03T25:00'], 'published_at', 'Zadejte platné datum a čas publikace.'];
        yield 'invalid utf-8 title' => [['title' => 'A' . self::INVALID_UTF8], 'title', 'Pole obsahuje neplatné znaky.'];
        yield 'invalid utf-8 slug' => [['slug' => 'a' . self::INVALID_UTF8], 'slug', 'Pole obsahuje neplatné znaky.'];
        yield 'invalid utf-8 excerpt' => [['excerpt' => self::INVALID_UTF8], 'excerpt', 'Pole obsahuje neplatné znaky.'];
        yield 'invalid utf-8 body' => [['body' => 'Text ' . self::INVALID_UTF8], 'body', 'Pole obsahuje neplatné znaky.'];
        yield 'invalid utf-8 category' => [['categoryId' => self::INVALID_UTF8], 'category_id', 'Pole obsahuje neplatné znaky.'];
        yield 'invalid utf-8 status' => [['status' => self::INVALID_UTF8], 'status', 'Pole obsahuje neplatné znaky.'];
        yield 'invalid utf-8 date' => [['publishedAt' => self::INVALID_UTF8], 'published_at', 'Pole obsahuje neplatné znaky.'];
        yield 'invalid utf-8 tag' => [['tagIds' => [self::INVALID_UTF8]], 'tags', 'Pole obsahuje neplatné znaky.'];
    }

    /** @param array{title?: string, slug?: string, excerpt?: string, body?: string, categoryId?: string,
     *     status?: string, publishedAt?: string, tagIds?: list<string>} $overrides */
    #[DataProvider('invalidInputs')]
    public function test_invalid_field_reports_czech_message(array $overrides, string $field, string $message): void
    {
        $errors = $this->errors($overrides);

        self::assertSame($message, $errors[$field] ?? null, sprintf('Chyby: %s', json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)));
    }

    /** @param array{title?: string, slug?: string, excerpt?: string, body?: string, categoryId?: string,
     *     status?: string, publishedAt?: string, tagIds?: list<string>} $overrides */
    #[DataProvider('invalidInputs')]
    public function test_invalid_field_reports_only_its_own_error(array $overrides, string $field, string $message): void
    {
        self::assertSame([$field], array_keys($this->errors($overrides)));
    }

    public function test_all_errors_are_reported_at_once(): void
    {
        $errors = $this->errors([
            'title' => '   ',
            'categoryId' => '',
            'status' => '',
            'publishedAt' => 'zitra',
            'tagIds' => ['99'],
        ]);

        self::assertSame([
            'category_id' => 'Vyberte rubriku.',
            'published_at' => 'Zadejte platné datum a čas publikace.',
            'status' => 'Vyberte stav článku.',
            'tags' => 'Vybraný štítek neexistuje.',
            'title' => 'Vyplňte titulek.',
        ], self::sorted($errors));
    }

    /**
     * @param array<string, string> $errors
     * @return array<string, string>
     */
    private static function sorted(array $errors): array
    {
        ksort($errors);

        return $errors;
    }
}
