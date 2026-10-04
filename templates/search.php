<?php
/**
 * Výsledky vyhledávání s zvýrazněným výrazem.
 *
 * @var string $query
 * @var list<\App\Domain\Article\ArticleSummary> $results
 * @var int $limit
 */
?>
<h1>Hledání</h1>
<?php if ($query === '') : ?>
<p>Zadejte hledaný výraz. Prohledává se titulek, perex i celý text článků.</p>
<?php elseif ($results === []) : ?>
<p>Pro výraz „<?= e($query) ?>“ nebyl nalezen žádný článek.</p>
<?php else : ?>
<p>Nalezeno článků: <?= e(count($results)) ?><?= count($results) >= $limit ? ' (zobrazeno prvních ' . e($limit) . ')' : '' ?>.</p>
<?php foreach ($results as $article) : ?>
<article class="article-summary">
    <h2><a href="/clanek/<?= e_attr($article->slug) ?>"><?= highlight($article->title, $query) ?></a></h2>
    <p class="article-meta"><time datetime="<?= e_attr($article->publishedAt->format(DATE_ATOM)) ?>"><?= e(czech_date($article->publishedAt)) ?></time> · <?= e($article->categoryName) ?></p>
    <p><?= highlight($article->excerpt, $query) ?></p>
<?php if (!contains_ci($article->title, $query) && !contains_ci($article->excerpt, $query)) : ?>
    <p class="article-meta">Shoda je v textu článku.</p>
<?php endif; ?>
</article>
<?php endforeach; ?>
<?php endif; ?>
