#!/usr/bin/env bash
# test-fix-new-post.sh — Restore the Gutenberg block editor after test-break-new-post.sh.
#
# Diagnosis: The nginx CSP on the Pi was missing 'self' in frame-src.
# Gutenberg renders the block editor in a same-origin iframe — without frame-src 'self'
# the browser blocks it and shows "This content is blocked."
#
# What this script does:
#   1. Syncs the fixed nginx/default.conf from this local repo to the Pi nginx bind mount
#   2. Reloads nginx inside the pi_nginx Docker container
#   3. Purges the Cloudflare cache so the fixed headers are served immediately

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
NGINX_SRC="${REPO_ROOT}/andrewbakerninja/nginx/default.conf"
PI_KEY="${HOME}/Desktop/github/pi-monitor/deploy/pi_key"
PI_USER="pi"
PI_HOST="andrew-pi-5.local"
NGINX_DEST="/home/pi/andrewbakerninja-pi/nginx/wordpress.conf"

pi_ssh() { ssh -i "$PI_KEY" -o StrictHostKeyChecking=no "${PI_USER}@${PI_HOST}" "$@"; }

echo "=== Deploying fixed nginx CSP (frame-src 'self' restored) ==="
scp -i "$PI_KEY" -o StrictHostKeyChecking=no "${NGINX_SRC}" "${PI_USER}@${PI_HOST}:${NGINX_DEST}"

echo "=== Reloading nginx ==="
pi_ssh "docker exec pi_nginx nginx -t && docker exec pi_nginx nginx -s reload"

echo ""
echo "=== Verifying — frame-src should contain 'self' ==="
pi_ssh "grep 'frame-src' ${NGINX_DEST}" | head -1

echo ""
echo "=== Purging Cloudflare cache ==="
CF_CREDS="${HOME}/Desktop/github/.cf-credentials"
if [[ -f "$CF_CREDS" ]]; then
    source "$CF_CREDS"
    curl -s -X POST "https://api.cloudflare.com/client/v4/zones/${CF_ZONE_ID}/purge_cache" \
        -H "X-Auth-Email: ${CF_EMAIL}" \
        -H "X-Auth-Key: ${CF_KEY}" \
        -H "Content-Type: application/json" \
        --data '{"purge_everything":true}' | grep -o '"success":[^,]*'
else
    echo "Skipped (${CF_CREDS} not found)"
fi

echo ""
echo "=== Done — editor should be restored ==="
echo "Open https://andrewbaker.ninja/cleanshirt/post-new.php to confirm."
