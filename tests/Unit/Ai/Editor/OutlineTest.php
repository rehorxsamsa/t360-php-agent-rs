<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Editor;

use App\Ai\Editor\Outline;
use App\Tests\Unit\Support\AiFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Plán 010, AC 6 a §2: osnova článku – schéma, pravidla v PHP a textové formy pro prompt i zobrazení. */
final class OutlineTest extends TestCase
{
    use EditorSchemaAssertions;

    /**
     * @param list<mixed> $sections prázdné = sekce z fixtury
     * @return array<string, mixed>
     */
    private static function outline(string $title = 'Titulek osnovy', string $angle = 'Úhel pohledu na téma', array $sections = []): array
    {
        return AiFixtures::editorOutline(['title' => $title, 'angle' => $angle] + ($sections === [] ? [] : ['sections' => $sections]));
    }

    /** @return list<array{heading: string, points: list<string>}> */
    private static function sections(int $count, int $points = 2): array
    {
        $sections = [];
        for ($i = 1; $i <= $count; $i++) {
            $sections[] = [
                'heading' => 'Sekce ' . $i,
                'points' => array_map(static fn(int $p): string => sprintf('Bod %d.%d', $i, $p), range(1, $points)),
            ];
        }

        return $sections;
    }

    public function test_schema_is_strict_object_with_title_angle_and_sections(): void
    {
        $schema = Outline::schema();

        self::assertSame('object', $schema['type'] ?? null);
        self::assertSame(['angle', 'sections', 'title'], self::sortedKeys($schema['properties'] ?? []));
        self::assertSame('array', self::at($schema, 'properties', 'sections')['type'] ?? null);
        self::assertSame(['heading', 'points'], self::sortedKeys(self::at($schema, 'properties', 'sections', 'items', 'properties')));
        self::assertSame('array', self::at($schema, 'properties', 'sections', 'items', 'properties', 'points')['type'] ?? null);
        self::assertSame('string', self::at($schema, 'properties', 'sections', 'items', 'properties', 'points', 'items')['type'] ?? null);
        self::assertStrictSchema($schema);
    }

    public function test_fixture_outline_is_valid(): void
    {
        self::assertSame([], Outline::errors(AiFixtures::editorOutline()));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function validBoundaries(): iterable
    {
        yield 'title 10, angle 10, 3 sections' => [self::outline(str_repeat('t', 10), str_repeat('u', 10), self::sections(3, 1))];
        yield 'title 200, angle 300, 6 sections with 4 points' => [self::outline(str_repeat('ř', 200), str_repeat('ů', 300), self::sections(6, 4))];
        yield 'heading 3 and 100 chars, point 3 and 200 chars' => [self::outline(sections: [
            ['heading' => 'Abc', 'points' => ['xyz']],
            ['heading' => str_repeat('h', 100), 'points' => [str_repeat('p', 200)]],
            ['heading' => 'Třetí', 'points' => ['Bod']],
        ])];
        yield 'extra keys are ignored' => [self::outline() + ['status' => 'published', 'publish' => true]];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('validBoundaries')]
    public function test_valid_boundaries_have_no_errors(array $data): void
    {
        self::assertSame([], Outline::errors($data));
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function invalidOutlines(): iterable
    {
        yield 'title 9' => [self::outline(str_repeat('t', 9)), 'title'];
        yield 'title 201' => [self::outline(str_repeat('t', 201)), 'title'];
        yield 'title only spaces' => [self::outline('            '), 'title'];
        yield 'angle 9' => [self::outline(angle: str_repeat('u', 9)), 'angle'];
        yield 'angle 301' => [self::outline(angle: str_repeat('u', 301)), 'angle'];
        yield '2 sections' => [self::outline(sections: self::sections(2)), 'sections'];
        yield '7 sections' => [self::outline(sections: self::sections(7)), 'sections'];
        yield 'heading 2' => [self::outline(sections: [['heading' => 'Ab', 'points' => ['Bod']], ...self::sections(2)]), 'heading'];
        yield 'heading 101' => [self::outline(sections: [['heading' => str_repeat('h', 101), 'points' => ['Bod']], ...self::sections(2)]), 'heading'];
        yield 'no points' => [self::outline(sections: [['heading' => 'Sekce', 'points' => []], ...self::sections(2)]), 'points'];
        yield '5 points' => [self::outline(sections: [...self::sections(2), ...self::sections(1, 5)]), 'points'];
        yield 'point 2 chars' => [self::outline(sections: [['heading' => 'Sekce', 'points' => ['ab']], ...self::sections(2)]), 'points'];
        yield 'point 201 chars' => [self::outline(sections: [['heading' => 'Sekce', 'points' => [str_repeat('p', 201)]], ...self::sections(2)]), 'points'];
        yield 'point is not string' => [self::outline(sections: [['heading' => 'Sekce', 'points' => [42]], ...self::sections(2)]), 'points'];
        yield 'sections is object' => [['title' => 'Titulek osnovy', 'angle' => 'Úhel pohledu', 'sections' => ['a' => 1]], 'sections'];
        yield 'section is string' => [self::outline(sections: ['Sekce', ...self::sections(2)]), 'sections'];
        yield 'missing everything' => [[], 'title'];
    }

    /** @param array<mixed> $data */
    #[DataProvider('invalidOutlines')]
    public function test_invalid_outline_has_czech_error_for_field(array $data, string $field): void
    {
        $errors = Outline::errors($data);

        self::assertNotSame([], $errors);
        self::assertErrorAbout($field, $errors);
    }

    public function test_from_data_trims_strings(): void
    {
        $outline = Outline::fromData(self::outline('  Titulek osnovy  ', "\tÚhel pohledu na téma\n", [
            ['heading' => '  Proč  ', 'points' => ['  Bod jedna ']],
            ['heading' => 'Jak', 'points' => ['Bod dva']],
            ['heading' => 'Co dál', 'points' => ['Bod tři']],
        ]));

        self::assertSame('Titulek osnovy', $outline->title);
        self::assertSame('Úhel pohledu na téma', $outline->angle);
        self::assertSame('Proč', $outline->sections[0]['heading']);
        self::assertSame(['Bod jedna'], $outline->sections[0]['points']);
        self::assertCount(3, $outline->sections);
    }

    public function test_prompt_text_lists_title_angle_and_sections_as_markdown(): void
    {
        $outline = Outline::fromData(AiFixtures::editorOutline());

        $text = $outline->toPromptText();

        self::assertStringStartsWith(
            "Titulek: Docker v malé redakci\nÚhel: Praktický pohled na kontejnery pro malý redakční tým.\n\n"
            . "## Proč Docker\n- Stejné prostředí všude\n- Rychlý start nových lidí",
            $text,
        );
        self::assertStringContainsString("## Jak začít\n- Soubor compose.yaml v repozitáři", $text);
        self::assertStringContainsString("## Na co si dát pozor\n- Zálohy dat ve volumes\n- Pravidelná aktualizace obrazů", $text);
        self::assertSame(3, preg_match_all('~^## ~mu', $text));
    }

    public function test_display_text_has_numbered_sections_with_points_joined_by_semicolon(): void
    {
        $outline = new Outline('Docker v malé redakci', 'Praktický pohled.', [
            ['heading' => 'Proč Docker', 'points' => ['Stejné prostředí', 'Rychlý start']],
            ['heading' => 'Jak začít', 'points' => ['Compose']],
            ['heading' => 'Pozor', 'points' => ['Zálohy', 'Aktualizace']],
        ]);

        self::assertSame(
            "Docker v malé redakci\nPraktický pohled.\n1. Proč Docker – Stejné prostředí; Rychlý start\n2. Jak začít – Compose\n3. Pozor – Zálohy; Aktualizace",
            $outline->toDisplayText(),
        );
    }

    public function test_outline_is_final_readonly(): void
    {
        $class = new \ReflectionClass(Outline::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }
}
