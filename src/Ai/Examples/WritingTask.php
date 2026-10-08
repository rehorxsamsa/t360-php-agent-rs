<?php

declare(strict_types=1);

namespace App\Ai\Examples;

/** Zadání asistentovi psaní: akce a text (už zkontrolovaný a oříznutý o okrajové mezery). */
final readonly class WritingTask
{
    public const int MAX_LENGTH = 5000;

    public function __construct(
        public WritingAction $action,
        public string $text,
    ) {}

    /**
     * Vstup z formuláře nebo konzole je nedůvěryhodný: akce musí být ze známého výčtu a text neprázdný a nepříliš
     * dlouhý (cena roste s délkou). Při chybě se model nevolá.
     *
     * @throws InvalidExampleInput
     */
    public static function fromInput(string $action, string $text): self
    {
        $parsed = WritingAction::tryFrom($action) ?? throw new InvalidExampleInput('Vyberte akci.');

        $text = trim($text);
        if ($text === '') {
            throw new InvalidExampleInput('Zadejte text.');
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw new InvalidExampleInput('Text je pro asistenta příliš dlouhý (max. 5 000 znaků).');
        }

        return new self($parsed, $text);
    }
}
