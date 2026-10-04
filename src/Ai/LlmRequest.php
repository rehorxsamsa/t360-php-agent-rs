<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Požadavek na model. Záměrně neobsahuje teplotu ani vynucený nástroj
 * (nové modely je odmítají – viz ADR-0006). `exampleId` a `userId` jsou jen metadata
 * pro log; do API se neposílají.
 */
final readonly class LlmRequest
{
    public const int MAX_TOKENS_LIMIT = 16000;

    /** @var list<string> */
    private const array EFFORTS = ['low', 'medium', 'high'];

    /**
     * @param list<array{role: 'user'|'assistant', content: string}> $messages
     * @param array<string, mixed>|null $jsonSchema schéma pro `output_config.format`
     */
    public function __construct(
        public string $model,
        public string $system,
        public array $messages,
        public int $maxTokens,
        public string $exampleId,
        public ?int $userId = null,
        public ?string $effort = null,
        public ?array $jsonSchema = null,
        public bool $cacheSystem = false,
    ) {
        if ($maxTokens < 1 || $maxTokens > self::MAX_TOKENS_LIMIT) {
            throw new \InvalidArgumentException(sprintf('maxTokens musí být 1 až %d.', self::MAX_TOKENS_LIMIT));
        }

        if ($messages === []) {
            throw new \InvalidArgumentException('Požadavek musí mít alespoň jednu zprávu.');
        }

        if ($messages[array_key_last($messages)]['role'] !== 'user') {
            throw new \InvalidArgumentException('Poslední zpráva požadavku musí mít roli user.');
        }

        if ($effort !== null && !in_array($effort, self::EFFORTS, true)) {
            throw new \InvalidArgumentException('effort musí být low, medium, high nebo null.');
        }
    }

    /**
     * Kopie požadavku s jiným seznamem zpráv (opakování po neplatné odpovědi).
     *
     * @param list<array{role: 'user'|'assistant', content: string}> $messages
     */
    public function withMessages(array $messages): self
    {
        return new self(
            $this->model,
            $this->system,
            $messages,
            $this->maxTokens,
            $this->exampleId,
            $this->userId,
            $this->effort,
            $this->jsonSchema,
            $this->cacheSystem,
        );
    }
}
