<?php

declare(strict_types=1);

namespace App\Ai\Examples;

use App\Ai\AiBudgetExceeded;
use App\Ai\LlmCallFailed;

interface AiExample extends ExampleDescription
{
    /**
     * Modely, z nichž si uživatel smí vybrat (prázdné = příklad volbu modelu nemá).
     *
     * @return list<string>
     */
    public function modelChoices(): array;

    /**
     * @throws InvalidExampleInput
     * @throws InvalidModelOutput
     * @throws LlmCallFailed
     * @throws AiBudgetExceeded
     */
    public function run(ArticleSnapshot $article, ExampleContext $context): ExampleResult;
}
