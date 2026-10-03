<?php
/**
 * @var int $status
 * @var string $message
 * @var string $title
 */
?>
<h1><?= e($title) ?></h1>
<p class="error-status">Chyba <?= e($status) ?></p>
<p><?= e($message) ?></p>
<p><a href="/">Zpět na titulní stránku</a></p>
