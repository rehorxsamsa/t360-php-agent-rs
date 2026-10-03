<?php

declare(strict_types=1);

namespace App\Application\Article;

use App\Domain\Article\ArticleData;
use App\Domain\Article\ArticleStatus;
use App\Domain\Article\Slug;
use App\Domain\Category\CategoryRepository;
use App\Domain\Tag\TagRepository;
use App\Domain\Time\Clock;

/**
 * Serverová validace formuláře článku. Sbírá všechny chyby najednou a hlásí je česky
 * u jednotlivých polí; výsledkem je ArticleData se základním slugem (bez řešení kolizí).
 */
final readonly class ArticleInputValidator
{
    public const int TITLE_MAX = 200;
    public const int SLUG_INPUT_MAX = 220;
    public const int EXCERPT_MAX = 500;
    public const int BODY_MAX = 100_000;

    private const string INVALID_CHARACTERS = 'Pole obsahuje neplatné znaky.';

    /** Kladné celé číslo bez úvodních nul, max. 18 číslic (žádné přetečení int). */
    private const string ID_PATTERN = '/^[1-9][0-9]{0,17}\z/';

    /** Přijímané formáty pole datetime-local (prohlížeče posílají i sekundy). */
    private const array DATETIME_FORMATS = ['Y-m-d\TH:i', 'Y-m-d\TH:i:s'];

    public function __construct(
        private CategoryRepository $categories,
        private TagRepository $tags,
        private Clock $clock,
    ) {}

    /** @throws InvalidArticleInput se všemi chybami formuláře */
    public function validate(ArticleInput $input): ArticleData
    {
        /** @var array<string, string> $errors */
        $errors = [];

        $title = $this->validateTitle($input->title, $errors);
        $slugInput = $this->validateSlugInput($input->slug, $errors);
        $excerpt = $this->validateExcerpt($input->excerpt, $errors);
        $status = $this->validateStatus($input->status, $errors);
        $body = $this->validateBody($input->body, $status, $errors);
        $categoryId = $this->validateCategory($input->categoryId, $errors);
        $tagIds = $this->validateTags($input->tagIds, $errors);
        $publishedAt = $this->validatePublishedAt($input->publishedAt, $status, $errors);

        if ($errors !== [] || $title === null || $slugInput === null || $excerpt === null
            || $status === null || $body === null || $categoryId === null || $tagIds === null
        ) {
            throw new InvalidArticleInput($errors);
        }

        // Ručně zadaný slug se normalizuje stejně jako titulek (plán 005, otázka 1).
        $slug = Slug::fromText($slugInput !== '' ? $slugInput : $title);

        return new ArticleData($title, $slug, $excerpt, $body, $categoryId, $tagIds, $status, $publishedAt);
    }

    /** @param array<string, string> $errors */
    private function validateTitle(string $value, array &$errors): ?string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $errors['title'] = self::INVALID_CHARACTERS;

            return null;
        }

        $title = trim($value);
        if ($title === '') {
            $errors['title'] = 'Vyplňte titulek.';

            return null;
        }

        if (mb_strlen($title) > self::TITLE_MAX) {
            $errors['title'] = 'Titulek může mít nejvýše 200 znaků.';

            return null;
        }

        return $title;
    }

    /** @param array<string, string> $errors */
    private function validateSlugInput(string $value, array &$errors): ?string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $errors['slug'] = self::INVALID_CHARACTERS;

            return null;
        }

        $slug = trim($value);
        if (mb_strlen($slug) > self::SLUG_INPUT_MAX) {
            $errors['slug'] = 'Adresa (slug) může mít nejvýše 220 znaků.';

            return null;
        }

        return $slug;
    }

    /** @param array<string, string> $errors */
    private function validateExcerpt(string $value, array &$errors): ?string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $errors['excerpt'] = self::INVALID_CHARACTERS;

            return null;
        }

        $excerpt = trim($value);
        if (mb_strlen($excerpt) > self::EXCERPT_MAX) {
            $errors['excerpt'] = 'Perex může mít nejvýše 500 znaků.';

            return null;
        }

        return $excerpt;
    }

    /**
     * Text se neořezává (Markdown může začínat odsazeným blokem kódu).
     *
     * @param array<string, string> $errors
     */
    private function validateBody(string $value, ?ArticleStatus $status, array &$errors): ?string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $errors['body'] = self::INVALID_CHARACTERS;

            return null;
        }

        if (mb_strlen($value) > self::BODY_MAX) {
            $errors['body'] = 'Text může mít nejvýše 100 000 znaků.';

            return null;
        }

        // Koncept smí být bez textu, publikovaný článek ne (plán 005, otázka 6).
        if ($status === ArticleStatus::Published && trim($value) === '') {
            $errors['body'] = 'Publikovaný článek musí mít text.';

            return null;
        }

        return $value;
    }

    /** @param array<string, string> $errors */
    private function validateStatus(string $value, array &$errors): ?ArticleStatus
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $errors['status'] = self::INVALID_CHARACTERS;

            return null;
        }

        $status = ArticleStatus::tryFrom($value);
        if ($status === null) {
            $errors['status'] = 'Vyberte stav článku.';
        }

        return $status;
    }

    /** @param array<string, string> $errors */
    private function validateCategory(string $value, array &$errors): ?int
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $errors['category_id'] = self::INVALID_CHARACTERS;

            return null;
        }

        if (preg_match(self::ID_PATTERN, $value) === 1) {
            $id = (int) $value;
            foreach ($this->categories->all() as $category) {
                if ($category->id === $id) {
                    return $id;
                }
            }
        }

        $errors['category_id'] = 'Vyberte rubriku.';

        return null;
    }

    /**
     * @param list<string> $values
     * @param array<string, string> $errors
     * @return list<int>|null seřazená ID bez duplicit
     */
    private function validateTags(array $values, array &$errors): ?array
    {
        if ($values === []) {
            return [];
        }

        foreach ($values as $value) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                $errors['tags'] = self::INVALID_CHARACTERS;

                return null;
            }
        }

        $existing = [];
        foreach ($this->tags->all() as $tag) {
            $existing[$tag->id] = true;
        }

        $ids = [];
        foreach ($values as $value) {
            $id = preg_match(self::ID_PATTERN, $value) === 1 ? (int) $value : 0;
            if (!isset($existing[$id])) {
                $errors['tags'] = 'Vybraný štítek neexistuje.';

                return null;
            }
            $ids[$id] = $id;
        }

        sort($ids);

        return $ids;
    }

    /**
     * Prázdné datum u publikovaného článku = teď (zaokrouhleno na minuty, aby šlo zpět
     * do pole datetime-local); budoucí datum je platné (naplánovaný článek).
     *
     * @param array<string, string> $errors
     */
    private function validatePublishedAt(string $value, ?ArticleStatus $status, array &$errors): ?\DateTimeImmutable
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $errors['published_at'] = self::INVALID_CHARACTERS;

            return null;
        }

        $now = $this->clock->now();

        if ($value === '') {
            if ($status !== ArticleStatus::Published) {
                return null;
            }

            return $now->setTime((int) $now->format('H'), (int) $now->format('i'));
        }

        foreach (self::DATETIME_FORMATS as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, $now->getTimezone());
            // Zpětný format() odmítne přetečení typu 30. února nebo 25:00.
            if ($date !== false && $date->format($format) === $value) {
                return $date;
            }
        }

        $errors['published_at'] = 'Zadejte platné datum a čas publikace.';

        return null;
    }
}
