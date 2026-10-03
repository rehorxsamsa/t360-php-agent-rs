---
name: project-subagent-tools-mcp
description: Pasti konfigurace agentů — allowlist tools vyřadí MCP nástroje; relace spuštěná z .claude/ nenačte projektové hooky; Bash(docker compose *) je de facto root
metadata:
  type: project
---

Zjištěno při plánu 001 (2026-10-03):
1. Subagent s polem `tools:` (allowlist) nedostane MCP nástroje, které v něm nejsou
   (`mcp__<server>`), ani když má server inline v `mcpServers`. Tester a databazista to měli špatně.
2. Hlavní relace běžela s cwd `…/t360-php-agent-rs/.claude` → projektový adresář = `.claude`,
   paměť agentů padá do `.claude/.claude/agent-memory/` a `.claude/settings.json` (hooky,
   permissions) se nenačte. Claude Code se musí spouštět z kořene repa.
3. `Bash(docker compose *)` v allow umožní `docker compose run -v /:/host` (root na hostiteli);
   `docker compose config` a `docker inspect` vypíšou hesla z `.env`; `make composer ARGS="require …"`
   obchází `ask` pravidlo pro nové závislosti.

**Why:** pojistky z ADR-0001 („hooky a permissions jsou zákon“) jinak tiše neplatí.

**How to apply:** při každé změně agentů/MCP/permissions tyto body zkontroluj a uveď v rizicích plánu;
pokud paměť najdeš jen v `.claude/.claude/agent-memory/`, relace stále běží z nesprávného adresáře.
