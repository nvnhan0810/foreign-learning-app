#!/bin/sh
# Generate/copy objective_c.framework.dSYM for App Store archives.
# See mobile/README.md — "iOS dSYM (objective_c.framework)".

FRAMEWORK_BIN="${1:-${TARGET_BUILD_DIR}/${FRAMEWORKS_FOLDER_PATH}/objective_c.framework/objective_c}"
DSYM_OUT="${2:-${DWARF_DSYM_FOLDER_PATH}/objective_c.framework.dSYM}"

if [ ! -f "${FRAMEWORK_BIN}" ]; then
  exit 0
fi

if [ -z "${DSYM_OUT}" ] || [ "${DSYM_OUT}" = "/objective_c.framework.dSYM" ]; then
  echo "warning: DWARF_DSYM_FOLDER_PATH not set; skip objective_c dSYM" >&2
  exit 0
fi

echo "note: Generating dSYM for objective_c.framework"
mkdir -p "$(dirname "${DSYM_OUT}")"
xcrun dsymutil "${FRAMEWORK_BIN}" -o "${DSYM_OUT}" || {
  echo "warning: objective_c dSYM could not be generated (known Flutter/objective_c issue)" >&2
  exit 0
}
