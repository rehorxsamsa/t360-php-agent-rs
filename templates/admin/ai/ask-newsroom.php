<?php
/**
 * Administrace: AI příklad 07 – Zeptej se redakce (tool use, ADR-0008). Formulář s otázkou a výsledek po PRG.
 * Odpověď modelu i výsledky nástrojů (titulky článků) jsou nedůvěryhodné (LLM05): jen přes e().
 *
 * @var \App\Ai\Examples\Example07AskNewsroom $example
 * @var string $action
 * @var string $question
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
        <label for="question">Otázka</label>
        <textarea name="question" id="question" rows="3" maxlength="500" required><?= e($question) ?></textarea>
        <p class="field-hint">Agent smí jen číst publikované články (nejvýše 5 kroků).</p>
    </div>
    <p><button type="submit">Zeptat se</button></p>
</form>
<?php if ($result !== null) : ?>
<?php require __DIR__ . '/_result.php'; ?>
<?php endif; ?>
<p class="actions"><a href="/admin/ai">Zpět na AI nástroje</a></p>
