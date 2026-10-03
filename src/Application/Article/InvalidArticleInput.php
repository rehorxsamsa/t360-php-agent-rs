<?php

declare(strict_types=1);

namespace App\Application\Article;

/** Formulář článku obsahuje chyby; `errors` mapuje název pole na českou hlášku. */
final class InvalidArticleInput extends \RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors, ?\Throwable $previous = null)
    {
        parent::__construct('Formulář článku obsahuje chyby.', 0, $previous);
    }
}
