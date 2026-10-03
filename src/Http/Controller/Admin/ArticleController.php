<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Article\AdminArticles;
use App\Application\Article\ArticleInput;
use App\Application\Article\ArticleNotFound;
use App\Application\Article\CreateArticle;
use App\Application\Article\DeleteArticle;
use App\Application\Article\InvalidArticleInput;
use App\Application\Article\UpdateArticle;
use App\Domain\Article\EditableArticle;
use App\Domain\Time\Clock;
use App\Http\Auth\AuthSession;
use App\Http\PageNotFound;
use App\Http\PageNumber;
use App\Http\Request;
use App\Http\Response;
use App\Http\Security\CsrfToken;
use App\Http\Session\Flash;
use App\Http\View\MarkdownRenderer;
use App\Http\View\TemplateRenderer;

/**
 * Administrace článků: seznam, nový článek, úprava a smazání (PRG + flash zprávy).
 * Tenký controller – validaci, slug a audit řeší use-case služby v Application.
 */
final readonly class ArticleController
{
    /** ID z URL: kladné celé číslo bez úvodní nuly, nejvýše 18 číslic (žádné přetečení int). */
    private const string ID_PATTERN = '/^[1-9][0-9]{0,17}\z/';
    private const string LOGIN_PATH = '/admin/prihlaseni';
    private const string LIST_PATH = '/admin/clanky';
    private const string CREATE_PATH = '/admin/clanky/novy';

    public function __construct(
        private TemplateRenderer $renderer,
        private AdminArticles $articles,
        private CreateArticle $createArticle,
        private UpdateArticle $updateArticle,
        private DeleteArticle $deleteArticle,
        private MarkdownRenderer $markdown,
        private AuthSession $auth,
        private CsrfToken $csrf,
        private Flash $flash,
        private Clock $clock,
    ) {}

    /** @throws PageNotFound neplatné číslo strany nebo strana mimo rozsah */
    public function index(Request $request): Response
    {
        if ($this->auth->user() === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        $page = $this->articles->page(PageNumber::fromQuery($request->queryParameter('strana')))
            ?? throw new PageNotFound();

        return Response::html($this->renderer->render('admin/articles/index', [
            'title' => 'Články',
            'page' => $page,
            'flash' => $this->flash->pull(),
            'now' => $this->clock->now(),
        ]));
    }

    public function create(Request $request): Response
    {
        if ($this->auth->user() === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        return $this->form(200, self::CREATE_PATH, 'Nový článek', ArticleInput::empty(), [], null);
    }

    public function store(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        $input = $this->input($request);

        try {
            $id = $this->createArticle->handle($input, $user, $request->clientIp);
        } catch (InvalidArticleInput $exception) {
            return $this->form(422, self::CREATE_PATH, 'Nový článek', $input, $exception->errors, null);
        }

        $this->flash->set('Článek byl vytvořen.');

        return Response::redirect(self::editPath($id));
    }

    /** @throws PageNotFound neplatné nebo neexistující ID */
    public function edit(Request $request): Response
    {
        if ($this->auth->user() === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        $article = $this->findArticle($request);

        return $this->form(200, self::editPath($article->id), 'Úprava článku', ArticleInput::fromArticle($article), [], $article);
    }

    /** @throws PageNotFound neplatné nebo neexistující ID */
    public function update(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        $id = $this->articleId($request);
        $input = $this->input($request);

        try {
            $this->updateArticle->handle($id, $input, $user, $request->clientIp);
        } catch (ArticleNotFound) {
            throw new PageNotFound();
        } catch (InvalidArticleInput $exception) {
            $article = $this->articles->find($id) ?? throw new PageNotFound();

            return $this->form(422, self::editPath($id), 'Úprava článku', $input, $exception->errors, $article);
        }

        $this->flash->set('Změny byly uloženy.');

        return Response::redirect(self::editPath($id));
    }

    /** @throws PageNotFound neplatné nebo neexistující ID */
    public function confirmDelete(Request $request): Response
    {
        if ($this->auth->user() === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        $article = $this->findArticle($request);

        return Response::html($this->renderer->render('admin/articles/delete', [
            'title' => 'Smazat článek',
            'article' => $article,
            'csrfToken' => $this->csrf->token(),
        ]));
    }

    /** @throws PageNotFound neplatné nebo neexistující ID */
    public function delete(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        try {
            $title = $this->deleteArticle->handle($this->articleId($request), $user, $request->clientIp);
        } catch (ArticleNotFound) {
            throw new PageNotFound();
        }

        $this->flash->set(sprintf('Článek „%s“ byl smazán.', $title));

        return Response::redirect(self::LIST_PATH);
    }

    /**
     * @param array<string, string> $errors
     */
    private function form(
        int $status,
        string $action,
        string $heading,
        ArticleInput $input,
        array $errors,
        ?EditableArticle $article,
    ): Response {
        $savedBody = $article === null ? '' : $article->body;

        return Response::html($this->renderer->render('admin/articles/form', [
            'title' => $heading,
            'heading' => $heading,
            'action' => $action,
            'input' => $input,
            'errors' => $errors,
            'categories' => $this->articles->categories(),
            'tags' => $this->articles->tags(),
            'article' => $article,
            // Náhled uložené verze textu jen přes sanitizující MarkdownRenderer (ADR-0005).
            'previewHtml' => trim($savedBody) === '' ? '' : $this->markdown->toHtml($savedBody),
            'flash' => $this->flash->pull(),
            'csrfToken' => $this->csrf->token(),
            'now' => $this->clock->now(),
        ]), $status);
    }

    /** Surové hodnoty formuláře – jen pojmenovaná pole, nic dalšího se do use-case nedostane. */
    private function input(Request $request): ArticleInput
    {
        return new ArticleInput(
            title: $request->input('title'),
            slug: $request->input('slug'),
            excerpt: $request->input('excerpt'),
            body: $request->input('body'),
            categoryId: $request->input('category_id'),
            status: $request->input('status'),
            publishedAt: $request->input('published_at'),
            tagIds: $request->inputList('tags'),
        );
    }

    /** @throws PageNotFound */
    private function findArticle(Request $request): EditableArticle
    {
        return $this->articles->find($this->articleId($request)) ?? throw new PageNotFound();
    }

    /** @throws PageNotFound ID neodpovídá vzoru (stejná 404 jako neexistující článek) */
    private function articleId(Request $request): int
    {
        $id = $request->routeParameter('id');
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new PageNotFound();
        }

        return (int) $id;
    }

    private static function editPath(int $id): string
    {
        return sprintf('/admin/clanky/%d/upravit', $id);
    }
}
