<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Article\ArticleRepository;
use App\Domain\Time\Clock;
use App\Http\Request;
use App\Http\Response;
use App\Http\View\TemplateRenderer;

/** Fulltextové vyhledávání ve veřejných článcích (titulek, perex, zdrojový text). */
final readonly class SearchController
{
    public const int LIMIT = 50;
    public const int MAX_QUERY_LENGTH = 100;

    public function __construct(
        private TemplateRenderer $renderer,
        private ArticleRepository $articles,
        private Clock $clock,
    ) {}

    public function index(Request $request): Response
    {
        $query = mb_substr(trim($request->queryParameter('q')), 0, self::MAX_QUERY_LENGTH);
        $results = $query === '' ? [] : $this->articles->searchPublished($query, $this->clock->now(), self::LIMIT);

        return Response::html($this->renderer->render('search', [
            'title' => $query === '' ? 'Hledání' : 'Hledání: ' . $query,
            'query' => $query,
            'searchQuery' => $query,
            'results' => $results,
            'limit' => self::LIMIT,
        ]));
    }
}
