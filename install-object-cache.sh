#!/usr/bin/env bash
# install-object-cache.sh — Install APCu object cache drop-in into WordPress
#
# Copies lib/object-cache.php → wp-content/object-cache.php inside the
# pi_wordpress Docker container, and installs php-apcu if not present.
#
# Usage:
#   bash wordpress-cyber-devtools/install-object-cache.sh
#   bash wordpress-cyber-devtools/install-object-cache.sh --check   # status only

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

source "${REPO_ROOT}/.claude-config.sh" 2>/dev/null || true

PI_KEY="${REPO_ROOT}/pi-monitor/deploy/pi_key"
CF_KEY="${HOME}/.cloudflared/pi-service-key"
CF_PROXY="${REPO_ROOT}/cf-ssh-proxy.sh"
CONTAINER="pi_wordpress"
WP_CONTENT="/var/www/html/wp-content"
SRC_FILE="${SCRIPT_DIR}/lib/object-cache.php"

# ── Detect network ────────────────────────────────────────────────────────────
SSH_CMD=""
if ssh -i "${PI_KEY}" -o ConnectTimeout=4 -o StrictHostKeyChecking=no \
       andrew@andrew-pi-5.local exit 2>/dev/null; then
    SSH_CMD="ssh -i ${PI_KEY} -o StrictHostKeyChecking=no andrew@andrew-pi-5.local"
    echo "[network] LAN (direct)"
elif [[ -f "${CF_KEY}" ]]; then
    SSH_CMD="ssh -i ${CF_KEY} -o ProxyCommand=${CF_PROXY} %h %p -o StrictHostKeyChecking=no andrew@ssh.andrewbaker.ninja"
    echo "[network] Remote (Cloudflare tunnel)"
else
    echo "[error] No reachable SSH path found." >&2
    exit 1
fi

DOCKER="docker exec ${CONTAINER}"

# ── Status check ─────────────────────────────────────────────────────────────
apcu_loaded=$($SSH_CMD "${DOCKER} php -r \"echo extension_loaded('apcu') ? 'yes' : 'no';\"" 2>/dev/null || echo "no")
drop_in=$($SSH_CMD "${DOCKER} test -f ${WP_CONTENT}/object-cache.php && echo 'yes' || echo 'no'" 2>/dev/null || echo "no")

echo "[status] APCu extension: ${apcu_loaded}"
echo "[status] object-cache.php installed: ${drop_in}"

if [[ "${1:-}" == "--check" ]]; then
    exit 0
fi

# ── Install APCu if missing ───────────────────────────────────────────────────
if [[ "${apcu_loaded}" != "yes" ]]; then
    echo "[action] Installing php-apcu in container..."
    $SSH_CMD "${DOCKER} apt-get install -y php-apcu" 2>/dev/null \
        || $SSH_CMD "${DOCKER} sh -c 'apk add --no-cache php-apcu'" 2>/dev/null \
        || { echo "[error] Could not install php-apcu — install manually and re-run." >&2; exit 1; }
    echo "[action] Restarting container to load APCu..."
    $SSH_CMD "docker restart ${CONTAINER}"
    sleep 6
    apcu_loaded=$($SSH_CMD "${DOCKER} php -r \"echo extension_loaded('apcu') ? 'yes' : 'no';\"" 2>/dev/null || echo "no")
    echo "[status] APCu after restart: ${apcu_loaded}"
fi

# ── Copy drop-in ─────────────────────────────────────────────────────────────
echo "[action] Copying object-cache.php to ${WP_CONTENT}/..."
# Pipe the file through SSH to avoid needing scp/rsync
$SSH_CMD "cat > ${WP_CONTENT}/object-cache.php" < "${SRC_FILE}"
echo "[action] Setting permissions..."
$SSH_CMD "${DOCKER} chown www-data:www-data ${WP_CONTENT}/object-cache.php"

# ── Verify ────────────────────────────────────────────────────────────────────
result=$($SSH_CMD "${DOCKER} php -r \"
define('ABSPATH', '/var/www/html/');
require '${WP_CONTENT}/object-cache.php';
wp_cache_init();
wp_cache_set('test', 'ok', 'default', 60);
echo wp_cache_get('test', 'default') === 'ok' ? 'PASS' : 'FAIL';
\"" 2>/dev/null || echo "FAIL")

echo "[verify] object-cache.php self-test: ${result}"

if [[ "${result}" == "PASS" ]]; then
    echo ""
    echo "[done] APCu object cache installed."
    echo "       The CS Monitor N+1 warnings for WP core patterns will clear on the next page load."
else
    echo "[warn] Self-test failed — check APCu status or PHP errors."
fi
