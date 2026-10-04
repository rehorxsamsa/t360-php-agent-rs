<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Audit;

use App\Domain\Audit\AuditAction;
use PHPUnit\Framework\TestCase;

/** Plán 007, AC 2: české popisky akcí audit logu. */
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
            ],
            $labels,
        );
    }
}
