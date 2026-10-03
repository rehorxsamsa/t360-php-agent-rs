<?php
/**
 * @var string $csrfToken
 * @var string $email
 * @var string $error
 * @var string $flash
 */
?>
<h1>Přihlášení do administrace</h1>
<?php if ($flash !== '') : ?>
<p class="notice" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?php if ($error !== '') : ?>
<p class="form-error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
<form method="post" action="/admin/prihlaseni">
    <?= csrf_field($csrfToken) ?>
    <p>
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" value="<?= e_attr($email) ?>" autocomplete="username" required>
    </p>
    <p>
        <label for="password">Heslo</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
    </p>
    <p><button type="submit">Přihlásit se</button></p>
</form>
