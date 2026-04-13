#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="merchant-wiki-seo-audit"
DIST_DIR="${ROOT_DIR}/dist"
BUILD_DIR="${DIST_DIR}/${SLUG}"
ZIP_PATH="${DIST_DIR}/${SLUG}.zip"

rm -rf "${BUILD_DIR}"
mkdir -p "${DIST_DIR}"

EXCLUDES=(
  '.git/'
  '.github/'
  '.vscode/'
  '.idea/'
  'node_modules/'
  'tests/'
  'docs/'
  '.wordpress-org/'
  '.distignore'
  '.ftp-deploy-sync-state.json'
  'dist/'
  'scripts/'
  'Pack Source.command.sh'
  'make_single_source.py'
  'source-*.txt'
  'merchant-wiki-audit.zip'
  'merchant-wiki-seo-audit.zip'
)

RSYNC_ARGS=("-a" "--delete")
for pattern in "${EXCLUDES[@]}"; do
  RSYNC_ARGS+=("--exclude=${pattern}")
done

RSYNC_ARGS+=("${ROOT_DIR}/" "${BUILD_DIR}/")
rsync "${RSYNC_ARGS[@]}"

pushd "${DIST_DIR}" >/dev/null
rm -f "${SLUG}.zip"
zip -r "${SLUG}.zip" "${SLUG}" >/dev/null
popd >/dev/null

echo "Created WordPress.org package at ${ZIP_PATH}"
