# 001 – Docker základ, kostra aplikace, Makefile, composer nástroje a /zdravi
Stav: schváleno

- **Milník:** M1 (bez CI — `ci.yml` je samostatný plán 002, viz Mimo rozsah)
- **Autor:** agent architekt · **Datum:** 2026-10-03
- **Souvisí:** [ADR-0001](../adr/0001-vyvoj-tymem-agentu.md),
  [ADR-0002 vše v Dockeru vč. MCP](../adr/0002-vse-v-dockeru-vcetne-mcp.md) (navrženo),
  [ADR-0003 anglické identifikátory](../adr/0003-anglicke-identifikatory.md) (navrženo),
  [architektura](../architektura.md), skill `devops-kontrakt`

## Cíl
Kdo si repozitář naklonuje na stroj, kde je jen Docker, git, bash a jq (případně make a curl),
spustí jedním příkazem `make up` celé vývojové prostředí, ověří zdraví aplikace na
`http://localhost:8080/zdravi` a jedním příkazem `make qa` celou kvalitativní bránu.
Agenti (hooky, Playwright, čtení DB, context7) přitom nepotřebují na hostiteli PHP ani Node.

## Akceptační kritéria
Všechny příkazy se spouští z kořene repa. „Hostitel“ = WSL2/Linux bez PHP a Node.

### A. Prostředí v Dockeru
1. **Given** repo bez `.env` a bez kontejnerů projektu, **When** `make up`, **Then** příkaz skončí
   kódem 0, `.env` vznikne kopií `.env.example` a
   `docker compose ps --format '{{.Name}} {{.Health}}'` vypíše `app-t360 healthy`,
   `web-t360 healthy`, `db-t360 healthy` a `adminer-t360` běží.
2. **Given** `compose.yaml`, **When**
   `docker compose config --no-interpolate --format json | jq -r '.name, (.services[] | .container_name)'`,
   **Then** první řádek je `t360` a každý `container_name` končí `-t360`
   (`app-t360`, `web-t360`, `db-t360`, `adminer-t360`, `mcp-playwright-t360`, `mcp-mariadb-t360`);
   názvy služeb jsou `app`, `web`, `db`, `adminer`, `mcp-playwright`, `mcp-mariadb`.
3. **Given** běžící prostředí, **When** `docker compose port web 80`, `docker compose port db 3306`,
   `docker compose port adminer 8080`, **Then** výstupy jsou `0.0.0.0:8080` (příp. `127.0.0.1:8080`
   podle otázky 4), `127.0.0.1:3307`, `127.0.0.1:8081`; služba `app` nemá publikovaný port.
4. **Given** běžící prostředí, **When** `for s in app web db adminer; do docker compose exec -T $s id -u; done`,
   **Then** žádný výstup není `0`; `app` vrací `1000` (resp. `HOST_UID` z `.env`).
   A `docker compose config --no-interpolate --format json | jq -c '.services[] | [.cap_drop, .security_opt]'`
   ukáže u každé služby `["ALL"]` a `["no-new-privileges:true"]` (případná `cap_add` je zdůvodněná
   komentářem v `compose.yaml`).
5. **Given** čerstvě inicializovaná DB, **When**
   `docker compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -N -e "SELECT SCHEMA_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE \"redakce%\""'`,
   **Then** vypíše `redakce` i `redakce_test`, obě `utf8mb4_czech_ci`.
6. **Given** uživatel `redakce_cteni`, **When** v kontejneru `db` provede `SELECT 1` a pak
   `CREATE TABLE redakce.x (i INT)`, **Then** `SELECT` projde a `CREATE` skončí chybou
   `command denied`. Uživatel `redakce_app` má jen DML na `redakce` a `redakce_test`.
7. **Given** hostitel, **When** `for b in php composer node npm npx; do command -v $b; done`,
   **Then** výstup je prázdný (pravidlo 0) — a přesto všechna kritéria A–F projdou.
8. **Given** `docker/php/Dockerfile`, **When** `docker build --target prod -f docker/php/Dockerfile -t t360-app-prod .`
   a `docker run --rm t360-app-prod id -u`, **Then** build projde a uživatel není `0`
   (obraz obsahuje kód bez dev závislostí).
9. **Given** běžící prostředí, **When** `make down`, **Then** kontejnery jsou pryč a volume
   `t360_db_data` zůstává (`docker volume ls -q --filter name=t360_db_data` není prázdné).

### B. Endpoint `/zdravi` a kostra aplikace
10. **Given** běžící prostředí, **When** `curl -s http://localhost:8080/zdravi -w '\n%{http_code}\n'`,
    **Then** výstup je přesně `{"stav":"ok","db":"ok"}` a `200`.
11. **Given** běžící prostředí, **When** `curl -s http://localhost:8080/zdravi -D - -o /dev/null`,
    **Then** hlavičky obsahují `Content-Type: application/json; charset=utf-8`,
    `Cache-Control: no-store`, `X-Content-Type-Options: nosniff` a hlavička `Server`
    neobsahuje verzi nginx; chybí `X-Powered-By`.
12. **Given** `docker compose stop db`, **When** `curl -s -m 5 http://localhost:8080/zdravi -w '\n%{http_code}\n'`,
    **Then** do 3 s přijde `{"stav":"chyba","db":"chyba"}` a `503`; tělo neobsahuje
    `SQLSTATE`, název hostitele, uživatele ani heslo. Po `docker compose start db` se vrátí `200`.
13. **Given** běžící prostředí, **When** `curl -s http://localhost:8080/neexistuje -w '\n%{http_code}\n'`
    a `curl -s http://localhost:8080/zdravi -X POST -D - -o /dev/null`, **Then** první vrací `404`
    bez stack trace, druhý `405` s hlavičkou `Allow: GET`.
14. **Given** běžící prostředí, **When** `curl -s http://localhost:8080/vendor/autoload.php -w '\n%{http_code}\n'`
    a `curl -s http://localhost:8080/../composer.json -w '\n%{http_code}\n'`, **Then** obě vrací `404`
    (web servíruje jen `public/`, PHP vykonává jen `public/index.php`).
15. **Given** `compose.yaml`, **When** `docker inspect --format '{{json .Config.Healthcheck.Test}}' web-t360`,
    **Then** healthcheck volá `/zdravi` (kontrakt: `GET /zdravi → 200 {"stav":"ok","db":"ok"}`).
16. **Given** kód v `src/`, **When** `grep -rnP '\b(class|interface|enum|function)\s+\w*[ěščřžýáíéůúťďňó]' src/ tests/`
    nic nenajde a revize ověří ADR-0003, **Then** všechny identifikátory jsou anglicky.

### C. Kvalitativní brána
17. **Given** běžící prostředí, **When** `make qa`, **Then** skončí kódem 0 a výstup obsahuje
    úspěch `php -l`, PHP-CS-Fixer bez nálezů, PHPStan `[OK] No errors`, PHPUnit `OK`
    (sady `Unit` i `Integration`) a `composer audit` bez nálezů.
18. **Given** `phpstan.neon.dist`, **When** `grep -E '^\s*level:\s*max' phpstan.neon.dist` a
    `ls phpstan-baseline.neon`, **Then** první najde řádek, druhý soubor nenajde (bez baseline).
19. **Given** dočasně vložená chyba typu do souboru v `src/` (tester ji pak vrátí), **When**
    `make check`, **Then** skončí kódem ≠ 0 a vypíše soubor:řádek.
20. **Given** repo, **When** `git ls-files composer.lock` a `git check-ignore -q vendor/ && echo ignorovano`,
    **Then** `composer.lock` je verzovaný a `vendor/` ignorovaný.
21. **Given** `phpunit.xml.dist`, **When** se spustí integrační sada, **Then** používá DB
    `redakce_test` (vynuceno `<env … force="true"/>`) a `AI_PROVIDER=falesny`.

### D. Hooky a pojistky (proti kontejneru `app`, nikdy hostitelské PHP)
22. **Given** běžící `app` a soubor `var/tmp/rozbite.php` se syntaktickou chybou, **When**
    `echo '{"tool_input":{"file_path":"'"$PWD"'/var/tmp/rozbite.php"}}' | CLAUDE_PROJECT_DIR="$PWD" bash .claude/hooks/php-lint.sh; echo "exit=$?"`,
    **Then** `exit=2` a chybový výstup obsahuje cestu `/app/var/tmp/rozbite.php`
    (důkaz, že `php -l` běžel v kontejneru).
23. **Given** zastavený `app` (`docker compose stop app`), **When** stejný příkaz jako v bodě 22,
    **Then** `exit=0` a stdout je JSON s `hookSpecificOutput.additionalContext`, který říká,
    že lint neproběhl a je třeba `make up`. **And** `grep -rn 'command -v php' .claude/hooks .githooks`
    nic nenajde (žádný fallback na hostitele).
24. **Given** běžící `app` a dočasně padající test, **When**
    `echo '{"session_id":"t","agent_id":"a"}' | CLAUDE_PROJECT_DIR="$PWD" bash .claude/hooks/rychla-kontrola.sh; echo "exit=$?"`,
    **Then** `exit=2` a výstup obsahuje název padajícího testu (žádné `-q`, které výstup polyká).
    Se zelenou sadou `exit=0`.
25. **Given** zastavený `app` a existující `compose.yaml`, **When** příkaz z bodu 24 třikrát,
    **Then** pokaždé `exit=2` s návodem `make up`; počtvrté `exit=0` a JSON `systemMessage`
    (pojistka proti smyčce). Bez `compose.yaml` (repo před M1) hook vrací `exit=0`.
26. **Given** zastavený `app` a připravený (staged) `.php` soubor, **When** `bash .githooks/pre-commit; echo "exit=$?"`,
    **Then** `exit=1` s hláškou „spusť make up“ (žádné tiché projití).
27. **Given** hook `bash-strazce.sh`, **When** pro každý příkaz z tabulky
    `echo '{"tool_input":{"command":"<příkaz>"}}' | CLAUDE_PROJECT_DIR="$PWD" bash .claude/hooks/bash-strazce.sh | jq -r '.hookSpecificOutput.permissionDecision // "allow"'`,
    **Then** výsledky odpovídají:

    | Příkaz | Očekávání |
    |---|---|
    | `php -v` · `composer install` · `npx -y foo` · `ls && node -v` · `npm i` | `deny` |
    | `docker compose exec -T app php -v` · `docker compose exec -T app composer test` | `allow` |
    | `git commit --no-verify -m "x: y"` | `deny` |
    | `docker compose config` | `deny` · `docker compose config --quiet` → `allow` |
    | `make composer ARGS="require foo/bar"` | `ask` (nová závislost = brána člověka) |
    | `docker compose run --rm -v /:/host app sh` | `deny` |
28. **Given** `.claude/settings.json`, **When** `jq -r '.permissions.deny[]' .claude/settings.json | grep -cE '^Bash\((php|composer|node|npm|npx) '`,
    **Then** výsledek je `5` a `jq -r '.permissions.ask[]' .claude/settings.json | grep -c npm` je `0`.
29. **Given** tester připravil `tests/Hooks/scenare.sh` (kritéria 22–27 jako skript), **When**
    `make test-hooks`, **Then** skončí kódem 0 (běží na hostiteli, protože hooky běží na hostiteli;
    potřebuje jen bash, jq a docker).

### E. MCP servery v Dockeru
30. **Given** konfigurace, **When** `grep -rn 'npx' .mcp.json .claude/agents/ .claude/settings.json .claude/settings.local.json.example`,
    **Then** nic nenajde; `jq -r '.mcpServers.context7 | .type + " " + .url' .mcp.json` vypíše
    `http https://mcp.context7.com/mcp` (varianta A z otázky 3).
31. **Given** `make mcp`, **When** dokončí, **Then** jsou lokálně obraz Playwright MCP s pevnou
    verzí (ne `latest`) a obraz služby `mcp-mariadb` (`docker compose --profile mcp images`).
32. **Given** běžící `db`, **When**
    `printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"smoke","version":"0"}}}' | docker compose run --rm -T mcp-playwright | head -1 | jq -r '.result.serverInfo.name'`
    a totéž pro `mcp-mariadb`, **Then** oba vypíší neprázdný název serveru (stdio funguje bez Node na hostiteli).
33. **Given** nová relace Claude Code spuštěná **z kořene repa**, **When** `/mcp`, **Then** `context7`
    je připojený; **When** subagent `tester` zavolá Playwright `browser_navigate` na `http://web/zdravi`,
    **Then** stránka obsahuje `{"stav":"ok","db":"ok"}` a snímek obrazovky uložený do
    `tests/_artefakty/` patří uživateli hostitele (`stat -c %u` = `id -u`).
34. **Given** subagent `databazista`, **When** přes `mariadb-cteni` provede `SELECT DATABASE()` a pak
    pokus o `CREATE TABLE`, **Then** první vrátí `redakce`, druhý je odmítnut (MCP i oprávnění DB).
35. **Given** definice agentů, **When** `grep -n '^tools:' .claude/agents/tester.md .claude/agents/databazista.md`,
    **Then** tester má `mcp__playwright`, databazista `mcp__mariadb-cteni` (bez toho subagent
    MCP nástroje nedostane). `.claude/settings.local.json.example` už neobsahuje `DB_READONLY_PASSWORD`.

### F. Čistý klon podle README (ověřuje se až po lokálních commitech)
36. **Given** zastavené původní prostředí (`make down`) a kopie repa
    `git clone /home/q/projects/t360-php-agent-rs "$SCRATCH/t360-klon"`, **When** v klonu
    `COMPOSE_PROJECT_NAME=t360-klon make up && curl -s http://localhost:8080/zdravi && COMPOSE_PROJECT_NAME=t360-klon make qa`,
    **Then** vše projde bez jediného kroku, který není v `README.md` (proměnná
    `COMPOSE_PROJECT_NAME` jen odděluje volumes klonu od původní DB; úklid
    `COMPOSE_PROJECT_NAME=t360-klon docker compose down -v` schvaluje člověk).
37. **Given** klon, **When** `git ls-files | grep -E '^(vendor/|node_modules/|\.env$)'`, **Then** nic.
    `README.md` obsahuje: požadavky hostitele (pravidlo 0), `git config core.hooksPath .githooks`,
    `make up`, `make qa`, `make mcp`, URL aplikace a Admineru, „Claude Code spouštěj z kořene repa“.

## Návrh

### 1. Pravidlo 0 v praxi
Hostitel = orchestrace (`docker`, `git`, `bash`, `jq`, a po schválení `make`, `curl`).
Vše ostatní — PHP, Composer, nástroje kvality, prohlížeč, MySQL klient pro MCP — běží v
kontejnerech z `compose.yaml`. Detaily a alternativy: ADR-0002.

### 2. Služby `compose.yaml` (dev)
Top-level `name: t360`. Síť `t360_default`. Volumes `db_data`, `composer_cache`.

| Služba | `container_name` | Obraz / build | Porty (hostitel) | Uživatel | Healthcheck | Profil |
|---|---|---|---|---|---|---|
| `app` | `app-t360` | `docker/php/Dockerfile` target `dev` (PHP 8.4-FPM), repo → `/app` | žádné | `${HOST_UID:-1000}:${HOST_GID:-1000}` | FPM naslouchá na :9000 | — |
| `web` | `web-t360` | `nginx:1.<stable>-alpine`, mount `docker/nginx/*.conf` a `public/` (ro) | `8080:80` | `101` (nginx), pid/temp v `/tmp` | `wget -qO- http://127.0.0.1/zdravi` | — |
| `db` | `db-t360` | `mariadb:11.8`, `docker/mariadb/conf.d/` + `init/` | `127.0.0.1:3307:3306` | dle obrazu (`mysql`) | `healthcheck.sh --connect --innodb_initialized` | — |
| `adminer` | `adminer-t360` | `adminer:5` | `127.0.0.1:8081:8080` | dle obrazu (ne-root) | — | — |
| `mcp-playwright` | `mcp-playwright-t360`¹ | `mcr.microsoft.com/playwright/mcp:<pevná verze>` | žádné | `${HOST_UID}` (ověřit) | — | `mcp` |
| `mcp-mariadb` | `mcp-mariadb-t360`¹ | `docker/mcp/mariadb/Dockerfile` (`node:<LTS>-alpine` + pinnutý `@benborla29/mcp-server-mysql`) | žádné | `node` | — | `mcp` |

¹ `docker compose run` `container_name` ignoruje; pravidlo platí pro `up` (viz ADR-0002).

Společné: `security_opt: [no-new-privileges:true]`, `cap_drop: [ALL]` (+ jen nutné `cap_add`
s komentářem, typicky u `db`), `depends_on` s `condition: service_healthy` (`app` → `db`,
`web` → `app`, `mcp-mariadb` → `db`). Žádná tajemství v `compose.yaml` — jen `${VAR:?chybí}`
interpolace z `.env` a `env_file: .env` u `app` a `db`.

Návrh MCP služeb (pro devops, upřesní podle skutečného ENTRYPOINT obrazu):
```yaml
  mcp-playwright:
    profiles: [mcp]
    image: mcr.microsoft.com/playwright/mcp:<verze>   # pin, nikdy latest / --pull=always
    container_name: mcp-playwright-t360
    init: true
    command: ["--headless", "--browser", "chromium", "--no-sandbox", "--isolated", "--output-dir", "/artefakty"]
    volumes: ["./tests/_artefakty:/artefakty"]
  mcp-mariadb:
    profiles: [mcp]
    build: docker/mcp/mariadb
    container_name: mcp-mariadb-t360
    read_only: true
    depends_on: { db: { condition: service_healthy } }
    environment:
      MYSQL_HOST: db
      MYSQL_PORT: "3306"
      MYSQL_USER: redakce_cteni
      MYSQL_PASS: ${DB_READONLY_PASSWORD:?chybí v .env}
      MYSQL_DB: redakce
      ALLOW_INSERT_OPERATION: "false"
      ALLOW_UPDATE_OPERATION: "false"
      ALLOW_DELETE_OPERATION: "false"
      ALLOW_DDL_OPERATION: "false"
```

### 3. Obrazy
- `docker/php/Dockerfile` (multi-stage dle kontraktu):
  - `base` — `php:8.4-fpm-trixie` (Debian 13 = stejná distribuce jako VPS; Alpine by později
    potřeboval `icu-data-full` kvůli českému formátování v `intl`), rozšíření `pdo_mysql`, `opcache`,
    ne-root uživatel `app` (build args `HOST_UID/HOST_GID`, výchozí 1000), `WORKDIR /app`,
    `expose_php=Off`, ověřit `clear_env = no` (proměnné prostředí musí dojít do PHP).
  - `dev` — `COPY --from=composer:2 /usr/bin/composer`, `git`, `unzip`, `php.ini-development`.
    Xdebug v M1 ne (kontrakt: „Xdebug vyp.“; doplní se, až bude potřeba).
  - `prod` — kód + `composer install --no-dev --classmap-authoritative` (builder stage),
    `php.ini-production`, ne-root. V M1 jen musí jít sestavit (AC 8); používá ho CI (plán 002) a M9.
- `docker/nginx/nginx.conf` + `docker/nginx/default.conf` — `root /app/public`,
  `try_files $uri /index.php$is_args$args`, FastCGI **jen** pro `/index.php` (`location = /index.php`),
  ostatní `*.php` → 404, `server_tokens off`, pid a temp cesty v `/tmp` (běh pod uživatelem 101).
  Prod `docker/nginx/Dockerfile` je M9.
- `docker/mariadb/conf.d/redakce.cnf` — `character-set-server=utf8mb4`,
  `collation-server=utf8mb4_czech_ci`.
- `docker/mariadb/init/01-uzivatele.sh` — z env (`DB_PASSWORD`, `DB_MIGRACE_PASSWORD`,
  `DB_READONLY_PASSWORD`) vytvoří uživatele `redakce_app` (DML na `redakce.*` a `redakce_test.*`),
  `redakce_migrace` (DDL na obě DB), `redakce_cteni` (`SELECT` na `redakce.*`) a DB `redakce_test`.
  Hesla se předávají jen přes proměnné, nikdy do logu (`set +x`). Běží jen při prvním vytvoření volume.
- `docker/mcp/mariadb/Dockerfile` — `node:<aktuální LTS>-alpine`,
  `npm install -g @benborla29/mcp-server-mysql@<přesná verze>` (npm běží jen při buildu v Dockeru),
  `USER node`, `ENTRYPOINT` na binárku serveru.
- `.dockerignore` — `.git`, `vendor`, `var`, `.env*` (kromě `.env.example`), `.claude/logs`, `tests/_artefakty`.

### 4. Makefile
Všechny cíle volají `docker compose …`; na hostiteli se nespouští nic jiného.

| Cíl | Chování |
|---|---|
| `help` (výchozí) | seznam cílů s popisem |
| `up` | `.env` z `.env.example`, pokud chybí → `docker compose up -d --build db app` → `composer install` v `app` → `docker compose up -d --wait` (řeší slepici a vejce: healthcheck `web` potřebuje `vendor/autoload.php`) |
| `down` | `docker compose down` (nikdy `-v`) |
| `sh` | shell v `app` |
| `composer` | `docker compose exec app composer $(ARGS)` — funguje s TTY i bez (agenti) |
| `check` / `test` / `qa` / `fix` | `composer check` / `test` / `qa` / `cs:fix` v `app` |
| `logs` / `ps` | logy (`--tail=100 -f`) / stav služeb |
| `mcp` | předstažení/sestavení obrazů profilu `mcp` (`build` + `pull --ignore-buildable`) |
| `test-hooks` | `bash tests/Hooks/scenare.sh` (na hostiteli, hooky jsou bash) |

`migrate` a `seed` přibudou v M2 s migrátorem (žádné atrapy).
Proměnná `COMPOSE_PROJECT_NAME` z prostředí musí projít (AC 36).

### 5. Composer a nástroje kvality
- `composer.json`: `"name": "t360/redakcni-system"`, `"type": "project"`, `require`:
  `php: ~8.4.0`, `ext-pdo`, `ext-pdo_mysql`, `ext-json`; `require-dev`: `phpunit/phpunit ^13`
  (vyžaduje PHP 8.4), `phpstan/phpstan ^2`, `friendsofphp/php-cs-fixer ^3`;
  autoload `App\\` → `src/`, `App\\Tests\\` → `tests/`; `config.platform.php: 8.4.x`,
  `sort-packages: true`.
- Skripty: `lint` (php -l nad `src tests public` paralelně, tiše při úspěchu), `cs` (`--dry-run --diff`),
  `cs:fix`, `stan` (`--memory-limit=512M`), `check` = lint + cs + stan, `test` = phpunit,
  `qa` = check + test + `composer audit`.
- `phpstan.neon.dist`: `level: max`, `paths: [src, tests, public]`, bez baseline a bez `ignoreErrors`.
- `.php-cs-fixer.dist.php`: `@PER-CS` + migrační sada pro PHP 8.4 (název sady ověřit v nainstalované
  verzi — nové verze používají tvar `@PHP8x4Migration`), `declare_strict_types`,
  `ParallelConfigFactory::detect()`, cache do `var/`.
- `phpunit.xml.dist`: sady `Unit` a `Integration`, `failOnWarning`, `failOnRisky`,
  `cacheDirectory=var/phpunit`, `<env name="DB_NAME" value="redakce_test" force="true"/>`,
  `<env name="AI_PROVIDER" value="falesny" force="true"/>`.

### 6. Kostra aplikace (PHP, identifikátory dle ADR-0003)
Žádný router ani DI kontejner (M2) — `Kernel` má v M1 jednu pevnou cestu, M2 ho nahradí routerem.

| Soubor | Typ | Odpovědnost |
|---|---|---|
| `public/index.php` | front controller | autoload → `DatabaseConfig::fromEnvironment(getenv())` → sestavení objektů ručně → `Kernel::handle(Request::fromGlobals())->send()`; nezachycená výjimka → `500` `text/plain` „Interní chyba serveru“, detail jen do `error_log` (stderr kontejneru) |
| `src/Http/Request.php` | `final readonly class Request` | `method`, `path` (bez query); `fromGlobals()` je jediné místo se `$_SERVER` |
| `src/Http/Response.php` | `final readonly class Response` | `status`, `headers`, `body`; `json(array $data, int $status = 200)` (`JSON_THROW_ON_ERROR`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`), `text(…)`, `send()` |
| `src/Http/Kernel.php` | `final readonly class Kernel` | `handle(Request): Response` — `GET /zdravi` → `HealthController`; jiná metoda na `/zdravi` → `405` + `Allow: GET`; jinak `404` |
| `src/Http/Controller/HealthController.php` | `final readonly class` | `__invoke(): Response` — `DatabaseHealth::isReachable()` → `200 {"stav":"ok","db":"ok"}` / `503 {"stav":"chyba","db":"chyba"}` (klíče a hodnoty jsou zamčený kontrakt) |
| `src/Domain/Health/DatabaseHealth.php` | `interface` | `isReachable(): bool` — dvě reálná použití: PDO implementace a testovací náhrada v unit testech |
| `src/Infrastructure/Persistence/PdoDatabaseHealthRepository.php` | `final readonly class … implements DatabaseHealth` | připojí se přes `ConnectionFactory`, provede `SELECT 1` (SQL jen v `*Repository` dle AGENTS.md); `PDOException` → `false` + `error_log` jen s kódem chyby |
| `src/Infrastructure/Persistence/ConnectionFactory.php` | `final readonly class` | `create(): \PDO` — líné připojení, DSN s `charset=utf8mb4`, `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, `ATTR_TIMEOUT=2`; použijí ho i repozitáře od M2 |
| `src/Infrastructure/Config/DatabaseConfig.php` | `final readonly class` | `host`, `port`, `name`, `user`, `#[\SensitiveParameter] password`; `fromEnvironment(array $env)` |
| `src/Infrastructure/Config/MissingConfiguration.php` | `final class … extends \RuntimeException` | hláška jmenuje chybějící proměnnou, nikdy hodnotu |

Tok požadavku: diagram v [architektura.md §3](../architektura.md).

Testy (píše tester, názvy anglicky dle ADR-0003):
- `tests/Unit/Http/KernelTest.php` — 200/ok, 503/chyba (náhrada `DatabaseHealth`), 404, 405 + `Allow`, hlavičky.
- `tests/Unit/Http/RequestTest.php` — cesta bez query stringu, metoda velkými písmeny.
- `tests/Unit/Infrastructure/Config/DatabaseConfigTest.php` — chybějící proměnná → výjimka s názvem
  proměnné; hláška nikdy neobsahuje heslo.
- `tests/Integration/Persistence/PdoDatabaseHealthRepositoryTest.php` — skutečná MariaDB → `true`;
  nedostupný port (`db:1`) → `false` do 3 s.
- `tests/Hooks/scenare.sh` — kritéria 22–27 (spouští `make test-hooks`).
- `tests/E2E-scenare.md` — scénář „Zdraví aplikace“ (curl + Playwright na `http://web/zdravi`).

### 7. Konfigurace prostředí — `.env.example`
Dev sekce (ukázkové, ne-tajné hodnoty, verzují se): `APP_ENV=dev`, `APP_DEBUG=1`,
`APP_URL=http://localhost:8080`, `HOST_UID=1000`, `HOST_GID=1000`, `DB_HOST=db`, `DB_PORT=3306`,
`DB_NAME=redakce`, `DB_USER=redakce_app`, `DB_PASSWORD`, `DB_MIGRACE_PASSWORD`,
`DB_READONLY_PASSWORD`, `MARIADB_ROOT_PASSWORD`, `AI_PROVIDER=falesny`.
Sekce `# PRODUKCE` přesně podle kontraktu (zakomentované, bez hodnot): `APP_ENV APP_DEBUG APP_URL
IMAGE_OWNER MARIADB_ROOT_PASSWORD DB_PASSWORD DB_MIGRACE_PASSWORD DB_READONLY_PASSWORD AI_PROVIDER
ANTHROPIC_API_KEY AI_MODEL AI_MODEL_LEVNY AI_DENNI_LIMIT_TOKENU WEB_PORT TRUSTED_PROXIES
COMPOSE_PROFILES DOMENA`.

### 8. Změny konfigurace agentů (vyžadují souhlas člověka — úkol T4)
| Soubor | Změna | Proč |
|---|---|---|
| `.mcp.json` | `context7` → `{"type":"http","url":"https://mcp.context7.com/mcp"}`; `github` beze změny | žádný Node na hostiteli |
| `.claude/agents/tester.md` | `tools: …, mcp__playwright`; `mcpServers.playwright` → `command: docker`, `args: ["compose","-f","${CLAUDE_PROJECT_DIR:-.}/compose.yaml","run","--rm","-T","mcp-playwright"]`; v textu `http://localhost:8080` → `http://web` pro Playwright (curl z hostitele zůstává `localhost:8080`); názvy testů anglicky (ADR-0003) | MCP v Dockeru; bez `mcp__playwright` v `tools` subagent nástroje nedostane |
| `.claude/agents/databazista.md` | `tools: …, mcp__mariadb-cteni`; `mcpServers.mariadb-cteni` → `docker compose -f … run --rm -T mcp-mariadb`, blok `env` pryč (je v compose) | totéž; heslo jen v `.env` |
| `.claude/settings.local.json.example` | odebrat `DB_READONLY_PASSWORD` | heslo už Claude Code nepotřebuje |
| `.claude/settings.json` | `deny` + `Bash(php *)`, `Bash(composer *)`, `Bash(node *)`, `Bash(npm *)`, `Bash(npx *)`; z `ask` pryč `Bash(npm install *)`, přidat `Bash(docker compose run *)`; zúžit `allow` `Bash(docker compose *)` na `exec`, `ps`, `logs`, `up`, `stop`, `start`, `restart`, `build`, `pull`, `images`, `port`, `config --quiet` (otázka 11) | pravidlo 0; Docker socket = root |
| `.claude/hooks/php-lint.sh` | odstranit fallback na hostitelské `php`; neběží-li `app` → JSON `additionalContext` „lint neproběhl, spusť make up“; zjednodušit duplicitní podmínku `docker compose ps` | nic se nekontroluje mimo Docker a nic „tiše neprojde“ |
| `.claude/hooks/rychla-kontrola.sh` | bez `compose.yaml` → `exit 0`; neběží-li `app` nebo chybí `vendor/` → `exit 2` s návodem (počítadlo max 3); odstranit `-q`, aby výstup nesl názvy selhání | fail-closed |
| `.claude/hooks/bash-strazce.sh` | zakázat hostitelské `php|composer|node|npm|npx` na začátku příkazu i za `; & |`; `--no-verify`; `docker compose config` bez `--quiet`/`--no-interpolate`; `docker compose run` s `-v/--volume/--privileged/--cap-add/--pid/--network host`; `make composer ARGS=*require*` → `ask` | permissions kontrolují prefix; hook i řetězce a obchvat přes make |
| `.githooks/pre-commit` | neběží-li `app` a jsou staged `.php` → `exit 1` s „spusť make up“ | žádné tiché projití |
| `.claude/skills/devops-kontrakt/SKILL.md` | doplnit řádky `adminer`, `mcp-*`, `name: t360`, `container_name` pro dev (dle ADR-0002) | kontrakt se nemění bez ADR |
| `.claude/skills/php-oop-standardy/SKILL.md`, `.claude/rules/php.md`, `.claude/agents/architekt.md` | anglické názvy dle mapování v ADR-0003 | soulad s AGENTS.md |

Po změně definic agentů a `.mcp.json` je nutná **nová relace Claude Code spuštěná z kořene repa**.

### 9. Změny DB
Žádné migrace (migrátor je M2). Jen inicializace serveru: znaková sada/collation, DB `redakce`
a `redakce_test`, tři uživatelé. Je to těžko vratné (collation DB se po vytvoření mění ručně) —
proto už v M1.

## Dotčené soubory
**Nové:**
`compose.yaml`, `Makefile`, `.dockerignore`, `.env.example`, `composer.json`, `composer.lock`,
`phpunit.xml.dist`, `phpstan.neon.dist`, `.php-cs-fixer.dist.php`,
`docker/php/Dockerfile`, `docker/php/conf.d/app.ini`, `docker/nginx/nginx.conf`, `docker/nginx/default.conf`,
`docker/mariadb/conf.d/redakce.cnf`, `docker/mariadb/init/01-uzivatele.sh`, `docker/mcp/mariadb/Dockerfile`,
`public/index.php`, `src/Http/{Request,Response,Kernel}.php`, `src/Http/Controller/HealthController.php`,
`src/Domain/Health/DatabaseHealth.php`, `src/Infrastructure/Persistence/{ConnectionFactory,PdoDatabaseHealthRepository}.php`,
`src/Infrastructure/Config/{DatabaseConfig,MissingConfiguration}.php`,
`tests/Unit/…`, `tests/Integration/…`, `tests/Hooks/scenare.sh`, `tests/E2E-scenare.md`,
`README.md`, `docs/adr/0002-…`, `docs/adr/0003-…`, `docs/architektura.md`.

**Změněné:** `.gitignore` (případně `var/` už je; ověřit `tests/_artefakty/`), `.mcp.json`,
`.claude/settings.json`, `.claude/settings.local.json.example`, `.claude/hooks/{php-lint,rychla-kontrola,bash-strazce}.sh`,
`.githooks/pre-commit`, `.claude/agents/{tester,databazista,architekt}.md`,
`.claude/skills/{devops-kontrakt,php-oop-standardy}/SKILL.md`, `.claude/rules/php.md`,
`START-ZDE.md` (pryč instalace Node.js a `DB_READONLY_PASSWORD`; „spouštěj claude z kořene“),
`docs/tutorial.html` (kapitola M1 přes značky `<!-- AGENT: … -->`; kapitola Nasazení beze změny).

## Úkoly pro agenty
Brána 1 (člověk) schvaluje: tento plán, ADR-0002, ADR-0003 a závislosti z otázky 1.

| # | Fáze | Agent | Úkol | Výstup | Souběh |
|---|---|---|---|---|---|
| T1 | 1 | `devops` | Docker (§2–3), `.dockerignore`, `.env.example` (§7), `Makefile` (§4), `composer.json` + konfigurace nástrojů (§5), `composer.lock` přes `make composer ARGS="update"` v kontejneru, služby profilu `mcp` | `docker compose config --quiet` OK; `docker compose up -d --wait db app` OK; AC 2–9 kromě healthchecku `web` (čeká na T3); verze pinnutých obrazů a npm balíčku | ∥ T2, T4a |
| T2 | 1 | `tester` (režim A) | testy z §6 (unit, integrační, `tests/Hooks/scenare.sh`, E2E scénář) | soubory testů; po dokončení T1 doložit RED ze správného důvodu (chybí třídy) | ∥ T1, T4a |
| T4a | 1 | `devops` (**souhlas člověka**, hook `chran-soubory` se zeptá) | hooky + `settings.json` + `.githooks/pre-commit` dle §8 | diff + výstup `make test-hooks` (po T1/T2) | ∥ T1, T2 |
| T3 | 2 | `programator` | kostra §6 až do GREEN | `make qa` zelené; AC 10–16 | po T1+T2; ∥ T4b |
| T4b | 2 | `devops` (**souhlas člověka**) | `.mcp.json`, frontmatter `tester`/`databazista`, `settings.local.json.example`, rozšíření `devops-kontrakt`; úpravy dle ADR-0003 ve skillu/rules/architekt (pokud člověk ADR přijme) | diff + AC 30–32 a 35 | po T1; ∥ T3 |
| — | — | člověk | **restart relace Claude Code z kořene repa** (nové MCP a agenti) | `/mcp`, `/hooks` | po T4b |
| T5 | 3 | `tester` (režim B) | `make qa`, AC A–E (curl, hooky, MCP smoke, Playwright, mariadb-cteni) | PASS/FAIL po kritériích; při FAIL vrátit T3/T1/T4 | po restartu |
| T6 | 4 | `security-reviewer` | revize `git diff` (OWASP, Docker hardening, LLM/MCP rizika, únik tajemství) | verdikt + nálezy | ∥ T7 |
| T7 | 4 | `technicky-spisovatel` | `README.md` (rychlý start dle AC 37), `START-ZDE.md`, kapitola M1 v `docs/tutorial.html`, slovníček (Docker, compose profil, healthcheck, MCP stdio) — vše ověřené spuštěním | seznam ověřených příkazů | ∥ T6 |
| — | 5 | vedoucí | report → **brána 2** → commity (níže) | — | — |
| T8 | 6 | `tester` | AC 36–37 čistý klon nad commitnutým stavem | PASS/FAIL | po commitech |

Návrh commitů (každý musí sám o sobě projít `make up` a `make qa`; pokud 2 bez 3 neprojde
healthcheckem/PHPStanem, sloučit je do jednoho):
1. `chore(agenti): hooky a MCP servery jen v Dockeru` (T4a + T4b; hooky bez `compose.yaml` neblokují)
2. `build(docker): vývojové prostředí, Makefile a nástroje kvality` (T1)
3. `feat(zdravi): kostra aplikace a endpoint /zdravi` (T2 + T3)
4. `docs: README, START-ZDE, tutoriál M1, ADR-0002/0003 a architektura` (T7 + tento plán)

## Rizika a bezpečnost
- **Relace Claude Code nespuštěná z kořene repa.** Tento architekt běžel s pracovním adresářem
  `…/t360-php-agent-rs/.claude` (paměť agenta se ukládá do `.claude/.claude/agent-memory/`).
  Pak se projektová `.claude/settings.json` nenačte → **hooky ani permissions neplatí**. Ověřit `/hooks`;
  README a START-ZDE to musí říkat výslovně.
- **Docker socket = root na hostiteli.** `Bash(docker compose *)` dovoluje
  `docker compose run -v /:/host …`. Zúžit allowlist a doplnit hook (§8). `docker inspect` a
  `docker compose config` vypisují hesla z `.env` → `config` jen s `--quiet`, `inspect` není v allow
  (zeptá se člověka).
- **Obchvat brány závislostí:** `make composer ARGS="require …"` obejde `ask` pravidlo
  `docker compose exec * composer require *` → hook `ask`.
- **Expozice portů:** `8080:80` naslouchá na všech rozhraních (dostupné z LAN). Adminer a DB jen
  `127.0.0.1`. Otázka 4.
- **Únik informací z `/zdravi`:** žádné verze, výjimky ani DSN v těle; chyba jen do `error_log`
  s kódem. `#[\SensitiveParameter]` u hesla. Každý požadavek otevírá DB spojení (timeout 2 s) —
  nízké riziko, rate limiting až M3.
- **Spouštění PHP mimo `index.php`:** nginx předává FastCGI jen `= /index.php`; `vendor/`, `src/`
  mimo web root (AC 14).
- **Dodavatelský řetězec:** pinnuté tagy obrazů (ideálně i digest), pinnutá verze npm balíčku MCP,
  `composer.lock`, `composer audit` v `make qa`. Playwright **bez** `--pull=always`.
- **LLM / agentní rizika (OWASP LLM Top 10):** LLM01 prompt injection — obsah stránek
  (Playwright) i dat v DB (mariadb-cteni) je nedůvěryhodný, agenti se jím neřídí (CLAUDE.md);
  Playwright běží jen v síti projektu, zvážit `--allowed-origins http://web` (ověřit, že volba
  v pinnuté verzi existuje). LLM06 přílišná pravomoc — DB uživatel `redakce_cteni` má jen `SELECT`
  (dvojí pojistka s `ALLOW_*_OPERATION=false`); od M3 omezit sloupcová práva (hash hesla, audit).
  LLM02 únik citlivých dat — dotazy do vzdáleného context7 nesmí obsahovat tajemství ani kód s nimi.
- **Fail-closed hooky** mohou zablokovat práci, když Docker neběží — pojistka max. 3 vrácení,
  pak eskalace vedoucímu.
- **Slepice a vejce při čistém klonu:** healthcheck `web` potřebuje `vendor/` → pořadí v `make up` (§4).
- **Init skript DB běží jen jednou** (prázdný volume). Změna hesel v `.env` později = nesoulad;
  README popíše postup (smazání volume schvaluje člověk).
- **`${CLAUDE_PROJECT_DIR:-.}` ve frontmatteru agentů:** expanze je zdokumentovaná pro `.mcp.json`;
  pro inline definice ji ověřit (AC 33/34). Záloha: relativní `compose.yaml` (relace z kořene repa).
- **Playwright v kontejneru:** vlastnictví snímků na bind-mountu (UID), `--no-sandbox`,
  velikost obrazu (stovky MB) → `make mcp` předem, jinak timeout startu MCP.
- **WSL2 / Docker Desktop:** mapování UID na bind-mountu se liší od Docker Engine; repo musí ležet
  v Linuxovém FS (ne `/mnt/c`) kvůli výkonu.
- **Rozpor v podkladech:** skill `db-migrace` uvádí migrátor „v M1“, `zadani.md` v M2 — plán drží
  `zadani.md` (otázka 8).

## Mimo rozsah
- `.github/workflows/ci.yml` (zadání ho řadí do M1) → samostatný plán **002** (změna workflow =
  brána člověka; otázka 9).
- `compose.prod.yaml`, `docker/nginx/Dockerfile`, `deploy.yml`, `scripts/vps/nasad.sh` (M9).
- Router, DI kontejner, middleware, šablony, chybové stránky, `bin/konzole`, migrátor,
  `make migrate`/`make seed` (M2).
- Bezpečnostní hlavičky a CSP v plném rozsahu, session, CSRF (M3); v M1 jen `nosniff` u JSON.
- Profil `ollama` (M6), Xdebug, `phpstan-strict-rules`.

## Otázky pro člověka
1. **Schválení nových závislostí** (nic z toho v repu zatím není):
   - Docker obrazy: `php:8.4-fpm-trixie`, `composer:2` (jen zdroj binárky), `nginx:1.<stable>-alpine`,
     `mariadb:11.8`, `adminer:5` (jen dev), `mcr.microsoft.com/playwright/mcp:<pevná verze>`,
     `node:<LTS>-alpine` (základ obrazu `mcp-mariadb`) + npm balíček `@benborla29/mcp-server-mysql@<pevná verze>`
     instalovaný při buildu.
   - Composer (`require-dev`): `phpunit/phpunit ^13`, `phpstan/phpstan ^2`, `friendsofphp/php-cs-fixer ^3`.
   - Vzdálená služba: `https://mcp.context7.com/mcp` (třetí strana; bez API klíče s nižšími limity).
2. **Hostitel:** pravidlo 0 jmenuje jen docker, git, bash, jq. Kritéria i AGENTS.md ale potřebují
   **`make`** a **`curl`**. Rozšířit povolený seznam o `make` a `curl`? (Doporučuji ano; alternativou
   je `./bin/dev` skript a `wget` v kontejneru.)
3. **context7:** A) vzdálený HTTP server (doporučeno — na hostiteli ani v Dockeru nic neběží),
   nebo B) vlastní obraz `mcp-context7` v compose (`node:<LTS>-alpine` + `@upstash/context7-mcp`)?
4. **Port webu:** doslova `8080:80` (všechna rozhraní, workspace pravidlo), nebo bezpečnější
   `127.0.0.1:8080:80` (ve WSL2 funguje i z prohlížeče ve Windows)? Adminer `127.0.0.1:8081` je OK?
5. **`container_name` v produkci (M9):** doporučuji jen pro dev `compose.yaml`; `compose.prod.yaml`
   zůstává podle kontraktu (`name: redakce`). Souhlas?
6. **Port DB `127.0.0.1:3307`:** po přesunu MCP do sítě compose ho nic nepotřebuje. Ponechat
   (kontrakt; případný DB klient člověka), nebo odebrat novým ADR? Doporučuji ponechat.
7. **ADR-0003:** přijmout anglické identifikátory? A jak pojmenovat **tabulky a sloupce DB**
   (skill `db-migrace` česky vs. workspace pravidlo anglicky) — rozhodnout před M2.
8. **Migrátor:** M2 podle `zadani.md` (plán tak počítá), ne M1 podle skillu `db-migrace` — potvrdit.
9. **CI:** samostatný plán 002? A má CI běžet „taky v Dockeru“ (`docker compose` v runneru, stejné
   obrazy jako lokálně) místo `shivammathur/setup-php` z kontraktu? (Změna kontraktu = ADR.)
10. **Fail-closed hooky:** souhlas, že `rychla-kontrola` zablokuje programátora (max. 3×), když
    `app` neběží, a `pre-commit` zablokuje commit PHP souborů bez běžícího `app`?
11. **Zúžení allowlistu** `Bash(docker compose *)` na konkrétní podpříkazy a `docker compose run` → `ask`?
12. **nginx jako ne-root:** oficiální `nginx` pod UID 101 s pid/temp v `/tmp` (zachová kontrakt
    `8080:80`, doporučeno), nebo `nginxinc/nginx-unprivileged` (další obraz, interně port 8080)?
13. **Spouštění Claude Code:** potvrďte, že relace poběží z `/home/q/projects/t360-php-agent-rs`
    (ne z `.claude/`) — jinak hooky a permissions neplatí.

## Rozhodnutí člověka (2026-10-03)
Člověk schválil plán, ADR-0002, ADR-0003 i všechny závislosti („schvaluji vše“). Otázky 1–13
se řeší podle doporučení v textu plánu (make+curl na hostiteli, context7 vzdáleně, DB port 3307
ponechán, CI jako samostatný plán 002, fail-closed hooky, zúžený allowlist, nginx UID 101,
relace z kořene repa). Tabulky a sloupce DB: anglicky (ADR-0003), upřesní se před M2.
