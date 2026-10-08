<?php

declare(strict_types=1);

namespace App\Tests\Unit\Console;

use App\Ai\Client\FakeLlmClient;
use App\Ai\Examples\Example06WritingAssistant;
use App\Ai\Examples\WritingTask;
use App\Console\ConsoleApplication;
use App\Console\Output;
use App\Tests\Unit\Support\FixedClock;
use App\Tests\Unit\Support\InMemoryAiCallRepository;
use App\Tests\Unit\Support\InMemoryArticleAdminRepository;
use App\Tests\Unit\Support\InMemoryArticleRepository;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 006, AC 29: příkaz `ai:priklad NN [--clanek=…] [--model=…]` (falešný klient, log v paměti). */
final class AiExampleCommandTest extends TestCase
{
    /** Plán 008, AC 32: nápověda pro příklady 01–07 (regrese M6 záměrná). */
    private const string USAGE = 'Použití: php bin/konzole ai:priklad 01–07 [--clanek=…] [--model=ID] [--akce=pokracuj|zkrat|zjednodus] [--text=…] [--otazka=…]';

    /** @var resource */
    private $stdout;
    /** @var resource */
    private $stderr;
    private InMemoryAiCallRepository $aiCalls;
    private InMemoryArticleAdminRepository $articles;
    private ConsoleApplication $application;

    protected function setUp(): void
    {
        $this->stdout = fopen('php://memory', 'w+') ?: throw new \RuntimeException('stdout');
        $this->stderr = fopen('php://memory', 'w+') ?: throw new \RuntimeException('stderr');
        $this->aiCalls = new InMemoryAiCallRepository();
        $this->articles = new InMemoryArticleAdminRepository();
        $this->application = TestContainer::withoutSession(
            new InMemoryUserRepository(),
            new InMemoryAuditLogRepository(),
            adminArticles: $this->articles,
            aiCalls: $this->aiCalls,
        )->get(ConsoleApplication::class);
    }

    /** @param list<string> $arguments */
    private function runCommand(array $arguments): int
    {
        return $this->application->run($arguments, new Output($this->stdout, $this->stderr));
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

    public function test_example_01_prints_fields_and_cost_line(): void
    {
        $code = $this->runCommand(['ai:priklad', '01']);
        $out = $this->read($this->stdout);

        self::assertSame(0, $code, $this->allOutput());
        self::assertStringContainsString('Příklad 01 – Perex na jedno kliknutí (článek: Ukázkový článek)', $out);
        self::assertMatchesRegularExpression('~^Perex: \S.*$~mu', $out);
        self::assertMatchesRegularExpression(
            '~^Model claude-sonnet-5-5 · poskytovatel \S.* · volání 1 · tokeny vstup [0-9 ]+ / výstup [0-9 ]+ · cena [0-9 ,.]+ USD$~mu',
            $out,
        );
    }

    public function test_console_call_is_logged_without_user(): void
    {
        $this->runCommand(['ai:priklad', '01']);

        self::assertCount(1, $this->aiCalls->calls);
        self::assertNull($this->aiCalls->calls[0]->userId);
        self::assertSame('01', $this->aiCalls->calls[0]->exampleId);
        self::assertSame('fake', $this->aiCalls->calls[0]->provider);
    }

    public function test_review_of_injection_demo_reports_prompt_injection(): void
    {
        $code = $this->runCommand(['ai:priklad', '04', '--clanek=demo-injection']);

        self::assertSame(0, $code, $this->allOutput());
        self::assertStringContainsString('prompt injection', $this->read($this->stdout));
    }

    public function test_translation_with_cheap_model(): void
    {
        $code = $this->runCommand(['ai:priklad', '05', '--model=claude-haiku-4-5-20251001']);

        self::assertSame(0, $code, $this->allOutput());
        self::assertMatchesRegularExpression('~^Model claude-haiku-4-5-20251001 · ~mu', $this->read($this->stdout));
        self::assertSame('claude-haiku-4-5-20251001', $this->aiCalls->calls[0]->model ?? null);
    }

    public function test_article_from_database_by_id(): void
    {
        $this->articles->add(InMemoryArticleAdminRepository::editable(5, title: 'Článek pět', body: 'Text článku pět.'));

        $code = $this->runCommand(['ai:priklad', '01', '--clanek=5']);

        self::assertSame(0, $code, $this->allOutput());
        self::assertStringContainsString('Příklad 01 – Perex na jedno kliknutí (článek: Článek pět)', $this->read($this->stdout));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function invalidArguments(): iterable
    {
        yield 'no example' => [['ai:priklad']];
        yield 'example 08' => [['ai:priklad', '08']];
        yield 'unknown writing action' => [['ai:priklad', '06', '--akce=xyz']];
        yield 'example 1' => [['ai:priklad', '1']];
        yield 'unknown option' => [['ai:priklad', '01', '--neco=1']];
        yield 'two examples' => [['ai:priklad', '01', '02']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('invalidArguments')]
    public function test_invalid_arguments_print_usage_and_return_1(array $arguments): void
    {
        $code = $this->runCommand($arguments);

        self::assertSame(1, $code);
        self::assertStringContainsString(self::USAGE, $this->allOutput());
        self::assertSame([], $this->aiCalls->calls);
    }

    public function test_missing_article_returns_1_with_message(): void
    {
        $code = $this->runCommand(['ai:priklad', '01', '--clanek=999999']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Článek 999999 neexistuje.', $this->allOutput());
        self::assertSame([], $this->aiCalls->calls);
    }

    public function test_command_is_listed_in_command_overview(): void
    {
        $this->runCommand([]);

        self::assertStringContainsString('ai:priklad', $this->read($this->stdout));
    }

    // ---------------------------------------------------------------- plán 008, AC 32: příklady 06 a 07

    private const string SUMMARY = '~^Model claude-sonnet-5-5 · poskytovatel \S.* · volání %d · tokeny vstup [0-9 ]+ / výstup [0-9 ]+ · cena [0-9 ,.]+ USD$~mu';

    /** Text, který pro daný úkol vrátí falešný klient (stejné zapojení jako příkaz). */
    private static function fakeWritingText(string $action, string $text): string
    {
        $container = TestContainer::withoutSession(new InMemoryUserRepository(), new InMemoryAuditLogRepository());
        $request = $container->get(Example06WritingAssistant::class)->request(WritingTask::fromInput($action, $text), null);

        return new FakeLlmClient()->complete($request)->text;
    }

    public function test_example_06_streams_demo_text_and_prints_summary(): void
    {
        $code = $this->runCommand(['ai:priklad', '06', '--akce=zkrat']);
        $out = $this->read($this->stdout);

        self::assertSame(0, $code, $this->allOutput());
        $expected = self::fakeWritingText('zkrat', Example06WritingAssistant::DEMO_TEXT);
        self::assertNotSame('', $expected);
        // Přírůstky se vypisují za sebou bez konce řádku (Output::write), text je tedy na jednom řádku pod hlavičkou.
        self::assertStringContainsString(
            'Příklad 06 – Asistent psaní (akce: Zkrátit)' . PHP_EOL . $expected . PHP_EOL,
            $out,
        );
        self::assertMatchesRegularExpression(sprintf(self::SUMMARY, 1), $out);
        self::assertCount(1, $this->aiCalls->calls);
        self::assertSame('06', $this->aiCalls->calls[0]->exampleId);
        self::assertNull($this->aiCalls->calls[0]->userId);
        self::assertSame('end_turn', $this->aiCalls->calls[0]->stopReason);
    }

    public function test_example_06_default_action_continues_given_text(): void
    {
        $text = 'Vlastní odstavec pro asistenta. Druhá věta odstavce.';

        $code = $this->runCommand(['ai:priklad', '06', '--text=' . $text]);
        $out = $this->read($this->stdout);

        self::assertSame(0, $code, $this->allOutput());
        self::assertStringContainsString('Příklad 06 – Asistent psaní (akce: Pokračovat v textu)', $out);
        self::assertStringContainsString(self::fakeWritingText('pokracuj', $text), $out);
    }

    public function test_example_06_with_empty_text_fails_without_calling_llm(): void
    {
        $code = $this->runCommand(['ai:priklad', '06', '--akce=zkrat', '--text=   ']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Zadejte text.', $this->allOutput());
        self::assertSame([], $this->aiCalls->calls);
    }

    public function test_example_07_prints_fields_steps_sources_and_three_calls(): void
    {
        $aiCalls = new InMemoryAiCallRepository();
        $application = TestContainer::withoutSession(
            new InMemoryUserRepository(),
            new InMemoryAuditLogRepository(),
            articles: InMemoryArticleRepository::newsroomContract(),
            clock: FixedClock::at('2026-10-04 12:00:00'),
            aiCalls: $aiCalls,
        )->get(ConsoleApplication::class);

        $code = $application->run(['ai:priklad', '07', '--otazka=Co redakce píše o Dockeru?'], new Output($this->stdout, $this->stderr));
        $out = $this->read($this->stdout);

        self::assertSame(0, $code, $this->allOutput());
        self::assertStringContainsString('Příklad 07 – Zeptej se redakce', $out);
        self::assertMatchesRegularExpression('~^Otázka: Co redakce píše o Dockeru\?$~mu', $out);
        self::assertMatchesRegularExpression('~^Odpověď: .*Docker pro vývojáře: proč na něm záleží.*$~mu', $out);
        self::assertMatchesRegularExpression('~^Krok 1 – hledej_clanky: \S.*$~mu', $out);
        self::assertMatchesRegularExpression('~^Krok 2 – nacti_clanek: \S.*$~mu', $out);
        self::assertMatchesRegularExpression('~^Zdroje: /clanek/docker-pro-vyvojare$~mu', $out);
        self::assertMatchesRegularExpression(sprintf(self::SUMMARY, 3), $out);
        self::assertLessThan(strpos($out, 'Krok 1 –'), strpos($out, 'Odpověď:'));
        self::assertLessThan(strpos($out, 'Zdroje:'), strpos($out, 'Krok 2 –'));
        self::assertCount(3, $aiCalls->calls);
        foreach ($aiCalls->calls as $call) {
            self::assertNull($call->userId);
            self::assertSame('07', $call->exampleId);
        }
    }

    public function test_example_07_with_too_short_question_fails_without_calling_llm(): void
    {
        $code = $this->runCommand(['ai:priklad', '07', '--otazka=ab']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Zadejte otázku (3–500 znaků).', $this->allOutput());
        self::assertSame([], $this->aiCalls->calls);
    }
}
