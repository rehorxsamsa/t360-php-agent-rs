<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Container\Container;
use App\Domain\Article\ArticleAdminRepository;
use App\Domain\Article\ArticleRepository;
use App\Domain\Audit\AuditLogRepository;
use App\Domain\Category\CategoryRepository;
use App\Domain\Tag\TagRepository;
use App\Domain\Time\Clock;
use App\Domain\User\UserRepository;
use App\Http\Session\Session;

/** Skutečný kompoziční kořen s náhradami (session, hodiny a všechny repozitáře v paměti). */
final class TestContainer
{
    public static function create(
        ArraySession $session,
        InMemoryUserRepository $users,
        InMemoryAuditLogRepository $audit,
        ?InMemoryArticleRepository $articles = null,
        ?Clock $clock = null,
        ?InMemoryArticleAdminRepository $adminArticles = null,
        ?InMemoryCategoryRepository $categories = null,
        ?InMemoryTagRepository $tags = null,
    ): Container {
        /** @var Container $container */
        $container = require __DIR__ . '/../../../config/container.php';
        self::replaceArticleDependencies($container, $articles, $clock, $adminArticles, $categories, $tags);
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
        ?InMemoryArticleAdminRepository $adminArticles = null,
        ?InMemoryCategoryRepository $categories = null,
        ?InMemoryTagRepository $tags = null,
    ): Container {
        /** @var Container $container */
        $container = require __DIR__ . '/../../../config/container.php';
        self::replaceArticleDependencies($container, $articles, $clock, $adminArticles, $categories, $tags);
        $container->set(UserRepository::class, static fn(): UserRepository => $users);
        $container->set(AuditLogRepository::class, static fn(): AuditLogRepository => $audit);

        return $container;
    }

    /**
     * Repozitáře článků, rubrik, štítků a hodiny se nahrazují vždy, aby unit testy nikdy nesáhly
     * do databáze. Výchozí: prázdné repozitáře článků, rubriky a štítky z kontraktu plánu 005
     * a pevný čas 2026-10-03 12:00 (Europe/Prague). Výchozí dvojníci administrace se vytvářejí
     * líně až při prvním použití.
     */
    public static function replaceArticleDependencies(
        Container $container,
        ?InMemoryArticleRepository $articles = null,
        ?Clock $clock = null,
        ?InMemoryArticleAdminRepository $adminArticles = null,
        ?InMemoryCategoryRepository $categories = null,
        ?InMemoryTagRepository $tags = null,
    ): void {
        $articles ??= new InMemoryArticleRepository();
        $clock ??= FixedClock::at('2026-10-03 12:00:00');
        $container->set(ArticleRepository::class, static fn(): ArticleRepository => $articles);
        $container->set(Clock::class, static fn(): Clock => $clock);
        $container->set(
            ArticleAdminRepository::class,
            static fn(): ArticleAdminRepository => $adminArticles ?? new InMemoryArticleAdminRepository(),
        );
        $container->set(
            CategoryRepository::class,
            static fn(): CategoryRepository => $categories ?? new InMemoryCategoryRepository(),
        );
        $container->set(
            TagRepository::class,
            static fn(): TagRepository => $tags ?? new InMemoryTagRepository(),
        );
    }
}
