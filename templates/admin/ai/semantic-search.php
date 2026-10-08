<?php
/**
 * Administrace: AI příklad 08 – Sémantické vyhledávání (RAG, ADR-0009). Panel indexu vektorů, formulář s otázkou
 * a výsledek po PRG. Odpověď modelu, citace i titulky článků jsou nedůvěryhodné (LLM05): jen přes e().
 *
 * @var \App\Ai\Examples\Example08SemanticSearch $example
 * @var string $action
 * @var string $reindexAction
 * @var \App\Domain\Article\EmbeddingIndexStatus $indexStatus
 * @var string $embeddingModel
 * @var string $embeddingProvider popis poskytovatele embeddingů pro člověka
 * @var string $flash zpráva o poslední indexaci ('' = žádná)
 * @var string $question
 * @var string $error česká hláška ('' = bez chyby)
 * @var \App\Ai\Examples\ExampleResult|null $result
 * @var string $csrfToken
 */
?>
<h1><?= e($example->id()) ?> – <?= e($example->title()) ?></h1>
<p><?= e($example->description()) ?></p>
<?php if ($flash !== '') : ?>
<p class="notice" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<section class="ai-index">
    <h2>Index článků</h2>
<?php if ($indexStatus->upToDate === 0) : ?>
    <p>Index je prázdný.<?php if ($indexStatus->published > 0) : ?> Na indexaci čeká publikovaných článků: <?= e(czech_number($indexStatus->published)) ?> (model <?= e($embeddingModel) ?>, <?= e($embeddingProvider) ?>).<?php endif; ?></p>
<?php else : ?>
    <p>Index: <?= e(czech_number($indexStatus->upToDate)) ?> z <?= e(czech_number($indexStatus->published)) ?> publikovaných článků je aktuálních (model <?= e($embeddingModel) ?>, <?= e($embeddingProvider) ?>).</p>
<?php endif; ?>
    <form method="post" action="<?= e_attr($reindexAction) ?>">
        <?= csrf_field($csrfToken) ?>
        <p><button type="submit">Aktualizovat index</button></p>
    </form>
    <p class="field-hint">Indexace spočítá vektory publikovaných článků, které je nemají nebo se od poslední indexace změnily. Z konzole: <code>make index</code>.</p>
</section>

<?php if ($error !== '') : ?>
<p class="form-error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
<form method="post" action="<?= e_attr($action) ?>">
    <?= csrf_field($csrfToken) ?>
    <div class="field">
        <label for="question">Otázka</label>
        <textarea name="question" id="question" rows="3" maxlength="500" required><?= e($question) ?></textarea>
        <p class="field-hint">Odpovídá jen z publikovaných článků a cituje je.</p>
    </div>
    <p><button type="submit">Najít a odpovědět</button></p>
</form>
<?php if ($result !== null) : ?>
<?php require __DIR__ . '/_result.php'; ?>
<?php endif; ?>
<p class="actions"><a href="/admin/ai">Zpět na AI nástroje</a></p>
