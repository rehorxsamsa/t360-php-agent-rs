<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Ai\AiBudgetExceeded;
use App\Ai\AiUsageReport;
use App\Ai\Examples\AiExample;
use App\Ai\Examples\DemoArticles;
use App\Ai\Examples\ExampleContext;
use App\Ai\Examples\ExampleRegistry;
use App\Ai\Examples\ExampleResult;
use App\Ai\Examples\ExampleRunner;
use App\Ai\Examples\InvalidExampleInput;
use App\Ai\Examples\InvalidModelOutput;
use App\Ai\LlmCallFailed;
use App\Http\Auth\AuthSession;
use App\Http\PageNotFound;
use App\Http\Request;
use App\Http\Response;
use App\Http\Security\CsrfToken;
use App\Http\Session\ExampleResultStash;
use App\Http\View\TemplateRenderer;

/**
 * Administrace: AI nástroje – přehled spotřeby a spuštění příkladů 01–05 (PRG, výsledek jednou ze session).
 * Tenký controller: článek, limity a volání řeší ExampleRunner; LlmClient se odsud nikdy nevolá přímo.
 * GET nikdy nic nevolá (odkaz ani obnovení stránky nespustí placené volání).
 */
final readonly class AiController
{
    private const string LOGIN_PATH = '/admin/prihlaseni';
    private const string INVALID_MODEL = 'Vyberte model ze seznamu.';

    public function __construct(
        private TemplateRenderer $renderer,
        private ExampleRegistry $registry,
        private ExampleRunner $runner,
        private AiUsageReport $report,
        private AuthSession $auth,
        private CsrfToken $csrf,
        private ExampleResultStash $stash,
    ) {}

    public function index(Request $request): Response
    {
        // Obrana do hloubky: přístup hlídá už AdminAccessMiddleware, kontrola je i zde.
        if ($this->auth->user() === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        return Response::html($this->renderer->render('admin/ai/index', [
            'title' => 'AI nástroje',
            'provider' => $this->report->provider(),
            'models' => $this->report->models(),
            'today' => $this->report->today(),
            'dailyLimit' => $this->report->dailyLimit(),
            // Přehled ukazuje všechny příklady 01–10; spouštění přes show/run jen 01–05 (06–10 mají vlastní controllery).
            'examples' => $this->registry->listing(),
            'recentCalls' => $this->report->recent(),
            'csrfToken' => $this->csrf->token(),
        ]));
    }

    /** @throws PageNotFound neznámý příklad */
    public function show(Request $request): Response
    {
        if ($this->auth->user() === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        $example = $this->example($request);
        $models = $example->modelChoices();
        $articleOptions = $this->articleOptions();
        $stashed = $this->stash->pull($example->id());

        // Po PRG se formulář předvyplní volbami, se kterými výsledek vznikl. Hodnoty ze session
        // se berou jen tehdy, jsou-li v nabídce (článek mohl mezitím zmizet); jinak výchozí volba.
        $selectedArticle = DemoArticles::STANDARD;
        $selectedModel = $models[0] ?? '';
        if ($stashed !== null) {
            if (in_array($stashed->article, array_column($articleOptions, 'value'), true)) {
                $selectedArticle = $stashed->article;
            }
            if (in_array($stashed->model, $models, true)) {
                $selectedModel = $stashed->model;
            }
        }

        return $this->page(200, $example, $selectedArticle, $selectedModel, '', $stashed?->result, $articleOptions);
    }

    /** @throws PageNotFound neznámý příklad */
    public function run(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        $example = $this->example($request);
        $article = $request->input('article');
        $model = self::modelInput($request, $example);
        if ($model === null) {
            return $this->page(422, $example, $article, '', self::INVALID_MODEL, null);
        }

        try {
            $result = $this->runner->run($example->id(), $article, new ExampleContext($user->id, $model));
        } catch (InvalidExampleInput $exception) {
            return $this->page(422, $example, $article, $model, $exception->getMessage(), null);
        } catch (AiBudgetExceeded $exception) {
            return $this->page(429, $example, $article, $model, $exception->getMessage(), null);
        } catch (LlmCallFailed|InvalidModelOutput $exception) {
            return $this->page(502, $example, $article, $model, $exception->getMessage(), null);
        }

        $this->stash->put($result, $article, $model);

        return Response::redirect(self::examplePath($example->id()));
    }

    /**
     * Volba modelu jen u příkladu, který ji nabízí (05); jinde se pole ignoruje ('').
     * Chybějící pole = výchozí model (''); prázdný řetězec nebo pole (`model[]=…`) = neplatná volba (null).
     * Zda je model v nabídce, ověří až příklad (stejná hláška).
     */
    private static function modelInput(Request $request, AiExample $example): ?string
    {
        if ($example->modelChoices() === []) {
            return '';
        }
        if (array_key_exists('model', $request->bodyLists)) {
            return null;
        }
        if (!array_key_exists('model', $request->body)) {
            return '';
        }
        $model = $request->body['model'];

        return $model === '' ? null : $model;
    }

    /** @param list<array{value: string, label: string}>|null $articleOptions null = načíst */
    private function page(
        int $status,
        AiExample $example,
        string $selectedArticle,
        string $selectedModel,
        string $error,
        ?ExampleResult $result,
        ?array $articleOptions = null,
    ): Response {
        return Response::html($this->renderer->render('admin/ai/example', [
            'title' => sprintf('%s – %s', $example->id(), $example->title()),
            'example' => $example,
            'action' => self::examplePath($example->id()),
            'articleOptions' => $articleOptions ?? $this->articleOptions(),
            'selectedArticle' => $selectedArticle,
            'models' => $example->modelChoices(),
            'selectedModel' => $selectedModel,
            'error' => $error,
            'result' => $result,
            'csrfToken' => $this->csrf->token(),
        ]), $status);
    }

    /**
     * Volby výběru článku: dva ukázkové články (fungují i nad prázdnou DB) a články z administrace.
     *
     * @return list<array{value: string, label: string}>
     */
    private function articleOptions(): array
    {
        $options = [
            ['value' => DemoArticles::STANDARD, 'label' => 'Ukázkový článek (bez databáze)'],
            ['value' => DemoArticles::INJECTION, 'label' => 'Ukázka: článek s vloženým pokynem'],
        ];
        foreach ($this->runner->articleChoices() as $article) {
            $options[] = ['value' => (string) $article->id, 'label' => $article->title];
        }

        return $options;
    }

    /** @throws PageNotFound */
    private function example(Request $request): AiExample
    {
        return $this->registry->get($request->routeParameter('example')) ?? throw new PageNotFound();
    }

    private static function examplePath(string $exampleId): string
    {
        return '/admin/ai/' . rawurlencode($exampleId);
    }
}
