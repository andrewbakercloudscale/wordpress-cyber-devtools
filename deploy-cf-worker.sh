#!/usr/bin/env bash
# deploy-cf-worker.sh — Deploy CloudScale Uptime Worker to Cloudflare
# Reads credentials from ~/Desktop/github/.creds and WP token from the Pi server.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SEO_TESTS_DIR="/Users/cp363412/Desktop/github/wordpress-seo-ai-optimizer/tests"
CONTAINER="pi_wordpress"
WP_PATH="/var/www/html"
WP_CLI="php ${WP_PATH}/wp-cli.phar --allow-root"
CTRL_SOCK="/tmp/cf-worker-$$"

# Load credentials
CF_CREDS="${HOME}/Desktop/github/.creds"
[[ -f "$CF_CREDS" ]] || { echo "ERROR: ${CF_CREDS} not found."; exit 1; }
source "$CF_CREDS"

# Load WP base URL
[[ -f "$SEO_TESTS_DIR/.env" ]] || { echo "ERROR: SEO .env not found."; exit 1; }
WP_BASE_URL=$(grep '^WP_BASE_URL=' "$SEO_TESTS_DIR/.env" | cut -d'=' -f2- | tr -d '\r')

# SSH helper
if nc -z -w2 andrew-pi-5.local 22 2>/dev/null; then
    PI_HOST="pi@andrew-pi-5.local"
    pi_ssh() { ssh -i "${PI_KEY}" -o StrictHostKeyChecking=no -o LogLevel=ERROR -o ControlMaster=auto -o ControlPath="${CTRL_SOCK}" -o ControlPersist=yes "${PI_HOST}" "$@"; }
    echo "Network: home — direct SSH"
else
    PI_HOST="pi@ssh.andrewbaker.ninja"
    pi_ssh() { ssh -i "${HOME}/.cloudflared/pi-service-key" -o "ProxyCommand=${HOME}/.cloudflared/cf-ssh-proxy.sh" -o StrictHostKeyChecking=no -o LogLevel=ERROR -o ControlMaster=auto -o ControlPath="${CTRL_SOCK}" -o ControlPersist=yes "${PI_HOST}" "$@"; }
    echo "Network: remote — Cloudflare tunnel"
fi

run_wp_php() { pi_ssh "docker exec -i ${CONTAINER} ${WP_CLI} eval-file - --path=${WP_PATH}"; }
close_ssh()  { ssh -i "${PI_KEY}" -o ControlPath="${CTRL_SOCK}" -o LogLevel=ERROR -O exit "${PI_HOST}" 2>/dev/null || true; }
trap close_ssh EXIT

echo "--- Connecting to Pi..."
pi_ssh "echo 'OK'"

# Read WP options: token, ntfy URL, stored KV namespace ID
echo "--- Reading WordPress options..."
WP_CONFIG=$(printf '<?php
$token = get_option("csdt_uptime_token", "");
$ntfy  = get_option("csdt_uptime_ntfy_url", get_option("csdt_scan_schedule_ntfy_url", ""));
$kv_id = get_option("csdt_uptime_kv_id", "");
if ($token === "") {
    $token = bin2hex(random_bytes(24));
    update_option("csdt_uptime_token", $token, false);
    echo "NEW_TOKEN\n";
}
echo json_encode(compact("token", "ntfy", "kv_id"));
' | run_wp_php)

# Extract last JSON line
JSON_LINE=$(echo "$WP_CONFIG" | grep '^{' | tail -1)
if [[ -z "$JSON_LINE" ]]; then
    echo "ERROR: Could not read WP options."
    echo "Raw output: $WP_CONFIG"
    exit 1
fi

PING_TOKEN=$(echo "$JSON_LINE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['token'])")
NTFY_URL=$(echo "$JSON_LINE"   | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['ntfy'])")
KV_ID=$(echo "$JSON_LINE"      | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('kv_id',''))")
SITE_URL="${WP_BASE_URL}"

echo "  Site URL  : ${SITE_URL}"
echo "  Ntfy URL  : ${NTFY_URL:-<none>}"
echo "  Token     : ${PING_TOKEN:0:8}…"
echo "  KV ID     : ${KV_ID:-<not yet created>}"

# Resolve CF account ID from zone
echo ""
echo "--- Resolving Cloudflare account ID from zone ${CF_ZONE}..."
ZONE_RESP=$(curl -s \
    -H "X-Auth-Email: ${CF_EMAIL}" \
    -H "X-Auth-Key: ${CF_KEY}" \
    "https://api.cloudflare.com/client/v4/zones/${CF_ZONE}")
CF_ACCOUNT_ID=$(echo "$ZONE_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['result']['account']['id'])" 2>/dev/null || echo "")
if [[ -z "$CF_ACCOUNT_ID" ]]; then
    echo "ERROR: Could not resolve account ID."
    echo "$ZONE_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('errors',''))" 2>/dev/null
    exit 1
fi
echo "  Account ID: ${CF_ACCOUNT_ID}"

# Create or find the KV namespace used for watchdog state
echo ""
echo "--- Setting up KV namespace (csdt-uptime-state)..."
if [[ -n "$KV_ID" ]]; then
    echo "  Using stored KV namespace: ${KV_ID:0:8}..."
else
    KV_RESP=$(curl -s -X POST \
        "https://api.cloudflare.com/client/v4/accounts/${CF_ACCOUNT_ID}/storage/kv/namespaces" \
        -H "X-Auth-Email: ${CF_EMAIL}" \
        -H "X-Auth-Key: ${CF_KEY}" \
        -H "Content-Type: application/json" \
        -d '{"title": "csdt-uptime-state"}')
    KV_ID=$(echo "$KV_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['result']['id'])" 2>/dev/null || echo "")
    if [[ -z "$KV_ID" ]]; then
        # Namespace title may already exist — find it
        KV_LIST=$(curl -s \
            "https://api.cloudflare.com/client/v4/accounts/${CF_ACCOUNT_ID}/storage/kv/namespaces?per_page=100" \
            -H "X-Auth-Email: ${CF_EMAIL}" \
            -H "X-Auth-Key: ${CF_KEY}")
        KV_ID=$(echo "$KV_LIST" | python3 -c "
import sys,json
for ns in json.load(sys.stdin).get('result',[]):
    if ns.get('title') == 'csdt-uptime-state':
        print(ns['id']); break
" 2>/dev/null || echo "")
    fi
    if [[ -z "$KV_ID" ]]; then
        echo "ERROR: Could not create or find KV namespace 'csdt-uptime-state'."
        exit 1
    fi
    echo "  KV namespace created: ${KV_ID:0:8}..."
    printf '<?php update_option("csdt_uptime_kv_id", "%s", false);' "$KV_ID" | run_wp_php 2>/dev/null || true
fi

# Build multipart payload to deploy worker
WORKER_JS="${PLUGIN_DIR}/worker.js"
[[ -f "$WORKER_JS" ]] || { echo "ERROR: worker.js not found at ${WORKER_JS}"; exit 1; }

BOUNDARY="CSDTBnd$(openssl rand -hex 6)"
METADATA=$(python3 -c "
import json
print(json.dumps({
    'main_module': 'worker.js',
    'compatibility_date': '2024-11-01',
    'bindings': [
        {'type': 'plain_text',   'name': 'SITE_URL',   'text': '${SITE_URL}'},
        {'type': 'plain_text',   'name': 'PING_TOKEN', 'text': '${PING_TOKEN}'},
        {'type': 'plain_text',   'name': 'NTFY_URL',   'text': '${NTFY_URL}'},
        {'type': 'kv_namespace', 'name': 'STATE',      'namespace_id': '${KV_ID}'},
    ]
}))
")

echo ""
echo "--- Deploying Worker (cloudscale-uptime) to Cloudflare account ${CF_ACCOUNT_ID}..."
DEPLOY_RESP=$(curl -s -X PUT \
    "https://api.cloudflare.com/client/v4/accounts/${CF_ACCOUNT_ID}/workers/scripts/cloudscale-uptime" \
    -H "X-Auth-Email: ${CF_EMAIL}" \
    -H "X-Auth-Key: ${CF_KEY}" \
    -F "metadata=${METADATA};type=application/json" \
    -F "worker.js=@${WORKER_JS};type=application/javascript+module")

SUCCESS=$(echo "$DEPLOY_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('success',''))" 2>/dev/null)
if [[ "$SUCCESS" != "True" ]]; then
    echo "ERROR: Worker deploy failed."
    echo "$DEPLOY_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('errors',''))" 2>/dev/null
    exit 1
fi
echo "  Worker deployed successfully."

# Set cron trigger (* * * * *)
echo ""
echo "--- Setting cron trigger (* * * * *)..."
CRON_RESP=$(curl -s -X PUT \
    "https://api.cloudflare.com/client/v4/accounts/${CF_ACCOUNT_ID}/workers/scripts/cloudscale-uptime/schedules" \
    -H "X-Auth-Email: ${CF_EMAIL}" \
    -H "X-Auth-Key: ${CF_KEY}" \
    -H "Content-Type: application/json" \
    -d '[{"cron": "* * * * *"}]')

CRON_OK=$(echo "$CRON_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('success',''))" 2>/dev/null)
if [[ "$CRON_OK" == "True" ]]; then
    echo "  Cron trigger set: * * * * *"
else
    echo "  WARNING: Cron trigger may not have been set — check CF dashboard."
    echo "$CRON_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('errors',''))" 2>/dev/null
fi

# Enable workers.dev subdomain and store worker URL in WP
echo ""
echo "--- Enabling workers.dev subdomain..."
curl -s -X POST \
    "https://api.cloudflare.com/client/v4/accounts/${CF_ACCOUNT_ID}/workers/scripts/cloudscale-uptime/subdomain" \
    -H "X-Auth-Email: ${CF_EMAIL}" \
    -H "X-Auth-Key: ${CF_KEY}" \
    -H "Content-Type: application/json" \
    -d '{"enabled": true}' > /dev/null

SUB_RESP=$(curl -s \
    "https://api.cloudflare.com/client/v4/accounts/${CF_ACCOUNT_ID}/workers/subdomain" \
    -H "X-Auth-Email: ${CF_EMAIL}" \
    -H "X-Auth-Key: ${CF_KEY}")
SUBDOMAIN=$(echo "$SUB_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['result']['subdomain'])" 2>/dev/null || echo "")
if [[ -n "$SUBDOMAIN" ]]; then
    WORKER_URL="https://cloudscale-uptime.${SUBDOMAIN}.workers.dev"
    echo "  Worker URL: ${WORKER_URL}"
    printf '<?php update_option("csdt_uptime_worker_url", "%s", false); update_option("csdt_uptime_enabled", "1", false);' "$WORKER_URL" | run_wp_php 2>/dev/null || true
fi

# Install host cron on Pi — triggers wp-cron.php via localhost every minute.
# Must bypass Cloudflare: the public URL (https://site/wp-cron.php) is cached by CF
# and returns a CF cache-HIT 200 without WordPress ever executing. Hitting localhost
# directly ensures PHP-FPM processes the request and push_heartbeat() runs.
# FPM health check is preserved: if FPM is down, nginx returns 502, curl exits
# non-zero, no heartbeat is pushed, and the CF Worker correctly alerts.
echo ""
echo "--- Detecting nginx port on Pi..."
NGINX_PORT=$(pi_ssh "docker port pi_nginx 80/tcp 2>/dev/null | head -1 | cut -d: -f2" || echo "")
if [[ -z "$NGINX_PORT" ]]; then
    echo "  WARNING: Could not detect nginx port — defaulting to 8082"
    NGINX_PORT="8082"
fi
echo "  Nginx port: ${NGINX_PORT}"

echo ""
echo "--- Installing host cron for WP-Cron heartbeat via localhost (every minute)..."
WP_HOST="${SITE_URL#https://}"
WP_HOST="${WP_HOST#http://}"
CRON_LINE="* * * * * curl -sf -m 10 -H 'Host: ${WP_HOST}' 'http://127.0.0.1:${NGINX_PORT}/wp-cron.php?doing_wp_cron' -o /dev/null 2>/dev/null"
{ pi_ssh "crontab -l 2>/dev/null | grep -v 'wp-cron.php'"; echo "$CRON_LINE"; } | pi_ssh "crontab -"
echo "  Cron installed: ${CRON_LINE}"

echo ""
echo "════════════════════════════════════════════════════════"
echo " CloudScale Uptime Worker deployed (heartbeat watchdog)."
echo " WordPress pushes heartbeat → ${WORKER_URL:-workers.dev URL}"
echo " KV state:  ${KV_ID:0:8}... (csdt-uptime-state)"
echo " Alerting:  ${NTFY_URL:-<none>}"
echo " Host cron: curl localhost:${NGINX_PORT} (bypasses CF cache) every minute"
echo "   502 = FPM down = no heartbeat = Worker alerts after 3 min"
echo ""
echo " The Worker fires ntfy if no heartbeat for >3 minutes."
echo "════════════════════════════════════════════════════════"
