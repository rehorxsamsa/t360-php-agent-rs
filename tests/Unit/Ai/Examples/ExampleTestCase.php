<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Examples;

use App\Ai\Examples\AiExample;
use App\Ai\Examples\ArticleSnapshot;
use App\Ai\Examples\ExampleContext;
use App\Ai\Examples\ExampleRegistry;
use App\Ai\Examples\ExampleResult;
use App\Ai\LlmRequest;
use App\Container\Container;
use App\Tests\Unit\Support\AiFixtures;
use App\Tests\Unit\Support\InMemoryAiCallRepository;
use App\Tests\Unit\Support\InMemoryArticleAdminRepository;
use App\Tests\Unit\Support\InMemoryAuditLogRepository;
use App\Tests\Unit\Support\InMemoryCategoryRepository;
use App\Tests\Unit\Support\InMemoryTagRepository;
use App\Tests\Unit\Support\InMemoryUserRepository;
use App\Tests\Unit\Support\ScriptedLlmClient;
use App\Tests\Unit\Support\TestContainer;
use PHPUnit\Framework\TestCase;

/**
 * Společný základ testů příkladů (plán 006, AC 13–19): příklady se skládají skutečným kontejnerem
 * (autowiring – testy nezávisí na pořadí parametrů konstruktorů), LlmClient je skriptovaný.
 */
abstract class ExampleTestCase extends TestCase
{
    protected const string SECRET = 'sk-ant-api03-tajny-klic-pro-test';

    protected ScriptedLlmClient $llm;
    protected InMemoryArticleAdminRepository $articles;
    protected InMemoryCategoryRepository $categories;
    protected InMemoryTagRepository $tags;
    protected Container $container;

    protected function setUp(): void
    {
        $this->llm = new ScriptedLlmClient();
        $this->articles = new InMemoryArticleAdminRepository();
        $this->categories = new InMemoryCategoryRepository();
        $this->tags = new InMemoryTagRepository();
        $this->container = TestContainer::withoutSession(
            new InMemoryUserRepository(),
            new InMemoryAuditLogRepository(),
            adminArticles: $this->articles,
            categories: $this->categories,
            tags: $this->tags,
            aiCalls: new InMemoryAiCallRepository(),
            llmClient: $this->llm,
            aiConfig: AiFixtures::config(apiKey: self::SECRET),
        );
    }

    protected function example(string $id): AiExample
    {
        $example = $this->container->get(ExampleRegistry::class)->get($id);
        self::assertNotNull($example, 'Příklad ' . $id . ' není v registru.');

        return $example;
    }

    protected function runExample(string $id, ?ArticleSnapshot $article = null, string $model = '', ?int $userId = 7): ExampleResult
    {
        return $this->example($id)->run($article ?? AiFixtures::snapshot(), new ExampleContext($userId, $model));
    }

    protected function lastRequest(): LlmRequest
    {
        $request = end($this->llm->requests);
        self::assertInstanceOf(LlmRequest::class, $request, 'LLM nebyl volán.');

        return $request;
    }

    /** Jako assertStringStartsWith, ale snese i prázdný prefix (PHPStan: non-empty-string). */
    protected static function assertPrefix(string $prefix, string $actual, string $message = ''): void
    {
        self::assertTrue(str_starts_with($actual, $prefix), $message !== '' ? $message : sprintf('„%s“ nezačíná „%s“.', $actual, $prefix));
    }

    /** @param array<mixed> $data */
    protected static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return list<string> */
    protected static function labels(ExampleResult $result): array
    {
        return array_map(static fn(array $field): string => $field['label'], $result->fields);
    }

    protected static function field(ExampleResult $result, string $label): ?string
    {
        foreach ($result->fields as $field) {
            if ($field['label'] === $label) {
                return $field['value'];
            }
        }

        return null;
    }
}
