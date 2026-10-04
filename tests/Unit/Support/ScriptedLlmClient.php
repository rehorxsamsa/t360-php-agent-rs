<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Ai\LlmClient;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;

/**
 * Skriptovaný LLM klient (plán 006, §5): fronta odpovědí/výjimek, zaznamenává každý LlmRequest.
 * S `delegatingTo()` po vyčerpání fronty předá požadavek vnitřnímu klientovi (např. FakeLlmClient)
 * a zaznamená i jeho odpověď.
 */
final class ScriptedLlmClient implements LlmClient
{
    /** @var list<LlmResponse|\Throwable> */
    private array $queue = [];

    /** @var list<LlmRequest> */
    public array $requests = [];

    /** @var list<LlmResponse> */
    public array $responses = [];

    public function __construct(private readonly ?LlmClient $fallback = null) {}

    public static function delegatingTo(LlmClient $inner): self
    {
        return new self($inner);
    }

    public function push(LlmResponse|\Throwable ...$items): self
    {
        foreach ($items as $item) {
            $this->queue[] = $item;
        }

        return $this;
    }

    /** Zařadí textové odpovědi (stop_reason end_turn, malé usage a cena). */
    public function pushText(string ...$texts): self
    {
        foreach ($texts as $text) {
            $this->queue[] = AiFixtures::response($text);
        }

        return $this;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);
        if ($next === null) {
            if ($this->fallback === null) {
                throw new \LogicException('Skriptovaný LLM klient nemá další odpověď.');
            }
            $next = $this->fallback->complete($request);
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        $this->responses[] = $next;

        return $next;
    }
}
