#!/usr/bin/env bash
# FLC Mobile — bump version, clean, build Android AAB + iOS IPA, upload App Store.
#
# Usage:
#   ./scripts/release.sh                  # bump build (+N), build both, upload iOS
#   ./scripts/release.sh --bump patch     # 1.0.0+8 → 1.0.1+9
#   ./scripts/release.sh --bump minor     # 1.0.0+8 → 1.1.0+9
#   ./scripts/release.sh --bump major     # 1.0.0+8 → 2.0.0+9
#   ./scripts/release.sh --android-only
#   ./scripts/release.sh --ios-only
#   ./scripts/release.sh --skip-upload    # build IPA, do not upload
#   ./scripts/release.sh --no-bump        # keep current pubspec version
#
# App Store credentials (loaded automatically each run):
#   Prefer mobile/.env.release (NOT bundled into the Flutter app).
#   Fallback: mobile/.env — do NOT put ASC secrets there if .env is a Flutter asset.
#
#   ASC_KEY_ID=XXXXXXXXXX
#   ASC_ISSUER_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
#   # optional: ASC_KEY_PATH=/path/to/AuthKey_XXXXXXXXXX.p8
#   # else place AuthKey_${ASC_KEY_ID}.p8 in ~/.appstoreconnect/private_keys/
#
#   # or Apple ID + app-specific password:
#   APPLE_ID=you@example.com
#   APPLE_APP_SPECIFIC_PASSWORD=xxxx-xxxx-xxxx-xxxx
#
# Shell exports still win over .env files.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

BUMP="build" # build | patch | minor | major
DO_ANDROID=1
DO_IOS=1
DO_UPLOAD=1
DO_BUMP=1

while [[ $# -gt 0 ]]; do
  case "$1" in
    --bump)
      BUMP="${2:-}"
      [[ -n "$BUMP" ]] || { echo "Missing value for --bump"; exit 1; }
      shift 2
      ;;
    --no-bump) DO_BUMP=0; shift ;;
    --android-only) DO_IOS=0; shift ;;
    --ios-only) DO_ANDROID=0; shift ;;
    --skip-upload) DO_UPLOAD=0; shift ;;
    -h|--help)
      sed -n '2,28p' "$0" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
    *)
      echo "Unknown arg: $1"
      exit 1
      ;;
  esac
done

log() { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[error]\033[0m %s\n' "$*" >&2; exit 1; }

# Load KEY=VALUE from a dotenv file without overriding existing env vars.
load_dotenv_file() {
  local file="$1"
  [[ -f "$file" ]] || return 0

  local line key value
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    [[ -z "$line" || "$line" =~ ^[[:space:]]*# ]] && continue
    [[ "$line" == *=* ]] || continue

    key="${line%%=*}"
    value="${line#*=}"
    key="$(echo "$key" | sed -E 's/^[[:space:]]+|[[:space:]]+$//g')"
    value="$(echo "$value" | sed -E 's/^[[:space:]]+|[[:space:]]+$//g')"
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"

    # Only release-related keys from dotenv (avoid polluting the shell with app config).
    case "$key" in
      ASC_KEY_ID|ASC_ISSUER_ID|ASC_KEY_PATH|APPLE_ID|APPLE_APP_SPECIFIC_PASSWORD) ;;
      *) continue ;;
    esac

    if [[ -z "${!key:-}" ]]; then
      export "$key=$value"
    fi
  done < "$file"
}

load_release_env() {
  # Prefer dedicated release secrets file (not shipped in the app bundle).
  load_dotenv_file "$ROOT/.env.release"
  load_dotenv_file "$ROOT/.env"

  if [[ -f "$ROOT/.env" ]] && grep -qE '^[[:space:]]*ASC_(KEY_ID|ISSUER_ID)=' "$ROOT/.env" 2>/dev/null; then
    warn "ASC_* found in .env — that file is a Flutter asset. Prefer .env.release so keys are not bundled into the app."
  fi

  if [[ -n "${ASC_KEY_ID:-}" && -n "${ASC_ISSUER_ID:-}" ]]; then
    log "Loaded App Store API credentials (key id: $ASC_KEY_ID)"
  elif [[ -n "${APPLE_ID:-}" ]]; then
    log "Loaded Apple ID credentials for upload ($APPLE_ID)"
  fi
}

load_release_env

# Prefer FVM-pinned Flutter when available.
if command -v fvm >/dev/null 2>&1 && [[ -f "$ROOT/.fvmrc" ]]; then
  FLUTTER=(fvm flutter)
elif [[ -x "$ROOT/.fvm/flutter_sdk/bin/flutter" ]]; then
  FLUTTER=("$ROOT/.fvm/flutter_sdk/bin/flutter")
elif command -v flutter >/dev/null 2>&1; then
  FLUTTER=(flutter)
else
  die "Flutter not found. Install Flutter or run via FVM."
fi

PUBSPEC="$ROOT/pubspec.yaml"
[[ -f "$PUBSPEC" ]] || die "pubspec.yaml not found at $PUBSPEC"

current_version_line() {
  grep -E '^version:' "$PUBSPEC" | head -1
}

parse_version() {
  local line name build
  line="$(current_version_line)"
  line="${line#version:}"
  line="$(echo "$line" | tr -d '[:space:]')"
  name="${line%%+*}"
  build="${line##*+}"
  if [[ "$name" == "$line" ]]; then
    build=0
  fi
  IFS=. read -r MAJOR MINOR PATCH <<<"$name"
  BUILD="$build"
  MAJOR="${MAJOR:-0}"
  MINOR="${MINOR:-0}"
  PATCH="${PATCH:-0}"
  BUILD="${BUILD:-0}"
}

bump_version() {
  parse_version
  local old="${MAJOR}.${MINOR}.${PATCH}+${BUILD}"

  BUILD=$((BUILD + 1))
  case "$BUMP" in
    build) ;;
    patch) PATCH=$((PATCH + 1)) ;;
    minor) MINOR=$((MINOR + 1)); PATCH=0 ;;
    major) MAJOR=$((MAJOR + 1)); MINOR=0; PATCH=0 ;;
    *) die "Invalid --bump value: $BUMP (use build|patch|minor|major)" ;;
  esac

  local new="${MAJOR}.${MINOR}.${PATCH}+${BUILD}"
  if [[ "$(uname)" == Darwin ]]; then
    sed -i '' -E "s/^version:[[:space:]]*.*/version: ${new}/" "$PUBSPEC"
  else
    sed -i -E "s/^version:[[:space:]]*.*/version: ${new}/" "$PUBSPEC"
  fi

  # Keep Xcode MARKETING_VERSION in sync (Info.plist still uses FLUTTER_BUILD_NAME).
  local pbx="$ROOT/ios/Runner.xcodeproj/project.pbxproj"
  if [[ -f "$pbx" ]]; then
    if [[ "$(uname)" == Darwin ]]; then
      sed -i '' -E "s/MARKETING_VERSION = [^;]+;/MARKETING_VERSION = ${MAJOR}.${MINOR}.${PATCH};/g" "$pbx"
    else
      sed -i -E "s/MARKETING_VERSION = [^;]+;/MARKETING_VERSION = ${MAJOR}.${MINOR}.${PATCH};/g" "$pbx"
    fi
  fi

  log "Version: $old → $new"
  VERSION_NAME="${MAJOR}.${MINOR}.${PATCH}"
  VERSION_CODE="$BUILD"
}

resolve_flutter() {
  log "Using: ${FLUTTER[*]} ($("${FLUTTER[@]}" --version 2>/dev/null | head -1))"
}

clean_and_get() {
  log "flutter clean"
  "${FLUTTER[@]}" clean

  log "flutter pub get"
  "${FLUTTER[@]}" pub get
}

pod_install() {
  [[ "$DO_IOS" -eq 1 ]] || return 0
  command -v pod >/dev/null 2>&1 || die "CocoaPods (pod) not found. brew install cocoapods"

  log "pod install (ios/)"
  (
    cd "$ROOT/ios"
    # Avoid stale DerivedData issues after version bumps on some machines.
    pod install --repo-update
  )
}

build_android_aab() {
  [[ "$DO_ANDROID" -eq 1 ]] || return 0

  if [[ ! -f "$ROOT/android/key.properties" ]]; then
    warn "android/key.properties missing — release AAB may be signed with the debug key."
  elif [[ ! -f "$ROOT/android/app/upload-keystore.jks" ]]; then
    warn "android/app/upload-keystore.jks missing — check storeFile in key.properties."
  fi

  log "Build Android App Bundle (release)"
  "${FLUTTER[@]}" build appbundle --release

  local aab="$ROOT/build/app/outputs/bundle/release/app-release.aab"
  [[ -f "$aab" ]] || die "AAB not found at $aab"
  log "Android AAB ready: $aab"
  AAB_PATH="$aab"
}

resolve_asc_key_path() {
  if [[ -n "${ASC_KEY_PATH:-}" && -f "$ASC_KEY_PATH" ]]; then
    return 0
  fi
  if [[ -n "${ASC_KEY_ID:-}" ]]; then
    local default_key="$HOME/.appstoreconnect/private_keys/AuthKey_${ASC_KEY_ID}.p8"
    if [[ -f "$default_key" ]]; then
      ASC_KEY_PATH="$default_key"
      export ASC_KEY_PATH
    fi
  fi
}

export_ios_ipa_from_archive() {
  local archive="$ROOT/build/ios/archive/Runner.xcarchive"
  local export_dir="$ROOT/build/ios/ipa"
  local export_plist="$ROOT/ios/ExportOptions-AppStore.plist"

  [[ -d "$archive" ]] || return 1
  [[ -f "$export_plist" ]] || return 1

  resolve_asc_key_path
  if [[ -z "${ASC_KEY_ID:-}" || -z "${ASC_ISSUER_ID:-}" || ! -f "${ASC_KEY_PATH:-}" ]]; then
    warn "ASC auth export skipped (missing ASC_KEY_ID, ASC_ISSUER_ID, or .p8 key)."
    return 1
  fi

  log "Export IPA via xcodebuild (App Store Connect API auth)"
  rm -rf "$export_dir"
  mkdir -p "$export_dir"

  xcodebuild -exportArchive \
    -archivePath "$archive" \
    -exportPath "$export_dir" \
    -exportOptionsPlist "$export_plist" \
    -allowProvisioningUpdates \
    -authenticationKeyID "$ASC_KEY_ID" \
    -authenticationKeyIssuerID "$ASC_ISSUER_ID" \
    -authenticationKeyPath "$ASC_KEY_PATH"
}

build_ios_ipa() {
  [[ "$DO_IOS" -eq 1 ]] || return 0

  local export_plist="$ROOT/ios/ExportOptions-AppStore.plist"
  [[ -f "$export_plist" ]] || die "Missing $export_plist"

  log "Build iOS IPA (App Store / production)"
  if ! "${FLUTTER[@]}" build ipa --release --export-options-plist="$export_plist"; then
    warn "flutter build ipa failed — trying ASC auth export from archive"
  fi

  local ipa
  ipa="$(find "$ROOT/build/ios/ipa" -maxdepth 1 -name '*.ipa' | head -1 || true)"

  if [[ -z "$ipa" || ! -f "$ipa" ]]; then
    export_ios_ipa_from_archive || true
    ipa="$(find "$ROOT/build/ios/ipa" -maxdepth 1 -name '*.ipa' | head -1 || true)"
  fi

  [[ -n "$ipa" && -f "$ipa" ]] || die "IPA not found under build/ios/ipa/"
  log "iOS IPA ready: $ipa"
  IPA_PATH="$ipa"
}

upload_app_store() {
  [[ "$DO_IOS" -eq 1 && "$DO_UPLOAD" -eq 1 ]] || return 0
  [[ -n "${IPA_PATH:-}" && -f "$IPA_PATH" ]] || die "No IPA to upload"

  log "Upload IPA to App Store Connect"

  if [[ -n "${ASC_KEY_PATH:-}" ]]; then
    local key_file
    key_file="$(basename "$ASC_KEY_PATH")"
    # altool looks for AuthKey_<KEY_ID>.p8 under ./private_keys, ~/private_keys,
    # or ~/.appstoreconnect/private_keys — copy into the last location if needed.
    if [[ "$key_file" != "AuthKey_${ASC_KEY_ID}.p8" ]]; then
      warn "ASC_KEY_PATH should be named AuthKey_${ASC_KEY_ID}.p8 (got $key_file)"
    fi
    mkdir -p "$HOME/.appstoreconnect/private_keys"
    if [[ ! -f "$HOME/.appstoreconnect/private_keys/$key_file" ]]; then
      cp "$ASC_KEY_PATH" "$HOME/.appstoreconnect/private_keys/$key_file"
      log "Installed API key to ~/.appstoreconnect/private_keys/$key_file"
    fi
  fi

  if [[ -n "${ASC_KEY_ID:-}" && -n "${ASC_ISSUER_ID:-}" ]]; then
    xcrun altool --upload-app \
      --type ios \
      --file "$IPA_PATH" \
      --apiKey "$ASC_KEY_ID" \
      --apiIssuer "$ASC_ISSUER_ID"
  elif [[ -n "${APPLE_ID:-}" && -n "${APPLE_APP_SPECIFIC_PASSWORD:-}" ]]; then
    xcrun altool --upload-app \
      --type ios \
      --file "$IPA_PATH" \
      --username "$APPLE_ID" \
      --password "$APPLE_APP_SPECIFIC_PASSWORD"
  else
    warn "ASC_KEY_ID/ASC_ISSUER_ID or APPLE_ID/APPLE_APP_SPECIFIC_PASSWORD not set."
    warn "Add them to mobile/.env.release (recommended) then re-run, or upload via Transporter / Xcode Organizer."
    warn "Example .env.release:"
    warn "  ASC_KEY_ID=XXXXXXXXXX"
    warn "  ASC_ISSUER_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
    return 0
  fi

  log "Upload finished. Processing on App Store Connect may take a few minutes."
}

print_summary() {
  log "Done"
  echo "  version : $(current_version_line | sed 's/^version:[[:space:]]*//')"
  [[ -n "${AAB_PATH:-}" ]] && echo "  android : $AAB_PATH"
  [[ -n "${IPA_PATH:-}" ]] && echo "  ios     : $IPA_PATH"
  if [[ "$DO_IOS" -eq 1 && "$DO_UPLOAD" -eq 1 ]]; then
    if [[ -n "${ASC_KEY_ID:-}${APPLE_ID:-}" ]]; then
      echo "  upload  : App Store Connect (submitted)"
    else
      echo "  upload  : skipped (missing credentials)"
    fi
  elif [[ "$DO_UPLOAD" -eq 0 ]]; then
    echo "  upload  : skipped (--skip-upload)"
  fi
}

main() {
  resolve_flutter

  if [[ "$DO_BUMP" -eq 1 ]]; then
    bump_version
  else
    parse_version
    VERSION_NAME="${MAJOR}.${MINOR}.${PATCH}"
    VERSION_CODE="$BUILD"
    log "Keeping version ${VERSION_NAME}+${VERSION_CODE}"
  fi

  clean_and_get
  pod_install
  build_android_aab
  build_ios_ipa
  upload_app_store
  print_summary
}

main
