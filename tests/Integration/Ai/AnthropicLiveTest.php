<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ai;

use App\Ai\Client\AnthropicClient;
use App\Ai\Client\CurlHttpTransport;
use App\Ai\Cost\ModelCatalog;
use App\Ai\Examples\DemoArticles;
use App\Ai\LlmRequest;
use App\Ai\PromptData;
use App\Ai\PromptLibrary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Plán 006, AC 32: jediné skutečné volání Claude API (příklad 01, maxTokens 400).
 * Spouští jen člověk: `docker compose exec app vendor/bin/phpunit --group live`. Bez klíče se přeskočí;
 * `make test` skupinu `live` vylučuje (phpunit.xml.dist).
 */
#[Group('live')]
final class AnthropicLiveTest extends TestCase
{
    public function test_example_01_request_returns_text_usage_and_request_id(): void
    {
        $apiKey = getenv('ANTHROPIC_API_KEY');
        if (!is_string($apiKey) || $apiKey === '') {
            self::markTestSkipped('ANTHROPIC_API_KEY není nastaven – živý test se přeskakuje.');
        }

        $root = dirname(__DIR__, 3);
        $model = getenv('AI_MODEL');
        $model = is_string($model) && $model !== '' ? $model : 'claude-sonnet-5-5';
        $client = new AnthropicClient(new CurlHttpTransport(), ModelCatalog::fromFile($root . '/config/ai-models.php'), $apiKey);

        $response = $client->complete(new LlmRequest(
            model: $model,
            system: new PromptLibrary($root . '/src/Ai/Prompts')->system('01-excerpt'),
            messages: [['role' => 'user', 'content' => PromptData::article(DemoArticles::standard()) . "\n\nNapiš perex článku."]],
            maxTokens: 400,
            exampleId: '01',
            effort: 'low',
        ));

        self::assertSame('anthropic', $response->provider);
        self::assertNotSame('', trim($response->text));
        self::assertGreaterThan(0, $response->usage->input);
        self::assertGreaterThan(0, $response->usage->output);
        self::assertStringStartsWith('req_', (string) $response->requestId);
        self::assertContains($response->stopReason, ['end_turn', 'max_tokens']);
    }
}
