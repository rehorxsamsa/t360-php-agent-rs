<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Article;

use App\Application\Article\ArticleInput;
use App\Domain\Article\ArticleStatus;
use App\Tests\Unit\Support\InMemoryArticleAdminRepository;
use PHPUnit\Framework\TestCase;

/** Plán 005, §2: surová data formuláře pro prázdný formulář a pro úpravu. */
final class ArticleInputTest extends TestCase
{
    public function test_empty_form_is_draft_with_blank_fields(): void
    {
        $input = ArticleInput::empty();

        self::assertSame('draft', $input->status);
        self::assertSame('', $input->title);
        self::assertSame('', $input->slug);
        self::assertSame('', $input->excerpt);
        self::assertSame('', $input->body);
        self::assertSame('', $input->categoryId);
        self::assertSame('', $input->publishedAt);
        self::assertSame([], $input->tagIds);
    }

    public function test_from_article_prefills_strings_for_form(): void
    {
        $article = InMemoryArticleAdminRepository::editable(
            5,
            title: 'Starý článek',
            slug: 'stary-clanek',
            excerpt: 'Perex.',
            body: 'Text.',
            categoryId: 2,
            tagIds: [1, 3],
            status: ArticleStatus::Published,
            publishedAt: '2026-11-01 09:30:00',
        );

        $input = ArticleInput::fromArticle($article);

        self::assertSame('Starý článek', $input->title);
        self::assertSame('stary-clanek', $input->slug);
        self::assertSame('Perex.', $input->excerpt);
        self::assertSame('Text.', $input->body);
        self::assertSame('2', $input->categoryId);
        self::assertSame(['1', '3'], $input->tagIds);
        self::assertSame('published', $input->status);
        self::assertSame('2026-11-01T09:30', $input->publishedAt);
    }

    public function test_from_article_without_publication_date_has_empty_date(): void
    {
        self::assertSame('', ArticleInput::fromArticle(InMemoryArticleAdminRepository::editable(5))->publishedAt);
    }
}
