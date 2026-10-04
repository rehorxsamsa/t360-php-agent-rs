<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLogFilter;
use App\Domain\Audit\AuditLogRepository;

/**
 * Výpis audit logu: serverová validace filtru (akce, datum od–do) a stránkování po 50.
 * Kalendářní dny se počítají v zóně aplikace (Europe/Prague); převod do UTC dělá repozitář (ADR-0007).
 */
final readonly class AuditLogSearch
{
    public const int PAGE_SIZE = 50;

    /** Datum z pole `<input type="date">`: přesně RRRR-MM-DD (platnost dne ověří checkdate). */
    private const string DATE_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})\z/';

    public function __construct(private AuditLogRepository $auditLog) {}

    /**
     * Filtr z hodnot query stringu; prázdná hodnota = bez omezení. `do` zahrnuje celý den
     * (horní mez je následující den 00:00:00, správně i přes změnu času).
     *
     * @throws InvalidAuditLogFilter se všemi chybami filtru najednou
     */
    public function filter(string $action, string $from, string $to): AuditLogFilter
    {
        /** @var array<string, string> $errors */
        $errors = [];

        $auditAction = null;
        if ($action !== '') {
            $auditAction = AuditAction::tryFrom($action);
            if ($auditAction === null) {
                $errors['akce'] = 'Vyberte akci ze seznamu.';
            }
        }

        $fromDay = self::day($from);
        if ($fromDay === false) {
            $errors['od'] = 'Zadejte datum od ve tvaru RRRR-MM-DD.';
        }

        $toDay = self::day($to);
        if ($toDay === false) {
            $errors['do'] = 'Zadejte datum do ve tvaru RRRR-MM-DD.';
        }

        if ($fromDay instanceof \DateTimeImmutable && $toDay instanceof \DateTimeImmutable && $fromDay > $toDay) {
            $errors['od'] = 'Datum od nesmí být pozdější než datum do.';
        }

        if ($errors !== []) {
            throw new InvalidAuditLogFilter($errors);
        }

        return new AuditLogFilter(
            $auditAction,
            $fromDay instanceof \DateTimeImmutable ? $fromDay : null,
            $toDay instanceof \DateTimeImmutable ? $toDay->modify('+1 day') : null,
        );
    }

    /** @return AuditLogPage|null null, když stránka neexistuje; prázdný výsledek je platná strana 1 */
    public function page(AuditLogFilter $filter, int $page): ?AuditLogPage
    {
        $total = $this->auditLog->count($filter);
        $totalPages = max(1, intdiv($total + self::PAGE_SIZE - 1, self::PAGE_SIZE));

        if ($page < 1 || $page > $totalPages) {
            return null;
        }

        $records = $this->auditLog->search($filter, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        return new AuditLogPage($records, $page, $totalPages, $total);
    }

    /**
     * Začátek dne (00:00:00) v zóně aplikace; '' = bez omezení (null), neplatné datum = false.
     */
    private static function day(string $value): \DateTimeImmutable|false|null
    {
        if ($value === '') {
            return null;
        }

        if (preg_match(self::DATE_PATTERN, $value, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            return false;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone(date_default_timezone_get()));
    }
}
