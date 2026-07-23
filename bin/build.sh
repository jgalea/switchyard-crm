#!/usr/bin/env bash
# Builds a distributable plugin directory and zip.
#
# The working tree carries dev tooling that must not ship: tests, CI config,
# linter configs and the composer manifests. Plugin Check and the WordPress.org
# review both flag those, so the build is what gets checked and released, not
# the repo. Output goes outside the plugin directory so it is never itself
# scanned as part of the plugin.
set -euo pipefail

SLUG="conduit-crm"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$(cd "${ROOT}/.." && pwd)/.conduit-build"
DEST="${OUT}/${SLUG}"

rm -rf "${OUT}"
mkdir -p "${DEST}"

# Production dependencies only, resolved against the supported PHP floor.
composer install --no-dev --quiet --working-dir="${ROOT}"

rsync -a --delete --exclude-from="${ROOT}/.distignore" "${ROOT}/" "${DEST}/"

( cd "${OUT}" && zip -qr "${SLUG}.zip" "${SLUG}" )

# Restore the dev dependencies the working tree expects.
composer install --quiet --working-dir="${ROOT}"

echo "Built ${OUT}/${SLUG}.zip"
