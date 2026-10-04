<?php

declare(strict_types=1);

namespace App\Ai\Examples;

use App\Ai\LlmClient;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;

/**
 * Strukturovaný výstup s validací v PHP a jedním opakováním: schéma v API tvar nezaručí
 * tak, jak ho potřebujeme (délky, počty), proto výstup modelu vždy kontrolujeme sami
 * jako nedůvěryhodná data (LLM05). Při chybě se modelu pošle jeho odpověď a seznam chyb.
 */
final readonly class StructuredCall
{
    public function __construct(private LlmClient $client) {}

    /**
     * @param callable(array<mixed>): list<string> $validate vrací české chyby, prázdný seznam = platné
     * @throws InvalidModelOutput
     */
    public function run(LlmRequest $request, callable $validate): StructuredOutcome
    {
        $first = $this->client->complete($request);
        $this->assertComplete($first);

        [$data, $errors] = $this->check($first->text, $validate);
        if ($data !== null && $errors === []) {
            return new StructuredOutcome($data, [$first]);
        }

        // Jediné opakování: modelu se vrátí jeho vlastní odpověď a seznam chyb.
        $retry = $request->withMessages([
            ...$request->messages,
            ['role' => 'assistant', 'content' => $first->text !== '' ? $first->text : '(prázdná odpověď)'],
            [
                'role' => 'user',
                'content' => "Tvoje předchozí odpověď nebyla platná:\n- " . implode("\n- ", $errors)
                    . "\nOprav ji a vrať znovu pouze jeden JSON objekt podle schématu.",
            ],
        ]);

        $second = $this->client->complete($retry);
        $this->assertComplete($second);

        [$data, $errors] = $this->check($second->text, $validate);
        if ($data === null || $errors !== []) {
            throw new InvalidModelOutput('Model ani na druhý pokus nevrátil platná data: ' . implode('; ', $errors));
        }

        return new StructuredOutcome($data, [$first, $second]);
    }

    private function assertComplete(LlmResponse $response): void
    {
        if ($response->stopReason === 'refusal') {
            throw new InvalidModelOutput('Model odmítl odpovědět (refusal).');
        }

        if ($response->stopReason === 'max_tokens') {
            throw new InvalidModelOutput('Odpověď byla useknuta limitem max_tokens, JSON je neúplný.');
        }
    }

    /**
     * @param callable(array<mixed>): list<string> $validate
     * @return array{0: array<mixed>|null, 1: list<string>}
     */
    private function check(string $text, callable $validate): array
    {
        try {
            $data = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [null, ['odpověď není platný JSON']];
        }

        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            return [null, ['odpověď musí být JSON objekt']];
        }

        return [$data, $validate($data)];
    }
}
