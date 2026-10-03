<?php

declare(strict_types=1);

namespace App\Infrastructure\Session;

use App\Http\Session\Session;

/**
 * Nativní PHP session a jediné místo, které sahá na $_SESSION.
 * Startuje líně: bez čtení či zápisu se cookie ani soubor session nevytvoří.
 */
final class NativeSession implements Session
{
    public function __construct(
        private readonly bool $secureCookie,
        private readonly string $name = 'redakce_session',
    ) {}

    public function get(string $key): string|int|null
    {
        $this->start();
        $value = $_SESSION[$key] ?? null;

        return is_string($value) || is_int($value) ? $value : null;
    }

    public function set(string $key, string|int $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function pull(string $key): string|int|null
    {
        $value = $this->get($key);
        unset($_SESSION[$key]);

        return $value;
    }

    public function regenerateId(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    public function invalidate(): void
    {
        $this->start();
        $_SESSION = [];
        session_regenerate_id(true);
    }

    private function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $started = session_start([
            'name' => $this->name,
            'cookie_lifetime' => 0,
            'cookie_path' => '/',
            'cookie_secure' => $this->secureCookie,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict',
            'use_strict_mode' => true,
            'use_only_cookies' => true,
        ]);

        if (!$started) {
            throw new \RuntimeException('Nepodařilo se spustit session.');
        }
    }
}
