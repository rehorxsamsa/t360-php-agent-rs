<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Ai\AiBudgetExceeded;
use App\Ai\Editor\DraftProposal;
use App\Ai\Examples\Example09AiEditor;
use App\Ai\Examples\InvalidExampleInput;
use App\Ai\Examples\InvalidModelOutput;
use App\Ai\LlmCallFailed;
use App\Application\Article\AdminArticles;
use App\Application\Article\ArticleInput;
use App\Application\Article\InvalidArticleInput;
use App\Application\Article\SaveAiDraft;
use App\Http\Auth\AuthSession;
use App\Http\Request;
use App\Http\Response;
use App\Http\Security\CsrfToken;
use App\Http\Session\AiDraftStash;
use App\Http\Session\Flash;
use App\Http\View\TemplateRenderer;

/**
 * Administrace: AI příklad 09 – AI redaktor (plán 010, ADR-0010, člověk ve smyčce).
 *
 * POST /admin/ai/09 spustí workflow a návrh odloží do session (PRG); GET nikdy nevolá LLM.
 * Jediný zápis výstupu AI do databáze je POST /admin/ai/09/ulozit, který odešle administrátor –
 * a i ten jde přes {@see SaveAiDraft}, který vynutí stav koncept (LLM06). Rubriku vybírá vždy admin.
 * Z požadavku se čtou jen vyjmenovaná pole; `status`, `published_at`, `slug` a `tags[]` se ignorují.
 */
final readonly class AiEditorController
{
    private const string LOGIN_PATH = '/admin/prihlaseni';
    private const string PATH = '/admin/ai/09';
    private const string SAVE_PATH = '/admin/ai/09/ulozit';
    private const string DISCARD_PATH = '/admin/ai/09/zahodit';

    private const string SAVED_FLASH = 'AI návrh byl uložen jako koncept. Zkontrolujte ho – publikovat ho můžete jen vy.';
    private const string GONE_FLASH = 'Návrh už není k dispozici – nechte AI redaktora navrhnout nový.';
    private const string DISCARDED_FLASH = 'Návrh byl zahozen.';

    public function __construct(
        private TemplateRenderer $renderer,
        private Example09AiEditor $example,
        private SaveAiDraft $saveAiDraft,
        private AdminArticles $articles,
        private AuthSession $auth,
        private CsrfToken $csrf,
        private AiDraftStash $stash,
        private Flash $flash,
    ) {}

    public function show(Request $request): Response
    {
        // Obrana do hloubky: přístup hlídá už AdminAccessMiddleware, kontrola je i zde.
        if ($this->auth->user() === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        $proposal = $this->stash->get();

        return $this->page(
            200,
            $proposal === null ? Example09AiEditor::DEMO_TOPIC : $proposal->topic,
            '',
            $proposal,
            $proposal === null ? null : self::inputFromProposal($proposal),
            [],
        );
    }

    public function draft(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        $topic = $request->input('topic');

        try {
            $proposal = $this->example->draft($topic, $user->id);
        } catch (InvalidExampleInput $exception) {
            return $this->failedDraftPage(422, $topic, $exception->getMessage());
        } catch (AiBudgetExceeded $exception) {
            return $this->failedDraftPage(429, $topic, $exception->getMessage());
        } catch (LlmCallFailed|InvalidModelOutput $exception) {
            return $this->failedDraftPage(502, $topic, $exception->getMessage());
        }

        $this->stash->put($proposal);

        return Response::redirect(self::PATH);
    }

    public function save(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        // Bez návrhu v session není co schvalovat (např. opakované odeslání formuláře).
        $proposal = $this->stash->get();
        if ($proposal === null) {
            $this->flash->set(self::GONE_FLASH);

            return Response::redirect(self::PATH);
        }

        // Jen obsahová pole, která admin viděl a mohl upravit; stav, datum, slug ani štítky se nečtou.
        $input = new ArticleInput(
            title: $request->input('title'),
            excerpt: $request->input('excerpt'),
            body: $request->input('body'),
            categoryId: $request->input('category_id'),
        );

        try {
            $id = $this->saveAiDraft->handle($input, $user, $request->clientIp);
        } catch (InvalidArticleInput $exception) {
            return $this->page(422, $proposal->topic, '', $proposal, $input, $exception->errors);
        }

        $this->stash->clear();
        $this->flash->set(self::SAVED_FLASH);

        return Response::redirect(sprintf('/admin/clanky/%d/upravit', $id));
    }

    public function discard(Request $request): Response
    {
        if ($this->auth->user() === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        $this->stash->clear();
        $this->flash->set(self::DISCARDED_FLASH);

        return Response::redirect(self::PATH);
    }

    /** Chyba nového návrhu: předchozí návrh v session zůstává beze změny, formulář nese odeslané téma. */
    private function failedDraftPage(int $status, string $topic, string $error): Response
    {
        $proposal = $this->stash->get();

        return $this->page(
            $status,
            $topic,
            $error,
            $proposal,
            $proposal === null ? null : self::inputFromProposal($proposal),
            [],
        );
    }

    /** Formulář schválení předvyplněný návrhem; rubrika zůstává prázdná (vybírá ji vždy admin). */
    private static function inputFromProposal(DraftProposal $proposal): ArticleInput
    {
        return new ArticleInput(
            title: $proposal->draft->title,
            excerpt: $proposal->draft->excerpt,
            body: $proposal->draft->body,
        );
    }

    /**
     * @param array<string, string> $errors chyby formuláře schválení (pole => česká hláška)
     */
    private function page(
        int $status,
        string $topic,
        string $error,
        ?DraftProposal $proposal,
        ?ArticleInput $input,
        array $errors,
    ): Response {
        return Response::html($this->renderer->render('admin/ai/ai-editor', [
            'title' => sprintf('%s – %s', $this->example->id(), $this->example->title()),
            'example' => $this->example,
            'action' => self::PATH,
            'saveAction' => self::SAVE_PATH,
            'discardAction' => self::DISCARD_PATH,
            'topic' => $topic,
            'error' => $error,
            'proposal' => $proposal,
            'input' => $input,
            'errors' => $errors,
            'categories' => $proposal === null ? [] : $this->articles->categories(),
            'flash' => $this->flash->pull(),
            'csrfToken' => $this->csrf->token(),
        ]), $status);
    }
}
