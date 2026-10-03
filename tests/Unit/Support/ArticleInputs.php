<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Application\Article\ArticleInput;

/** Formuláře článku pro testy: platný formulář z kontraktu plánu 005 s přepsanými poli. */
final class ArticleInputs
{
    /**
     * @param array{title?: string, slug?: string, excerpt?: string, body?: string, categoryId?: string,
     *     status?: string, publishedAt?: string, tagIds?: list<string>} $overrides
     */
    public static function valid(array $overrides = []): ArticleInput
    {
        $values = $overrides + [
            'title' => 'Nový článek',
            'slug' => '',
            'excerpt' => 'Perex.',
            'body' => "Ahoj **světe**",
            'categoryId' => '1',
            'status' => 'draft',
            'publishedAt' => '',
            'tagIds' => ['3', '2'],
        ];

        return new ArticleInput(
            title: $values['title'],
            slug: $values['slug'],
            excerpt: $values['excerpt'],
            body: $values['body'],
            categoryId: $values['categoryId'],
            status: $values['status'],
            publishedAt: $values['publishedAt'],
            tagIds: $values['tagIds'],
        );
    }

    /**
     * Totéž jako pole POST formuláře (`body`) a seznam štítků (`bodyLists`) pro Request.
     *
     * @param array<string, string> $overrides názvy polí formuláře (title, category_id, …)
     * @param list<string>|null $tags
     * @return array{body: array<string, string>, lists: array<string, list<string>>}
     */
    public static function post(array $overrides = [], ?array $tags = ['3', '2']): array
    {
        return [
            'body' => $overrides + [
                'title' => 'Nový článek',
                'slug' => '',
                'excerpt' => 'Perex.',
                'body' => "Ahoj **světe**",
                'category_id' => '1',
                'status' => 'draft',
                'published_at' => '',
            ],
            'lists' => $tags === null ? [] : ['tags' => $tags],
        ];
    }
}
