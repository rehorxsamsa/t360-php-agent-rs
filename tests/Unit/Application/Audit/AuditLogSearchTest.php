<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Audit;

use App\Application\Audit\AuditLogSearch;
use App\Application\Audit\InvalidAuditLogFilter;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogFilter;
use App\Domain\Audit\AuditLogRecord;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 007, AC 11–12: validace filtru audit logu a stránkování po 50. */
final class AuditLogSearchTest extends TestCase
{
    private const string BAD_FROM = 'Zadejte datum od ve tvaru RRRR-MM-DD.';
    private const string BAD_TO = 'Zadejte datum do ve tvaru RRRR-MM-DD.';

    private InMemoryAuditLogRepository $repository;
    private AuditLogSearch $search;

    protected function setUp(): void
    {
        $this->repository = new InMemoryAuditLogRepository();
        $this->search = new AuditLogSearch($this->repository);
    }

    /** @return array<string, string> */
    private function errorsFor(string $action, string $from, string $to): array
    {
        try {
            $this->search->filter($action, $from, $to);
        } catch (InvalidAuditLogFilter $exception) {
            return $exception->errors;
        }

        self::fail(sprintf('Filtr (%s, %s, %s) měl být neplatný.', $action, $from, $to));
    }

    private function addLogins(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->repository->records[] = InMemoryAuditLogRepository::record(
                $i + 1,
                new \DateTimeImmutable('2026-10-01 08:00:00', new \DateTimeZone('Europe/Prague'))->modify(sprintf('+%d minutes', $i))->format('Y-m-d H:i:s'),
                'auth.login',
                7,
                'Administrátor',
            );
        }
    }

    // ---------------------------------------------------------------- AC 11: filtr

    public function test_page_size_is_fifty(): void
    {
        self::assertSame(50, AuditLogSearch::PAGE_SIZE);
    }

    public function test_empty_inputs_give_empty_filter(): void
    {
        $filter = $this->search->filter('', '', '');

        self::assertTrue($filter->isEmpty());
        self::assertNull($filter->action);
        self::assertNull($filter->from);
        self::assertNull($filter->until);
    }

    public function test_action_and_single_day_become_prague_day_bounds(): void
    {
        $filter = $this->search->filter('auth.login', '2026-10-03', '2026-10-03');

        self::assertSame(AuditAction::LoginSucceeded, $filter->action);
        self::assertNotNull($filter->from);
        self::assertNotNull($filter->until);
        self::assertSame('2026-10-03 00:00:00 +02:00', $filter->from->format('Y-m-d H:i:s P'));
        self::assertSame('2026-10-04 00:00:00 +02:00', $filter->until->format('Y-m-d H:i:s P'));
        self::assertSame('Europe/Prague', $filter->from->getTimezone()->getName());
        self::assertSame('Europe/Prague', $filter->until->getTimezone()->getName());
    }

    public function test_day_of_dst_change_lasts_twenty_five_hours(): void
    {
        $filter = $this->search->filter('', '2026-10-25', '2026-10-25');

        self::assertNotNull($filter->from);
        self::assertNotNull($filter->until);
        self::assertSame(25 * 3600, $filter->until->getTimestamp() - $filter->from->getTimestamp());
    }

    public function test_only_from_leaves_until_open(): void
    {
        $filter = $this->search->filter('', '2026-10-04', '');

        self::assertSame('2026-10-04 00:00:00 +02:00', $filter->from?->format('Y-m-d H:i:s P'));
        self::assertNull($filter->until);
        self::assertNull($filter->action);
    }

    public function test_only_to_includes_whole_day(): void
    {
        $filter = $this->search->filter('', '', '2026-10-02');

        self::assertNull($filter->from);
        self::assertSame('2026-10-03 00:00:00 +02:00', $filter->until?->format('Y-m-d H:i:s P'));
    }

    public function test_every_action_code_is_accepted(): void
    {
        foreach (AuditAction::cases() as $action) {
            self::assertSame($action, $this->search->filter($action->value, '', '')->action);
        }
    }

    public function test_unknown_action_is_invalid(): void
    {
        self::assertSame(['akce' => 'Vyberte akci ze seznamu.'], $this->errorsFor('xyz', '', ''));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDates(): iterable
    {
        yield 'month 13' => ['2026-13-01'];
        yield 'february 30' => ['2026-02-30'];
        yield 'czech format' => ['3.10.2026'];
        yield 'without leading zeros' => ['2026-1-1'];
        yield 'injection' => ['"><script>'];
        yield 'trailing newline' => ["2026-10-03\n"];
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_from_date(string $value): void
    {
        self::assertSame(['od' => self::BAD_FROM], $this->errorsFor('', $value, ''));
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_to_date(string $value): void
    {
        self::assertSame(['do' => self::BAD_TO], $this->errorsFor('', '', $value));
    }

    public function test_more_errors_at_once(): void
    {
        $errors = $this->errorsFor('xyz', '2026-13-01', '3.10.2026');

        self::assertSame('Vyberte akci ze seznamu.', $errors['akce'] ?? null);
        self::assertSame(self::BAD_FROM, $errors['od'] ?? null);
        self::assertSame(self::BAD_TO, $errors['do'] ?? null);
    }

    public function test_from_after_to_is_invalid(): void
    {
        $errors = $this->errorsFor('', '2026-10-04', '2026-10-03');

        self::assertContains('Datum od nesmí být pozdější než datum do.', $errors);
    }

    public function test_same_day_from_and_to_is_valid(): void
    {
        self::assertFalse($this->search->filter('', '2026-10-04', '2026-10-04')->isEmpty());
    }

    // ---------------------------------------------------------------- AC 12: stránkování

    public function test_first_page_calls_count_and_search_once(): void
    {
        $this->repository = InMemoryAuditLogRepository::withContractRecords();
        $this->search = new AuditLogSearch($this->repository);
        $filter = new AuditLogFilter();

        $page = $this->search->page($filter, 1);

        self::assertNotNull($page);
        self::assertSame(1, $this->repository->countCalls);
        self::assertSame(1, $this->repository->searchCalls);
        self::assertSame(50, $this->repository->searches[0]['limit']);
        self::assertSame(0, $this->repository->searches[0]['offset']);
        self::assertSame($filter, $this->repository->searches[0]['filter']);
        self::assertSame([5, 4, 3, 2, 1], array_map(static fn(AuditLogRecord $r): int => $r->id, $page->records));
        self::assertSame(1, $page->page);
        self::assertSame(1, $page->totalPages);
        self::assertSame(5, $page->total);
        self::assertFalse($page->hasPrevious());
        self::assertFalse($page->hasNext());
    }

    public function test_third_page_of_hundred_twenty_uses_offset_hundred(): void
    {
        $this->addLogins(120);
        $filter = new AuditLogFilter(action: AuditAction::LoginSucceeded);

        $page = $this->search->page($filter, 3);

        self::assertNotNull($page);
        self::assertSame(50, $this->repository->searches[0]['limit']);
        self::assertSame(100, $this->repository->searches[0]['offset']);
        self::assertSame(3, $page->totalPages);
        self::assertSame(120, $page->total);
        self::assertCount(20, $page->records);
        self::assertTrue($page->hasPrevious());
        self::assertFalse($page->hasNext());
    }

    public function test_middle_page_has_previous_and_next(): void
    {
        $this->addLogins(120);

        $page = $this->search->page(new AuditLogFilter(), 2);

        self::assertNotNull($page);
        self::assertTrue($page->hasPrevious());
        self::assertTrue($page->hasNext());
        self::assertCount(50, $page->records);
    }

    public function test_page_out_of_range_is_null(): void
    {
        $this->addLogins(120);

        self::assertNull($this->search->page(new AuditLogFilter(), 4));
    }

    public function test_no_records_is_valid_first_page(): void
    {
        $page = $this->search->page(new AuditLogFilter(), 1);

        self::assertNotNull($page);
        self::assertSame(1, $page->page);
        self::assertSame([], $page->records);
        self::assertSame(0, $page->total);
        self::assertFalse($page->hasPrevious());
        self::assertFalse($page->hasNext());
    }

    public function test_no_records_second_page_is_null(): void
    {
        self::assertNull($this->search->page(new AuditLogFilter(), 2));
    }
}
