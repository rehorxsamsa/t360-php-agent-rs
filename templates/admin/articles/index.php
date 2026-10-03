<?php
/**
 * Administrace: seznam všech článků (i koncepty, archiv a naplánované) se stránkováním.
 *
 * @var \App\Application\Article\ArticlePage<\App\Domain\Article\AdminArticleSummary> $page
 * @var string $flash
 * @var \DateTimeImmutable $now
 */
?>
<h1>Články</h1>
<?php if ($flash !== '') : ?>
<p class="notice" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<p class="actions"><a href="/admin/clanky/novy">Nový článek</a></p>
<?php if ($page->articles === []) : ?>
<p>Zatím tu nejsou žádné články.</p>
<?php else : ?>
<div class="table-wrapper">
<table class="admin-table">
    <caption class="visually-hidden">Seznam článků</caption>
    <thead>
        <tr>
            <th scope="col">Titulek</th>
            <th scope="col">Stav</th>
            <th scope="col">Rubrika</th>
            <th scope="col">Publikace</th>
            <th scope="col">Naposledy upraveno</th>
            <th scope="col">Akce</th>
        </tr>
    </thead>
    <tbody>
<?php foreach ($page->articles as $article) : ?>
        <tr>
            <td><a href="/admin/clanky/<?= e_attr($article->id) ?>/upravit"><?= e($article->title) ?></a></td>
            <td><?= e($article->isScheduled($now) ? 'Naplánováno' : $article->status->label()) ?></td>
            <td><?= e($article->categoryName) ?></td>
            <td>
<?php if ($article->publishedAt === null) : ?>
                —
<?php else : ?>
                <time datetime="<?= e_attr($article->publishedAt->format(DATE_ATOM)) ?>"><?= e(czech_date($article->publishedAt)) ?></time>
<?php endif; ?>
            </td>
            <td>
                <time datetime="<?= e_attr($article->updatedAt->format(DATE_ATOM)) ?>"><?= e(czech_date($article->updatedAt) . ' ' . $article->updatedAt->format('H:i')) ?></time>
                <br><?= e($article->updatedByName ?? 'neuvedeno') ?>
            </td>
            <td class="row-actions">
                <a href="/admin/clanky/<?= e_attr($article->id) ?>/upravit" aria-label="Upravit článek <?= e_attr($article->title) ?>">Upravit</a>
                <a href="/admin/clanky/<?= e_attr($article->id) ?>/smazat" aria-label="Smazat článek <?= e_attr($article->title) ?>">Smazat</a>
            </td>
        </tr>
<?php endforeach; ?>
    </tbody>
</table>
</div>
<nav aria-label="Stránkování" class="pagination">
<?php if ($page->hasPrevious()) : ?>
    <a href="<?= e_attr($page->page === 2 ? '/admin/clanky' : '/admin/clanky?strana=' . ($page->page - 1)) ?>" rel="prev">Předchozí strana</a>
<?php endif; ?>
    <span aria-current="page">Strana <?= e($page->page) ?> z <?= e($page->totalPages) ?></span>
<?php if ($page->hasNext()) : ?>
    <a href="/admin/clanky?strana=<?= e_attr($page->page + 1) ?>" rel="next">Další strana</a>
<?php endif; ?>
</nav>
<?php endif; ?>
<p><a href="/admin">Zpět do administrace</a></p>
