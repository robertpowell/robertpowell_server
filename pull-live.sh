#!/bin/bash
# Copy the live files on this box INTO the repository checkout, following the manifest.
# Run after editing something in place, then `git status` / `git diff` shows exactly what changed.
# Never deletes anything live. Removes repo files that no longer exist live so deletions show in git.
set -euo pipefail
cd "$(dirname "$(readlink -f "$0")")"
. ./lib.sh
for_each_entry pull
echo "Pulled live files into $(pwd). Now: git status"
