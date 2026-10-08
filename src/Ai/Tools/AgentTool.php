<?php

declare(strict_types=1);

namespace App\Ai\Tools;

/**
 * Nástroj, který smí model zavolat (ADR-0008). Nástroje jsou výhradně čtecí: nic nezapisují, nemažou
 * ani nevykonávají. Vstup (`input`) pochází z modelu, tedy je nedůvěryhodný: `run()` ho musí zvalidovat
 * a špatný vstup vrátit jako `ToolResult` s chybou, nikdy výjimkou.
 */
interface AgentTool
{
    /** Jméno nástroje, jak ho zná model (zamčený kontrakt, např. `hledej_clanky`). */
    public function name(): string;

    /**
     * Definice nástroje ve tvaru Claude API.
     *
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public function definition(): array;

    /**
     * @param array<mixed> $input vstup nástroje od modelu
     */
    public function run(array $input): ToolResult;
}
