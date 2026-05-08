#!/usr/bin/env bash
# test-break-new-post.sh — Reproduce the "This content is blocked" Gutenberg editor bug.
#
# Root cause (2026-05-07):
#   The nginx CSP in /home/pi/andrewbakerninja-pi/nginx/wordpress.conf had 'self' removed
#   from frame-src. Gutenberg 6.x+ renders the block editor in a same-origin iframe —
#   without frame-src 'self', the browser blocks it and shows "This content is blocked."
#
# What this script does:
#   Replaces 'self' with nothing in frame-src of the Pi nginx CSP, then reloads nginx.
#
# Run test-fix-new-post.sh to restore.

set -euo pipefail

PI_KEY="${HOME}/Desktop/github/pi-monitor/deploy/pi_key"
PI_USER="pi"
PI_HOST="andrew-pi-5.local"
NGINX_CONF="/home/pi/andrewbakerninja-pi/nginx/wordpress.conf"
BACKUP_PATH="/tmp/wordpress_nginx_backup_$(date +%s).conf"

pi_ssh() { ssh -i "$PI_KEY" -o StrictHostKeyChecking=no "${PI_USER}@${PI_HOST}" "$@"; }

echo "=== Backing up nginx config to ${BACKUP_PATH} ==="
pi_ssh "cp ${NGINX_CONF} ${BACKUP_PATH}"

echo "=== BREAKING: removing 'self' from frame-src in nginx CSP ==="
pi_ssh "sed -i \"s/frame-src 'self' https/frame-src https/g\" ${NGINX_CONF}"

echo "=== Reloading nginx ==="
pi_ssh "docker exec pi_nginx nginx -t && docker exec pi_nginx nginx -s reload"

echo ""
echo "=== Verifying — frame-src should NOT contain 'self' ==="
pi_ssh "grep 'frame-src' ${NGINX_CONF}" | head -1

echo ""
echo "=== Done — site is now broken ==="
echo "Open https://andrewbaker.ninja/cleanshirt/post-new.php and you should see:"
echo "  \"This content is blocked. Contact the site owner to fix the issue.\""
echo ""
echo "Backup saved at: ${BACKUP_PATH} (on the Pi)"
echo "Run test-fix-new-post.sh to restore."
