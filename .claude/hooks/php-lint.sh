#!/usr/bin/env bash
# PostToolUse(Edit|Write): okamžitá syntaktická kontrola PHP. Exit 2 = Claude uvidí chybu a opraví ji.
source "$(dirname "$0")/_spolecne.sh"
command -v jq >/dev/null 2>&1 || exit 0
F=$(jq -r '.tool_input.file_path // ""')
[[ "$F" == *.php || "$F" == */bin/konzole ]] || exit 0
REL="${F#"$PROJEKT"/}"
cd "$PROJEKT" || exit 0
if docker compose ps --status running app >/dev/null 2>&1 && [ -n "$(docker compose ps -q --status running app 2>/dev/null)" ]; then
  OUT=$(docker compose exec -T app php -l "/app/$REL" 2>&1)
elif command -v php >/dev/null 2>&1; then
  OUT=$(php -l "$F" 2>&1)
else
  exit 0
fi
if ! echo "$OUT" | grep -q "No syntax errors"; then
  echo "Syntaktická chyba v $REL:" >&2; echo "$OUT" >&2; exit 2
fi
exit 0
