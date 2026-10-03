#!/usr/bin/env bash
# PreToolUse(Edit|Write): ochrana citlivých a "zamčených" souborů.
source "$(dirname "$0")/_spolecne.sh"
vyzaduj_jq
F=$(jq -r '.tool_input.file_path // ""')
# Normalizace cesty ("/./", "//", ".." ani symlink neobejde vzory níže).
REL=$(normalizuj_rel "$F")

case "$REL" in
  .env.example) exit 0 ;;
  .env|.env.*|*/.env|*.pem|*.key|*id_ed25519*|*id_rsa*) zamitni "Soubory s tajemstvím agenti needitují ($REL)." ;;
  .git/*|vendor/*|node_modules/*) zamitni "$REL se needituje ručně (spravuje git/composer)." ;;
  composer.lock) zamitni "composer.lock mění jen 'composer require/update' v kontejneru." ;;
  .claude/settings.json|.claude/hooks/*) zeptej_se "Změna bezpečnostní konfigurace agentů ($REL) – vyžaduje souhlas člověka." ;;
  .github/workflows/*) zeptej_se "Změna CI/CD workflow ($REL) – vyžaduje souhlas člověka." ;;
esac
# compose*/override, Makefile/GNUmakefile/*.mk, docker/, hooky, tests/Hooks, .mcp.json, .dockerignore, .envrc,
# .claude/* (kromě agent-memory), CLAUDE.md, AGENTS.md: soubory, které nástroj načte/spustí na hostiteli.
if chraneny_soubor "$REL"; then
  zeptej_se "Soubor ovlivňuje spouštění na hostiteli/Docker nebo oprávnění agentů ($REL) – souhlas člověka."
fi

# Commitnutá migrace je neměnná.
if [[ "$REL" == database/migrations/* ]] && git -C "$PROJEKT" ls-files --error-unmatch "$REL" >/dev/null 2>&1; then
  zamitni "Migrace $REL už je v gitu. Neměň ji – vytvoř novou migraci."
fi
exit 0
