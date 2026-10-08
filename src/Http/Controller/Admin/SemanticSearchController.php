<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Ai\AiBudgetExceeded;
use App\Ai\Embedding\EmbeddingFailed;
use App\Ai\Embedding\EmbeddingProvider;
use App\Ai\Examples\Example08SemanticSearch;
use App\Ai\Examples\ExampleResult;
use App\Ai\Examples\InvalidExampleInput;
use App\Ai\Examples\InvalidModelOutput;
use App\Ai\LlmCallFailed;
use App\Ai\Rag\ArticleIndexer;
use App\Http\Auth\AuthSession;
use App\Http\Request;
use App\Http\Response;
use App\Http\Security\CsrfToken;
use App\Http\Session\ExampleResultStash;
use App\Http\Session\Flash;
use App\Http\View\TemplateRenderer;

/**
 * Administrace: AI příklad 08 – Sémantické vyhledávání (RAG, ADR-0009). Stránka ukazuje stav indexu vektorů,
 * tlačítko pro jeho aktualizaci a formulář s otázkou. Oba POSTy končí přesměrováním (PRG): zpráva o indexaci
 * přes flash, výsledek dotazu přes ExampleResultStash (zobrazí se jednou). GET nikdy nevolá embeddingy ani LLM.
 * Odpověď modelu, citace i titulky článků jsou nedůvěryhodné – šablona je jen escapuje.
 */
final readonly class SemanticSearchController
{
    private const string LOGIN_PATH = '/admin/prihlaseni';
    private const string PATH = '/admin/ai/08';
    private const string REINDEX_PATH = '/admin/ai/08/indexace';

    public function __construct(
        private TemplateRenderer $renderer,
        private Example08SemanticSearch $example,
        private ArticleIndexer $indexer,
        private AuthSession $auth,
        private CsrfToken $csrf,
        private ExampleResultStash $stash,
        private Flash $flash,
    ) {}

    public function show(Request $request): Response
    {
        // Obrana do hloubky: přístup hlídá už AdminAccessMiddleware, kontrola je i zde.
        if ($this->auth->user() === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        // Slot „article“ stashe nese u příkladu 08 text otázky, se kterou výsledek vznikl.
        $stashed = $this->stash->pull($this->example->id());
        $question = $stashed !== null && $stashed->article !== '' ? $stashed->article : Example08SemanticSearch::DEMO_QUESTION;

        return $this->page(200, $question, '', $stashed?->result);
    }

    public function ask(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        $question = $request->input('question');

        try {
            $result = $this->example->ask($question, $user->id);
        } catch (InvalidExampleInput $exception) {
            return $this->page(422, $question, $exception->getMessage(), null);
        } catch (EmbeddingFailed $exception) {
            return $this->page(503, $question, $exception->getMessage(), null);
        } catch (AiBudgetExceeded $exception) {
            return $this->page(429, $question, $exception->getMessage(), null);
        } catch (LlmCallFailed|InvalidModelOutput $exception) {
            return $this->page(502, $question, $exception->getMessage(), null);
        }

        $this->stash->put($result, trim($question));

        return Response::redirect(self::PATH);
    }

    public function reindex(Request $request): Response
    {
        if ($this->auth->user() === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        try {
            $report = $this->indexer->update();
            $this->flash->set(sprintf(
                'Index aktualizován: zaindexováno %d, odebráno %d, čeká %d (model %s).',
                $report->indexed,
                $report->removed,
                $report->remaining,
                $report->model,
            ));
        } catch (EmbeddingFailed $exception) {
            // Zpráva EmbeddingFailed je česká a bez tajemství (plán 009, AC 4 a 9).
            $this->flash->set('Indexace selhala: ' . $exception->getMessage());
        }

        return Response::redirect(self::PATH);
    }

    private function page(int $status, string $question, string $error, ?ExampleResult $result): Response
    {
        $provider = $this->indexer->provider();

        return Response::html($this->renderer->render('admin/ai/semantic-search', [
            'title' => sprintf('%s – %s', $this->example->id(), $this->example->title()),
            'example' => $this->example,
            'action' => self::PATH,
            'reindexAction' => self::REINDEX_PATH,
            'indexStatus' => $this->indexer->status(),
            'embeddingModel' => $this->indexer->model(),
            'embeddingProvider' => EmbeddingProvider::fromLogName($provider)?->label() ?? $provider,
            'flash' => $this->flash->pull(),
            'question' => $question,
            'error' => $error,
            'result' => $result,
            'csrfToken' => $this->csrf->token(),
        ]), $status);
    }
}
