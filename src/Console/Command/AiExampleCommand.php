<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Ai\AiBudgetExceeded;
use App\Ai\AiProvider;
use App\Ai\Embedding\EmbeddingFailed;
use App\Ai\Examples\DemoArticles;
use App\Ai\Examples\Example06WritingAssistant;
use App\Ai\Examples\Example07AskNewsroom;
use App\Ai\Examples\Example08SemanticSearch;
use App\Ai\Examples\Example09AiEditor;
use App\Ai\Examples\Example10McpServer;
use App\Ai\Examples\ExampleContext;
use App\Ai\Examples\ExampleRegistry;
use App\Ai\Examples\ExampleResult;
use App\Ai\Examples\ExampleRunner;
use App\Ai\Examples\InvalidExampleInput;
use App\Ai\Examples\InvalidModelOutput;
use App\Ai\Examples\WritingAction;
use App\Ai\Examples\WritingTask;
use App\Ai\LlmCallFailed;
use App\Console\Command;
use App\Console\Output;
use App\Domain\Ai\TokenUsage;

/**
 * ai:priklad NN – spustí AI příklad 01–09 (10 je MCP server, tady jen odkáže na `mcp:server`).
 * Volby: `--clanek=` a `--model=` (01–05), `--akce=` a `--text=` (06),
 * `--otazka=` (07 a 08), `--tema=` (09: jen náhled návrhu, nic se neukládá). Z konzole se volání loguje
 * bez uživatele (`userId null`).
 */
final readonly class AiExampleCommand implements Command
{
    private const string USAGE = 'Použití: php bin/konzole ai:priklad 01–09 [--clanek=…] [--model=ID] '
        . '[--akce=pokracuj|zkrat|zjednodus] [--text=…] [--otazka=…] [--tema=…]';

    public function __construct(
        private ExampleRegistry $registry,
        private ExampleRunner $runner,
        private Example06WritingAssistant $writing,
        private Example07AskNewsroom $askNewsroom,
        private Example08SemanticSearch $semanticSearch,
        private Example09AiEditor $aiEditor,
        private Example10McpServer $mcpServer,
    ) {}

    public function run(array $arguments, Output $output): int
    {
        $parsed = $this->parse($arguments);
        if ($parsed === null) {
            $output->error(self::USAGE);

            return 1;
        }

        try {
            return match ($parsed['id']) {
                '06' => $this->runWriting($parsed, $output),
                '07' => $this->runAskNewsroom($parsed, $output),
                '08' => $this->runSemanticSearch($parsed, $output),
                '09' => $this->runAiEditor($parsed, $output),
                '10' => $this->explainMcpServer($output),
                default => $this->runArticleExample($parsed, $output),
            };
        } catch (InvalidExampleInput|InvalidModelOutput|EmbeddingFailed|LlmCallFailed|AiBudgetExceeded $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }

    /**
     * @param array{id: string, article: string, model: string, action: string, text: ?string, question: ?string, topic: ?string} $parsed
     */
    private function runArticleExample(array $parsed, Output $output): int
    {
        $example = $this->registry->get($parsed['id']);
        if ($example === null) {
            $output->error(self::USAGE);

            return 1;
        }

        if ($parsed['model'] !== '' && $example->modelChoices() === []) {
            $output->error(sprintf('Upozornění: příklad %s volbu modelu nemá, použije se AI_MODEL.', $example->id()));
            $parsed['model'] = '';
        }

        $article = $this->runner->loadArticle($example->id(), $parsed['article']);
        // Z konzole se volání loguje bez uživatele (userId null).
        $result = $this->runner->run($example->id(), $parsed['article'], new ExampleContext(null, $parsed['model']));

        $output->line(sprintf('Příklad %s – %s (článek: %s)', $example->id(), $example->title(), $article->title));
        $this->printResult($result, $output);

        return 0;
    }

    /**
     * @param array{id: string, article: string, model: string, action: string, text: ?string, question: ?string, topic: ?string} $parsed
     */
    private function runWriting(array $parsed, Output $output): int
    {
        $action = WritingAction::tryFrom($parsed['action']);
        if ($action === null) {
            $output->error('Vyberte akci.');
            $output->error(self::USAGE);

            return 1;
        }

        $task = WritingTask::fromInput($parsed['action'], $parsed['text'] ?? Example06WritingAssistant::DEMO_TEXT);

        $output->line(sprintf('Příklad %s – %s (akce: %s)', $this->writing->id(), $this->writing->title(), $action->label()));

        // Text se vypisuje průběžně po přírůstcích, jak ho model generuje.
        $response = $this->writing->stream($task, null, static function (string $delta) use ($output): bool {
            $output->write($delta);

            return true;
        });
        $output->line('');

        if ($response->stopReason === 'max_tokens') {
            $output->line('Upozornění: Odpověď byla useknuta limitem max_tokens.');
        }

        $this->printSummary($response->model, $response->provider, 1, $response->usage, $response->costUsd ?? 0.0, $output);

        return 0;
    }

    /**
     * @param array{id: string, article: string, model: string, action: string, text: ?string, question: ?string, topic: ?string} $parsed
     */
    private function runAskNewsroom(array $parsed, Output $output): int
    {
        $result = $this->askNewsroom->ask($parsed['question'] ?? Example07AskNewsroom::DEMO_QUESTION, null);

        $output->line(sprintf('Příklad %s – %s', $this->askNewsroom->id(), $this->askNewsroom->title()));
        $this->printResult($result, $output);

        return 0;
    }

    /**
     * @param array{id: string, article: string, model: string, action: string, text: ?string, question: ?string, topic: ?string} $parsed
     */
    private function runSemanticSearch(array $parsed, Output $output): int
    {
        $result = $this->semanticSearch->ask($parsed['question'] ?? Example08SemanticSearch::DEMO_QUESTION, null);

        $output->line(sprintf('Příklad %s – %s', $this->semanticSearch->id(), $this->semanticSearch->title()));
        $this->printResult($result, $output);

        return 0;
    }

    /**
     * Náhled návrhu AI redaktoru. Nic se neukládá: konzole nemá schvalovací krok ani přihlášeného administrátora.
     *
     * @param array{id: string, article: string, model: string, action: string, text: ?string, question: ?string, topic: ?string} $parsed
     */
    private function runAiEditor(array $parsed, Output $output): int
    {
        $proposal = $this->aiEditor->draft($parsed['topic'] ?? Example09AiEditor::DEMO_TOPIC, null);

        $output->line(sprintf('Příklad %s – %s', $this->aiEditor->id(), $this->aiEditor->title()));
        $this->printResult($proposal->result, $output);
        $output->line('Návrh se neukládá – uložit ho jako koncept může jen administrátor na /admin/ai/09.');

        return 0;
    }

    /** Příklad 10 nemá co spustit z konzole: MCP server startuje klient (Claude Code) příkazem `mcp:server`. */
    private function explainMcpServer(Output $output): int
    {
        $output->error(sprintf(
            'Příklad %s (%s) se nespouští přes ai:priklad: php bin/konzole mcp:server, návod je na /admin/ai/%s.',
            $this->mcpServer->id(),
            $this->mcpServer->title(),
            $this->mcpServer->id(),
        ));

        return 1;
    }

    /**
     * @param list<string> $arguments
     * @return array{id: string, article: string, model: string, action: string, text: ?string, question: ?string, topic: ?string}|null
     *         null při špatném zápisu nebo neznámém čísle příkladu
     */
    private function parse(array $arguments): ?array
    {
        $id = null;
        $options = ['clanek' => DemoArticles::STANDARD, 'model' => '', 'akce' => WritingAction::Continue->value, 'text' => null, 'otazka' => null, 'tema' => null];

        foreach ($arguments as $argument) {
            if (preg_match('/^--(clanek|model|akce|text|otazka|tema)=(.*)$/s', $argument, $matches) === 1) {
                $options[$matches[1]] = $matches[2];
            } elseif ($id === null && !str_starts_with($argument, '-')) {
                $id = $argument;
            } else {
                return null;
            }
        }

        if ($id === null || !in_array($id, ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10'], true)) {
            return null;
        }

        return [
            'id' => $id,
            'article' => (string) $options['clanek'],
            'model' => (string) $options['model'],
            'action' => (string) $options['akce'],
            'text' => $options['text'],
            'question' => $options['otazka'],
            'topic' => $options['tema'],
        ];
    }

    private function printResult(ExampleResult $result, Output $output): void
    {
        foreach ($result->fields as $field) {
            if (str_contains($field['value'], "\n")) {
                $output->line($field['label'] . ':');
                $output->line($field['value']);
            } else {
                $output->line($field['label'] . ': ' . $field['value']);
            }
        }

        foreach ($result->warnings as $warning) {
            $output->line('Upozornění: ' . $warning);
        }

        $this->printSummary($result->model, $result->provider, $result->calls, $result->usage, $result->costUsd, $output);
    }

    private function printSummary(string $model, string $provider, int $calls, TokenUsage $usage, float $costUsd, Output $output): void
    {
        $label = AiProvider::fromLogName($provider)?->shortLabel() ?? $provider;
        $output->line(sprintf(
            'Model %s · poskytovatel %s · volání %d · tokeny vstup %d / výstup %d · cena %s USD',
            $model,
            $label,
            $calls,
            $usage->input,
            $usage->output,
            number_format($costUsd, 6, ',', ' '),
        ));

        if ($provider === AiProvider::Fake->logName()) {
            $output->line('Falešný klient: cena je jen orientační, nic se neúčtovalo.');
        }
    }
}
