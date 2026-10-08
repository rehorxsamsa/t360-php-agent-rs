<?php

declare(strict_types=1);

namespace App\Ai;

use App\Domain\Ai\TokenUsage;

/**
 * Odpověď modelu: text z bloků `text`, spotřeba tokenů a metadata volání.
 * Pro tool use navíc `content` (surové bloky odpovědi, které se při dalším kroku vrací beze změny,
 * včetně bloků `thinking` se `signature`) a `toolCalls` (bloky `tool_use` jako `ToolCall`).
 */
final readonly class LlmResponse
{
    /**
     * @param list<array<string, mixed>> $content surové bloky odpovědi (u streamu prázdné)
     * @param list<ToolCall> $toolCalls
     */
    public function __construct(
        public string $text,
        public string $model,
        public string $stopReason,
        public TokenUsage $usage,
        public string $provider,
        public ?string $requestId = null,
        public int $attempts = 1,
        public ?float $costUsd = null,
        public array $content = [],
        public array $toolCalls = [],
    ) {}

    public function withCost(float $costUsd): self
    {
        return new self(
            $this->text,
            $this->model,
            $this->stopReason,
            $this->usage,
            $this->provider,
            $this->requestId,
            $this->attempts,
            $costUsd,
            $this->content,
            $this->toolCalls,
        );
    }
}
