#!/usr/bin/env bash
# Asynchronní auditní stopa: kdo (agent) co (nástroj) kdy. Podklad pro /retro.
source "$(dirname "$0")/_spolecne.sh"
command -v jq >/dev/null 2>&1 || exit 0
jq -c '{
  cas: (now | todate),
  udalost: .hook_event_name,
  agent: (.agent_type // "hlavni"),
  nastroj: (.tool_name // null),
  souhrn: ((.tool_input.command // .tool_input.file_path // .tool_input.description // "") | tostring | .[0:160]),
  rezim: (.permission_mode // null)
}' >> "$LOGDIR/audit.jsonl" 2>/dev/null
exit 0
