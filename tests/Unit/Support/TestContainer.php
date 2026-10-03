<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Container\Container;
use App\Domain\Audit\AuditLogRepository;
use App\Domain\User\UserRepository;
use App\Http\Session\Session;

/** Skutečný kompoziční kořen se třemi náhradami (session a oba repozitáře v paměti). */
final class TestContainer
{
    public static function create(
        ArraySession $session,
        InMemoryUserRepository $users,
        InMemoryAuditLogRepository $audit,
    ): Container {
        /** @var Container $container */
        $container = require __DIR__ . '/../../../config/container.php';
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
    ): Container {
        /** @var Container $container */
        $container = require __DIR__ . '/../../../config/container.php';
        $container->set(UserRepository::class, static fn(): UserRepository => $users);
        $container->set(AuditLogRepository::class, static fn(): AuditLogRepository => $audit);

        return $container;
    }
}
