<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Audit;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogFilter;
use PHPUnit\Framework\TestCase;

/** Plán 007, §2: filtr audit logu (from včetně, until vyjma). */
final class AuditLogFilterTest extends TestCase
{
    private static function date(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('Europe/Prague'));
    }

    public function test_default_filter_is_empty(): void
    {
        $filter = new AuditLogFilter();

        self::assertTrue($filter->isEmpty());
        self::assertNull($filter->action);
        self::assertNull($filter->from);
        self::assertNull($filter->until);
    }

    public function test_filter_with_any_criterion_is_not_empty(): void
    {
        self::assertFalse(new AuditLogFilter(action: AuditAction::Logout)->isEmpty());
        self::assertFalse(new AuditLogFilter(from: self::date('2026-10-03 00:00:00'))->isEmpty());
        self::assertFalse(new AuditLogFilter(until: self::date('2026-10-04 00:00:00'))->isEmpty());
    }

    public function test_from_must_be_before_until(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AuditLogFilter(from: self::date('2026-10-04 00:00:00'), until: self::date('2026-10-04 00:00:00'));
    }

    public function test_from_after_until_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AuditLogFilter(from: self::date('2026-10-05 00:00:00'), until: self::date('2026-10-04 00:00:00'));
    }
}
