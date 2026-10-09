# ADR-0012: Sandbox na úrovni OS pro relace Claude Code – hranice hostitele pro Bash, Docker jen přes úzké výjimky a chráněné definice kontejnerů
- **Stav:** navrženo (schvaluje člověk – mění pracovní prostředí týmu; změny `.claude/settings.json` a hooků provádí člověk)
- **Datum:** 2026-10-09
- **Autor:** agent architekt (rozhodnutí zadal product owner)
- **Souvisí:** [STAV.md – Známá rizika](../plan/STAV.md), [ADR-0001](0001-vyvoj-tymem-agentu.md) (tým agentů),
  [ADR-0002](0002-vse-v-dockeru-vcetne-mcp.md) (vše v Dockeru vč. MCP), [ADR-0011](0011-mcp-server-redakce-sdk-a-stdio.md)
  (MCP server redakce, izolace adresářem), `.claude/settings.json`, `.claude/settings.local.json`, `.claude/hooks/`, `Makefile`,
  `compose.yaml`

## Kontext
Bezpečnost relací Claude Code v repu dnes stojí na dvou vrstvách:
1. **Hooky** v `.claude/hooks/` (`bash-strazce.sh` analyzuje text příkazu) – pohodlná první linie, ale analýza řetězce se dá
   obejít a hook **selhává otevřeně** (chyba nebo timeout = příkaz projde podle oprávnění). `chran-soubory.sh` je navíc v demo
   režimu vypnutý (`exit 0`).
2. **Allowlist** v `.claude/settings.json` (`permissions.allow/ask/deny`) – také jen porovnání textu příkazu před spuštěním.

Čtyři kola revize skončila u rizik, která obě vrstvy z principu neuzavřou (viz `docs/plan/STAV.md`):

| ID | Riziko |
|---|---|
| S3 | `cat <<EOF … EOF \| bash` spustí libovolný kód |
| S4 | zápis souborů přes interpretery (`python -c`, `perl -e` …) obejde kontrolu chráněných souborů |
| N4, N5 | okrajová obejití pravidel podle kontextu (kolo 3) |
| – | interpretery obecně (`python`, `perl`, `ruby`, `awk`, `sh`) |
| – | hooky selhávají otevřeně |
| – | `bash -c '…'` / `eval` se neanalyzuje |
| – | `docker compose -f jiny.yaml …` obejde `-f compose.yaml` z Makefile |
| – | `COMPOSE_FILE` v `.env` (compose ho načítá automaticky) |
| R4-4 | přesměrování `>`/`>>` mimo repo (např. `~/.bashrc`) |
| – | MCP (`context7`, `playwright`) jako výstupní kanál bez dotazu |

**Skutečný stav na tomto stroji je horší, než předpokládá STAV.md:** lokální (neverzovaný) `.claude/settings.local.json` má
`"defaultMode": "bypassPermissions"` a v `allow` mimo jiné `Bash(docker compose *)` a `Bash(docker volume *)`. V režimu
`bypassPermissions` se neptá nic (ani na chráněné cesty), takže allowlist dnes **není hranice vůbec** – zbývají jen hooky.

### Ověřená fakta (2026-10-09: code.claude.com/docs/en/sandboxing, /settings-reference, /sandbox-environments; context7 `/anthropics/sandbox-runtime`)
- **Co sandbox omezuje:** jen příkazy nástrojů Bash/PowerShell/Monitor a jejich potomky. **Mimo sandbox běží** nástroje
  Read/Edit/Write/WebFetch/WebSearch (řídí je jen `permissions`), hooky, lokální MCP servery, status line a příkazy, které
  člověk napíše s prefixem `!`. `denyRead` nezastaví nástroj Read, `allowedDomains` neomezí WebFetch.
- **Linux/WSL2:** `bubblewrap` (izolace FS) + `socat` (most do proxy); jen WSL2, ne WSL1. Ubuntu 24.04+ může vyžadovat profil
  AppArmor pro `bwrap` (`sysctl kernel.apparmor_restrict_unprivileged_userns`). Volitelný **seccomp filtr** (statický
  `apply-seccomp` z balíčku `@anthropic-ai/sandbox-runtime`, instalace `npm install -g …`, pokud chybí) blokuje
  `socket(AF_UNIX, …)`. **Bez seccomp filtru jsou Unix sockety neomezené** (jen varování). `failIfUnavailable` hlídá jen
  povinné závislosti, ne seccomp.
- **Výchozí FS:** zápis jen do pracovního adresáře, per-user temp a `--add-dir`; čtení skoro všude včetně `~/.ssh`.
  Klíče: `sandbox.filesystem.allowWrite|denyWrite|denyRead|allowRead` (cesty: `/abs`, `~/…`, `./…` = kořen projektu
  v projektovém nastavení). Na Linuxu `allowWrite/denyWrite` berou jen doslovné cesty, `denyRead` umí glob s doslovným
  začátkem. `permissions.blockReadsOutsideWorkingDirectories: true` (od v2.1.257) zakáže sandboxovaným příkazům čtení
  `/home`, `/root`, `/mnt`, `/media`, `/srv` … kromě pracovních adresářů a nutných částí `~/.claude`.
  `Read(...)`/`Edit(...)` pravidla z `permissions` se do konfigurace sandboxu **slučují** (`Read(./.env)` v `deny` = `denyRead`).
- **Chráněné cesty (nelze vyjmout):** `.claude/settings*.json`, `.claude/skills|agents|commands|hooks`, `.mcp.json`,
  `.bashrc`/`.zshrc`, `.gitconfig`, `.git/hooks`, `.git/config`, většina `~/.claude` a `~/.claude.json`. Vlastní
  `core.hooksPath` **chráněný není**.
- **Síť:** samostatný síťový namespace, ven jen přes proxy Claude Code (`HTTP(S)_PROXY`, kontrola názvu hostitele, bez
  inspekce TLS → domain fronting přes široké domény jako `github.com`). `network.allowedDomains` (výchozí prázdné),
  `deniedDomains`; `network.strictAllowlist` (jen user/managed/`--settings`, v projektu se ignoruje; od v2.1.219) = mimo
  allowlist odmítnout bez dotazu; zároveň pak Claude Code ignoruje `allowedDomains` z repa. Na Linuxu/WSL2 je `localhost`
  sandboxovaného příkazu soukromý – přímé `curl http://localhost:8080` na hostitele nedosáhne (Claude Code nastaví
  `NO_PROXY` pro localhost); přes proxy jde, `localhost` smí resolvovat na loopback. `allowUnixSockets` Linux ignoruje,
  `allowAllUnixSockets: true` vypne seccomp blokaci úplně.
- **Docker:** dokumentace výslovně: „`docker` is incompatible with the sandbox“ → vyjmout přes `sandbox.excludedCommands`.
  Vyjmutý příkaz běží **bez sandboxu s plným přístupem**; varování: když vzor pokrývá nástroj, který čte soubor v repu
  (jako `docker compose` svůj compose soubor), Claude ten soubor může přepsat a pak ho spustit mimo sandbox. Vyjmutí platí,
  jen když vzoru odpovídá **každý** příkaz volání; v sandboxu zůstane volání s `cd`, přesměrováním do souboru, `$(…)`,
  subshellem, `if/for`, názvem příkazu z proměnné, začínající `sudo`/`eval`/`xargs`; skript nebo cíl `make`, který docker
  volá uvnitř, vzoru neodpovídá. „Exclusion is a convenience, not a security boundary.“
- **Únik ze sandboxu:** `sandbox.allowUnsandboxedCommands` (výchozí `true`) dovolí modelu zopakovat příkaz s
  `dangerouslyDisableSandbox`; v `bypassPermissions` se to stane **bez dotazu** a i hosty mimo allowlist projdou bez dotazu
  (pokud není `strictAllowlist`). `false` = „strict sandbox mode“. `autoAllowBashIfSandboxed` (výchozí `true`) spouští
  sandboxované příkazy bez dotazu; `deny` a obsahová `ask` pravidla platí dál.
- **Dokumentace sama:** „Allowing access to `/var/run/docker.sock` effectively grants access to the host system.“

### Prostředí
WSL2 (jádro 5.15), `systemd=true` v `/etc/wsl.conf`, Docker Desktop s integrací WSL (`/var/run/docker-desktop-proxy.pid`),
CLI dockeru z Docker Desktopu (`/mnt/wsl/docker-desktop/…`). Zda jsou nainstalované `bwrap`, `socat` a seccomp filtr, jsem
bez shellu ověřit nemohl → krok 1 zavedení. Repo: `compose.yaml` připojuje projekt do `app` jen ke čtení s výjimkou
`src/`, `tests/Unit|Integration`, `public/`, `var/`, `vendor/`, `database/`, `bin/`, `config/`, `templates/`, `composer.*`;
`.env` → `/dev/null`; `cap_drop: ALL`, `no-new-privileges`, neprivilegovaný uživatel. `.git/config` má
`core.hooksPath = .githooks`.

## Rozhodnutí
**Zapneme vestavěný sandbox Claude Code (bubblewrap) v přísném režimu jako skutečnou hranici hostitele pro všechny
příkazy Bash: zápis jen do adresáře projektu (a per-user temp), síť jen přes proxy s minimálním allowlistem, zákaz čtení
`.env`, `~/.ssh` a mimo pracovní adresář. Docker ze sandboxu pracovat nemůže, proto jsou z něj vyjmuté jen přesně
vyjmenované tvary (`docker compose exec -T app …` a cíle `make`), jejichž účinek nezávisí na souborech, které může agent
změnit bez člověka. Hooky zůstávají první linií, allowlist druhou, sandbox je hranice.**

### Tři vrstvy a jejich role
| Vrstva | Role po zavedení | Co garantuje |
|---|---|---|
| Hooky (`.claude/hooks/`) | pohodlí, rychlá zpětná vazba, audit | nic (selhávají otevřeně) |
| `permissions` (`allow/ask/deny`) | dotazy na necílené akce, **jediná ochrana nástrojů Read/Edit/WebFetch a MCP** | to, co sandbox nekryje |
| Sandbox (OS) | hranice pro Bash a vše, co z něj vznikne | zápis, čtení, síť, Unix sockety |

### Navržená konfigurace – `.claude/settings.json` (verzované; mění člověk)
Doplnit klíč `sandbox` a rozšířit `permissions` (stávající položky zůstávají):

```json
{
  "permissions": {
    "defaultMode": "acceptEdits",
    "blockReadsOutsideWorkingDirectories": true,
    "ask": [
      "Bash(dangerouslyDisableSandbox:true)",
      "Edit(./compose.yaml)", "Edit(./compose.*.yaml)", "Edit(./docker-compose*.yml)",
      "Edit(./Makefile)", "Edit(./docker/**)", "Edit(./.githooks/**)", "Edit(./tests/Hooks/**)"
    ],
    "deny": [
      "Read(~/.ssh/**)", "Read(~/.docker/**)", "Read(~/.aws/**)", "Read(~/.config/gh/**)"
    ]
  },
  "sandbox": {
    "enabled": true,
    "failIfUnavailable": true,
    "allowUnsandboxedCommands": false,
    "autoAllowBashIfSandboxed": true,
    "excludedCommands": [
      "docker compose exec -T app composer *",
      "docker compose exec -T app php bin/konzole *",
      "docker compose exec -T app php -l *",
      "docker compose exec -T app vendor/bin/*",
      "docker compose ps*",
      "docker compose logs*",
      "make up", "make down", "make check", "make test", "make qa", "make fix",
      "make migrate", "make seed", "make ps", "make logs"
    ],
    "filesystem": {
      "denyRead": [
        "./.env", "./.env.local", "./.env.prod",
        "~/.ssh", "~/.docker", "~/.aws", "~/.config/gh",
        "/var/run/docker.sock", "/run/docker.sock", "/run/WSL", "/run/user"
      ],
      "denyWrite": [
        "./.env", "./.env.local", "./.env.prod",
        "./compose.yaml", "./compose.override.yaml", "./compose.prod.yaml",
        "./docker-compose.yml", "./docker-compose.override.yml",
        "./Makefile", "./docker", "./.githooks", "./tests/Hooks"
      ]
    },
    "network": {
      "allowedDomains": []
    },
    "credentials": {
      "files": [
        { "path": "~/.ssh", "mode": "deny" }
      ],
      "envVars": [
        { "name": "GITHUB_PAT", "mode": "deny" },
        { "name": "DB_READONLY_PASSWORD", "mode": "deny" },
        { "name": "ANTHROPIC_API_KEY", "mode": "deny" }
      ]
    }
  }
}
```

Zdůvodnění jednotlivých položek:
- **`allowUnsandboxedCommands: false`** – bez toho je sandbox jen doporučení: model po selhání požádá o běh bez sandboxu
  (v `bypassPermissions` bez dotazu). `ask` na `Bash(dangerouslyDisableSandbox:true)` je pojistka pro případ, že by
  klíč někdo vrátil. **Nedávat** `allowUnsandboxedCommands: false` do `--settings`: sandbox by se stal „admin-required“
  a Claude Code by ignoroval `excludedCommands` z repa (Docker by nešel).
- **`failIfUnavailable: true`** – když chybí `bwrap`/`socat`, relace nenastartuje (jinak by tiše běžela bez sandboxu).
- **`excludedCommands` – jen tvary, které nemohou změnit definici kontejneru ani hostitele:**
  - `docker compose exec -T app …` jen spouští proces v **už běžícím** kontejneru `app`; `exec` nepřidá připojení svazků,
    privilegia ani nový kontejner. Volby před `app` jsou pevné, `-f`, `--project-directory`, `run`, `up` vzoru neodpovídají.
    Tvary bez `-T` (dnes v `allow`) se nevyjímají – Bash nástroj TTY nemá; v sandboxu prostě selžou.
  - `make <cíl>` jen přesné cíle; `Makefile` volá `docker compose -f compose.yaml` (vypíná automatické načtení
    `compose.override.yaml`) a z příkazové řádky přijímá jen `ARGS` (revize S5). Bezpečné jen proto, že `Makefile`,
    `compose*.yaml`, `docker/` a `.env` jsou chráněné proti zápisu z Bash (`denyWrite`) i z nástrojů Edit/Write (`ask`).
  - **Nevyjímat:** holé `docker compose up|build|restart|run|pull`, `docker run …`, `docker volume …`, `make composer`
    (`composer require` je brána člověka), `make test-hooks`, `make mcp`, `make ai-local`. Holé `docker compose up`
    načte i `compose.override.yaml`, který by Bash mohl vytvořit → kontejner s `/:/host` = root nad hostitelem. Tyto
    příkazy spouští člověk sám přes `!` nebo v terminálu.
- **`denyWrite`** – soubory, které běží mimo sandbox nebo definují kontejnery: compose soubory (i dosud neexistující
  override), `Makefile`, `docker/` (Dockerfile, init DB, nginx), `.env` (`COMPOSE_FILE`), `.githooks/` (Git hooky běží
  i v terminálu člověka **mimo sandbox** – `core.hooksPath` sandbox sám nechrání), `tests/Hooks/` (spouští člověk).
- **`denyRead` socketů a `/run/WSL`, `/run/user`** – obrana do hloubky pro případ, že seccomp filtr chybí: soubor
  socketu Docker démona je pak skrytý (bubblewrap přes něj připojí `/dev/null`), interop socket WSL (spouštění `cmd.exe`,
  `powershell.exe` na Windows bez sandboxu) a uživatelská sběrnice D-Bus/systemd (`systemd-run --user` = proces mimo
  sandbox) také. Abstraktní Unix sockety odděluje samostatný síťový namespace. `/mnt` (Windows disky, CLI Docker Desktopu
  v `/mnt/wsl`) skrývá `blockReadsOutsideWorkingDirectories`.
- **`network.allowedDomains: []`** – product owner jmenoval `api.anthropic.com`, `packagist.org`/`repo.packagist.org`,
  `github.com`. Ověřeno: **žádný sandboxovaný příkaz na hostiteli je nepotřebuje.** Volání API dělá proces Claude Code
  (mimo sandbox), composer a npm běží v kontejnerech přes vyjmuté `docker compose exec` (provoz jde přes Docker, ne přes
  proxy sandboxu), `git push` se nedělá a remote je SSH. Každá doména navíc je kanál pro únik dat (`github.com` výslovně
  kvůli domain frontingu). Seznam domén product ownera patří do **síťové politiky kontejnerů** (viz Docker níže).
- **`credentials.envVars`** – proměnné z `settings.local.json`/prostředí relace se sandboxovaným příkazům odeberou.
  MCP servery (např. `github` s `GITHUB_PAT`) běží mimo sandbox a dál je dostanou.
- **`blockReadsOutsideWorkingDirectories`** – zakáže čtení zbytku `/home/q` (ostatní projekty, `~/.ssh`, `~/.claude.json`
  s registrací MCP) sandboxovaným příkazům i nástrojům Read/Grep/Glob; čtení mimo repo pak jde přes `/add-dir`.

### Navržená konfigurace – uživatelská a lokální (neverzované; mění člověk)
- `~/.claude/settings.json`: `{"sandbox": {"network": {"strictAllowlist": true, "allowedDomains": ["localhost:8080"]}}}`.
  `strictAllowlist` v projektu nefunguje a když platí, Claude Code ignoruje `allowedDomains` z repa – proto i
  `localhost:8080` patří sem. Mimo projekty se zapnutým sandboxem nemá vliv.
- `.claude/settings.local.json`: odstranit `"defaultMode": "bypassPermissions"` (platí projektové `acceptEdits`;
  sandboxované Bash příkazy se díky `autoAllowBashIfSandboxed` stejně neptají), odstranit `Bash(docker compose *)`,
  `Bash(docker volume *)` a další široká pravidla. V `bypassPermissions` by nástroj Edit bez dotazu přepsal
  `.claude/settings.json` (sandbox ho nekryje) a vypnul celé řešení.

### HTTP kontroly `curl http://localhost:8080/…`
V sandboxu na WSL2 přímé spojení na `localhost` hostitele nedojde. **Varianta A (doporučená):** `curl --noproxy '' -sS
http://localhost:8080/…` (prázdný `--noproxy` přebije `NO_PROXY`, spojení jde přes proxy sandboxu, která `localhost:8080`
pustí podle allowlistu) – vyžaduje rozšířit povolené volby v `bash-strazce.sh` a tvar v `allow`; ověřit krokem V11.
**Varianta B (záložní):** vyjmout přesné tvary `curl -s http://localhost:8080/*` – vzor `*` ale pokryje i další argumenty
(`-o ~/.bashrc http://jinam`), hranicí by pak byl jen hook. Playwright MCP běží mimo sandbox a změna se ho netýká.

### Docker socket – poctivé zhodnocení
**Přichází sandbox přístupem k Dockeru o smysl? Ne, ale jen za čtyř podmínek; jinak ano.**

1. **Socket ze sandboxu = úplný únik.** Kdo se dostane k Docker démonu, spustí `docker run -v /:/host …` (na WSL2 navíc
   `/mnt/c`) jako root ve VM Docker Desktopu → čte `~/.ssh`, zapisuje `~/.bashrc`, má volnou síť. Připojení k socketu
   na read-only připojení funguje, takže read-only FS nestačí. Brání tomu jen seccomp filtr (blokuje vytvoření
   `AF_UNIX`) a skrytí souboru socketu (`denyRead`). **Podmínka 1:** seccomp filtr nainstalovaný, nebo aspoň `denyRead`
   socketů, `/run/WSL`, `/run/user` + `blockReadsOutsideWorkingDirectories`; ověřit V6, V8. **Nikdy** `allowAllUnixSockets`.
2. **Vyjmuté příkazy běží bez sandboxu záměrně.** Bezpečné jsou jen tehdy, když jejich účinek nezávisí na souborech,
   které agent mění bez člověka. **Podmínka 2:** vyjmout jen tvary výše; **podmínka 3:** `compose*.yaml`, `Makefile`,
   `docker/`, `.env`, `.githooks/` chráněné `denyWrite` (Bash) i `ask` (Edit/Write). Vzor `docker compose *` nebo
   `docker *` by z sandboxu udělal divadlo (`-f cizi.yaml`, `run -v /:/host`, override soubor).
3. **Podmínka 4:** žádné `bypassPermissions` ani `--dangerously-skip-permissions` v relacích repa – nástroje Edit/Write
   jsou mimo sandbox a v tomto režimu by bez dotazu změnily nastavení nebo `compose.yaml`.
4. **Zbytkové riziko, které sandbox neřeší – kontejner `app` jako výstupní kanál.** Kód spouštěný přes vyjmuté
   `exec` (PHPUnit, `bin/konzole`, skripty composeru, `vendor/bin/*`) může agent upravit (`src/`, `tests/`, `composer.json`
   jsou zapisovatelné). Ten kód běží v kontejneru s **neomezeným odchozím internetem** a v prostředí má
   `ANTHROPIC_API_KEY`, `DB_PASSWORD`, `DB_MIGRACE_PASSWORD` a čte obsah projektu. Na hostitele (`~/.ssh`, `.env`, hooky,
   `.git`) nedosáhne – kontejner nemá socket Dockeru, `cap_drop: ALL`, `.git`/`.claude` jen ke čtení, `.env` je `/dev/null`.
   Dopad: únik tajemství kontejneru a kódu repa, ne převzetí hostitele. Ve výukovém režimu (výchozí `AI_PROVIDER=falesny`,
   lokální hesla) **přijato**; skutečné řešení je síťová politika kontejnerů (vlastní síť `internal: true` pro `app` +
   egress proxy s allowlistem `api.anthropic.com`, `repo.packagist.org`, `packagist.org`, `codeload.github.com`,
   `objects.githubusercontent.com` pro `composer install`) – backlog M9 spolu s oddělením migračního hesla.

Shrnutí: sandbox posouvá hranici z „textové analýzy příkazu“ na „co smí proces v OS“. Docker zůstává jedinou dírou a
navržená konfigurace ji zužuje na „spustit proces v běžícím kontejneru `app`“, tj. z úrovně hostitele na úroveň kontejneru.

### Co se změní u rizik ze STAV.md
| Riziko | Po zavedení |
|---|---|
| S3 `cat <<EOF \| bash`, interpretery, `bash -c`, `eval` | běží v sandboxu: zápis jen do repa mimo chráněné cesty, bez sítě kromě allowlistu, bez `.env`/`~/.ssh`; `eval` se nikdy nevyjme → **vyřešeno u kořene** (zbývá zápis do `src/` apod., stejně jako přes Edit) |
| S4 zápis přes interpretery | `denyWrite` a chráněné cesty vynucuje jádro → **vyřešeno** pro Bash; Edit/Write řídí `permissions` |
| N4, N5 | sníží se na nepohodlí; po ověření uzavřít jako „kryje sandbox“ |
| hooky selhávají otevřeně | přestává vadit; Bash hooky ani nastavení přepsat nemůže (chráněné cesty) |
| `-f jiny.yaml`, `COMPOSE_FILE` | `docker compose -f …` vzoru neodpovídá → v sandboxu bez socketu selže; `.env` chráněn `denyWrite` → **vyřešeno** |
| R4-4 přesměrování mimo repo | FS mimo repo je jen ke čtení → **vyřešeno** |
| MCP jako výstupní kanál (`context7`, `playwright`) | **nevyřešeno** – MCP běží mimo sandbox; zůstávají `permissions` |
| WebFetch, Read/Edit/Write | **nevyřešeno sandboxem** – řídí `permissions` (`WebFetch` je v `ask`) |
| `.githooks/` jako persistence (nový nález) | `denyWrite` + `ask` → **vyřešeno** |
| kontejner `app` s internetem a tajemstvími (nový nález) | **přijato**, backlog M9 |

### Kroky zavedení (provádí člověk; agent nastavení ani hooky nemění)
1. **Závislosti ve WSL2:** `sudo apt-get install bubblewrap socat`; `sysctl kernel.apparmor_restrict_unprivileged_userns`
   (při `1` profil AppArmor pro `bwrap` podle dokumentace); `claude --version` ≥ **2.1.285** (pořadí priorit nastavení
   sandboxu, `blockReadsOutsideWorkingDirectories`, `strictAllowlist`).
2. **Kontrola seccomp:** v relaci `/sandbox` → záložka Dependencies. Chybí-li seccomp filtr, viz otázka 2.
3. **Lokální nastavení:** upravit `.claude/settings.local.json` (bez `bypassPermissions`, bez širokých `docker` pravidel).
4. **Uživatelské nastavení:** `strictAllowlist` + `allowedDomains: ["localhost:8080"]` do `~/.claude/settings.json`.
5. **Projektové nastavení:** sloučit blok výše do `.claude/settings.json` (commit `chore(claude): sandbox …`, ADR → přijato).
6. **Hook a allow pro curl (varianta A):** povolit `--noproxy ''` v `bash-strazce.sh`, upravit tvary `curl` v `allow`
   a skill/paměť testera; doplnit scénáře do `tests/Hooks/scenare.sh`.
7. Restart Claude Code, `/sandbox` → Config (zkontrolovat „Denied within allowed“), `claude doctor` bez varování,
   pak scénáře ověření níže v hlavní relaci (ne přes `!`, ten běží mimo sandbox).
8. Aktualizovat `docs/plan/STAV.md` (uzavřít S3, S4, R4-4 …) a lekci v `CLAUDE.md` („docker jen vyjmutými tvary,
   ostatní spouští člověk přes `!`“).

### Ověření (požádat Clauda o spuštění; při nabídce běhu bez sandboxu odmítnout)
| # | Příkaz | Očekávání |
|---|---|---|
| V1 | `touch ~/sandbox-probe` | `Read-only file system` |
| V2 | `curl --noproxy '*' https://example.com` | `Could not resolve host` |
| V3 | `curl -sS https://example.com` | odmítnuto proxy bez dotazu (403 / host not allowed) |
| V4 | `cat .env` a `head -c 20 .env.example` | `.env` prázdný/nečitelný, `.env.example` čitelný |
| V5 | `ls ~/.ssh` a `ls ~/projects` | selže (mimo pracovní adresář) |
| V6 | `docker ps` a `docker compose -f compose.yaml ps` | selže – nelze se připojit k démonu |
| V7 | `echo x >> .githooks/pre-commit`, `echo x > compose.override.yaml`, `echo x >> Makefile`, `echo x > .claude/hooks/x.sh` | `Read-only file system` |
| V8 | `cmd.exe /c ver` a `systemd-run --user true` | selže |
| V9 | `cat <<'EOF' \| bash` s `touch ~/x` (regrese S3) a `python3 -c "open('/home/q/.bashrc','a')"` | `Read-only file system` |
| V10 | `make qa` | projde (vyjmuto) |
| V11 | `curl --noproxy '' -sS http://localhost:8080/zdravi` | `{"stav":"ok","db":"ok"}` |
| V12 | `git status`, `git add` zkušebního souboru a `git restore --staged -- <soubor>` | zápis indexu funguje; skutečný commit (vč. `commit-brana.sh` a `.githooks/`) ověří první běžný `/commit` |
| V13 | nástroj Edit na `compose.yaml` | dotaz |
| V14 | selhání v sandboxu | žádná nabídka „Bash command (unsandboxed)“ |

## Důsledky
+ Hranice vynucená jádrem místo analýzy textu: S3, S4, interpretery, `bash -c`/`eval`, `-f cizí soubor`, `COMPOSE_FILE`
  a přesměrování mimo repo se řeší jedním mechanismem; další kola revize hooků přestávají být nutná.
+ Méně dotazů: sandboxované příkazy (`git`, `ls`, `jq`, `grep` …) běží bez dotazu a bez nutnosti udržovat jejich přesné
  tvary v `allow`; pohodlí blízké `bypassPermissions` bez jeho rizika.
+ `.env`, `~/.ssh` a ostatní projekty workspace jsou pro Bash nečitelné i při úspěšné prompt injection.
+ Hooky se mohou časem zjednodušit (jen pohodlí a audit) – samostatné rozhodnutí po ověření.
− **Docker zůstává dírou**, jen zúženou na `exec` do běžícího `app`; kontejner má internet a tajemství (přijato, M9).
− Úpravy `compose*.yaml`, `Makefile`, `docker/`, `.githooks/` se vždy ptají a z Bash nejdou vůbec (tření pro agenta
  `devops`; tyto změny jsou stejně brána člověka).
− Holé `docker compose up/build/run`, `docker volume`, `make test-hooks`, `make mcp`, `make ai-local`, `actionlint` spouští
  člověk (`!` nebo terminál) – agent je zkusí, selžou, retry bez sandboxu není.
− Závislosti hostitele (`bubblewrap`, `socat` z apt) a možná seccomp filtr z npm (konflikt s pravidlem „Node jen v Dockeru“).
− Riziko falešného pocitu bezpečí: Read/Edit/Write, WebFetch, MCP servery a hooky jsou **mimo** sandbox.
− Provozní drobnosti: `git checkout/merge` měnící chráněné soubory (`.claude/skills` …) selže s `unable to unlink old`
  (spustí člověk); po `SIGKILL` relace mohou zůstat 0bajtové zástupné soubory u `.claude` (`claude doctor`, smazat ručně);
  `strictAllowlist` je v uživatelském nastavení (platí pro všechny projekty se zapnutým sandboxem).

## Zvažované alternativy
- **A – jen hooky + allowlist (stav dnes)** – textová analýza se dá obejít (4 kola revize, S3/S4 neuzavřeny), hooky
  selhávají otevřeně, lokální `bypassPermissions` allowlist vyřazuje. Odmítnuto jako hranice; zůstává jako první a druhá linie.
- **B – devcontainer / vlastní kontejner s Claude Code uvnitř + firewall (`init-firewall.sh`, default-deny iptables)** –
  izoluje celý proces včetně Read/Edit, MCP a hooků, Node v kontejneru je v souladu s pravidly. Ale Docker uvnitř znamená
  buď připojit socket hostitele (stejná díra, horší: celý proces má root nad hostitelem), nebo Docker-in-Docker
  (privilegovaný kontejner); MCP servery přes `docker compose run`, Playwright a změna workflow (VS Code). Odloženo –
  kandidát, pokud bude potřeba běh bez dozoru.
- **C – VM / Docker Sandboxes (microVM s vlastním Docker démonem)** – nejsilnější: Docker démon patří VM, ne hostiteli,
  takže díra socketu zmizí; řeší i MCP a nástroje. Cena: nastavení, výkon, synchronizace souborů, nový produkt mimo
  Docker Desktop. Odloženo – doporučená cesta, kdyby se měl vrátit `bypassPermissions`.
- **D – `@anthropic-ai/sandbox-runtime` kolem celého Claude Code (`npx … claude`)** – kryje i MCP a hooky, ale je to beta,
  potřebuje Node na hostiteli a Docker naráží na stejný socket (MCP servery přes compose). Odmítnuto.
- **E – sandbox s `excludedCommands: ["docker *"]` / `["docker compose *"]` nebo `allowAllUnixSockets: true`** – pohodlné,
  ale z hranice dělá divadlo (`-f`, `run -v /:/host`, override soubor; všechny sockety včetně interop WSL). Odmítnuto.
- **F – filtrující proxy Docker API (např. `docker-socket-proxy`) nebo rootless Docker pro relace agentů** – vyjmuté
  příkazy by přes `DOCKER_HOST` směly jen `exec` do kontejnerů `t360`; zúží i případ selhání ochrany souborů. Nová
  služba/obraz a složitost nad rámec výukového režimu. Odloženo (M9+).

## Otázky pro člověka
1. **`bypassPermissions` v `.claude/settings.local.json`** – vypnout a používat `acceptEdits` + sandbox auto-allow?
   *Doporučení: ano; bez toho ADR nemá smysl (Edit/Write mimo sandbox by bez dotazu změnily nastavení).*
2. **Seccomp filtr, pokud v `/sandbox` chybí** – (a) jednorázová výjimka z pravidla „Node jen v Dockeru“
   (`npm install -g @anthropic-ai/sandbox-runtime` na hostiteli), nebo (b) spoléhat na `denyRead` socketů, `/run/WSL`,
   `/run/user` + `blockReadsOutsideWorkingDirectories`. *Doporučení: nejdřív ověřit, zda ho nativní Claude Code nemá;
   chybí-li, (b) a ověřit V6/V8; (a) jen pokud V6 nebo V8 selže.*
3. **Síťový allowlist sandboxu** – zadání jmenovalo `api.anthropic.com`, packagist a `github.com`; ověřeno, že je žádný
   sandboxovaný příkaz nepotřebuje. *Doporučení: prázdný + `localhost:8080`; domény přidávat až s konkrétní potřebou.*
4. **`strictAllowlist` v `~/.claude/settings.json` (platí pro všechny projekty se sandboxem) vs. spouštění
   `claude --settings …` jen pro tento projekt.** *Doporučení: uživatelské nastavení (jednodušší, jinde sandbox není zapnutý).*
5. **Kontejner `app` s internetem a tajemstvími** – přijmout ve výukovém režimu a dát síťovou politiku kontejnerů
   (allowlist domén ze zadání) do backlogu M9? *Doporučení: ano.*
6. **Ochrana `compose*.yaml`, `Makefile`, `docker/`, `.githooks/`, `tests/Hooks/` (`denyWrite` + `ask`)** – přijmout tření
   pro agenta `devops`? *Doporučení: ano; jinak nelze vyjmout `make` cíle.*
7. **`curl` na `localhost:8080`** – varianta A (`--noproxy ''` přes proxy, změna hooku) nebo B (vyjmutí curl)?
   *Doporučení: A; B jen pokud V11 selže.*
