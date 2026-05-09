// CloudScale Uptime Monitor — heartbeat watchdog
// WordPress pushes a POST heartbeat every 3 minutes via WP-Cron.
// If no heartbeat arrives for >8 minutes, the site is treated as down.
//
// Required environment bindings:
//   SITE_URL   — WordPress site URL (for alert messages)
//   PING_TOKEN — shared secret (WordPress uses this to auth heartbeat pushes)
//   NTFY_URL   — ntfy.sh topic URL for down/recovery alerts
//   STATE      — KV namespace (stores last heartbeat ts + alert state)
//
// Cron trigger: * * * * *  (every minute — checks for stale heartbeat)
// HTTP POST / with Authorization: Bearer <PING_TOKEN>:
//   action=csdt_heartbeat — record a heartbeat from WordPress (WP-Cron calls this)
//   (no action)           — manual test, returns current watchdog state

const STALE_MS   = 15 * 60 * 1000; // 15 min without heartbeat = site down (heartbeat every 10 min)
const ALERT_COOL = 30 * 60 * 1000; // cooldown between repeat down-alerts

async function watchdog(env, ctx) {
  const now = Date.now();
  const [hbStr, dsStr, laStr] = await Promise.all([
    env.STATE.get('hb'),
    env.STATE.get('ds'),
    env.STATE.get('la'),
  ]);
  const lastHb    = hbStr ? parseInt(hbStr, 10) : 0;
  const downSince = dsStr ? parseInt(dsStr, 10) : 0;
  const lastAlert = laStr ? parseInt(laStr, 10) : 0;
  const stale     = !lastHb || (now - lastHb) > STALE_MS;

  if (stale) {
    const since = downSince || now;
    const ops = [];
    if (!downSince) ops.push(env.STATE.put('ds', String(now)));
    if (now - lastAlert > ALERT_COOL) {
      ops.push(notify(env, false, Math.round((now - since) / 1000)));
      ops.push(env.STATE.put('la', String(now)));
    }
    ctx.waitUntil(Promise.all(ops));
  } else if (downSince) {
    const downSecs = Math.round((now - downSince) / 1000);
    ctx.waitUntil(Promise.all([
      notify(env, true, downSecs),
      env.STATE.delete('ds'),
      env.STATE.put('la', String(now)),
    ]));
  }

  return { stale, lastHb, downSince };
}

async function notify(env, recovered, downSecs) {
  if (!env.NTFY_URL) return;
  const dur = downSecs > 0 ? fmtSecs(downSecs) : null;
  return fetch(env.NTFY_URL, {
    method: 'POST',
    headers: {
      Title:    (recovered ? 'CF: Recovered — ' : 'CF: Site Down — ') + env.SITE_URL,
      Priority: recovered ? 'default' : 'urgent',
      Tags:     recovered ? 'white_check_mark' : 'rotating_light',
    },
    body: recovered
      ? 'Back online' + (dur ? ' — was down ' + dur : '')
      : 'No heartbeat received for ' + (dur || '8m+'),
  }).catch(() => {});
}

function fmtSecs(s) {
  const m = Math.floor(s / 60);
  return m > 0 ? m + 'm ' + (s % 60) + 's' : s + 's';
}

export default {
  async scheduled(event, env, ctx) {
    ctx.waitUntil(watchdog(env, ctx));
  },

  async fetch(request, env, ctx) {
    if (request.method !== 'POST') return new Response('Method Not Allowed', { status: 405 });
    const auth = request.headers.get('Authorization') || '';
    if (auth !== 'Bearer ' + env.PING_TOKEN) return new Response('Unauthorized', { status: 401 });

    const text   = await request.text();
    const params = new URLSearchParams(text);

    if (params.get('action') === 'csdt_heartbeat') {
      await env.STATE.put('hb', String(Date.now()));
      return new Response(JSON.stringify({ ok: true }), { headers: { 'Content-Type': 'application/json' } });
    }

    // Manual test — run watchdog and return current state
    const state = await watchdog(env, ctx);
    return new Response(JSON.stringify({ ok: !state.stale, ...state, triggered: true }), {
      headers: { 'Content-Type': 'application/json' },
    });
  },
};
