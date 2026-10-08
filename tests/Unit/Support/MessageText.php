<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

/**
 * Obsah zprávy `LlmRequest::$messages` jako text. Od plánu 008 může být obsah i seznam bloků
 * (tool use); testy příkladů 01–05 posílají vždy text, jinak jde o chybu.
 */
final class MessageText
{
    /** @param string|list<array<string, mixed>> $content */
    public static function of(string|array $content): string
    {
        if (!is_string($content)) {
            throw new \UnexpectedValueException('Zpráva má obsah v blocích, test očekává text.');
        }

        return $content;
    }

    /**
     * Texty všech zpráv v pořadí.
     *
     * @param list<array{role: string, content: string|list<array<string, mixed>>}> $messages
     *
     * @return list<string>
     */
    public static function all(array $messages): array
    {
        return array_map(
            static fn(array $message): string => self::of($message['content']),
            $messages,
        );
    }
}
