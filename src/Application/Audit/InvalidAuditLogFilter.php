<?php

declare(strict_types=1);

namespace App\Application\Audit;

/** Filtr audit logu obsahuje chyby; `errors` mapuje název pole (`akce`, `od`, `do`) na českou hlášku. */
final class InvalidAuditLogFilter extends \RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors, ?\Throwable $previous = null)
    {
        parent::__construct('Filtr audit logu obsahuje chyby.', 0, $previous);
    }
}
