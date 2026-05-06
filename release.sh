#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# release.sh — Build & release nera-instant-win-threshold to its GitHub repo.
#
# Usage:
#   ./release.sh          # reads version from nera-instant-win-threshold.php (Version header)
#   ./release.sh 1.2.0    # override version (optional leading v: v1.2.0)
#
# Requirements: git, grep, sed, php + build-wp-release-zip.php (or zip), gh (optional).
# Optional SSH: set origin to $GITHUB_REMOTE or use HTTPS (see theme release.sh).
#
# What it does:
#   1. Resolves version from argument or main plugin file
#   2. npm run build when package.json exists
#   3. Copies plugin to a clean temp dir; sets * Version + NERA_IWT_VERSION; syncs readme Stable tag + plugin.json
#   4. Builds PLUGIN_SLUG-VERSION.zip (PHP ZipArchive preferred — never PowerShell Compress-Archive)
#   5. Syncs the clean tree back into this git repo, commits, pushes main + tag (no force-push to main)
#   6. gh release create / upload on github.com
#
# Why this file was missing on GitHub: an older flow used `git init` in /tmp without `release.sh`,
# then `git push --force` to main, so the remote never contained this script. This version follows
# the theme release pattern: the real repo keeps and commits `release.sh`.
# ─────────────────────────────────────────────────────────────────────────────
set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_SLUG="nera-instant-win-threshold"
GITHUB_REPO="Nera-Marketing/nera-instant-win-threshold"
GITHUB_REMOTE="git@github.com-nera:${GITHUB_REPO}.git"
# github.com-nera = SSH Host alias only (same as nera-spin-to-win/release.sh). gh uses GH_HOST=github.com.

PID="$$"
WORK_DIR="/tmp/${PLUGIN_SLUG}-release-${PID}"
STAGE_ZIP_PARENT="/tmp/${PLUGIN_SLUG}-zipparent-${PID}"

cleanup() {
  rm -rf "$WORK_DIR" "$STAGE_ZIP_PARENT" 2>/dev/null || true
}
trap cleanup EXIT

# ── 1. Resolve version ────────────────────────────────────────────────────────
if [ -n "${1:-}" ]; then
  VERSION="${1#v}"
else
  VERSION=$(grep -m1 '^ \* Version:' "$PLUGIN_DIR/${PLUGIN_SLUG}.php" | sed 's/.*Version: *//')
fi

if [ -z "$VERSION" ]; then
  echo "ERROR: Could not determine version. Pass it as an argument: ./release.sh 1.2.0"
  exit 1
fi

TAG="v${VERSION}"

echo "──────────────────────────────────────────"
echo " Releasing $PLUGIN_SLUG $TAG"
echo "──────────────────────────────────────────"

for cmd in git grep sed; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "ERROR: required command not found: $cmd"
    exit 1
  fi
done

# ── 2. Build assets (optional) ────────────────────────────────────────────────
if [ -f "$PLUGIN_DIR/package.json" ]; then
  if ! command -v npm >/dev/null 2>&1; then
    echo "ERROR: package.json exists but npm is not in PATH."
    exit 1
  fi
  echo "▶ Building assets (npm run build)..."
  ( cd "$PLUGIN_DIR" && npm run build )
else
  echo "▶ No package.json — skipping npm build."
fi

# ── 3. Clean temp copy (exclude release.sh from zip tree only; repo keeps the script) ──
rm -rf "$WORK_DIR"
mkdir -p "$WORK_DIR"

echo "▶ Copying plugin files..."
if command -v rsync >/dev/null 2>&1; then
  rsync -a \
    --exclude='.git' \
    --exclude='node_modules' \
    --exclude='release.sh' \
    --exclude='.DS_Store' \
    --exclude='*.bak' \
    --exclude="${PLUGIN_SLUG}-*.zip" \
    "$PLUGIN_DIR/" "$WORK_DIR/"
else
  cp -a "$PLUGIN_DIR"/. "$WORK_DIR"/
  rm -rf "$WORK_DIR/.git" "$WORK_DIR/node_modules" 2>/dev/null || true
  rm -f "$WORK_DIR/release.sh" "$WORK_DIR/.DS_Store" 2>/dev/null || true
  rm -f "$WORK_DIR/${PLUGIN_SLUG}"-*.zip 2>/dev/null || true
  find "$WORK_DIR" -name '*.bak' -type f -delete 2>/dev/null || true
fi

echo "▶ Setting * Version + NERA_IWT_VERSION to ${VERSION} in release tree (for PUC)..."
PHP_MAIN="$WORK_DIR/${PLUGIN_SLUG}.php"
if [ ! -f "$PHP_MAIN" ]; then
  echo "ERROR: Missing $PHP_MAIN"
  exit 1
fi
if sed --version >/dev/null 2>&1; then
  sed -i "s/^ \\* Version: .*/ * Version: ${VERSION}/" "$PHP_MAIN"
  sed -i "s/define( 'NERA_IWT_VERSION', '[^']*' );/define( 'NERA_IWT_VERSION', '${VERSION}' );/" "$PHP_MAIN"
else
  sed -i '' "s/^ \\* Version: .*/ * Version: ${VERSION}/" "$PHP_MAIN"
  sed -i '' "s/define( 'NERA_IWT_VERSION', '[^']*' );/define( 'NERA_IWT_VERSION', '${VERSION}' );/" "$PHP_MAIN"
fi

README_TXT="$WORK_DIR/readme.txt"
if [ -f "$README_TXT" ]; then
  echo "▶ Syncing Stable tag in readme.txt to ${VERSION}..."
  if sed --version >/dev/null 2>&1; then
    sed -i "s/^Stable tag: .*/Stable tag: ${VERSION}/" "$README_TXT"
  else
    sed -i '' "s/^Stable tag: .*/Stable tag: ${VERSION}/" "$README_TXT"
  fi
fi

JSON_META="$WORK_DIR/plugin.json"
if [ -f "$JSON_META" ]; then
  echo "▶ Syncing plugin.json version + download_url + last_updated (for self-hosted PUC)..."
  DOWNLOAD_URL="https://github.com/${GITHUB_REPO}/releases/download/${TAG}/${PLUGIN_SLUG}-${VERSION}.zip"
  LAST_UPDATED="$(date -u '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -u '+%Y-%m-%d %H:%M:%S')"
  if sed --version >/dev/null 2>&1; then
    sed -i "s/\"version\": *\"[^\"]*\"/\"version\": \"${VERSION}\"/" "$JSON_META"
    sed -i "s|\"download_url\": *\"[^\"]*\"|\"download_url\": \"${DOWNLOAD_URL}\"|" "$JSON_META"
    sed -i "s/\"last_updated\": *\"[^\"]*\"/\"last_updated\": \"${LAST_UPDATED}\"/" "$JSON_META"
  else
    sed -i '' "s/\"version\": *\"[^\"]*\"/\"version\": \"${VERSION}\"/" "$JSON_META"
    sed -i '' "s|\"download_url\": *\"[^\"]*\"|\"download_url\": \"${DOWNLOAD_URL}\"|" "$JSON_META"
    sed -i '' "s/\"last_updated\": *\"[^\"]*\"/\"last_updated\": \"${LAST_UPDATED}\"/" "$JSON_META"
  fi
fi

# ── 4. Build distributable zip ───────────────────────────────────────────────
ZIP_PATH="$PLUGIN_DIR/${PLUGIN_SLUG}-${VERSION}.zip"
echo "▶ Creating zip..."
rm -f "$ZIP_PATH"
rm -rf "$STAGE_ZIP_PARENT"
mkdir -p "$STAGE_ZIP_PARENT"
cp -a "$WORK_DIR" "$STAGE_ZIP_PARENT/${PLUGIN_SLUG}"
STAGE_SLUG_DIR="$STAGE_ZIP_PARENT/${PLUGIN_SLUG}"

if command -v php >/dev/null 2>&1 && [ -f "$PLUGIN_DIR/build-wp-release-zip.php" ]; then
  echo "▶ Building zip with PHP ZipArchive (WP-safe paths)..."
  php "$PLUGIN_DIR/build-wp-release-zip.php" "$STAGE_SLUG_DIR" "$ZIP_PATH"
elif command -v zip >/dev/null 2>&1; then
  ( cd "$STAGE_ZIP_PARENT" && zip -rq "$ZIP_PATH" "${PLUGIN_SLUG}" )
elif [ -x "/c/Program Files/Git/usr/bin/zip.exe" ]; then
  ( cd "$STAGE_ZIP_PARENT" && "/c/Program Files/Git/usr/bin/zip.exe" -rq "$ZIP_PATH" "${PLUGIN_SLUG}" )
else
  echo "ERROR: Need php + build-wp-release-zip.php, or zip (e.g. Git usr/bin/zip.exe)."
  echo "       Do not use PowerShell Compress-Archive for WordPress plugin zips."
  exit 1
fi
rm -rf "$STAGE_ZIP_PARENT"

if [ ! -s "$ZIP_PATH" ]; then
  echo "ERROR: Zip is missing or empty: $ZIP_PATH"
  exit 1
fi
echo "▶ Zip OK ($(wc -c < "$ZIP_PATH" | tr -d ' ') bytes)"

# ── 5. Commit + push (real repo — keeps release.sh) ──────────────────────────
echo "▶ Syncing release tree into git working tree..."
cd "$PLUGIN_DIR"

# Release commit author: prefer repo .git/config (--local), then global, then defaults.
RELEASE_AUTHOR_NAME="$(git config --local user.name 2>/dev/null || true)"
RELEASE_AUTHOR_EMAIL="$(git config --local user.email 2>/dev/null || true)"
if [ -z "$RELEASE_AUTHOR_NAME" ]; then
  RELEASE_AUTHOR_NAME="$(git config --global user.name 2>/dev/null || true)"
fi
if [ -z "$RELEASE_AUTHOR_EMAIL" ]; then
  RELEASE_AUTHOR_EMAIL="$(git config --global user.email 2>/dev/null || true)"
fi
if [ -z "$RELEASE_AUTHOR_NAME" ]; then
  RELEASE_AUTHOR_NAME="Minh Le"
fi
if [ -z "$RELEASE_AUTHOR_EMAIL" ]; then
  RELEASE_AUTHOR_EMAIL="minh@nera.marketing"
fi
git config user.name "$RELEASE_AUTHOR_NAME"
git config user.email "$RELEASE_AUTHOR_EMAIL"

if command -v rsync >/dev/null 2>&1; then
  rsync -a "$WORK_DIR/" "$PLUGIN_DIR/"
else
  if [ -f "/c/Windows/System32/robocopy.exe" ]; then
    WIN_SRC=$(cd "$WORK_DIR" && pwd -W)
    WIN_DST=$(cd "$PLUGIN_DIR" && pwd -W)
    if [ -n "$WIN_SRC" ] && [ -n "$WIN_DST" ]; then
      echo "▶ rsync not found — using robocopy (Windows)..."
      set +e
      MSYS_NO_PATHCONV=1 /c/Windows/System32/robocopy.exe "$WIN_SRC" "$WIN_DST" /E /R:2 /W:1 /NFL /NDL /NJH /NJS
      rc=$?
      set -e
      if [ "$rc" -ge 8 ]; then
        echo "ERROR: robocopy failed (exit $rc)"
        exit 1
      fi
    else
      echo "▶ rsync not found — pwd -W unavailable — using cp -a (Git Bash / Windows)..."
      ( cd "$WORK_DIR" && cp -a . "$PLUGIN_DIR/" )
    fi
  else
    echo "▶ rsync not found — using cp -a (Git Bash / Windows)..."
    ( cd "$WORK_DIR" && cp -a . "$PLUGIN_DIR/" )
  fi
fi

git add -A
if git diff --staged --quiet; then
  echo "▶ No staged changes after sync — skipping commit (tree already matches release)."
else
  git commit -m "Release $TAG" -q
fi

echo "▶ Pushing main to origin..."
git push origin main

if git rev-parse "$TAG" >/dev/null 2>&1; then
  git tag -d "$TAG" 2>/dev/null || true
fi
git tag -a "$TAG" -m "Release $TAG" 2>/dev/null || git tag "$TAG"

echo "▶ Pushing tag $TAG..."
git push origin "refs/tags/${TAG}" --force

# ── 6. GitHub Release (gh) ───────────────────────────────────────────────────
GH_CMD=""
if command -v gh >/dev/null 2>&1; then
  GH_CMD="gh"
elif [ -f "/c/Program Files/GitHub CLI/gh.exe" ]; then
  GH_CMD="/c/Program Files/GitHub CLI/gh.exe"
elif [ -f "/c/Program Files (x86)/GitHub CLI/gh.exe" ]; then
  GH_CMD="/c/Program Files (x86)/GitHub CLI/gh.exe"
elif [ -n "${HOME:-}" ] && [ -f "${HOME}/AppData/Local/Programs/GitHub CLI/gh.exe" ]; then
  GH_CMD="${HOME}/AppData/Local/Programs/GitHub CLI/gh.exe"
fi

if [ -n "$GH_CMD" ]; then
  echo "▶ Checking gh auth (github.com)..."
  if ! ( export GH_HOST=github.com && "$GH_CMD" auth status -h github.com >/dev/null 2>&1 ); then
    echo "ERROR: gh is not logged in for github.com."
    echo "       Run: gh auth login -h github.com"
    echo "       Zip kept at: $ZIP_PATH"
    exit 1
  fi

  echo "▶ Publishing GitHub Release $TAG (using: $GH_CMD)..."
  (
    export GH_HOST=github.com
    if "$GH_CMD" release view "$TAG" --repo "$GITHUB_REPO" >/dev/null 2>&1; then
      echo "▶ Release exists — uploading / replacing zip asset..."
      "$GH_CMD" release upload "$TAG" "$ZIP_PATH" --repo "$GITHUB_REPO" --clobber
    else
      "$GH_CMD" release create "$TAG" \
        --repo "$GITHUB_REPO" \
        --title "$TAG" \
        --notes "Release $TAG" \
        "$ZIP_PATH"
    fi
  )
  rm -f "$ZIP_PATH"
else
  echo ""
  echo "⚠  gh (GitHub CLI) not found — skipped GitHub Release upload."
  echo "    Zip:     $ZIP_PATH"
  echo "    Manual:  https://github.com/${GITHUB_REPO}/releases/new?tag=${TAG}"
  echo ""
fi

echo ""
echo "✅ Done! Tag $TAG is on GitHub."
if [ -n "$GH_CMD" ]; then
  echo "   Release: https://github.com/${GITHUB_REPO}/releases/tag/${TAG}"
  echo ""
  echo "WordPress sites with the plugin installed can pick up the update after PUC checks GitHub."
  echo "   Tip: Dashboard → Updates → Check again. Stale cache: wp transient delete update_plugins"
else
  echo "   Tag: https://github.com/${GITHUB_REPO}/releases/tag/${TAG}"
fi
