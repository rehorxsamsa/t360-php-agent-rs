<?php
/**
 * Administrace: audit log (jen čtení) s filtrem podle akce a data a stránkováním po 50.
 * Vše od uživatele (shrnutí, jméno, hodnoty filtru) jde přes e()/e_attr().
 *
 * @var array<string, string> $query surové hodnoty filtru (`akce`, `od`, `do`)
 * @var array<string, string> $errors pole => česká hláška (neplatný filtr, 422)
 * @var list<\App\Domain\Audit\AuditAction> $actions
 * @var \App\Application\Audit\AuditLogPage|null $page null, když je filtr neplatný
 * @var bool $filtered výsledek je zúžený filtrem
 * @var string|null $previousUrl
 * @var string|null $nextUrl
 */
?>
<h1>Audit log</h1>
<?php if ($errors !== []) : ?>
<div class="form-error audit-errors" role="alert">
    <p>Filtr nelze použít, opravte prosím chyby:</p>
    <ul>
<?php foreach ($errors as $field => $message) : ?>
        <li id="<?= e_attr($field) ?>-error"><?= e($message) ?></li>
<?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<form method="get" action="/admin/audit" class="audit-filter">
    <div class="field">
        <label for="akce">Akce</label>
        <select name="akce" id="akce"<?php if (isset($errors['akce'])) : ?> aria-invalid="true" aria-describedby="akce-error"<?php endif; ?>>
            <option value="">Všechny akce</option>
<?php foreach ($actions as $action) : ?>
            <option value="<?= e_attr($action->value) ?>"<?php if ($query['akce'] === $action->value) : ?> selected<?php endif; ?>><?= e($action->label()) ?></option>
<?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="od">Od</label>
        <input type="date" name="od" id="od"<?php if ($query['od'] !== '') : ?> value="<?= e_attr($query['od']) ?>"<?php endif; ?><?php if (isset($errors['od'])) : ?> aria-invalid="true" aria-describedby="od-error"<?php endif; ?>>
    </div>
    <div class="field">
        <label for="do">Do</label>
        <input type="date" name="do" id="do"<?php if ($query['do'] !== '') : ?> value="<?= e_attr($query['do']) ?>"<?php endif; ?><?php if (isset($errors['do'])) : ?> aria-invalid="true" aria-describedby="do-error"<?php endif; ?>>
    </div>
    <p class="actions">
        <button type="submit">Filtrovat</button>
        <a href="/admin/audit">Zrušit filtr</a>
    </p>
</form>
<?php if ($page !== null) : ?>
<?php if ($page->records === []) : ?>
<p><?= e($filtered ? 'Filtru neodpovídá žádný záznam.' : 'Audit log je zatím prázdný.') ?></p>
<?php else : ?>
<p>Počet záznamů: <?= e(czech_number($page->total)) ?></p>
<div class="table-wrapper">
<table class="admin-table audit-table">
    <caption class="visually-hidden">Záznamy audit logu, nejnovější první</caption>
    <thead>
        <tr>
            <th scope="col">Čas</th>
            <th scope="col">Akce</th>
            <th scope="col">Uživatel</th>
            <th scope="col">Objekt</th>
            <th scope="col">Shrnutí</th>
            <th scope="col">IP adresa</th>
        </tr>
    </thead>
    <tbody>
<?php foreach ($page->records as $record) : ?>
        <tr>
            <td><time datetime="<?= e_attr($record->createdAt->format(DATE_ATOM)) ?>"><?= e(czech_date($record->createdAt) . ' ' . $record->createdAt->format('H:i:s')) ?></time></td>
            <td><?= e($record->actionLabel()) ?></td>
            <td><?= e($record->userName ?? '—') ?></td>
            <td><?= e($record->entityLabel()) ?></td>
            <td><?= e($record->summary) ?></td>
            <td><?= e($record->ipAddress ?? '—') ?></td>
        </tr>
<?php endforeach; ?>
    </tbody>
</table>
</div>
<nav aria-label="Stránkování" class="pagination">
<?php if ($previousUrl !== null) : ?>
    <a href="<?= e_attr($previousUrl) ?>" rel="prev">Předchozí strana</a>
<?php endif; ?>
    <span aria-current="page">Strana <?= e($page->page) ?> z <?= e($page->totalPages) ?></span>
<?php if ($nextUrl !== null) : ?>
    <a href="<?= e_attr($nextUrl) ?>" rel="next">Další strana</a>
<?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
<p><a href="/admin">Zpět do administrace</a></p>
