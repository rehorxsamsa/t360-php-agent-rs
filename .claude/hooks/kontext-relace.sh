#!/usr/bin/env bash
# SessionStart: vloží do kontextu aktuální stav projektu (plain stdout = kontext pro Clauda).
source "$(dirname "$0")/_spolecne.sh"
cd "$PROJEKT" || exit 0
echo "## Stav projektu při startu relace"
echo "Větev: $(git branch --show-current 2>/dev/null || echo '?')"
echo "Poslední commity:"; git log --oneline -5 2>/dev/null | sed 's/^/  /'
ZMENY=$(git status --short 2>/dev/null | head -20)
[ -n "$ZMENY" ] && { echo "Necommitnuté změny:"; echo "$ZMENY" | sed 's/^/  /'; }
echo "Rozpracované plány:"
grep -L -i 'Stav: hotovo' docs/plan/[0-9]*.md 2>/dev/null | sed 's/^/  /' || echo "  žádné"
[ -f docs/plan/STAV.md ] && { echo "Poznámky ke stavu (docs/plan/STAV.md):"; head -20 docs/plan/STAV.md | sed 's/^/  /'; }
if command -v docker >/dev/null 2>&1 && [ -f compose.yaml ]; then
  echo "Kontejnery:"; docker compose ps --format '  {{.Service}}: {{.State}}' 2>/dev/null || echo "  neběží"
fi
echo "Připomínka: push a nasazení dělá člověk. Pracuj podle CLAUDE.md (brány 1 a 2)."
exit 0
