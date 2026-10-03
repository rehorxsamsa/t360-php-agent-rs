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
}
