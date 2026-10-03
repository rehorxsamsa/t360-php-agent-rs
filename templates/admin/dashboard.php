<?php
/**
 * @var string $csrfToken
 * @var string $userName
 */
?>
<h1>Administrace</h1>
<p>Přihlášen(a) jako <?= e($userName) ?></p>
<p>Správa článků přibude v dalším milníku.</p>
<form method="post" action="/admin/odhlaseni">
    <?= csrf_field($csrfToken) ?>
    <p><button type="submit">Odhlásit se</button></p>
</form>
