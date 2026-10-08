<?php

declare(strict_types=1);

namespace App\Ai\Examples;

use App\Ai\AiBudgetExceeded;
use App\Ai\AiConfig;
use App\Ai\LlmCallFailed;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;
use App\Ai\PromptData;
use App\Ai\PromptLibrary;
use App\Ai\StreamingLlmClient;

/**
 * 06 – Asistent psaní: „pokračuj / zkrať / zjednoduš“ s živým výpisem (streaming, ADR-0008).
 * Text se předává modelu uvnitř značky `<text>` (data, ne pokyny); výstup modelu je nedůvěryhodný
 * a zobrazuje se jen jako prostý text.
 */
final readonly class Example06WritingAssistant implements ExampleDescription
{
    public const string DEMO_TEXT = 'Redakce dnes spustila nový web. Čtenáři v něm najdou rychlejší vyhledávání, přehlednější rubriky '
        . 'a možnost uložit si články na později. Autoři si naopak pochvalují jednodušší editor.';

    private const int MAX_TOKENS = 1000;

    public function __construct(
        private StreamingLlmClient $client,
        private PromptLibrary $prompts,
        private AiConfig $config,
    ) {}

    public function id(): string
    {
        return '06';
    }

    public function title(): string
    {
        return 'Asistent psaní';
    }

    public function description(): string
    {
        return 'Pokračuje v odstavci, zkrátí ho nebo zjednoduší a text se vypisuje živě (streaming SSE). '
            . 'Generování jde kdykoli přerušit.';
    }

    /** Požadavek na model; text se zneškodněním vložených značek jde do `<text>`, za něj pokyn akce. */
    public function request(WritingTask $task, ?int $userId): LlmRequest
    {
        return new LlmRequest(
            model: $this->config->model,
            system: $this->prompts->system('06-writing-assistant'),
            messages: [[
                'role' => 'user',
                'content' => "<text>\n" . PromptData::neutralize($task->text) . "\n</text>\n\nÚkol: " . $task->action->instruction(),
            ]],
            maxTokens: self::MAX_TOKENS,
            exampleId: $this->id(),
            userId: $userId,
            effort: 'low',
        );
    }

    /**
     * Generuje text a každý přírůstek předává `$onText`; vrátí-li callback `false`, generování se přeruší.
     *
     * @param callable(string): bool $onText
     * @throws LlmCallFailed
     * @throws AiBudgetExceeded
     */
    public function stream(WritingTask $task, ?int $userId, callable $onText): LlmResponse
    {
        return $this->client->stream($this->request($task, $userId), $onText);
    }
}
