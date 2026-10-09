---
name: project-m7d-priklad-10-mcp
description: M7d (plán 011) příklad 10 MCP server — ověřená fakta o mcp/sdk 0.8.1 (STDIO jen handshake éra, validace schématu v SDK, PromptGetException), rozhodnutí add() + anonymní třídy
metadata:
  type: project
---

- T2+T5 hotovo 2026-10-09: `StatisticsTool`, `Example10McpServer`, prompt `10-suggest-article.md`, `src/Mcp/NewsroomMcpServer.php`, `McpServerCommand`, větev 10 v `AiExampleCommand`, `docs/ai-priklady/10.md`. Stránka `/admin/ai/10` dělá programator (5 testů AdminMcpServerPageTest zůstává červených do té doby).
- SDK 0.8.1 fakta: `StdioTransport` nese jen handshake éru (`server/discover` → -32600 bez `id`, AC 13 nesplnitelné na STDIO); `close()` zavírá předané proudy (přepsáno anonymní podtřídou); SDK validuje argumenty proti `inputSchema` před handlerem (-32602, ne `isError`); vlastní zprávu promptu vrací jen `PromptGetException`; řádek > maxLineBytes se tiše zahodí.
- Adaptér používá `Builder::add(Tool|Prompt, *HandlerInterface)` s anonymními třídami (jediný soubor se `use Mcp\`), ne `addTool(closure)` — popis argumentu promptu a obecná smyčka nad `AgentTool`.
- `McpSourceRulesTest` zakazuje řetězec `Session` i v komentářích a v názvech metod (`handleSessionEnd`) → `close()` je prázdné.
- DB spojení se otevírá při startu (kontejner staví PDO při autowiringu), ne líně jako říká plán.
- Živý test v Claude Code (AC 23) neproběhl; riziko: id-less chyba na `server/discover` u Claude Code v2.

- Revize T7 (V1, 2026-10-09): rozsah `local` platí pro VŠECHNY relace v adresáři projektu (ne jen pro člověka) → registrace v prázdném `~/redakce-mcp` (`CONNECT_COMMAND` 3 řádky, `$KOREN`), varování `USAGE_WARNING` na stránce, druhý TextContent `CONTENT_NOTICE` u hledej_clanky/nacti_clanek. Deny pravidlo `mcp__redakce` v settings.json = návrh pro člověka, agent ho neprovádí.

**Why:** navazující práce (úprava AC, živý test, případný PSR logger pro zahozené řádky) vědět, co je ověřené a co ne.
**How to apply:** při přechodu na mcp/sdk 0.9 počítat s úpravou jen `src/Mcp/NewsroomMcpServer.php`.
