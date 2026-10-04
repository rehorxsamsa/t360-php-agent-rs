<?php

declare(strict_types=1);

namespace App\Ai\Examples;

use App\Ai\AiConfig;
use App\Ai\LlmClient;
use App\Ai\LlmRequest;
use App\Ai\PromptData;
use App\Ai\PromptLibrary;

/** 01 – Perex na jedno kliknutí: první volání API, volný text místo JSON. */
final readonly class Example01Excerpt implements AiExample
{
    private const int MAX_LENGTH = 300;
    private const int MAX_TOKENS = 400;

    public function __construct(
        private LlmClient $client,
        private PromptLibrary $prompts,
        private AiConfig $config,
    ) {}

    public function id(): string
    {
        return '01';
    }

    public function title(): string
    {
        return 'Perex na jedno kliknutí';
    }

    public function description(): string
    {
        return 'Z textu článku vygeneruje perex do 300 znaků. První volání API: system prompt, max_tokens a cena volání.';
    }

    public function modelChoices(): array
    {
        return [];
    }

    public function run(ArticleSnapshot $article, ExampleContext $context): ExampleResult
    {
        $response = $this->client->complete(new LlmRequest(
            model: $this->config->model,
            system: $this->prompts->system('01-excerpt'),
            messages: [['role' => 'user', 'content' => PromptData::article($article) . "\n\nÚkol: napiš perex tohoto článku."]],
            maxTokens: self::MAX_TOKENS,
            exampleId: $this->id(),
            userId: $context->userId,
            effort: 'low',
        ));

        $excerpt = trim($response->text);
        if ($response->stopReason === 'refusal') {
            throw new InvalidModelOutput('Model odmítl odpovědět (refusal).');
        }

        if ($excerpt === '') {
            throw new InvalidModelOutput('Model vrátil prázdný perex.');
        }

        $warnings = [];
        if ($response->stopReason === 'max_tokens') {
            $warnings[] = 'Odpověď byla useknuta limitem max_tokens.';
        }

        if (mb_strlen($excerpt) > self::MAX_LENGTH) {
            $excerpt = $this->shorten($excerpt);
            $warnings[] = sprintf('Model vrátil delší perex, zkráceno na %d znaků.', self::MAX_LENGTH);
        }

        return new ExampleResult(
            $this->id(),
            [['label' => 'Perex', 'value' => $excerpt]],
            $warnings,
            $response->text,
            $response->usage,
            $response->costUsd ?? 0.0,
            $response->model,
            $response->provider,
            1,
        );
    }

    /** Zkrátí na hranici slova tak, aby výsledek včetně `…` měl nejvýše 300 znaků. */
    private function shorten(string $text): string
    {
        $cut = mb_substr($text, 0, self::MAX_LENGTH - 1);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > 0) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t\n\r,;:-–") . '…';
    }
}
