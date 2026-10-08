<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Ai\Embedding\EmbeddingFailed;
use App\Ai\Embedding\EmbeddingProvider;
use App\Ai\Rag\ArticleIndexer;
use App\Console\Command;
use App\Console\Output;

/**
 * ai:indexuj – aktualizuje index vektorů publikovaných článků pro příklad 08 (zpracuje jen chybějící a změněné články,
 * nepublikované odebere). Bez argumentů; velké redakce indexujte z konzole, ne přes web (omezení doby běhu).
 */
final readonly class IndexArticlesCommand implements Command
{
    public function __construct(private ArticleIndexer $indexer) {}

    public function run(array $arguments, Output $output): int
    {
        if ($arguments !== []) {
            $output->error('Použití: php bin/konzole ai:indexuj');

            return 1;
        }

        try {
            $report = $this->indexer->update();
        } catch (EmbeddingFailed $exception) {
            $output->error($exception->getMessage());

            return 1;
        }

        $output->line(sprintf(
            'Index aktualizován: zaindexováno %d, odebráno %d, čeká %d (model %s, %s, tokeny %d, %d ms).',
            $report->indexed,
            $report->removed,
            $report->remaining,
            $report->model,
            EmbeddingProvider::fromLogName($report->provider)?->label() ?? $report->provider,
            $report->tokens,
            $report->durationMs,
        ));

        return 0;
    }
}
