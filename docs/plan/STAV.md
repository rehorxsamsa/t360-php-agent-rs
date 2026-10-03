# Stav projektu

## Známá rizika (přijatá po revizi kol 1–3, neopravují se)

Hooky v `.claude/hooks/` jsou pojistka proti omylům a běžným obejitím, **ne bezpečnostní hranice**.
Skutečnou hranicí je allowlist v `.claude/settings.json` (`permissions.allow`) – cokoli mimo něj se ptá člověka.

| ID | Riziko | Doporučení |
|---|---|---|
| S3 | `cat <<EOF … EOF \| bash` – tělo heredocu se před analýzou vyřezává jen u `git commit` a `cat <<`, ale `cat <<EOF \| bash` pak spustí libovolný kód. | Sandbox Claude Code (viz níže); rozšířit allowlist jen o přesné tvary. |
| S4 | Zápis souborů přes interpretery (`python -c`, `perl -e` …) obejde kontrolu chráněných souborů. | Interpretery nejsou v allowlistu (ptají se); nepovolovat je. |
| N4, N5 | Zbylé nálezy kola 3 (okrajové obejití pravidel podle kontextu). | Opravit, až bude hranicí sandbox. |
| – | Interpretery obecně (`python`, `perl`, `ruby`, `awk`, `sh`) lze použít k čemukoli. | Viz sandbox. |
| – | Hooky selhávají otevřeně (chyba/timeout hooku = příkaz projde na základě permissions). `vyzaduj_jq` selhává zavřeně, ostatní chyby ne. | Hranice musí být allowlist, ne hook. |
| – | `bash -c '…'` / `eval` mimo `docker compose exec` se neanalyzuje (obsah uvozovek se maskuje). | Nepovolovat `Bash(bash *)`, `Bash(eval *)`. |
| – | Přímé `docker compose -f jiny.yaml …` obejde `-f compose.yaml` z Makefile. | `-f` s cizím souborem patří do dotazu; revize při změně. |
| – | `COMPOSE_FILE` v `.env` (načítá ho compose automaticky) lze změnit zápisem do `.env`, který hook chrání jen částečně. | `make up` doplní `COMPOSE_FILE=compose.yaml`; `.env` je chráněn (`Edit(./.env)` v deny). |
| N2/N5 (kolo 1) | Obrazy v Dockerfile/compose bez digestů (`@sha256:…`), `npm` lockfile (MCP obraz), prod obraz běží jako `root:root` pro soubory. | Připíchnout digesty před prvním nasazením; ověřit vlastnictví souborů v prod obrazu. |
| I1–I5 | Informační nálezy revize (viz `.claude/agent-memory/security-reviewer/`). | Bez akce. |

### Doporučený samostatný ADR: sandbox Claude Code
Navrhnout ADR „Sandbox pro Bash nástroj Claude Code“ (omezení zápisu na adresář projektu, síťová allowlist,
zákaz čtení `.env`/`~/.ssh` na úrovni OS). Sandbox řeší S3, S4, interpretery i `bash -c` u kořene – hooky by pak
zůstaly jen pohodlnou první linií. Schvaluje člověk (mění architekturu pracovního prostředí).

## Kolo 4 (hotovo)
- Zúžený `allow` pro git, curl allowlist, jq/`git commit -F` kontroly, `.git*` chráněno, normalizace názvu
  příkazu, zákaz `COMPOSE_*`/`DOCKER_HOST` a zmínek MAKEFLAGS-like proměnných.
- Makefile: S5 (`override CLI_VARS`, z příkazové řádky jen `ARGS`) a N3 (`COMPOSE_FILE` doplněn v `make up`).
- Regresní scénáře v `tests/Hooks/scenare.sh` (188 ok).
- Po revizi kola 4 opraveno: R4-1 (`git restore --staged -- *`, deny `--wor*`/`--so*`), R4-2 (deny `git * --pathspec*`),
  R4-3 (`override CLI_VARS`).

## Zbývá po revizi kola 4 (přijatá rizika / úkoly)
- **MCP jako výstupní kanál bez dotazu:** `context7` (vzdálené HTTP) a `playwright` jsou povolené bez dotazu, takže
  při prompt injection mohou posloužit k odeslání dat ven. Řeší až sandbox / síťová politika (samostatné ADR).
- **Přesměrování mimo projekt (R4-4):** hook nekontroluje cíle `>`/`>>` mimo repo (např. `~/.bashrc`). Člověk má
  jednorázově ověřit v hlavní relaci: `git log --oneline -1 > /tmp/t360-probe.txt` a sledovat, zda přijde dotaz.
  Pojistka do `check_target` je navržena v reportu revize.
- **Nízké (R4-5, R4-6):** falešný poplach hooku u čtecího `git config --get` na klíč pro cestu k hookům (a u textů,
  které ten klíč jen zmiňují); `curl -w` s `%output{…}` je nebezpečné až od curl 8.3 (na hostiteli je 7.81).
- **Pohodlí:** holé `git diff`, `git diff --staged`, `git log`, `git show <commit>` jdou přes dotaz; případně přidat
  přesné tvary do `allow`.

## Rozhodnutí: výukový režim (2026-10-03)
Aplikace je jen lokální výuková (Docker na localhostu), nikdy na produkci. Bezpečnostní omezení a doporučení
se zmírňují ve prospěch rychlého spuštění a ukázky práce agentů; nemusí být na 100 %.
- T5: AC 6 opraveno (`ALTER USER redakce_cteni`, heslo sjednoceno s `.env`). AC 7, 33, 1 přijaty jako neověřené.
- T6 (security review) se pro výukové účely přeskakuje; rizika výše jsou přijatá.

## Otevřené úkoly pro M9 (z plánu 002)
- Oddělit migrační heslo (`DB_MIGRACE_*`) od služby `app`, např. jednorázovým kontejnerem; v M2 je v `app` kvůli `bin/konzole` a integračním testům.
- Produkční `prod` stage musí kopírovat i `config/`, `templates/`, `database/` a `bin/`.
- Dvě sady testů nesmí běžet paralelně nad `redakce_test`.
