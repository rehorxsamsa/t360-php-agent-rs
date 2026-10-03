<?php

declare(strict_types=1);

namespace App\Http\Security;

use App\Http\Session\Session;

/** CSRF token jedné session (synchronizer token), porovnávaný v konstantním čase. */
final readonly class CsrfToken
{
    private const string KEY = '_csrf';

    public function __construct(private Session $session) {}

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(32));
        $this->session->set(self::KEY, $token);

        return $token;
    }

    public function isValid(string $submitted): bool
    {
        $expected = $this->session->get(self::KEY);

        return $submitted !== '' && is_string($expected) && $expected !== '' && hash_equals($expected, $submitted);
    }

    /** Zapomene token, další token() vydá nový (po přihlášení). */
    public function rotate(): void
    {
        $this->session->remove(self::KEY);
    }
}
