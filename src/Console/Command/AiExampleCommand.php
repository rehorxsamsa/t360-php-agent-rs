<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Ai\AiBudgetExceeded;
use App\Ai\AiProvider;
use App\Ai\Examples\DemoArticles;
use App\Ai\Examples\ExampleContext;
use App\Ai\Examples\ExampleRegistry;
use App\Ai\Examples\ExampleResult;
use App\Ai\Examples\ExampleRunner;
use App\Ai\Examples\InvalidExampleInput;
use App\Ai\Examples\InvalidModelOutput;
use App\Ai\LlmCallFailed;
use App\Console\Command;
use App\Console\Output;

/** ai:priklad NN [--clanek=demo|demo-injection|ID] [--model=ID] – spustí AI příklad 01–05. */
final readonly class AiExampleCommand implements Command
{
    private const string USAGE = 'Použití: php bin/konzole ai:priklad 01–05 [--clanek=demo|demo-injection|ID] [--model=ID]';

    public function __construct(
        private ExampleRegistry $registry,
        private ExampleRunner $runner,
    ) {}

    public function run(array $arguments, Output $output): int
    {
        $parsed = $this->parse($arguments);
        $example = $parsed === null ? null : $this->registry->get($parsed['id']);
        if ($parsed === null || $example === null) {
            $output->error(self::USAGE);

            return 1;
        }

        if ($parsed['model'] !== '' && $example->modelChoices() === []) {
            $output->error(sprintf('Upozornění: příklad %s volbu modelu nemá, použije se AI_MODEL.', $example->id()));
            $parsed['model'] = '';
        }

        try {
            $article = $this->runner->loadArticle($example->id(), $parsed['article']);
            // Z konzole se volání loguje bez uživatele (userId null).
            $result = $this->runner->run($example->id(), $parsed['article'], new ExampleContext(null, $parsed['model']));
        } catch (InvalidExampleInput|InvalidModelOutput|LlmCallFailed|AiBudgetExceeded $exception) {
            $output->error($exception->getMessage());

            return 1;
        }

        $output->line(sprintf('Příklad %s – %s (článek: %s)', $example->id(), $example->title(), $article->title));
        $this->printResult($result, $output);

        return 0;
    }

    /**
     * @param list<string> $arguments
     * @return array{id: string, article: string, model: string}|null null při špatném zápisu
     */
    private function parse(array $arguments): ?array
    {
        $id = null;
        $article = DemoArticles::STANDARD;
        $model = '';

        foreach ($arguments as $argument) {
            if (preg_match('/^--(clanek|model)=(.*)$/s', $argument, $matches) === 1) {
                if ($matches[1] === 'clanek') {
                    $article = $matches[2];
                } else {
                    $model = $matches[2];
                }
            } elseif ($id === null && !str_starts_with($argument, '-')) {
                $id = $argument;
            } else {
                return null;
            }
        }

        return $id === null ? null : ['id' => $id, 'article' => $article, 'model' => $model];
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

        $provider = AiProvider::fromLogName($result->provider)?->shortLabel() ?? $result->provider;
        $output->line(sprintf(
            'Model %s · poskytovatel %s · volání %d · tokeny vstup %d / výstup %d · cena %s USD',
            $result->model,
            $provider,
            $result->calls,
            $result->usage->input,
            $result->usage->output,
            number_format($result->costUsd, 6, ',', ' '),
        ));

        if ($result->provider === AiProvider::Fake->logName()) {
            $output->line('Falešný klient: cena je jen orientační, nic se neúčtovalo.');
        }
    }
}
