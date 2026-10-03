<?php

declare(strict_types=1);

namespace App\Infrastructure\Migration;

/**
 * Spouští soubory z adresáře migrací v pořadí podle názvu (`YYYYMMDDHHMM_popis.php`).
 *
 * Bez transakcí: DDL v MariaDB potvrzuje implicitně, proto jedna migrace = jedna tabulka.
 * Záznam o provedení se zapíše až po úspěšném up().
 */
final readonly class Migrator
{
    private const string FILE_PATTERN = '/^\d{12}_[a-z0-9_]+\.php$/';

    public function __construct(
        private PdoMigrationRepository $repository,
        private \PDO $pdo,
        private string $directory,
    ) {}

    /**
     * @return list<string> názvy právě provedených migrací
     */
    public function migrate(): array
    {
        $this->repository->ensureTable();
        $files = $this->discover();
        $executed = $this->repository->executed();

        $done = [];
        foreach ($files as $name => $path) {
            if (isset($executed[$name])) {
                continue;
            }

            $this->load($name, $path)->up($this->pdo);
            $this->repository->markExecuted($name);
            $done[] = $name;
        }

        return $done;
    }

    /**
     * @return list<string> názvy vrácených migrací od nejnovější
     */
    public function rollback(int $steps = 1): array
    {
        $this->repository->ensureTable();
        $files = $this->discover();

        $reverted = [];
        foreach ($this->repository->lastExecuted($steps) as $name) {
            if (!isset($files[$name])) {
                throw new InvalidMigration(sprintf('Soubor provedené migrace %s.php chybí.', $name));
            }

            $this->load($name, $files[$name])->down($this->pdo);
            $this->repository->forget($name);
            $reverted[] = $name;
        }

        return $reverted;
    }

    /**
     * @return list<MigrationStatus> podle názvu souboru
     */
    public function status(): array
    {
        $this->repository->ensureTable();
        $executed = $this->repository->executed();

        $result = [];
        foreach ($this->discover() as $name => $path) {
            $this->load($name, $path); // ověří, že soubor vrací Migration
            $result[] = new MigrationStatus($name, $executed[$name] ?? null);
        }

        return $result;
    }

    /**
     * @return array<string, string> název migrace (bez .php) => cesta k souboru, řazeno podle názvu
     */
    private function discover(): array
    {
        $paths = glob($this->directory . '/*.php');
        if ($paths === false) {
            throw new InvalidMigration(sprintf('Adresář migrací %s nelze přečíst.', $this->directory));
        }

        sort($paths);

        $files = [];
        foreach ($paths as $path) {
            $file = basename($path);
            if (preg_match(self::FILE_PATTERN, $file) !== 1) {
                throw new InvalidMigration(sprintf(
                    'Neplatný název souboru migrace %s (očekáváno YYYYMMDDHHMM_popis.php).',
                    $file,
                ));
            }

            $files[substr($file, 0, -4)] = $path;
        }

        return $files;
    }

    private function load(string $name, string $path): Migration
    {
        $migration = require $path;
        if (!$migration instanceof Migration) {
            throw new InvalidMigration(sprintf('Soubor %s.php nevrací instanci Migration.', $name));
        }

        return $migration;
    }
}
