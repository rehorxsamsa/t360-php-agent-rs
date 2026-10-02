#!/usr/bin/env bash
# PreToolUse(Bash, if: git commit *): skener tajemství nad připravenými změnami.
source "$(dirname "$0")/_spolecne.sh"
cd "$PROJEKT" || exit 0
STAGED=$(git diff --cached --name-only 2>/dev/null)
[ -z "$STAGED" ] && exit 0
if echo "$STAGED" | grep -Eq '(^|/)\.env($|\.)' && ! echo "$STAGED" | grep -qx '.env.example'; then
  echo "Commit obsahuje .env soubor. Odeber ho: git restore --staged <soubor>" >&2; exit 2
fi
VZORY='sk-ant-[A-Za-z0-9_-]{20,}|AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9]{36}|github_pat_[A-Za-z0-9_]{40,}|-----BEGIN [A-Z ]*PRIVATE KEY-----|(password|heslo|secret)[[:space:]]*[:=][[:space:]]*["'"'"'][^"'"'"'$]{8,}'
NALEZ=$(git diff --cached -U0 | grep -E '^\+' | grep -Ei "$VZORY" | head -5)
if [ -n "$NALEZ" ]; then
  echo "Možné tajemství v commitu – commit zablokován:" >&2
  echo "$NALEZ" | sed -E 's/(.{12}).*/\1…/' >&2
  echo "Přesuň hodnotu do .env a do kódu dej čtení z prostředí." >&2
  exit 2
fi
exit 0
