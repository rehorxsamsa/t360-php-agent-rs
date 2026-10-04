# Redakční systém, který napsali agenti

Jednoduchý redakční systém (CMS) v čistém PHP 8.4, MariaDB 11.8 a Dockeru. Celý ho staví
tým agentů v Claude Code, člověk zadává a schvaluje. Podrobný návod je v
[`docs/tutorial.html`](docs/tutorial.html), pravidla pro agenty v [`AGENTS.md`](AGENTS.md)
a [`CLAUDE.md`](CLAUDE.md).

## Požadavky na hostiteli
Na svém počítači (Linux nebo WSL2) potřebuješ jen:

- `docker` (s pluginem `docker compose`)
- `git`
- `bash`
- `jq`
- `make`
- `curl`

PHP, Composer, Node ani npm **neinstaluj**. Všechno ostatní běží v kontejnerech.
Repozitář měj v Linuxovém souborovém systému (ve WSL2 ne v `/mnt/c`).

## Rychlý start
```bash
git clone <adresa-repozitáře> redakcni-system
cd redakcni-system
git config core.hooksPath .githooks   # git hooky (formát commitu, kontrola PHP)
make up                               # .env, sestavení obrazů, composer install, start služeb
make qa                               # php -l, PHP-CS-Fixer, PHPStan, PHPUnit, composer audit
make migrate                          # vytvoří databázové schéma (migrace z database/migrations/)
make seed                             # nahraje ukázková data (16 článků; jen dev, opakovat lze bezpečně)
make mcp                              # jednorázově předstáhne obrazy MCP serverů (Playwright, MariaDB)
```

`make up` při prvním spuštění zkopíruje `.env.example` do `.env` a počká, až jsou všechny
služby zdravé. Další cíle vypíše `make help`.

| Co | Adresa |
|---|---|
| Aplikace (titulní stránka, 10 článků na stranu) | <http://localhost:8080/> |
| Starší články (stránkování) | <http://localhost:8080/?strana=2> |
| Detail článku | <http://localhost:8080/clanek/ukazka-markdownu> |
| Kontrola zdraví | <http://localhost:8080/zdravi> |
| Administrace (jen přihlášený admin) | <http://localhost:8080/admin> |
| Přihlášení do administrace | <http://localhost:8080/admin/prihlaseni> |
| Správa článků (seznam, úprava, smazání) | <http://localhost:8080/admin/clanky> |
| Nový článek | <http://localhost:8080/admin/clanky/novy> |
| Audit log (jen admin, filtr podle akce a data) | <http://localhost:8080/admin/audit> |
| AI nástroje (přehled, spotřeba tokenů, poslední volání) | <http://localhost:8080/admin/ai> |
| AI příklad 01–05 (např. perex) | <http://localhost:8080/admin/ai/01> |
| Adminer (správa databáze) | <http://localhost:8081> |

Správná odpověď aplikace je `{"stav":"ok","db":"ok"}`. Do Admineru se přihlásíš
serverem `db`, uživatelem `redakce_app` a heslem `DB_PASSWORD` z `.env`.

## První administrátor
Do administrace se nelze zaregistrovat, účet vytvoří příkaz v konzoli (po `make migrate`):

```bash
docker compose exec -T app php bin/konzole admin:vytvor --email=admin@example.cz --jmeno=Administrátor --heslo=dlouhe-heslo-12
```

Heslo musí mít aspoň 12 znaků. Bez `--heslo` se heslo vygeneruje a vypíše jen jednou. Pak se přihlas na
<http://localhost:8080/admin/prihlaseni>. Heslo zadané přes `--heslo` zůstane v historii shellu, pro skutečné
nasazení proto použij vygenerované.

Session cookie má v dev režimu `HttpOnly; SameSite=Strict`, ale ne `Secure` (vývoj běží přes HTTP).
Je to záměr: dev compose proměnnou `SESSION_COOKIE_SECURE` kontejneru `app` vůbec nepředává a soubor `.env`
kontejner nevidí (hodnoty dostává jen přes `environment:` v `compose.yaml`), takže řádek v `.env` by nic nezměnil.
Produkční `compose.prod.yaml` (M9) za HTTPS musí dát `SESSION_COOKIE_SECURE: "1"` do `environment:` služby `app`
(čte ji jen `config/container.php`), jinak prohlížeč cookie posílá i po HTTP.

**Časy:** audit log (`audit_log.created_at`) se v databázi ukládá v UTC, protože čas doplňuje databáze. Na stránce
`/admin/audit` se zobrazuje v pražském čase (Europe/Prague), takže se hodnota v Admineru liší o 1–2 hodiny.
Pravidlo, proč a kde se čas převádí, je v [ADR-0007](docs/adr/0007-casy-v-databazi-utc-vs-praha.md).

## AI příklady (M6)
Pět AI příkladů (perex, SEO, štítky a rubrika, kontrola před publikací, překlad) běží **bez API klíče**
přes falešný klient (`AI_PROVIDER=falesny`, nic se neúčtuje, cena je jen orientační). Spuštění z konzole
(po `make migrate`):

```bash
docker compose exec -T app php bin/konzole ai:priklad 01
docker compose exec -T app php bin/konzole ai:priklad 04 --clanek=demo-injection
docker compose exec -T app php bin/konzole ai:priklad 05 --model=claude-haiku-4-5-20251001
```

Volby: `--clanek=demo|demo-injection|ID` (výchozí `demo`), `--model=ID` (jen příklad 05). V prohlížeči
se přihlas jako admin a otevři `/admin/ai`. Proměnné v `.env` (výchozí hodnoty jsou v `.env.example`):

| Proměnná | Význam |
|---|---|
| `AI_PROVIDER` | `falesny` (výchozí, bez sítě) nebo `anthropic` (skutečné Claude API) |
| `ANTHROPIC_API_KEY` | klíč k API, potřebný jen pro `anthropic`; **jen v `.env`, nikdy v repozitáři** |
| `AI_MODEL` | model pro generování textu (výchozí `claude-sonnet-5-5`) |
| `AI_MODEL_LEVNY` | levnější model pro klasifikaci (výchozí `claude-haiku-4-5-20251001`) |
| `AI_DENNI_LIMIT_TOKENU` | denní limit tokenů všech volání (výchozí `200000`) |

Skutečné API zapneš tak, že do `.env` doplníš `AI_PROVIDER=anthropic` a `ANTHROPIC_API_KEY=…` a spustíš `make up`.
Kvůli ceně se po každém volání zapisují do tabulky `ai_calls` jen metadata (tokeny, cena, trvání), nikdy texty.
Výklad je v kapitole „AI jádro“ v [`docs/tutorial.html`](docs/tutorial.html).

Testy: `make test` (nebo `make qa`). Integrační testy schématu mažou a znovu vytvářejí tabulky
v databázi `redakce_test`, proto dvě sady testů nesmí běžet paralelně nad `redakce_test`.

Zastavení prostředí: `make down`. Data databáze zůstanou v Docker volume `t360_db_data`.

## Claude Code
**Claude Code spouštěj z kořene repa** (`cd redakcni-system && claude`). Jen tam se načte
`.claude/settings.json` s oprávněními a hooky a `.mcp.json` s MCP servery. Spustíš-li ho jinde,
pojistky neplatí. Po spuštění ověř `/hooks` a `/mcp`. Více v [`START-ZDE.md`](START-ZDE.md).

Agenti i hooky používají PHP jen v kontejneru `app`, takže při práci s agenty musí
prostředí běžet (`make up`).

## Databáze: init skript a hesla
Při **prvním** vytvoření volume `t360_db_data` spustí MariaDB skript
[`docker/mariadb/init/01-uzivatele.sh`](docker/mariadb/init/01-uzivatele.sh). Vytvoří databáze
`redakce` a `redakce_test` (kolace `utf8mb4_czech_ci`) a tři uživatele: `redakce_app` (DML),
`redakce_migrace` (DDL) a `redakce_cteni` (jen `SELECT`). Hesla bere z `.env`.

Skript už podruhé neběží. Změna hesla v `.env` po vytvoření databáze proto **nezmění**
heslo v databázi a aplikace se přestane připojovat. Hesla v `.env.example` jsou ukázková
a pro vývoj stačí. Chceš-li začít znovu s novými hesly, smaž volume: `make down` a
`docker volume rm t360_db_data` (smažeš tím všechna data).

## Kde co leží
| Cesta | Obsah |
|---|---|
| `src/`, `public/`, `tests/` | aplikace, front controller, testy |
| `config/` | kompoziční kořen: `container.php` (kontejner), `routes.php` (trasy) |
| `templates/` | PHP šablony (`layout`, `home`, `article`, `error`, `admin/`, `admin/articles/`, `admin/ai/`), výstup přes `e()` |
| `database/seeds/` | ukázková data (`demo_content.php`), nahrává je `make seed` |
| `database/migrations/` | migrace schématu (`RRRRMMDDHHMM_popis.php`) |
| `bin/konzole` | CLI: `migrace:spust`, `migrace:vrat [--kroky=N]`, `migrace:stav`, `admin:vytvor`, `db:seed`, `ai:priklad NN` |
| `docker/`, `compose.yaml`, `Makefile` | prostředí v Dockeru |
| `.claude/`, `.mcp.json`, `.githooks/` | tým agentů, hooky, MCP servery |
| `docs/` | zadání, architektura, ADR, plány, tutoriál |
