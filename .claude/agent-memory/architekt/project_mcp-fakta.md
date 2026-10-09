---
name: project-mcp-fakta
description: Ověřená fakta o MCP pro t360 (2026-10-09, plán 011/ADR-0011) — mcp/sdk 0.8.1, éry protokolu 2025-11-25 vs 2026-07-28, claude mcp add, dělení argumentů promptu, past STDIO stdout
metadata:
  type: project
---

Ověřeno 2026-10-09 (packagist, GitHub modelcontextprotocol/php-sdk, context7 `/modelcontextprotocol/php-sdk`, modelcontextprotocol.io,
code.claude.com/docs/en/mcp) pro plán 011 (M7d, příklad 10), ADR-0011 navrženo:
- `mcp/sdk` v0.8.1 (29. 8. 2026), PHP ^8.1, Apache-2.0/MIT, **experimentální 0.x — každá minor láme** → `^0.8.1`. ~18 balíčků,
  `php-http/discovery` je Composer plugin (neinteraktivní install bez `allow-plugins` selže → nastavit `false`), potřebuje `ext-fileinfo`.
  API: `Server::builder()->setServerInfo()->addTool(handler, name, title, description, annotations, inputSchema)->addPrompt(...)->build()
  ->run(new StdioTransport($in, $out, …, maxLineBytes))` (0 po EOF, jde testovat přes `php://memory`); argumenty mapuje na parametry closure podle jména.
- Protokol: `2026-07-28` je bezstavový (bez `initialize`, povinné `server/discover`, bez `ping`, `resultType`); „legacy“ = `2025-11-25`.
  Claude Code je dvouérový (`MCP_PROTOCOL_NEGOTIATION=auto|legacy`), legacy-only server zatím funguje.
- Claude Code: `claude mcp add [volby] <název> -- <příkaz…>`, `--scope local` (`~/.claude.json`) / `project` (`.mcp.json`) / `user`;
  prompt `/mcp__srv__prompt a b` **dělí argumenty mezerami**; varování > 10k tokenů výstupu; property names ASCII 1–64, schéma 2020-12
  (`"properties":{}` musí být objekt — PHP `new \stdClass`); popisy uřezává na 2 048 znaků; stdio idle 30 min.
- Repo: `redakce_cteni` má `MAX_USER_CONNECTIONS 3` a 2 000 dotazů/h a sdílí ho `mcp-mariadb` agentů → pro další MCP server nepoužívat.

**Why:** protokol i SDK se mění po měsících; bez toho by plán sliboval nefunkční API nebo neověřitelná AC.

**How to apply:** u M7d a navazujících MCP úkolů vycházej z ADR-0011; před implementací nech `ai-inzenyr` ověřit SDK nad `vendor/`.
Odpověď vedoucímu: model je v klientovi (žádné `ai_calls`). Viz [[project-ai-api-fakta]], [[project-ai-zapis-llm06]], [[project-vyukovy-rezim]].
