<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\View;

use App\Http\View\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    private const string ALLOWED_TAG = '~^(?:</?(?:p|h2|h3|h4|ul|ol|li|blockquote|pre|code|strong|em|a)>|<a href="[^"<>]*">)$~';

    private MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new MarkdownRenderer();
    }

    /** Odstraní \n mezi značkami a na okrajích. */
    private function render(string $markdown): string
    {
        return trim((string) preg_replace('~>\s*\n\s*<~', '><', $this->renderer->toHtml($markdown)));
    }

    /** @return iterable<string, array{string, string}> */
    public static function blocks(): iterable
    {
        yield 'bold and italic' => ['Ahoj **světe** a *vy*', '<p>Ahoj <strong>světe</strong> a <em>vy</em></p>'];
        yield 'h2' => ['## Nadpis', '<h2>Nadpis</h2>'];
        yield 'h1 becomes h2' => ['# Nadpis', '<h2>Nadpis</h2>'];
        yield 'h3' => ['### N', '<h3>N</h3>'];
        yield 'h6 becomes h4' => ['###### N', '<h4>N</h4>'];
        yield 'unordered list' => ["- a\n- b", '<ul><li>a</li><li>b</li></ul>'];
        yield 'ordered list' => ["1. a\n2. b", '<ol><li>a</li><li>b</li></ol>'];
        yield 'blockquote' => ['> citace', '<blockquote><p>citace</p></blockquote>'];
        yield 'fenced code' => ["```\n<b>x</b> **y**\n```", '<pre><code>&lt;b&gt;x&lt;/b&gt; **y**</code></pre>'];
        yield 'inline code' => ['kód `<i>` a **`x`**', '<p>kód <code>&lt;i&gt;</code> a <strong><code>x</code></strong></p>'];
        yield 'two paragraphs' => ["odstavec 1\n\nodstavec 2", '<p>odstavec 1</p><p>odstavec 2</p>'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('blocks')]
    public function test_renders_supported_markdown(string $markdown, string $expected): void
    {
        self::assertSame($expected, $this->render($markdown));
    }

    public function test_windows_line_endings_are_normalized(): void
    {
        self::assertSame('<p>odstavec 1</p><p>odstavec 2</p>', $this->render("odstavec 1\r\n\r\nodstavec 2"));
    }

    /** @return iterable<string, array{string, string}> */
    public static function allowedLinks(): iterable
    {
        yield 'https' => ['[PHP](https://www.php.net/)', '<a href="https://www.php.net/">PHP</a>'];
        yield 'relative' => ['[o nás](/clanek/o-nas)', '<a href="/clanek/o-nas">o nás</a>'];
        yield 'anchor' => ['[x](#kapitola-2)', '<a href="#kapitola-2">x</a>'];
        yield 'mailto' => ['[x](mailto:a@b.cz)', '<a href="mailto:a@b.cz">x</a>'];
        yield 'ampersand escaped' => ['[x](https://a.cz/?a=1&b=2)', '<a href="https://a.cz/?a=1&amp;b=2">x</a>'];
    }

    #[DataProvider('allowedLinks')]
    public function test_allowed_links_are_rendered(string $markdown, string $expectedAnchor): void
    {
        self::assertStringContainsString($expectedAnchor, $this->render($markdown));
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenLinks(): iterable
    {
        yield 'quote breakout' => ['[x](https://a.cz/"onmouseover=alert(1))'];
        yield 'javascript' => ['[x](javascript:alert(1))'];
        yield 'javascript mixed case' => ['[x](JavaScript:alert(1))'];
        yield 'javascript with tab' => ["[x](java\tscript:alert(1))"];
        yield 'javascript with newline' => ["[x](java\nscript:alert(1))"];
        yield 'data uri' => ['[x](data:text/html;base64,PHN)'];
        yield 'vbscript' => ['[x](vbscript:x)'];
        yield 'protocol relative' => ['[x](//zlo.cz)'];
        yield 'leading space' => ['[x]( javascript:alert(1))'];
        yield 'entity encoded colon' => ['[x](javascript&#58;alert(1))'];
        yield 'entity encoded letters' => ['[x](&#106;avascript:alert(1))'];
        yield 'backslash relative' => ['[x](/\\zlo.cz)'];
    }

    #[DataProvider('forbiddenLinks')]
    public function test_forbidden_links_render_as_plain_text(string $markdown): void
    {
        $html = $this->render($markdown);

        self::assertStringNotContainsString('<a', $html);
        self::assertStringContainsString('x', $html);
    }

    /** @return iterable<string, array{string}> */
    public static function attacks(): iterable
    {
        yield 'script tag' => ['<script>alert(1)</script>'];
        yield 'img onerror' => ['<img src=x onerror=alert(1)>'];
        yield 'raw anchor' => ['<a href="javascript:x">y</a>'];
        yield 'tag inside bold' => ['**<b>**'];
        yield 'tag inside link text' => ['[<b>x</b>](https://a.cz)'];
        yield 'attribute breakout' => ['"><svg onload=alert(1)>'];
        yield 'entities' => ['&lt;script&gt;'];
        yield 'invalid utf8' => ["\xC3\x28"];
        yield 'raw html in list' => ["- <script>x</script>\n- b"];
        yield 'raw html in quote' => ['> <iframe src=x>'];
        yield 'raw html in heading' => ['## <script>x</script>'];
        yield 'unclosed fence' => ["```\n<script>x</script>"];
    }

    #[DataProvider('attacks')]
    public function test_attacks_never_produce_active_html(string $markdown): void
    {
        $html = $this->renderer->toHtml($markdown);

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<svg', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertSame(0, preg_match('~<[^>]*\son(?:error|load)\s*=~i', $html), 'event handler attribute');
        self::assertSame(0, preg_match('~href\s*=\s*"\s*javascript:~i', $html), 'javascript: href');
        self::assertTrue(mb_check_encoding($html, 'UTF-8'), 'výstup musí být platné UTF-8');
        $this->assertOnlyAllowedTags($html);
    }

    public function test_entity_in_input_is_double_escaped_not_decoded(): void
    {
        self::assertStringContainsString('&amp;lt;script&amp;gt;', $this->render('&lt;script&gt;'));
    }

    /** @return iterable<string, array{string}> */
    public static function allSupportedSamples(): iterable
    {
        foreach (self::blocks() as $name => [$markdown]) {
            yield 'block: ' . $name => [$markdown];
        }
        foreach (self::allowedLinks() as $name => [$markdown]) {
            yield 'link: ' . $name => [$markdown];
        }
        foreach (self::forbiddenLinks() as $name => [$markdown]) {
            yield 'forbidden link: ' . $name => [$markdown];
        }
    }

    #[DataProvider('allSupportedSamples')]
    public function test_output_contains_only_allowed_tags(string $markdown): void
    {
        $this->assertOnlyAllowedTags($this->renderer->toHtml($markdown));
    }

    private function assertOnlyAllowedTags(string $html): void
    {
        preg_match_all('~<[^>]*>~', $html, $matches);
        foreach ($matches[0] as $tag) {
            self::assertMatchesRegularExpression(self::ALLOWED_TAG, $tag, 'nepovolená značka: ' . $tag);
        }
        // Holé "<" mimo značku (které by prohlížeč mohl vyložit jako značku) se nesmí objevit.
        self::assertSame(0, preg_match('~<(?![/a-z])~i', $html), 'holé "<" ve výstupu');
        self::assertSame(0, preg_match('~<[a-z/][^>]*$~i', $html), 'neuzavřená značka');
    }

    /** @return iterable<string, array{string}> */
    public static function pathologicalInputs(): iterable
    {
        yield 'asterisks' => [str_repeat('*', 100000)];
        yield 'brackets' => [str_repeat('[', 100000)];
        yield 'backticks' => [str_repeat('`', 100000)];
        yield 'unclosed bold' => [str_repeat('**a', 33334)];
        yield 'unclosed link' => [str_repeat('[a](', 25000)];
        yield 'many hashes' => [str_repeat('#', 100000)];
        yield 'many lines' => [str_repeat("- a\n", 25000)];
    }

    #[DataProvider('pathologicalInputs')]
    public function test_pathological_input_finishes_quickly_without_warnings(string $markdown): void
    {
        $start = microtime(true);

        $html = $this->renderer->toHtml($markdown);

        self::assertLessThan(1.0, microtime(true) - $start);
        self::assertTrue(mb_check_encoding($html, 'UTF-8'));
    }
}
