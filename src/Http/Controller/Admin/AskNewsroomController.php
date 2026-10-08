<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Ai\AiBudgetExceeded;
use App\Ai\Examples\Example07AskNewsroom;
use App\Ai\Examples\ExampleResult;
use App\Ai\Examples\InvalidExampleInput;
use App\Ai\Examples\InvalidModelOutput;
use App\Ai\LlmCallFailed;
use App\Http\Auth\AuthSession;
use App\Http\Request;
use App\Http\Response;
use App\Http\Security\CsrfToken;
use App\Http\Session\ExampleResultStash;
use App\Http\View\TemplateRenderer;

/**
 * Administrace: AI příklad 07 – Zeptej se redakce (tool use, ADR-0008). Stejný tok jako příklady 01–05:
 * POST spustí agenta a přesměruje (PRG), výsledek se ukáže jednou ze session. GET nikdy nic nevolá.
 * Agent smí jen číst publikované články; výstup modelu i nástrojů se v šabloně jen escapuje.
 */
final readonly class AskNewsroomController
{
    private const string LOGIN_PATH = '/admin/prihlaseni';
    private const string PATH = '/admin/ai/07';

    public function __construct(
        private TemplateRenderer $renderer,
        private Example07AskNewsroom $example,
        private AuthSession $auth,
        private CsrfToken $csrf,
        private ExampleResultStash $stash,
    ) {}

    public function show(Request $request): Response
    {
        // Obrana do hloubky: přístup hlídá už AdminAccessMiddleware, kontrola je i zde.
        if ($this->auth->user() === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        // Slot „article“ stashe nese u příkladu 07 text otázky, se kterou výsledek vznikl.
        $stashed = $this->stash->pull($this->example->id());
        $question = $stashed !== null && $stashed->article !== '' ? $stashed->article : Example07AskNewsroom::DEMO_QUESTION;

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
        } catch (AiBudgetExceeded $exception) {
            return $this->page(429, $question, $exception->getMessage(), null);
        } catch (LlmCallFailed|InvalidModelOutput $exception) {
            return $this->page(502, $question, $exception->getMessage(), null);
        }

        $this->stash->put($result, trim($question));

        return Response::redirect(self::PATH);
    }

    private function page(int $status, string $question, string $error, ?ExampleResult $result): Response
    {
        return Response::html($this->renderer->render('admin/ai/ask-newsroom', [
            'title' => sprintf('%s – %s', $this->example->id(), $this->example->title()),
            'example' => $this->example,
            'action' => self::PATH,
            'question' => $question,
            'error' => $error,
            'result' => $result,
            'csrfToken' => $this->csrf->token(),
        ]), $status);
    }
}
