---
name: project-pravidlo0-docker
description: Rozhodnutí člověka (2026-10-03) — vše výhradně v Dockeru včetně MCP serverů; co smí být na hostiteli; navazující ADR-0002
metadata:
  type: project
---

„Pravidlo 0“ (rozhodl člověk, 2026-10-03): vše se provádí, vidí a kontroluje jen v Dockeru.
Na hostiteli nesmí být PHP ani Node/npm/npx; povoleno docker, git, bash, jq. Zda smí být i `make`
a `curl`, je otevřená otázka 2 v docs/plan/001-docker-zaklad.md (doporučil jsem ano).

**Why:** workspace pravidlo (/home/q/projects/CLAUDE.md) a reprodukovatelnost čistého klonu;
MCP přes `npx @latest` bylo i riziko dodavatelského řetězce.

**How to apply:** v každém plánu ověř, že žádný krok nevyžaduje hostitelský runtime. MCP:
context7 jako vzdálený HTTP, Playwright a mariadb-cteni jako služby compose v profilu `mcp`,
spouštěné `docker compose -f … run --rm -T <služba>`; prohlížeč otevírá aplikaci na `http://web`.
Hooky selhávají viditelně (fail-closed), žádný fallback na hostitelské `php`.
Viz ADR-0002 (stav k 2026-10-03: navrženo). Souvisí [[project-nazvy-anglicky]], [[project-subagent-tools-mcp]].
