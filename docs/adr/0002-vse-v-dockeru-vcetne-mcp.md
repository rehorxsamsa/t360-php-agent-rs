# ADR-0002: Vše v Dockeru včetně MCP serverů („pravidlo 0“)
- **Stav:** přijato
- **Datum:** 2026-10-03
- **Autor:** agent architekt (rozhodnutí „pravidlo 0“ učinil člověk, tento ADR ho zpřesňuje)

## Kontext
Člověk (product owner) rozhodl: **vše se provádí, vidí a kontroluje výhradně v Dockeru.**
Na hostiteli není a nesmí být PHP ani Node/npm. Pravidlo platí pro celý workspace
(`/home/q/projects/CLAUDE.md`) a nově i pro nástroje agentů.

Výchozí sada agentního týmu s tím na několika místech nesouhlasí:
- `.mcp.json` spouští `context7` přes `npx` na hostiteli.
- `tester` (Playwright) a `databazista` (mariadb-cteni) mají inline MCP servery přes `npx`.
- `START-ZDE.md` instaluje Node.js 22 na hostitele.
- Hook `php-lint.sh` má záložní větev `php -l` na hostiteli; `rychla-kontrola.sh` a git hook
  `pre-commit` při neběžícím kontejneru **tiše projdou** (kontrola se neprovede a nikdo to nevidí).
- `settings.json` povoluje `docker compose *` a jen se ptá na `npm install *` – hostitelské
  `php`/`npx` nic neblokuje.
- Subagenti `tester` a `databazista` mají v `tools:` allowlist bez MCP nástrojů; podle dokumentace
  Claude Code subagent s allowlistem MCP nástroje **nedostane**, dokud nejsou v `tools` uvedené
  (`mcp__<server>`). Prohlížeč ani čtení DB tedy dnes reálně nefungují.

Workspace pravidla dále požadují `container_name: <služba>-t360`, web na `8080:80`,
Adminer na `8081`, větev `main`, verzované zámky a naklonovatelnost podle README.

## Rozhodnutí
Hostitel má jen **orchestraci**, nikdy běhová prostředí jazyků; vše ostatní běží v kontejnerech
projektu definovaných v `compose.yaml`.

1. **Hostitel smí mít:** `docker` (+ plugin compose), `git`, `bash`, `jq` (hooky) a — pokud člověk
   schválí (viz otázky v plánu 001) — `make` a `curl`. Nikdy `php`, `composer`, `node`, `npm`, `npx`.
2. **MCP servery:**
   - `context7` → vzdálený HTTP server `https://mcp.context7.com/mcp` (nic se lokálně nespouští,
     žádný Node). Záložní varianta: služba `mcp-context7` v compose (vlastní malý obraz).
   - `playwright` → služba `mcp-playwright` v `compose.yaml` (profil `mcp`, oficiální obraz
     `mcr.microsoft.com/playwright/mcp` s pevnou verzí), spouštěná jako stdio přes
     `docker compose -f … run --rm -T mcp-playwright`. Prohlížeč je v síti compose a aplikaci
     otevírá na `http://web` (ne `localhost:8080`).
   - `mariadb-cteni` → služba `mcp-mariadb` (profil `mcp`, vlastní obraz `docker/mcp/mariadb/`
     z `node:<LTS>-alpine` s **pinnutou** verzí `@benborla29/mcp-server-mysql`), připojuje se na
     `db:3306` uživatelem `redakce_cteni` (jen `SELECT`). Heslo bere compose z `.env` —
     odpadá jeho kopie v `.claude/settings.local.json`.
   - MCP servery zůstávají inline ve frontmatteru agentů (nezatěžují kontext hlavní relace);
     agenti je musí mít uvedené v `tools:` (`mcp__playwright`, `mcp__mariadb-cteni`).
3. **Hooky selhávají viditelně:** žádný fallback na hostitelský `php`. Když kontejner `app`
   neběží, `php-lint` to Claudovi oznámí a `rychla-kontrola` / `pre-commit` **zablokují**
   s návodem `make up` (fail-closed s pojistkou max. 3 opakování u rychlé kontroly).
4. **Permissions:** `deny` pro hostitelské `php`, `composer`, `node`, `npm`, `npx`;
   `bash-strazce.sh` totéž vynucuje i v řetězených příkazech a navíc blokuje
   `git commit --no-verify` a `docker compose config` bez `--quiet`/`--no-interpolate`
   (výpis by obsahoval hesla z `.env`).
5. **Rozšíření nasazovacího kontraktu (`devops-kontrakt`) pro dev** — nic ze stávajícího se nemění,
   jen přibývá:
   - top-level `name: t360` v `compose.yaml` (stabilní název sítě `t360_default` a volumes),
   - `container_name: <služba>-t360` u každé služby dev compose (`app-t360`, `web-t360`, `db-t360`,
     `adminer-t360`, `mcp-playwright-t360`, `mcp-mariadb-t360`),
   - služba `adminer` (jen dev, `127.0.0.1:8081:8080` — viz otázka v plánu),
   - služby profilu `mcp` (viz bod 2).
   `compose.prod.yaml` (M9) se tímto ADR **nemění** (`name: redakce`, bez `container_name`).

## Důsledky
+ Čistý stroj potřebuje jen Docker, git, bash, jq (a make/curl) — README platí doslova.
+ Verze nástrojů agentů (Playwright, MySQL MCP) jsou pinnuté a verzované v repu, ne `@latest` z npx.
+ Jediný zdroj hesel je `.env`; agent ho nečte, compose ho jen interpoluje do kontejnerů.
+ Kontroly, které „tiše neproběhly“, už nemohou vypadat jako úspěch.
− První spuštění MCP stahuje velký obraz Playwright (stovky MB) → nutný krok `make mcp`
  (předstažení), jinak hrozí timeout startu MCP.
− E2E testy používají `http://web`, ne `http://localhost:8080`; aplikace nesmí generovat
  absolutní URL z pevného `APP_URL` bez ohledu na Host (relevantní od M2).
− `docker compose run` ignoruje `container_name` (generuje `t360-<služba>-run-<id>`) — pravidlo
  pojmenování u služeb profilu `mcp` platí jen pro `up`. Díky tomu ale může běžet více instancí
  (např. dva paralelní testeři) bez konfliktu.
− Kdo má přístup k Docker socketu, má fakticky práva roota na hostiteli. Allowlist
  `Bash(docker compose *)` je proto příliš široký (`docker compose run -v /:/host …`) — plán 001
  navrhuje jeho zúžení.
− Změny `.claude/settings.json`, `.claude/hooks/*`, `.mcp.json`, `.claude/agents/*` a skillu
  `devops-kontrakt` vyžadují souhlas člověka.

## Zvažované alternativy
- **Node.js na hostiteli, MCP přes `npx`** (původní stav) — porušuje pravidlo 0, `@latest` je
  riziko dodavatelského řetězce a výsledky nejsou reprodukovatelné.
- **MCP přes `docker run -i --rm …` přímo v `.mcp.json`** — funguje, ale cesty k volume a název
  sítě by musely být natvrdo v JSON a heslo DB by se muselo předávat přes prostředí Claude Code
  (`settings.local.json`). Služby v compose mají relativní cesty, síť a `.env` zadarmo.
- **Docker MCP Toolkit / MCP Gateway (Docker Desktop)** — závisí na Docker Desktop (ne na Docker
  Engine ve WSL/VPS) a konfiguraci mimo repo; projekt by nebyl naklonovatelný.
- **Playwright MCP jako trvale běžící HTTP služba (port 8931)** — další otevřený port a sdílený
  prohlížeč mezi relacemi; stdio přes `compose run` je izolovanější.
- **Bez hostitelského `make`, jen `./bin/…` bash skripty** — AGENTS.md, kontrakt, tutoriál
  i permissions počítají s `make`; změna by byla velká a bez přínosu. Rozhoduje člověk.
