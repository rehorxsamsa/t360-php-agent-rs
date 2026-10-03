# ADR-0004: Tabulky a sloupce databáze anglicky
- **Stav:** navrženo (člověk dal předběžný souhlas 2026-10-03 v plánu 001, potvrdí se na bráně 1 plánu 002)
- **Datum:** 2026-10-03
- **Autor:** agent architekt

## Kontext
ADR-0003 rozhodl o anglických identifikátorech v PHP, ale databázi nechal otevřenou. Skill
`db-migrace` předepisuje české názvy bez diakritiky (`clanky`, `vytvoreno`, `publikovano_v`,
`migrace (nazev, spusteno_v)`). Workspace pravidlo („názvy ve zdrojovém kódu výhradně anglicky“)
pro SQL výjimku nezná. M2 vytváří první tabulky. Přejmenovat je později znamená novou migraci
pro každou tabulku a úpravu všech repozitářů, testů a dokumentace.

## Rozhodnutí
**Tabulky, sloupce, indexy, cizí klíče a hodnoty ENUM píšeme anglicky ve snake_case.**
- Tabulky v množném čísle: `users`, `categories`, `tags`, `articles`, `article_tags`
  (spojovací tabulka = `<jednotné>_<množné>`), výjimka `audit_log` (ustálený název).
- Časové sloupce: `created_at`, `updated_at`, `published_at` (`DATETIME(6)`).
- Cizí klíče: `<entita>_id` (`category_id`); autor změn `created_by`, `updated_by`.
- Hodnoty ENUM odpovídají `case` hodnotám PHP enumů: `draft`, `published`, `archived`, `admin`.
- Tabulka migrátoru: `migrations (name, executed_at)`.
- Názvy indexů a omezení: `uq_<tabulka>_<sloupce>`, `idx_<tabulka>_<sloupce>`, `fk_<tabulka>_<sloupec>`.
- Česky zůstává jen **obsah** (data), CLI příkazy `bin/konzole` (zamčený kontrakt dle ADR-0003),
  názvy databází a DB uživatelů z M1 (`redakce`, `redakce_test`, `redakce_app`, `redakce_migrace`,
  `redakce_cteni`) — ty jsou součástí nasazovacího kontraktu.

Mapování (závazné): `clanky → articles`, `rubriky → categories`, `stitky → tags`,
`clanky_stitky → article_tags`, `uzivatele → users`, `titulek → title`, `perex → excerpt`,
`text → body`, `stav → status`, `koncept/publikovano/archiv → draft/published/archived`,
`vytvoreno → created_at`, `upraveno → updated_at`, `publikovano_v → published_at`,
`clanky_vektory → article_embeddings` (M7).

## Důsledky
+ Jeden jazyk od SQL po PHP: `ArticleStatus::Published->value === 'published'` bez převodní tabulky.
+ Soulad s workspace pravidlem i ADR-0003.
− Skill `db-migrace` je nutné přepsat (změna `.claude/` = souhlas člověka). Do té doby má přednost
  tento ADR.
− Adminer a MCP `mariadb-cteni` ukazují anglické názvy; tutoriál je vysvětlí česky v textu.

## Zvažované alternativy
- **Česky podle skillu `db-migrace`** — porušuje workspace pravidlo; SQL v repozitářích by mísilo
  `SELECT titulek` s `Article::$title`. Odmítnuto.
- **Česky jen data/ENUM hodnoty (`'publikovano'`)** — vyžaduje mapování v každém repozitáři,
  zdroj chyb. Odmítnuto.
