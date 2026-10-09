<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Tests\Unit\Support\AiFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plán 011, AC 14 a AC 24 (grep část): MCP server smí číst jen publikované články přes `ArticleRepository`,
 * SDK (`use Mcp\…`) je jen v `src/Mcp/`, stdout patří výhradně protokolu a identifikátory jsou anglicky.
 */
final class McpSourceRulesTest extends TestCase
{
    /** Soubory, které tvoří MCP server (bez SDK i s ním). */
    private const array FIXED_FILES = [
        'src/Ai/Examples/Example10McpServer.php',
        'src/Ai/Tools/StatisticsTool.php',
        'src/Console/Command/McpServerCommand.php',
        'src/Mcp/NewsroomMcpServer.php',
    ];

    /**
     * Zakázané závislosti: zápis, uživatelé, audit, log AI, vektory, LLM, session, přímé PDO, prostředí (tajemství
     * v prostředí kontejneru) a čtení souborů (server nemá číst nic mimo `ArticleRepository` a soubory promptů).
     */
    private const array FORBIDDEN = [
        'ArticleAdminRepository', 'ArticleAdmin', 'UserRepository', 'AuditLogRepository', 'AiCallRepository', 'ArticleEmbeddingRepository',
        'LlmClient', 'CreateArticle', 'UpdateArticle', 'DeleteArticle', 'SaveAiDraft', 'Session', '\\PDO',
        'getenv', '$_ENV', '$_SERVER', 'putenv', 'ini_get', 'phpinfo', 'file_get_contents', 'fopen',
    ];

    /** @return list<string> relativní cesty všech souborů MCP serveru */
    private static function mcpFiles(): array
    {
        $root = AiFixtures::root();
        $files = self::FIXED_FILES;
        if (is_dir($root . '/src/Mcp')) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src/Mcp', \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $files[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    private static function source(string $file): string
    {
        $path = AiFixtures::root() . '/' . $file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** Zdroj bez komentářů (zmínka „echo“ v komentáři nevadí, volání ano). */
    private static function code(string $file): string
    {
        $code = '';
        foreach (token_get_all(self::source($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** @return iterable<string, array{string}> */
    public static function files(): iterable
    {
        foreach (self::mcpFiles() as $file) {
            yield $file => [$file];
        }
    }

    #[DataProvider('files')]
    public function test_mcp_file_does_not_depend_on_write_side_users_audit_llm_pdo_or_environment(string $file): void
    {
        $source = self::source($file);

        foreach (self::FORBIDDEN as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source, $file . ': ' . $forbidden);
        }
    }

    #[DataProvider('files')]
    public function test_mcp_file_contains_no_sql(string $file): void
    {
        self::assertSame(0, preg_match('~\b(SELECT|INSERT|UPDATE|DELETE)\b~', self::source($file), $match), $file . ': ' . ($match[0] ?? ''));
    }

    /** Čistota stdout (AC 16) a žádné spouštění procesů: na výstup píše jen SDK přes předaný proud. */
    #[DataProvider('files')]
    public function test_mcp_file_neither_prints_nor_executes(string $file): void
    {
        $source = self::code($file);

        foreach ([
            '~(?<![\w>:$])echo\b~', '~(?<![\w>:$])print\b~', '~(?<![\w>:$])printf\s*\(~', '~\bvar_dump\s*\(~', '~\bprint_r\s*\(~',
            '~(?<![\w>:$])exec\s*\(~', '~\bshell_exec\b~', '~\bpassthru\b~', '~\bproc_open\b~', '~(?<![\w>:$])system\s*\(~', '~\beval\s*\(~',
        ] as $pattern) {
            self::assertSame(0, preg_match($pattern, $source), $file . ': ' . $pattern);
        }
    }

    public function test_command_writes_nothing_through_output_line(): void
    {
        $source = self::code('src/Console/Command/McpServerCommand.php');

        self::assertStringNotContainsString('->line(', $source, 'Stdout patří protokolu – příkaz píše jen na stderr.');
        self::assertStringNotContainsString('->write(', $source);
    }

    public function test_sdk_is_used_only_in_mcp_adapter(): void
    {
        $root = AiFixtures::root();
        $importing = [];
        $qualified = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, 'use Mcp\\')) {
                $importing[] = $relative;
            }
            if (preg_match('~(?<![\w\\\\])\\\\Mcp\\\\~', $source) === 1) {
                $qualified[] = $relative;
            }
        }
        sort($importing);

        self::assertContains('src/Mcp/NewsroomMcpServer.php', $importing, 'Adaptér importuje SDK (use Mcp\…).');
        foreach ([...$importing, ...$qualified] as $file) {
            self::assertStringStartsWith('src/Mcp/', $file, $file . ' používá SDK mimo src/Mcp/.');
        }
    }

    /** Identifikátory (proměnné, funkce, třídy, konstanty) jen anglicky – zamčené kontrakty jsou řetězcové literály. */
    #[DataProvider('files')]
    public function test_mcp_file_has_english_identifiers_only(string $file): void
    {
        foreach (token_get_all(self::source($file)) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_VARIABLE, T_STRING], true)) {
                continue;
            }
            $identifier = $token[1];
            self::assertSame(1, preg_match('~^\$?[A-Za-z_][A-Za-z0-9_]*$~D', $identifier), $file . ': ne-ASCII identifikátor ' . $identifier);
            self::assertSame(
                0,
                preg_match('~clan|nastroj|statistik|hledej|nacti|rubrik|stitk|prikaz|vystup|vstup|chyb|popis|nazev|navrh|tema$~i', $identifier),
                $file . ': český identifikátor ' . $identifier,
            );
        }
    }
}
