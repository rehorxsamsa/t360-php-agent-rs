<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Audit;

use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 007, AC 2: popisek akce a objektu záznamu audit logu. */
final class AuditLogRecordTest extends TestCase
{
    public function test_known_action_has_label(): void
    {
        self::assertSame('Odhlášení', InMemoryAuditLogRepository::record(1, '2026-10-04 10:15:30', 'auth.logout')->actionLabel());
    }

    public function test_unknown_action_is_shown_verbatim_without_exception(): void
    {
        self::assertSame('legacy.x', InMemoryAuditLogRepository::record(1, '2026-10-04 10:15:30', 'legacy.x')->actionLabel());
    }

    /** @return iterable<string, array{?string, ?int, string}> */
    public static function entities(): iterable
    {
        yield 'article' => ['article', 5, 'článek #5'];
        yield 'user' => ['user', 3, 'uživatel #3'];
        yield 'other type' => ['category', 2, 'category #2'];
        yield 'no type' => [null, null, '—'];
    }

    #[DataProvider('entities')]
    public function test_entity_label(?string $type, ?int $id, string $expected): void
    {
        $record = InMemoryAuditLogRepository::record(1, '2026-10-04 10:15:30', 'article.deleted', entityType: $type, entityId: $id);

        self::assertSame($expected, $record->entityLabel());
    }

    public function test_record_keeps_raw_values(): void
    {
        $record = InMemoryAuditLogRepository::record(3, '2026-10-03 23:59:59', 'article.created', 7, 'Administrátor', 'article', 5, 'Titulek [titulek]', '172.19.0.1');

        self::assertSame(3, $record->id);
        self::assertSame('2026-10-03 23:59:59 +02:00', $record->createdAt->format('Y-m-d H:i:s P'));
        self::assertSame('article.created', $record->action);
        self::assertSame(7, $record->userId);
        self::assertSame('Administrátor', $record->userName);
        self::assertSame('article', $record->entityType);
        self::assertSame(5, $record->entityId);
        self::assertSame('Titulek [titulek]', $record->summary);
        self::assertSame('172.19.0.1', $record->ipAddress);
    }
}
