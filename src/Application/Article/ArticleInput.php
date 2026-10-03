<?php

declare(strict_types=1);

namespace App\Application\Article;

use App\Domain\Article\ArticleStatus;
use App\Domain\Article\EditableArticle;

/**
 * Surová (neověřená) data formuláře článku. Slouží i k opětovnému vykreslení formuláře
 * po chybě validace, proto drží řetězce přesně tak, jak přišly. Skládá ho controller
 * z hodnot požadavku – Application nezná Http\Request.
 */
final readonly class ArticleInput
{
    /** Formát pole `<input type="datetime-local">`. */
    public const string DATETIME_LOCAL_FORMAT = 'Y-m-d\TH:i';

    /** @param list<string> $tagIds ID zaškrtnutých štítků (`tags[]`) */
    public function __construct(
        public string $title = '',
        public string $slug = '',
        public string $excerpt = '',
        public string $body = '',
        public string $categoryId = '',
        public string $status = '',
        public string $publishedAt = '',
        public array $tagIds = [],
    ) {}

    /** Prázdný formulář nového článku (výchozí stav koncept). */
    public static function empty(): self
    {
        return new self(status: ArticleStatus::Draft->value);
    }

    /** Formulář předvyplněný uloženým článkem. */
    public static function fromArticle(EditableArticle $article): self
    {
        return new self(
            title: $article->title,
            slug: $article->slug,
            excerpt: $article->excerpt,
            body: $article->body,
            categoryId: (string) $article->categoryId,
            status: $article->status->value,
            publishedAt: $article->publishedAt?->format(self::DATETIME_LOCAL_FORMAT) ?? '',
            tagIds: array_map(strval(...), $article->tagIds),
        );
    }
}
