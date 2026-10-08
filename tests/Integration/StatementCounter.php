<?php

declare(strict_types=1);

namespace App\Tests\Integration;

/**
 * Měří počet příkazů daného typu (`Com_select`, `Com_insert`, `Com_update`, `Com_delete` a další z `ALL`)
 * provedených v rámci jedné práce, očištěný o režii samotného čtení čítače (jako plán 004, AC 21).
 */
final class StatementCounter
{
    /** Všechny sledované čítače (vícetabulkové DELETE/UPDATE a INSERT … SELECT mají vlastní čítače). */
    public const array ALL = [
        'Com_select', 'Com_insert', 'Com_insert_select', 'Com_update', 'Com_update_multi',
        'Com_delete', 'Com_delete_multi', 'Com_replace',
    ];

    private const string IN_LIST = "'Com_select', 'Com_insert', 'Com_insert_select', 'Com_update', 'Com_update_multi', 'Com_delete', 'Com_delete_multi', 'Com_replace'";

    /**
     * @param list<string> $counters např. ['Com_select', 'Com_insert']
     * @return array<string, int> název čítače => počet příkazů
     */
    public static function measure(\PDO $pdo, array $counters, callable $work): array
    {
        $read = static function () use ($pdo, $counters): array {
            $values = [];
            foreach (TestDatabase::rows($pdo, "SHOW SESSION STATUS WHERE Variable_name IN (" . self::IN_LIST . ")") as $row) {
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

    /** Součet všech příkazů (SELECT, INSERT, UPDATE, DELETE vč. vícetabulkových) provedených prací. */
    public static function statements(\PDO $pdo, callable $work): int
    {
        return array_sum(self::measure($pdo, self::ALL, $work));
    }

    public static function selects(\PDO $pdo, callable $work): int
    {
        return self::measure($pdo, ['Com_select'], $work)['Com_select'];
    }
}
