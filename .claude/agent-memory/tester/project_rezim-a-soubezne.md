---
name: rezim-a-soubezne
description: Režim A běží souběžně s implementátory – část testů může být hned zelená; jak psát testy odolné vůči neupřesněným signaturám
metadata:
  type: project
---

V M6 (plán 006, 2026-10-03) psal tester testy souběžně s `ai-inzenyr`/`databazista`; jejich kód vznikl dřív,
takže testy AC 1–19 a 29 byly při prvním běhu rovnou zelené a RED zůstal jen u HTTP části (T5).

**Why:** vedoucí spouští T1–T4 paralelně; „RED ze správného důvodu“ pak nejde doložit u všeho.
**How to apply:** před hlášením RED zjistit `git status src/` a v reportu rozlišit „RED (chybí X)“ vs. „zelené hned
(kód už existoval)“. Neupřesněné konstruktory (výjimky apod.) soustředit do jednoho pomocníka v `tests/Unit/Support`
(např. `AiFixtures`) a třídy skládat přes kontejner (autowiring), ne `new` s hádaným pořadím parametrů.
RED doložit i tak (M8, 2026-10-04): `git archive HEAD | tar -x -C <scratchpad>/head`, zkopírovat `vendor/` a nové
testy, pak `docker run --rm --network none -u 1000 -v <scratchpad>/head:/app -w /app t360-app vendor/bin/phpunit --testsuite Unit`
– pracovní strom programátora zůstane netknutý, sdílená DB se nepoužije.
Pozor: `TestCase::result()` i `run()` jsou v PHPUnit 13 `final` (nepojmenovávat tak pomocníky); XML komentář nesmí obsahovat `--`.
M7 (plán 008, 2026-10-04): T2+T3 hotové před T1 → AC 1–23, 32 zelené hned, RED jen HTTP (AC 24–31, chybí trasy → 404).
Když T4 chybí úplně, RED se dokládá přímo v pracovním stromu (není třeba kopie HEAD). Helpery HTTP testů M7 jsou
v `tests/Unit/Http/AdminAiM7TestCase.php`. Falešný proud 06: akce `zkrat` dá jen ~3 delty → měření bufferování (AC 34) přes `pokracuj`.
M7b (plán 009, 2026-10-08): T2–T5 (vč. kontroleru) byly hotové dřív než T1 → všech 160 nových unit testů zelených hned;
RED jen v kopii HEAD. V kopii HEAD (`docker run` bez env) padá i `KernelTest` (14×, „Chybí DB_HOST“) – artefakt prostředí,
ne RED. Integrační testy kolidují s jiným agentem, který zrovna pouští phpunit (`Table 'users' already exists`) → počkat,
až `ps aux | grep phpunit` nic neukáže, a pustit znovu. `StatementCounter::statements()` sčítá i `Com_*_multi`.
Viz [[hook-curl-zavinac]].
