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

    // ---------------------------------------------------------------- plán 010, AC 13 a 31 (grep část)

    /** @return list<string> relativní cesty: příklad 09 a všechny třídy src/Ai/Editor */
    private static function editorFiles(): array
    {
        $files = ['src/Ai/Examples/Example09AiEditor.php'];
        foreach (glob(AiFixtures::root() . '/src/Ai/Editor/*.php') ?: [] as $file) {
            $files[] = substr($file, strlen(AiFixtures::root()) + 1);
        }

        return $files;
    }

    public function test_ai_editor_has_no_path_to_writing(): void
    {
        $files = self::editorFiles();
        self::assertGreaterThanOrEqual(9, count($files), 'Chybí příklad 09 nebo třídy src/Ai/Editor (8 souborů).');

        foreach ($files as $file) {
            self::assertFileExists(AiFixtures::root() . '/' . $file);
            $source = (string) file_get_contents(AiFixtures::root() . '/' . $file);
            foreach ([
                'ArticleAdminRepository',
                'ArticleRepository',
                'CreateArticle',
                'SaveAiDraft',
                'AuditLogRepository',
                'Session',
                '\\PDO',
                'PDO;',
                'tools:',
            ] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, $file . ': ' . $forbidden);
            }
        }
    }

    public function test_save_ai_draft_is_used_only_by_ai_editor_controller(): void
    {
        $users = array_values(array_diff(
            self::filesContaining('src', 'SaveAiDraft'),
            ['src/Application/Article/SaveAiDraft.php'],
        ));

        self::assertSame(['src/Http/Controller/Admin/AiEditorController.php'], $users);
    }

    public function test_new_files_of_plan_010_have_no_sql_and_no_czech_identifiers(): void
    {
        $files = [
            ...self::editorFiles(),
            'src/Application/Article/SaveAiDraft.php',
            'src/Http/Session/AiDraftStash.php',
            'src/Http/Controller/Admin/AiEditorController.php',
        ];

        foreach ($files as $file) {
            self::assertFileExists(AiFixtures::root() . '/' . $file);
            $source = (string) file_get_contents(AiFixtures::root() . '/' . $file);
            self::assertDoesNotMatchRegularExpression('~\b(SELECT\s.+?\sFROM|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b~s', $source, $file . ': SQL patří jen do *Repository.');
            // Identifikátory (proměnné, funkce, třídy, konstanty, případy výčtu) jen ASCII (ADR-0003).
            self::assertDoesNotMatchRegularExpression(
                '~(\$|\bfunction\s+|\b(?:class|enum|interface|trait)\s+|\bconst\s+(?:\w+\s+)?|\bcase\s+)[A-Za-z_\x80-\x{10FFFF}]*[^\x00-\x7F]~u',
                $source,
                $file . ': české identifikátory',
            );
        }
    }

    public function test_every_post_form_in_ai_editor_template_has_csrf_field(): void
    {
        $path = AiFixtures::root() . '/templates/admin/ai/ai-editor.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        preg_match_all('~<form\b[^>]*method="post"[^>]*>(.*?)</form>~su', $source, $forms);
        self::assertGreaterThanOrEqual(3, count($forms[1]), 'Formuláře: návrh, uložení, zahození.');
        foreach ($forms[1] as $index => $form) {
            self::assertStringContainsString('csrf_field(', $form, sprintf('formulář %d bez csrf_field', $index));
        }
    }
}
