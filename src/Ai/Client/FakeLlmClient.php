<?php

declare(strict_types=1);

namespace App\Ai\Client;

use App\Ai\LlmClient;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;
use App\Domain\Ai\TokenUsage;

/**
 * Falešný klient bez sítě a bez API klíče: odpověď je deterministická funkce požadavku
 * (podle `exampleId` a textu uvnitř značek `<titulek>` a `<text>`). Tokeny se odhadují
 * jako znaky / 4, takže i bez klíče vidíte orientační cenu. Nejde o skutečný model:
 * např. „nález prompt injection“ v příkladu 04 je naskriptovaný (hledá slovo „ignoruj“).
 */
final readonly class FakeLlmClient implements LlmClient
{
    private const int CHARS_PER_TOKEN = 4;

    public function complete(LlmRequest $request): LlmResponse
    {
        $text = $this->answer($request);

        $inputChars = mb_strlen($request->system);
        foreach ($request->messages as $message) {
            $inputChars += mb_strlen($message['content']);
        }

        return new LlmResponse(
            $text,
            $request->model,
            'end_turn',
            new TokenUsage($this->estimateTokens($inputChars), $this->estimateTokens(mb_strlen($text))),
            'fake',
        );
    }

    private function estimateTokens(int $chars): int
    {
        return intdiv($chars + self::CHARS_PER_TOKEN - 1, self::CHARS_PER_TOKEN);
    }

    private function answer(LlmRequest $request): string
    {
        $data = $request->messages[0]['content'];
        $title = $this->between($data, 'titulek');
        $excerpt = $this->between($data, 'perex');
        $body = $this->between($data, 'text');

        return match ($request->exampleId) {
            '01' => $this->excerpt($title, $body),
            '02' => $this->json($this->seo($title, $body)),
            '03' => $this->json($this->classification($request, $title, $body)),
            '04' => $this->json($this->review($body)),
            '05' => $this->json(['title' => '[EN] ' . $title, 'excerpt' => $excerpt, 'body' => $body]),
            default => sprintf('Falešná odpověď klienta (příklad %s).', $request->exampleId),
        };
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
