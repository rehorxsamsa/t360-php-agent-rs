<?php
/**
 * Administrace: AI příklad 09 – AI redaktor (plán 010, ADR-0010). Formulář s tématem, návrh ke schválení
 * (průběh workflow, nálezy, cena) a formulář „Uložit jako koncept“ / „Zahodit návrh“.
 * Návrh je výstup modelu, tedy nedůvěryhodný (LLM05): v šabloně jen přes e() / e_attr(), nikdy jako HTML.
 * Formulář schválení záměrně nemá stav, datum publikace, slug ani štítky – publikovat lze jen v úpravě článku.
 *
 * @var \App\Ai\Examples\Example09AiEditor $example
 * @var string $action
 * @var string $saveAction
 * @var string $discardAction
 * @var string $topic téma ve formuláři (odeslané, z návrhu, nebo ukázkové)
 * @var string $error česká hláška k tématu ('' = bez chyby)
 * @var \App\Ai\Editor\DraftProposal|null $proposal návrh čekající na schválení
 * @var \App\Application\Article\ArticleInput|null $input hodnoty formuláře schválení (návrh nebo odeslané)
 * @var array<string, string> $errors chyby formuláře schválení (pole => česká hláška)
 * @var list<\App\Domain\Category\Category> $categories
 * @var string $flash
 * @var string $csrfToken
 */
?>
<h1><?= e($example->id()) ?> – <?= e($example->title()) ?></h1>
<p><?= e($example->description()) ?></p>
<p class="field-hint">AI redaktor jen navrhuje. Koncept uloží až administrátor tlačítkem „Uložit jako koncept“ a publikovat ho lze jen v úpravě článku.</p>
<?php if ($flash !== '') : ?>
<p class="notice" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?php if ($error !== '') : ?>
<p class="form-error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
<form method="post" action="<?= e_attr($action) ?>">
    <?= csrf_field($csrfToken) ?>
    <div class="field">
        <label for="topic">Téma</label>
        <textarea name="topic" id="topic" rows="3" maxlength="300" required aria-describedby="topic-hint"><?= e($topic) ?></textarea>
        <p class="field-hint" id="topic-hint">10–300 znaků. Workflow: osnova → koncept → sebekontrola → nejvýše jedno přepracování (3–4 volání modelu).</p>
    </div>
    <p><button type="submit">Navrhnout koncept</button></p>
</form>
<?php if ($proposal !== null && $input !== null) : ?>
<?php
    $result = $proposal->result;
    $categoryMatched = false;
    foreach ($categories as $category) {
        $categoryMatched = $categoryMatched || $input->categoryId === (string) $category->id;
    }
?>
<section class="ai-proposal" aria-labelledby="navrh">
    <h2 id="navrh">Návrh ke schválení</h2>
    <p class="notice" role="note">Fakta v konceptu AI neověřila – před publikací je zkontrolujte.</p>
<?php require __DIR__ . '/_result.php'; ?>

<?php if ($errors !== []) : ?>
    <ul class="form-error" role="alert">
<?php foreach ($errors as $message) : ?>
        <li><?= e($message) ?></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
    <div class="ai-proposal-decision">
        <form method="post" action="<?= e_attr($saveAction) ?>" class="ai-proposal-save">
            <?= csrf_field($csrfToken) ?>
            <div class="field">
                <label for="title">Titulek</label>
<?php if (isset($errors['title'])) : ?>
                <p class="field-error" id="title-error"><?= e($errors['title']) ?></p>
<?php endif; ?>
                <input type="text" name="title" id="title" maxlength="200" required value="<?= e_attr($input->title) ?>"<?php if (isset($errors['title'])) : ?> aria-invalid="true" aria-describedby="title-error"<?php endif; ?>>
            </div>
            <div class="field">
                <label for="excerpt">Perex</label>
<?php if (isset($errors['excerpt'])) : ?>
                <p class="field-error" id="excerpt-error"><?= e($errors['excerpt']) ?></p>
<?php endif; ?>
                <textarea name="excerpt" id="excerpt" rows="3" maxlength="500"<?php if (isset($errors['excerpt'])) : ?> aria-invalid="true" aria-describedby="excerpt-error"<?php endif; ?>><?= e($input->excerpt) ?></textarea>
            </div>
            <div class="field">
                <label for="body">Text (Markdown)</label>
<?php if (isset($errors['body'])) : ?>
                <p class="field-error" id="body-error"><?= e($errors['body']) ?></p>
<?php endif; ?>
                <textarea name="body" id="body" rows="16" class="body-input"<?php if (isset($errors['body'])) : ?> aria-invalid="true" aria-describedby="body-error"<?php endif; ?>><?= e($input->body) ?></textarea>
            </div>
            <div class="field">
                <label for="category_id">Rubrika</label>
<?php if (isset($errors['category_id'])) : ?>
                <p class="field-error" id="category_id-error"><?= e($errors['category_id']) ?></p>
<?php endif; ?>
                <select name="category_id" id="category_id" required<?php if (isset($errors['category_id'])) : ?> aria-invalid="true" aria-describedby="category_id-error"<?php endif; ?>>
                    <option value=""<?php if (!$categoryMatched) : ?> selected<?php endif; ?>>— vyberte rubriku —</option>
<?php foreach ($categories as $category) : ?>
                    <option value="<?= e_attr($category->id) ?>"<?php if ($input->categoryId === (string) $category->id) : ?> selected<?php endif; ?>><?= e($category->name) ?></option>
<?php endforeach; ?>
                </select>
            </div>
            <p class="field-hint">Uloží se vždy jen jako koncept bez štítků; adresa (slug) vznikne z titulku.</p>
            <p><button type="submit">Uložit jako koncept</button></p>
        </form>
        <form method="post" action="<?= e_attr($discardAction) ?>" class="ai-proposal-discard">
            <?= csrf_field($csrfToken) ?>
            <p><button type="submit" class="button-secondary">Zahodit návrh</button></p>
        </form>
    </div>
</section>
<?php endif; ?>
<p class="actions"><a href="/admin/ai">Zpět na AI nástroje</a></p>
