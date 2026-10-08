<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Ai\AiBudgetExceeded;
use App\Ai\Examples\Example06WritingAssistant;
use App\Ai\Examples\InvalidExampleInput;
use App\Ai\Examples\WritingAction;
use App\Ai\Examples\WritingTask;
use App\Ai\LlmCallFailed;
use App\Http\Auth\AuthSession;
use App\Http\Request;
use App\Http\Response;
use App\Http\Security\CsrfToken;
use App\Http\Session\Session;
use App\Http\Stream\SseWriter;
use App\Http\Stream\StreamOutput;
use App\Http\View\TemplateRenderer;

/**
 * Administrace: AI příklad 06 – Asistent psaní se živým výstupem (streaming, ADR-0008).
 *
 * GET jen vykreslí formulář (nic nevolá). POST `/admin/ai/06/proud` (fetch z `ai-stream.js`) ověří vstup,
 * uvolní zámek session a vrátí proud Server-Sent Events: `: start`, `delta` (0..n×), pak `done` nebo `error`.
 * Producent běží až v `Response::send()`, mimo middleware – proto každou výjimku převádí na událost `error`
 * a detail neznámé chyby jde jen do `error_log`. Výstup modelu je nedůvěryhodný: jde do prohlížeče jen
 * jako JSON v `data:` a stránka ho vkládá přes `textContent`.
 */
final readonly class WritingAssistantController
{
    private const string LOGIN_PATH = '/admin/prihlaseni';
    private const string INTERNAL_ERROR = 'Interní chyba serveru.';

    public function __construct(
        private TemplateRenderer $renderer,
        private Example06WritingAssistant $example,
        private AuthSession $auth,
        private CsrfToken $csrf,
        private Session $session,
    ) {}

    public function show(Request $request): Response
    {
        // Obrana do hloubky: přístup hlídá už AdminAccessMiddleware, kontrola je i zde.
        if ($this->auth->user() === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        return Response::html($this->renderer->render('admin/ai/writing-assistant', [
            'title' => sprintf('%s – %s', $this->example->id(), $this->example->title()),
            'example' => $this->example,
            'actions' => WritingAction::cases(),
            'selectedAction' => WritingAction::Continue,
            'text' => Example06WritingAssistant::DEMO_TEXT,
            'csrfToken' => $this->csrf->token(),
        ]));
    }

    public function stream(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect(self::LOGIN_PATH);
        }

        try {
            $task = WritingTask::fromInput($request->input('action'), $request->input('text'));
        } catch (InvalidExampleInput $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }

        // Proud může běžet desítky sekund; se zamčenou session by čekaly všechny další požadavky admina.
        // Od teď se session v tomto požadavku už nečte ani nezapisuje.
        $this->session->release();

        $example = $this->example;
        $userId = $user->id;

        return Response::stream(static function (StreamOutput $output) use ($example, $task, $userId): void {
            self::produce($output, $example, $task, $userId);
        });
    }

    /** Tělo proudu; po přerušení klientem už nic nezapisuje (ani `done`, ani `error`). */
    private static function produce(StreamOutput $output, Example06WritingAssistant $example, WritingTask $task, int $userId): void
    {
        $sse = new SseWriter($output);
        // Úvodní komentář odešle hlavičky hned – prohlížeč může ukázat „Generuji…“ ještě před první deltou.
        $sse->comment('start');

        try {
            $response = $example->stream(
                $task,
                $userId,
                static fn(string $text): bool => $sse->event('delta', ['text' => $text]),
            );
        } catch (AiBudgetExceeded $exception) {
            self::error($sse, $output, $exception->getMessage());

            return;
        } catch (LlmCallFailed $exception) {
            // Jen obecná česká hláška podle typu chyby, nikdy detail z API.
            self::error($sse, $output, $exception->type->userMessage());

            return;
        } catch (\Throwable $exception) {
            error_log(sprintf('Asistent psaní (06): %s: %s', $exception::class, $exception->getMessage()));
            self::error($sse, $output, self::INTERNAL_ERROR);

            return;
        }

        if ($output->isAborted() || $response->stopReason === 'aborted') {
            return;
        }

        $sse->event('done', [
            'stopReason' => $response->stopReason,
            'model' => $response->model,
            'provider' => $response->provider,
            'inputTokens' => $response->usage->input,
            'outputTokens' => $response->usage->output,
            'costUsd' => $response->costUsd ?? 0.0,
        ]);
    }

    private static function error(SseWriter $sse, StreamOutput $output, string $message): void
    {
        if (!$output->isAborted()) {
            $sse->event('error', ['message' => $message]);
        }
    }
}
