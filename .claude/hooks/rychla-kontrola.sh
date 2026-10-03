#!/usr/bin/env bash
# Stop hook v frontmatteru agenta 'programator' (běží jako SubagentStop).
# Když kontrola selže, exit 2 => programátor NESMÍ skončit a pokračuje v opravách.
# Fail-closed: neběží-li app nebo chybí vendor/, také exit 2 (s návodem).
# Pojistka proti nekonečné smyčce: max 3 vrácení za sebou, pak systemMessage a exit 0.
source "$(dirname "$0")/_spolecne.sh"
command -v jq >/dev/null 2>&1 || exit 0
INPUT=$(cat)
SID=$(echo "$INPUT" | jq -r '.session_id // "x"')
AID=$(echo "$INPUT" | jq -r '.agent_id // "x"')
CNT="$LOGDIR/.kontrola-$SID-$AID"
cd "$PROJEKT" || exit 0
# Repo před M1 (bez compose.yaml) nic neblokuje.
[ -f compose.yaml ] || exit 0

# Společné vrácení programátorovi s počítadlem (max 3).
vrat() {
  local N
  N=$(( $(cat "$CNT" 2>/dev/null || echo 0) + 1 )); echo "$N" > "$CNT"
  if [ "$N" -gt 3 ]; then
    rm -f "$CNT"
    jq -n '{systemMessage:"⚠ Programátor 3× nesplnil rychlou kontrolu – předávám vedoucímu týmu."}'
    exit 0
  fi
  echo "Rychlá kontrola selhala (pokus $N/3). $1" >&2
  [ -n "${2:-}" ] && echo "$2" | tail -60 >&2
  exit 2
}

if [ -z "$(docker compose ps -q --status running app 2>/dev/null)" ]; then
  vrat "Kontejner app neběží – spusť make up (kontrola se na hostiteli neprovádí)."
fi
if ! docker compose exec -T app sh -c 'test -x vendor/bin/phpunit' 2>/dev/null; then
  vrat "Chybí vendor/ – spusť make up (nebo make composer ARGS=\"install\")."
fi

OUT=$(docker compose exec -T app composer check 2>&1 && docker compose exec -T app composer test 2>&1)
if [ $? -ne 0 ]; then
  vrat "Oprav chyby, než skončíš:" "$OUT"
fi
rm -f "$CNT"; exit 0
