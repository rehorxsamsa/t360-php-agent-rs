<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Volání modelu selhalo. Zpráva je česká a bez tajemství (klíč, hlavičky, tělo požadavku).
 */
final class LlmCallFailed extends \RuntimeException
{
    /**
     * @param string|null $message vlastní zpráva; jinak `LlmErrorType::userMessage()`
     */
    public function __construct(
        public readonly LlmErrorType $type,
        public readonly ?int $httpStatus = null,
        public readonly int $attempts = 1,
        public readonly ?string $requestId = null,
        ?string $message = null,
    ) {
        parent::__construct($message ?? $type->userMessage());
    }
}
