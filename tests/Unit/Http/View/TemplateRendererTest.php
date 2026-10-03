<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\View;

use App\Application\Article\ArticlePage;
use App\Http\View\TemplateNotFound;
use App\Http\View\TemplateRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemplateRendererTest extends TestCase
{
    private function fixtures(): TemplateRenderer
    {
        return new TemplateRenderer(__DIR__ . '/templates');
    }

    private function real(): TemplateRenderer
    {
        return new TemplateRenderer(__DIR__ . '/../../../../templates');
    }

    public function test_renders_template_without_layout_with_escaped_data(): void
    {
        self::assertSame("Ahoj &lt;b&gt;\n", $this->fixtures()->render('plain', ['name' => '<b>'], null));
    }

    public function test_home_is_wrapped_in_layout_with_escaped_title(): void
    {
        $html = $this->real()->render('home', ['title' => '<b>Ahoj</b>', 'page' => new ArticlePage([], 1, 1, 0)]);

        self::assertStringContainsString('<html lang="cs">', $html);
        self::assertStringContainsString('<title>&lt;b&gt;Ahoj&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>Ahoj</b>', $html);
        self::assertMatchesRegularExpression('/<main[^>]*>.*Zatím tu nejsou žádné publikované články\..*<\/main>/s', $html);
    }

    #[DataProvider('invalidTemplateProvider')]
    public function test_invalid_or_missing_template_name_throws_template_not_found(string $name): void
    {
        $this->expectException(TemplateNotFound::class);

        $this->real()->render($name);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTemplateProvider(): iterable
    {
        yield 'path traversal' => ['../composer'];
        yield 'missing' => ['nic'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'extension' => ['home.php'];
    }

    public function test_exception_in_template_does_not_leak_output_buffer(): void
    {
        $level = ob_get_level();

        try {
            $this->fixtures()->render('throwing', [], null);
            self::fail('Očekávána výjimka ze šablony.');
        } catch (\RuntimeException $exception) {
            self::assertSame('šablona selhala', $exception->getMessage());
        }

        self::assertSame($level, ob_get_level());
    }
}
