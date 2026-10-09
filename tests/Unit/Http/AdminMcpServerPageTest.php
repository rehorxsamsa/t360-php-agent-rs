<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Ai\Examples\Example10McpServer;
use App\Ai\Examples\ExampleRegistry;
use App\Ai\Tools\AgentTool;
use App\Tests\Unit\Support\ScriptedLlmClient;

/**
 * Plán 011, AC 18–20: informační stránka příkladu 10 (návod k připojení MCP serveru, nástroje se schématy,
 * náhled promptu) a přehled `/admin/ai`. Stránka nemá formulář, nevolá LLM ani ArticleRepository.
 */
final class AdminMcpServerPageTest extends AdminAiM7TestCase
{
    private const string PATH = '/admin/ai/10';
    private const string NOTE = 'Server jen čte publikované články a nic nezapisuje. Model běží v Claude Code – server ani tato stránka žádné AI API nevolají.';

    private ScriptedLlmClient $llm;

    protected function setUp(): void
    {
        parent::setUp();
        // Prázdná fronta: jakékoli volání LLM by vyhodilo výjimku a zaznamenalo se v $llm->requests.
        $this->llm = new ScriptedLlmClient();
        $this->boot($this->llm);
    }

    private function example(): Example10McpServer
    {
        return $this->container->get(Example10McpServer::class);
    }

    /** @return list<string> obsah všech `<pre>…</pre>` (HTML, jak je ve stránce) */
    private static function preBlocks(string $html): array
    {
        preg_match_all('~<pre\b[^>]*>(.*?)</pre>~su', $html, $matches);

        return $matches[1];
    }

    /** @return list<string> texty nadpisů dané úrovně */
    private static function headings(string $html, string $level): array
    {
        preg_match_all('~<' . $level . '\b[^>]*>(.*?)</' . $level . '>~su', $html, $matches);

        return array_map(static fn(string $inner): string => self::text($inner), $matches[1]);
    }

    private function assertNoSideEffects(): void
    {
        self::assertSame([], $this->llm->requests, 'Stránka nesmí volat LLM.');
        self::assertSame([], $this->aiCalls->calls);
        self::assertSame(0, $this->articles->totalCalls(), 'Schémata a prompt se skládají bez DB.');
    }

    // ---------------------------------------------------------------- AC 18: přístup

    public function test_anonymous_get_redirects_to_login(): void
    {
        self::assertRedirectsToLogin($this->get(self::PATH));
    }

    public function test_anonymous_post_with_valid_token_redirects_to_login(): void
    {
        self::assertRedirectsToLogin($this->post(self::PATH));
        $this->assertNoSideEffects();
    }

    public function test_signed_in_post_with_valid_token_is_404(): void
    {
        $this->signIn();

        self::assertSame(404, $this->post(self::PATH)->status);
        self::assertSame(404, $this->post(self::PATH, ['article' => 'demo'])->status);
        $this->assertNoSideEffects();
    }

    public function test_signed_in_post_without_token_is_rejected(): void
    {
        $this->signIn();

        self::assertSame(403, $this->post(self::PATH, withToken: false)->status);
        $this->assertNoSideEffects();
    }

    public function test_example_11_is_404(): void
    {
        $this->signIn();

        self::assertSame(404, $this->get('/admin/ai/11')->status);
        self::assertSame(404, $this->post('/admin/ai/11', ['article' => 'demo'])->status);
    }

    // ---------------------------------------------------------------- AC 19: stránka

    public function test_page_shows_heading_and_read_only_note_without_side_effects(): void
    {
        $this->signIn();

        $response = $this->get(self::PATH);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1>10 – MCP server redakce</h1>', $response->body);
        self::assertStringContainsString(self::NOTE, self::text($response->body));
        $headings = self::headings($response->body, 'h2');
        foreach (['Připojení', 'Nástroje', 'Prompt'] as $section) {
            self::assertContains($section, $headings, 'Chybí oddíl ' . $section);
        }
        $this->assertNoSideEffects();
    }

    public function test_connection_section_shows_escaped_command_in_code_block(): void
    {
        $this->signIn();

        $body = $this->get(self::PATH)->body;

        $command = e(Example10McpServer::CONNECT_COMMAND);
        self::assertStringContainsString('&quot;$KOREN/compose.yaml&quot;', $command);
        self::assertStringNotContainsString('"$KOREN/compose.yaml"', $body, 'Uvozovky v příkazu se escapují.');
        self::assertStringNotContainsString('"$PWD/compose.yaml"', $body, 'Registrace běží z jiného adresáře než kořen repa.');
        self::assertMatchesRegularExpression('~<pre\b[^>]*><code\b[^>]*>[^<]*' . preg_quote($command, '~') . '~u', $body);
        self::assertStringContainsString('mkdir -p ~/redakce-mcp &amp;&amp; cd ~/redakce-mcp', $body, 'Samostatný prázdný adresář pro relaci s MCP serverem.');
        $listCommand = e(Example10McpServer::LIST_COMMAND);
        self::assertStringContainsString('cd ~/redakce-mcp &amp;&amp; claude mcp list', $listCommand);
        $inCode = false;
        foreach (self::preBlocks($body) as $block) {
            $inCode = $inCode || str_contains($block, $listCommand);
        }
        self::assertTrue($inCode, '`claude mcp list` (z adresáře ~/redakce-mcp) je v bloku <pre><code>.');
    }

    /** V1 revize: návod nesmí tvrdit, že rozsah `local` chrání před relacemi týmu agentů; rozhoduje izolace adresářem. */
    public function test_connection_section_explains_directory_isolation_not_local_scope(): void
    {
        $this->signIn();

        $text = self::text($this->get(self::PATH)->body);

        self::assertStringContainsString('relace spuštěné v tomto adresáři', $text);
        self::assertStringContainsString('Spouštějte Claude Code z ~/redakce-mcp', $text);
        self::assertStringNotContainsString('Server je dostupný jen v tomto projektu', $text);
    }

    public function test_page_shows_visible_warning_about_auto_approved_sessions(): void
    {
        $this->signIn();

        $body = $this->get(self::PATH)->body;

        $warning = 'Nepoužívejte server v relaci, která má automaticky povolený Bash nebo --dangerously-skip-permissions; obsah článků je nedůvěryhodný vstup.';
        self::assertSame($warning, Example10McpServer::USAGE_WARNING);
        self::assertMatchesRegularExpression('~<p\b[^>]*class="form-error"[^>]*>\s*' . preg_quote(e($warning), '~') . '\s*</p>~u', $body);
        self::assertStringContainsString($warning, self::text($body));
        // Varování je dřív než příkaz k registraci, aby ho čtenář nepřehlédl.
        self::assertLessThan(strpos($body, '<pre'), strpos($body, e($warning)));
    }

    public function test_tools_section_lists_three_tools_with_descriptions_and_pretty_schemas(): void
    {
        $this->signIn();

        $body = $this->get(self::PATH)->body;

        $h3 = self::headings($body, 'h3');
        $names = ['hledej_clanky', 'nacti_clanek', 'statistiky'];
        self::assertSame($names, array_values(array_intersect($h3, $names)), 'Tři <h3> s názvy nástrojů v pořadí AC 4.');
        $blocks = self::preBlocks($body);
        foreach ($this->example()->tools() as $tool) {
            self::assertInstanceOf(AgentTool::class, $tool);
            $definition = $tool->definition();
            self::assertStringContainsString(e($definition['description']), $body, $definition['name']);
            $schema = e(json_encode(
                $definition['input_schema'],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
            $found = false;
            foreach ($blocks as $block) {
                $found = $found || str_contains($block, $schema);
            }
            self::assertTrue($found, 'Schéma nástroje ' . $definition['name'] . ' v <pre> (JSON_PRETTY_PRINT).');
        }
        self::assertStringContainsString('&quot;properties&quot;: {}', $body, 'Prázdné schéma statistiky jako objekt.');
        $this->assertNoSideEffects();
    }

    public function test_prompt_section_shows_slash_command_and_escaped_preview(): void
    {
        $this->signIn();

        $body = $this->get(self::PATH)->body;

        self::assertStringContainsString('/mcp__redakce__navrhni_clanek docker', $body);
        $preview = e($this->example()->suggestArticlePrompt(Example10McpServer::DEMO_TOPIC));
        self::assertStringContainsString('&lt;tema&gt;', $preview);
        $found = false;
        foreach (self::preBlocks($body) as $block) {
            $found = $found || str_contains($block, $preview);
        }
        self::assertTrue($found, 'Náhled promptu (escapovaný) v <pre>.');
        self::assertStringNotContainsString('<tema>', $body);
        $this->assertNoSideEffects();
    }

    public function test_page_has_no_post_form_for_example_10(): void
    {
        $this->signIn();

        $response = $this->get(self::PATH);
        $body = $response->body;

        self::assertSame(200, $response->status);
        self::assertNull(self::findTag($body, 'form', ['method' => 'post', 'action' => self::PATH]));
        self::assertStringNotContainsString('action="/admin/ai/10"', $body);
    }

    // ---------------------------------------------------------------- AC 20: přehled

    public function test_overview_lists_examples_01_to_10_with_descriptions(): void
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
            '08' => 'Sémantické vyhledávání (RAG)',
            '09' => 'AI redaktor',
            '10' => 'MCP server redakce',
        ];
        foreach ($titles as $id => $title) {
            self::assertStringContainsString(sprintf('<a href="/admin/ai/%s">%s – %s</a>', $id, $id, e($title)), $body);
        }
        self::assertStringContainsString(e($this->example()->description()), $body, 'Popis příkladu 10.');
        $registry = $this->container->get(ExampleRegistry::class);
        self::assertCount(10, $registry->listing());
        self::assertCount(5, $registry->all());
        self::assertNull($registry->get('10'));
    }
}
