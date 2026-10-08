<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Tests\Unit\Support\AiFixtures;
use PHPUnit\Framework\TestCase;

/** Plán 006, AC 33 (grep část): síť, adresa API a klíč jen na jednom místě; žádné zakázané parametry. */
final class AiSourceRulesTest extends TestCase
{
    /** @return list<string> relativní cesty souborů pod $directory, které obsahují $needle */
    private static function filesContaining(string $directory, string $needle, bool $phpOnly = true): array
    {
        $root = AiFixtures::root();
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if ($phpOnly && $file->getExtension() !== 'php') {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                $found[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($found);

        return $found;
    }

    /** @return list<string> */
    private static function filesMatching(string $directory, string $pattern): array
    {
        $root = AiFixtures::root();
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                $found[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($found);

        return $found;
    }

    public function test_curl_functions_only_in_curl_transport(): void
    {
        self::assertSame(['src/Ai/Client/CurlHttpTransport.php'], self::filesContaining('src', 'curl_'));
    }

    public function test_api_host_only_in_anthropic_client(): void
    {
        self::assertSame(['src/Ai/Client/AnthropicClient.php'], self::filesContaining('src', 'api.anthropic.com'));
    }

    /**
     * AC 33 říká „ANTHROPIC_API_KEY v src/ jen v AiConfig“, ale AC 6 vyžaduje název proměnné v české
     * hlášce (LlmErrorType::userMessage). Kontroluje se proto čtení proměnné = samostatný řetězcový literál.
     */
    public function test_api_key_variable_read_only_in_ai_config(): void
    {
        self::assertSame(['src/Ai/AiConfig.php'], self::filesContaining('src', "'ANTHROPIC_API_KEY'"));
        self::assertSame([], self::filesContaining('src', '"ANTHROPIC_API_KEY"'));
    }

    public function test_ai_module_has_no_forbidden_parameters_or_code_execution(): void
    {
        foreach (['temperature', 'tool_choice', 'shell_exec', 'passthru', 'proc_open', 'unserialize('] as $needle) {
            self::assertSame([], self::filesContaining('src/Ai', $needle, false), $needle);
        }
        // eval( a exec( jako samostatné funkce (curl_exec je v pořádku).
        foreach (['~(?<![\w>:$])eval\s*\(~', '~(?<![\w>:$])exec\s*\(~'] as $pattern) {
            self::assertSame([], self::filesMatching('src/Ai', $pattern), $pattern);
        }
    }

    public function test_curl_transport_restricts_protocol_and_redirects(): void
    {
        $source = (string) file_get_contents(AiFixtures::root() . '/src/Ai/Client/CurlHttpTransport.php');

        self::assertStringContainsString('CURLPROTO_HTTPS', $source);
        self::assertStringContainsString('CURLOPT_FOLLOWLOCATION', $source);
        self::assertStringNotContainsString('CURLOPT_SSL_VERIFYPEER', $source, 'Ověřování TLS zůstává výchozí (zapnuté).');
        self::assertStringNotContainsString('CURLOPT_SSL_VERIFYHOST', $source);
    }
    // ---------------------------------------------------------------- plán 009, AC 36 (grep část)

    public function test_vector_sql_only_in_embedding_repository_and_migration(): void
    {
        self::assertSame(['src/Infrastructure/Persistence/PdoArticleEmbeddingRepository.php'], self::filesContaining('src', 'VEC_'));
        self::assertSame(
            ['database/migrations/202610080001_create_article_embeddings_table.php'],
            self::filesMatching('database', '~VECTOR\s*(\(|INDEX)~'),
        );
    }

    public function test_ollama_endpoint_only_in_ollama_client(): void
    {
        self::assertSame(['src/Ai/Embedding/OllamaEmbeddingClient.php'], self::filesContaining('src', '/api/embed'));
    }

    public function test_rag_and_example_08_do_not_depend_on_admin_repositories_or_pdo(): void
    {
        $files = ['src/Ai/Examples/Example08SemanticSearch.php'];
        foreach (glob(AiFixtures::root() . '/src/Ai/Rag/*.php') ?: [] as $file) {
            $files[] = substr($file, strlen(AiFixtures::root()) + 1);
        }
        self::assertGreaterThanOrEqual(3, count($files), 'Chybí src/Ai/Rag/ArticleIndexer.php a IndexReport.php.');

        foreach ($files as $file) {
            $source = (string) file_get_contents(AiFixtures::root() . '/' . $file);
            foreach (['ArticleAdminRepository', 'AuditLogRepository', '\\PDO', 'PDO;'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, $file . ': ' . $forbidden);
            }
        }
    }
}
