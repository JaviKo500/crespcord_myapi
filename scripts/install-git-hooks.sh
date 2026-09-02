#!/bin/bash

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SRC="$(dirname "$SCRIPT_DIR")"

cd "$SRC" || exit 1

# core.hooksPath points git at the committed hooks instead of copying them into
# .git/hooks: no copy to keep in sync, and a change to the hook reaches everyone
# on the next pull.
git config core.hooksPath scripts/hooks

echo "Hooks instalados: core.hooksPath = scripts/hooks"
echo "Para desinstalarlos: git config --unset core.hooksPath"
