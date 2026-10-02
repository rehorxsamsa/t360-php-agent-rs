#!/usr/bin/env bash
# Notification: desktopová notifikace přes terminálovou sekvenci (funguje i ve WSL / Windows Terminal).
command -v jq >/dev/null 2>&1 || exit 0
MSG=$(jq -r '.message // "Claude Code čeká na tebe"')
SEQ=$(printf '\033]9;%s\007\033]777;notify;Redakční systém;%s\007' "$MSG" "$MSG")
jq -nc --arg s "$SEQ" '{terminalSequence: $s}'
