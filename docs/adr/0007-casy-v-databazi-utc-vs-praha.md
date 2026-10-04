# ADR-0007: Časy v databázi – co plní databáze, je v UTC; co plní aplikace, je v Europe/Prague
- **Stav:** navrženo (schvaluje člověk na bráně 1 plánu 007)
- **Datum:** 2026-10-04
- **Autor:** agent architekt
- **Souvisí:** [plán 007](../plan/007-audit-a-dokonceni.md), [plán 005](../plan/005-sprava-clanku.md) (čas z `Clock`),
  [plán 006](../plan/006-ai-jadro.md) (`ai_calls.created_at`), [ADR-0004](0004-anglicke-nazvy-v-databazi.md)

## Kontext
- PHP běží s `date.timezone = Europe/Prague` (`docker/php/conf.d/app.ini`), čas aplikace dodává rozhraní
  `Domain\Time\Clock`. MariaDB v kontejneru běží v UTC (systémová zóna obrazu), takže `CURRENT_TIMESTAMP(6)`
  a `NOW()` vracejí UTC. Sloupce jsou `DATETIME(6)`, které zónu neukládají.
- Od M4/M5 platí, že časy, se kterými aplikace počítá (publikace, úpravy článků, volání AI), zapisuje
  repozitář explicitně z `Clock` (pražský čas): `articles.published_at`, `articles.created_at`, `articles.updated_at`
  (plán 005), `ai_calls.created_at` (plán 006).
- Databázovou výchozí hodnotou (UTC) se dnes plní: `audit_log.created_at`, `users.created_at`, `users.last_login_at`
  (`CURRENT_TIMESTAMP(6)` v `PdoUserRepository::touchLastLogin`), `migrations.executed_at`. A omylem i
  `articles.created_at`/`updated_at` u **seedovaných** článků (seed sloupce nevyplňuje) – v administraci se proto
  u ukázkových článků zobrazuje „Naposledy upraveno“ posunuté o 1–2 hodiny.
- M8 přidává stránku audit logu, která musí čas zobrazit v pražském čase a filtrovat podle kalendářního dne.
  Přijaté riziko z M5 („audit_log a seed jsou v UTC, řešit v M8“) je potřeba uzavřít s co nejmenší změnou.
- Na serveru (M9) poběží stejný obraz MariaDB; kdyby ale někdo nastavil zónu serveru jinak, výchozí hodnoty by
  tiše změnily význam.

## Rozhodnutí
**Pravidlo jednoho řádku: sloupec, který plní databáze (`DEFAULT CURRENT_TIMESTAMP`, `CURRENT_TIMESTAMP()`
v SQL), je v UTC; sloupec, který plní aplikace z `Clock`, je v Europe/Prague. Tabulka nesmí míchat oba
způsoby v jednom sloupci.**

1. `ConnectionFactory::create()` po připojení nastaví zónu spojení na UTC (`SET time_zone = '+00:00'`).
   Výchozí hodnoty databáze jsou pak UTC nezávisle na nastavení serveru. Dnes se tím chování nemění
   (server už v UTC běží), jen se z náhody stává záruka.
2. `audit_log.created_at` zůstává v UTC (plní ho databáze, zápis se nemění). Převod dělá **jen**
   `PdoAuditLogRepository` při čtení: hranice filtru převede z pražského času do UTC, načtené časy vrátí jako
   `DateTimeImmutable` v zóně `date_default_timezone_get()` (Europe/Prague). Doména, šablony i testy přes Kernel
   pracují jen s pražským časem.
3. Seed vyplní `articles.created_at` a `updated_at` explicitně (pražský čas), protože tabulka `articles` patří
   do skupiny „plní aplikace“. Seedované řádky, které nikdo v administraci neupravil, seed dorovná (plán 007, otázka 3).
4. `users.created_at`, `users.last_login_at`, `migrations.executed_at` zůstávají v UTC; nikde se nezobrazují.
   Až je bude něco zobrazovat, převede je jeho repozitář stejně jako bod 2.
5. V SQL repozitářů se dál nepoužívá `NOW()` pro porovnání s pražskými sloupci (čas jde z `Clock`).

## Důsledky
+ Žádná migrace schématu ani dat audit logu, žádná změna zápisu auditu ani volajících `AuditEntry`.
+ Audit log v UTC je jednoznačný i při přechodu ze letního času na zimní (hodina 02:00–03:00 se v pražském
  čase opakuje, v UTC ne) – pro záznam „kdo a kdy“ je to správná volba.
+ Pravidlo jde ověřit pohledem do kódu: kdo zapisuje sloupec, ten určuje zónu.
− V databázi platí dvě konvence. Kdo se dívá přímo do DB (Adminer, MCP `redakce_cteni`), vidí `audit_log` o 1–2 h
  „pozadu“. Proto je pravidlo v `docs/architektura.md`, v docbloku `PdoAuditLogRepository` a v kapitole M8 tutoriálu.
− Pražské sloupce (`articles`, `ai_calls`) mají při podzimním přechodu času nejednoznačnou hodinu. Přijato
  (výuková aplikace, dopad jen na zobrazení a řazení v jedné hodině ročně).
− Filtr podle data musí převádět hranice dne; převod je na jednom místě (repozitář) a pokrývá ho integrační test
  se záznamem těsně kolem půlnoci a se zimním časem.

## Zvažované alternativy
- **Sjednotit vše na Europe/Prague:** `PdoAuditLogRepository` by zapisoval `created_at` z `Clock` a jednorázová
  datová migrace by převedla stávající řádky z UTC. Zamítnuto: přepis dat v `main` (migrace by musela běžet přesně
  mezi nasazením kódu a prvním zápisem), nejednoznačná hodina v auditu, víc změn (kontejner, testy zápisu).
- **Sjednotit vše na UTC** (i `articles`, `ai_calls`, převod při zobrazení všude): čistší, ale přepis M4–M6,
  datová migrace a riziko regresí; mimo rozsah výukového MVP.
- **Zóna spojení `Europe/Prague`** (`SET time_zone = 'Europe/Prague'`): nové výchozí hodnoty by byly pražské,
  ale staré řádky zůstanou v UTC (smíšený sloupec) a potřebují se načtené časové tabulky MariaDB. Zamítnuto.
- **Sloupce `TIMESTAMP`** (MariaDB převádí podle zóny spojení): změna schématu všech časových sloupců
  a rozsah jen do roku 2038 (`planovany-clanek` má 2099). Zamítnuto.
