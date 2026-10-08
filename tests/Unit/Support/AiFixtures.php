<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Ai\AiConfig;
use App\Ai\AiProvider;
use App\Ai\Client\TransportFailed;
use App\Ai\Cost\ModelCatalog;
use App\Ai\Examples\ArticleSnapshot;
use App\Ai\LlmCallFailed;
use App\Ai\LlmErrorType;
use App\Ai\LlmRequest;
use App\Ai\LlmResponse;
use App\Ai\ToolCall;
use App\Domain\Ai\TokenUsage;

/**
 * Testovací data AI (kontrakt plánu 006): konfigurace, katalog, odpovědi a výjimky.
 * Konstruktory výjimek, které plán neupřesňuje, jsou soustředěné sem (jedno místo k úpravě).
 */
final class AiFixtures
{
    public const string SONNET = 'claude-sonnet-5-5';
    public const string HAIKU = 'claude-haiku-4-5-20251001';

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function config(
        AiProvider $provider = AiProvider::Fake,
        string $apiKey = '',
        int $dailyTokenLimit = 200000,
    ): AiConfig {
        return new AiConfig(
            provider: $provider,
            apiKey: $apiKey,
            model: self::SONNET,
            cheapModel: self::HAIKU,
            dailyTokenLimit: $dailyTokenLimit,
        );
    }

    public static function catalog(): ModelCatalog
    {
        return ModelCatalog::fromFile(self::root() . '/config/ai-models.php');
    }

    public static function response(
        string $text,
        string $stopReason = 'end_turn',
        int $input = 10,
        int $output = 5,
        string $model = self::SONNET,
        string $provider = 'fake',
        ?float $costUsd = 0.0001,
        ?string $requestId = null,
        int $attempts = 1,
    ): LlmResponse {
        return new LlmResponse(
            text: $text,
            model: $model,
            stopReason: $stopReason,
            usage: new TokenUsage($input, $output),
            provider: $provider,
            requestId: $requestId,
            attempts: $attempts,
            costUsd: $costUsd,
        );
    }

    /**
     * Odpověď s voláním nástrojů (plán 008, AC 9): surový blok `thinking` (se signaturou), volitelně
     * `text` a `tool_use` pro každé volání; `stop_reason 'tool_use'`. Spolu s `finalResponse()` jediné místo,
     * které skládá LlmResponse s `content` a `toolCalls`.
     *
     * @param list<ToolCall> $calls
     */
    public static function toolUseResponse(
        array $calls,
        string $text = '',
        int $input = 10,
        int $output = 5,
        ?float $costUsd = 0.0001,
    ): LlmResponse {
        $content = [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-' . count($calls)]];
        if ($text !== '') {
            $content[] = ['type' => 'text', 'text' => $text];
        }
        foreach ($calls as $call) {
            $content[] = ['type' => 'tool_use', 'id' => $call->id, 'name' => $call->name, 'input' => $call->input];
        }

        return new LlmResponse(
            text: $text,
            model: self::SONNET,
            stopReason: 'tool_use',
            usage: new TokenUsage($input, $output),
            provider: 'fake',
            costUsd: $costUsd,
            content: $content,
            toolCalls: $calls,
        );
    }

    /** Konečná odpověď smyčky (bez nástrojů) s blokem `text` v `content`. */
    public static function finalResponse(string $text, string $stopReason = 'end_turn', ?float $costUsd = 0.0001): LlmResponse
    {
        return new LlmResponse(
            text: $text,
            model: self::SONNET,
            stopReason: $stopReason,
            usage: new TokenUsage(10, 5),
            provider: 'fake',
            costUsd: $costUsd,
            content: $text === '' ? [] : [['type' => 'text', 'text' => $text]],
            toolCalls: [],
        );
    }

    /** @param array<string, mixed>|null $jsonSchema */
    public static function request(
        string $model = self::SONNET,
        int $maxTokens = 400,
        ?string $effort = 'low',
        ?array $jsonSchema = null,
        bool $cacheSystem = false,
        string $system = 'S',
        string $user = 'U',
        string $exampleId = '01',
        ?int $userId = 7,
    ): LlmRequest {
        return new LlmRequest(
            model: $model,
            system: $system,
            messages: [['role' => 'user', 'content' => $user]],
            maxTokens: $maxTokens,
            exampleId: $exampleId,
            userId: $userId,
            effort: $effort,
            jsonSchema: $jsonSchema,
            cacheSystem: $cacheSystem,
        );
    }

    /**
     * PŘEDPOKLAD (plán neupřesňuje konstruktor): `new LlmCallFailed(type:, httpStatus:, attempts:, requestId:)`,
     * zpráva výchozí = `$type->userMessage()`.
     */
    public static function llmCallFailed(
        LlmErrorType $type,
        ?int $httpStatus = null,
        int $attempts = 1,
        ?string $requestId = null,
    ): LlmCallFailed {
        return new LlmCallFailed(type: $type, httpStatus: $httpStatus, attempts: $attempts, requestId: $requestId);
    }

    /** PŘEDPOKLAD (plán neupřesňuje konstruktor): `new TransportFailed(message:, timedOut:)`. */
    public static function transportFailed(bool $timedOut): TransportFailed
    {
        return new TransportFailed(message: $timedOut ? 'Operation timed out' : 'Could not connect', timedOut: $timedOut);
    }

    public static function snapshot(
        string $title = 'Titulek článku',
        string $slug = 'titulek-clanku',
        string $excerpt = 'Perex článku.',
        string $body = 'Text článku. Druhá věta textu.',
    ): ArticleSnapshot {
        return new ArticleSnapshot(title: $title, slug: $slug, excerpt: $excerpt, body: $body);
    }
}
