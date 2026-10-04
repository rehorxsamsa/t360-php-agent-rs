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
