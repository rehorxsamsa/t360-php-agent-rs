<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Požadavek modelu na zavolání nástroje (blok `tool_use` z odpovědi). Jsou to jen data:
 * nic se nevykonává, dokud to výslovně neudělá smyčka příkladu (ADR-0008).
 */
final readonly class ToolCall
{
    /** @param array<string, mixed> $input vstup nástroje; nedůvěryhodný, každý nástroj ho musí validovat */
    public function __construct(
        public string $id,
        public string $name,
        public array $input,
    ) {}
}
