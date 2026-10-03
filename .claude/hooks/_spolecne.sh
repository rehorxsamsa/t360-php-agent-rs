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

# normalizuj_rel <cesta>: absolutní/relativní cesta -> cesta relativní k projektu, bez "/./", "//" a "..".
# Cesty mimo projekt zůstanou absolutní. Nemusí existovat (realpath -m).
normalizuj_rel() {
  local p=$1 root abs
  root=$(realpath -m -- "$PROJEKT" 2>/dev/null) || root=$PROJEKT
  [[ "$p" == /* ]] || p="$root/$p"
  abs=$(realpath -m -- "$p" 2>/dev/null) || abs=$p
  printf '%s' "${abs#"$root"/}"
}

# chraneny_soubor <relativní cesta>: vrátí 0, pokud soubor ovlivňuje spouštění na hostiteli,
# Docker, hooky nebo oprávnění agentů (změna = souhlas člověka). Paměť agentů je výjimka.
chraneny_soubor() {
  case "$1" in
    .claude/agent-memory/*) return 1 ;;
    compose*.y*ml|docker-compose*.y*ml|*.override.y*ml|*/compose*.y*ml|*/docker-compose*.y*ml) return 0 ;;
    Makefile|GNUmakefile|makefile|*/Makefile|*/GNUmakefile|*/makefile|*.mk) return 0 ;;
    docker/*|.githooks/*|tests/Hooks/*|.mcp.json|.dockerignore|.envrc) return 0 ;;
    .git|.git/*|.gitattributes|.gitmodules|*/.gitattributes|*/.gitmodules) return 0 ;;
    .claude/*|CLAUDE.md|AGENTS.md|*/CLAUDE.md|*/AGENTS.md) return 0 ;;
  esac
  return 1
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
