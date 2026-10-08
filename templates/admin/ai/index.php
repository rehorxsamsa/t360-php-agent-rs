<?php
/**
 * Administrace: přehled AI nástrojů – poskytovatel, modely, dnešní spotřeba, příklady a poslední volání.
 *
 * @var \App\Ai\AiProvider $provider
 * @var array{text: string, cheap: string} $models
 * @var \App\Domain\Ai\AiUsageTotals $today
 * @var int $dailyLimit
 * @var list<\App\Ai\Examples\ExampleDescription> $examples příklady 01–08 (ExampleRegistry::listing())
 * @var list<\App\Domain\Ai\AiCall> $recentCalls
 * @var string $csrfToken
 */
?>
<h1>AI nástroje</h1>
<p>Poskytovatel: <?= e($provider->label()) ?></p>
<p>Modely: <code><?= e($models['text']) ?></code> (texty), <code><?= e($models['cheap']) ?></code> (levnější)</p>
<p class="ai-usage">Dnes: <?= e(czech_number($today->calls)) ?> volání, <?= e(czech_number($today->tokens)) ?> z <?= e(czech_number($dailyLimit)) ?> tokenů, <?= e(czech_number($today->costUsd, 6)) ?> USD</p>

<h2>Příklady</h2>
<ul class="ai-examples">
<?php foreach ($examples as $example) : ?>
    <li>
        <a href="/admin/ai/<?= e_attr($example->id()) ?>"><?= e($example->id()) ?> – <?= e($example->title()) ?></a>
        <p class="field-hint"><?= e($example->description()) ?></p>
    </li>
<?php endforeach; ?>
</ul>

<h2>Poslední volání</h2>
<?php if ($recentCalls === []) : ?>
<p>Zatím žádná volání.</p>
<?php else : ?>
<div class="table-wrapper">
<table class="admin-table ai-calls">
    <caption class="visually-hidden">Posledních 20 volání AI</caption>
    <thead>
        <tr>
            <th scope="col">Čas</th>
            <th scope="col">Příklad</th>
            <th scope="col">Poskytovatel</th>
            <th scope="col">Model</th>
            <th scope="col" class="number">Tokeny vstup</th>
            <th scope="col" class="number">Tokeny výstup</th>
            <th scope="col" class="number">Cena (USD)</th>
            <th scope="col" class="number">Trvání (ms)</th>
            <th scope="col">Stav</th>
        </tr>
    </thead>
    <tbody>
<?php foreach ($recentCalls as $call) : ?>
        <tr>
            <td><time datetime="<?= e_attr($call->createdAt->format(DATE_ATOM)) ?>"><?= e(czech_date($call->createdAt) . ' ' . $call->createdAt->format('H:i')) ?></time></td>
            <td><?= e($call->exampleId) ?></td>
            <td><?= e($call->provider) ?></td>
            <td><?= e($call->model) ?></td>
            <td class="number"><?= e(czech_number($call->usage->input)) ?></td>
            <td class="number"><?= e(czech_number($call->usage->output)) ?></td>
            <td class="number"><?= e(czech_number($call->costUsd, 6)) ?></td>
            <td class="number"><?= e(czech_number($call->durationMs)) ?></td>
            <td><?= e(match (true) {
                $call->status !== \App\Domain\Ai\AiCallStatus::Ok => sprintf('Chyba (%s)', $call->errorType ?? 'neznámá'),
                $call->stopReason === 'aborted' => 'Přerušeno',
                default => 'OK',
            }) ?></td>
        </tr>
<?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<p class="actions"><a href="/admin">Zpět do administrace</a></p>
<form method="post" action="/admin/odhlaseni">
    <?= csrf_field($csrfToken) ?>
    <p><button type="submit">Odhlásit se</button></p>
</form>
