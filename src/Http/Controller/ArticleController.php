<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Article\PublishedArticles;
use App\Http\PageNotFound;
use App\Http\Request;
use App\Http\Response;
use App\Http\View\MarkdownRenderer;
use App\Http\View\TemplateRenderer;

final readonly class ArticleController
{
    public function __construct(
        private TemplateRenderer $renderer,
        private PublishedArticles $articles,
        private MarkdownRenderer $markdown,
    ) {}

    /** @throws PageNotFound článek neexistuje nebo není veřejný (stejná 404 pro oba případy) */
    public function show(Request $request): Response
    {
        $article = $this->articles->findBySlug($request->routeParameter('slug')) ?? throw new PageNotFound();

        return Response::html($this->renderer->render('article', [
            'title' => $article->title,
            'article' => $article,
            'bodyHtml' => $this->markdown->toHtml($article->body),
        ]));
    }
}
