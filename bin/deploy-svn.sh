#!/bin/bash
set -euo pipefail

PLUGIN_SLUG="tagbridge"
PLUGIN_VERSION="0.2.2"
SVN_URL="https://plugins.svn.wordpress.org/${PLUGIN_SLUG}"
SVN_DIR="${HOME}/Projects/tagbridge-svn"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"

echo "==> Deploying ${PLUGIN_SLUG} v${PLUGIN_VERSION} to WordPress.org SVN"

# 1. Checkout SVN if not already present
if [ ! -d "$SVN_DIR" ]; then
    echo "==> Checking out SVN repo..."
    svn co "$SVN_URL" "$SVN_DIR"
else
    echo "==> Updating existing SVN checkout..."
    svn up "$SVN_DIR"
fi

# 2. Clean trunk
echo "==> Cleaning trunk..."
rm -rf "${SVN_DIR}/trunk/"*

# 3. Build production vendor
echo "==> Installing production dependencies..."
TEMP_VENDOR=$(mktemp -d)
cp "$PLUGIN_DIR/composer.json" "$TEMP_VENDOR/"
cp "$PLUGIN_DIR/composer.lock" "$TEMP_VENDOR/"
(cd "$TEMP_VENDOR" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)

# 4. Copy plugin files to trunk
echo "==> Copying plugin files to trunk..."
cp "$PLUGIN_DIR/tagbridge.php" "${SVN_DIR}/trunk/"
cp "$PLUGIN_DIR/uninstall.php" "${SVN_DIR}/trunk/"
cp "$PLUGIN_DIR/readme.txt" "${SVN_DIR}/trunk/"
cp -R "$PLUGIN_DIR/src" "${SVN_DIR}/trunk/src"
cp -R "$PLUGIN_DIR/assets" "${SVN_DIR}/trunk/assets"
cp -R "$PLUGIN_DIR/languages" "${SVN_DIR}/trunk/languages"

# Production vendor (from temp build)
cp -R "$TEMP_VENDOR/vendor" "${SVN_DIR}/trunk/vendor"
rm -rf "$TEMP_VENDOR"

# 5. Copy WordPress.org assets (banners, screenshots, icons)
echo "==> Copying plugin directory assets..."
mkdir -p "${SVN_DIR}/assets"
if [ -d "$PLUGIN_DIR/.wordpress-org" ]; then
    cp "$PLUGIN_DIR/.wordpress-org/"* "${SVN_DIR}/assets/" 2>/dev/null || true
fi

# 6. Create tag
echo "==> Creating tag ${PLUGIN_VERSION}..."
rm -rf "${SVN_DIR}/tags/${PLUGIN_VERSION}"
cp -R "${SVN_DIR}/trunk" "${SVN_DIR}/tags/${PLUGIN_VERSION}"

# 7. SVN add/remove changes
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
