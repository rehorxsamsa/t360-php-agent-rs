<?php
/**
 * Administrace: formulář nového článku i úpravy (sdílený).
 *
 * @var string $heading
 * @var string $action
 * @var \App\Application\Article\ArticleInput $input surové hodnoty (i po chybě validace)
 * @var array<string, string> $errors pole => česká hláška
 * @var list<\App\Domain\Category\Category> $categories
 * @var list<\App\Domain\Tag\Tag> $tags
 * @var \App\Domain\Article\EditableArticle|null $article uložený článek (null u nového)
 * @var string $previewHtml HTML uloženého textu z MarkdownRendereru ('' = bez náhledu)
 * @var string $flash
 * @var string $csrfToken
 * @var \DateTimeImmutable $now
 */
?>
<h1><?= e($heading) ?></h1>
<?php if ($flash !== '') : ?>
<p class="notice" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?php if ($article !== null) : ?>
<p class="article-meta">Naposledy upraveno <?= e(czech_date($article->updatedAt) . ' ' . $article->updatedAt->format('H:i')) ?> (<?= e($article->updatedByName ?? 'neuvedeno') ?>)</p>
<?php endif; ?>
<?php if ($errors !== []) : ?>
<p class="form-error" role="alert">Článek se nepodařilo uložit, opravte prosím chyby ve formuláři.</p>
<?php endif; ?>
<form method="post" action="<?= e_attr($action) ?>">
    <?= csrf_field($csrfToken) ?>
    <div class="field">
        <label for="title">Titulek</label>
<?php if (isset($errors['title'])) : ?>
        <p class="field-error" id="title-error"><?= e($errors['title']) ?></p>
<?php endif; ?>
        <input id="title" name="title" type="text" maxlength="200" required value="<?= e_attr($input->title) ?>"<?php if (isset($errors['title'])) : ?> aria-invalid="true" aria-describedby="title-error"<?php endif; ?>>
    </div>
    <div class="field">
        <label for="slug">Adresa (slug)</label>
        <p class="field-hint" id="slug-hint">Nechte prázdné – vytvoří se z titulku.</p>
<?php if (isset($errors['slug'])) : ?>
        <p class="field-error" id="slug-error"><?= e($errors['slug']) ?></p>
<?php endif; ?>
        <input id="slug" name="slug" type="text" maxlength="220" value="<?= e_attr($input->slug) ?>"<?php if (isset($errors['slug'])) : ?> aria-invalid="true" aria-describedby="slug-error slug-hint"<?php else : ?> aria-describedby="slug-hint"<?php endif; ?>>
    </div>
    <div class="field">
        <label for="excerpt">Perex</label>
<?php if (isset($errors['excerpt'])) : ?>
        <p class="field-error" id="excerpt-error"><?= e($errors['excerpt']) ?></p>
<?php endif; ?>
        <textarea id="excerpt" name="excerpt" maxlength="500" rows="3"<?php if (isset($errors['excerpt'])) : ?> aria-invalid="true" aria-describedby="excerpt-error"<?php endif; ?>><?= e($input->excerpt) ?></textarea>
    </div>
    <div class="field">
        <label for="body">Text (Markdown)</label>
        <p class="field-hint" id="body-hint">Nadpisy <code>##</code>, odrážky <code>-</code>, <code>**tučně**</code>, <code>*kurzíva*</code>, odkazy <code>[text](https://…)</code>. HTML se nevykoná.</p>
<?php if (isset($errors['body'])) : ?>
        <p class="field-error" id="body-error"><?= e($errors['body']) ?></p>
<?php endif; ?>
        <textarea id="body" name="body" rows="16" class="body-input"<?php if (isset($errors['body'])) : ?> aria-invalid="true" aria-describedby="body-error body-hint"<?php else : ?> aria-describedby="body-hint"<?php endif; ?>><?= e($input->body) ?></textarea>
    </div>
    <div class="field">
        <label for="category_id">Rubrika</label>
<?php if (isset($errors['category_id'])) : ?>
        <p class="field-error" id="category_id-error"><?= e($errors['category_id']) ?></p>
<?php endif; ?>
        <select id="category_id" name="category_id"<?php if (isset($errors['category_id'])) : ?> aria-invalid="true" aria-describedby="category_id-error"<?php endif; ?>>
            <option value="">— vyberte rubriku —</option>
<?php foreach ($categories as $category) : ?>
            <option value="<?= e_attr($category->id) ?>"<?php if ($input->categoryId === (string) $category->id) : ?> selected<?php endif; ?>><?= e($category->name) ?></option>
<?php endforeach; ?>
        </select>
    </div>
    <fieldset class="field checkbox-group">
        <legend>Štítky</legend>
<?php if (isset($errors['tags'])) : ?>
        <p class="field-error" id="tags-error"><?= e($errors['tags']) ?></p>
<?php endif; ?>
<?php foreach ($tags as $tag) : ?>
        <span class="checkbox">
            <input type="checkbox" id="tag-<?= e_attr($tag->id) ?>" name="tags[]" value="<?= e_attr($tag->id) ?>"<?php if (in_array((string) $tag->id, $input->tagIds, true)) : ?> checked<?php endif; ?>>
            <label for="tag-<?= e_attr($tag->id) ?>"><?= e($tag->name) ?></label>
        </span>
<?php endforeach; ?>
    </fieldset>
    <div class="field">
        <label for="status">Stav</label>
<?php if (isset($errors['status'])) : ?>
        <p class="field-error" id="status-error"><?= e($errors['status']) ?></p>
<?php endif; ?>
        <select id="status" name="status"<?php if (isset($errors['status'])) : ?> aria-invalid="true" aria-describedby="status-error"<?php endif; ?>>
<?php foreach (\App\Domain\Article\ArticleStatus::cases() as $status) : ?>
            <option value="<?= e_attr($status->value) ?>"<?php if ($input->status === $status->value) : ?> selected<?php endif; ?>><?= e($status->label()) ?></option>
<?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="published_at">Datum publikace</label>
        <p class="field-hint" id="published_at-hint">U publikovaného článku prázdné = teď; budoucí datum = naplánovaný článek.</p>
<?php if (isset($errors['published_at'])) : ?>
        <p class="field-error" id="published_at-error"><?= e($errors['published_at']) ?></p>
<?php endif; ?>
        <input type="datetime-local" id="published_at" name="published_at" value="<?= e_attr($input->publishedAt) ?>"<?php if (isset($errors['published_at'])) : ?> aria-invalid="true" aria-describedby="published_at-error published_at-hint"<?php else : ?> aria-describedby="published_at-hint"<?php endif; ?>>
    </div>
    <p><button type="submit">Uložit článek</button></p>
</form>
<?php if ($article !== null) : ?>
<p class="actions">
<?php if ($article->isPubliclyVisible($now)) : ?>
    <a href="/clanek/<?= e_attr($article->slug) ?>">Zobrazit na webu</a>
<?php endif; ?>
    <a class="button-danger" href="/admin/clanky/<?= e_attr($article->id) ?>/smazat">Smazat článek</a>
</p>
<?php if ($previewHtml !== '') : ?>
<section class="preview" aria-labelledby="preview-heading">
    <h2 id="preview-heading">Náhled uloženého textu</h2>
    <div class="article-body">
<?= $previewHtml /* {# bezpecne: sanitizovano #} */ ?>
    </div>
</section>
<?php endif; ?>
<?php endif; ?>
<p><a href="/admin/clanky">Zpět na seznam článků</a></p>
