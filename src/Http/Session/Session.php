<?php

declare(strict_types=1);

namespace App\Http\Session;

/**
 * Úložiště session. Implementace smí session zakládat líně (až při prvním přístupu),
 * aby veřejné stránky nedostávaly cookie.
 */
interface Session
{
    public function get(string $key): string|int|null;

    public function set(string $key, string|int $value): void;

    public function remove(string $key): void;

    /** Přečte hodnotu a hned ji smaže (flash zprávy). */
    public function pull(string $key): string|int|null;

    /** Nové ID session se zachováním dat (po přihlášení – ochrana proti session fixation). */
    public function regenerateId(): void;

    /** Smaže všechna data a vydá nové ID (odhlášení). */
    public function invalidate(): void;

    /**
     * Uloží data a uvolní zámek session (před dlouhou streamovanou odpovědí – jinak by čekaly
     * všechny další požadavky téhož uživatele). Po uvolnění se session v požadavku už nečte ani nezapisuje.
     */
    public function release(): void;
}
