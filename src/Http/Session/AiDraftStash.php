<?php

declare(strict_types=1);

namespace App\Http\Session;

use App\Ai\Editor\DraftProposal;

/**
 * Návrh AI redaktoru (příklad 09) čekající na rozhodnutí administrátora (plán 010, ADR-0010).
 *
 * Na rozdíl od {@see ExampleResultStash} se při čtení nemaže: návrh zůstává, dokud ho admin neuloží
 * jako koncept nebo nezahodí. V session je jen JSON řetězec. Data ze session jsou nedůvěryhodná –
 * poškozený JSON, jiný tvar, jiný příklad nebo koncept porušující pravidla se zahodí a klíč smaže.
 */
final readonly class AiDraftStash
{
    private const string KEY = 'ai_draft';

    /** Hloubka JSON: návrh → výsledek → pole → položka pole (s rezervou). */
    private const int JSON_DEPTH = 16;

    public function __construct(private Session $session) {}

    public function put(DraftProposal $proposal): void
    {
        $this->session->set(self::KEY, json_encode(
            $proposal->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** Aktuální návrh (opakovaně, nemaže); poškozená data → null a klíč se smaže. */
    public function get(): ?DraftProposal
    {
        $stored = $this->session->get(self::KEY);
        if ($stored === null) {
            return null;
        }

        $proposal = is_string($stored) ? self::decode($stored) : null;
        if ($proposal === null) {
            $this->clear();
        }

        return $proposal;
    }

    public function clear(): void
    {
        $this->session->remove(self::KEY);
    }

    private static function decode(string $stored): ?DraftProposal
    {
        try {
            $data = json_decode($stored, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? DraftProposal::fromArray($data) : null;
    }
}
