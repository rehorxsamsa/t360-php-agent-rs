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
Pozor: `TestCase::result()` je v PHPUnit 13 `final` (nepojmenovávat tak pomocníky); XML komentář nesmí obsahovat `--`.
Viz [[hook-curl-zavinac]].
