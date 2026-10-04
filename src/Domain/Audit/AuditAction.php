<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/** Auditované akce; hodnota se ukládá do `audit_log.action`. */
enum AuditAction: string
{
    case LoginSucceeded = 'auth.login';
    case LoginFailed = 'auth.login_failed';
    case Logout = 'auth.logout';
    case UserCreated = 'user.created';
    case ArticleCreated = 'article.created';
    case ArticleUpdated = 'article.updated';
    case ArticleDeleted = 'article.deleted';

    /** Český popisek akce pro výpis audit logu a filtr. */
    public function label(): string
    {
        return match ($this) {
            self::LoginSucceeded => 'Přihlášení',
            self::LoginFailed => 'Neúspěšné přihlášení',
            self::Logout => 'Odhlášení',
            self::UserCreated => 'Vytvoření účtu',
            self::ArticleCreated => 'Vytvoření článku',
            self::ArticleUpdated => 'Úprava článku',
            self::ArticleDeleted => 'Smazání článku',
        };
    }
}
