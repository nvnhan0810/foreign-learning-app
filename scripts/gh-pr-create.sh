#!/usr/bin/env bash
# Create a GitHub PR and strip Cursor attribution the agent runtime may inject.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
strip="${repo_root}/scripts/strip-cursor-attribution.sh"

if [[ ! -x "$strip" ]]; then
  echo "Missing executable: $strip" >&2
  exit 1
fi

pr_url="$(gh pr create "$@")"
pr_number="$(gh pr view "$pr_url" --json number -q .number)"
body="$(gh pr view "$pr_number" --json body -q .body)"
clean="$(printf '%s\n' "$body" | "$strip")"

if [[ "$body" != "$clean" ]]; then
  gh pr edit "$pr_number" --body "$clean" >/dev/null
fi

printf '%s\n' "$pr_url"
