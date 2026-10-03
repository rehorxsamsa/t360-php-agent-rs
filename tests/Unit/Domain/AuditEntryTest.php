<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use PHPUnit\Framework\TestCase;

final class AuditEntryTest extends TestCase
{
    public function test_actions_map_to_database_values(): void
    {
        self::assertSame('auth.login', AuditAction::LoginSucceeded->value);
        self::assertSame('auth.login_failed', AuditAction::LoginFailed->value);
        self::assertSame('auth.logout', AuditAction::Logout->value);
        self::assertSame('user.created', AuditAction::UserCreated->value);
    }

    public function test_summary_is_truncated_to_255_characters(): void
    {
        $entry = new AuditEntry(AuditAction::LoginFailed, summary: str_repeat('č', 300));

        self::assertSame(255, mb_strlen($entry->summary));
    }

    public function test_defaults_are_null_and_empty(): void
    {
        $entry = new AuditEntry(AuditAction::Logout);

        self::assertNull($entry->userId);
        self::assertNull($entry->entityType);
        self::assertNull($entry->entityId);
        self::assertNull($entry->ipAddress);
        self::assertSame('', $entry->summary);
    }
}
