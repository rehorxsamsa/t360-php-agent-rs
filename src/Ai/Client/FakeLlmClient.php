<?php

declare(strict_types=1);

namespace App\Ai\Client;

use App\Ai\Examples\WritingAction;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;
use App\Ai\StreamingLlmClient;
use App\Ai\ToolCall;
use App\Domain\Ai\TokenUsage;

/**
 * Falešný klient bez sítě a bez API klíče: odpověď je deterministická funkce požadavku
 * (podle `exampleId` a textu uvnitř značek `<titulek>` a `<text>`). Tokeny se odhadují
 * jako znaky / 4, takže i bez klíče vidíte orientační cenu. Nejde o skutečný model:
 * např. „nález prompt injection“ v příkladu 04 je naskriptovaný (hledá slovo „ignoruj“).
 *
 * Umí i streamování (příklad 06: hotový text se rozdělí na přírůstky po 1–3 slovech se zpožděním
 * `$streamDelayMs`) a tool use scénář příkladu 07 (hledej → načti → odpověz, viz `toolScenario()`).
 */
final readonly class FakeLlmClient implements StreamingLlmClient
{
    private const int CHARS_PER_TOKEN = 4;

    /** Počet slov v po sobě jdoucích přírůstcích proudu (střídá se). */
    private const array DELTA_WORDS = [2, 1, 3];

    /** Slova, která se při hledání v příkladu 07 přeskakují (název aplikace, nic by nerozlišila). */
    private const string SEARCH_STOP_WORD_PREFIX = 'redak';

    /** @param int $streamDelayMs pauza mezi přírůstky proudu v ms (v testech 0, v dev kontejneru 60) */
    public function __construct(private int $streamDelayMs = 0) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        $inputChars = mb_strlen($request->system);
        foreach ($request->messages as $message) {
            $inputChars += $this->contentLength($message['content']);
        }

        if ($request->exampleId === '07' && $request->tools !== null) {
            return $this->toolScenario($request, $inputChars);
        }

        $text = $this->answer($request);

        return new LlmResponse(
            $text,
            $request->model,
            'end_turn',
            new TokenUsage($this->estimateTokens($inputChars), $this->estimateTokens(mb_strlen($text))),
            'fake',
        );
    }

    public function stream(LlmRequest $request, callable $onText): LlmResponse
    {
        if ($request->tools !== null) {
            throw new \LogicException('Streamování s nástroji není podporované.');
        }

        $response = $this->complete($request);

        $sent = '';
        foreach ($this->deltas($response->text) as $index => $delta) {
            if ($index > 0 && $this->streamDelayMs > 0) {
                usleep($this->streamDelayMs * 1000);
            }

            $sent .= $delta;
            if ($onText($delta) === false) {
                // Přerušený proud: výstup se odhadne jen z toho, co už bylo odesláno.
                return new LlmResponse(
                    $sent,
                    $response->model,
                    'aborted',
                    new TokenUsage($response->usage->input, $this->estimateTokens(mb_strlen($sent))),
                    'fake',
                );
            }
        }

        return $response;
    }

    /**
     * Rozdělí text na přírůstky po 1–3 slovech (vždy na hranici slova, mezery zůstávají u slova);
     * spojením přírůstků vznikne původní text.
     *
     * @return list<string>
     */
    private function deltas(string $text): array
    {
        preg_match_all('/\s*\S+\s*/u', $text, $matches);

        $deltas = [];
        $words = $matches[0];
        for ($position = 0, $step = 0; $position < count($words); $step++) {
            $size = self::DELTA_WORDS[$step % count(self::DELTA_WORDS)];
            $deltas[] = implode('', array_slice($words, $position, $size));
            $position += $size;
        }

        return $deltas;
    }

    /** @param string|list<array<string, mixed>> $content */
    private function contentLength(string|array $content): int
    {
        if (is_string($content)) {
            return mb_strlen($content);
        }

        return mb_strlen(json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
    }

    private function estimateTokens(int $chars): int
    {
        return intdiv($chars + self::CHARS_PER_TOKEN - 1, self::CHARS_PER_TOKEN);
    }

    private function answer(LlmRequest $request): string
    {
        $data = is_string($request->messages[0]['content']) ? $request->messages[0]['content'] : '';
        $title = $this->between($data, 'titulek');
        $excerpt = $this->between($data, 'perex');
        $body = $this->between($data, 'text');

        return match ($request->exampleId) {
            '01' => $this->excerpt($title, $body),
            '02' => $this->json($this->seo($title, $body)),
            '03' => $this->json($this->classification($request, $title, $body)),
            '04' => $this->json($this->review($body)),
            '05' => $this->json(['title' => '[EN] ' . $title, 'excerpt' => $excerpt, 'body' => $body]),
            '06' => $this->writing($data, $body),
            default => sprintf('Falešná odpověď klienta (příklad %s).', $request->exampleId),
        };
    }

    /** Asistent psaní: podle akce v zadání (`Úkol: …`) pokračuje, zkracuje nebo zjednodušuje text ze značky `<text>`. */
    private function writing(string $data, string $text): string
    {
        $plain = $this->plainText($text);

        if (str_contains($data, 'Úkol: ' . WritingAction::Shorten->instruction())) {
            return $this->firstSentences($plain, max(20, intdiv(mb_strlen($plain), 2)));
        }

        if (str_contains($data, 'Úkol: ' . WritingAction::Simplify->instruction())) {
            return 'Jednodušeji řečeno: ' . $this->firstSentences($plain, 200);
        }

        $words = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tail = implode(' ', array_slice($words, -4));

        return 'To ale není všechno. Souvislosti, které se na první pohled snadno přehlédnou, často rozhodují o výsledku celého projektu. '
            . 'Proto má smysl věnovat pozornost i detailům a ověřit si, že všichni chápou zadání stejně. '
            . 'Toto pokračování vytvořil falešný klient a navazuje na slova „' . $tail . '“.';
    }

    /**
     * Scénář příkladu 07 podle posledního kroku konverzace (deterministický, bez sítě):
     * 1) otázka → `tool_use hledej_clanky`, 2) výsledky hledání → `tool_use nacti_clanek` nebo „nic jsem nenašel“,
     * 3) načtený článek → odpověď se zdrojem.
     */
    private function toolScenario(LlmRequest $request, int $inputChars): LlmResponse
    {
        $last = $request->messages[count($request->messages) - 1]['content'];
        $results = is_array($last) ? $this->toolResults($last) : [];

        if (isset($results['fake_read'])) {
            $article = $results['fake_read'];
            if ($article['isError']) {
                $text = 'Článek se nepodařilo načíst.';
            } else {
                $data = $article['data'];
                $title = is_string($data['title'] ?? null) ? $data['title'] : '';
                $url = is_string($data['url'] ?? null) ? $data['url'] : '';
                $excerpt = is_string($data['excerpt'] ?? null) ? trim($data['excerpt']) : '';
                $body = is_string($data['body'] ?? null) ? $data['body'] : '';
                $summary = $this->firstSentence($excerpt !== '' ? $excerpt : $this->plainText($body), 300);
                $text = sprintf('Podle článku „%s“ (%s): %s', $title, $url, $summary);
            }

            return $this->finalToolResponse($request, $inputChars, $text, 'end_turn');
        }

        if (isset($results['fake_search'])) {
            $search = $results['fake_search'];
            $found = $search['isError'] ? '' : $this->firstResultSlug($search['data']);
            if ($found === '') {
                return $this->finalToolResponse($request, $inputChars, 'V publikovaných článcích jsem k tomu nic nenašel.', 'end_turn');
            }

            return $this->toolUseResponse($request, $inputChars, 'Čtu nejvhodnější článek.', new ToolCall('fake_read', 'nacti_clanek', ['slug' => $found]));
        }

        $question = is_string($request->messages[0]['content']) ? $request->messages[0]['content'] : '';

        return $this->toolUseResponse($request, $inputChars, 'Hledám v publikovaných článcích.', new ToolCall('fake_search', 'hledej_clanky', ['query' => $this->searchQuery($question)]));
    }

    /** @param array<mixed> $data dekódovaný výsledek nástroje hledej_clanky */
    private function firstResultSlug(array $data): string
    {
        $results = $data['results'] ?? null;
        $first = is_array($results) ? ($results[0] ?? null) : null;
        $slug = is_array($first) ? ($first['slug'] ?? null) : null;

        return is_string($slug) ? $slug : '';
    }

    /**
     * Hledaný výraz: prvních 5 znaků nejdelšího slova otázky (aspoň 4 písmena, při shodě délky první),
     * malými písmeny. Slova začínající „redak“ se přeskakují – v „Zeptej se redakce“ by hledání
     * po názvu aplikace nenašlo nic užitečného.
     */
    private function searchQuery(string $question): string
    {
        preg_match_all('/\p{L}{4,}/u', $question, $matches);

        $best = '';
        foreach ($matches[0] as $word) {
            if (str_starts_with(mb_strtolower($word), self::SEARCH_STOP_WORD_PREFIX)) {
                continue;
            }
            if (mb_strlen($word) > mb_strlen($best)) {
                $best = $word;
            }
        }

        return mb_strtolower(mb_substr($best !== '' ? $best : trim($question), 0, 5));
    }

    /**
     * Výsledky nástrojů z poslední zprávy `user`: id volání → dekódovaný obsah (JSON) a příznak chyby.
     *
     * @param list<array<string, mixed>> $blocks
     * @return array<string, array{isError: bool, data: array<mixed>}>
     */
    private function toolResults(array $blocks): array
    {
        $results = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) !== 'tool_result' || !is_string($block['tool_use_id'] ?? null)) {
                continue;
            }

            $decoded = is_string($block['content'] ?? null) ? json_decode($block['content'], true) : null;
            $results[$block['tool_use_id']] = [
                'isError' => ($block['is_error'] ?? false) === true,
                'data' => is_array($decoded) ? $decoded : [],
            ];
        }

        return $results;
    }

    private function finalToolResponse(LlmRequest $request, int $inputChars, string $text, string $stopReason): LlmResponse
    {
        $content = [['type' => 'text', 'text' => $text]];

        return new LlmResponse(
            $text,
            $request->model,
            $stopReason,
            new TokenUsage($this->estimateTokens($inputChars), $this->estimateTokens($this->contentLength($content))),
            'fake',
            content: $content,
        );
    }

    private function toolUseResponse(LlmRequest $request, int $inputChars, string $text, ToolCall $call): LlmResponse
    {
        $content = [
            ['type' => 'text', 'text' => $text],
            ['type' => 'tool_use', 'id' => $call->id, 'name' => $call->name, 'input' => $call->input],
        ];

        return new LlmResponse(
            $text,
            $request->model,
            'tool_use',
            new TokenUsage($this->estimateTokens($inputChars), $this->estimateTokens($this->contentLength($content))),
            'fake',
            content: $content,
            toolCalls: [$call],
        );
    }

    private function between(string $data, string $tag): string
    {
        if (preg_match('~<' . $tag . '>(.*?)</' . $tag . '>~su', $data, $matches) !== 1) {
            return '';
        }

        return trim($matches[1]);
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private function excerpt(string $title, string $body): string
    {
        return $this->firstSentences($this->plainText($body), 250) ?: $title;
    }

    /** @return array<string, mixed> */
    private function seo(string $title, string $body): array
    {
        $plain = $this->plainText($body);

        return [
            'title' => $this->cut($title !== '' ? $title : 'Ukázkový článek', 60),
            'meta_description' => $this->firstSentences($plain, 155) ?: 'Ukázkový popis článku pro vyhledávače.',
            'keywords' => $this->keywords($title . ' ' . $plain, 5, ['redakce', 'článek', 'ukázka']),
        ];
    }

    /** @return array<string, mixed> */
    private function classification(LlmRequest $request, string $title, string $body): array
    {
        $category = '';
        $properties = $request->jsonSchema['properties'] ?? null;
        if (is_array($properties) && is_array($properties['category'] ?? null)) {
            $enum = $properties['category']['enum'] ?? null;
            if (is_array($enum) && is_string($enum[0] ?? null)) {
                $category = $enum[0];
            }
        }

        return [
            'category' => $category,
            'tags' => $this->keywords($title . ' ' . $this->plainText($body), 4, ['redakce', 'novinky', 'ukázka']),
        ];
    }

    /** @return array<string, mixed> */
    private function review(string $body): array
    {
        $findings = [];

        if (preg_match('/[^.!?\n]*ignoruj[^.!?\n]*[.!?]?/iu', $body, $matches) === 1) {
            $findings[] = [
                'type' => 'prompt_injection',
                'severity' => 'high',
                'quote' => $this->cut(trim($matches[0]), 200),
                'note' => 'Text se pokouší zadat modelu pokyn. Byl zpracován jako data a nebyl vykonán.',
            ];
        }

        if (preg_match('/\+?\d{3}(?:[ \x{00A0}]?\d{3}){2,3}/u', $body, $matches) === 1) {
            $findings[] = [
                'type' => 'personal_data',
                'severity' => 'medium',
                'quote' => $this->cut($matches[0], 200),
                'note' => 'Telefonní číslo je osobní údaj. Před publikací ověřte souhlas a nutnost uvedení.',
            ];
        }

        return [
            'summary' => $findings === []
                ? 'Text je srozumitelný a neutrální, žádná rizika nenalezena.'
                : sprintf('Nalezená rizika: %d. Článek by se neměl publikovat bez úpravy.', count($findings)),
            'findings' => $findings,
        ];
    }

    /** Odstraní z Markdownu nadpisy, bloky kódu a značky, aby zbyl souvislý text. */
    private function plainText(string $markdown): string
    {
        $lines = [];
        $inCode = false;
        foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $inCode = !$inCode;
                continue;
            }

            if ($inCode || preg_match('/^\s{0,3}#{1,6}\s/', $line) === 1) {
                continue;
            }

            $line = preg_replace('/^\s*(?:[-*+]|\d+\.)\s+/u', '', $line) ?? $line;
            $line = str_replace(['*', '_', '`'], '', $line);
            $lines[] = trim($line);
        }

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $lines)) ?? '');
    }

    /** Věty od začátku, dokud se vejdou do `$maxChars`; první věta se případně zkrátí. */
    private function firstSentences(string $text, int $maxChars): string
    {
        $result = '';
        foreach (preg_split('/(?<=[.!?])\s+/u', $text) ?: [] as $sentence) {
            $candidate = $result === '' ? $sentence : $result . ' ' . $sentence;
            if (mb_strlen($candidate) > $maxChars) {
                break;
            }
            $result = $candidate;
        }

        return $result !== '' ? $result : $this->cut($text, $maxChars);
    }

    /** Pouze první věta; je-li delší než `$maxChars`, zkrátí se. */
    private function firstSentence(string $text, int $maxChars): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text), 2) ?: [];

        return $this->cut($sentences[0] ?? '', $maxChars);
    }

    private function cut(string $text, int $maxChars): string
    {
        return mb_strlen($text) <= $maxChars ? $text : rtrim(mb_substr($text, 0, $maxChars - 1)) . '…';
    }

    /**
     * Nejčastější delší slova (bez ohledu na velikost písmen) jako klíčová slova; chybí-li, doplní `$fallback`.
     *
     * @param list<string> $fallback
     * @return list<string>
     */
    private function keywords(string $text, int $count, array $fallback): array
    {
        preg_match_all('/\p{L}{5,}/u', mb_strtolower($text), $matches);

        $counts = array_count_values($matches[0]);
        // Stabilní pořadí: nejdřív četnost, při shodě pořadí prvního výskytu.
        $order = array_flip(array_keys($counts));
        uksort($counts, static fn(string $a, string $b): int => [$counts[$b], $order[$a]] <=> [$counts[$a], $order[$b]]);

        $words = array_slice(array_map(strval(...), array_keys($counts)), 0, $count);
        foreach ($fallback as $word) {
            if (count($words) >= 3) {
                break;
            }
            if (!in_array($word, $words, true)) {
                $words[] = $word;
            }
        }

        return $words;
    }
}
