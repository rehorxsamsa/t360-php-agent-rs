<?php
/**
 * Administrace: AI příklad 06 – Asistent psaní se živým výstupem (streaming, ADR-0008).
 *
 * Formulář odesílá `ai-stream.js` přes fetch (POST + FormData s `_csrf`) a výstup modelu vkládá jen přes
 * textContent – výstup modelu je nedůvěryhodný (LLM05). Bez JavaScriptu příklad nefunguje (<noscript>).
 *
 * @var \App\Ai\Examples\Example06WritingAssistant $example
 * @var list<\App\Ai\Examples\WritingAction> $actions
 * @var \App\Ai\Examples\WritingAction $selectedAction
 * @var string $text
 * @var string $csrfToken
 */
?>
<h1><?= e($example->id()) ?> – <?= e($example->title()) ?></h1>
<p><?= e($example->description()) ?></p>
<noscript><p class="form-error">Asistent psaní potřebuje zapnutý JavaScript.</p></noscript>
<p class="form-error" role="alert" id="ai-stream-error" hidden></p>
<form id="writing-assistant" method="post" action="/admin/ai/06/proud">
    <?= csrf_field($csrfToken) ?>
    <div class="field">
        <label for="text">Text</label>
        <textarea name="text" id="text" rows="8" maxlength="5000" required><?= e($text) ?></textarea>
    </div>
    <div class="field">
        <label for="action">Akce</label>
        <select name="action" id="action">
<?php foreach ($actions as $action) : ?>
            <option value="<?= e_attr($action->value) ?>"<?php if ($action === $selectedAction) : ?> selected<?php endif; ?>><?= e($action->label()) ?></option>
<?php endforeach; ?>
        </select>
    </div>
    <p class="ai-stream-buttons">
        <button type="submit">Generovat</button>
        <button type="button" id="ai-stream-abort" class="button-secondary" disabled>Přerušit</button>
    </p>
</form>
<section class="ai-result" aria-labelledby="ai-stream-heading">
    <h2 id="ai-stream-heading">Výstup</h2>
    <p class="ai-stream-status" role="status" id="ai-stream-status">Připraveno.</p>
    <div class="ai-stream-output" id="ai-stream-output" aria-live="polite" aria-busy="false"></div>
    <p class="ai-meta" id="ai-stream-meta"></p>
</section>
<p class="actions"><a href="/admin/ai">Zpět na AI nástroje</a></p>
<script src="/assets/ai-stream.js" defer></script>
