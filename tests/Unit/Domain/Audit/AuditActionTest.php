<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Audit;

use App\Domain\Audit\AuditAction;
use PHPUnit\Framework\TestCase;

/**
 * Plán 007, AC 2, plán 010, AC 18 a plán 013, AC 9 (záměrné regrese: nové akce AI konceptu a limitu AI):
 * české popisky akcí audit logu.
 */
final class AuditActionTest extends TestCase
{
    public function test_every_action_has_czech_label(): void
    {
        $labels = [];
        foreach (AuditAction::cases() as $action) {
            $labels[$action->value] = $action->label();
        }

        self::assertSame(
            [
                'auth.login' => 'Přihlášení',
                'auth.login_failed' => 'Neúspěšné přihlášení',
                'auth.logout' => 'Odhlášení',
                'user.created' => 'Vytvoření účtu',
                'article.created' => 'Vytvoření článku',
                'article.updated' => 'Úprava článku',
                'article.deleted' => 'Smazání článku',
                'article.ai_draft_saved' => 'Uložení AI konceptu',
                'ai.rate_limited' => 'Překročení limitu AI',
            ],
            $labels,
        );
    }

    /** Plán 013, AC 9: akce odmítnutí rate limitem AI. */
    public function test_ai_rate_limited_action_has_stable_value(): void
    {
        self::assertSame('ai.rate_limited', AuditAction::AiRateLimited->value);
        self::assertSame('Překročení limitu AI', AuditAction::AiRateLimited->label());
        self::assertLessThanOrEqual(50, strlen(AuditAction::AiRateLimited->value), 'Vejde se do audit_log.action VARCHAR(50).');
    }

    public function test_ai_draft_action_has_stable_value(): void
    {
        self::assertSame('article.ai_draft_saved', AuditAction::ArticleAiDraftSaved->value);
        self::assertLessThanOrEqual(50, strlen(AuditAction::ArticleAiDraftSaved->value), 'Vejde se do audit_log.action VARCHAR(50).');
    }
}
