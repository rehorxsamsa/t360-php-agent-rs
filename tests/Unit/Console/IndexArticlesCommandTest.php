<?php

declare(strict_types=1);

namespace App\Tests\Unit\Console;

use App\Ai\Embedding\EmbeddingClient;
use App\Ai\Embedding\FakeEmbeddingClient;
use App\Console\ConsoleApplication;
use App\Console\Output;
use App\Tests\Unit\Support\EmbeddingFixtures;
use App\Tests\Unit\Support\FixedClock;
use App\Tests\Unit\Support\InMemoryAiCallRepository;
use App\Tests\Unit\Support\InMemoryArticleEmbeddingRepository;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\ScriptedEmbeddingClient;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 009, AC 29: příkaz `ai:indexuj` (falešný klient embeddingů, vektory v paměti). */
final class IndexArticlesCommandTest extends TestCase
{
    private const string USAGE = 'Použití: php bin/konzole ai:indexuj';
    private const string SUMMARY = '~^Index aktualizován: zaindexováno %d, odebráno %d, čeká %d \(model fake-hash-768, falešný klient, tokeny \d+, \d+ ms\)\.$~mu';

    /** @var resource */
    private $stdout;
    /** @var resource */
    private $stderr;
    private InMemoryArticleEmbeddingRepository $embeddings;
    private InMemoryAiCallRepository $aiCalls;

    protected function setUp(): void
    {
        $this->stdout = fopen('php://memory', 'w+') ?: throw new \RuntimeException('stdout');
        $this->stderr = fopen('php://memory', 'w+') ?: throw new \RuntimeException('stderr');
        $this->embeddings = InMemoryArticleEmbeddingRepository::contract();
        $this->aiCalls = new InMemoryAiCallRepository();
    }

    /** @param list<string> $arguments */
    private function runCommand(array $arguments, ?EmbeddingClient $client = null): int
    {
        $application = TestContainer::withoutSession(
            new InMemoryUserRepository(),
            new InMemoryAuditLogRepository(),
            clock: FixedClock::at('2026-10-08 12:00:00'),
            aiCalls: $this->aiCalls,
            embeddings: $this->embeddings,
            embeddingClient: $client ?? new FakeEmbeddingClient(),
        )->get(ConsoleApplication::class);

        return $application->run($arguments, new Output($this->stdout, $this->stderr));
    }

    /** @param resource $stream */
    private function read(mixed $stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    private function allOutput(): string
    {
        return $this->read($this->stdout) . $this->read($this->stderr);
    }

    public function test_indexes_published_articles_and_prints_summary(): void
    {
        $code = $this->runCommand(['ai:indexuj']);

        self::assertSame(0, $code, $this->allOutput());
        self::assertMatchesRegularExpression(sprintf(self::SUMMARY, 3, 0, 0), $this->read($this->stdout));
        self::assertSame(['docker-pro-vyvojare', 'nova-studie-o-spanku', 'planovany-clanek'], $this->embeddings->indexedSlugs());
        self::assertSame([], $this->aiCalls->calls, 'Embeddingy se do ai_calls nelogují.');
    }

    public function test_second_run_reports_nothing_to_do_and_removed_vectors(): void
    {
        $this->embeddings->setVector('druhy-koncept', EmbeddingFixtures::unit(4));
        self::assertSame(0, $this->runCommand(['ai:indexuj']));

        $code = $this->runCommand(['ai:indexuj']);

        self::assertSame(0, $code, $this->allOutput());
        $out = $this->read($this->stdout);
        self::assertMatchesRegularExpression(sprintf(self::SUMMARY, 3, 1, 0), $out);
        self::assertMatchesRegularExpression(sprintf(self::SUMMARY, 0, 0, 0), $out);
    }

    public function test_embedding_failure_prints_message_and_returns_1(): void
    {
        $client = new ScriptedEmbeddingClient();
        $client->push(EmbeddingFixtures::failed());

        $code = $this->runCommand(['ai:indexuj'], $client);

        self::assertSame(1, $code);
        self::assertStringContainsString('Služba embeddingů (Ollama) neodpovídá na http://ollama:11434 – spusťte ji: make ai-local.', $this->allOutput());
        self::assertSame([], $this->embeddings->saved);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function invalidArguments(): iterable
    {
        yield 'argument' => [['ai:indexuj', 'vse']];
        yield 'option' => [['ai:indexuj', '--model=embeddinggemma']];
        yield 'flag' => [['ai:indexuj', '--force']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('invalidArguments')]
    public function test_any_argument_or_option_prints_usage_and_returns_1(array $arguments): void
    {
        $client = ScriptedEmbeddingClient::delegatingTo(new FakeEmbeddingClient());

        $code = $this->runCommand($arguments, $client);

        self::assertSame(1, $code);
        self::assertStringContainsString(self::USAGE, $this->allOutput());
        self::assertSame(0, $client->calls());
        self::assertSame([], $this->embeddings->saved);
    }

    public function test_command_is_listed_in_command_overview(): void
    {
        $this->runCommand([]);

        self::assertStringContainsString('ai:indexuj', $this->read($this->stdout));
    }
}
