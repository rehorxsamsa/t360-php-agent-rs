<?php
/**
 * Blok výsledku AI příkladu (01–05 a 07): varování, pole, souhrn spotřeby a surová odpověď.
 * Výstup modelu i nástrojů je nedůvěryhodný (LLM05): vždy jen přes e(), nikdy přes Markdown renderer.
 *
 * @var \App\Ai\Examples\ExampleResult $result
 */
?>
<section class="ai-result" aria-labelledby="vysledek">
    <h2 id="vysledek">Výsledek</h2>
<?php foreach ($result->warnings as $warning) : ?>
    <p class="notice" role="status"><?= e($warning) ?></p>
<?php endforeach; ?>
    <dl class="ai-fields">
<?php foreach ($result->fields as $field) : ?>
        <dt><?= e($field['label']) ?></dt>
        <dd class="ai-value"><?= e($field['value']) ?></dd>
<?php endforeach; ?>
    </dl>
    <p class="ai-meta">Model <?= e($result->model) ?> · <?= e(\App\Ai\AiProvider::fromLogName($result->provider)?->shortLabel() ?? $result->provider) ?> · volání <?= e($result->calls) ?> · tokeny vstup <?= e(czech_number($result->usage->input)) ?> / výstup <?= e(czech_number($result->usage->output)) ?> · cena <?= e(czech_number($result->costUsd, 6)) ?> USD</p>
<?php if ($result->provider === \App\Ai\AiProvider::Fake->logName()) : ?>
    <p class="field-hint">Falešný klient: cena je jen orientační, nic se neúčtovalo.</p>
<?php endif; ?>
    <details>
        <summary>Surová odpověď modelu</summary>
        <pre class="ai-output"><?= e($result->rawOutput) ?></pre>
    </details>
</section>
