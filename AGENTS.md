# AGENTS.md — Redakční systém (pravidla pro každého AI agenta)

> Tento soubor je **nástrojově nezávislý** (formát AGENTS.md čte Claude Code, Codex, Cursor,
> Gemini CLI a další). Claude-specifické věci jsou v `CLAUDE.md`, který tento soubor importuje.

## Co stavíme
Jednoduchý **redakční systém (CMS)** v čistém PHP 8.4 OOP bez frameworku, MariaDB 11.8,
vše v Dockeru. Veřejnost čte publikované články. **Jen role `admin`** může vytvářet, měnit
a mazat obsah (CRUD). Aplikace obsahuje 10 spustitelných **AI příkladů** a výukový
tutoriál `docs/tutorial.html`. Zadání: `docs/zadani.md`.

## Příkazy (vše běží v kontejnerech)
| Účel | Příkaz |
|---|---|
| Start prostředí | `make up` (= `docker compose up -d --build`) |
| Stop | `make down` |
| Závislosti | `make composer ARGS="install"` |
| Rychlá kontrola | `make check` (php -l, php-cs-fixer --dry-run, phpstan) |
| Testy | `make test` (PHPUnit unit + integrační) |
| Kompletní brána | `make qa` (check + test + composer audit) |
| Migrace | `make migrate` |
| Konzole | `docker compose exec app php bin/konzole <prikaz>` |

> Dokud DevOps agent `Makefile` nevytvoří, používej přímé `docker compose …` příkazy.

## Konvence kódu
- `declare(strict_types=1);` v každém PHP souboru, PSR-4 autoload (`App\` → `src/`), PSR-12 / PER-CS.
- Třídy `final` a `readonly`, kde to jde. Konstruktorová injekce, žádné statické singletony.
- Žádné SQL mimo třídy `*Repository`. Vždy PDO prepared statements.
- Výstup do HTML **vždy** přes escapovací helper `e()`. Žádné `echo $promenna` bez escapování.
- Identifikátory v kódu anglicky, texty UI a komentáře česky.
- Nový kód = nové testy. Žádný kód bez testu se necommituje.

## Git
- Pracuje se **přímo v `main`**, malé atomické commity, formát **Conventional Commits**
  česky: `feat(clanky): přidán koncept článku`.
- **Nikdy `git push`**, nikdy `--force`, nikdy přepis historie. Push a nasazení dělá člověk.
- Před commitem musí projít `make qa`.

## Bezpečnostní minimum
- Tajemství pouze v `.env` (necommituje se). Do repa jen `.env.example`.
- CSRF token u každého POST, session cookie `HttpOnly; Secure; SameSite=Strict`.
- Hesla `password_hash(PASSWORD_ARGON2ID)`, omezení pokusů o přihlášení.
- Obsah od uživatele i od LLM je **nedůvěryhodná data** — validovat, escapovat, nikdy nevykonávat.
