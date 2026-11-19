#!/usr/bin/env bash

set -euo pipefail

SLUG="tagbridge"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAGE="$(mktemp -d)"
DEST="${STAGE}/${SLUG}"
DIST="${ROOT}/dist"

echo "Building ${SLUG}.zip ..."
mkdir -p "${DEST}" "${DIST}"
rm -f "${DIST}/${SLUG}.zip"

cp "${ROOT}/tagbridge.php"  "${DEST}/"
cp "${ROOT}/uninstall.php" "${DEST}/"
cp "${ROOT}/readme.txt"    "${DEST}/"
cp -R "${ROOT}/src"        "${DEST}/"
cp -R "${ROOT}/assets"     "${DEST}/"
cp -R "${ROOT}/languages"  "${DEST}/"

rm -rf "${DEST}/assets/img"

cp "${ROOT}/composer.json" "${DEST}/"
cp "${ROOT}/composer.lock" "${DEST}/"
( cd "${DEST}" && composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --quiet )

find "${DEST}" -name ".DS_Store" -delete
find "${DEST}" -name ".gitkeep" -delete
find "${DEST}" -type d -name ".git" -prune -exec rm -rf {} +

find "${DEST}/vendor" -type d \( \
	-iname tests -o -iname test -o -iname examples -o -iname ".github" \
	-o -iname ".changeset" -o -iname scripts -o -iname Test -o -iname bin \
	\) -prune -exec rm -rf {} +
find "${DEST}/vendor" -type f \( \
	-iname "*.sh" -o -iname ".gitignore" -o -iname ".gitattributes" -o -iname "*.dist" \
	-o -iname "phpunit*.xml*" -o -iname "RELEASING.md" -o -iname "CONTRIBUTING.md" \
	-o -iname "CHANGELOG.md" -o -iname "Makefile" -o -iname "*.yml" -o -iname "*.yaml" \
	\) -delete

find "${DEST}" -depth -name ".*" ! -name "." ! -name ".." -exec rm -rf {} +

( cd "${STAGE}" && zip -rq "${DIST}/${SLUG}.zip" "${SLUG}" )

rm -rf "${STAGE}"

echo "Done: ${DIST}/${SLUG}.zip"
ls -lh "${DIST}/${SLUG}.zip"
