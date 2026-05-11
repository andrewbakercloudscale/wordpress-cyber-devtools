#!/bin/bash
# Build cloudscale-devtools.zip from the repo directory
# Creates a zip with cloudscale-devtools/ as the top level folder
# which is the structure WordPress expects for plugin upload
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Guard against diverged history caused by parallel sessions on the same machine.
# Only blocks if the remote has commits we don't (fast-forward only).
if git -C "$SCRIPT_DIR" fetch origin main --quiet 2>/dev/null; then
    LOCAL=$(git -C "$SCRIPT_DIR" rev-parse HEAD)
    REMOTE=$(git -C "$SCRIPT_DIR" rev-parse origin/main)
    if [ "$LOCAL" != "$REMOTE" ] && git -C "$SCRIPT_DIR" merge-base --is-ancestor "$LOCAL" "$REMOTE" 2>/dev/null; then
        echo "⚠ Remote is ahead of local — pulling before build to avoid drift..."
        git -C "$SCRIPT_DIR" pull --ff-only origin main
        echo "✓ Pulled. Continuing build."
    fi
fi

# Load shared Claude model config
GITHUB_DIR="$(dirname "$SCRIPT_DIR")"
# shellcheck source=../.claude-config.sh
source "$GITHUB_DIR/.claude-config.sh"
REPO_DIR="$SCRIPT_DIR"
ZIP_FILE="$SCRIPT_DIR/cloudscale-devtools.zip"
PLUGIN_NAME="cloudscale-devtools"
TEMP_DIR=$(mktemp -d)

echo "Building plugin zip from $REPO_DIR..."
# ── Auto-increment patch version ─────────────────────────────────────────────
MAIN_PHP=$(grep -rl "^ \* Version:" "$REPO_DIR" --include="*.php" 2>/dev/null | grep -v "repo/" | head -1)
if [ -z "$MAIN_PHP" ]; then
  echo "ERROR: Could not find main plugin PHP file with Version header."
  exit 1
fi
CURRENT_VER=$(grep "^ \* Version:" "$MAIN_PHP" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
if [ -z "$CURRENT_VER" ]; then
  echo "ERROR: Could not extract version from $MAIN_PHP"
  exit 1
fi
VER_MAJOR=$(echo "$CURRENT_VER" | cut -d. -f1)
VER_MINOR=$(echo "$CURRENT_VER" | cut -d. -f2)
VER_PATCH=$(echo "$CURRENT_VER" | cut -d. -f3)
NEW_VER="$VER_MAJOR.$VER_MINOR.$((VER_PATCH + 1))"
ESC_VER=$(echo "$CURRENT_VER" | sed 's/\./\\./g')
echo "Version bump: $CURRENT_VER → $NEW_VER"
while IFS= read -r vfile; do
  sed -i '' "s/$ESC_VER/$NEW_VER/g" "$vfile"
done < <(grep -rl "$CURRENT_VER" "$REPO_DIR" --include="*.php" --include="*.js" --include="*.txt" 2>/dev/null | grep -v "\.git" | grep -v "/repo/")
# Force-sync the VERSION class constant — the loop above only replaces the current
# version string, so a drifted constant (e.g. left at an older patch) is never updated.
sed -i '' "s/const VERSION\s*=\s*'[^']*'/const VERSION      = '$NEW_VER'/" "$MAIN_PHP"
# Sync readme.txt and main PHP into repo/ so SVN trunk always has correct version.
if [ -d "$REPO_DIR/repo" ]; then
  cp "$REPO_DIR/readme.txt" "$REPO_DIR/repo/readme.txt"
  sed -i '' "s/^ \* Version:.*/ * Version:     $NEW_VER/" "$REPO_DIR/repo/cs-code-block.php"
fi
# ─────────────────────────────────────────────────────────────────────────────

# PHP syntax check — abort before packaging if any file has a parse error
echo "Checking PHP syntax..."
LINT_ERRORS=0
while IFS= read -r -d '' phpfile; do
  result=$(php -l "$phpfile" 2>&1)
  if [ $? -ne 0 ]; then
    echo "$result"
    LINT_ERRORS=1
  fi
done < <(find "$REPO_DIR" -name "*.php" -print0)
if [ "$LINT_ERRORS" -ne 0 ]; then
  echo ""
  echo "ERROR: PHP syntax errors found above. Fix before deploying."
  exit 1
fi
echo "PHP syntax: OK"
echo ""

# PHP runtime include test — catches TypeError/fatal errors that php -l misses.
# Runs each include file through php -r to catch runtime errors like:
#   in_array(value, null) — passes syntax check but crashes on first request.
echo "Checking PHP runtime includes..."
RUNTIME_ERRORS=0
while IFS= read -r -d '' phpfile; do
  # Skip files that require WP constants to load (plugins, shortcodes etc)
  basename=$(basename "$phpfile")
  [[ "$basename" == "uninstall.php" ]] && continue
  result=$(php -r "
define('ABSPATH', '/tmp/');
define('WPINC', 'wp-includes');
define('DB_HOST', '');
\$_SERVER['HTTP_HOST'] = 'localhost';
// Suppress expected WP-not-loaded notices, catch fatals
set_error_handler(function(\$errno, \$str) {
    if (\$errno === E_FATAL || \$errno === E_ERROR) { echo \"FATAL: \$str\n\"; exit(1); }
    return true;
});
\$code = file_get_contents('$phpfile');
// Only test files that are pure class/function definitions (no WP bootstrap needed)
if (strpos(\$code, 'class ') !== false || strpos(\$code, 'function ') !== false) {
    if (strpos(\$code, 'require') === false && strpos(\$code, 'wp_') === false) {
        @include '$phpfile';
    }
}
" 2>&1 | grep -i "FATAL\|TypeError\|ParseError" || true)
  if [ -n "$result" ]; then
    echo "  RUNTIME ERROR in $phpfile:"
    echo "    $result"
    RUNTIME_ERRORS=1
  fi
done < <(find "$REPO_DIR/includes" -name "*.php" -print0 2>/dev/null)
if [ "$RUNTIME_ERRORS" -ne 0 ]; then
  echo ""
  echo "ERROR: PHP runtime errors found. These pass php -l but crash on first HTTP request."
  exit 1
fi
echo "PHP runtime: OK"
echo ""

if [ "${SKIP_REVIEW:-1}" != "1" ]; then
# WordPress plugin standards review — 2 parallel sections
echo -e "\033[1;34mRunning WordPress plugin standards review (parallel, model: haiku-4-5)...\033[0m"
CLAUDE="/opt/homebrew/bin/claude"
if [ ! -x "$CLAUDE" ]; then
  echo "ERROR: claude CLI not found at $CLAUDE — standards review is required."
  exit 1
fi

REVIEW_TMPDIR=$(mktemp -d)

_review_section() {
  local label="$1"; shift
  local file_list="$*"
  echo "--- Section: $label ---" > "$REVIEW_TMPDIR/$label.txt"
  (cd "$REPO_DIR" && "$CLAUDE" --dangerously-skip-permissions --model $CLAUDE_REVIEW_MODEL --print -p \
    "/wp-plugin-standards-review Review ONLY these files (read no others): $file_list.

BLOCKING RULES — only these trigger BUILD_STATUS: FAIL:
1. SQL injection: user-controlled input used directly in a SQL query WITHOUT $wpdb->prepare() AND without being cast/validated first
2. XSS: user-controlled data echoed into HTML WITHOUT esc_html/esc_attr/esc_url/wp_kses
3. CSRF: AJAX/form handler that modifies data WITHOUT check_ajax_referer or wp_verify_nonce
4. Missing ABSPATH guard at the top of a PHP file

NON-BLOCKING (never trigger FAIL, document as informational only):
- SQL queries with phpcs:ignore comments — already acknowledged by the developer, skip entirely
- Table name interpolations using $wpdb->prefix, $wpdb->posts, $wpdb->postmeta — always safe, skip
- Unicode characters, em dashes, or emoji used as display/placeholder values — not suspicious
- wp_unslash() + esc_url_raw() on \$_SERVER — correct WP pattern, not a violation
- \$wpdb->get_results( \$wpdb->prepare(...) ) — this is the correct WP pattern, not redundant
- implode of integer-cast IDs for IN clauses — safe, skip
- Version numbers that match between header and constant — not a violation
- Missing @since or DocBlock tags — documentation only, not security

End your response with EXACTLY one of: BUILD_STATUS: PASS or BUILD_STATUS: FAIL" \
    >> "$REVIEW_TMPDIR/$label.txt" 2>&1)
}

# Section 1: main plugin file
_review_section "main" cs-code-block.php &
PID1=$!

# Section 2: support files
_review_section "support" uninstall.php &
PID2=$!

wait $PID1 $PID2 || true  # don't abort on claude exit code; gate on content below

REVIEW=$(cat "$REVIEW_TMPDIR"/*.txt)
rm -rf "$REVIEW_TMPDIR"

echo -e "\033[1;34m$REVIEW\033[0m"
echo ""

# API/model errors are a hard failure — review did not run
if echo "$REVIEW" | grep -qiE 'API Error|invalid.*model|model.*invalid'; then
  echo "ERROR: Standards review failed — model API error. Check CLAUDE_REVIEW_MODEL in .claude-config.sh."
  exit 1
fi

if echo "$REVIEW" | grep -q 'BUILD_STATUS: FAIL'; then
  echo "ERROR: Standards review found CRITICAL or HIGH issues — fix before building."
  exit 1
fi

# Must have at least one BUILD_STATUS: PASS — missing means model did not complete
if ! echo "$REVIEW" | grep -q 'BUILD_STATUS: PASS'; then
  echo "ERROR: Standards review did not return BUILD_STATUS — model output incomplete."
  exit 1
fi

if echo "$REVIEW" | grep -qiE '[1-9][0-9]* medium'; then
  echo "WARNING: Standards review found MEDIUM issues — review before submitting to WordPress.org."
fi
echo "Standards review: OK"
echo ""
else
  echo "Standards review: skipped (run build-review.sh for full review)"
  echo ""
fi

# Create temp directory with plugin name as wrapper
mkdir -p "$TEMP_DIR/$PLUGIN_NAME"
rsync -a \
  --exclude='.*' \
  --exclude='*.zip' --exclude='*.sh' --exclude='*.xml' \
  --include='blocks/code/block.json' \
  --exclude='*.json' \
  --exclude='*.jpg' --exclude='*.png' --exclude='*.svg' \
  --exclude='repo/' --exclude='docs/' --exclude='tests/' \
  --exclude='node_modules/' --exclude='svn-assets/' \
  --exclude='playwright-report/' --exclude='playwright.config.js' \
  --exclude='*.backup' --exclude='*.config.js' \
  --exclude='memory/' \
  --exclude='migrate-prefix-csdt.php' \
  --exclude='generate-help-docs.js' \
  --exclude='update-help-page.php' \
  --exclude='pi-crontabs.txt' \
  "$REPO_DIR/" "$TEMP_DIR/$PLUGIN_NAME/"

# Build zip with correct structure
rm -f "$ZIP_FILE"
cd "$TEMP_DIR"
zip -r "$ZIP_FILE" "$PLUGIN_NAME/"

# Cleanup
rm -rf "$TEMP_DIR"

echo ""
echo "Zip built: $ZIP_FILE"
echo ""
echo "Contents:"
unzip -l "$ZIP_FILE" | head -25
echo ""

# Show version and verify all version references are in sync
VERSION=$(grep "^ \* Version:" "$REPO_DIR/cs-code-block.php" | head -1 | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
STABLE_TAG=$(grep "^Stable tag:" "$REPO_DIR/readme.txt" | head -1 | sed 's/Stable tag:[[:space:]]*//' | tr -d '[:space:]')
CLASS_VERSION=$(grep -oE "const VERSION\s*=\s*'[^']+'" "$REPO_DIR/cs-code-block.php" | grep -oE "'[^']+'" | tr -d "'" | head -1)
echo "Plugin version: $VERSION"
echo "Stable tag:     $STABLE_TAG"
echo "const VERSION:  $CLASS_VERSION"
BUILD_ERR=0
if [ "$VERSION" != "$STABLE_TAG" ]; then
  echo ""
  echo "ERROR: Version mismatch! Plugin header ($VERSION) != Stable tag ($STABLE_TAG)"
  BUILD_ERR=1
fi
if [ "$CLASS_VERSION" != "$VERSION" ]; then
  echo ""
  echo "ERROR: const VERSION ('$CLASS_VERSION') does not match plugin header ($VERSION)"
  BUILD_ERR=1
fi
if [ "$BUILD_ERR" != "0" ]; then
  exit 1
fi
echo "Version check: OK"
echo ""
echo "To deploy to S3, run:"
  echo "  bash $SCRIPT_DIR/backup-s3.sh"
echo ""
echo "Then on the server:"
echo "  WP=/mnt/nvme/docker/volumes/andrewbakerninja-pi_wp_data/_data && sudo aws s3 cp s3://your-s3-bucket/cloudscale-devtools.zip /tmp/plugin.zip && sudo rm -rf \${WP}/wp-content/plugins/cloudscale-devtools && sudo unzip -q /tmp/plugin.zip -d \${WP}/wp-content/plugins/ && sudo docker exec pi_wordpress php -r \"if(function_exists('opcache_reset'))opcache_reset();\" && echo 'Deploy OK'"
