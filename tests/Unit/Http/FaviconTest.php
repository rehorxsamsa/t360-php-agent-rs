<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Kernel;
use App\Http\Request;
use App\Tests\Unit\Support\ArraySession;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 007, AC 18: ikona webu jako statické SVG odkázané z layoutu (prohlížeč pak nežádá /favicon.ico). */
final class FaviconTest extends TestCase
{
    private const string ICON_LINK = '<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">';
    private const string ICON_FILE = __DIR__ . '/../../../public/assets/favicon.svg';

    public function test_layout_template_links_svg_icon(): void
    {
        $layout = (string) file_get_contents(__DIR__ . '/../../../templates/layout.php');

        self::assertStringContainsString(self::ICON_LINK, $layout);
    }

    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        yield 'homepage' => ['/'];
        yield 'login' => ['/admin/prihlaseni'];
        yield 'not found page' => ['/neexistuje'];
    }

    #[DataProvider('pages')]
    public function test_rendered_pages_link_svg_icon(string $path): void
    {
        $container = TestContainer::create(new ArraySession(), new InMemoryUserRepository(), new InMemoryAuditLogRepository());

        $response = $container->get(Kernel::class)->handle(new Request('GET', $path, clientIp: '172.18.0.1'));

        self::assertStringContainsString(self::ICON_LINK, $response->body);
    }

    public function test_icon_is_static_svg_without_scripts_or_external_references(): void
    {
        self::assertFileExists(self::ICON_FILE);
        $svg = (string) file_get_contents(self::ICON_FILE);

        self::assertMatchesRegularExpression('~<svg\b[^>]*\sviewBox="[^"]+"~u', $svg);
        self::assertMatchesRegularExpression('~<svg\b[^>]*\sxmlns="http://www\.w3\.org/2000/svg"~u', $svg);
        self::assertDoesNotMatchRegularExpression('~<script\b~iu', $svg);
        self::assertDoesNotMatchRegularExpression('~\son[a-z]+\s*=~iu', $svg, 'Žádné obsluhy událostí.');
        self::assertDoesNotMatchRegularExpression('~href\s*=~iu', $svg, 'Žádné odkazy (href, xlink:href).');
        self::assertDoesNotMatchRegularExpression('~<(?:foreignObject|image|use|iframe)\b~iu', $svg);
        self::assertDoesNotMatchRegularExpression('~url\s*\(~iu', $svg);
        $withoutNamespace = str_replace('xmlns="http://www.w3.org/2000/svg"', '', $svg);
        self::assertDoesNotMatchRegularExpression('~(?:https?:)?//~iu', $withoutNamespace, 'Žádné externí adresy.');
    }
}
