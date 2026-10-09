<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Cost\ModelCatalog;
use App\Ai\Cost\ModelInfo;
use App\Ai\Cost\UnknownModel;
use App\Domain\Ai\TokenUsage;
use App\Tests\Unit\Support\AiFixtures;
use PHPUnit\Framework\TestCase;

/** Plán 006, AC 2–3: katalog modelů z config/ai-models.php a výpočet ceny. */
final class ModelCatalogTest extends TestCase
{
    private ModelCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = AiFixtures::catalog();
    }

    public function test_sonnet_prices_and_effort_support(): void
    {
        $model = $this->catalog->get('claude-sonnet-5-5');

        self::assertSame('claude-sonnet-5-5', $model->id);
        self::assertSame(2.0, $model->inputPerMTok);
        self::assertSame(10.0, $model->outputPerMTok);
        self::assertSame(2.5, $model->cacheWritePerMTok);
        self::assertSame(0.1, $model->cacheReadPerMTok);
        self::assertTrue($model->supportsEffort);
    }

    public function test_haiku_prices_and_no_effort_support(): void
    {
        $model = $this->catalog->get('claude-haiku-4-5-20251001');

        self::assertSame('claude-haiku-4-5-20251001', $model->id);
        self::assertSame(1.0, $model->inputPerMTok);
        self::assertSame(5.0, $model->outputPerMTok);
        self::assertSame(1.25, $model->cacheWritePerMTok);
        self::assertSame(0.1, $model->cacheReadPerMTok);
        self::assertFalse($model->supportsEffort);
    }

    public function test_unknown_model_throws(): void
    {
        $this->expectException(UnknownModel::class);

        $this->catalog->get('claude-neexistuje');
    }

    public function test_empty_catalog_throws_unknown_model(): void
    {
        $this->expectException(UnknownModel::class);

        new ModelCatalog([])->get('claude-sonnet-5-5');
    }

    public function test_config_file_has_verification_date_and_pricing_url(): void
    {
        $source = (string) file_get_contents(AiFixtures::root() . '/config/ai-models.php');

        self::assertStringContainsString('2026-10-03', $source);
        self::assertStringContainsString('https://platform.claude.com/docs/en/about-claude/pricing', $source);
    }

    public function test_sonnet_cost_of_input_and_output(): void
    {
        self::assertSame(0.007, $this->catalog->get('claude-sonnet-5-5')->cost(new TokenUsage(1000, 500, 0, 0)));
    }

    public function test_haiku_cost_includes_cache_write_and_read(): void
    {
        self::assertSame(0.00315, $this->catalog->get('claude-haiku-4-5-20251001')->cost(new TokenUsage(100, 50, 2000, 3000)));
    }

    public function test_cost_is_rounded_to_six_decimals(): void
    {
        $model = new ModelInfo('m', 2.0, 10.0, 2.5, 0.2, true);

        self::assertSame(0.0, $model->cost(new TokenUsage(0, 0, 0, 1)));       // 0,0000002 → 0
        self::assertSame(0.000001, $model->cost(new TokenUsage(0, 0, 0, 3)));  // 0,0000006 → 0,000001
        self::assertSame(0.0, $model->cost(new TokenUsage(0, 0)));
    }
}
