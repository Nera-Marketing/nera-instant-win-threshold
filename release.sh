#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# release.sh — Build & release nera-instant-win-threshold to its standalone GitHub repo.
#
# Usage:
#   ./release.sh          # reads version from plugin header automatically
#   ./release.sh 1.2.0    # override version
#
# What it does:
#   1. Reads version from nera-instant-win-threshold.php
#   2. Runs `npm run build` when package.json exists (optional; this plugin is PHP/assets only by default)
#   3. Copies the plugin into a clean temp dir (no node_modules, no .git)
#   4. Pushes to Nera-Marketing/nera-instant-win-threshold with tag vX.Y.Z
#   5. Creates a GitHub Release with the plugin zip attached (via gh CLI)
# ─────────────────────────────────────────────────────────────────────────────
set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_SLUG="nera-instant-win-threshold"
GITHUB_REPO="Nera-Marketing/nera-instant-win-threshold"
GITHUB_REMOTE="git@github.com-nera:${GITHUB_REPO}.git"

# ── 1. Resolve version ────────────────────────────────────────────────────────
if [ -n "$1" ]; then
  VERSION="$1"
else
  VERSION=$(grep -m1 '^ \* Version:' "$PLUGIN_DIR/nera-instant-win-threshold.php" | sed 's/.*Version: *//')
fi

if [ -z "$VERSION" ]; then
  echo "ERROR: Could not determine version. Pass it as an argument: ./release.sh 1.2.0"
  exit 1
fi

TAG="v${VERSION}"

echo "──────────────────────────────────────────"
echo " Releasing $PLUGIN_SLUG $TAG"
echo "──────────────────────────────────────────"

# ── 2. Build assets (optional) ────────────────────────────────────────────────
if [ -f "$PLUGIN_DIR/package.json" ]; then
  echo "▶ Building assets (npm run build)..."
  cd "$PLUGIN_DIR"
  npm run build
else
  echo "▶ No package.json — skipping npm build."
fi

# ── 3. Create clean temp copy ─────────────────────────────────────────────────
WORK_DIR="/tmp/${PLUGIN_SLUG}-release"
rm -rf "$WORK_DIR"
mkdir -p "$WORK_DIR"

echo "▶ Copying plugin files..."
rsync -a \
  --exclude='.git' \
  --exclude='node_modules' \
  --exclude='release.sh' \
  --exclude='.DS_Store' \
  --exclude="${PLUGIN_SLUG}-*.zip" \
  "$PLUGIN_DIR/" "$WORK_DIR/"

# ── 4. Push to GitHub ─────────────────────────────────────────────────────────
echo "▶ Pushing to GitHub..."
cd "$WORK_DIR"

git init -b main
git config user.name "Minh Le"
git config user.email "minh@nera.marketing"
git remote add origin "$GITHUB_REMOTE"

git add -A
git commit -m "Release $TAG"
git tag "$TAG"
git push origin main --force
git push origin "$TAG" --force

# ── 5. Create zip ─────────────────────────────────────────────────────────────
ZIP_PATH="$PLUGIN_DIR/${PLUGIN_SLUG}-${VERSION}.zip"
echo "▶ Creating zip..."
cd /tmp
cp -r "$WORK_DIR" "/tmp/${PLUGIN_SLUG}"
zip -rq "$ZIP_PATH" "${PLUGIN_SLUG}"
rm -rf "/tmp/${PLUGIN_SLUG}"

# ── 6. Create GitHub Release via gh CLI ───────────────────────────────────────
echo "▶ Creating GitHub Release $TAG..."
GH_HOST="github.com" gh release create "$TAG" \
  --repo "$GITHUB_REPO" \
  --title "$TAG" \
  --notes "Release $TAG" \
  "$ZIP_PATH"

rm -f "$ZIP_PATH"
rm -rf "$WORK_DIR"

echo ""
echo "✅ Done! Release $TAG is live."
echo "   https://github.com/${GITHUB_REPO}/releases/tag/${TAG}"
echo ""
echo "WordPress sites with the plugin installed will now see the update notification."
