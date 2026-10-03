#!/usr/bin/env bash
# PostToolUse(Edit|Write): okamžitá syntaktická kontrola PHP výhradně v kontejneru 'app'
# (pravidlo 0: na hostiteli PHP není, žádný fallback). Exit 2 = Claude uvidí chybu a opraví ji.
source "$(dirname "$0")/_spolecne.sh"
command -v jq >/dev/null 2>&1 || exit 0
F=$(jq -r '.tool_input.file_path // ""')
[[ "$F" == *.php || "$F" == */bin/konzole ]] || exit 0
[[ "$F" == "$PROJEKT"/* ]] || exit 0
REL="${F#"$PROJEKT"/}"
cd "$PROJEKT" || exit 0
# Repo před M1 (bez compose.yaml) nemá v čem lintovat.
[ -f compose.yaml ] || exit 0

if [ -z "$(docker compose ps -q --status running app 2>/dev/null)" ]; then
  jq -n --arg r "$REL" '{hookSpecificOutput:{hookEventName:"PostToolUse",additionalContext:("php -l pro " + $r + " neproběhl: kontejner app neběží. Spusť make up a kontrolu zopakuj (PHP na hostiteli není).")}}'
  exit 0
fi

OUT=$(docker compose exec -T app php -l "/app/$REL" 2>&1)
if ! echo "$OUT" | grep -q "No syntax errors"; then
  echo "Syntaktická chyba v $REL:" >&2; echo "$OUT" >&2; exit 2
fi
exit 0
