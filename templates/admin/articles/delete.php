<?php
/**
 * Administrace: potvrzení smazání článku (mazání jen metodou POST s CSRF tokenem).
 *
 * @var \App\Domain\Article\EditableArticle $article
 * @var string $csrfToken
 */
?>
<h1>Smazat článek</h1>
<p>Opravdu smazat článek „<?= e($article->title) ?>“? Akci nelze vrátit.</p>
<form method="post" action="/admin/clanky/<?= e_attr($article->id) ?>/smazat">
    <?= csrf_field($csrfToken) ?>
    <p class="actions">
        <button type="submit" class="button-danger">Smazat článek</button>
        <a href="/admin/clanky/<?= e_attr($article->id) ?>/upravit">Zrušit</a>
    </p>
</form>
