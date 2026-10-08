<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Požadavek na model. Záměrně neobsahuje teplotu ani vynucený nástroj
 * (nové modely je odmítají – viz ADR-0006). `exampleId` a `userId` jsou jen metadata
 * pro log; do API se neposílají.
 *
 * Tool use (ADR-0008) jsou jen data: `tools` jsou definice nástrojů ve tvaru API a obsah zprávy
 * může být seznam bloků (`tool_use`, `tool_result`, `thinking`…) vrácených beze změny.
 */
final readonly class LlmRequest
{
    public const int MAX_TOKENS_LIMIT = 16000;

    /** @var list<string> */
    private const array EFFORTS = ['low', 'medium', 'high'];

    /** Jméno nástroje podle pravidel API. */
    private const string TOOL_NAME_PATTERN = '/^[a-zA-Z0-9_-]{1,128}$/';

    /**
     * @param list<array{role: 'user'|'assistant', content: string|list<array<string, mixed>>}> $messages
     * @param array<string, mixed>|null $jsonSchema schéma pro `output_config.format`
     * @param list<array<string, mixed>>|null $tools definice nástrojů (`name`, `description`, `input_schema`)
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
        public ?array $tools = null,
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

        if ($tools !== null) {
            self::assertValidTools($tools);
        }
    }

    /**
     * Kopie požadavku s jiným seznamem zpráv (opakování po neplatné odpovědi, další krok smyčky).
     * Zachová i definice nástrojů.
     *
     * @param list<array{role: 'user'|'assistant', content: string|list<array<string, mixed>>}> $messages
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
            $this->tools,
        );
    }

    /** @param list<array<string, mixed>> $tools */
    private static function assertValidTools(array $tools): void
    {
        if ($tools === []) {
            throw new \InvalidArgumentException('Seznam nástrojů nesmí být prázdný (bez nástrojů předejte null).');
        }

        foreach ($tools as $tool) {
            $name = $tool['name'] ?? null;
            if (!is_string($name) || preg_match(self::TOOL_NAME_PATTERN, $name) !== 1) {
                throw new \InvalidArgumentException('Nástroj musí mít jméno z písmen a–z, číslic, „_“ a „-“ (1 až 128 znaků).');
            }
        }
    }
}
