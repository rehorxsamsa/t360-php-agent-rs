<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Cost\ModelCatalog;
use App\Ai\Cost\ModelInfo;
use App\Ai\Cost\UnknownModel;
use App\Domain\Ai\TokenUsage;
use App\Tests\Unit\Support\AiFixtures;
use PHPUnit\Framework\TestCase;

/** Plán 006, AC 2–3 a plán 012, AC 2–6: katalog modelů z config/ai-models.php a výpočet ceny. */
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

    /** Plán 012, AC 4: čtení z cache Sonnet 5.5 stojí 0,10 USD/MTok (dřív chybně 0,20). */
    public function test_sonnet_cache_read_of_one_million_tokens_costs_ten_cents(): void
    {
        self::assertSame(0.1, $this->catalog->get('claude-sonnet-5-5')->cost(new TokenUsage(0, 0, 0, 1_000_000)));
    }

    /** Plán 012, AC 2. */
    public function test_haiku_5_5_prices_and_effort_support(): void
    {
        $model = $this->catalog->get(AiFixtures::HAIKU);

        self::assertSame('claude-haiku-5-5', $model->id);
        self::assertSame(0.1, $model->inputPerMTok);
        self::assertSame(0.5, $model->outputPerMTok);
        self::assertSame(0.125, $model->cacheWritePerMTok);
        self::assertSame(0.01, $model->cacheReadPerMTok);
        self::assertTrue($model->supportsEffort);
    }

    /** Plán 012, AC 5: legacy Haiku 4.5 zůstává se starými cenami a bez `effort`. */
    public function test_legacy_haiku_prices_and_no_effort_support(): void
    {
        $model = $this->catalog->get(AiFixtures::LEGACY_HAIKU);

        self::assertSame(AiFixtures::LEGACY_HAIKU, $model->id);
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

        self::assertStringContainsString('2026-10-09', $source);
        self::assertStringContainsString('https://platform.claude.com/docs/en/about-claude/pricing', $source);
    }

    /** Plán 012, AC 6: katalog drží jen nižší cenové pásmo Haiku 5.5, poznámka to musí říct. */
    public function test_config_file_notes_haiku_5_5_price_band_above_100k_tokens(): void
    {
        $source = (string) file_get_contents(AiFixtures::root() . '/config/ai-models.php');

        self::assertStringContainsString('100 000', $source);
    }

    public function test_sonnet_cost_of_input_and_output(): void
    {
        self::assertSame(0.007, $this->catalog->get('claude-sonnet-5-5')->cost(new TokenUsage(1000, 500, 0, 0)));
    }

    /** Plán 012, AC 3: desetina ceny legacy Haiku 4.5. */
    public function test_haiku_5_5_cost_includes_cache_write_and_read(): void
    {
        self::assertSame(0.000315, $this->catalog->get(AiFixtures::HAIKU)->cost(new TokenUsage(100, 50, 2000, 3000)));
    }

    /** Plán 006, AC 3 a plán 012, AC 5: cena legacy Haiku 4.5 se nemění. */
    public function test_legacy_haiku_cost_includes_cache_write_and_read(): void
    {
        self::assertSame(0.00315, $this->catalog->get(AiFixtures::LEGACY_HAIKU)->cost(new TokenUsage(100, 50, 2000, 3000)));
    }

    public function test_cost_is_rounded_to_six_decimals(): void
    {
        $model = new ModelInfo('m', 2.0, 10.0, 2.5, 0.2, true);

        self::assertSame(0.0, $model->cost(new TokenUsage(0, 0, 0, 1)));       // 0,0000002 → 0
        self::assertSame(0.000001, $model->cost(new TokenUsage(0, 0, 0, 3)));  // 0,0000006 → 0,000001
        self::assertSame(0.0, $model->cost(new TokenUsage(0, 0)));
    }
}
