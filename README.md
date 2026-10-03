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
make mcp                              # jednorázově předstáhne obrazy MCP serverů (Playwright, MariaDB)
```

`make up` při prvním spuštění zkopíruje `.env.example` do `.env` a počká, až jsou všechny
služby zdravé. Další cíle vypíše `make help`.

| Co | Adresa |
|---|---|
| Aplikace (kontrola zdraví) | <http://localhost:8080/zdravi> |
| Adminer (správa databáze) | <http://localhost:8081> |

Správná odpověď aplikace je `{"stav":"ok","db":"ok"}`. Do Admineru se přihlásíš
serverem `db`, uživatelem `redakce_app` a heslem `DB_PASSWORD` z `.env`.

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
| `docker/`, `compose.yaml`, `Makefile` | prostředí v Dockeru |
| `.claude/`, `.mcp.json`, `.githooks/` | tým agentů, hooky, MCP servery |
| `docs/` | zadání, architektura, ADR, plány, tutoriál |
