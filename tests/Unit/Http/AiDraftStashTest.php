<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Session\AiDraftStash;
use App\Tests\Unit\Ai\Editor\DraftProposalTest;
use App\Tests\Unit\Support\ArraySession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plán 010, AC 19: návrh AI redaktora v session pod klíčem `ai_draft` (jen JSON řetězec). Zůstává do uložení
 * nebo zahození; data ze session jsou nedůvěryhodná – poškozená se smažou.
 */
final class AiDraftStashTest extends TestCase
{
    private const string KEY = 'ai_draft';

    private ArraySession $session;
    private AiDraftStash $stash;

    protected function setUp(): void
    {
        $this->session = new ArraySession();
        $this->stash = new AiDraftStash($this->session);
    }

    /**
     * Návrh uložený přes put() a přečtený zpět jako pole (pro úpravy simulující poškozenou session).
     *
     * @return array{topic: mixed, draft: array<mixed>, result: array<mixed>}
     */
    private function storedArray(): array
    {
        $this->stash->put(DraftProposalTest::sample());
        $data = json_decode((string) $this->session->data[self::KEY], true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['draft'] ?? null);
        self::assertIsArray($data['result'] ?? null);

        return ['topic' => $data['topic'] ?? null, 'draft' => $data['draft'], 'result' => $data['result']] + $data;
    }

    public function test_put_stores_json_string_under_ai_draft_key(): void
    {
        $this->stash->put(DraftProposalTest::sample());

        self::assertIsString($this->session->data[self::KEY] ?? null);
        self::assertIsArray(json_decode((string) $this->session->data[self::KEY], true));
        self::assertSame([self::KEY], array_keys($this->session->data));
    }

    public function test_get_returns_equal_proposal_repeatedly(): void
    {
        $this->stash->put(DraftProposalTest::sample());

        $first = $this->stash->get();
        $second = $this->stash->get();

        self::assertEquals(DraftProposalTest::sample(), $first);
        self::assertEquals(DraftProposalTest::sample(), $second);
        self::assertArrayHasKey(self::KEY, $this->session->data, 'get() návrh nemaže.');
    }

    public function test_clear_removes_proposal(): void
    {
        $this->stash->put(DraftProposalTest::sample());

        $this->stash->clear();

        self::assertNull($this->stash->get());
        self::assertArrayNotHasKey(self::KEY, $this->session->data);
    }

    public function test_get_reads_current_session_value(): void
    {
        $data = $this->storedArray();
        $data['topic'] = 'Úplně jiné téma pro druhý návrh';
        $this->session->data[self::KEY] = json_encode($data, JSON_THROW_ON_ERROR);

        self::assertSame('Úplně jiné téma pro druhý návrh', $this->stash->get()?->topic);
    }

    public function test_empty_session_gives_null(): void
    {
        self::assertNull($this->stash->get());
    }

    /** @return iterable<string, array{string|int}> */
    public static function corruptedValues(): iterable
    {
        yield 'broken json' => ['{"topic":"x","draft":'];
        yield 'json list' => ['[1,2,3]'];
        yield 'other shape' => ['{"neco":"jineho"}'];
        yield 'integer' => [5];
        yield 'json string' => ['"<script>"'];
    }

    #[DataProvider('corruptedValues')]
    public function test_corrupted_value_is_ignored_and_removed(string|int $value): void
    {
        $this->session->data[self::KEY] = $value;

        self::assertNull($this->stash->get());
        self::assertArrayNotHasKey(self::KEY, $this->session->data);
    }

    public function test_proposal_of_other_example_is_ignored_and_removed(): void
    {
        $data = $this->storedArray();
        $data['result'] = ['exampleId' => '08'] + $data['result'];
        $this->session->data[self::KEY] = json_encode($data, JSON_THROW_ON_ERROR);

        self::assertNull($this->stash->get());
        self::assertArrayNotHasKey(self::KEY, $this->session->data);
    }

    public function test_draft_breaking_rules_is_ignored_and_removed(): void
    {
        $data = $this->storedArray();
        $data['draft'] = ['body' => 'Příliš krátký text bez mezititulků.'] + $data['draft'];
        $this->session->data[self::KEY] = json_encode($data, JSON_THROW_ON_ERROR);

        self::assertNull($this->stash->get());
        self::assertArrayNotHasKey(self::KEY, $this->session->data);
    }
}
