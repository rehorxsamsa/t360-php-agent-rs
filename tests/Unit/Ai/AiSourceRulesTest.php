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
}
