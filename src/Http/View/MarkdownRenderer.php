<?php

declare(strict_types=1);

namespace App\Http\View;

/**
 * Bezpečný převod pevné podmnožiny Markdownu na HTML (ADR-0005).
 *
 * Princip „nejdřív escapovat, pak značkovat“: každý kus textu projde e() dřív, než se
 * k němu přidá značka. Značky vznikají jen zde v kódu (p, h2–h4, ul, ol, li, blockquote,
 * pre, code, strong, em, a) a jediný atribut je href, jehož URL prošla allowlistem.
 * Výstup je proto bezpečné vypsat bez e().
 *
 * Podporováno: odstavce, nadpisy # až ######, odrážky, číslované seznamy (bez vnoření),
 * citace, blok kódu, vložený kód, tučné, kurzíva, odkazy. Syrové HTML se vždy escapuje.
 */
final readonly class MarkdownRenderer
{
    /** Zástupné znaky ze soukromé oblasti Unicode; ze vstupu se předem odstraní, nelze je tedy podvrhnout. */
    private const string TOKEN_START = "\u{E000}";
    private const string TOKEN_END = "\u{E001}";

    /** Horní mez délky URL; ve vzoru odkazu je URL povoleno s jednou úrovní závorek, např. `/a_(b)`. */
    private const int MAX_URL_LENGTH = 2048;

    private const string LINK_PATTERN = '/\[([^\[\]]*+)\]\(((?:[^()]++|\([^()]*+\))*+)\)/';
    private const string STRONG_PATTERN = '/\*\*(?![ \t\n])([^*]++)(?<![ \t\n])\*\*/';
    private const string EMPHASIS_PATTERN = '/\*(?![ \t\n])([^*]++)(?<![ \t\n])\*/';

    public function toHtml(string $markdown): string
    {
        try {
            $text = str_replace([self::TOKEN_START, self::TOKEN_END], '', mb_scrub($markdown, 'UTF-8'));
            $text = str_replace(["\r\n", "\r"], "\n", $text);

            return $this->renderBlocks(explode("\n", $text));
        } catch (\RuntimeException) {
            // Selhání PCRE (např. limit zpětného sledování): celý text jako escapovaný odstavec.
            return '<p>' . e($markdown) . '</p>';
        }
    }

    /** @param list<string> $lines */
    private function renderBlocks(array $lines): string
    {
        $blocks = [];
        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
                continue;
            }

            if (str_starts_with($line, '```')) {
                $code = [];
                $i++;
                while ($i < $count && rtrim($lines[$i]) !== '```') {
                    $code[] = $lines[$i];
                    $i++;
                }
                $i++; // uzavírací ohrada (nebo konec vstupu)
                $blocks[] = '<pre><code>' . e(implode("\n", $code)) . '</code></pre>';
                continue;
            }

            $heading = $this->heading($line);
            if ($heading !== null) {
                $blocks[] = $heading;
                $i++;
                continue;
            }

            if ($this->isQuoteLine($line)) {
                $quote = [];
                while ($i < $count && $this->isQuoteLine($lines[$i])) {
                    $quote[] = trim(substr($lines[$i], 1));
                    $i++;
                }
                $blocks[] = '<blockquote><p>' . $this->inline(implode("\n", $quote)) . '</p></blockquote>';
                continue;
            }

            if ($this->bulletItem($line) !== null) {
                $blocks[] = $this->renderList('ul', $lines, $i, $count, $this->bulletItem(...));
                continue;
            }

            if ($this->numberedItem($line) !== null) {
                $blocks[] = $this->renderList('ol', $lines, $i, $count, $this->numberedItem(...));
                continue;
            }

            $paragraph = [];
            while ($i < $count && trim($lines[$i]) !== '' && ($paragraph === [] || !$this->startsBlock($lines[$i]))) {
                $paragraph[] = trim($lines[$i]);
                $i++;
            }
            $blocks[] = '<p>' . $this->inline(implode("\n", $paragraph)) . '</p>';
        }

        return implode("\n", $blocks);
    }

    /**
     * @param list<string> $lines
     * @param callable(string): ?string $item vrací obsah položky, nebo null, když řádek není položka
     */
    private function renderList(string $tag, array $lines, int &$i, int $count, callable $item): string
    {
        $items = [];
        while ($i < $count) {
            $content = $item($lines[$i]);
            if ($content === null) {
                break;
            }
            $items[] = '<li>' . $this->inline($content) . '</li>';
            $i++;
        }

        return '<' . $tag . '>' . implode('', $items) . '</' . $tag . '>';
    }

    private function heading(string $line): ?string
    {
        if (preg_match('/^(#{1,6})[ \t]+(.+)$/D', $line, $match) !== 1) {
            return null;
        }

        $text = rtrim($match[2]);
        if (str_ends_with($text, '#')) {
            $withoutHashes = rtrim($text, '#');
            if ($withoutHashes === '' || str_ends_with($withoutHashes, ' ') || str_ends_with($withoutHashes, "\t")) {
                $text = rtrim($withoutHashes);
            }
        }

        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $level = strlen($match[1]);
        $tag = match (true) {
            $level <= 2 => 'h2',
            $level === 3 => 'h3',
            default => 'h4',
        };

        return '<' . $tag . '>' . $this->inline($text) . '</' . $tag . '>';
    }

    private function isQuoteLine(string $line): bool
    {
        return str_starts_with($line, '>');
    }

    private function bulletItem(string $line): ?string
    {
        return preg_match('/^[-*+][ \t]+(.*)$/D', $line, $match) === 1 ? trim($match[1]) : null;
    }

    private function numberedItem(string $line): ?string
    {
        return preg_match('/^\d{1,9}[.)][ \t]+(.*)$/D', $line, $match) === 1 ? trim($match[1]) : null;
    }

    /** Řádky, které smějí přerušit odstavec (číslovaný seznam ne – „2026. rok“ je běžná věta). */
    private function startsBlock(string $line): bool
    {
        return str_starts_with($line, '```')
            || $this->isQuoteLine($line)
            || $this->bulletItem($line) !== null
            || preg_match('/^#{1,6}[ \t]+\S/', $line) === 1;
    }

    /** Vložený text: kód → escapování → odkazy → tučné → kurzíva → návrat zástupných tokenů. */
    private function inline(string $text): string
    {
        /** @var list<string> $tokens */
        $tokens = [];
        $escaped = e($this->extractCodeSpans($text, $tokens));

        $escaped = $this->pregCallback(
            self::LINK_PATTERN,
            function (array $match) use (&$tokens): string {
                $label = $this->restoreTokens($this->emphasis($match[1]), $tokens);
                $url = $this->safeUrl($match[2]);
                if ($url === null) {
                    return $label;
                }

                $tokens[] = '<a href="' . e_attr($url) . '">' . ($label === '' ? e($url) : $label) . '</a>';

                return $this->token(count($tokens) - 1);
            },
            $escaped,
        );

        return $this->restoreTokens($this->emphasis($escaped), $tokens);
    }

    /**
     * Nahradí `kód` tokeny; obsah kódu se escapuje a uloží do $tokens (žádné další zpracování).
     *
     * @param list<string> $tokens
     */
    private function extractCodeSpans(string $text, array &$tokens): string
    {
        $result = '';
        $position = 0;
        $length = strlen($text);

        while ($position < $length) {
            $start = strpos($text, '`', $position);
            if ($start === false) {
                break;
            }

            $end = strpos($text, '`', $start + 1);
            if ($end === false) {
                break;
            }

            $result .= substr($text, $position, $start - $position);
            if ($end === $start + 1) {
                $result .= '``'; // prázdný kód není kód
            } else {
                $tokens[] = '<code>' . e(substr($text, $start + 1, $end - $start - 1)) . '</code>';
                $result .= $this->token(count($tokens) - 1);
            }
            $position = $end + 1;
        }

        return $result . substr($text, $position);
    }

    private function token(int $index): string
    {
        return self::TOKEN_START . $index . self::TOKEN_END;
    }

    /** @param list<string> $tokens */
    private function restoreTokens(string $text, array $tokens): string
    {
        return $this->pregCallback(
            '/' . self::TOKEN_START . '(\d+)' . self::TOKEN_END . '/u',
            static fn(array $match): string => $tokens[(int) $match[1]] ?? '',
            $text,
        );
    }

    private function emphasis(string $escaped): string
    {
        $strong = $this->pregReplace(self::STRONG_PATTERN, '<strong>$1</strong>', $escaped);

        return $this->pregReplace(self::EMPHASIS_PATTERN, '<em>$1</em>', $strong);
    }

    /**
     * URL z (už escapovaného) textu → dekódovat právě jednou → allowlist. Vrací null, když neprojde.
     * Allowlist (ne denylist): prohlížeče ze schématu odstraňují tabulátory a nové řádky,
     * proto se kontroluje celá hodnota a cokoli neznámého se zahodí.
     */
    private function safeUrl(string $escapedUrl): ?string
    {
        if (strlen($escapedUrl) > self::MAX_URL_LENGTH) {
            return null;
        }

        $url = trim(html_entity_decode($escapedUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $allowed = preg_match('~^(?:https?://|mailto:)[^\s<>"\'\x00-\x1f\x7f]+\z~i', $url) === 1
            || preg_match('~^/(?![/\\\\])[^\s<>"\'\x00-\x1f\x7f]*\z~', $url) === 1
            || preg_match('~^#[A-Za-z0-9_-]*\z~', $url) === 1;

        return $allowed ? $url : null;
    }

    private function pregReplace(string $pattern, string $replacement, string $subject): string
    {
        $result = preg_replace($pattern, $replacement, $subject);
        if ($result === null) {
            throw new \RuntimeException('Zpracování Markdownu selhalo.');
        }

        return $result;
    }

    /** @param callable(array<string>): string $callback */
    private function pregCallback(string $pattern, callable $callback, string $subject): string
    {
        $result = preg_replace_callback($pattern, $callback, $subject);
        if ($result === null) {
            throw new \RuntimeException('Zpracování Markdownu selhalo.');
        }

        return $result;
    }
}
