<?php
/**
 * Administrace: jeden AI příklad – formulář (výběr článku, u 05 i modelu) a výsledek po PRG.
 * Výstup modelu je nedůvěryhodný (LLM05): vždy jen přes e(), nikdy přes Markdown renderer.
 *
 * @var \App\Ai\Examples\AiExample $example
 * @var string $action
 * @var list<array{value: string, label: string}> $articleOptions ukázkové články + články z databáze
 * @var string $selectedArticle
 * @var list<string> $models prázdné = příklad volbu modelu nemá
 * @var string $selectedModel
 * @var string $error česká hláška ('' = bez chyby)
 * @var \App\Ai\Examples\ExampleResult|null $result
 * @var string $csrfToken
 */
?>
<h1><?= e($example->id()) ?> – <?= e($example->title()) ?></h1>
<p><?= e($example->description()) ?></p>
<?php if ($error !== '') : ?>
<p class="form-error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
<form method="post" action="<?= e_attr($action) ?>">
    <?= csrf_field($csrfToken) ?>
    <div class="field">
        <label for="article">Článek</label>
        <select name="article" id="article">
<?php foreach ($articleOptions as $option) : ?>
            <option value="<?= e_attr($option['value']) ?>"<?php if ($option['value'] === $selectedArticle) : ?> selected<?php endif; ?>><?= e($option['label']) ?></option>
<?php endforeach; ?>
        </select>
    </div>
<?php if ($models !== []) : ?>
    <div class="field">
        <label for="model">Model</label>
        <select name="model" id="model">
<?php foreach ($models as $model) : ?>
            <option value="<?= e_attr($model) ?>"<?php if ($model === $selectedModel) : ?> selected<?php endif; ?>><?= e($model) ?></option>
<?php endforeach; ?>
        </select>
    </div>
<?php endif; ?>
    <p><button type="submit">Spustit příklad</button></p>
</form>
<?php if ($result !== null) : ?>
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
<?php endif; ?>
<p class="actions"><a href="/admin/ai">Zpět na AI nástroje</a></p>
