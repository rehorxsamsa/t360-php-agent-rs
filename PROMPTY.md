# PROMPTY — scénář pro product ownera

Prompty kopíruj do Claude Code tak, jak jsou. Po každé fázi si přečti, **co sledovat** —
tam je to hlavní učení. Odpovědi na brány piš krátce: `schvaluji`, nebo konkrétní připomínky.

---

## F0 — Orientace (plan mode, Shift+Tab)
```
Jsi vedoucí týmu podle CLAUDE.md. Přečti AGENTS.md, CLAUDE.md, docs/zadani.md, docs/adr/
a projdi .claude/ (agenti, skills, rules, hooks, settings). Nic neměň.
Vysvětli mi jako učitel:
1) jak bude tým spolupracovat na jedné funkci (krok za krokem, kdo, s jakým modelem a nástroji),
2) které pojistky jsou vynucené hooky/permissions a které jen prompty,
3) co bys v konfiguraci týmu zlepšil, než začneme (max 5 bodů, s odůvodněním).
```
**Sleduj:** jestli rozumí rozdílu „prompt = prosba, hook = zákon“. Návrhy zlepšení posuď —
dobré nech zapracovat (je to první ukázka, že agenti mohou ladit i svůj vlastní proces).

## F1 — M0 Architektura
```
/feature M0 z docs/zadani.md: architektura celé aplikace. Architekt vytvoří
docs/architektura.md (vrstvy, tok požadavku, diagramy Mermaid), ADR pro klíčová rozhodnutí
(router a DI bez frameworku, migrátor, šablony, LlmKlient, nasazovací kontrakt) a backlog
docs/plan/000-backlog.md rozpadnutý na /feature úkoly pro M1–M9. Kód se nepíše.
```
**Brána 1:** čti ADR. Ptej se „proč ne X?“. Agent má umět obhájit volby.

## F2 — M1 Infrastruktura a kostra
```
/feature M1: Docker prostředí podle skillu devops-kontrakt (compose.yaml, Dockerfile s targety
dev/prod, nginx, MariaDB 11.8 s init skriptem pro uživatele redakce_app/redakce_migrace/
redakce_cteni), Makefile, composer.json s PHPUnit, PHPStan (level max), PHP-CS-Fixer
a skripty check/test/qa, .env.example, kostra public/index.php s endpointem /zdravi,
a .github/workflows/ci.yml přesně dle kontraktu. Ověř `make up`, `make qa`, `curl /zdravi`.
```
**Sleduj:** hook `chran-soubory` se zeptá na `.github/workflows/*` — to je tvoje schválení.
Po commitu si `.env` vytvoř sám: `cp .env.example .env` a doplň hesla.

## F3 — M2 Jádro
```
/feature M2: jádro aplikace — Kernel, router, DI kontejner, middleware pipeline, Pozadavek/
Odpoved, šablonovací vrstva s e()/e_attr()/csrf_pole(), chybové stránky 404/403/500,
migrátor (bin/konzole migrace:*) a první migrace se schématem z plánu. Testy napřed.
```
**Sleduj:** `tester` píše testy dřív než `programator` kód. Hook `rychla-kontrola` nepustí
programátora pryč s červenými testy (uvidíš ho pokračovat sám).

## F4 — M3 Přihlášení a role admin
```
/feature M3: přihlášení admina, role admin (enum Role), middleware VyzadujeRoli, CSRF,
bezpečnostní hlavičky s CSP nonce, ochrana proti brute force, session hardening, audit log,
příkaz bin/konzole admin:vytvor (heslo interaktivně, ne parametrem). Security reviewer
musí dát SCHVÁLENO.
```
**Sleduj:** report security revize. Zkus si sám: `curl -X POST` na admin URL bez tokenu.

## F5 — M4 + M5 CRUD
```
/feature M4: veřejná část — titulní stránka se stránkováním, detail /clanek/{slug},
rubriky, štítky, fulltextové hledání. Markdown přes CommonMark s bezpečným nastavením.
Seed s 12 realistickými českými články ve 3 rubrikách.
```
```
/feature M5: administrace — CRUD článků (koncept/publikováno/archiv), rubrik a štítků,
mazání s potvrzením přes POST, flash zprávy, PRG, audit všech změn. Tester ověří E2E
v prohlížeči (Playwright MCP) i negativní scénáře (nepřihlášený, bez CSRF).
```
**Sleduj:** `tester` spouští prohlížeč přes MCP, který má **jen on** (MCP ve frontmatteru) —
hlavní kontext tím nezaplácáš.

## F6 — M6 AI jádro a příklady 01–05
```
/feature M6a: AI jádro podle skillu ai-integrace — LlmKlient, AnthropicKlient (cURL, retry,
timeout, prompt caching), FalesnyKlient s fixtures, logování do ai_volani s cenou,
denní limit tokenů, rate limit AI endpointů. Ověř aktuální API přes context7.
```
Pak pro každý příklad (můžeš 2–3 najednou v paralelních relacích, viz „Pokročilé“):
```
/feature AI příklad 01 (Perex na jedno kliknutí) podle skillu ai-integrace. Balíček:
třída, prompt v src/Ai/Prompty, CLI, stránka v administraci, test s FalesnyKlient,
podklady docs/ai-priklady/01.md a kapitola v tutoriálu.
```
(02 SEO structured output · 03 štítky a rubrika · 04 kontrola před publikací + demo prompt
injection · 05 překlad)

**Sleduj u 04:** ať ti agent ukáže útok (článek s „Ignoruj předchozí pokyny…“) a obranu.

## F7 — M7 AI příklady 06–10
```
/feature AI příklad 06: asistent psaní se streamingem (SSE v PHP i v prohlížeči, CSP nonce).
/feature AI příklad 07: „Zeptej se redakce“ — tool use se čtecími nástroji, limit 5 kroků.
/feature AI příklad 08: RAG — embeddingy (Ollama profil ai-local, fallback falesny),
         MariaDB VECTOR + VECTOR INDEX, odpověď s citacemi článků.
/feature AI příklad 09: AI redaktor — osnova → koncept → sebekontrola → uložit jako koncept;
         publikovat smí jen admin ručně.
/feature AI příklad 10: MCP server redakce (oficiální PHP SDK, STDIO), čtecí nástroje
         + prompt; návod `claude mcp add` v tutoriálu.
```
**Sleduj u 10:** aplikace, kterou agenti postavili, se stane **nástrojem pro další agenty**.
Po dokončení si ji připoj: `claude mcp add redakce -- docker compose exec -T app php bin/mcp-server`
a zeptej se: „Kolik článků je v rubrice Technologie?“

## F8 — M8 Audit
```
/audit
```
**Sleduj:** paralelní subagenti, u velkého rozsahu dynamic workflow s nezávislým ověřením
nálezů. Pak: `/feature Opravy z auditu YYYY-MM-DD — jen Kritické a Vysoké`.

## F9 — M9 Produkce
```
/feature M9: produkce podle skillu devops-kontrakt — compose.prod.yaml (vč. profilu caddy),
docker/nginx/Dockerfile, .github/workflows/deploy.yml, scripts/vps/nasad.sh s rollbackem.
Validuj actionlintem a `docker compose -f compose.prod.yaml config`. Na VPS se nepřipojuj.
Technický spisovatel jen zkontroluje, že kapitola Nasazení odpovídá skutečným souborům.
```
Pak sám: `git push`, nastav GitHub podle kapitoly **Nasazení na VPS Debian** v
`docs/tutorial.html`, schval deploy v GitHubu.

## F10 — Retrospektiva (po každém milníku, nejméně po M3, M5, M7, M9)
```
/retro
```

---

## Prompty pro běžné situace
| Situace | Prompt |
|---|---|
| Agent se točí v kruhu | `Zastav. Shrň, co zkoušíš, proč to selhává a 2 alternativy. Nic neměň.` |
| Chci druhý názor | `Ať security-reviewer a architekt nezávisle posoudí tento diff. Porovnej závěry.` |
| Kontext je plný | `Zapiš stav do docs/plan/STAV.md (hotovo/rozpracováno/další krok).` → pak `/compact` |
| Vedlejší úkol bez zaplácání | `/subtask napiš E2E scénáře pro mazání rubrik` (fork na pozadí) |
| Rychlá otázka bokem | `/btw jaký je rozdíl mezi readonly class a readonly property?` |
| Vrátit poslední commit | `Vrať poslední commit pomocí git revert a vysvětli dopad.` |
| Agent porušil pravidlo | `Zapiš do CLAUDE.md → Lekce pravidlo, které tomu příště zabrání. Je potřeba i hook?` |
| Proč hook blokoval | `Ukaž posledních 20 záznamů z .claude/logs/audit.jsonl a vysvětli blokace.` |
| Nová závislost | `Navrhni balíček pro X: 2 kandidáti, údržba, licence, bezpečnost. Nic neinstaluj.` |

---

## Pokročilé a „budoucnost“ (vyzkoušej po M5)
1. **Paralelní relace ve worktree** — AI příklady 02 a 03 současně:
   `claude --worktree ai-02` a v druhém terminálu `claude --worktree ai-03`, každá spustí svůj
   `/feature`. Na konci: `Sluč větev worktree do main fast-forwardem a ověř make qa.`
   (alternativa: subagent s `isolation: worktree`).
2. **Agent teams** (experimentální) — vedoucí spustí tým „spoluhráčů“ ve vlastních relacích se
   sdíleným seznamem úkolů a zprávami mezi sebou. Zapni podle docs (Agent teams) a zkus:
   `Vytvoř tým: tester, programator a security-reviewer na M5. Ať si úkoly rozdělí sami.`
3. **Dynamic workflows** — `Napiš workflow, které projde všechny kontrolery, najde chybějící
   autorizační kontroly a druhou sadou agentů každý nález ověří.`
4. **Headless / CI** — Claude v pipeline bez terminálu:
   `claude -p "Zkontroluj docs/tutorial.html: najdi příkazy, které neodpovídají Makefile" --output-format json`
   Totéž v GitHub Actions přes `anthropics/claude-code-action` (review každého pushe).
5. **Pozadí a více relací** — relace na pozadí (agent view / background agents v docs) a
   cross-session messaging: jedna relace dělá RAG, druhá jí pošle zprávu o změně schématu.
6. **Plugin** — až bude tým vyladěný: `Zabal .claude/agents, skills, hooks a rules jako plugin
   „agentni-tym-php“, ať ho můžu použít v dalším projektu.`

> Tip učitele: po každé fázi si odpověz na 3 otázky — *Co agent udělal dobře bez mé pomoci?
> Kde ho zachránila pojistka (hook/test/revize)? Co bych příště zadal jinak?* Odpovědi jsou
> přesně to, co se v roce 2026+ od vývojáře s agenty čeká.
