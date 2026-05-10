/* global csdtOptimizer */
'use strict';

(function () {
    var ajaxUrl = csdtOptimizer.ajaxUrl;
    var nonce   = csdtOptimizer.nonce;
    var baseUrl = csdtOptimizer.baseUrl;

    function post(action, params) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', nonce);
        if (params) {
            Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
        }
        return fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Simple markdown-ish formatter for AI output
    function formatAiText(text) {
        // Escape HTML first
        var escaped = escHtml(text);
        // Code spans
        escaped = escaped.replace(/`([^`\n]+)`/g,
            '<code style="background:#f1f5f9;color:#1e293b;padding:1px 6px;border-radius:3px;font-family:\'SF Mono\',Consolas,monospace;font-size:.88em;">$1</code>');
        // Bold **text**
        escaped = escaped.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
        // Numbered steps: "1. ", "2. " etc at line start
        escaped = escaped.replace(/(^|\n)(\d+)\.\s+/g, '$1<br><strong style="color:#6366f1;">$2.</strong> ');
        // Paragraph breaks
        escaped = escaped.replace(/\n\n/g, '</p><p style="margin:0 0 10px;line-height:1.75;">');
        // Single line breaks
        escaped = escaped.replace(/\n/g, '<br>');
        return escaped;
    }

    var TAB_LABELS = {
        'security':   'Security Scan',
        'login':      'Login Security',
        'mail':       'Mail / SMTP',
        'migrate':    'Code Migrator',
        'sql':        'SQL Command',
        'logs':       'Server Logs',
        'thumbnails': 'Thumbnails',
    };

    // ── Plugin Stack Scanner ─────────────────────────────────────────────────

    var scanBtn     = document.getElementById('csdt-optimizer-scan-btn');
    var scanningMsg = document.getElementById('csdt-optimizer-scanning');
    var resultsDiv  = document.getElementById('csdt-optimizer-results');

    if (scanBtn) {
        scanBtn.addEventListener('click', function () {
            scanBtn.disabled = true;
            scanBtn.textContent = '⏳ Scanning…';
            if (scanningMsg) scanningMsg.style.display = '';
            if (resultsDiv)  resultsDiv.style.display  = 'none';

            post('csdt_plugin_stack_scan').then(function (res) {
                scanBtn.disabled  = false;
                scanBtn.innerHTML = '🔍 Scan My Plugin Stack';
                if (scanningMsg) scanningMsg.style.display = 'none';

                if (!res.success) {
                    resultsDiv.innerHTML = '<p style="color:#dc2626;font-size:.9em;">Scan failed — please reload and try again.</p>';
                    resultsDiv.style.display = '';
                    return;
                }
                renderScanResults(res.data);
            }).catch(function () {
                scanBtn.disabled  = false;
                scanBtn.innerHTML = '🔍 Scan My Plugin Stack';
                if (scanningMsg) scanningMsg.style.display = 'none';
                if (resultsDiv) {
                    resultsDiv.innerHTML = '<p style="color:#dc2626;font-size:.9em;">Request failed — please reload and try again.</p>';
                    resultsDiv.style.display = '';
                }
            });
        });
    }

    function renderScanResults(data) {
        var matched  = data.matched  || [];
        var saving   = data.total_saving || 0;
        var html     = '';

        var activePlugins   = matched.filter(function (p) { return p.active !== false; });
        var inactivePlugins = matched.filter(function (p) { return p.active === false; });

        if (activePlugins.length === 0 && inactivePlugins.length === 0) {
            html =
                '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:20px 24px;display:flex;gap:14px;align-items:flex-start;">' +
                '<span style="font-size:1.8em;line-height:1;">✅</span>' +
                '<div>' +
                '<p style="margin:0 0 6px;font-weight:700;color:#166534;font-size:1em;">Your plugin stack is already clean.</p>' +
                '<p style="margin:0;color:#374151;font-size:.92em;line-height:1.6;">None of your ' + (data.active_count || 'active') + ' active plugins overlap with CloudScale features. You\'re running a lean stack.</p>' +
                '</div></div>';
        } else {
            if (activePlugins.length > 0) {
                var savingHtml = saving > 0
                    ? ' Removing them could save you <strong>$' + saving + '/year</strong> in premium license fees.'
                    : '';
                html +=
                    '<div style="background:linear-gradient(135deg,#fff7ed,#fefce8);border:1px solid #fed7aa;border-radius:8px;padding:16px 20px;margin-bottom:20px;display:flex;gap:14px;align-items:flex-start;">' +
                    '<span style="font-size:1.8em;line-height:1;">🎯</span>' +
                    '<div>' +
                    '<p style="margin:0 0 5px;font-weight:700;color:#0f172a;font-size:1.05em;">' +
                    activePlugins.length + ' active plugin' + (activePlugins.length !== 1 ? 's' : '') + ' found that CloudScale already replaces.' +
                    '</p>' +
                    '<p style="margin:0;color:#64748b;font-size:.9em;line-height:1.6;">' + savingHtml + ' Safe to deactivate once you\'ve confirmed the CloudScale equivalent is working.</p>' +
                    '</div></div>';

                html += renderPluginTable(activePlugins);

                html +=
                    '<div style="background:#f0f9ff;border-left:3px solid #0ea5e9;padding:11px 16px;border-radius:0 6px 6px 0;font-size:.87em;color:#0c4a6e;line-height:1.6;margin-bottom:' + (inactivePlugins.length > 0 ? '24' : '0') + 'px;">' +
                    '<strong>Before deactivating:</strong> set up and test the CloudScale equivalent first. Take a backup — the free ' +
                    '<a href="https://andrewbaker.ninja/wordpress-plugin-help/cloudscale-backup-restore-help/" target="_blank" rel="noopener" style="color:#0369a1;">CloudScale Backup plugin</a>' +
                    ' does a one-click full-site snapshot.' +
                    '</div>';
            }

            if (inactivePlugins.length > 0) {
                html +=
                    '<div style="background:#fafafa;border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;margin-bottom:16px;">' +
                    '<p style="margin:0 0 12px;font-weight:700;color:#374151;font-size:.95em;">🗑️ Also installed but inactive — safe to delete</p>' +
                    '<p style="margin:0 0 14px;color:#6b7280;font-size:.87em;line-height:1.5;">These plugins are deactivated and covered by CloudScale. You can delete them to reduce attack surface and keep your dashboard tidy.</p>';
                html += renderPluginTable(inactivePlugins, true);
                html += '</div>';
            }
        }

        if (resultsDiv) {
            resultsDiv.innerHTML = html;
            resultsDiv.style.display = '';
        }
    }

    function renderPluginTable(plugins, muted) {
        var html =
            '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:16px;">' +
            '<table style="width:100%;border-collapse:collapse;font-size:.88em;">' +
            '<thead>' +
            '<tr style="background:#f8fafc;">' +
            '<th style="padding:10px 14px;text-align:left;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Plugin</th>' +
            '<th style="padding:10px 14px;text-align:left;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">CloudScale replaces it with</th>' +
            ( !muted ? '<th style="padding:10px 14px;text-align:right;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;white-space:nowrap;">Saved/yr</th>' : '' ) +
            '<th style="padding:10px 14px;text-align:center;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Go to</th>' +
            '</tr>' +
            '</thead><tbody>';

        plugins.forEach(function (p, i) {
            var bg      = i % 2 === 0 ? '#fff' : '#f8fafc';
            var tabUrl  = baseUrl + '&tab=' + encodeURIComponent(p.tab || 'home');
            var tabName = TAB_LABELS[p.tab] || p.tab;
            var vStr    = p.version ? ' <span style="font-weight:400;color:#9ca3af;font-size:.82em;">v' + escHtml(p.version) + '</span>' : '';
            html +=
                '<tr style="background:' + bg + ';border-bottom:1px solid #f1f5f9;">' +
                '<td style="padding:10px 14px;font-weight:600;color:' + (muted ? '#6b7280' : '#0f172a') + ';">' + escHtml(p.name) + vStr + '</td>' +
                '<td style="padding:10px 14px;color:#374151;line-height:1.5;">' + escHtml(p.feature) + '</td>';
            if (!muted) {
                var costStr = p.cost > 0
                    ? '<span style="color:#dc2626;font-weight:600;">$' + p.cost + '</span>'
                    : '<span style="color:#6b7280;">—</span>';
                html += '<td style="padding:10px 14px;text-align:right;">' + costStr + '</td>';
            }
            html +=
                '<td style="padding:10px 14px;text-align:center;">' +
                '<a href="' + tabUrl + '" style="color:#6366f1;font-weight:600;font-size:.85em;text-decoration:none;white-space:nowrap;">→ ' + escHtml(tabName) + '</a>' +
                '</td>' +
                '</tr>';
        });

        html += '</tbody></table></div>';
        return html;
    }

    // ── Uptime Monitor ───────────────────────────────────────────────────────

    var _uptimePauseCountdown = null;

    function uptimeEl(id) { return document.getElementById(id); }

    function startPauseCountdown(pauseUntil) {
        if (_uptimePauseCountdown) { clearInterval(_uptimePauseCountdown); }
        var pBtn = uptimeEl('csdt-uptime-pause-btn');
        var cBtn = uptimeEl('csdt-uptime-cancel-pause-btn');
        var pSt  = uptimeEl('csdt-uptime-push-status');
        if (pBtn) pBtn.style.display = 'none';
        if (cBtn) cBtn.style.display = '';

        function tick() {
            var remaining = Math.max(0, Math.round((pauseUntil * 1000 - Date.now()) / 1000));
            var pS = uptimeEl('csdt-uptime-push-status');
            if (pS) {
                var m = Math.floor(remaining / 60);
                var s = remaining % 60;
                pS.style.display = '';
                pS.style.color   = '#d97706';
                pS.textContent   = remaining > 0
                    ? '⏸ Paused — CF Worker will alert in ~' + (m > 0 ? m + 'm ' : '') + s + 's'
                    : '▶ Resumed';
            }
            if (remaining <= 0) {
                clearInterval(_uptimePauseCountdown);
                _uptimePauseCountdown = null;
                var pb = uptimeEl('csdt-uptime-pause-btn');
                var cb = uptimeEl('csdt-uptime-cancel-pause-btn');
                if (pb) pb.style.display = '';
                if (cb) cb.style.display = 'none';
                setTimeout(function () {
                    var ps = uptimeEl('csdt-uptime-push-status');
                    if (ps) ps.style.display = 'none';
                }, 3000);
                loadUptimeHistory();
            }
        }
        tick();
        _uptimePauseCountdown = setInterval(tick, 1000);
    }

    function clearPauseState() {
        if (_uptimePauseCountdown) { clearInterval(_uptimePauseCountdown); _uptimePauseCountdown = null; }
        var pb = uptimeEl('csdt-uptime-pause-btn');
        var cb = uptimeEl('csdt-uptime-cancel-pause-btn');
        var ps = uptimeEl('csdt-uptime-push-status');
        if (pb) pb.style.display = '';
        if (cb) cb.style.display = 'none';
        if (ps) ps.style.display = 'none';
    }

    function renderManualDeploy(workerJs, wranglerToml) {
        var wrap = uptimeEl('csdt-uptime-manual-wrap');
        if (!wrap) return;
        wrap.innerHTML =
            '<p style="font-size:.85em;color:#374151;margin:0 0 10px;">1. Go to <a href="https://dash.cloudflare.com/?to=/:account/workers-and-pages/create" target="_blank" rel="noopener" style="color:#6366f1;">dash.cloudflare.com → Workers → Create</a>. Choose "Hello World" then replace the entire script with the code below.</p>' +
            '<p style="font-size:.85em;color:#374151;margin:0 0 6px;">2. Create a KV namespace named <code style="background:#f1f5f9;padding:1px 5px;border-radius:3px;">csdt-uptime-state</code> and bind it as <code style="background:#f1f5f9;padding:1px 5px;border-radius:3px;">STATE</code> in Settings → KV Namespace Bindings.</p>' +
            '<p style="font-size:.85em;color:#374151;margin:0 0 6px;">3. Add variables: <code style="background:#f1f5f9;padding:1px 5px;border-radius:3px;">SITE_URL</code>, <code style="background:#f1f5f9;padding:1px 5px;border-radius:3px;">PING_TOKEN</code>, <code style="background:#f1f5f9;padding:1px 5px;border-radius:3px;">NTFY_URL</code>.</p>' +
            '<p style="font-size:.85em;color:#374151;margin:0 0 6px;">4. Go to <strong>Triggers → Cron Triggers → Add Cron</strong> and enter <code style="background:#f1f5f9;padding:1px 5px;border-radius:3px;">* * * * *</code>.</p>' +
            '<p style="font-size:.85em;font-weight:700;color:#374151;margin:8px 0 4px;">worker.js</p>' +
            '<textarea readonly style="width:100%;height:160px;font-family:monospace;font-size:.78em;border:1px solid #e5e7eb;border-radius:6px;padding:10px;resize:vertical;background:#f8fafc;">' + escHtml(workerJs) + '</textarea>' +
            '<p style="font-size:.85em;font-weight:700;color:#374151;margin:10px 0 4px;">wrangler.toml (CLI users)</p>' +
            '<textarea readonly style="width:100%;height:120px;font-family:monospace;font-size:.78em;border:1px solid #e5e7eb;border-radius:6px;padding:10px;resize:vertical;background:#f8fafc;">' + escHtml(wranglerToml) + '</textarea>';
    }

    function loadUptimeHistory(push) {
        return post('csdt_uptime_history', push ? { push: 1 } : null).then(function (res) {
            var inner = uptimeEl('csdt-uptime-status-inner');
            var wrap  = uptimeEl('csdt-uptime-status-wrap');
            if (!res.success || !inner) return;
            renderUptimeStatus(res.data, inner);
            if (wrap) wrap.style.display = '';
            return res.data;
        }).catch(function () {});
    }

    function fmtAgo(ts) {
        if (!ts) return null;
        var secs = Math.floor(Date.now() / 1000) - ts;
        if (secs < 5)    return 'just now';
        if (secs < 60)   return secs + 's ago';
        if (secs < 3600) return Math.round(secs / 60) + 'm ago';
        return Math.round(secs / 3600) + 'h ago';
    }

    function renderUptimeStatus(d, statusInner) {
        if (!statusInner) return;
        var lp     = d.last_ping;
        var isUp   = lp && lp.up;
        var age    = lp ? lp.age_seconds : null;
        var ageStr = age != null ? (age < 60 ? age + 's ago' : Math.round(age / 60) + 'm ago') : '—';

        var statusColor = !lp ? '#6b7280' : (isUp ? '#16a34a' : '#dc2626');
        var statusLabel = !lp ? 'No pings yet' : (isUp ? '✅ UP' : '🔴 DOWN');
        var msLabel     = lp ? lp.ms + 'ms' : '';

        var staleness = (lp && age != null && age > 120)
            ? ' <span style="font-size:.8em;background:#fef2f2;color:#dc2626;padding:1px 6px;border-radius:3px;margin-left:4px;">⚠ stale</span>'
            : '';
        var heartbeat = lp
            ? '<p style="font-size:.78em;color:#6b7280;margin:10px 0 0;">Last heartbeat: <strong style="color:#374151;">' + escHtml(ageStr) + '</strong>' + staleness + '</p>'
            : '';

        var tilesHtml =
            '<div style="flex-shrink:0;display:grid;grid-template-columns:1fr 1fr;gap:10px;">' +
            uptimeStatCard(statusLabel, msLabel, statusColor) +
            (d.uptime_24h != null ? uptimeStatCard(d.uptime_24h + '%', '24h uptime', d.uptime_24h >= 99 ? '#16a34a' : d.uptime_24h >= 95 ? '#ca8a04' : '#dc2626') : '') +
            (d.uptime_7d  != null ? uptimeStatCard(d.uptime_7d  + '%', '7d uptime',  d.uptime_7d  >= 99 ? '#16a34a' : d.uptime_7d  >= 95 ? '#ca8a04' : '#dc2626') : '') +
            (d.avg_ms_24h != null ? uptimeStatCard(d.avg_ms_24h + 'ms', 'avg resp', d.avg_ms_24h < 500 ? '#16a34a' : d.avg_ms_24h < 1500 ? '#ca8a04' : '#dc2626') : '') +
            '</div>' + heartbeat;

        var raw = d.raw || [];
        var chartHtml = '';
        if (raw.length >= 5) {
            var recent = raw.slice(-60);
            var maxMs  = Math.max.apply(null, recent.map(function(r){ return r.ms; })) || 1;
            var fmtMs  = function(v){ return v >= 1000 ? (v/1000).toFixed(1)+'s' : v+'ms'; };
            var midMs  = Math.round(maxMs / 2);
            chartHtml += '<p style="font-size:.82em;font-weight:700;color:#374151;margin:0 0 6px;">Response time — last ' + recent.length + ' pings</p>';
            chartHtml += '<div style="display:flex;align-items:flex-start;gap:4px;width:100%;">';
            chartHtml += '<div style="height:80px;display:flex;flex-direction:column;justify-content:space-between;padding:4px 0;font-size:10px;color:#9ca3af;text-align:right;line-height:1;min-width:36px;flex-shrink:0;">'
                + '<span>' + fmtMs(maxMs) + '</span>'
                + '<span>' + fmtMs(midMs) + '</span>'
                + '<span>0</span>'
                + '</div>';
            chartHtml += '<div style="flex:1;min-width:0;display:flex;align-items:flex-end;gap:1px;height:80px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:4px 6px;overflow:hidden;">';
            recent.forEach(function(r) {
                var h   = Math.max(2, Math.round((r.ms / maxMs) * 72));
                var col = r.up ? '#34d399' : '#f87171';
                chartHtml += '<div style="flex:1;min-width:3px;max-width:14px;height:' + h + 'px;background:' + col + ';border-radius:1px;" title="' + (r.up ? 'UP' : 'DOWN') + ' ' + r.ms + 'ms"></div>';
            });
            chartHtml += '</div></div>';
            chartHtml += '<p style="font-size:.75em;color:#9ca3af;margin:4px 0 0;">Green = up · Red = down · Height = response time</p>';
        } else if (raw.length > 0) {
            chartHtml = '<p style="font-size:.78em;color:#9ca3af;margin:0;padding-top:8px;">Chart appears after 5 pings (' + (5 - raw.length) + ' more needed).</p>';
        }

        statusInner.innerHTML =
            '<div style="display:flex;gap:20px;align-items:flex-start;margin-bottom:16px;">' +
            '<div>' + tilesHtml + '</div>' +
            '<div style="flex:1;min-width:0;">' + chartHtml + '</div>' +
            '</div>';
    }

    function uptimeStatCard(value, label, color) {
        return '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;text-align:center;">' +
            '<div style="font-size:1.3em;font-weight:800;color:' + color + ';line-height:1.2;">' + escHtml(String(value)) + '</div>' +
            '<div style="font-size:.75em;color:#6b7280;font-weight:600;margin-top:4px;">' + escHtml(label) + '</div>' +
            '</div>';
    }

    function csdtUptimeInit() {
        var deployBtn   = uptimeEl('csdt-uptime-deploy-btn');
        var testBtn     = uptimeEl('csdt-uptime-test-btn');
        var saveBtn     = uptimeEl('csdt-uptime-save-btn');
        var refreshBtn  = uptimeEl('csdt-uptime-refresh-btn');
        var pauseBtn    = uptimeEl('csdt-uptime-pause-btn');
        var cancelBtn   = uptimeEl('csdt-uptime-cancel-pause-btn');

        if (!deployBtn && !saveBtn) return; // not on optimizer tab

        if (pauseBtn) {
            pauseBtn.addEventListener('click', function() {
                pauseBtn.disabled = true;
                post('csdt_uptime_pause_heartbeat').then(function(res) {
                    pauseBtn.disabled = false;
                    if (res.success && res.data && res.data.pause_until) {
                        startPauseCountdown(res.data.pause_until);
                    }
                }).catch(function() { pauseBtn.disabled = false; });
            });
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                cancelBtn.disabled = true;
                post('csdt_uptime_pause_heartbeat', { cancel: 1 }).then(function(res) {
                    cancelBtn.disabled = false;
                    if (res.success) { clearPauseState(); loadUptimeHistory(true); }
                }).catch(function() { cancelBtn.disabled = false; });
            });
        }

        if (deployBtn) {
            deployBtn.addEventListener('click', function() {
                if (!confirm('Deploy the Cloudflare Worker? This will create or update the worker and KV namespace in your Cloudflare account.')) return;
                var ntfy     = uptimeEl('csdt-uptime-ntfy-url');
                var deploying = uptimeEl('csdt-uptime-deploying');
                var deployRes = uptimeEl('csdt-uptime-deploy-result');
                deployBtn.disabled = true;
                deployBtn.textContent = '⏳ Deploying…';
                if (deploying) deploying.style.display = '';
                if (deployRes) deployRes.innerHTML = '';

                post('csdt_uptime_deploy_worker', { ntfy_url: ntfy ? ntfy.value.trim() : '' }).then(function(res) {
                    deployBtn.disabled = false;
                    deployBtn.textContent = '🚀 Deploy Worker to Cloudflare';
                    if (deploying) deploying.style.display = 'none';
                    if (!res.success) {
                        if (deployRes) deployRes.innerHTML = '<div style="background:#fef2f2;border-left:3px solid #dc2626;padding:10px 14px;border-radius:0 6px 6px 0;font-size:.87em;color:#7f1d1d;">⚠ ' + escHtml((res.data && res.data.message) || 'Deploy failed') + '</div>';
                        post('csdt_uptime_setup').then(function(sr) { if (sr.success) renderManualDeploy(sr.data.worker_js, sr.data.wrangler_toml); });
                        return;
                    }
                    if (deployRes) {
                        var d = res.data;
                        deployRes.innerHTML = '<div style="background:#f0fdf4;border-left:3px solid #16a34a;padding:10px 14px;border-radius:0 6px 6px 0;font-size:.87em;color:#166534;">✅ ' + escHtml(d.message) +
                            (d.cf_worker_url ? ' <a href="' + escHtml(d.cf_worker_url) + '" target="_blank" rel="noopener" style="color:#16a34a;font-weight:600;">View Worker →</a>' : '') +
                            (!d.cron_ok ? '<br><span style="color:#ca8a04;">⚠ Cron trigger could not be set — go to Worker dashboard → Triggers → Add Cron → <code>* * * * *</code></span>' : '') +
                            '</div>';
                    }
                    loadUptimeHistory();
                }).catch(function() {
                    deployBtn.disabled = false;
                    deployBtn.textContent = '🚀 Deploy Worker to Cloudflare';
                    if (deploying) deploying.style.display = 'none';
                    var dr = uptimeEl('csdt-uptime-deploy-result');
                    if (dr) dr.innerHTML = '<p style="color:#dc2626;font-size:.9em;">Request failed — please reload.</p>';
                });
            });
        }

        if (saveBtn) {
            saveBtn.addEventListener('click', function() {
                var ntfyI  = uptimeEl('csdt-uptime-ntfy-url');
                var zoneI  = uptimeEl('csdt-cf-zone-id');
                var tokenI = uptimeEl('csdt-cf-api-token');
                var saveSt = uptimeEl('csdt-uptime-save-status');
                saveBtn.disabled = true;
                post('csdt_uptime_save_settings', {
                    ntfy_url:     ntfyI  ? ntfyI.value.trim()  : '',
                    cf_zone_id:   zoneI  ? zoneI.value.trim()  : '',
                    cf_api_token: tokenI ? tokenI.value.trim() : '',
                }).then(function(res) {
                    saveBtn.disabled = false;
                    if (!saveSt) return;
                    saveSt.style.display = '';
                    saveSt.style.color = res.success ? '#16a34a' : '#dc2626';
                    saveSt.textContent = res.success ? '✓ Saved' : '✗ Save failed';
                    setTimeout(function() { saveSt.style.display = 'none'; }, 10000);
                }).catch(function() { saveBtn.disabled = false; });
            });
        }

        if (testBtn) {
            testBtn.addEventListener('click', function() {
                var deployRes = uptimeEl('csdt-uptime-deploy-result');
                testBtn.disabled = true;
                testBtn.textContent = '⏳ Testing…';
                if (deployRes) deployRes.innerHTML = '';
                post('csdt_uptime_test_endpoint').then(function(res) {
                    testBtn.disabled = false;
                    testBtn.textContent = '🧪 Test Endpoint';
                    var dr = uptimeEl('csdt-uptime-deploy-result');
                    if (!res.success) {
                        if (dr) dr.innerHTML = '<div style="background:#fef2f2;border-left:3px solid #dc2626;padding:10px 14px;border-radius:0 6px 6px 0;font-size:.87em;color:#7f1d1d;">⚠ ' + escHtml((res.data && res.data.message) || 'Test failed') + '</div>';
                        return;
                    }
                    var d = res.data;
                    var ok = d.ok;
                    var staleNote = d.stale === true ? ' — Worker stale (site was down)' : d.stale === false ? ' — Worker fresh' : '';
                    if (dr) {
                        dr.innerHTML = '<div style="background:' + (ok?'#f0fdf4':'#fef2f2') + ';border-left:3px solid ' + (ok?'#16a34a':'#dc2626') + ';padding:10px 14px;border-radius:0 6px 6px 0;font-size:.87em;color:' + (ok?'#166534':'#7f1d1d') + ';">' +
                            (ok ? '✅ Heartbeat accepted — ' + d.ms + 'ms' : '🔴 Worker returned HTTP ' + d.status_code + ' — ' + d.ms + 'ms') + staleNote + '</div>';
                        setTimeout(function() { var x = uptimeEl('csdt-uptime-deploy-result'); if (x) x.innerHTML = ''; }, 8000);
                    }
                    loadUptimeHistory();
                }).catch(function() {
                    testBtn.disabled = false;
                    testBtn.textContent = '🧪 Test Endpoint';
                    var dr = uptimeEl('csdt-uptime-deploy-result');
                    if (dr) dr.innerHTML = '<div style="background:#fef2f2;border-left:3px solid #dc2626;padding:10px 14px;border-radius:0 6px 6px 0;font-size:.87em;color:#7f1d1d;">⚠ Request failed — check network</div>';
                });
            });
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', function() {
                refreshBtn.disabled = true;
                refreshBtn.textContent = '⏳ Pushing heartbeat…';
                loadUptimeHistory(true).then(function() {
                    refreshBtn.disabled = false;
                    refreshBtn.textContent = '✓ Heartbeat sent!';
                    refreshBtn.style.color = '#16a34a';
                    setTimeout(function() {
                        refreshBtn.textContent = '↻ Push Heartbeat + Refresh';
                        refreshBtn.style.color = '';
                    }, 3000);
                    var ps = uptimeEl('csdt-uptime-push-status');
                    if (ps) ps.style.display = 'none';
                });
            });
        }

        // Generic show/hide toggle for password-type fields (.cs-pw-toggle)
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.cs-pw-toggle');
            if (!btn) return;
            var inp = document.getElementById(btn.getAttribute('data-target'));
            if (!inp) return;
            var showing = inp.type !== 'password';
            inp.type = showing ? 'password' : 'text';
            btn.textContent = showing ? '👁 Show' : '🔒 Hide';
        });

        // Notification alerts save
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('#cs-alerts-save');
            if (!btn) return;
            btn.disabled = true;
            var data = { action: 'csdt_devtools_save_alerts', nonce: (window.csdtDevtoolsThumbs || {}).nonce || '' };
            document.querySelectorAll('.cs-alert-toggle').forEach(function(cb) {
                data[cb.dataset.opt] = cb.checked ? '1' : '0';
            });
            var fd = new FormData();
            Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });
            fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function() {
                    btn.disabled = false;
                    var saved = document.getElementById('cs-alerts-saved');
                    if (saved) { saved.style.display = ''; setTimeout(function(){ saved.style.display = 'none'; }, 2000); }
                })
                .catch(function() { btn.disabled = false; });
        });

        // Credentials panel — show/copy/rotate
        document.addEventListener('click', function(e) {
            var showBtn = e.target.closest('.cs-cred-show');
            if (showBtn) {
                var code = document.getElementById(showBtn.getAttribute('data-target'));
                if (!code) return;
                var shown = showBtn.textContent.includes('Hide');
                code.textContent = shown ? (code.dataset.masked || '') : (code.dataset.real || '');
                showBtn.textContent = shown ? '👁 Show' : '🔒 Hide';
                return;
            }
            var copyBtn = e.target.closest('.cs-cred-copy');
            if (copyBtn) {
                var code2 = document.getElementById(copyBtn.getAttribute('data-target'));
                if (!code2) return;
                navigator.clipboard.writeText(code2.dataset.real || '').then(function() {
                    var orig = copyBtn.textContent;
                    copyBtn.textContent = '✓ Copied';
                    setTimeout(function() { copyBtn.textContent = orig; }, 1500);
                });
                return;
            }
            var regenBtn = e.target.closest('.cs-cred-regen');
            if (regenBtn) {
                if (!confirm('Rotate this credential? You must update all .env files and scripts that use it.')) return;
                var action = regenBtn.getAttribute('data-action');
                var target = regenBtn.getAttribute('data-target');
                var ftype  = regenBtn.getAttribute('data-type');
                var code3  = document.getElementById(target);
                regenBtn.disabled = true;
                regenBtn.textContent = '⏳';
                var body = new FormData();
                body.append('action', action);
                body.append('nonce',  (window.csdtTestAccounts || {}).nonce || (window.csdtDevtoolsLogin || {}).nonce || '');
                fetch(window.csdtTestAccounts && window.csdtTestAccounts.ajaxUrl ? window.csdtTestAccounts.ajaxUrl : (window.ajaxurl || ''), { method: 'POST', body: body })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        regenBtn.disabled = false;
                        regenBtn.textContent = '↺ Rotate';
                        if (res.success && code3) {
                            var newVal = res.data.secret || res.data.path_token_url || res.data.session_url || '';
                            if (newVal) {
                                var masked = newVal.length > 8
                                    ? '•'.repeat(Math.min(24, newVal.length - 4)) + newVal.slice(-4)
                                    : newVal;
                                if (ftype === 'url') {
                                    var parts = newVal.replace(/\/$/, '').split('/');
                                    var last  = parts.pop();
                                    masked    = parts.join('/') + '/' + '•'.repeat(Math.max(8, last.length - 4)) + last.slice(-4);
                                }
                                code3.dataset.real   = newVal;
                                code3.dataset.masked = masked;
                                code3.textContent    = masked;
                                // Reset sibling show button
                                var row = code3.closest('td');
                                if (row) {
                                    var sb = row.querySelector('.cs-cred-show');
                                    if (sb) sb.textContent = '👁 Show';
                                }
                                // Flash row green
                                var tr = code3.closest('tr');
                                if (tr) { tr.style.background = '#f0fdf4'; setTimeout(function() { tr.style.background = ''; }, 2000); }
                            }
                        }
                    })
                    .catch(function() { regenBtn.disabled = false; regenBtn.textContent = '↺ Rotate'; });
            }
        });

        // Eye toggle for Cloudflare credential fields
        document.querySelectorAll('.csdt-cf-eye-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var inp = uptimeEl(btn.getAttribute('data-target'));
                if (!inp) return;
                var showing = inp.getAttribute('data-showing') === '1';
                if (!showing) {
                    inp.value = inp.getAttribute('data-real') || '';
                    inp.setAttribute('data-showing', '1');
                    btn.textContent = '🙈';
                } else {
                    inp.value = inp.getAttribute('data-masked') || '';
                    inp.setAttribute('data-showing', '0');
                    btn.textContent = '👁';
                }
            });
        });

        // Auto-load history on each tab visit
        post('csdt_uptime_history').then(function(res) {
            if (!res.success) return;
            var inner = uptimeEl('csdt-uptime-status-inner');
            var wrap  = uptimeEl('csdt-uptime-status-wrap');
            if (inner) renderUptimeStatus(res.data, inner);
            if (wrap)  wrap.style.display = '';
            var pu = res.data.pause_until;
            if (pu && pu * 1000 > Date.now()) startPauseCountdown(pu);
        }).catch(function() {});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', csdtUptimeInit);
    } else {
        csdtUptimeInit();
    }
    document.addEventListener('csdt:tab-shown', function(e) {
        if (e.detail && e.detail.tab === 'optimizer') csdtUptimeInit();
    });

    // ── Update Risk Scorer ───────────────────────────────────────────────────

    var riskScanBtn     = document.getElementById('csdt-update-risk-scan-btn');
    var riskScanningMsg = document.getElementById('csdt-update-risk-scanning');
    var riskResultsDiv  = document.getElementById('csdt-update-risk-results');

    if (riskScanBtn) {
        riskScanBtn.addEventListener('click', function () {
            riskScanBtn.disabled  = true;
            riskScanBtn.textContent = '⏳ Scanning…';
            if (riskScanningMsg) riskScanningMsg.style.display = '';
            if (riskResultsDiv)  riskResultsDiv.style.display  = 'none';

            post('csdt_update_risk_scan').then(function (res) {
                riskScanBtn.disabled  = false;
                riskScanBtn.innerHTML = '🔍 Scan for Available Updates';
                if (riskScanningMsg) riskScanningMsg.style.display = 'none';

                if (!res.success) {
                    if (riskResultsDiv) {
                        riskResultsDiv.innerHTML = '<p style="color:#dc2626;font-size:.9em;">Scan failed — please reload and try again.</p>';
                        riskResultsDiv.style.display = '';
                    }
                    return;
                }
                renderUpdateRiskResults(res.data.plugins || []);
            }).catch(function () {
                riskScanBtn.disabled  = false;
                riskScanBtn.innerHTML = '🔍 Scan for Available Updates';
                if (riskScanningMsg) riskScanningMsg.style.display = 'none';
                if (riskResultsDiv) {
                    riskResultsDiv.innerHTML = '<p style="color:#dc2626;font-size:.9em;">Request failed — please reload and try again.</p>';
                    riskResultsDiv.style.display = '';
                }
            });
        });
    }

    var RISK_BADGE = {
        patch:    { bg: '#f0fdf4', border: '#86efac', badge: '#16a34a', label: '🟢 Patch',    text: 'Low risk — bug fixes only.' },
        minor:    { bg: '#fefce8', border: '#fde68a', badge: '#ca8a04', label: '🟡 Minor',    text: 'Review changelog — new features, possible deprecations.' },
        breaking: { bg: '#fef2f2', border: '#fca5a5', badge: '#dc2626', label: '🔴 Breaking', text: 'High risk — major version change. Test in staging first.' },
    };

    function renderUpdateRiskResults(plugins) {
        if (!riskResultsDiv) return;
        if (plugins.length === 0) {
            riskResultsDiv.innerHTML =
                '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:16px 20px;">' +
                '<p style="margin:0;font-weight:600;color:#166534;">✅ All plugins are up to date — nothing to assess.</p></div>';
            riskResultsDiv.style.display = '';
            return;
        }

        var html =
            '<p style="margin:0 0 14px;color:#6b7280;font-size:.87em;">' + plugins.length + ' update' + (plugins.length !== 1 ? 's' : '') + ' available. Click <strong>Assess Risk</strong> on each row to get an AI risk score before updating.</p>' +
            '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">' +
            '<table style="width:100%;border-collapse:collapse;font-size:.88em;" id="csdt-risk-table">' +
            '<thead><tr style="background:#f8fafc;">' +
            '<th style="padding:10px 14px;text-align:left;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Plugin</th>' +
            '<th style="padding:10px 14px;text-align:center;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Current</th>' +
            '<th style="padding:10px 14px;text-align:center;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">New</th>' +
            '<th style="padding:10px 14px;text-align:center;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;">Risk</th>' +
            '<th style="padding:10px 14px;text-align:center;font-weight:700;color:#374151;border-bottom:1px solid #e5e7eb;"></th>' +
            '</tr></thead><tbody>';

        plugins.forEach(function (p, i) {
            var bg  = i % 2 === 0 ? '#fff' : '#f8fafc';
            var rid = 'risk-row-' + i;
            html +=
                '<tr style="background:' + bg + ';border-bottom:1px solid #f1f5f9;" id="' + rid + '">' +
                '<td style="padding:10px 14px;font-weight:600;color:#0f172a;">' + escHtml(p.name) + '</td>' +
                '<td style="padding:10px 14px;text-align:center;color:#6b7280;font-size:.85em;">' + escHtml(p.current_version) + '</td>' +
                '<td style="padding:10px 14px;text-align:center;color:#0ea5e9;font-weight:600;font-size:.85em;">' + escHtml(p.new_version) + '</td>' +
                '<td style="padding:10px 14px;text-align:center;" class="risk-badge-cell-' + i + '"><span style="color:#9ca3af;font-size:.82em;">—</span></td>' +
                '<td style="padding:10px 14px;text-align:center;">' +
                '<button class="csdt-assess-risk-btn" ' +
                'data-idx="' + i + '" ' +
                'data-slug="' + escHtml(p.slug) + '" ' +
                'data-name="' + escHtml(p.name) + '" ' +
                'data-current="' + escHtml(p.current_version) + '" ' +
                'data-new="' + escHtml(p.new_version) + '" ' +
                'style="background:#6366f1;color:#fff;border:none;font-size:.8em;font-weight:700;padding:5px 12px;border-radius:6px;cursor:pointer;white-space:nowrap;">Assess Risk</button>' +
                '</td>' +
                '</tr>' +
                '<tr class="risk-reason-row-' + i + '" style="display:none;"><td colspan="5" style="padding:0 14px 10px 42px;font-size:.84em;color:#374151;line-height:1.5;" class="risk-reason-cell-' + i + '"></td></tr>';
        });

        html += '</tbody></table></div>';
        riskResultsDiv.innerHTML = html;
        riskResultsDiv.style.display = '';

        riskResultsDiv.addEventListener('click', function (e) {
            var btn = e.target.closest('.csdt-assess-risk-btn');
            if (!btn || btn.disabled) return;
            var idx     = btn.getAttribute('data-idx');
            var slug    = btn.getAttribute('data-slug');
            var name    = btn.getAttribute('data-name');
            var current = btn.getAttribute('data-current');
            var newVer  = btn.getAttribute('data-new');

            btn.disabled    = true;
            btn.textContent = '⏳…';

            post('csdt_update_risk_assess', { slug: slug, name: name, current_version: current, new_version: newVer })
                .then(function (res) {
                    var risk   = (res.success && res.data && res.data.risk) ? res.data.risk : 'minor';
                    var reason = (res.success && res.data && res.data.reason) ? res.data.reason : '';
                    var rb     = RISK_BADGE[risk] || RISK_BADGE.minor;

                    var badgeCell  = riskResultsDiv.querySelector('.risk-badge-cell-' + idx);
                    var reasonRow  = riskResultsDiv.querySelector('.risk-reason-row-' + idx);
                    var reasonCell = riskResultsDiv.querySelector('.risk-reason-cell-' + idx);

                    if (badgeCell) {
                        badgeCell.innerHTML =
                            '<span style="background:' + rb.bg + ';border:1px solid ' + rb.border + ';color:' + rb.badge + ';font-weight:700;font-size:.78em;padding:3px 10px;border-radius:20px;white-space:nowrap;">' +
                            escHtml(rb.label) + '</span>';
                    }
                    if (reasonCell) {
                        reasonCell.innerHTML = '<em style="color:#6b7280;">' + escHtml(rb.text) + '</em>' + ( reason ? ' ' + escHtml(reason) : '' );
                    }
                    if (reasonRow) reasonRow.style.display = '';

                    btn.textContent = 'Re-assess';
                    btn.disabled    = false;
                })
                .catch(function () {
                    btn.textContent = 'Assess Risk';
                    btn.disabled    = false;
                });
        });
    }

    // ── Database Intelligence Engine ─────────────────────────────────────────

    var dbScanBtn     = document.getElementById('csdt-db-intelligence-scan-btn');
    var dbScanningMsg = document.getElementById('csdt-db-intelligence-scanning');
    var dbResultsDiv  = document.getElementById('csdt-db-intelligence-results');

    if (dbScanBtn) {
        dbScanBtn.addEventListener('click', function () {
            dbScanBtn.disabled    = true;
            dbScanBtn.textContent = '⏳ Scanning…';
            if (dbScanningMsg) dbScanningMsg.style.display = '';
            if (dbResultsDiv)  dbResultsDiv.style.display  = 'none';

            post('csdt_db_intelligence_scan').then(function (res) {
                dbScanBtn.disabled  = false;
                dbScanBtn.innerHTML = '🔍 Analyse Database';
                if (dbScanningMsg) dbScanningMsg.style.display = 'none';

                if (!res.success) {
                    if (dbResultsDiv) {
                        dbResultsDiv.innerHTML = '<p style="color:#dc2626;font-size:.9em;">Scan failed — please reload and try again.</p>';
                        dbResultsDiv.style.display = '';
                    }
                    return;
                }
                renderDbIntelligence(res.data);
            }).catch(function () {
                dbScanBtn.disabled  = false;
                dbScanBtn.innerHTML = '🔍 Analyse Database';
                if (dbScanningMsg) dbScanningMsg.style.display = 'none';
                if (dbResultsDiv) {
                    dbResultsDiv.innerHTML = '<p style="color:#dc2626;font-size:.9em;">Request failed — please reload and try again.</p>';
                    dbResultsDiv.style.display = '';
                }
            });
        });
    }

    var DB_SEV_COLOR = {
        critical: { bg: '#fef2f2', border: '#fca5a5', badge: '#dc2626', text: '#7f1d1d' },
        high:     { bg: '#fff7ed', border: '#fed7aa', badge: '#ea580c', text: '#7c2d12' },
        medium:   { bg: '#fefce8', border: '#fde68a', badge: '#ca8a04', text: '#713f12' },
        low:      { bg: '#f0fdf4', border: '#86efac', badge: '#16a34a', text: '#14532d' },
        info:     { bg: '#f0f9ff', border: '#7dd3fc', badge: '#0284c7', text: '#0c4a6e' },
    };

    function renderDbIntelligence(data) {
        if (!dbResultsDiv) return;
        var stats    = data.stats    || {};
        var findings = data.findings || [];

        // Stats cards
        var totalMb     = stats.total_db_kb     ? (stats.total_db_kb / 1024).toFixed(1)     : '—';
        var autoloadKb  = stats.autoload_total_kb != null ? stats.autoload_total_kb  : '—';
        var revisionsK  = stats.revisions_count  != null ? stats.revisions_count.toLocaleString() : '—';
        var orphansN    = stats.orphaned_postmeta != null ? stats.orphaned_postmeta.toLocaleString() : '—';

        var statsHtml =
            '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:24px;">' +
            dbStatCard('Total DB', totalMb + ' MB', '#0f172a') +
            dbStatCard('Autoload', autoloadKb + ' KB', autoloadKb > 500 ? '#dc2626' : '#16a34a') +
            dbStatCard('Revisions', revisionsK, stats.revisions_count > 500 ? '#ea580c' : '#374151') +
            dbStatCard('Orphan Meta', orphansN, stats.orphaned_postmeta > 50 ? '#ea580c' : '#374151') +
            '</div>';

        // Top autoloaded table
        var topHtml = '';
        if (stats.top_autoloaded && stats.top_autoloaded.length) {
            topHtml =
                '<details style="margin-bottom:20px;">' +
                '<summary style="cursor:pointer;font-size:.88em;font-weight:600;color:#374151;padding:8px 0;">📋 Top ' + stats.top_autoloaded.length + ' autoloaded options by size</summary>' +
                '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;margin-top:8px;">' +
                '<table style="width:100%;border-collapse:collapse;font-size:.84em;">' +
                '<thead><tr style="background:#f8fafc;"><th style="padding:7px 12px;text-align:left;color:#374151;border-bottom:1px solid #e5e7eb;">Option name</th><th style="padding:7px 12px;text-align:right;color:#374151;border-bottom:1px solid #e5e7eb;white-space:nowrap;">Size (KB)</th></tr></thead><tbody>';
            stats.top_autoloaded.forEach(function (r, i) {
                var bg = i % 2 === 0 ? '#fff' : '#f8fafc';
                topHtml += '<tr style="background:' + bg + ';border-bottom:1px solid #f1f5f9;">' +
                    '<td style="padding:7px 12px;color:#374151;word-break:break-all;">' + escHtml(r.option_name) + '</td>' +
                    '<td style="padding:7px 12px;text-align:right;font-weight:600;color:#0f172a;">' + escHtml(String(r.size_kb)) + '</td>' +
                    '</tr>';
            });
            topHtml += '</tbody></table></div></details>';
        }

        // Findings
        var findingsHtml = '<div style="display:flex;flex-direction:column;gap:12px;" id="csdt-db-findings">';
        findings.forEach(function (f, i) {
            var sev = (f.severity || 'info').toLowerCase();
            var col = DB_SEV_COLOR[sev] || DB_SEV_COLOR.info;
            var fixBtn = '';
            if (f.fix_action) {
                fixBtn = '<button class="csdt-db-fix-btn" data-fix-id="' + escHtml(f.fix_action) + '" data-idx="' + i + '" ' +
                    'style="background:#10b981;color:#fff;border:none;font-size:.8em;font-weight:700;padding:6px 14px;border-radius:6px;cursor:pointer;margin-top:8px;">⚡ Fix It</button>' +
                    '<span class="csdt-db-fix-status-' + i + '" style="display:none;margin-left:8px;font-size:.82em;"></span>';
            }
            findingsHtml +=
                '<div style="background:' + col.bg + ';border:1px solid ' + col.border + ';border-radius:8px;padding:14px 18px;">' +
                '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">' +
                '<span style="background:' + col.badge + ';color:#fff;font-size:.7em;font-weight:700;padding:2px 8px;border-radius:20px;text-transform:uppercase;">' + escHtml(sev) + '</span>' +
                '<span style="font-weight:700;color:#0f172a;font-size:.93em;">' + escHtml(f.title) + '</span>' +
                '</div>' +
                '<p style="margin:0 0 6px;color:#4b5563;font-size:.87em;line-height:1.6;">' + escHtml(f.detail) + '</p>' +
                '<div style="background:rgba(255,255,255,.7);border-left:2px solid ' + col.badge + ';padding:7px 11px;border-radius:0 4px 4px 0;font-size:.84em;color:#374151;">' +
                '<strong style="color:' + col.text + ';">Fix: </strong>' + escHtml(f.fix) +
                '</div>' +
                fixBtn +
                '</div>';
        });
        findingsHtml += '</div>';

        dbResultsDiv.innerHTML = statsHtml + topHtml + findingsHtml;
        dbResultsDiv.style.display = '';

        // Fix button delegation
        dbResultsDiv.addEventListener('click', function (e) {
            var btn = e.target.closest('.csdt-db-fix-btn');
            if (!btn || btn.disabled) return;
            var fixId = btn.getAttribute('data-fix-id');
            var idx   = btn.getAttribute('data-idx');
            var statusEl = dbResultsDiv.querySelector('.csdt-db-fix-status-' + idx);
            btn.disabled    = true;
            btn.textContent = '⏳ Fixing…';
            if (statusEl) statusEl.style.display = 'none';

            post('csdt_db_intelligence_fix', { fix_id: fixId }).then(function (res) {
                if (res && res.success) {
                    btn.textContent    = '✅ Done';
                    btn.style.background = '#6b7280';
                    if (statusEl) {
                        statusEl.style.display = 'inline';
                        statusEl.style.color   = '#16a34a';
                        statusEl.textContent   = res.data && res.data.message ? res.data.message : 'Done';
                    }
                } else {
                    btn.disabled    = false;
                    btn.textContent = '⚡ Fix It';
                    if (statusEl) {
                        statusEl.style.display = 'inline';
                        statusEl.style.color   = '#dc2626';
                        statusEl.textContent   = (res && res.data) || 'Error';
                    }
                }
            }).catch(function () {
                btn.disabled    = false;
                btn.textContent = '⚡ Fix It';
                if (statusEl) {
                    statusEl.style.display = 'inline';
                    statusEl.style.color   = '#dc2626';
                    statusEl.textContent   = 'Request failed';
                }
            });
        });
    }

    function dbStatCard(label, value, color) {
        return '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;text-align:center;">' +
            '<div style="font-size:1.4em;font-weight:800;color:' + color + ';line-height:1.2;">' + escHtml(String(value)) + '</div>' +
            '<div style="font-size:.75em;color:#6b7280;font-weight:600;margin-top:4px;">' + escHtml(label) + '</div>' +
            '</div>';
    }

    // ── Orphaned Table Cleanup ───────────────────────────────────────────────

    var orphanScanBtn  = document.getElementById('csdt-orphan-scan-btn');
    var orphanScanning = document.getElementById('csdt-orphan-scanning');
    var orphanResults  = document.getElementById('csdt-orphan-results');

    function fmtSize(kb) {
        if (!kb) return '0 KB';
        return kb >= 1024 ? (kb / 1024).toFixed(1) + ' MB' : kb + ' KB';
    }

    function renderOrphanResults(tables) {
        if (!orphanResults) return;
        if (!tables || !tables.length) {
            orphanResults.innerHTML =
                '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:14px 16px;">' +
                '<p style="margin:0;font-weight:700;color:#15803d;">✓ No orphaned tables found</p>' +
                '<p style="margin:6px 0 0;font-size:.88em;color:#166534;">All non-core tables appear to belong to active plugins.</p>' +
                '</div>';
            orphanResults.style.display = '';
            return;
        }

        var totalKb   = tables.reduce(function (s, t) { return s + (t.size_kb || 0); }, 0);
        var totalRows = tables.reduce(function (s, t) { return s + (t.rows || 0); }, 0);

        var html = '<div style="margin-bottom:14px;display:flex;gap:12px;flex-wrap:wrap;">' +
            dbStatCard(tables.length, 'orphaned tables', '#dc2626') +
            dbStatCard(totalRows.toLocaleString(), 'total rows', '#6b7280') +
            dbStatCard(fmtSize(totalKb), 'wasted space', '#ca8a04') +
            '</div>';

        html += '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">';
        html += '<table style="width:100%;border-collapse:collapse;font-size:.85em;">';
        html += '<thead><tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb;">' +
            '<th style="padding:8px 10px;width:32px;"><input type="checkbox" id="csdt-orphan-select-all" title="Select all"></th>' +
            '<th style="padding:8px 10px;text-align:left;font-weight:600;color:#374151;">Table</th>' +
            '<th style="padding:8px 10px;text-align:left;font-weight:600;color:#374151;">Likely Plugin</th>' +
            '<th style="padding:8px 10px;text-align:right;font-weight:600;color:#374151;">Rows</th>' +
            '<th style="padding:8px 10px;text-align:right;font-weight:600;color:#374151;">Size</th>' +
            '</tr></thead><tbody>';

        tables.forEach(function (t) {
            html += '<tr style="border-top:1px solid #f3f4f6;">' +
                '<td style="padding:7px 10px;"><input type="checkbox" class="csdt-orphan-chk" value="' + escHtml(t.table) + '"></td>' +
                '<td style="padding:7px 10px;font-family:monospace;font-size:.9em;color:#1e293b;">' + escHtml(t.table) + '</td>' +
                '<td style="padding:7px 10px;color:#6b7280;">' + escHtml(t.plugin) + '</td>' +
                '<td style="padding:7px 10px;text-align:right;color:#374151;">' + (t.rows || 0).toLocaleString() + '</td>' +
                '<td style="padding:7px 10px;text-align:right;color:#374151;">' + fmtSize(t.size_kb) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table></div>';
        html += '<div style="margin-top:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">' +
            '<button id="csdt-orphan-drop-btn" class="cs-btn-primary" style="background:#dc2626;border-color:#b91c1c;" disabled>' +
            '🗑 Drop Selected</button>' +
            '<span id="csdt-orphan-drop-status" style="font-size:.85em;"></span>' +
            '</div>';

        orphanResults.innerHTML = html;
        orphanResults.style.display = '';

        var selectAll  = document.getElementById('csdt-orphan-select-all');
        var dropBtn    = document.getElementById('csdt-orphan-drop-btn');
        var dropStatus = document.getElementById('csdt-orphan-drop-status');

        function updateDropBtn() {
            var checked = orphanResults.querySelectorAll('.csdt-orphan-chk:checked');
            dropBtn.disabled    = checked.length === 0;
            dropBtn.textContent = checked.length
                ? '🗑 Drop ' + checked.length + ' table' + (checked.length === 1 ? '' : 's')
                : '🗑 Drop Selected';
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                orphanResults.querySelectorAll('.csdt-orphan-chk').forEach(function (c) { c.checked = selectAll.checked; });
                updateDropBtn();
            });
        }
        orphanResults.querySelectorAll('.csdt-orphan-chk').forEach(function (c) {
            c.addEventListener('change', updateDropBtn);
        });

        if (dropBtn) {
            dropBtn.addEventListener('click', function () {
                var checked = Array.prototype.slice.call(orphanResults.querySelectorAll('.csdt-orphan-chk:checked'));
                if (!checked.length) return;
                var names = checked.map(function (c) { return c.value; });
                if (!confirm('Permanently DROP ' + names.length + ' table(s)?\n\n' + names.join('\n') + '\n\nThis cannot be undone.')) return;

                dropBtn.disabled    = true;
                dropBtn.textContent = '⏳ Dropping…';
                if (dropStatus) { dropStatus.style.color = '#6b7280'; dropStatus.textContent = ''; }

                post('csdt_db_drop_tables', { tables: JSON.stringify(names) })
                    .then(function (res) {
                        if (res && res.success) {
                            if (dropStatus) { dropStatus.style.color = '#16a34a'; dropStatus.textContent = '✓ ' + (res.data.message || 'Done'); }
                            runOrphanScan();
                        } else {
                            dropBtn.disabled    = false;
                            dropBtn.textContent = '🗑 Drop Selected';
                            var msg = (res && res.data && res.data.message) || (res && res.data) || 'Error';
                            if (dropStatus) { dropStatus.style.color = '#dc2626'; dropStatus.textContent = '✕ ' + msg; }
                        }
                    })
                    .catch(function () {
                        dropBtn.disabled    = false;
                        dropBtn.textContent = '🗑 Drop Selected';
                        if (dropStatus) { dropStatus.style.color = '#dc2626'; dropStatus.textContent = 'Request failed'; }
                    });
            });
        }
    }

    function runOrphanScan() {
        var btn = document.getElementById('csdt-orphan-scan-btn');
        var scanning = document.getElementById('csdt-orphan-scanning');
        var results  = document.getElementById('csdt-orphan-results');
        if (!btn) return;
        btn.disabled    = true;
        btn.textContent = '⏳ Scanning…';
        if (scanning) scanning.style.display = '';
        if (results)  results.style.display  = 'none';

        post('csdt_db_orphaned_scan').then(function (res) {
            btn.disabled  = false;
            btn.innerHTML = '🔍 Scan for Orphaned Tables';
            if (scanning) scanning.style.display = 'none';
            if (!results) return;
            if (!res.success) {
                results.innerHTML = '<p style="color:#dc2626;font-size:.9em;">Scan failed: ' + escHtml((res && res.data) || 'unknown error') + '</p>';
                results.style.display = '';
                return;
            }
            renderOrphanResults(res.data.tables);
        }).catch(function (err) {
            btn.disabled  = false;
            btn.innerHTML = '🔍 Scan for Orphaned Tables';
            if (scanning) scanning.style.display = 'none';
            if (results) {
                results.innerHTML = '<p style="color:#dc2626;font-size:.9em;">Request failed — please reload and try again.</p>';
                results.style.display = '';
            }
        });
    }

    // Use event delegation so it works regardless of getElementById timing
    document.addEventListener('click', function (e) {
        if (e.target && e.target.id === 'csdt-orphan-scan-btn') {
            runOrphanScan();
        }
    });

    // ── AI Debugging Assistant ───────────────────────────────────────────────

    var debugBtn     = document.getElementById('csdt-debug-analyze-btn');
    var analyzingMsg = document.getElementById('csdt-debug-analyzing');
    var debugInput   = document.getElementById('csdt-debug-input');
    var debugResult  = document.getElementById('csdt-debug-result');

    if (debugBtn) {
        debugBtn.addEventListener('click', function () {
            var input = debugInput ? debugInput.value.trim() : '';
            if (!input) {
                if (debugResult) {
                    debugResult.innerHTML = '<p style="color:#dc2626;font-size:.9em;">Please enter an error message or description first.</p>';
                    debugResult.style.display = '';
                }
                return;
            }

            debugBtn.disabled  = true;
            debugBtn.textContent = '⏳ Analyzing…';
            if (analyzingMsg) analyzingMsg.style.display = '';
            if (debugResult)  debugResult.style.display  = 'none';

            post('csdt_ai_debug_log', { input: input }).then(function (res) {
                debugBtn.disabled    = false;
                debugBtn.innerHTML   = '🤖 Diagnose with AI';
                if (analyzingMsg) analyzingMsg.style.display = 'none';

                if (!res.success) {
                    var errMsg = (res.data && res.data.message) ? res.data.message : 'Analysis failed.';
                    if (debugResult) {
                        debugResult.innerHTML =
                            '<div style="background:#fff5f5;border-left:3px solid #dc2626;padding:12px 16px;border-radius:0 6px 6px 0;color:#7f1d1d;font-size:.9em;line-height:1.6;">' +
                            '<strong>Error:</strong> ' + escHtml(errMsg) +
                            '</div>';
                        debugResult.style.display = '';
                    }
                    return;
                }

                var text = (res.data && res.data.analysis) ? res.data.analysis : '';
                var html =
                    '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:20px 24px;">' +
                    '<div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #e2e8f0;">' +
                    '<span style="font-size:1.2em;">🤖</span>' +
                    '<span style="font-weight:700;color:#0f172a;font-size:.95em;">AI Diagnosis</span>' +
                    '</div>' +
                    '<div style="color:#374151;font-size:.92em;line-height:1.75;">' +
                    '<p style="margin:0 0 10px;line-height:1.75;">' + formatAiText(text) + '</p>' +
                    '</div>' +
                    '</div>';

                if (debugResult) {
                    debugResult.innerHTML = html;
                    debugResult.style.display = '';
                }
            }).catch(function () {
                debugBtn.disabled    = false;
                debugBtn.innerHTML   = '🤖 Diagnose with AI';
                if (analyzingMsg) analyzingMsg.style.display = 'none';
                if (debugResult) {
                    debugResult.innerHTML = '<p style="color:#dc2626;font-size:.9em;">Request failed — please reload and try again.</p>';
                    debugResult.style.display = '';
                }
            });
        });
    }

}());
