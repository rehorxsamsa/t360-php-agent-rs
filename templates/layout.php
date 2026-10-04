<?php
/**
 * Rozvržení stránky. $content je už vykreslené HTML šablony – jediný výpis bez e().
 *
 * @var string $content
 * @var string|null $title
 */
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? '') ?> · Redakční systém</title>
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<a class="skip-link" href="#obsah">Přejít na obsah</a>
<header class="site-header">
    <a class="site-name" href="/">Redakční systém</a>
    <p class="site-code">t360-php-agent-rs</p>
</header>
<div class="page-body">
<main id="obsah">
<?= $content /* už vykreslené HTML z šablony, ne vstup uživatele */ ?>
</main>
<aside class="sidebar" aria-label="Vyhledávání">
    <form action="/hledani" method="get" role="search" class="search-form">
        <label for="sidebar-q">Hledat v článcích</label>
        <input type="search" id="sidebar-q" name="q" maxlength="100" value="<?= e_attr($searchQuery ?? "") ?>">
        <button type="submit">Hledat</button>
    </form>
</aside>
</div>
<footer class="site-footer">
    <p>Redakční systém – výukový projekt</p>
</footer>
</body>
</html>
