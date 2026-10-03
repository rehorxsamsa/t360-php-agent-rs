<?php
/**
 * Titulní stránka: výpis publikovaných článků se stránkováním.
 *
 * @var \App\Application\Article\ArticlePage $page
 */
?>
<h1>Nejnovější články</h1>
<?php if ($page->articles === []) : ?>
<p>Zatím tu nejsou žádné publikované články.</p>
<?php else : ?>
<?php foreach ($page->articles as $article) : ?>
<article class="article-summary">
    <h2><a href="/clanek/<?= e_attr($article->slug) ?>"><?= e($article->title) ?></a></h2>
    <p class="article-meta"><time datetime="<?= e_attr($article->publishedAt->format(DATE_ATOM)) ?>"><?= e(czech_date($article->publishedAt)) ?></time> · <?= e($article->categoryName) ?></p>
    <p><?= e($article->excerpt) ?></p>
</article>
<?php endforeach; ?>
<nav aria-label="Stránkování" class="pagination">
<?php if ($page->hasPrevious()) : ?>
    <a href="<?= $page->page === 2 ? '/' : '/?strana=' . e_attr($page->page - 1) ?>" rel="prev">Novější články</a>
<?php endif; ?>
    <span aria-current="page">Strana <?= e($page->page) ?> z <?= e($page->totalPages) ?></span>
<?php if ($page->hasNext()) : ?>
    <a href="/?strana=<?= e_attr($page->page + 1) ?>" rel="next">Starší články</a>
<?php endif; ?>
</nav>
<?php endif; ?>
