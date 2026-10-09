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
    private const string RESERVED_TAGS = 'clanek|titulek|perex|text|rubriky|existujici_stitky|tema|osnova|koncept|nalezy';

    /**
     * Libovolný nedůvěryhodný text (téma, výstup modelu z předchozího kroku) v pojmenované značce.
     * Vložené vyhrazené značky se zneškodní, takže text nemůže značku uzavřít ani podvrhnout další.
     *
     * @throws \InvalidArgumentException značka není vyhrazená
     */
    public static function block(string $tag, string $text): string
    {
        if (preg_match('~^(?:' . self::RESERVED_TAGS . ')$~D', $tag) !== 1) {
            throw new \InvalidArgumentException(sprintf('Značka „%s“ není vyhrazená.', $tag));
        }

        return '<' . $tag . ">\n" . self::neutralize($text) . "\n</" . $tag . '>';
    }

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

    /**
     * Zneškodní zápis `<` před názvem vyhrazené značky (i s `/`, mezerami a v libovolné velikosti písmen).
     *
     * Nejdřív odstraní neviditelné formátovací znaky (`\p{Cf}`: zero-width space/joiner, BOM, soft hyphen,
     * řídicí znaky směru textu), kterými by šlo značku „rozbít“ (`<` + U+200B + `/tema>`) a přitom ji
     * model přečetl jako celou. Potom se jako `<` berou i plnošířkové `＜`, malé `﹤` a entity `&lt;`,
     * `&#60;`, `&#x3c;` (s nulami navíc, bez středníku, v libovolné velikosti písmen). Všechny se nahradí `‹`.
     * Ostatní text zůstává beze změny.
     */
    public static function neutralize(string $text): string
    {
        $text = preg_replace('~\p{Cf}+~u', '', $text);
        if ($text === null) {
            return '';
        }

        $opening = '(?:<|\x{FF1C}|\x{FE64}|&lt;?|&#0*60;?|&#x0*3c;?)';

        return preg_replace(
            '~' . $opening . '(?=\s*/?\s*(?:' . self::RESERVED_TAGS . ')\b)~iu',
            '‹',
            $text,
        ) ?? '';
    }
}
