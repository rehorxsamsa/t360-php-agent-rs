<?php

declare(strict_types=1);

namespace App\Ai\Tools;

/**
 * Výsledek nástroje. `content` jde zpět modelu (jako `tool_result`), `summary` je krátký český popis
 * pro administraci. Výsledek je pro model nedůvěryhodná data (text článků může obsahovat pokyny),
 * proto se nikdy nevkládá do systémového promptu.
 */
final readonly class ToolResult
{
    /** Nejvyšší délka obsahu pro model (znaky), aby jeden nástroj nezahltil kontext. */
    public const int MAX_LENGTH = 8000;

    public function __construct(
        public string $content,
        public bool $isError,
        public string $summary,
        public ?string $sourceUrl = null,
    ) {}

    /** Úspěšný výsledek; obsah delší než `MAX_LENGTH` se uřízne. */
    public static function success(string $content, string $summary, ?string $sourceUrl = null): self
    {
        return new self(mb_substr($content, 0, self::MAX_LENGTH), false, $summary, $sourceUrl);
    }

    /** Chyba, kterou model uvidí jako `tool_result` s `is_error` a může na ni reagovat. */
    public static function failure(string $message): self
    {
        return new self(mb_substr($message, 0, self::MAX_LENGTH), true, 'Chyba: ' . $message);
    }
}
