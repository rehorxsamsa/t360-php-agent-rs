#!/usr/bin/env bash
# Stop hook v frontmatteru agenta 'programator' (běží jako SubagentStop).
# Když kontrola selže, exit 2 => programátor NESMÍ skončit a pokračuje v opravách.
# Pojistka proti nekonečné smyčce: max 3 vrácení za sebou.
source "$(dirname "$0")/_spolecne.sh"
command -v jq >/dev/null 2>&1 || exit 0
INPUT=$(cat)
SID=$(echo "$INPUT" | jq -r '.session_id // "x"')
AID=$(echo "$INPUT" | jq -r '.agent_id // "x"')
CNT="$LOGDIR/.kontrola-$SID-$AID"
cd "$PROJEKT" || exit 0
[ -n "$(docker compose ps -q --status running app 2>/dev/null)" ] || exit 0
docker compose exec -T app sh -c 'test -x vendor/bin/phpunit' 2>/dev/null || exit 0

OUT=$(docker compose exec -T app composer -q check 2>&1 && docker compose exec -T app composer -q test 2>&1)
if [ $? -ne 0 ]; then
  N=$(( $(cat "$CNT" 2>/dev/null || echo 0) + 1 )); echo "$N" > "$CNT"
  if [ "$N" -gt 3 ]; then
    rm -f "$CNT"
    jq -n '{systemMessage:"⚠ Programátor 3× nesplnil rychlou kontrolu – předávám vedoucímu týmu."}'
    exit 0
  fi
  echo "Rychlá kontrola selhala (pokus $N/3). Oprav chyby, než skončíš:" >&2
  echo "$OUT" | tail -40 >&2
  exit 2
fi
rm -f "$CNT"; exit 0
