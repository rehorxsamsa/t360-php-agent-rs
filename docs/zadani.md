# Zadání: Redakční systém (výuková aplikace stavěná agenty)

**Product owner:** člověk (zadává požadavky, schvaluje plány a výsledky, pushuje a nasazuje).
**Tým:** agenti Claude Code podle `CLAUDE.md`.
**Cíl:** jednoduchý, ale produkčně poctivý redakční systém v čistém PHP 8.4 OOP + MariaDB 11.8
v Dockeru, s 10 AI příklady a tutoriálem `docs/tutorial.html`.

## Role a oprávnění
| Role | Může |
|---|---|
| Návštěvník (nepřihlášený) | číst publikované články, procházet rubriky a štítky, hledat |
| `admin` | vše: CRUD článků, rubrik, štítků; AI nástroje; audit log; správa vlastního účtu |

Jediná role s právem zápisu je `admin`. Admin účet vzniká jen příkazem `bin/konzole admin:vytvor`
(žádná veřejná registrace).

## Uživatelské příběhy (akceptační kritéria rozpracuje architekt v plánech)
1. Jako návštěvník vidím na titulní stránce 10 nejnovějších publikovaných článků se stránkováním.
2. Jako návštěvník otevřu článek podle URL `/clanek/{slug}` (Markdown vykreslený bezpečně).
3. Jako návštěvník filtruji podle rubriky `/rubrika/{slug}` a štítku `/stitek/{slug}`, fulltext `/hledat?q=`.
4. Jako admin se přihlásím na `/admin/prihlaseni` (ochrana proti brute force) a odhlásím.
5. Jako admin vytvořím článek (titulek, slug – generuje se z titulku, perex, text v Markdownu,
   rubrika, štítky, stav koncept/publikováno/archiv, datum publikace).
6. Jako admin upravím článek; vidím, kdo a kdy ho naposledy měnil.
7. Jako admin smažu článek po potvrzení; akce se zapíše do audit logu.
8. Jako admin spravuji rubriky a štítky (CRUD; rubriku s články nelze smazat bez přesunu).
9. Jako admin vidím audit log (filtrování podle akce a data).
10. Jako admin používám AI nástroje (příklady 01–10, viz skill `ai-integrace`) a vidím jejich cenu.
11. Jako provozovatel ověřím zdraví aplikace na `/zdravi`.

## Nefunkční požadavky
- Bezpečnost dle skillu `bezpecnost-owasp` (CSP, CSRF, session, argon2id, audit, LLM rizika).
- Testy: PHPUnit (unit + integrační nad MariaDB), PHPStan level max, PHP-CS-Fixer, E2E scénáře.
- Vše běží bez API klíče (`AI_PROVIDER=falesny`); s klíčem volá Claude API.
- Přístupnost: sémantické HTML, ovladatelné klávesnicí, kontrast AA. Responzivní.
- Čeština v UI, `utf8mb4_czech_ci`, české řazení a formát data.
- Výkon: titulní stránka < 100 ms na lokálu, žádné N+1 dotazy.

## Milníky (každý = jedna nebo více `/feature`)
| M | Obsah | Hlavní agenti |
|---|---|---|
| M0 | Architektura, ADR, backlog | architekt |
| M1 | Docker, Makefile, composer nástroje, CI (`ci.yml`), kostra aplikace, `/zdravi` | devops, programátor |
| M2 | Router, DI, middleware, šablony, chybové stránky, migrátor, schéma | programátor, databazista |
| M3 | Přihlášení, role admin, CSRF, bezpečnostní hlavičky, audit log | programátor, security |
| M4 | Veřejná část: výpis, detail, rubriky, štítky, hledání | programátor, tester |
| M5 | Administrace: CRUD článků, rubrik, štítků | programátor, tester |
| M6 | AI jádro (`LlmKlient`, logování nákladů, limity) + příklady 01–05 | ai-inženýr |
| M7 | AI příklady 06–10 (streaming, tool use, RAG, agent, MCP server) | ai-inženýr, databazista |
| M8 | Audit (`/audit`), opravy, dokončení tutoriálu | všichni |
| M9 | `compose.prod.yaml`, `deploy.yml`, `scripts/vps/nasad.sh` podle kontraktu, `/retro` | devops |

## Mimo rozsah
Více rolí (redaktor, korektor), komentáře, nahrávání obrázků, vícejazyčný web (kromě AI překladu).
Jsou vhodné jako cvičení po dokončení.
