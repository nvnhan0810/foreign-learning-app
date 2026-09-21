#!/usr/bin/env bash
# Remove Cursor attribution lines from commit messages or PR bodies (stdin).
set -euo pipefail

sed -E \
  -e '/^[[:space:]]*Made with (\[Cursor\]\(https:\/\/cursor\.com\)|Cursor)[[:space:]]*$/d' \
  -e '/^[[:space:]]*Co-authored-by:[[:space:]]*Cursor[[:space:]]*$/d' \
  -e '/^[[:space:]]*Made-with:[[:space:]]*Cursor[[:space:]]*$/d' \
  | sed -e :a -e '/^\n*$/{$d;N;ba' -e '}'
