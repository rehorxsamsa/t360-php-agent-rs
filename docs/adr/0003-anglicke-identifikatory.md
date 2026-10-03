# ADR-0003: Identifikátory v kódu anglicky, česky jen to, co vidí uživatel
- **Stav:** navrženo
- **Datum:** 2026-10-03
- **Autor:** agent architekt

## Kontext
Pravidla si odporují a M1 je první milník, kde vzniká kód — později se to špatně vrací
(přejmenování tříd, rozhraní, testů i dokumentace napříč vrstvami).

| Zdroj | Co říká |
|---|---|
| `/home/q/projects/CLAUDE.md` (workspace) | názvy tříd, metod, proměnných… **výhradně anglicky** |
| `AGENTS.md` | „Identifikátory v kódu anglicky, texty UI a komentáře česky.“ |
| skill `php-oop-standardy` | příklady česky: `Clanek`, `ClanekRepository::najdiPodleSlugu`, `Pozadavek`, `Odpoved`, `Middleware::zpracuj`, `Hodiny`, `GeneratorTokenu`, `csrf_pole()`, `DomenovaVyjimka`, `Kontroler` |
| `.claude/rules/php.md` | `App\Http\Pozadavek`, `App\Infrastructure\Session` |
| definice agenta `architekt` | `LlmKlient`, `AnthropicKlient`, `OllamaKlient`, `FalesnyKlient` |
| skill `db-migrace` | tabulky a sloupce česky (`clanky`, `vytvoreno`) |
| `devops-kontrakt` | `bin/konzole migrace:spust`, `/zdravi`, JSON `{"stav":"ok","db":"ok"}`, proměnné `DB_MIGRACE_PASSWORD`… |

## Rozhodnutí
Platí workspace pravidlo a AGENTS.md: **všechny identifikátory PHP kódu jsou anglicky.**
Česky zůstává jen to, co je uživatelské rozhraní nebo zamčený vnější kontrakt:

- **Anglicky:** jmenné prostory, třídy, rozhraní, enumy a jejich case, metody, vlastnosti,
  proměnné, konstanty, helpery (`e()`, `e_attr()`, `url()`, `csrf_field()`), názvy testovacích
  metod (`test_returns_503_when_database_is_down`), proměnné v shell skriptech.
- **Česky (výjimky):** URL cesty (`/zdravi`, `/clanek/{slug}`, `/admin/prihlaseni`), texty UI,
  chybové hlášky pro uživatele, komentáře a PHPDoc popisy, Markdown prompty, testovací data,
  a vše zamčené kontraktem: JSON `/zdravi` (`stav`, `db`), název CLI `bin/konzole` a jeho
  příkazy (`migrace:spust`, `admin:vytvor`), názvy proměnných prostředí z kontraktu.
- **Databáze:** rozhodne člověk v M2 (otázka v plánu 001). Doporučení: anglicky
  (`articles`, `created_at`) — SQL je zdrojový kód a workspace pravidlo výjimku pro DB nezná.

Mapování příkladů ze skillů (závazné pro další plány): `Clanek → Article`, `Rubrika → Category`,
`Stitek → Tag`, `Pozadavek → Request`, `Odpoved → Response`, `Kontroler → Controller`,
`Middleware::zpracuj → Middleware::process`, `Hodiny → Clock`, `GeneratorTokenu → TokenGenerator`,
`DomenovaVyjimka → DomainException` (v `App\Domain`), `NeplatnyVstup → InvalidInput`,
`LlmKlient → LlmClient`, `AnthropicKlient → AnthropicClient`, `OllamaKlient → OllamaClient`,
`FalesnyKlient → FakeLlmClient`.

## Důsledky
+ Soulad s workspace pravidlem a AGENTS.md; kód je čitelný pro nástroje i cizí vývojáře.
+ Jasná hranice: co vidí uživatel/provoz = česky, co čte jen programátor = anglicky.
− Je nutné upravit skill `php-oop-standardy`, `.claude/rules/php.md` a text agenta `architekt`
  (změna `.claude/` → souhlas člověka). Do té doby mají přednost tento ADR a AGENTS.md.
− Výuková čeština v kódu tutoriálu ubude; tutoriál vysvětluje pojmy česky v textu.

## Zvažované alternativy
- **Česky podle skillů** — porušuje workspace pravidlo i AGENTS.md; odmítnuto.
- **Míchat (doména česky, technika anglicky)** — nejhorší čitelnost, nekonzistentní názvy
  (`ArticleRepository::najdiPodleSlugu`); odmítnuto.
