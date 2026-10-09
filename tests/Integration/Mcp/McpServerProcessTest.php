<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mcp;

use App\Infrastructure\Migration\Migrator;
use App\Infrastructure\Migration\PdoMigrationRepository;
use App\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Plán 011, AC 16: skutečný proces `php bin/konzole mcp:server` nad `redakce_test`. Hlídá čistotu stdout
 * (jen řádky JSON-RPC – žádné hlášky, varování ani BOM) a ukončení po zavření vstupu. `proc_open` s polem
 * argumentů (bez shellu) je jen v testu.
 */
final class McpServerProcessTest extends TestCase
{
    private const int TIMEOUT_SECONDS = 10;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        new Migrator(new PdoMigrationRepository($this->pdo), $this->pdo, __DIR__ . '/../../../database/migrations')->migrate();

        $this->pdo->exec("INSERT INTO categories (name, slug) VALUES ('Technologie', 'technologie')");
        $categoryId = (int) $this->pdo->lastInsertId();
        $insert = $this->pdo->prepare(
            'INSERT INTO articles (category_id, title, slug, excerpt, body, status, published_at)'
            . ' VALUES (:category, :title, :slug, :excerpt, :body, :status, :published_at)',
        );
        $insert->execute([
            'category' => $categoryId, 'title' => 'Publikovaný článek', 'slug' => 'publikovany', 'excerpt' => 'Perex.',
            'body' => 'Text o Dockeru.', 'status' => 'published', 'published_at' => '2026-01-05 08:00:00',
        ]);
        $insert->execute([
            'category' => $categoryId, 'title' => 'Koncept', 'slug' => 'koncept', 'excerpt' => '',
            'body' => 'Rozepsaný text o Dockeru.', 'status' => 'draft', 'published_at' => null,
        ]);
    }

    protected function tearDown(): void
    {
        TestDatabase::reset();
    }

    /**
     * @param list<string> $messages
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private static function runServer(array $messages): array
    {
        /** @var array<string, string> $environment */
        $environment = getenv();
        $process = proc_open(
            ['php', 'bin/konzole', 'mcp:server'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            $environment,
        );
        self::assertIsResource($process, 'Proces mcp:server se nespustil.');

        fwrite($pipes[0], implode("\n", $messages) . "\n");
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $status = proc_get_status($process);
        while ($status['running'] && microtime(true) < $deadline) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 0, 100_000) > 0) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
            }
            $status = proc_get_status($process);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        if ($status['running']) {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            self::fail(sprintf('Proces neskončil do %d s po zavření vstupu. stderr: %s', self::TIMEOUT_SECONDS, $stderr));
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return ['code' => $status['exitcode'], 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private static function valueAt(mixed $data, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($data, implode('.', $path));
            self::assertArrayHasKey($key, $data, implode('.', $path));
            $data = $data[$key];
        }

        return $data;
    }

    public function test_real_process_answers_initialize_and_statistics_with_clean_stdout(): void
    {
        $result = self::runServer([
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},'
                . '"clientInfo":{"name":"phpunit","version":"1.0"}}}',
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"statistiky","arguments":{}}}',
        ]);

        self::assertSame(0, $result['code'], 'stderr: ' . $result['stderr']);
        self::assertStringStartsNotWith("\u{FEFF}", $result['stdout'], 'Stdout nesmí začínat BOM.');
        $lines = explode("\n", rtrim($result['stdout'], "\n"));
        self::assertCount(2, $lines, "Stdout má přesně 2 řádky (odpovědi id 1 a 2):\n" . $result['stdout']);

        $messages = [];
        foreach ($lines as $line) {
            $message = json_decode($line, true);
            self::assertIsArray($message, 'Řádek není JSON: ' . $line);
            self::assertSame('2.0', $message['jsonrpc'] ?? null);
            $messages[] = $message;
        }
        self::assertSame([1, 2], array_column($messages, 'id'));
        self::assertSame('redakce', self::valueAt($messages[0], 'result', 'serverInfo', 'name'));

        $text = self::valueAt($messages[1], 'result', 'content', 0, 'text');
        self::assertIsString($text);
        $statistics = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($statistics);
        self::assertSame(1, $statistics['published_articles'] ?? null, 'Koncept se nepočítá.');
    }
}
