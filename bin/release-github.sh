#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-$(grep -m1 'Version:' "${ROOT}/tagbridge.php" | awk '{print $NF}')}"
VERSION="${VERSION#v}"
TAG="v${VERSION}"
ZIP="${ROOT}/dist/tagbridge.zip"

if ! command -v gh >/dev/null 2>&1; then
	echo "Error: GitHub CLI (gh) is required." >&2
	exit 1
fi

if [ -z "${GH_TOKEN:-}" ] && ! gh auth status >/dev/null 2>&1; then
	echo "Error: gh is not authenticated. Run 'gh auth login' first." >&2
	exit 1
fi

if ! git -C "${ROOT}" rev-parse -q --verify "refs/tags/${TAG}" >/dev/null; then
	echo "Error: git tag ${TAG} does not exist locally." >&2
	echo "Create and push it first:" >&2
	echo "  git tag ${TAG} && git push origin ${TAG}" >&2
	exit 1
fi

bash "${ROOT}/bin/build-zip.sh"

CHANGELOG="$(awk -v ver="${VERSION}" '
	$0 == "= " ver " =" { in_sec = 1; next }
	in_sec && /^=/ { exit }
	in_sec { print }
' "${ROOT}/readme.txt")"

if [ -z "${CHANGELOG//[[:space:]]/}" ]; then
	echo "Error: no changelog entry found for ${VERSION} in readme.txt." >&2
	exit 1
fi

REQ_PHP="$(grep -m1 '^Requires PHP:' "${ROOT}/readme.txt" | awk '{print $NF}')"
REQ_WP="$(grep -m1 '^Requires at least:' "${ROOT}/readme.txt" | awk '{print $NF}')"
TESTED_WP="$(grep -m1 '^Tested up to:' "${ROOT}/readme.txt" | awk '{print $NF}')"

NOTES="$(mktemp)"
trap 'rm -f "${NOTES}"' EXIT

cat > "${NOTES}" <<EOF
Modular integration platform for WordPress, starting with a deep, independent integration for PostHog. Not affiliated with PostHog.

Install: download \`tagbridge.zip\` below and upload it via Plugins -> Add New -> Upload Plugin (or install directly from WordPress.org).

### What's New in ${VERSION}

${CHANGELOG}

Requires PHP ${REQ_PHP:-8.2}+ and WordPress ${REQ_WP:-5.8}+. WooCommerce optional. Tested up to WordPress ${TESTED_WP:-7.0}.
EOF

if gh release view "${TAG}" >/dev/null 2>&1; then
	gh release edit "${TAG}" --title "Tagbridge ${VERSION}" --notes-file "${NOTES}"
	gh release upload "${TAG}" "${ZIP}" --clobber
	echo "Updated GitHub release ${TAG}"
else
	gh release create "${TAG}" "${ZIP}" --title "Tagbridge ${VERSION}" --notes-file "${NOTES}"
	echo "Published GitHub release ${TAG}"
fi
