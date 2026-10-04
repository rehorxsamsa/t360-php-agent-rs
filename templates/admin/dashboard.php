<?php
/**
 * @var string $csrfToken
 * @var string $userName
 */
?>
<h1>Administrace</h1>
<p>Přihlášen(a) jako <?= e($userName) ?></p>
<nav aria-label="Správa obsahu">
    <ul>
        <li><a href="/admin/clanky">Články</a></li>
        <li><a href="/admin/ai">AI nástroje</a></li>
        <li><a href="/admin/audit">Audit log</a></li>
    </ul>
</nav>
<form method="post" action="/admin/odhlaseni">
    <?= csrf_field($csrfToken) ?>
    <p><button type="submit">Odhlásit se</button></p>
</form>
