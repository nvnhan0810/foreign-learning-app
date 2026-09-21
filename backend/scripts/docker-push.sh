#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

: "${GHCR_IMAGE:?Set GHCR_IMAGE, e.g. ghcr.io/nvnhan0810/my-foreign-language-coures}"
: "${GHCR_TOKEN:?Set GHCR_TOKEN (GitHub PAT with write:packages or GITHUB_TOKEN)}"

TAG="${1:-latest}"
APP_URL="${APP_URL:-https://flc.nvnhan0810.com}"
PLATFORM="${PLATFORM:-linux/amd64}"

echo "$GHCR_TOKEN" | docker login ghcr.io -u "${GHCR_USER:-nvnhan0810}" --password-stdin

docker buildx build \
  --platform "$PLATFORM" \
  --build-arg "APP_URL=${APP_URL}" \
  -t "${GHCR_IMAGE}:${TAG}" \
  --push \
  .

echo "Pushed ${GHCR_IMAGE}:${TAG}"
