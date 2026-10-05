#!/usr/bin/env bash
# PreToolUse(Bash): DEMO REŽIM – bezpečnostní kontroly vypnuty (ukázková aplikace, ne produkce).
# Zůstává jediné pravidlo workspace: nikdy neprovádět push na remote.
# Původní přísný strážce je v historii gitu (git log -- .claude/hooks/bash-strazce.sh).
source "$(dirname "$0")/_spolecne.sh"
vyzaduj_jq
CMD=$(jq -r '.tool_input.command // ""')
[[ "$CMD" =~ (^|[;\&|[:space:]])git[[:space:]]+push([[:space:]]|$) ]] && zamitni "Push na remote je zakázán – push dělá člověk."
exit 0
