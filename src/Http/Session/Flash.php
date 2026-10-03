<?php

declare(strict_types=1);

namespace App\Http\Session;

/** Jednorázová zpráva, která přežije přesměrování (PRG) a po prvním přečtení zmizí. */
final readonly class Flash
{
    private const string KEY = 'flash';

    public function __construct(private Session $session) {}

    public function set(string $message): void
    {
        $this->session->set(self::KEY, $message);
    }

    /** Přečte a smaže zprávu; bez zprávy vrací prázdný řetězec. */
    public function pull(): string
    {
        $message = $this->session->pull(self::KEY);

        return $message === null ? '' : (string) $message;
    }
}
