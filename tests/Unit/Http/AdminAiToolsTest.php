<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Ai\Examples\ExampleRegistry;
use App\Ai\LlmErrorType;
use App\Ai\ToolCall;
use App\Domain\Ai\TokenUsage;
use App\Tests\Unit\Support\AiFixtures;
use App\Tests\Unit\Support\InMemoryAiCallRepository;
use App\Tests\Unit\Support\ScriptedLlmClient;

/**
 * Plán 008, AC 29–31: příklad 07 přes Kernel (stránka, PRG, chyby, escapování) a přehled `/admin/ai`
 * s příklady 06–07 a stavem „Přerušeno“.
 */
final class AdminAiToolsTest extends AdminAiM7TestCase
{
    private const string PATH = '/admin/ai/07';
    private const string DEMO_QUESTION = 'Co redakce píše o Dockeru?';

    private function textarea(string $html): string
    {
        $textarea = self::element($html, 'textarea', ['name' => 'question', 'id' => 'question']);
        self::assertNotNull($textarea, 'Chybí <textarea name="question" id="question">.');

        return $textarea;
    }

    private function assertFormWithQuestion(string $html, string $question): void
    {
        $form = self::element($html, 'form', ['method' => 'post', 'action' => self::PATH]);
        self::assertNotNull($form, 'Chybí <form method="post" action="/admin/ai/07">.');
        self::assertStringContainsString('name="_csrf"', $form);
        self::assertStringContainsString(e($question), $this->textarea($form));
    }

    // ---------------------------------------------------------------- AC 29: stránka

    public function test_ask_newsroom_page(): void
    {
        $llm = new ScriptedLlmClient();
        $this->boot($llm);
        $this->signIn();

        $response = $this->get(self::PATH);
        $body = $response->body;

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1>07 – Zeptej se redakce</h1>', $body);
        self::assertStringContainsString('<form method="post" action="/admin/ai/07">', $body);
        self::assertStringContainsString('name="_csrf" value="' . $this->csrf() . '"', $body);
        self::assertStringContainsString('<label for="question">Otázka</label>', $body);
        self::assertStringContainsString(self::DEMO_QUESTION, $this->textarea($body));
        self::assertMatchesRegularExpression('~<button\b[^>]*>\s*Zeptat se\s*</button>~u', $body);
        self::assertStringContainsString('Agent smí jen číst publikované články (nejvýše 5 kroků).', self::text($body));
        self::assertStringNotContainsString('id="vysledek"', $body);
        self::assertSame([], $llm->requests, 'GET nesmí volat AI.');
    }

    // ---------------------------------------------------------------- AC 29: běh (PRG)

    public function test_ask_redirects_and_shows_result_exactly_once(): void
    {
        $this->signIn();
        $question = 'Co píšete o Dockeru?';

        $response = $this->post(self::PATH, ['question' => $question]);

        self::assertSame(303, $response->status);
        self::assertSame(self::PATH, $response->headers['Location'] ?? null);
        self::assertCount(3, $this->aiCalls->calls);
        foreach ($this->aiCalls->calls as $call) {
            self::assertSame('07', $call->exampleId);
            self::assertSame(self::ADMIN_ID, $call->userId);
        }
        self::assertSame(['tool_use', 'tool_use', 'end_turn'], array_map(static fn($call): ?string => $call->stopReason, $this->aiCalls->calls));

        $page = $this->get(self::PATH);
        $body = $page->body;
        $text = self::text($body);
        self::assertSame(200, $page->status);
        self::assertSame(1, substr_count($body, '<h2 id="vysledek">Výsledek</h2>'));
        $positions = [];
        foreach (['Otázka', 'Odpověď', 'Krok 1 – hledej_clanky', 'Krok 2 – nacti_clanek', 'Zdroje'] as $label) {
            $position = strpos($text, $label, (int) strpos($text, 'Výsledek'));
            self::assertNotFalse($position, 'Ve výsledku chybí pole ' . $label);
            $positions[] = $position;
        }
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'Pole výsledku musí být v pořadí AC 18.');
        self::assertStringContainsString('Docker pro vývojáře: proč na něm záleží', $text);
        self::assertStringContainsString('/clanek/docker-pro-vyvojare', $text);
        self::assertMatchesRegularExpression('~ · volání 3 · ~u', $text);
        self::assertStringContainsString(e($question), $this->textarea($body), 'Otázka má být předvyplněná.');
        self::assertCount(3, $this->aiCalls->calls, 'GET nesmí volat AI.');

        $again = $this->get(self::PATH);
        self::assertStringNotContainsString('id="vysledek"', $again->body);
    }

    public function test_invalid_question_is_422_with_alert_and_submitted_question(): void
    {
        $llm = new ScriptedLlmClient();
        $this->boot($llm);
        $this->signIn();

        $response = $this->post(self::PATH, ['question' => 'ab']);

        self::assertSame(422, $response->status);
        self::assertAlert($response->body, 'Zadejte otázku (3–500 znaků).');
        $this->assertFormWithQuestion($response->body, 'ab');
        self::assertSame([], $llm->requests);
        self::assertSame([], $this->aiCalls->calls);
    }

    public function test_missing_question_is_422(): void
    {
        $this->signIn();

        $response = $this->post(self::PATH);

        self::assertSame(422, $response->status);
        self::assertAlert($response->body, 'Zadejte otázku (3–500 znaků).');
    }

    public function test_budget_exceeded_is_429_with_form(): void
    {
        $this->aiCalls->add(InMemoryAiCallRepository::call('2026-10-04 09:00:00', new TokenUsage(199000, 900), 1.0));
        $this->signIn();

        $response = $this->post(self::PATH, ['question' => 'Co víte o Dockeru?']);

        self::assertSame(429, $response->status);
        self::assertStringContainsString('Denní limit AI tokenů (200 000) by byl překročen', self::text($response->body));
        $this->assertFormWithQuestion($response->body, 'Co víte o Dockeru?');
        self::assertCount(1, $this->aiCalls->calls, 'Odmítnuté volání se neloguje.');
    }

    public function test_llm_call_failure_is_502_with_form(): void
    {
        $llm = new ScriptedLlmClient();
        $llm->push(AiFixtures::llmCallFailed(LlmErrorType::Overloaded, 529, 3));
        $this->boot($llm);
        $this->signIn();

        $response = $this->post(self::PATH, ['question' => 'Co víte o Dockeru?']);

        self::assertSame(502, $response->status);
        self::assertAlert($response->body, LlmErrorType::Overloaded->userMessage());
        $this->assertFormWithQuestion($response->body, 'Co víte o Dockeru?');
        self::assertStringNotContainsString('id="vysledek"', $response->body);
    }

    public function test_invalid_model_output_is_502_with_form(): void
    {
        $llm = new ScriptedLlmClient();
        $llm->push(AiFixtures::finalResponse(''));
        $this->boot($llm);
        $this->signIn();

        $response = $this->post(self::PATH, ['question' => 'Co víte o Dockeru?']);

        self::assertSame(502, $response->status);
        self::assertMatchesRegularExpression('~role="alert"~u', $response->body);
        $this->assertFormWithQuestion($response->body, 'Co víte o Dockeru?');
    }

    // ---------------------------------------------------------------- AC 30: escapování

    public function test_article_title_in_step_and_model_answer_are_escaped(): void
    {
        $this->articles->addArticle('xss-clanek', '<script>alert(1)</script>', 'Text o Dockeru.', publishedAt: '2026-09-20 08:00:00');
        $llm = new ScriptedLlmClient();
        $llm->push(
            AiFixtures::toolUseResponse([new ToolCall('toolu_1', 'nacti_clanek', ['slug' => 'xss-clanek'])]),
            AiFixtures::finalResponse('<img src=x onerror=alert(1)>'),
        );
        $this->boot($llm);
        $this->signIn();

        self::assertSame(303, $this->post(self::PATH, ['question' => '<b>Otázka</b> o Dockeru'])->status);
        $body = $this->get(self::PATH)->body;

        self::assertStringContainsString('id="vysledek"', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
        self::assertStringContainsString('&lt;img', $body);
        self::assertStringContainsString('&lt;b&gt;Otázka', $body);
        self::assertStringNotContainsString('<script>alert', $body);
        self::assertStringNotContainsString('<img src=x', $body);
        self::assertStringNotContainsString('<b>Otázka', $body);
    }

    // ---------------------------------------------------------------- AC 31: přehled

    public function test_overview_lists_examples_01_to_07_with_descriptions(): void
    {
        $this->signIn();

        $body = $this->get('/admin/ai')->body;

        $titles = [
            '01' => 'Perex na jedno kliknutí',
            '02' => 'SEO titulek a meta popis',
            '03' => 'Štítky a rubrika',
            '04' => 'Kontrola před publikací',
            '05' => 'Překlad CZ → EN',
            '06' => 'Asistent psaní',
            '07' => 'Zeptej se redakce',
        ];
        foreach ($titles as $id => $title) {
            self::assertStringContainsString(sprintf('<a href="/admin/ai/%s">%s – %s</a>', $id, $id, e($title)), $body);
        }
        foreach ($this->container->get(ExampleRegistry::class)->listing() as $example) {
            self::assertStringContainsString(e($example->description()), $body, 'Popis příkladu ' . $example->id());
        }
    }

    public function test_recent_calls_show_aborted_status(): void
    {
        $this->aiCalls->add(InMemoryAiCallRepository::call('2026-10-04 10:00:00', new TokenUsage(300, 40), 0.001, exampleId: '01'));
        $this->aiCalls->add(InMemoryAiCallRepository::call('2026-10-04 11:00:00', new TokenUsage(300, 12), 0.0007, exampleId: '06', stopReason: 'aborted'));
        $this->signIn();

        $text = self::text($this->get('/admin/ai')->body);

        self::assertStringContainsString('Poslední volání', $text);
        self::assertMatchesRegularExpression('~4\. října 2026 11:00.{0,200}Přerušeno~u', $text);
        self::assertSame(1, substr_count($text, 'Přerušeno'));
        self::assertMatchesRegularExpression('~\bOK\b~u', $text);
    }

    // ---------------------------------------------------------------- AC 37: CSRF ve formulářích

    public function test_every_post_form_on_new_pages_has_csrf_field(): void
    {
        $this->signIn();

        foreach (['/admin/ai/06', self::PATH] as $path) {
            $response = $this->get($path);
            self::assertSame(200, $response->status, $path);
            preg_match_all('~<form\b[^>]*method="post"[^>]*>(.*?)</form>~su', $response->body, $forms);
            self::assertNotSame([], $forms[1], $path . ': stránka bez POST formuláře');
            foreach ($forms[1] as $index => $form) {
                self::assertStringContainsString('name="_csrf"', $form, sprintf('%s: formulář %d bez _csrf', $path, $index));
            }
        }
    }
}
