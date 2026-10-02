#!/usr/bin/env bash
# Sdílené funkce hooků. Hooky dostávají JSON na stdin (viz docs: Hooks reference).
set -uo pipefail
PROJEKT="${CLAUDE_PROJECT_DIR:-$(pwd)}"
LOGDIR="$PROJEKT/.claude/logs"
mkdir -p "$LOGDIR"

vyzaduj_jq() {
  if ! command -v jq >/dev/null 2>&1; then
    echo "Hook vyžaduje 'jq' (sudo apt install jq). Akce zablokována pro jistotu." >&2
    exit 2   # bezpečnostní hook selhává ZAVŘENĚ
  fi
}

# Odmítnutí nástroje strukturovaně (PreToolUse) – Claude uvidí důvod.
zamitni() {
  jq -n --arg d "$1" '{hookSpecificOutput:{hookEventName:"PreToolUse",permissionDecision:"deny",permissionDecisionReason:$d}}'
  exit 0
}
# Eskalace na člověka (zobrazí se dotaz na povolení).
zeptej_se() {
  jq -n --arg d "$1" '{hookSpecificOutput:{hookEventName:"PreToolUse",permissionDecision:"ask",permissionDecisionReason:$d}}'
  exit 0
}
