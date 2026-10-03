<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Article\PublishedArticles;
use App\Http\PageNotFound;
use App\Http\PageNumber;
use App\Http\Request;
use App\Http\Response;
use App\Http\View\TemplateRenderer;

final readonly class HomeController
{
    public function __construct(
        private TemplateRenderer $renderer,
        private PublishedArticles $articles,
    ) {}

    /** @throws PageNotFound neplatné číslo strany nebo strana mimo rozsah */
    public function index(Request $request): Response
    {
        $page = PageNumber::fromQuery($request->queryParameter('strana'));
        $articlePage = $this->articles->page($page) ?? throw new PageNotFound();

        return Response::html($this->renderer->render('home', [
            'title' => $page === 1 ? 'Úvod' : 'Strana ' . $page,
            'page' => $articlePage,
        ]));
    }
}
