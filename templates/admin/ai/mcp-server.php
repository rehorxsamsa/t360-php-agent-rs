<?php
/**
 * Administrace: AI příklad 10 – MCP server redakce (plán 011, ADR-0011). Jen informační stránka bez formuláře:
 * návod k připojení z Claude Code, nástroje se vstupními schématy a náhled promptu. Nic nevolá a nic nezapisuje.
 * Příkaz, schémata i prompt jsou v <pre> jen přes e() (uvozovky v příkazu i značka <tema> se escapují).
 *
 * @var \App\Ai\Examples\Example10McpServer $example
 * @var string $connectCommand registrace (tři řádky shellu), v <pre> jen přes e()
 * @var string $listCommand
 * @var string $connectDirectory
 * @var string $usageWarning
 * @var list<array{name: string, description: string, schema: string}> $tools schéma = JSON (JSON_PRETTY_PRINT)
 * @var string $promptName
 * @var string $promptDescription
 * @var string $promptArgument
 * @var string $promptArgumentDescription
 * @var string $slashCommand
 * @var string $demoTopic
 * @var string $promptPreview text promptu pro ukázkové téma
 * @var string $instructions instrukce serveru pro klienta
 * @var string $csrfToken
 */
?>
<h1><?= e($example->id()) ?> – <?= e($example->title()) ?></h1>
<p><?= e($example->description()) ?></p>
<p class="notice" role="note">Server jen čte publikované články a nic nezapisuje. Model běží v Claude Code – server ani tato stránka žádné AI API nevolají.</p>

<section class="mcp-section" aria-labelledby="pripojeni">
    <h2 id="pripojeni">Připojení</h2>
    <p class="form-error" role="note"><?= e($usageWarning) ?></p>
    <p>V kořeni projektu (běží <code>make up</code>) zaregistrujte server v Claude Code. Server komunikuje přes STDIO: Claude Code ho spustí příkazem v kontejneru <code>app</code>.</p>
    <p>Registrace v rozsahu <code>local</code> platí pro všechny relace Claude Code spuštěné v daném adresáři, tedy i pro relace s automaticky povoleným Bashem. Proto se server zaregistruje v samostatném prázdném adresáři <code><?= e($connectDirectory) ?></code>, kde relace nedědí nastavení tohoto repozitáře:</p>
    <pre class="mcp-code"><code><?= e($connectCommand) ?></code></pre>
    <p>Ověření, že je server připojený (spouští se z tohoto adresáře):</p>
    <pre class="mcp-code"><code><?= e($listCommand) ?></code></pre>
    <p class="field-hint">Spouštějte Claude Code z <?= e($connectDirectory) ?>, ne z kořene tohoto repozitáře: relace spuštěné v tomto adresáři vidí server, ostatní relace (včetně týmu agentů v repozitáři) ne. V Claude Code ukáže stav serveru i příkaz <code>/mcp</code>.</p>
    <h3>Instrukce serveru pro model</h3>
    <pre class="mcp-code mcp-text"><?= e($instructions) ?></pre>
</section>

<section class="mcp-section" aria-labelledby="nastroje">
    <h2 id="nastroje">Nástroje</h2>
    <p>Model v Claude Code volá nástroje sám podle jejich popisu. Všechny jen čtou publikované články.</p>
<?php foreach ($tools as $tool) : ?>
    <h3><?= e($tool['name']) ?></h3>
    <p><?= e($tool['description']) ?></p>
    <p class="field-hint">Vstupní schéma (JSON Schema):</p>
    <pre class="mcp-code"><?= e($tool['schema']) ?></pre>
<?php endforeach; ?>
</section>

<section class="mcp-section" aria-labelledby="prompt">
    <h2 id="prompt">Prompt</h2>
    <h3><?= e($promptName) ?></h3>
    <p><?= e($promptDescription) ?></p>
    <p>Argument <code><?= e($promptArgument) ?></code>: <?= e($promptArgumentDescription) ?></p>
    <p>V Claude Code se prompt vyvolá jako příkaz:</p>
    <pre class="mcp-code"><code><?= e($slashCommand) ?></code></pre>
    <p>Náhled textu promptu pro téma „<?= e($demoTopic) ?>“ (téma je v promptu jako data ve značce, ne jako pokyn):</p>
    <pre class="mcp-code mcp-text"><?= e($promptPreview) ?></pre>
</section>

<p class="actions"><a href="/admin/ai">Zpět na AI nástroje</a></p>
<form method="post" action="/admin/odhlaseni">
    <?= csrf_field($csrfToken) ?>
    <p><button type="submit">Odhlásit se</button></p>
</form>
