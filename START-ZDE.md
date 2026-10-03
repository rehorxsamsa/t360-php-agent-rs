# START ZDE — Redakční systém stavěný týmem agentů

Tahle sada **neobsahuje ani řádek aplikačního kódu**. Obsahuje „firmu“: tým agentů, jejich
pravidla, nástroje, pojistky a zadání. Kód napíšou agenti. Ty budeš product owner.

```
redakcni-system/
├── START-ZDE.md            ← čteš (příprava prostředí)
├── PROMPTY.md              ← všechny prompty, fáze po fázi (tvůj „scénář“)
├── AGENTS.md               ← pravidla pro jakéhokoli AI agenta (otevřený standard)
├── CLAUDE.md               ← ústava týmu: orchestrace, brány, struktura (importuje AGENTS.md)
├── .mcp.json               ← sdílené MCP servery: context7 (vzdálený, aktuální dokumentace), github (jen čtení)
├── .claude/
│   ├── settings.json       ← oprávnění (allow/ask/deny) + hooky + env
│   ├── settings.local.json.example ← tvůj lokální GitHub token (zkopíruj, necommituje se)
│   ├── agents/             ← 8 subagentů (role, modely, nástroje, paměť, vlastní hooky a MCP)
│   ├── skills/             ← 10 skills: /feature /commit /audit /retro + znalostní příručky
│   ├── rules/              ← pravidla, která se načtou jen u odpovídajících souborů
│   ├── hooks/              ← deterministické pojistky (bash + jq)
│   └── agent-memory/       ← trvalá paměť agentů (plní se sama, commituje se)
├── .githooks/              ← klasické git hooky (formát commitu) – platí i pro člověka
└── docs/
    ├── zadani.md           ← co stavíme (PRD + milníky M0–M9)
    ├── adr/                ← rozhodnutí (ADR-0001 už je přijato)
    ├── plan/ ai-priklady/  ← plní agenti
    └── tutorial.html       ← kostra tutoriálu + HOTOVÁ kapitola Nasazení na VPS
```

## 1. Prostředí (Ubuntu / WSL2)
```bash
sudo apt update && sudo apt install -y git jq make curl unzip
# Docker: buď Docker Desktop s WSL integrací, nebo Docker Engine v Ubuntu
docker version && docker compose version
# GitHub CLI (secrets, ověření CI)
sudo apt install -y gh && gh auth login
```

## 2. Claude Code
```bash
curl -fsSL https://claude.ai/install.sh | bash   # nativní instalátor
claude --version
# v běžící relaci pak /doctor zkontroluje instalaci i konfiguraci projektu
```

## 3. Projekt
```bash
mkdir -p ~/projekty && cd ~/projekty
unzip ~/Downloads/redakcni-system.zip && cd redakcni-system
git init -b main
git config core.hooksPath .githooks
chmod +x .githooks/* .claude/hooks/*.sh
cp .claude/settings.local.json.example .claude/settings.local.json   # doplň hodnoty
git add -A && git commit -m "chore: startovní sada agentního týmu"
```
`GITHUB_PAT` = fine-grained token jen pro tvůj repozitář s právy **Read** (Contents, Actions,
Issues). Bez něj github MCP nepoběží — nevadí, je volitelný.
Hesla k databázi tu nezadáváš: `make up` vytvoří `.env` z `.env.example` a MCP server pro čtení
databáze běží v Dockeru a heslo bere odtud (agenti `.env` nevidí — čtení je zakázané hookem
i permissions).

Na hostiteli nepotřebuješ PHP, Composer ani Node.js. Všechno běží v Dockeru. Prostředí
spustíš `make up`, postup je v [`README.md`](README.md).

## 4. První spuštění
**Spouštěj `claude` z kořene repa.** Jen tam se načtou `.claude/settings.json` (oprávnění a
hooky), `.mcp.json` i definice agentů. Z podadresáře (např. `.claude/`) pojistky neplatí.
```bash
make up && make mcp     # prostředí a obrazy MCP serverů (jednou)
claude
```
1. Potvrď **workspace trust** — bez něj se nespustí hooky z frontmatteru agentů ani jejich MCP.
2. Zkontroluj: `/hooks` (seznam hooků), `/mcp` (context7, github), `/context` (kolik kontextu
   co stojí), napiš `@` a uvidíš agenty, `/` a uvidíš skills (`/feature`, `/commit`, `/audit`, `/retro`).
3. Režim oprávnění přepínáš **Shift+Tab**. Výchozí je `acceptEdits` (editace bez ptaní,
   příkazy dle allowlistu). Pro plánování přepni na **plan mode**.

## 5. Proč to je takhle (co se tu učíš)
| Mechanismus | Co řeší | Kde to uvidíš |
|---|---|---|
| **CLAUDE.md / AGENTS.md** | trvalý kontext, „ústava“ týmu | každá relace |
| **Subagenti** | izolovaný kontext, specializace, nejmenší potřebná práva, model podle role | `.claude/agents/*` |
| **Skills** | opakovatelné postupy (`/feature`) a znalosti načítané na vyžádání | `.claude/skills/*` |
| **Rules s `paths`** | pravidla jen pro určitý typ souboru = úspora kontextu | `.claude/rules/*` |
| **Hooks** | co **musí** platit vždy (prompt je prosba, hook je zákon) | zákaz push, ochrana `.env`, lint, kontrola tajemství, „nesmíš skončit s červenými testy“ |
| **Permissions** | první linie: allow / ask / deny | `settings.json` |
| **MCP** | nástroje a data zvenku: dokumentace, prohlížeč, DB, GitHub | `.mcp.json` + MCP jen pro konkrétního agenta |
| **Paměť agentů** | agent se učí napříč relacemi | `memory: project` |
| **Git + git hooky** | auditovatelná historie, poslední pojistka i pro člověka | `.githooks/` |
| **CI/CD** | nezávislé ověření mimo agenta + nasazení se schválením | GitHub Actions + environment `production` |

Pokračuj do **PROMPTY.md**.
