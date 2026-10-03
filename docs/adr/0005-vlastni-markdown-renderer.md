# ADR-0005: Vlastní minimální Markdown renderer místo knihovny CommonMark
- **Stav:** přijato (schváleno člověkem na bráně 1 plánu 004)
- **Datum:** 2026-10-03
- **Autor:** agent architekt
- **Souvisí:** [plán 004](../plan/004-verejna-cast.md), skill `bezpecnost-owasp` (oddíl Vstup a výstup)

## Kontext
Příběh 2 zadání chce text článku v Markdownu „vykreslený bezpečně“. Skill `bezpecnost-owasp`
předpokládá knihovnu CommonMark (`league/commonmark`) s `html_input: 'strip'`
a `allow_unsafe_links: false`. Projekt je ve výukovém režimu (STAV.md) a člověk pro M4 výslovně
zakázal novou composer závislost. Výstup rendereru se v šabloně vypisuje **bez** `e()` (jediná
výjimka v `.claude/rules/sablony.md`), takže renderer je bezpečnostní hranice proti XSS.
Obsah článků zatím píše jen seed (M4) a admin (M5); od M6 může text navrhovat i LLM — to je
nedůvěryhodný vstup.

## Rozhodnutí
**Píšeme vlastní třídu `App\Http\View\MarkdownRenderer` s pevně danou podmnožinou Markdownu
a principem „nejdřív escapovat, pak značkovat“.**
- Podporováno: odstavce, nadpisy `#`–`######` (mapované na `h2`–`h4`, `h1` patří titulku článku),
  odrážkové a číslované seznamy bez vnoření, citace `>`, blok kódu ```` ``` ````, vložený kód
  `` ` ``, `**tučně**`, `*kurzíva*`, odkazy `[text](url)`.
- Nepodporováno (zůstane čitelné jako text): syrové HTML (vždy escapované), obrázky, tabulky,
  vnořené seznamy, vodorovná čára, referenční odkazy, automatické odkazy, `_kurzíva_`.
- Každý text projde `e()` dřív, než se k němu přidá značka. Značky vznikají jen v kódu rendereru
  z allowlistu `p, h2, h3, h4, ul, ol, li, blockquote, pre, code, strong, em, a`; jediný atribut
  je `href` u `a`.
- Odkazy: URL se ověřuje **allowlistem** (`http://`, `https://`, `mailto:`, interní cesta `/…`
  bez `//`, kotva `#…`), nikdy denylistem. Neprošlá URL = jen text odkazu bez `<a>`.
- Bez regulárních výrazů s vnořenými kvantifikátory (ReDoS); neplatné UTF-8 se před zpracováním
  opraví (`mb_scrub`), selhání `preg_*` vrátí celý text escapovaný v jednom odstavci.

## Důsledky
+ Žádná nová závislost, kód je krátký a výukově čitelný (kapitola tutoriálu M4).
+ Bezpečnostní vlastnost je jednoduše testovatelná: výstup obsahuje jen značky z allowlistu.
− Podmnožina se liší od CommonMark (např. `_kurzíva_`, vnořené seznamy). Články psané pro
  CommonMark se vykreslí jednodušeji, ale nic se neztratí — text zůstane čitelný.
− Odchylka od skillu `bezpecnost-owasp`; skill se upraví jen se souhlasem člověka (změna `.claude/`).
− Pozdější přechod na `league/commonmark` je snadný (jedna třída, stejné rozhraní `toHtml()`),
  uložená data (Markdown) se nemění. Pak tento ADR nahradí nový.

## Zvažované alternativy
- **`league/commonmark` dle skillu** — úplný a prověřený parser, ale nová závislost (zakázáno pro M4)
  a pro výuku „černá skříňka“. Vhodný kandidát, pokud podmnožina přestane stačit.
- **Parsedown (jeden soubor zkopírovaný do repa)** — obchází zákaz závislostí jen formálně,
  bez aktualizací přes `composer audit`; v minulosti měl XSS chyby v safe mode. Odmítnuto.
- **Bez Markdownu, jen `nl2br(e($body))`** — nejbezpečnější, ale nesplní příběh 2 (Markdown). Odmítnuto.
- **Markdown → HTML a následná HTML sanitizace (DOMDocument + allowlist)** — dvě složité vrstvy
  místo jedné; parsování HTML přes DOM má vlastní úskalí (mutation XSS). Odmítnuto.
