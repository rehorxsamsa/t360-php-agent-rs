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
<?php require __DIR__ . '/_result.php'; ?>
<?php endif; ?>
<p class="actions"><a href="/admin/ai">Zpět na AI nástroje</a></p>
