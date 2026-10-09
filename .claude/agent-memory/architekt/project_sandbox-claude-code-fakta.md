---
name: project-sandbox-claude-code-fakta
description: Ověřená fakta o sandboxu Claude Code (bubblewrap, WSL2, Docker, klíče settings) a nálezy prostředí t360 z ADR-0012 (2026-10-09)
metadata:
  type: project
---

Ověřeno 2026-10-09 (code.claude.com/docs/en/sandboxing, settings-reference, sandbox-environments; context7 `/anthropics/sandbox-runtime`):
- Sandbox kryje jen Bash/PowerShell/Monitor + potomky. Read/Edit/Write/WebFetch, hooky, MCP servery a příkazy `!` běží MIMO.
- Docker je se sandboxem nekompatibilní → `sandbox.excludedCommands`; vyjmutý příkaz běží s plným přístupem. Vyjmutí jen když
  vzoru odpovídá každý příkaz volání; `cd`, přesměrování, `$(…)`, `eval`/`sudo`/`xargs`, název z proměnné zůstávají v sandboxu;
  cíl `make` volající docker vzoru `docker …` neodpovídá.
- Linux: Unix sockety blokuje jen volitelný seccomp filtr (`apply-seccomp` z npm balíčku); bez něj jsou neomezené.
  `allowUnixSockets` Linux ignoruje. `failIfUnavailable` hlídá jen bwrap/socat.
- `allowUnsandboxedCommands: false` (strict) v `--settings` = admin-required → ignoruje `excludedCommands` z repa.
  `strictAllowlist`, `filesystem.disabled`, `tlsTerminate` v projektu nefungují. Na WSL2 je `localhost` sandboxu soukromý.
- Chráněné cesty sandboxu nepokrývají vlastní `core.hooksPath` (t360 má `.githooks`).

Nálezy t360: `settings.local.json` má `bypassPermissions` + `Bash(docker compose *)` (allowlist tak není hranice);
`chran-soubory.sh` je vypnutý (`exit 0`); Docker Desktop s integrací WSL.

**Why:** podklad ADR-0012 (stav navrženo); bez těchto faktů tým navrhne „sandbox“, který Docker nebo bypass obejde.

**How to apply:** při návrzích kolem `.claude/settings*`, hooků, Dockeru a MCP vycházej z ADR-0012; nikdy nedoporučuj
`excludedCommands: docker *` ani `allowAllUnixSockets`. Ověř aktuální stav nastavení, mohl se změnit. Viz [[project-mcp-fakta]].
