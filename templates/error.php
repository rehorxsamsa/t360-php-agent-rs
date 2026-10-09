<?php
/**
 * @var int $status
 * @var string $message
 * @var string $title
 * @var string|null $backPath volitelný odkaz zpět (interní cesta), výchozí titulní stránka
 * @var string|null $backLabel text odkazu zpět
 */
$backPath ??= '/';
$backLabel ??= 'Zpět na titulní stránku';
?>
<h1><?= e($title) ?></h1>
<p class="error-status">Chyba <?= e($status) ?></p>
<p><?= e($message) ?></p>
<p><a href="<?= e_attr($backPath) ?>"><?= e($backLabel) ?></a></p>
