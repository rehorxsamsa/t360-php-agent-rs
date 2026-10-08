<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Embedding;

use App\Ai\Client\CurlHttpTransport;
use App\Ai\Client\HttpTransport;
use App\Ai\Client\TransportFailed;
use App\Ai\Embedding\EmbeddingClient;
use App\Ai\Embedding\EmbeddingConfig;
use App\Ai\Embedding\EmbeddingProvider;
use App\Ai\Embedding\FakeEmbeddingClient;
use App\Ai\Embedding\OllamaEmbeddingClient;
use App\Container\Container;
use App\Infrastructure\Config\MissingConfiguration;
use App\Tests\Unit\Support\AiFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plán 009, AC 5–6: konfigurace embeddingů z prostředí, HTTP bez TLS jen pro instanci transportu Ollamy
 * a zapojení klienta v kompozičním kořeni. Transport se zkouší jen na loopbacku (port 1 = spojení odmítnuto),
 * nikdy ne přes síť.
 */
final class EmbeddingConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        $this->previousEnvironment = [];
    }

    private function setEnvironment(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->previousEnvironment)) {
            $this->previousEnvironment[$name] = getenv($name);
        }
        putenv($name . '=' . $value);
    }

    // ---------------------------------------------------------------- AC 5: konfigurace

    public function test_empty_environment_uses_fake_provider_and_defaults(): void
    {
        $config = EmbeddingConfig::fromEnvironment([]);

        self::assertSame(EmbeddingProvider::Fake, $config->provider);
        self::assertSame('embeddinggemma', $config->model);
        self::assertSame('http://ollama:11434', $config->ollamaUrl);
    }

    public function test_reads_ollama_provider_model_and_url(): void
    {
        $config = EmbeddingConfig::fromEnvironment([
            'EMBED_PROVIDER' => 'ollama',
            'EMBED_MODEL' => 'embeddinggemma:300m',
            'OLLAMA_URL' => 'https://ollama.example.cz:443',
            'PATH' => '/usr/bin',
        ]);

        self::assertSame(EmbeddingProvider::Ollama, $config->provider);
        self::assertSame('embeddinggemma:300m', $config->model);
        self::assertSame('https://ollama.example.cz:443', $config->ollamaUrl);
    }

    public function test_falesny_is_fake_and_model_may_contain_namespace(): void
    {
        $config = EmbeddingConfig::fromEnvironment(['EMBED_PROVIDER' => 'falesny', 'EMBED_MODEL' => 'library/bge-m3', 'OLLAMA_URL' => 'http://localhost']);

        self::assertSame(EmbeddingProvider::Fake, $config->provider);
        self::assertSame('library/bge-m3', $config->model);
        self::assertSame('http://localhost', $config->ollamaUrl);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidEnvironments(): iterable
    {
        yield 'provider voyage (backlog)' => [['EMBED_PROVIDER' => 'voyage'], 'EMBED_PROVIDER'];
        yield 'provider xyz' => [['EMBED_PROVIDER' => 'xyz'], 'EMBED_PROVIDER'];
        yield 'provider fake (log name, not env value)' => [['EMBED_PROVIDER' => 'fake'], 'EMBED_PROVIDER'];
        yield 'model uppercase' => [['EMBED_MODEL' => 'EmbeddingGemma'], 'EMBED_MODEL'];
        yield 'model with space' => [['EMBED_MODEL' => 'embedding gemma'], 'EMBED_MODEL'];
        yield 'model starting with dash' => [['EMBED_MODEL' => '-embeddinggemma'], 'EMBED_MODEL'];
        yield 'model 101 characters' => [['EMBED_MODEL' => str_repeat('a', 101)], 'EMBED_MODEL'];
        yield 'url ftp' => [['OLLAMA_URL' => 'ftp://ollama:11434'], 'OLLAMA_URL'];
        yield 'url with path' => [['OLLAMA_URL' => 'http://ollama:11434/api'], 'OLLAMA_URL'];
        yield 'url trailing slash' => [['OLLAMA_URL' => 'http://ollama:11434/'], 'OLLAMA_URL'];
        yield 'url with credentials' => [['OLLAMA_URL' => 'http://tajne-heslo@ollama:11434'], 'OLLAMA_URL'];
        yield 'url port too long' => [['OLLAMA_URL' => 'http://ollama:123456'], 'OLLAMA_URL'];
        yield 'url without scheme' => [['OLLAMA_URL' => 'ollama:11434'], 'OLLAMA_URL'];
    }

    /** @param array<string, string> $environment */
    #[DataProvider('invalidEnvironments')]
    public function test_invalid_value_throws_missing_configuration_naming_only_variable(array $environment, string $variable): void
    {
        try {
            EmbeddingConfig::fromEnvironment($environment);
            self::fail('Očekávána výjimka MissingConfiguration.');
        } catch (MissingConfiguration $exception) {
            self::assertSame(MissingConfiguration::invalidVariable($variable)->getMessage(), $exception->getMessage());
            foreach ($environment as $value) {
                self::assertStringNotContainsString($value, $exception->getMessage());
            }
        }
    }

    public function test_provider_enum_values_are_environment_contract(): void
    {
        self::assertSame('falesny', EmbeddingProvider::Fake->value);
        self::assertSame('ollama', EmbeddingProvider::Ollama->value);
        self::assertSame('fake', EmbeddingProvider::Fake->logName());
        self::assertSame('ollama', EmbeddingProvider::Ollama->logName());
        self::assertSame('falešný klient', EmbeddingProvider::Fake->label());
        self::assertSame('Ollama (lokálně)', EmbeddingProvider::Ollama->label());
    }

    // ---------------------------------------------------------------- AC 6: transport

    private static function curlErrorOf(HttpTransport $transport, string $url): string
    {
        try {
            $transport->post($url, ['content-type' => 'application/json'], '{}');
        } catch (TransportFailed $exception) {
            return $exception->getMessage();
        }

        self::fail('Očekávána výjimka TransportFailed (loopback port 1 nic neposlouchá).');
    }

    public function test_default_transport_rejects_plain_http_before_connecting(): void
    {
        // CURLE_UNSUPPORTED_PROTOCOL (1): protokol odmítnut ještě před spojením.
        self::assertStringContainsString('(cURL 1)', self::curlErrorOf(new CurlHttpTransport(), 'http://127.0.0.1:1/api/embed'));
    }

    public function test_transport_with_plain_http_allowed_tries_to_connect(): void
    {
        $transport = new CurlHttpTransport(connectTimeoutSeconds: 2, timeoutSeconds: 5, allowPlainHttp: true);

        // CURLE_COULDNT_CONNECT (7): HTTP povoleno, jen na loopbacku nic neposlouchá.
        self::assertStringContainsString('(cURL 7)', self::curlErrorOf($transport, 'http://127.0.0.1:1/api/embed'));
        // Jiné protokoly zůstávají zakázané.
        self::assertStringContainsString('(cURL 1)', self::curlErrorOf($transport, 'ftp://127.0.0.1:1/'));
    }

    public function test_transport_source_allows_http_only_by_flag(): void
    {
        $source = (string) file_get_contents(AiFixtures::root() . '/src/Ai/Client/CurlHttpTransport.php');

        self::assertMatchesRegularExpression('~bool \$allowPlainHttp = false~', $source);
        self::assertMatchesRegularExpression('~CURLPROTO_HTTP\s*\|\s*CURLPROTO_HTTPS~', $source);
    }

    public function test_container_enables_plain_http_only_for_ollama_transport(): void
    {
        $source = (string) file_get_contents(AiFixtures::root() . '/config/container.php');

        self::assertSame(1, substr_count($source, 'allowPlainHttp'), 'allowPlainHttp smí být v kontejneru jen jednou.');
        self::assertMatchesRegularExpression(
            '~new OllamaEmbeddingClient\(\s*new CurlHttpTransport\([^)]*allowPlainHttp:\s*true~',
            $source,
        );
    }

    // ---------------------------------------------------------------- zapojení v config/container.php

    private static function container(): Container
    {
        /** @var Container $container */
        $container = require AiFixtures::root() . '/config/container.php';

        return $container;
    }

    public function test_container_uses_fake_client_by_default(): void
    {
        $this->setEnvironment('EMBED_PROVIDER', 'falesny');

        self::assertInstanceOf(FakeEmbeddingClient::class, self::container()->get(EmbeddingClient::class));
    }

    public function test_container_uses_ollama_client_with_plain_http_transport_when_configured(): void
    {
        $this->setEnvironment('EMBED_PROVIDER', 'ollama');
        $this->setEnvironment('EMBED_MODEL', 'embeddinggemma');
        $this->setEnvironment('OLLAMA_URL', 'http://127.0.0.1:1');

        $container = self::container();
        $client = $container->get(EmbeddingClient::class);

        self::assertInstanceOf(OllamaEmbeddingClient::class, $client);
        self::assertSame('embeddinggemma', $client->model());
        $transport = null;
        foreach (new \ReflectionObject($client)->getProperties() as $property) {
            $value = $property->getValue($client);
            if ($value instanceof CurlHttpTransport) {
                $transport = $value;
            }
        }
        self::assertInstanceOf(CurlHttpTransport::class, $transport, 'OllamaEmbeddingClient má dostat CurlHttpTransport.');
        self::assertStringContainsString('(cURL 7)', self::curlErrorOf($transport, 'http://127.0.0.1:1/api/embed'));

        // Sdílený transport pro Claude zůstává jen HTTPS.
        $shared = $container->get(HttpTransport::class);
        self::assertNotSame($transport, $shared);
        self::assertStringContainsString('(cURL 1)', self::curlErrorOf($shared, 'http://127.0.0.1:1/'));
    }
}
