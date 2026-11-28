#!/bin/bash
# One step for "I changed something live on this box": pull the live files into the checkout,
# commit whatever changed and push to GitHub. Use it at the end of ANY live edit, including
# edits made over SSH from another box (the daily report says "Out of sync" until this runs).
#   ./commit-live.sh "what changed and why"
# Touches nothing live. Prints the changed files; does nothing if live already matches the repo.
# A new live PATH (not under a manifest entry) is not seen: add a manifest line first.
set -euo pipefail
cd "$(dirname "$(readlink -f "$0")")"
MSG=${1:-}
[ -n "$MSG" ] || { echo "usage: $0 \"what changed and why\"" >&2; exit 2; }
git pull --quiet --ff-only
./pull-live.sh >/dev/null
if [ -z "$(git status --porcelain)" ]; then
    echo "Nothing to commit: live matches the repo at $(git rev-parse --short HEAD)."
    exit 0
fi
git add -A
git status --short
git commit --quiet -m "$MSG"
git push --quiet
echo "Committed and pushed $(git rev-parse --short HEAD)."
