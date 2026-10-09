<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Ai;

use App\Application\Ai\AiRateBucket;
use App\Application\Ai\AiRateLimiter;
use App\Application\Ai\AiRateLimitExceeded;
use App\Domain\Ai\RateLimit;
use App\Domain\Audit\AuditAction;
use App\Tests\Unit\Support\InMemoryAiRateLimitHitRepository;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\MutableClock;
use PHPUnit\Framework\TestCase;

/**
 * Plán 013, AC 5–8: posuvné okno se záznamy (`created_at > now − okno`), Retry-After z nejstaršího záznamu,
 * nezávislé kbelíky a uživatelé, audit jen u odmítnutí. Čas t0 = 2026-10-09 12:00:00 (Europe/Prague), bez sleep.
 */
final class AiRateLimiterTest extends TestCase
{
    private const string IP = '172.18.0.1';
    private const string TARGET = 'POST /admin/ai/01';

    private InMemoryAiRateLimitHitRepository $hits;
    private InMemoryAuditLogRepository $audit;
    private MutableClock $clock;
    private AiRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->hits = new InMemoryAiRateLimitHitRepository();
        $this->audit = new InMemoryAuditLogRepository();
        $this->clock = new MutableClock(self::at('2026-10-09 12:00:00'));
        $this->limiter = $this->limiter(new RateLimit(10, 60), new RateLimit(3, 600));
    }

    private function limiter(RateLimit $standard, RateLimit $heavy): AiRateLimiter
    {
        return new AiRateLimiter($this->hits, $this->audit, $this->clock, $standard, $heavy);
    }

    private static function at(string $dateTime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($dateTime, new \DateTimeZone('Europe/Prague'));
    }

    private function consumeTimes(int $times, int $userId = 1, AiRateBucket $bucket = AiRateBucket::Standard, string $target = self::TARGET): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->limiter->consume($userId, $bucket, self::IP, $target);
        }
    }

    private function rejection(int $userId = 1, AiRateBucket $bucket = AiRateBucket::Standard, string $target = self::TARGET): AiRateLimitExceeded
    {
        try {
            $this->limiter->consume($userId, $bucket, self::IP, $target);
        } catch (AiRateLimitExceeded $exception) {
            return $exception;
        }

        self::fail('Požadavek měl být odmítnut (AiRateLimitExceeded).');
    }

    // ---------------------------------------------------------------- AiRateBucket

    public function test_buckets_have_stable_values_and_czech_labels(): void
    {
        self::assertSame('ai', AiRateBucket::Standard->value);
        self::assertSame('ai_heavy', AiRateBucket::Heavy->value);
        self::assertSame('běžných AI požadavků', AiRateBucket::Standard->label());
        self::assertSame('náročných AI požadavků (AI redaktor)', AiRateBucket::Heavy->label());
        self::assertCount(2, AiRateBucket::cases());
    }

    // ---------------------------------------------------------------- AC 5: limit a odmítnutí

    public function test_ten_requests_at_same_moment_pass_and_are_recorded_with_clock_time(): void
    {
        $this->consumeTimes(10);

        self::assertCount(10, $this->hits->hits);
        foreach ($this->hits->hits as $hit) {
            self::assertSame(1, $hit['userId']);
            self::assertSame('ai', $hit['bucket']);
            self::assertSame('2026-10-09 12:00:00.000000 +02:00', $hit['at']->format('Y-m-d H:i:s.u P'));
        }
        self::assertSame([], $this->audit->entries, 'Povolená volání audit nezapisují.');
    }

    public function test_eleventh_request_is_rejected_with_retry_after_and_not_recorded(): void
    {
        $this->consumeTimes(10);

        $exception = $this->rejection();

        self::assertSame(60, $exception->retryAfterSeconds);
        self::assertSame(10, $exception->limit);
        self::assertSame(60, $exception->windowSeconds);
        self::assertSame('Příliš mnoho požadavků na AI: nejvýše 10 za 60 s. Zkuste to znovu za 60 s.', $exception->getMessage());
        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertCount(10, $this->hits->hits, 'Odmítnutí se do limitu nepočítá.');
    }

    public function test_repeated_rejections_do_not_extend_the_window(): void
    {
        $this->consumeTimes(10);
        $this->rejection();
        $this->clock->advanceSeconds(30);

        $exception = $this->rejection();

        self::assertSame(30, $exception->retryAfterSeconds);
        self::assertCount(10, $this->hits->hits);
    }

    public function test_heavy_bucket_uses_its_own_limit(): void
    {
        $this->consumeTimes(3, bucket: AiRateBucket::Heavy, target: 'POST /admin/ai/09');

        $exception = $this->rejection(bucket: AiRateBucket::Heavy, target: 'POST /admin/ai/09');

        self::assertSame(600, $exception->retryAfterSeconds);
        self::assertSame(3, $exception->limit);
        self::assertSame(600, $exception->windowSeconds);
        self::assertSame('Příliš mnoho požadavků na AI: nejvýše 3 za 600 s. Zkuste to znovu za 600 s.', $exception->getMessage());
        self::assertSame(3, $this->hits->countBucket('ai_heavy'));
        self::assertSame(0, $this->hits->countBucket('ai'));
    }

    public function test_configured_limits_are_used(): void
    {
        $this->limiter = $this->limiter(new RateLimit(2, 30), new RateLimit(1, 120));
        $this->consumeTimes(2);

        $exception = $this->rejection();

        self::assertSame([30, 2, 30], [$exception->retryAfterSeconds, $exception->limit, $exception->windowSeconds]);
    }

    // ---------------------------------------------------------------- AC 6: posuvné okno

    public function test_retry_after_counts_down_from_oldest_record(): void
    {
        $this->consumeTimes(10);
        $this->clock->advanceSeconds(45);

        self::assertSame(15, $this->rejection()->retryAfterSeconds);
    }

    public function test_request_passes_exactly_one_window_after_oldest_record(): void
    {
        $this->consumeTimes(10);
        $this->clock->advanceSeconds(45);
        $this->rejection();
        $this->clock->advanceSeconds(15);

        $this->limiter->consume(1, AiRateBucket::Standard, self::IP, self::TARGET);

        self::assertCount(11, $this->hits->hits);
        self::assertSame('2026-10-09 12:01:00', $this->hits->hits[10]['at']->format('Y-m-d H:i:s'));
    }

    public function test_window_slides_record_by_record(): void
    {
        // 5 záznamů v t0, 5 v t0 + 20 s; v t0 + 60 s vypadne jen první pětice.
        $this->consumeTimes(5);
        $this->clock->advanceSeconds(20);
        $this->consumeTimes(5);
        $this->clock->advanceSeconds(40);

        $this->consumeTimes(5);
        $exception = $this->rejection();

        self::assertSame(20, $exception->retryAfterSeconds, 'Nejstarší záznam v okně je z t0 + 20 s.');
    }

    public function test_fractional_seconds_round_up(): void
    {
        $this->clock = new MutableClock(self::at('2026-10-09 12:00:00.300000'));
        $this->limiter = $this->limiter(new RateLimit(10, 60), new RateLimit(3, 600));
        $this->consumeTimes(10);
        $this->clock = new MutableClock(self::at('2026-10-09 12:00:45'));
        $this->limiter = $this->limiter(new RateLimit(10, 60), new RateLimit(3, 600));

        self::assertSame(16, $this->rejection()->retryAfterSeconds, '15,3 s se zaokrouhlí nahoru na 16.');
    }

    public function test_retry_after_is_at_least_one_second(): void
    {
        $this->consumeTimes(10);
        $this->clock = new MutableClock(self::at('2026-10-09 12:00:59.999999'));
        $this->limiter = $this->limiter(new RateLimit(10, 60), new RateLimit(3, 600));

        self::assertSame(1, $this->rejection()->retryAfterSeconds);
    }

    public function test_limiter_asks_repository_for_window_starting_now_minus_window(): void
    {
        // Záznam přesně na hranici okna (now − 60 s) se už nepočítá, o mikrosekundu mladší ano.
        $this->hits->add(1, 'ai', self::at('2026-10-09 11:59:00'));
        for ($i = 0; $i < 9; ++$i) {
            $this->hits->add(1, 'ai', self::at('2026-10-09 11:59:00.000001'));
        }

        $this->limiter->consume(1, AiRateBucket::Standard, self::IP, self::TARGET);

        self::assertSame(1, $this->rejection()->retryAfterSeconds);
    }

    // ---------------------------------------------------------------- AC 7: nezávislost

    public function test_exhausted_heavy_bucket_does_not_block_standard_bucket(): void
    {
        $this->consumeTimes(3, bucket: AiRateBucket::Heavy);
        $this->rejection(bucket: AiRateBucket::Heavy);

        $this->limiter->consume(1, AiRateBucket::Standard, self::IP, self::TARGET);

        self::assertSame(1, $this->hits->countBucket('ai'));
    }

    public function test_exhausted_standard_bucket_does_not_block_heavy_bucket(): void
    {
        $this->consumeTimes(10);

        $this->limiter->consume(1, AiRateBucket::Heavy, self::IP, 'POST /admin/ai/09');

        self::assertSame(1, $this->hits->countBucket('ai_heavy'));
    }

    public function test_exhausted_bucket_of_one_user_does_not_block_another_user(): void
    {
        $this->consumeTimes(10);
        $this->rejection();

        $this->limiter->consume(2, AiRateBucket::Standard, self::IP, self::TARGET);

        self::assertCount(11, $this->hits->hits);
        self::assertSame(2, $this->hits->hits[10]['userId']);
    }

    // ---------------------------------------------------------------- AC 8: audit

    public function test_rejection_writes_exactly_one_audit_entry(): void
    {
        $this->consumeTimes(10);

        $this->rejection();

        self::assertCount(1, $this->audit->entries);
        $entry = $this->audit->entries[0];
        self::assertSame(AuditAction::AiRateLimited, $entry->action);
        self::assertSame(1, $entry->userId);
        self::assertSame(self::IP, $entry->ipAddress);
        self::assertSame('Limit běžných AI požadavků 10 za 60 s: POST /admin/ai/01', $entry->summary);
    }

    public function test_heavy_rejection_summary_names_ai_editor(): void
    {
        $this->consumeTimes(3, bucket: AiRateBucket::Heavy, target: 'POST /admin/ai/09');

        $this->rejection(bucket: AiRateBucket::Heavy, target: 'POST /admin/ai/09');

        self::assertCount(1, $this->audit->entries);
        self::assertSame(
            'Limit náročných AI požadavků (AI redaktor) 3 za 600 s: POST /admin/ai/09',
            $this->audit->entries[0]->summary,
        );
    }

    public function test_each_rejection_is_audited_and_missing_ip_is_allowed(): void
    {
        $this->consumeTimes(10);

        $this->rejection();
        try {
            $this->limiter->consume(1, AiRateBucket::Standard, null, self::TARGET);
            self::fail('Očekáváno odmítnutí.');
        } catch (AiRateLimitExceeded) {
        }

        self::assertCount(2, $this->audit->byAction(AuditAction::AiRateLimited));
        self::assertNull($this->audit->entries[1]->ipAddress);
    }
}
