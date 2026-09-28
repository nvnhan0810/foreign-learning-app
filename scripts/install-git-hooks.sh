#!/usr/bin/env bash
# Point this clone at the shared .githooks/ directory.
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

git config core.hooksPath .githooks
chmod +x .githooks/pre-commit

echo "Git hooks installed (core.hooksPath=.githooks)."
