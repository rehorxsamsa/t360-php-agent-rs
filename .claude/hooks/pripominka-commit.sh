#!/usr/bin/env bash
# Stop: nevynucuje nic, jen upozorní člověka na necommitnutou práci.
source "$(dirname "$0")/_spolecne.sh"
command -v jq >/dev/null 2>&1 || exit 0
INPUT=$(cat)
[ "$(echo "$INPUT" | jq -r '.stop_hook_active // false')" = "true" ] && exit 0
cd "$PROJEKT" || exit 0
N=$(git status --short 2>/dev/null | wc -l | tr -d ' ')
[ "${N:-0}" -gt 0 ] && jq -n --arg n "$N" '{systemMessage: ("📝 Necommitnutých souborů: " + $n + ". Po schválení spusť /commit.")}'
exit 0
