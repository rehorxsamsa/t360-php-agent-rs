<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Ai\Client\FakeLlmClient;
use App\Ai\Examples\Example06WritingAssistant;
use App\Ai\Examples\WritingTask;
use App\Ai\LlmErrorType;
use App\Domain\Ai\AiCallStatus;
use App\Http\Response;
use App\Http\Stream\StreamOutput;
use App\Tests\Unit\Support\AiFixtures;
use App\Tests\Unit\Support\ArraySession;
use App\Tests\Unit\Support\BufferStreamOutput;
use App\Tests\Unit\Support\ScriptedStreamingLlmClient;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plán 008, AC 24–28 a 30: příklad 06 přes Kernel – stránka `/admin/ai/06` a proud `POST /admin/ai/06/proud`
 * (producent se spouští ručně do `BufferStreamOutput`, jako by to udělal `Response::send()`).
 */
final class AdminAiStreamingTest extends AdminAiM7TestCase
{
    private const string STREAM_PATH = '/admin/ai/06/proud';

    private function streamPost(string $action = 'zkrat', string $text = Example06WritingAssistant::DEMO_TEXT): Response
    {
        return $this->post(self::STREAM_PATH, ['action' => $action, 'text' => $text]);
    }

    private static function runProducer(Response $response, ?StreamOutput $output = null): BufferStreamOutput|StreamOutput
    {
        self::assertNotNull($response->producer, 'Odpověď musí být streamovaná (Response::stream).');
        $output ??= new BufferStreamOutput();
        ($response->producer)($output);

        return $output;
    }

    /** Text, který pro ukázkový odstavec a akci vrátí falešný klient. */
    private function fakeText(string $action = 'zkrat'): string
    {
        $request = $this->container->get(Example06WritingAssistant::class)
            ->request(WritingTask::fromInput($action, Example06WritingAssistant::DEMO_TEXT), self::ADMIN_ID);

        return new FakeLlmClient()->complete($request)->text;
    }

    /**
     * @param list<array{event: string, data: mixed}> $events
     * @return list<string>
     */
    private static function eventNames(array $events): array
    {
        return array_column($events, 'event');
    }

    // ---------------------------------------------------------------- AC 24: přístup

    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        yield '06' => ['/admin/ai/06'];
        yield '07' => ['/admin/ai/07'];
    }

    #[DataProvider('pages')]
    public function test_anonymous_get_redirects_to_login(string $path): void
    {
        self::assertRedirectsToLogin($this->get($path), $path);
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function posts(): iterable
    {
        yield 'stream 06' => [self::STREAM_PATH, ['action' => 'zkrat', 'text' => 'Text.']];
        yield 'ask 07' => ['/admin/ai/07', ['question' => 'Co redakce píše o Dockeru?']];
    }

    /** @param array<string, string> $body */
    #[DataProvider('posts')]
    public function test_anonymous_post_with_valid_token_redirects_to_login_without_calling_llm(string $path, array $body): void
    {
        $llm = new ScriptedStreamingLlmClient();
        $this->boot($llm);

        $response = $this->post($path, $body);

        self::assertRedirectsToLogin($response, $path);
        self::assertNull($response->producer);
        self::assertSame([], $llm->requests);
        self::assertSame([], $this->aiCalls->calls);
    }

    /** @param array<string, string> $body */
    #[DataProvider('posts')]
    public function test_signed_in_post_without_token_is_403(string $path, array $body): void
    {
        $llm = new ScriptedStreamingLlmClient();
        $this->boot($llm);
        $this->signIn();

        $response = $this->post($path, $body, withToken: false);

        self::assertSame(403, $response->status, $path);
        self::assertNull($response->producer);
        self::assertSame([], $llm->requests);
        self::assertSame([], $this->aiCalls->calls);
    }

    /** @param array<string, string> $body */
    #[DataProvider('posts')]
    public function test_signed_in_post_with_wrong_token_is_403(string $path, array $body): void
    {
        $llm = new ScriptedStreamingLlmClient();
        $this->boot($llm);
        $this->signIn();
        $this->csrf();

        $response = $this->post($path, $body, token: str_repeat('0', 64));

        self::assertSame(403, $response->status, $path);
        self::assertNull($response->producer);
        self::assertSame([], $llm->requests);
        self::assertSame([], $this->aiCalls->calls);
    }

    public function test_get_on_stream_endpoint_is_405(): void
    {
        $this->signIn();

        self::assertSame(405, $this->get(self::STREAM_PATH)->status);
    }

    /** Plán 009 a 010 (záměrné regrese M7, M7b): 08 a 09 už existují, neexistující je nově 10. */
    public function test_example_10_is_404(): void
    {
        $this->signIn();

        self::assertSame(404, $this->get('/admin/ai/10')->status);
        self::assertSame(404, $this->post('/admin/ai/10', ['article' => 'demo'])->status);
    }

    // ---------------------------------------------------------------- AC 25: stránka 06

    public function test_writing_assistant_page(): void
    {
        $llm = new ScriptedStreamingLlmClient();
        $this->boot($llm);
        $this->signIn();

        $response = $this->get('/admin/ai/06');
        $body = $response->body;

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1>06 – Asistent psaní</h1>', $body);
        $form = self::element($body, 'form', ['id' => 'writing-assistant', 'method' => 'post', 'action' => '/admin/ai/06/proud']);
        self::assertNotNull($form, 'Chybí <form id="writing-assistant" method="post" action="/admin/ai/06/proud">.');
        self::assertStringContainsString('name="_csrf" value="' . $this->csrf() . '"', $form);
        self::assertStringContainsString('<label for="text">Text</label>', $form);
        $textarea = self::element($form, 'textarea', ['name' => 'text', 'id' => 'text']);
        self::assertNotNull($textarea, 'Chybí <textarea name="text" id="text">.');
        self::assertStringContainsString(e(Example06WritingAssistant::DEMO_TEXT), $textarea);
        self::assertNotNull(self::findTag($form, 'select', ['name' => 'action', 'id' => 'action']), 'Chybí <select name="action" id="action">.');
        self::assertSame(
            [
                ['value' => 'pokracuj', 'text' => 'Pokračovat v textu', 'selected' => true],
                ['value' => 'zkrat', 'text' => 'Zkrátit', 'selected' => false],
                ['value' => 'zjednodus', 'text' => 'Zjednodušit', 'selected' => false],
            ],
            self::options($form, 'action'),
        );
        self::assertMatchesRegularExpression('~<button\b(?=[^>]*\stype="submit")[^>]*>\s*Generovat\s*</button>~u', $form);
        self::assertMatchesRegularExpression('~<button\b(?=[^>]*\stype="button")(?=[^>]*\sdisabled(?=[\s/>=]))[^>]*>\s*Přerušit\s*</button>~u', $body);
        self::assertNotNull(self::findTag($body, '[a-z]+', ['id' => 'ai-stream-output']), 'Chybí výstup id="ai-stream-output".');
        self::assertNotNull(self::findTag($body, '[a-z]+', ['role' => 'status']), 'Chybí stavový řádek role="status".');
        self::assertStringContainsString('<script src="/assets/ai-stream.js" defer></script>', $body);
        self::assertMatchesRegularExpression('~<noscript>.*Asistent psaní potřebuje zapnutý JavaScript\..*</noscript>~su', $body);
        self::assertSame([], $llm->requests, 'GET nesmí volat AI.');
        self::assertSame([], $this->aiCalls->calls);
    }

    // ---------------------------------------------------------------- AC 26: proud

    public function test_stream_response_headers_and_session_release(): void
    {
        $this->signIn();

        $response = $this->streamPost();

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
        self::assertSame('text/event-stream; charset=utf-8', $response->headers['Content-Type'] ?? null);
        self::assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        self::assertSame('no', $response->headers['X-Accel-Buffering'] ?? null);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options'] ?? null);
        self::assertSame('DENY', $response->headers['X-Frame-Options'] ?? null);
        self::assertArrayHasKey('Content-Security-Policy', $response->headers);
        self::assertArrayHasKey('Referrer-Policy', $response->headers);

        $session = $this->session;
        $output = new class ($session) implements StreamOutput {
            public ?bool $releasedAtFirstWrite = null;
            public string $body = '';

            public function __construct(private readonly ArraySession $session) {}

            public function write(string $chunk): void
            {
                $this->releasedAtFirstWrite ??= $this->session->released;
                $this->body .= $chunk;
            }

            public function isAborted(): bool
            {
                return false;
            }
        };
        self::runProducer($response, $output);

        self::assertTrue($output->releasedAtFirstWrite, 'Session musí být uvolněná dřív, než producent zapíše první bajt.');
        self::assertSame(0, $this->session->writesAfterRelease, 'Po uvolnění se do session nezapisuje.');
        self::assertNotSame('', $output->body);
    }

    public function test_stream_body_has_start_comment_deltas_and_done(): void
    {
        $this->signIn();

        $output = self::runProducer($this->streamPost());
        self::assertInstanceOf(BufferStreamOutput::class, $output);
        $body = $output->body();
        $events = $output->events();

        self::assertStringStartsWith(": start\n\n", $body);
        $deltas = array_values(array_filter($events, static fn(array $event): bool => $event['event'] === 'delta'));
        self::assertGreaterThanOrEqual(2, count($deltas));
        $text = '';
        foreach ($deltas as $delta) {
            self::assertIsArray($delta['data']);
            self::assertSame(['text'], array_keys($delta['data']));
            self::assertIsString($delta['data']['text']);
            $text .= $delta['data']['text'];
        }
        self::assertSame($this->fakeText('zkrat'), $text);

        $last = end($events);
        self::assertNotFalse($last);
        self::assertSame('done', $last['event']);
        self::assertStringEndsWith("\n\n", $body);
        self::assertIsArray($last['data']);
        $keys = array_keys($last['data']);
        sort($keys);
        self::assertSame(['costUsd', 'inputTokens', 'model', 'outputTokens', 'provider', 'providerLabel', 'stopReason'], $keys);
        self::assertSame('end_turn', $last['data']['stopReason']);
        self::assertSame('claude-sonnet-5-5', $last['data']['model']);
        self::assertSame('fake', $last['data']['provider']);
        self::assertSame('falešný klient', $last['data']['providerLabel']);
        self::assertGreaterThan(0, $last['data']['inputTokens']);
        self::assertGreaterThan(0, $last['data']['outputTokens']);
        self::assertIsNumeric($last['data']['costUsd']);
        self::assertSame(1, substr_count($body, 'event: done'));

        self::assertCount(1, $this->aiCalls->calls);
        $call = $this->aiCalls->calls[0];
        self::assertSame('06', $call->exampleId);
        self::assertSame(self::ADMIN_ID, $call->userId);
        self::assertSame('end_turn', $call->stopReason);
        self::assertSame(AiCallStatus::Ok, $call->status);
        self::assertSame($last['data']['outputTokens'], $call->usage->output);
    }

    // ---------------------------------------------------------------- AC 27: chyby proudu

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidInputs(): iterable
    {
        yield 'empty text' => ['zkrat', '   ', 'Zadejte text.'];
        yield 'unknown action' => ['xyz', 'Text.', 'Vyberte akci.'];
        yield 'text too long' => ['zkrat', str_repeat('a', 5001), 'Text je pro asistenta příliš dlouhý (max. 5 000 znaků).'];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_is_422_json_without_calling_llm(string $action, string $text, string $message): void
    {
        $llm = new ScriptedStreamingLlmClient();
        $this->boot($llm);
        $this->signIn();

        $response = $this->streamPost($action, $text);

        self::assertSame(422, $response->status);
        self::assertNull($response->producer);
        self::assertStringStartsWith('application/json', $response->headers['Content-Type'] ?? '');
        self::assertSame(['error' => $message], json_decode($response->body, true));
        self::assertSame([], $llm->requests);
        self::assertSame([], $this->aiCalls->calls);
    }

    public function test_missing_fields_are_422_json(): void
    {
        $this->signIn();

        $response = $this->post(self::STREAM_PATH, []);

        self::assertSame(422, $response->status);
        self::assertSame(['error' => 'Vyberte akci.'], json_decode($response->body, true));
    }

    public function test_budget_exceeded_is_single_error_event_without_deltas(): void
    {
        $this->boot(config: AiFixtures::config(dailyTokenLimit: 500));
        $this->signIn();

        $response = $this->streamPost();
        self::assertSame(200, $response->status);
        $output = self::runProducer($response);
        self::assertInstanceOf(BufferStreamOutput::class, $output);
        $events = $output->events();

        self::assertSame(['error'], self::eventNames($events));
        self::assertIsArray($events[0]['data']);
        self::assertSame(['message'], array_keys($events[0]['data']));
        self::assertIsString($events[0]['data']['message']);
        self::assertStringStartsWith('Denní limit AI tokenů', $events[0]['data']['message']);
        self::assertSame([], $this->aiCalls->calls);
    }

    public function test_llm_failure_in_middle_of_stream_ends_with_error_event(): void
    {
        $llm = new ScriptedStreamingLlmClient();
        $llm->push(['Ahoj'], AiFixtures::llmCallFailed(LlmErrorType::Overloaded, 200));
        $this->boot($llm);
        $this->signIn();

        $output = self::runProducer($this->streamPost());
        self::assertInstanceOf(BufferStreamOutput::class, $output);
        $events = $output->events();

        self::assertSame(['delta', 'error'], self::eventNames($events));
        self::assertSame(['message' => LlmErrorType::Overloaded->userMessage()], $events[1]['data']);
    }

    public function test_unexpected_exception_is_generic_error_event_and_detail_goes_to_error_log(): void
    {
        $llm = new ScriptedStreamingLlmClient();
        $llm->push([], new \RuntimeException('Tajný detail 4242'));
        $this->boot($llm);
        $this->signIn();

        $output = self::runProducer($this->streamPost());
        self::assertInstanceOf(BufferStreamOutput::class, $output);
        $events = $output->events();

        self::assertSame(['error'], self::eventNames($events));
        self::assertSame(['message' => 'Interní chyba serveru.'], $events[0]['data']);
        self::assertStringNotContainsString('Tajný detail', $output->body());
        self::assertStringContainsString('Tajný detail 4242', $this->errorLog());
    }

    // ---------------------------------------------------------------- AC 28: přerušení

    public function test_client_abort_stops_producer_and_logs_aborted_call(): void
    {
        $this->signIn();

        $output = new BufferStreamOutput(abortAfterWrites: 2);
        self::runProducer($this->streamPost(), $output);

        self::assertSame(0, $output->writesAfterAbort, 'Po přerušení se už nic nezapisuje.');
        self::assertStringNotContainsString('event: done', $output->body());
        self::assertCount(1, $this->aiCalls->calls);
        $call = $this->aiCalls->calls[0];
        self::assertSame('aborted', $call->stopReason);
        self::assertSame(AiCallStatus::Ok, $call->status);
        self::assertGreaterThan(0, $call->usage->output);
        self::assertSame('06', $call->exampleId);
    }

    // ---------------------------------------------------------------- AC 30: escapování v proudu

    public function test_model_text_is_only_inside_json_data_lines(): void
    {
        $evil = ["<img src=x onerror=alert(1)>", "\n\nevent: done\ndata: {}\n\n", "</script><script>alert(1)</script>"];
        $llm = new ScriptedStreamingLlmClient();
        $llm->pushText(...$evil);
        $this->boot($llm);
        $this->signIn();

        $output = self::runProducer($this->streamPost());
        self::assertInstanceOf(BufferStreamOutput::class, $output);

        foreach (explode("\n", $output->body()) as $line) {
            if ($line === '') {
                continue;
            }
            self::assertMatchesRegularExpression('~^(?:: .*|event: [a-z]+|data: \{.*\})$~u', $line, 'Neočekávaný řádek proudu: ' . $line);
            if (str_starts_with($line, 'data: ')) {
                self::assertIsArray(json_decode(substr($line, 6), true), 'data: musí být JSON: ' . $line);
            }
        }
        $events = $output->events();
        $texts = array_column(array_column(array_filter($events, static fn(array $event): bool => $event['event'] === 'delta'), 'data'), 'text');
        self::assertSame($evil, $texts);
        self::assertSame(['delta', 'delta', 'delta', 'done'], self::eventNames($events));
    }

    public function test_stream_script_never_inserts_html(): void
    {
        $path = AiFixtures::root() . '/public/assets/ai-stream.js';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval', 'new Function'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source, $forbidden);
        }
        self::assertStringContainsString('textContent', $source);
        self::assertStringContainsString('AbortController', $source);
    }
}
