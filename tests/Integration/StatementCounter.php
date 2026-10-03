<?php

declare(strict_types=1);

namespace App\Tests\Integration;

/**
 * Měří počet příkazů daného typu (`Com_select`, `Com_insert`, `Com_update`, `Com_delete`)
 * provedených v rámci jedné práce, očištěný o režii samotného čtení čítače (jako plán 004, AC 21).
 */
final class StatementCounter
{
    /**
     * @param list<string> $counters např. ['Com_select', 'Com_insert']
     * @return array<string, int> název čítače => počet příkazů
     */
    public static function measure(\PDO $pdo, array $counters, callable $work): array
    {
        $read = static function () use ($pdo, $counters): array {
            $values = [];
            foreach (TestDatabase::rows($pdo, "SHOW SESSION STATUS WHERE Variable_name IN ('Com_select', 'Com_insert', 'Com_update', 'Com_delete')") as $row) {
                $values[$row['Variable_name']] = (int) $row['Value'];
            }

            $result = [];
            foreach ($counters as $counter) {
                $result[$counter] = $values[$counter] ?? throw new \RuntimeException('Neznámý čítač ' . $counter);
            }

            return $result;
        };

        $read();
        $baselineStart = $read();
        $baselineEnd = $read();

        $before = $read();
        $work();
        $after = $read();

        $result = [];
        foreach ($counters as $counter) {
            $overhead = $baselineEnd[$counter] - $baselineStart[$counter];
            $result[$counter] = $after[$counter] - $before[$counter] - $overhead;
        }

        return $result;
    }

    public static function selects(\PDO $pdo, callable $work): int
    {
        return self::measure($pdo, ['Com_select'], $work)['Com_select'];
    }
}
