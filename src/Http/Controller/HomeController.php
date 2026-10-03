<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Article\PublishedArticles;
use App\Http\PageNotFound;
use App\Http\Request;
use App\Http\Response;
use App\Http\View\TemplateRenderer;

final readonly class HomeController
{
    /** Číslo strany: 1–6 číslic bez úvodní nuly (omezuje délku vstupu, žádné `01`, `-1`, `1.5`). */
    private const string PAGE_PATTERN = '/^[1-9][0-9]{0,5}\z/';

    public function __construct(
        private TemplateRenderer $renderer,
        private PublishedArticles $articles,
    ) {}

    /** @throws PageNotFound neplatné číslo strany nebo strana mimo rozsah */
    public function index(Request $request): Response
    {
        $page = $this->parsePage($request->queryParameter('strana'));
        $articlePage = $this->articles->page($page) ?? throw new PageNotFound();

        return Response::html($this->renderer->render('home', [
            'title' => $page === 1 ? 'Úvod' : 'Strana ' . $page,
            'page' => $articlePage,
        ]));
    }

    private function parsePage(string $value): int
    {
        if ($value === '') {
            return 1;
        }

        if (preg_match(self::PAGE_PATTERN, $value) !== 1) {
            throw new PageNotFound();
        }

        return (int) $value;
    }
}
