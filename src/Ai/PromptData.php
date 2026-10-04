<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Examples\ArticleSnapshot;

/**
 * Sestavení datové části promptu. Nedůvěryhodný text článku se vždy vkládá uvnitř značek
 * `<clanek>` a vložené značky se zneškodní (`<` → `‹`), aby text nemohl značku uzavřít
 * a „vystoupit“ z dat (obrana proti prompt injection, LLM01).
 */
final class PromptData
{
    /** Značky, které si prompt vyhrazuje. */
    private const string RESERVED_TAGS = 'clanek|titulek|perex|text|rubriky|existujici_stitky';

    public static function article(ArticleSnapshot $article): string
    {
        return "<clanek>\n"
            . '<titulek>' . self::neutralize($article->title) . "</titulek>\n"
            . '<perex>' . self::neutralize($article->excerpt) . "</perex>\n"
            . '<text>' . self::neutralize($article->body) . "</text>\n"
            . '</clanek>';
    }

    /**
     * Seznam hodnot (např. názvy rubrik) v pojmenované značce, jedna položka na řádek.
     *
     * @param list<string> $items
     */
    public static function list(string $tag, array $items): string
    {
        $lines = array_map(
            static fn(string $item): string => '- ' . str_replace(["\r", "\n"], ' ', self::neutralize($item)),
            $items,
        );

        return '<' . $tag . ">\n" . implode("\n", $lines) . "\n</" . $tag . '>';
    }

    /** Nahradí `<` před názvem vyhrazené značky (i s `/`, mezerami a v libovolné velikosti písmen). */
    public static function neutralize(string $text): string
    {
        return preg_replace('~<(?=\s*/?\s*(?:' . self::RESERVED_TAGS . ')\b)~iu', '‹', $text) ?? '';
    }
}
