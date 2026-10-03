<?php
/**
 * Detail publikovaného článku.
 *
 * @var \App\Domain\Article\ArticleDetail $article
 * @var string $bodyHtml HTML vyrenderované MarkdownRendererem (jediná cesta Markdown → HTML)
 */
?>
<article>
    <header>
        <h1><?= e($article->title) ?></h1>
        <p class="article-meta"><time datetime="<?= e_attr($article->publishedAt->format(DATE_ATOM)) ?>"><?= e(czech_date($article->publishedAt)) ?></time> · Rubrika: <?= e($article->categoryName) ?></p>
    </header>
    <p class="article-excerpt"><?= e($article->excerpt) ?></p>
    <div class="article-body">
<?= $bodyHtml /* {# bezpecne: sanitizovano #} */ ?>
    </div>
<?php if ($article->tagNames !== []) : ?>
    <footer>
        <h2 class="visually-hidden">Štítky</h2>
        <ul class="tag-list">
<?php foreach ($article->tagNames as $tagName) : ?>
            <li><?= e($tagName) ?></li>
<?php endforeach; ?>
        </ul>
    </footer>
<?php endif; ?>
</article>
<p><a href="/">Zpět na titulní stránku</a></p>
