<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Container\Container;
use App\Domain\Article\ArticleRepository;
use App\Domain\Audit\AuditLogRepository;
use App\Domain\Time\Clock;
use App\Domain\User\UserRepository;
use App\Http\Session\Session;

/** Skutečný kompoziční kořen se třemi náhradami (session a oba repozitáře v paměti). */
final class TestContainer
{
    public static function create(
        ArraySession $session,
        InMemoryUserRepository $users,
        InMemoryAuditLogRepository $audit,
        ?InMemoryArticleRepository $articles = null,
        ?Clock $clock = null,
    ): Container {
        /** @var Container $container */
        $container = require __DIR__ . '/../../../config/container.php';
        self::replaceArticleDependencies($container, $articles, $clock);
        $container->set(Session::class, static fn(): Session => $session);
        $container->set(UserRepository::class, static fn(): UserRepository => $users);
        $container->set(AuditLogRepository::class, static fn(): AuditLogRepository => $audit);

        return $container;
    }

    /**
     * Totéž bez session: pro testy vrstvy Application/Console, které na HTTP části (Session) nezávisí.
     */
    public static function withoutSession(
        InMemoryUserRepository $users,
        InMemoryAuditLogRepository $audit,
        ?InMemoryArticleRepository $articles = null,
        ?Clock $clock = null,
    ): Container {
        /** @var Container $container */
        $container = require __DIR__ . '/../../../config/container.php';
        self::replaceArticleDependencies($container, $articles, $clock);
        $container->set(UserRepository::class, static fn(): UserRepository => $users);
        $container->set(AuditLogRepository::class, static fn(): AuditLogRepository => $audit);

        return $container;
    }

    /**
     * Repozitář článků a hodiny se nahrazují vždy, aby unit testy nikdy nesáhly do databáze.
     * Výchozí: prázdný repozitář a pevný čas 2026-10-03 12:00 (Europe/Prague).
     */
    public static function replaceArticleDependencies(
        Container $container,
        ?InMemoryArticleRepository $articles = null,
        ?Clock $clock = null,
    ): void {
        $articles ??= new InMemoryArticleRepository();
        $clock ??= FixedClock::at('2026-10-03 12:00:00');
        $container->set(ArticleRepository::class, static fn(): ArticleRepository => $articles);
        $container->set(Clock::class, static fn(): Clock => $clock);
    }
}
