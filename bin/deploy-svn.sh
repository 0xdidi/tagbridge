#!/bin/bash
set -euo pipefail

PLUGIN_SLUG="tagbridge"
SVN_URL="https://plugins.svn.wordpress.org/${PLUGIN_SLUG}"
SVN_DIR="${HOME}/Projects/tagbridge-svn"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_VERSION="$(grep -m1 'Version:' "${PLUGIN_DIR}/tagbridge.php" | awk '{print $NF}')"

echo "==> Deploying ${PLUGIN_SLUG} v${PLUGIN_VERSION} to WordPress.org SVN"

if [ ! -d "$SVN_DIR" ]; then
    echo "==> Checking out SVN repo..."
    svn co "$SVN_URL" "$SVN_DIR"
else
    echo "==> Updating existing SVN checkout..."
    svn up "$SVN_DIR"
fi

echo "==> Cleaning trunk..."
rm -rf "${SVN_DIR}/trunk/"*

echo "==> Installing production dependencies..."
TEMP_VENDOR=$(mktemp -d)
cp "$PLUGIN_DIR/composer.json" "$TEMP_VENDOR/"
cp "$PLUGIN_DIR/composer.lock" "$TEMP_VENDOR/"
(cd "$TEMP_VENDOR" && composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --quiet)

echo "==> Copying plugin files to trunk..."
cp "$PLUGIN_DIR/tagbridge.php" "${SVN_DIR}/trunk/"
cp "$PLUGIN_DIR/uninstall.php" "${SVN_DIR}/trunk/"
cp "$PLUGIN_DIR/readme.txt" "${SVN_DIR}/trunk/"
cp "$PLUGIN_DIR/composer.json" "${SVN_DIR}/trunk/"
cp "$PLUGIN_DIR/composer.lock" "${SVN_DIR}/trunk/"
cp -R "$PLUGIN_DIR/src" "${SVN_DIR}/trunk/src"
cp -R "$PLUGIN_DIR/assets" "${SVN_DIR}/trunk/assets"
cp -R "$PLUGIN_DIR/languages" "${SVN_DIR}/trunk/languages"

rm -rf "${SVN_DIR}/trunk/assets/img"

cp -R "$TEMP_VENDOR/vendor" "${SVN_DIR}/trunk/vendor"
rm -rf "$TEMP_VENDOR"

find "${SVN_DIR}/trunk" -name ".DS_Store" -delete
find "${SVN_DIR}/trunk" -name ".gitkeep" -delete
find "${SVN_DIR}/trunk" -type d -name ".git" -prune -exec rm -rf {} +
find "${SVN_DIR}/trunk/vendor" -type d \( \
    -iname tests -o -iname test -o -iname examples -o -iname ".github" \
    -o -iname ".changeset" -o -iname scripts -o -iname bin \
    \) -prune -exec rm -rf {} +
find "${SVN_DIR}/trunk/vendor" -type f \( \
    -iname "*.sh" -o -iname ".gitignore" -o -iname ".gitattributes" -o -iname "*.dist" \
    -o -iname "phpunit*.xml*" -o -iname "RELEASING.md" -o -iname "CONTRIBUTING.md" \
    -o -iname "CHANGELOG.md" -o -iname "Makefile" -o -iname "*.yml" -o -iname "*.yaml" \
    \) -delete
find "${SVN_DIR}/trunk" -depth -name ".*" ! -name "." ! -name ".." -exec rm -rf {} +

echo "==> Copying plugin directory assets..."
mkdir -p "${SVN_DIR}/assets"
if [ -d "$PLUGIN_DIR/.wordpress-org" ]; then
    cp "$PLUGIN_DIR/.wordpress-org/"* "${SVN_DIR}/assets/" 2>/dev/null || true
fi

echo "==> Creating tag ${PLUGIN_VERSION}..."
rm -rf "${SVN_DIR}/tags/${PLUGIN_VERSION}"
cp -R "${SVN_DIR}/trunk" "${SVN_DIR}/tags/${PLUGIN_VERSION}"

echo "==> Staging SVN changes..."
cd "$SVN_DIR"
svn add --force . --quiet 2>/dev/null || true
svn status | grep '^\!' | awk '{print $2}' | xargs -I {} svn rm --quiet {} 2>/dev/null || true

echo ""
echo "==> Ready to commit. Review with:"
echo "    cd ${SVN_DIR} && svn status"
echo ""
echo "==> Then commit with:"
echo "    svn ci -m 'Release v${PLUGIN_VERSION}' --username mcgreat"