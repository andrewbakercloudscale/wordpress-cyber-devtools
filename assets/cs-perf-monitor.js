/**
 * CloudScale Performance Monitor — DevTools-style admin + frontend panel
 *
 * Tabs: DB Queries | HTTP/REST | PHP Errors | Summary
 * Features: call-chain trace, EXPLAIN on demand, N+1 detection,
 *           multi-column sort, colour-coded severity, export JSON.
 *
 * @since 1.8.6
 */
(function () {
    'use strict';

    var LS_OPEN   = 'csdt_devtools_perf_open';
    var LS_HEIGHT = 'csdt_devtools_perf_height';
    var LS_TAB    = 'csdt_devtools_perf_tab';
    var DEFAULT_H = 340;
    var MIN_H     = 260;
    var MAX_H_PCT = 0.82;

    var T_MEDIUM   = 10;
    var T_SLOW     = 50;
    var T_CRITICAL = 200;
    var N1_THRESH  = 3;

    // ── State ─────────────────────────────────────────────────────────────────
    var data      = window.csdtDevtoolsPerfData || { queries: [], http: [], errors: [], logs: [], assets: { scripts: [], styles: [] }, cache: {}, hooks: [], meta: {}, request: {}, transients: [], template: { final: '', hierarchy: [] }, health: {} };
    var meta      = data.meta || {};
    var sortCol   = 'time';
    var sortDir   = 'desc';
    var activeTab = localStorage.getItem(LS_TAB) || 'db';

    var filteredDB   = [];
    var filteredHTTP = [];
    var n1Patterns   = {};

    // Hooks sort state
    var hookSortCol = 'total_ms';
    var hookSortDir = 'desc';

    // ── DOM refs ──────────────────────────────────────────────────────────────
    var panel, toggleBtn, exportBtn, resizeHandle, footTxt, totalTxt, ctxStrip;
    var tabBtns, panes, filterBar;
    var searchInput, pluginSel, speedSel, dupeChk;
    var dbTbody, httpTbody, logList, summaryWrap;
    var dbCount, httpCount, logCount;
    var badgeDB, badgeHTTP, badgeLOG, badgeISSUES;
    var logSearch, logLevel, logSource;
    var assetsTbody, assetsCount, assetSearch, assetType, assetPlugin;
    var hooksTbody, hooksCount, hookSearch;
    var issuesWrap, requestWrap, templateWrap;
    var transTbody, transCount;

    var issuesList = [];

    // ── Editor debug (browser-side fetch / JS error capture) ─────────────────
    var editorLogs     = [];   // {ts, type:'ok'|'fail'|'jserr', method, url, status, dur, detail}
    var editorFailCount = 0;
    var editorBadgeEl   = null;
    var pendingFlash    = false; // errors before toggleBtn exists defer the flash here

    (function initEditorDebug() {
        var origFetch = window.fetch;
        window.fetch = function (input, init) {
            var method  = (init && init.method) || 'GET';
            var url     = typeof input === 'string' ? input : (input && input.url) || String(input);
            var t0      = performance.now();
            // For admin-ajax.php POSTs, capture the action name from FormData so failures are self-diagnosing.
            var ajaxAction = '';
            if (method === 'POST' && url.indexOf('admin-ajax.php') !== -1 && init && init.body instanceof FormData) {
                try { ajaxAction = init.body.get('action') || ''; } catch (ex) { /* ignore */ }
            }
            return origFetch.apply(this, arguments).then(function (resp) {
                var dur = Math.round(performance.now() - t0);
                var ok  = resp.ok;
                if (!ok) {
                    // Clone to read body without consuming the original
                    resp.clone().text().then(function (body) {
                        pushEditorLog({ type:'fail', method:method, url:url, action:ajaxAction, status:resp.status, dur:dur, detail:body.slice(0,300) });
                    }).catch(function () {
                        pushEditorLog({ type:'fail', method:method, url:url, action:ajaxAction, status:resp.status, dur:dur });
                    });
                } else {
                    pushEditorLog({ type:'ok', method:method, url:url, action:ajaxAction, status:resp.status, dur:dur });
                }
                return resp;
            }, function (err) {
                pushEditorLog({ type:'fail', method:method, url:url, action:ajaxAction, status:'ERR', dur:Math.round(performance.now()-t0), detail:String(err) });
                throw err;
            });
        };

        window.addEventListener('error', function (e) {
            if (e.target && e.target !== window) { return; } // resource errors handled separately
            var msg      = e.message || 'Unknown error';
            var filePath = e.filename ? e.filename.replace(/^https?:\/\/[^/]+\//, '') : '';
            // "Script error." means a cross-origin script threw but the browser hid details.
            // Fix: add crossorigin="anonymous" to the offending <script> tag (CDN must send CORS headers).
            if (/^script error\.?$/i.test(msg)) {
                msg = 'Script error. (cross-origin — browser hid details; check browser DevTools console for the real error, or add crossorigin="anonymous" to the CDN <script> tag)';
            }
            pushEditorLog({ type:'jserr', detail: msg,
                file: filePath ? filePath + ':' + e.lineno + (e.colno ? ':' + e.colno : '') : '' });
        });

        window.addEventListener('unhandledrejection', function (e) {
            var msg = e.reason && (e.reason.message || String(e.reason)) || 'Unhandled rejection';
            pushEditorLog({ type:'jserr', detail:'Promise rejection: ' + msg });
        });

        // ── CSP violation listener — flash the monitor on any blocked resource ──
        // CSP issues are hard to spot; make them impossible to miss.
        document.addEventListener('securitypolicyviolation', function (e) {
            var blocked = e.blockedURI || '(inline)';
            var dir     = e.effectiveDirective || e.violatedDirective || '';
            var src     = e.sourceFile ? e.sourceFile.replace(/^https?:\/\/[^/]+\//, '') + ':' + e.lineNumber : '';
            var isReportOnly = e.disposition === 'report';
            // Suppress report-only violations that are not actionable:
            //   1. Same-origin paths (cdn-cgi/, wp-admin/, wp-json/) — Cloudflare's own injected
            //      report-only CSP on admin/edge pages.
            //   2. Known Google ad/consent service domains — Cloudflare's report-only policy omits
            //      these, but our enforcement CSP already allows them via the google_adsense preset.
            //      Nothing we can do on our side; suppressing prevents alert fatigue.
            if ( isReportOnly && /^https?:\/\/[^/]+\/(cdn-cgi\/|wp-admin\/|wp-json\/)/.test( blocked ) ) { return; }
            if ( isReportOnly && /^https?:\/\/([^/]*\.)?(fundingchoicesmessages\.google\.com|adtrafficquality\.google|googlesyndication\.com|googletagservices\.com|googleadservices\.com|doubleclick\.net|adservice\.google\.com|gstatic\.com|csi\.gstatic\.com)/.test( blocked ) ) { return; }
            var disp = isReportOnly ? '[report-only] ' : '';
            pushEditorLog({
                type:   'jserr',
                detail: disp + 'CSP blocked: ' + blocked + (dir ? ' (' + dir + ')' : '') +
                        ' — go to Headers tab → CSP to fix',
                file:   src,
            });
        });
    })();

    function pushEditorLog(entry) {
        var d = new Date();
        entry.ts = ('0'+d.getHours()).slice(-2)+':'+('0'+d.getMinutes()).slice(-2)+':'+('0'+d.getSeconds()).slice(-2)+'.'+('00'+d.getMilliseconds()).slice(-3);
        editorLogs.unshift(entry);
        if (editorLogs.length > 200) { editorLogs.pop(); }
        var isCrossOriginErr    = entry.type === 'jserr' && /^script error\./i.test(entry.detail || '');
        // Third-party ad/analytics scripts send fire-and-forget fetch pings (e.g. pagead/ping,
        // GTM beacons). When the page navigates away these abort, producing "Failed to fetch" /
        // "NetworkError" / "AbortError" unhandled rejections. They are not actionable and should
        // not trigger the error badge or a critical issue.
        var isThirdPartyFetch   = entry.type === 'jserr' && /^Promise rejection: (Failed to fetch|NetworkError|The operation was aborted\.|signal timed out)/i.test(entry.detail || '');
        if (entry.type === 'fail' || (entry.type === 'jserr' && !isCrossOriginErr && !isThirdPartyFetch)) {
            editorFailCount++;
            if (editorBadgeEl) { editorBadgeEl.textContent = editorFailCount; }
            computeIssues();
            renderIssues();
            // Flash the toolbar button so the error is visible even when panel is closed.
            // toggleBtn is null until csdtPerfInit runs on DOMContentLoaded — defer if not ready.
            if (toggleBtn) {
                toggleBtn.classList.remove('cs-monitor-flash');
                void toggleBtn.offsetWidth; // reflow to restart animation
                toggleBtn.classList.add('cs-monitor-flash');
                // Remove class when animation ends so clicks are never blocked.
                toggleBtn.addEventListener('animationend', function onFlashEnd() {
                    toggleBtn.classList.remove('cs-monitor-flash');
                    toggleBtn.removeEventListener('animationend', onFlashEnd);
                });
            } else {
                pendingFlash = true;
            }
        } else if (isCrossOriginErr || isThirdPartyFetch) {
            computeIssues();
            renderIssues();
        }
        if (activeTab === 'editor') { renderEditor(); }
    }

    // ── Bootstrap ─────────────────────────────────────────────────────────────
    function csdtPerfInit() {
        panel        = document.getElementById('cs-perf');
        toggleBtn    = document.getElementById('cs-perf-toggle');
        exportBtn    = document.getElementById('cs-perf-export');
        resizeHandle = document.getElementById('cs-perf-resize');
        footTxt      = document.getElementById('cs-perf-foot-txt');
        totalTxt     = document.getElementById('cs-perf-ttl');
        ctxStrip     = document.getElementById('cs-perf-ctx');
        tabBtns      = Array.prototype.slice.call(document.querySelectorAll('.cs-ptab'));
        panes        = Array.prototype.slice.call(document.querySelectorAll('.cs-ppane'));
        filterBar    = document.getElementById('cs-perf-filters');
        searchInput  = document.getElementById('cs-pf-search');
        pluginSel    = document.getElementById('cs-pf-plugin');
        speedSel     = document.getElementById('cs-pf-speed');
        dupeChk      = document.getElementById('cs-pf-dupe');
        dbTbody      = document.getElementById('cs-db-rows');
        httpTbody    = document.getElementById('cs-http-rows');
        logList      = document.getElementById('cs-log-list');
        summaryWrap  = document.getElementById('cs-summary-wrap');
        dbCount      = document.getElementById('cs-ptc-db');
        httpCount    = document.getElementById('cs-ptc-http');
        logCount     = document.getElementById('cs-ptc-log');
        badgeDB      = document.getElementById('cs-pb-db');
        badgeHTTP    = document.getElementById('cs-pb-http');
        badgeLOG     = document.getElementById('cs-pb-log');
        badgeISSUES  = document.getElementById('cs-pb-issues');
        logSearch    = document.getElementById('cs-lf-search');
        logLevel     = document.getElementById('cs-lf-level');
        logSource    = document.getElementById('cs-lf-source');
        assetsTbody  = document.getElementById('cs-assets-rows');
        assetsCount  = document.getElementById('cs-ptc-assets');
        assetSearch  = document.getElementById('cs-af-search');
        assetType    = document.getElementById('cs-af-type');
        assetPlugin  = document.getElementById('cs-af-plugin');
        hooksTbody   = document.getElementById('cs-hooks-rows');
        hooksCount   = document.getElementById('cs-ptc-hooks');
        hookSearch   = document.getElementById('cs-hkf-search');
        issuesWrap   = document.getElementById('cs-issues-wrap');
        requestWrap  = document.getElementById('cs-request-wrap');
        templateWrap = document.getElementById('cs-template-wrap');
        transTbody   = document.getElementById('cs-trans-rows');
        transCount   = document.getElementById('cs-ptc-trans');
        editorBadgeEl = document.getElementById('cs-ptc-editor');

        // Fire any flash that was queued before DOMContentLoaded
        if (pendingFlash && toggleBtn) {
            pendingFlash = false;
            toggleBtn.classList.add('cs-monitor-flash');
            toggleBtn.addEventListener('animationend', function onFlashEnd2() {
                toggleBtn.classList.remove('cs-monitor-flash');
                toggleBtn.removeEventListener('animationend', onFlashEnd2);
            });
        }

        if (!panel) return;

        computeN1Patterns();
        computeIssues();
        populatePluginFilter();
        populateAssetPluginFilter();
        updateBadges();
        updateTotalTime();
        renderPageContext();
        applyFilters();
        renderLogs();
        renderAssets();
        renderHooks();
        renderIssues();
        renderRequest();
        renderTemplate();
        renderTransients();
        renderSummary();
        renderEditor();
        restoreState();
        bindEvents();
        // Block ghost taps on initial load (iOS fires synthetic touches at last-tap coordinates).
        panelLocked = true;
        setTimeout(function () { panelLocked = false; }, 600);
        // iOS Safari bfcache: DOMContentLoaded doesn't fire on tab restore — force-close here.
        window.addEventListener('pageshow', function (e) {
            if (!e.persisted) return;
            closePanel();
            panelLocked = true;
            setTimeout(function () { panelLocked = false; }, 600);
        });
    }

    // ── Page context strip ────────────────────────────────────────────────────
    function renderPageContext() {
        if (!ctxStrip) return;
        var parts = [];
        if (meta.url)       parts.push('<span class="cs-ctx-url">' + esc(meta.url) + '</span>');
        if (meta.wp_screen) parts.push('<span class="cs-ctx-sep">·</span><span class="cs-ctx-page">' + esc(meta.wp_screen) + '</span>');
        if (meta.page_type) parts.push('<span class="cs-ctx-sep">·</span><span class="cs-ctx-page">' + esc(meta.page_type) + '</span>');
        if (meta.template)  parts.push('<span class="cs-ctx-sep">·</span><span class="cs-ctx-tmpl">' + esc(meta.template) + '</span>');
        ctxStrip.innerHTML = parts.join(' ') || '';
        ctxStrip.style.display = parts.length ? '' : 'none';
    }

    // ── N+1 detection ─────────────────────────────────────────────────────────
    function computeN1Patterns() {
        n1Patterns = {};
        data.queries.forEach(function (q) {
            var fp = normalisePattern(q.sql);
            if (!n1Patterns[fp]) n1Patterns[fp] = { count: 0, total_ms: 0, plugin: q.plugin, caller: q.caller || '', example: q.sql };
            n1Patterns[fp].count++;
            n1Patterns[fp].total_ms += q.time_ms;
        });
        Object.keys(n1Patterns).forEach(function (k) {
            if (n1Patterns[k].count < N1_THRESH) delete n1Patterns[k];
        });
    }

    function normalisePattern(sql) {
        return sql.replace(/\s+/g, ' ').toLowerCase().trim()
            .replace(/'(?:[^'\\]|\\.)*'/g, "'?'")
            .replace(/\b\d+(\.\d+)?\b/g, '?')
            .replace(/\((\s*\?,?\s*){2,}\)/g, '(...)');
    }

    function isN1(sql) {
        return Object.prototype.hasOwnProperty.call(n1Patterns, normalisePattern(sql));
    }

    // ── Panel open / close ────────────────────────────────────────────────────
    function restoreState() {
        // Always start collapsed — only the active tab is persisted.
        localStorage.removeItem(LS_OPEN);
        localStorage.removeItem(LS_HEIGHT);
        closePanel();
        switchTab(activeTab, false);
    }

    // Push the WP content area up so nothing is hidden under the fixed panel.
    function setPadding(px) {
        // Constrain sidebar height so it stops above the panel.
        // Use max-height (not bottom) so WP's internal menu positioning is untouched.
        // On mobile (≤782px) WP uses a flyout overlay for the sidebar — skip these
        // changes entirely or the menu items turn invisible on phones.
        var adminBar  = document.getElementById('wpadminbar');
        var adminBarH = adminBar ? adminBar.offsetHeight : 32;
        var adminMenu = document.getElementById('adminmenuwrap');
        if (adminMenu && window.innerWidth > 782) {
            adminMenu.style.maxHeight  = 'calc(100vh - ' + (adminBarH + px) + 'px)';
            adminMenu.style.overflowY  = 'auto';
            adminMenu.style.bottom     = '';   // clear any previously set bottom
        } else if (adminMenu) {
            // Reset any previously applied styles when in mobile view.
            adminMenu.style.maxHeight  = '';
            adminMenu.style.overflowY  = '';
            adminMenu.style.bottom     = '';
        }

        // Shrink the main content area so it fits above the panel.
        var wpcontent = document.getElementById('wpcontent');
        if (wpcontent) {
            wpcontent.style.marginBottom = px + 'px';
            wpcontent.style.minHeight    = 'calc(100vh - ' + (adminBarH + px) + 'px)';
        }

        // Clear any old padding-bottom.
        var wpbody = document.getElementById('wpbody-content');
        if (wpbody) wpbody.style.paddingBottom = '';

        // Front-end pages have no #wpcontent — pad the body directly so content
        // isn't obscured by the fixed panel.
        if (!wpcontent) {
            document.body.style.paddingBottom = px + 'px';
        } else {
            document.body.style.paddingBottom = '';
        }
    }

    function openPanel(h, animate) {
        if (!animate) panel.style.transition = 'none';
        panel.classList.remove('cs-perf-collapsed');
        panel.classList.add('cs-perf-open');
        var clamped = clampHeight(h);
        panel.style.height = clamped + 'px';
        setPadding(clamped);
        document.getElementById('cs-perf-toggle-arrow').innerHTML = '&#9660;';
        toggleBtn.setAttribute('aria-expanded', 'true');
        if (!animate) { void panel.offsetHeight; panel.style.transition = ''; }
    }

    function closePanel() {
        panel.classList.remove('cs-perf-open');
        panel.classList.add('cs-perf-collapsed');
        panel.style.height = '';
        setPadding(48);
        document.getElementById('cs-perf-toggle-arrow').innerHTML = '&#9650;';
        toggleBtn.setAttribute('aria-expanded', 'false');
    }

    var panelLocked = false;

    function togglePanel() {
        if (panelLocked) return;
        if (panel.classList.contains('cs-perf-open')) closePanel();
        else openPanel(DEFAULT_H, true);
    }

    // ── Tab switching ─────────────────────────────────────────────────────────
    function switchTab(tab, save) {
        activeTab = tab;
        if (save !== false) localStorage.setItem(LS_TAB, tab);
        tabBtns.forEach(function (btn) {
            var on = btn.dataset.tab === tab;
            btn.classList.toggle('active', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        panes.forEach(function (pane) {
            pane.classList.toggle('active', pane.id === 'cs-pp-' + tab);
        });
        var showFilters = tab === 'db' || tab === 'http';
        filterBar.style.display = showFilters ? '' : 'none';
        if (dupeChk) dupeChk.parentElement.style.display = tab === 'db' ? '' : 'none';
        var logFiltersEl    = document.querySelector('.cs-log-filters');
        var assetsFiltersEl = document.querySelector('.cs-assets-filters');
        var hooksFiltersEl  = document.querySelector('.cs-hooks-filters');
        if (logFiltersEl)    logFiltersEl.style.display    = tab === 'logs'   ? '' : 'none';
        if (assetsFiltersEl) assetsFiltersEl.style.display = tab === 'assets' ? '' : 'none';
        if (hooksFiltersEl)  hooksFiltersEl.style.display  = tab === 'hooks'  ? '' : 'none';
        if (tab === 'editor') { renderEditor(); }
    }

    // ── Plugin filter dropdown ────────────────────────────────────────────────
    function populatePluginFilter() {
        var seen = {};
        data.queries.forEach(function (q) { seen[q.plugin] = 1; });
        data.http.forEach(function (h)    { seen[h.plugin] = 1; });
        Object.keys(seen).sort().forEach(function (name) {
            var opt   = document.createElement('option');
            opt.value = name; opt.text = name;
            pluginSel.appendChild(opt);
        });
    }

    function populateAssetPluginFilter() {
        if (!assetPlugin) return;
        var seen = {};
        var assets = data.assets || {};
        (assets.scripts || []).forEach(function (a) { seen[a.plugin] = 1; });
        (assets.styles  || []).forEach(function (a) { seen[a.plugin] = 1; });
        Object.keys(seen).sort().forEach(function (name) {
            var opt   = document.createElement('option');
            opt.value = name; opt.text = name;
            assetPlugin.appendChild(opt);
        });
    }

    // ── Assets tab ────────────────────────────────────────────────────────────
    function renderAssets() {
        if (!assetsTbody) return;
        var assets  = data.assets || {};
        var scripts = assets.scripts || [];
        var styles  = assets.styles  || [];

        var typeFilter   = assetType   ? assetType.value   : '';
        var pluginFilter = assetPlugin ? assetPlugin.value : '';
        var search       = assetSearch ? assetSearch.value.toLowerCase().trim() : '';

        var rows = [];
        if (!typeFilter || typeFilter === 'scripts') {
            scripts.forEach(function (s) { rows.push({ type: 'JS', handle: s.handle, src: s.src, plugin: s.plugin, ver: s.ver, in_footer: s.in_footer, strategy: s.strategy || '' }); });
        }
        if (!typeFilter || typeFilter === 'styles') {
            styles.forEach(function (s)  { rows.push({ type: 'CSS', handle: s.handle, src: s.src, plugin: s.plugin, ver: s.ver, in_footer: true, strategy: '' }); });
        }

        rows = rows.filter(function (r) {
            if (pluginFilter && r.plugin !== pluginFilter) return false;
            if (search && String(r.handle).toLowerCase().indexOf(search) === -1
                       && String(r.src).toLowerCase().indexOf(search) === -1
                       && String(r.plugin).toLowerCase().indexOf(search) === -1) return false;
            return true;
        });

        if (rows.length === 0) {
            assetsTbody.innerHTML = '<tr><td colspan="4" class="cs-empty">'
                + '<span class="cs-empty-icon">&#128190;</span>No assets match the filters.'
                + '</td></tr>';
            return;
        }

        // Sort: plugin then type then handle
        rows.sort(function (a, b) {
            var pc = a.plugin.localeCompare(b.plugin);
            if (pc !== 0) return pc;
            var tc = a.type.localeCompare(b.type);
            return tc !== 0 ? tc : a.handle.localeCompare(b.handle);
        });

        var html = '';
        rows.forEach(function (r) {
            var srcShort = r.src ? truncateUrl(r.src, 50) : '—';
            // Load position badge: defer/async/footer vs blocking head
            var loadTag = '';
            if (r.type === 'JS') {
                if (r.strategy === 'defer')        loadTag = '<span class="cs-tag cs-tag-defer">defer</span>';
                else if (r.strategy === 'async')   loadTag = '<span class="cs-tag cs-tag-defer">async</span>';
                else if (r.in_footer)              loadTag = '<span class="cs-tag cs-tag-footer">footer</span>';
                else if (r.src)                    loadTag = '<span class="cs-tag cs-tag-blocking">blocking</span>';
            }
            html += '<tr' + (loadTag.indexOf('blocking') !== -1 ? ' class="cs-row-slow"' : '') + '>'
                + '<td class="c-at"><span class="cs-asset-type-' + r.type.toLowerCase() + '">' + r.type + '</span></td>'
                + '<td class="c-ah" title="' + esc(r.handle) + '">' + esc(r.handle) + (r.ver ? '<span class="cs-asset-ver"> v' + esc(r.ver) + '</span>' : '') + '</td>'
                + '<td class="c-ap">' + pluginChip(r.plugin) + '</td>'
                + '<td class="c-au" title="' + esc(r.src) + '"><span class="cs-asset-src">' + esc(srcShort) + '</span> ' + loadTag + '</td>'
                + '</tr>';
        });
        assetsTbody.innerHTML = html;
    }

    // ── Hooks tab ─────────────────────────────────────────────────────────────
    function renderHooks() {
        if (!hooksTbody) return;
        var hooks  = data.hooks || [];
        var search = hookSearch ? hookSearch.value.toLowerCase().trim() : '';

        var filtered = hooks.filter(function (h) {
            return !search || h.hook.toLowerCase().indexOf(search) !== -1;
        });

        // Sort
        filtered = filtered.slice().sort(function (a, b) {
            var aVal = a[hookSortCol] !== undefined ? a[hookSortCol] : 0;
            var bVal = b[hookSortCol] !== undefined ? b[hookSortCol] : 0;
            if (typeof aVal === 'string') {
                var cmp = aVal.localeCompare(bVal);
                return hookSortDir === 'asc' ? cmp : -cmp;
            }
            return hookSortDir === 'desc' ? bVal - aVal : aVal - bVal;
        });

        if (filtered.length === 0) {
            hooksTbody.innerHTML = '<tr><td colspan="5" class="cs-empty">'
                + '<span class="cs-empty-icon">&#128279;</span>'
                + (hooks.length === 0 ? 'No hooks captured.' : 'No hooks match the filter.')
                + '</td></tr>';
            return;
        }

        var maxMs = filtered.length > 0 ? filtered[0].total_ms : 1;
        var html  = '';
        filtered.forEach(function (h, i) {
            var barW = maxMs > 0 ? Math.max(2, Math.round((h.total_ms / maxMs) * 60)) : 2;
            var cls  = speedClass(h.max_ms);
            var rowCls = rowSpeedClass(h.max_ms);
            var hasCallbacks = h.callbacks && h.callbacks.length > 0;

            var tags = '';
            if (h.max_ms >= T_CRITICAL) tags += '<span class="cs-tag cs-tag-critical">critical</span> ';
            else if (h.max_ms >= T_SLOW) tags += '<span class="cs-tag cs-tag-slow">slow</span> ';

            html += '<tr class="' + rowCls + (hasCallbacks ? ' cs-expandable' : '') + '" data-hk-idx="' + i + '">'
                + '<td class="c-hk" title="' + esc(h.hook) + '">' + esc(h.hook)
                    + (tags ? '<br><span style="margin-top:2px;display:inline-block">' + tags + '</span>' : '')
                    + '</td>'
                + '<td class="c-hc" style="color:#888">' + h.count + '</td>'
                + '<td class="c-ht"><div class="cs-time-cell">'
                    + '<span class="cs-lat-bar cs-lat-' + cls + '" style="width:' + barW + 'px"></span>'
                    + '<span class="cs-time-val cs-tv-' + cls + '">' + fmtMs(h.total_ms) + '</span>'
                    + '</div></td>'
                + '<td class="c-hm cs-tv-' + speedClass(h.max_ms) + '">' + fmtMs(h.max_ms) + '</td>'
                + '</tr>';

            if (hasCallbacks) {
                html += '<tr class="cs-row-detail" id="cs-hkd-' + i + '" style="display:none">'
                    + '<td colspan="4">' + renderCallbacks(h.callbacks) + '</td></tr>';
            }
        });
        hooksTbody.innerHTML = html;

        Array.prototype.forEach.call(hooksTbody.querySelectorAll('tr.cs-expandable'), function (tr) {
            tr.addEventListener('click', function () {
                var d = document.getElementById('cs-hkd-' + tr.dataset.hkIdx);
                if (d) d.style.display = d.style.display === 'none' ? '' : 'none';
            });
        });
    }

    // ── Badges ────────────────────────────────────────────────────────────────
    function updateBadges() {
        badgeDB.querySelector('em').textContent   = meta.query_count || 0;
        badgeHTTP.querySelector('em').textContent = meta.http_count  || 0;
        var lc = (data.logs || []).length;
        if (lc > 0 && badgeLOG) { badgeLOG.style.display = ''; badgeLOG.querySelector('em').textContent = lc; }
        if (badgeISSUES) {
            var critCnt  = issuesList.filter(function (i) { return i.sev === 'critical'; }).length;
            var warnCnt  = issuesList.filter(function (i) { return i.sev === 'warning';  }).length;
            var issueCnt = critCnt + warnCnt;
            if (issueCnt > 0) {
                badgeISSUES.style.display = '';
                badgeISSUES.querySelector('em').textContent = issueCnt;
                badgeISSUES.className = 'cs-perf-badge ' + (critCnt > 0 ? 'cs-pb-issues-critical' : 'cs-pb-issues-warning');
            } else {
                badgeISSUES.style.display = 'none';
            }
        }
    }

    function updateTotalTime() {
        var parts = [];
        if (meta.query_count > 0) parts.push(meta.query_count + ' queries / ' + fmtMs(meta.query_total_ms));
        if (meta.http_count   > 0) parts.push(meta.http_count + ' HTTP / ' + fmtMs(meta.http_total_ms));
        if (meta.page_load_ms > 0) parts.push('Page: ' + fmtMs(meta.page_load_ms));
        totalTxt.textContent = parts.join('  ·  ');
    }

    // ── Filters ───────────────────────────────────────────────────────────────
    function applyFilters() {
        var search    = (searchInput.value || '').toLowerCase().trim();
        var plugin    = pluginSel.value;
        var threshold = parseInt(speedSel.value, 10) || 0;
        var dupeOnly  = dupeChk.checked;

        filteredDB = data.queries.filter(function (q) {
            if (plugin    && q.plugin  !== plugin)  return false;
            if (threshold && q.time_ms < threshold) return false;
            if (dupeOnly  && !q.is_dupe)            return false;
            if (search && q.sql.toLowerCase().indexOf(search)    === -1
                       && q.plugin.toLowerCase().indexOf(search) === -1
                       && q.caller.toLowerCase().indexOf(search) === -1) return false;
            return true;
        });

        filteredHTTP = data.http.filter(function (h) {
            if (plugin    && h.plugin !== plugin)   return false;
            if (threshold && h.time_ms < threshold) return false;
            if (search && h.url.toLowerCase().indexOf(search)    === -1
                       && h.plugin.toLowerCase().indexOf(search) === -1) return false;
            return true;
        });

        renderDB();
        renderHTTP();
        updateFooter();
        updateTabCounts();
    }

    function updateTabCounts() {
        dbCount.textContent   = filteredDB.length;
        httpCount.textContent = filteredHTTP.length;
        if (logCount)    logCount.textContent    = (data.logs || []).length;
        if (assetsCount) {
            var assets = data.assets || {};
            var aTotal = (assets.scripts || []).length + (assets.styles || []).length;
            assetsCount.textContent = aTotal;
            assetsCount.className   = aTotal > 40 ? 'cs-issues-cnt-critical' : aTotal > 20 ? 'cs-issues-cnt-warning' : '';
        }
        if (hooksCount)  hooksCount.textContent  = (data.hooks       || []).length;
        if (transCount)  transCount.textContent  = (data.transients  || []).length;

        var issuesCntEl = document.getElementById('cs-ptc-issues');
        if (issuesCntEl) {
            var critCnt = issuesList.filter(function (i) { return i.sev === 'critical'; }).length;
            var warnCnt = issuesList.filter(function (i) { return i.sev === 'warning';  }).length;
            var shown   = critCnt + warnCnt;
            issuesCntEl.textContent = shown;
            issuesCntEl.className   = critCnt > 0 ? 'cs-issues-cnt-critical'
                                    : warnCnt > 0 ? 'cs-issues-cnt-warning'
                                    : '';
        }
    }

    // ── Multi-column sort ─────────────────────────────────────────────────────
    function sortRows(rows) {
        return rows.slice().sort(function (a, b) {
            var aVal, bVal;
            if (sortCol === 'plugin') {
                aVal = (a.plugin || '').toLowerCase();
                bVal = (b.plugin || '').toLowerCase();
                var cmp = aVal.localeCompare(bVal);
                return sortDir === 'asc' ? cmp : -cmp;
            }
            if (sortCol === 'rows') {
                aVal = a.rows < 0 ? -1 : a.rows;
                bVal = b.rows < 0 ? -1 : b.rows;
            } else {
                // default: time
                aVal = a.time_ms;
                bVal = b.time_ms;
            }
            return sortDir === 'desc' ? bVal - aVal : aVal - bVal;
        });
    }

    function updateSortHeaders() {
        Array.prototype.forEach.call(document.querySelectorAll('.cs-sortable'), function (th) {
            var col = th.dataset.sort;
            th.classList.toggle('cs-sort-active', col === sortCol);
            var labels = { time: 'Time', plugin: 'Plugin', rows: 'Rows' };
            var arrow  = col !== sortCol ? '&#8597;' : (sortDir === 'desc' ? '&#8595;' : '&#8593;');
            th.innerHTML = (labels[col] || col) + '&nbsp;' + arrow;
        });
    }

    // ── DB table ──────────────────────────────────────────────────────────────
    function renderDB() {
        if (!dbTbody) return;

        if (!meta.savequeries_active) {
            dbTbody.innerHTML = '<tr><td colspan="5"><div class="cs-sq-warning">'
                + '<strong>&#9888; SAVEQUERIES is not active.</strong><br>'
                + 'Another plugin or wp-config.php defined <code>SAVEQUERIES</code> as <code>false</code> '
                + 'before this plugin loaded. Add <code>define(\'SAVEQUERIES\', true);</code> '
                + 'to wp-config.php to override.'
                + '</div></td></tr>';
            return;
        }

        if (filteredDB.length === 0) {
            dbTbody.innerHTML = '<tr><td colspan="5" class="cs-empty">'
                + '<span class="cs-empty-icon">&#128200;</span>'
                + (data.queries.length === 0 ? 'No queries captured on this page load.' : 'No queries match the current filters.')
                + '</td></tr>';
            return;
        }

        var sorted = sortRows(filteredDB);
        var maxMs  = Math.max.apply(null, sorted.map(function (q) { return q.time_ms; }));

        var html = '';
        sorted.forEach(function (q, i) {
            var rowN1    = isN1(q.sql);
            var rowClass = rowSpeedClass(q.time_ms) + (q.is_dupe ? ' cs-row-dupe' : '');
            var sqlShort = truncate(q.sql, 88);
            var rowsText = q.rows >= 0 ? q.rows : '–';
            var canExp   = /^(SELECT|SHOW|DESCRIBE)\b/i.test(q.sql);

            var tags = '';
            if (q.time_ms >= T_CRITICAL)  tags += '<span class="cs-tag cs-tag-critical">critical</span> ';
            else if (q.time_ms >= T_SLOW) tags += '<span class="cs-tag cs-tag-slow">slow</span> ';
            if (q.is_dupe)                tags += '<span class="cs-tag cs-tag-dupe">dupe</span> ';
            if (rowN1)                    tags += '<span class="cs-tag cs-tag-n1">N+1</span> ';

            html += '<tr class="cs-expandable ' + rowClass + '" data-idx="' + i + '">'
                + '<td class="c-n">' + (i + 1) + '</td>'
                + '<td class="c-q">'
                    + '<span class="' + kwColour(q.keyword) + '">' + esc(q.keyword) + '</span> '
                    + esc(sqlShort.replace(q.keyword, '').trimStart())
                    + (tags ? '<br><span style="margin-top:2px;display:inline-block">' + tags + '</span>' : '')
                    + (q.caller ? '<br><span style="color:#666;font-size:10px">' + esc(q.caller) + '</span>' : '')
                + '</td>'
                + '<td class="c-p">' + pluginChip(q.plugin) + '</td>'
                + '<td class="c-r" style="color:#888">' + rowsText + '</td>'
                + '<td class="c-t">' + timeCell(q.time_ms, maxMs) + '</td>'
                + '</tr>'
                // Detail row — full SQL, call chain, EXPLAIN button
                + '<tr class="cs-row-detail" id="cs-dr-' + i + '" style="display:none"><td colspan="5">'
                    + esc(q.sql)
                    + renderCallStack(q.stack || [])
                    + (canExp
                        ? '<br><button class="cs-explain-btn" data-sql="' + esc(q.sql) + '" data-row="' + i + '">EXPLAIN</button>'
                        + '<div id="cs-exp-' + i + '" class="cs-explain-result"></div>'
                        : '')
                + '</td></tr>';
        });

        dbTbody.innerHTML = html;

        Array.prototype.forEach.call(dbTbody.querySelectorAll('tr.cs-expandable'), function (tr) {
            tr.addEventListener('click', function () {
                var d = document.getElementById('cs-dr-' + tr.dataset.idx);
                if (d) d.style.display = d.style.display === 'none' ? '' : 'none';
            });
        });

        Array.prototype.forEach.call(dbTbody.querySelectorAll('.cs-explain-btn'), function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                runExplain(btn.getAttribute('data-sql'), btn.getAttribute('data-row'), btn);
            });
        });
    }

    // ── Call stack renderer ───────────────────────────────────────────────────
    /**
     * Renders the SAVEQUERIES call-chain array as a visual trace.
     *
     * Frame types:
     *   hook   — teal  — do_action / apply_filters (WP entry point)
     *   plugin — blue  — require from wp-content/plugins/
     *   theme  — purple— require from wp-content/themes/
     *   code   — white — application function / class method
     *   wp     — grey  — WP_Hook, call_user_func etc.
     *   file   — grey  — require from wp core
     *   db     — dark  — wpdb (implicit, badge hidden)
     */
    function renderCallStack(stack) {
        if (!stack || stack.length === 0) return '';

        // Drop trailing db/wp frames — they add no developer-relevant info
        var frames = stack.slice();
        while (frames.length > 0 && (frames[frames.length - 1].type === 'db' || frames[frames.length - 1].type === 'wp')) {
            frames.pop();
        }
        // Also drop leading db frames
        while (frames.length > 0 && frames[0].type === 'db') frames.shift();

        if (frames.length === 0) return '';

        var html = '<div class="cs-stack-trace">'
            + '<div class="cs-stack-hdr">Call chain — ' + frames.length + ' frames (most recent first)</div>'
            + '<div class="cs-stack-frames">';

        frames.forEach(function (f) {
            var typeLabel = f.type === 'plugin' ? 'plugin' :
                            f.type === 'theme'  ? 'theme'  :
                            f.type === 'hook'   ? 'hook'   :
                            f.type === 'code'   ? 'fn'     :
                            f.type === 'file'   ? 'core'   :
                            f.type === 'wp'     ? 'wp'     : '';

            html += '<div class="cs-sf cs-sf-' + esc(f.type) + '">'
                + '<span class="cs-sf-name" title="' + esc(f.frame) + '">' + esc(truncate(f.frame, 90)) + '</span>'
                + (typeLabel ? '<span class="cs-sf-type">' + typeLabel + '</span>' : '')
                + '</div>';
        });

        return html + '</div></div>';
    }

    // ── Hook callbacks renderer ───────────────────────────────────────────────
    function renderCallbacks(callbacks) {
        if (!callbacks || callbacks.length === 0) return '';
        var html = '<div class="cs-stack-trace">'
            + '<div class="cs-stack-hdr">Registered callbacks — ' + callbacks.length + '</div>'
            + '<div class="cs-stack-frames">';
        callbacks.forEach(function (cb) {
            var isCore = !cb.plugin || cb.plugin === 'WordPress Core';
            html += '<div class="cs-sf cs-sf-' + (isCore ? 'wp' : 'plugin') + '">'
                + '<span class="cs-sf-name" title="' + esc(cb.label) + '">' + esc(cb.label) + '</span>'
                + '<span class="cs-sf-type">p' + cb.priority + '</span>'
                + (isCore
                    ? '<span style="color:#666;font-size:10px">core</span>'
                    : pluginChip(cb.plugin))
                + '</div>';
        });
        return html + '</div></div>';
    }

    // ── EXPLAIN AJAX ──────────────────────────────────────────────────────────
    function runExplain(sql, rowIdx, btn) {
        var resultDiv = document.getElementById('cs-exp-' + rowIdx);
        if (!resultDiv) return;
        btn.disabled = true; btn.textContent = 'Loading\u2026';
        resultDiv.innerHTML = '<div class="cs-explain-loading">Running EXPLAIN\u2026</div>';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', meta.ajax_url || '/wp-admin/admin-ajax.php');
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function () {
            btn.disabled = false; btn.textContent = 'EXPLAIN';
            try {
                var resp = JSON.parse(xhr.responseText);
                resultDiv.innerHTML = resp.success
                    ? renderExplainTable(resp.data.rows)
                    : '<div class="cs-explain-error">' + esc(resp.data) + '</div>';
            } catch (e) { resultDiv.innerHTML = '<div class="cs-explain-error">Parse error</div>'; }
        };
        xhr.onerror = function () {
            btn.disabled = false; btn.textContent = 'EXPLAIN';
            resultDiv.innerHTML = '<div class="cs-explain-error">Request failed</div>';
        };
        xhr.send('action=csdt_devtools_perf_explain&nonce=' + encodeURIComponent(meta.explain_nonce || '')
                 + '&sql=' + encodeURIComponent(sql));
    }

    function renderExplainTable(rows) {
        if (!rows || rows.length === 0) return '<div class="cs-explain-empty">No rows returned.</div>';
        var cols = Object.keys(rows[0]);
        var html = '<table class="cs-explain-table"><thead><tr>';
        cols.forEach(function (c) { html += '<th>' + esc(c) + '</th>'; });
        html += '</tr></thead><tbody>';
        rows.forEach(function (row) {
            html += '<tr>';
            cols.forEach(function (c) {
                var val = row[c] !== null && row[c] !== undefined ? String(row[c]) : 'NULL';
                var cls = '';
                if (c === 'type') {
                    cls = val === 'ALL' ? 'cs-exp-bad' : val === 'index' ? 'cs-exp-warn'
                        : ['ref','eq_ref','const','system','range'].indexOf(val) >= 0 ? 'cs-exp-good' : '';
                } else if (c === 'rows')  { var n = parseInt(val,10); cls = n > 10000 ? 'cs-exp-bad' : n > 1000 ? 'cs-exp-warn' : ''; }
                  else if (c === 'key'   && val === 'NULL') cls = 'cs-exp-bad';
                  else if (c === 'Extra' && val.indexOf('Using filesort') !== -1) cls = 'cs-exp-warn';
                html += '<td class="' + cls + '">' + esc(val) + '</td>';
            });
            html += '</tr>';
        });
        return html + '</tbody></table>';
    }

    // ── HTTP table ────────────────────────────────────────────────────────────
    function renderHTTP() {
        if (!httpTbody) return;
        if (filteredHTTP.length === 0) {
            httpTbody.innerHTML = '<tr><td colspan="6" class="cs-empty">'
                + '<span class="cs-empty-icon">&#127760;</span>'
                + (data.http.length === 0 ? 'No outbound HTTP calls.' : 'No calls match the filters.')
                + '</td></tr>';
            return;
        }
        var sorted = filteredHTTP.slice().sort(function (a, b) {
            return sortDir === 'desc' ? b.time_ms - a.time_ms : a.time_ms - b.time_ms;
        });
        var maxMs = Math.max.apply(null, sorted.map(function (h) { return h.time_ms; }));
        var html  = '';
        sorted.forEach(function (h, i) {
            var tags = '';
            if (h.time_ms >= T_CRITICAL)  tags += '<span class="cs-tag cs-tag-critical">critical</span> ';
            else if (h.time_ms >= T_SLOW) tags += '<span class="cs-tag cs-tag-slow">slow</span> ';
            if (h.cached)   tags += '<span class="cs-tag cs-tag-cached">cached</span> ';
            if (h.error)    tags += '<span class="cs-tag cs-tag-error">error</span> ';
            if (h.insecure) tags += '<span class="cs-tag cs-tag-insecure">http</span> ';
            if (h.external) tags += '<span class="cs-tag cs-tag-external">external</span> ';

            html += '<tr class="cs-expandable ' + rowSpeedClass(h.time_ms) + '" data-idx-h="' + i + '">'
                + '<td class="c-n">' + (i + 1) + '</td>'
                + '<td class="c-m">' + methodBadge(h.method) + '</td>'
                + '<td class="c-u" title="' + esc(h.url) + '">'
                    + esc(truncateUrl(h.url, 60))
                    + (tags ? '<br><span style="margin-top:2px;display:inline-block">' + tags + '</span>' : '')
                + '</td>'
                + '<td class="c-p">' + pluginChip(h.plugin) + '</td>'
                + '<td class="c-s">' + statusBadge(h.status, h.error) + '</td>'
                + '<td class="c-t">' + timeCell(h.time_ms, maxMs) + '</td>'
                + '</tr>'
                + '<tr class="cs-row-detail" id="cs-hr-' + i + '" style="display:none"><td colspan="6">'
                + esc(h.url) + (h.error ? '\n\nError: ' + h.error : '') + '</td></tr>';
        });
        httpTbody.innerHTML = html;
        Array.prototype.forEach.call(httpTbody.querySelectorAll('tr.cs-expandable'), function (tr) {
            tr.addEventListener('click', function () {
                var d = document.getElementById('cs-hr-' + tr.dataset.idxH);
                if (d) d.style.display = d.style.display === 'none' ? '' : 'none';
            });
        });
    }

    // ── Debug bar ─────────────────────────────────────────────────────────────
    function renderDebugBar() {
        var statusEl = document.getElementById('cs-debug-status');
        var btnEl    = document.getElementById('cs-debug-toggle');
        if (!statusEl || !btnEl) return;
        var on = meta.wp_debug && meta.wp_debug_log;
        statusEl.textContent    = on ? '● Debug logging ON' : '○ Debug logging OFF';
        statusEl.className      = 'cs-debug-status ' + (on ? 'cs-debug-on' : 'cs-debug-off');
        btnEl.textContent       = on ? 'Disable' : 'Enable debug logging';
        btnEl.className         = 'cs-debug-toggle-btn ' + (on ? 'cs-debug-btn-off' : 'cs-debug-btn-on');
    }

    // ── Logs ──────────────────────────────────────────────────────────────────
    function renderLogs() {
        if (!logList) return;
        var logs    = data.logs || [];
        var search  = logSearch  ? logSearch.value.toLowerCase()  : '';
        var level   = logLevel   ? logLevel.value.toLowerCase()   : '';
        var source  = logSource  ? logSource.value                : '';

        var filtered = logs.filter(function (e) {
            if (level  && (e.level  || '').toLowerCase().indexOf(level)  === -1) return false;
            if (source && (e.source || '') !== source) return false;
            if (search && (e.message || '').toLowerCase().indexOf(search) === -1
                       && (e.ts || '').toLowerCase().indexOf(search) === -1) return false;
            return true;
        });

        if (filtered.length === 0) {
            logList.innerHTML = logs.length === 0
                ? '<div class="cs-empty"><span class="cs-empty-icon">&#9989;</span>No log entries found. Enable WP_DEBUG and WP_DEBUG_LOG in wp-config.php to capture logs.</div>'
                : '<div class="cs-empty"><span class="cs-empty-icon">&#128269;</span>No log entries match the current filters.</div>';
            return;
        }

        logList.innerHTML = filtered.map(function (e) {
            var lvl = (e.level || 'info').toLowerCase();
            var levelClass = lvl.indexOf('fatal') !== -1   ? 'cs-log-fatal'
                           : lvl.indexOf('error') !== -1   ? 'cs-log-error'
                           : lvl.indexOf('warn')  !== -1   ? 'cs-log-warning'
                           : lvl.indexOf('dep')   !== -1   ? 'cs-log-deprecated'
                           : lvl.indexOf('notic') !== -1   ? 'cs-log-notice'
                           : 'cs-log-info';
            var srcLabel = e.source === 'php_handler' ? '<span class="cs-log-src-tag">this request</span>' : '';
            return '<div class="cs-log-entry ' + levelClass + '">'
                + '<span class="cs-log-level">' + esc(e.level || 'info') + '</span>'
                + srcLabel
                + (e.ts ? '<span class="cs-log-ts">' + esc(e.ts) + '</span>' : '')
                + '<div class="cs-log-msg">' + esc(e.message || '') + '</div>'
                + '</div>';
        }).join('');
    }

    // ── Issues tab ────────────────────────────────────────────────────────────
    function computeIssues() {
        issuesList = [];

        // Critical & slow queries
        data.queries.forEach(function (q) {
            if (q.time_ms >= T_CRITICAL) {
                issuesList.push({ sev: 'critical', tab: 'db',
                    title: 'Critical query — ' + fmtMs(q.time_ms),
                    detail: truncate(q.sql, 110), plugin: q.plugin });
            } else if (q.time_ms >= T_SLOW) {
                issuesList.push({ sev: 'warning', tab: 'db',
                    title: 'Slow query — ' + fmtMs(q.time_ms),
                    detail: truncate(q.sql, 110), plugin: q.plugin });
            }
        });

        // N+1 patterns (already computed)
        // Callers that are WordPress core internals — un-fixable without a persistent
        // object cache (Redis/APCu/SQLite). Show as info, not warning.
        var WP_CORE_N1_CALLERS = [
            'create_initial_post_types',
            'load_default_textdomain',
            'wp_count_comments',
            'wp_site_icon',
            'register_post_type',
            'wp_set_script_translations',
        ];
        Object.keys(n1Patterns).forEach(function (k) {
            var p = n1Patterns[k];
            var detail = truncate(normalisePattern(p.example), 90);
            if (p.caller) detail += ' — caller: ' + truncate(p.caller, 50);
            var isWpCore = p.caller && WP_CORE_N1_CALLERS.some(function (c) {
                return p.caller.toLowerCase().indexOf(c.toLowerCase()) !== -1;
            });
            if (isWpCore) detail += ' (→ WP core — persistent object cache required)';
            issuesList.push({ sev: isWpCore ? 'info' : 'warning', tab: 'db',
                title: 'N+1 pattern — ' + p.count + ' identical calls — ' + fmtMs(p.total_ms) + ' total',
                detail: detail, plugin: p.plugin });
        });

        // Duplicate queries — one entry per group
        var dupeMap = {};
        data.queries.forEach(function (q) {
            if (!q.is_dupe) return;
            var fp = q.sql.replace(/\s+/g, ' ').toLowerCase().trim();
            if (!dupeMap[fp]) dupeMap[fp] = { sql: q.sql, count: 1, plugin: q.plugin };
            else dupeMap[fp].count++;
        });
        Object.keys(dupeMap).forEach(function (k) {
            var g = dupeMap[k];
            issuesList.push({ sev: 'warning', tab: 'db',
                title: 'Duplicate query — ' + g.count + ' extra calls',
                detail: truncate(g.sql, 110), plugin: g.plugin });
        });

        // HTTP security flags
        data.http.forEach(function (h) {
            if (h.insecure) {
                issuesList.push({ sev: 'warning', tab: 'http',
                    title: 'Insecure HTTP call — plain http:// (not HTTPS)',
                    detail: truncateUrl(h.url, 110), plugin: h.plugin });
            }
        });

        // HTTP errors + slow HTTP
        data.http.forEach(function (h) {
            if (h.error || (h.status && h.status >= 400)) {
                issuesList.push({ sev: 'critical', tab: 'http',
                    title: 'HTTP error' + (h.status ? ' ' + h.status : '') + (h.error ? ' — ' + h.error : '') + ' — ' + fmtMs(h.time_ms),
                    detail: truncateUrl(h.url, 110), plugin: h.plugin });
            } else if (h.time_ms >= T_SLOW) {
                issuesList.push({ sev: 'warning', tab: 'http',
                    title: 'Slow HTTP — ' + fmtMs(h.time_ms),
                    detail: truncateUrl(h.url, 110), plugin: h.plugin });
            }
        });

        // PHP errors / log entries
        (data.logs || []).forEach(function (e) {
            var lvl = (e.level || '').toLowerCase();
            var isFatal = lvl.indexOf('fatal') !== -1;
            var isError = lvl.indexOf('error') !== -1;
            var isWarn  = lvl.indexOf('warn') !== -1 || lvl.indexOf('dep') !== -1;
            if (isFatal || (isError && e.source === 'php_handler')) {
                issuesList.push({ sev: 'critical', tab: 'logs',
                    title: (e.level || 'Error') + (e.source === 'php_handler' ? ' — this request' : ''),
                    detail: truncate(e.message, 110), plugin: '' });
            } else if (isError || isWarn) {
                issuesList.push({ sev: 'warning', tab: 'logs',
                    title: e.level || 'Warning',
                    detail: truncate(e.message, 110), plugin: '' });
            }
        });

        // Object cache health
        var cache = data.cache || {};
        if (cache.available && cache.hit_rate !== null) {
            if (cache.hit_rate < 30) {
                issuesList.push({ sev: 'critical', tab: 'summary',
                    title: 'Object cache hit rate ' + cache.hit_rate + '% — critically low',
                    detail: cache.hits + ' hits / ' + cache.misses + ' misses', plugin: '' });
            } else if (cache.hit_rate < 60) {
                issuesList.push({ sev: 'warning', tab: 'summary',
                    title: 'Object cache hit rate ' + cache.hit_rate + '% — below 60%',
                    detail: cache.hits + ' hits / ' + cache.misses + ' misses', plugin: '' });
            }
        }
        if (cache.available && !cache.persistent) {
            issuesList.push({ sev: 'info', tab: 'summary',
                title: 'No persistent object cache',
                detail: 'Redis or Memcached would reduce database load significantly', plugin: '' });
        }

        // Debug logging
        if (!meta.wp_debug || !meta.wp_debug_log) {
            issuesList.push({ sev: 'info', tab: 'logs',
                title: 'Debug logging is off',
                detail: 'Enable WP_DEBUG + WP_DEBUG_LOG in wp-config.php to capture PHP errors here', plugin: '' });
        }

        // Slow / critical hooks
        (data.hooks || []).forEach(function (h) {
            var avgPart = h.avg_ms ? ' · ' + fmtMs(h.avg_ms) + ' avg' : '';
            var cbPart  = '';
            if (h.callbacks && h.callbacks.length > 0) {
                var seen = {}, labels = [];
                h.callbacks.forEach(function (c) {
                    var key = c.plugin || c.label || '';
                    if (key && !seen[key]) { seen[key] = true; labels.push(key); }
                });
                if (labels.length > 0) cbPart = ' · ' + labels.slice(0, 3).join(', ');
            }
            var hookDetail = h.count + ' fires · ' + fmtMs(h.total_ms) + ' total' + avgPart + cbPart;
            if (h.max_ms >= T_CRITICAL) {
                issuesList.push({ sev: 'critical', tab: 'hooks',
                    title: 'Critical hook — ' + h.hook + ' — max ' + fmtMs(h.max_ms),
                    detail: hookDetail, plugin: '' });
            } else if (h.max_ms >= T_SLOW) {
                issuesList.push({ sev: 'warning', tab: 'hooks',
                    title: 'Slow hook — ' + h.hook + ' — max ' + fmtMs(h.max_ms),
                    detail: hookDetail, plugin: '' });
            }
        });

        // Asset bloat
        var assets = data.assets || {};
        var totalAssets = (assets.scripts || []).length + (assets.styles || []).length;
        if (totalAssets > 40) {
            issuesList.push({ sev: 'warning', tab: 'assets',
                title: 'Heavy asset load — ' + totalAssets + ' scripts/styles enqueued',
                detail: (assets.scripts || []).length + ' JS · ' + (assets.styles || []).length + ' CSS — consider consolidating or deferring', plugin: '' });
        } else if (totalAssets > 20) {
            issuesList.push({ sev: 'info', tab: 'assets',
                title: 'Asset count: ' + totalAssets + ' scripts/styles enqueued',
                detail: (assets.scripts || []).length + ' JS · ' + (assets.styles || []).length + ' CSS', plugin: '' });
        }

        // ── Site health checks ────────────────────────────────────────────────
        var health = data.health || {};

        // WP_DEBUG_DISPLAY on — PHP errors shown to all visitors
        if (health.wp_debug_display) {
            issuesList.push({ sev: 'critical', tab: 'summary',
                title: 'WP_DEBUG_DISPLAY is on — PHP errors exposed to all visitors',
                detail: 'Set define(\'WP_DEBUG_DISPLAY\', false) in wp-config.php', plugin: '' });
        }

        // Site not HTTPS
        if (health.site_https === false) {
            issuesList.push({ sev: 'critical', tab: 'summary',
                title: 'Site is not HTTPS',
                detail: 'home_url() starts with http:// — auth cookies and data sent unencrypted', plugin: '' });
        }

        // Autoloaded options bloat
        if (health.autoload_kb >= 1500) {
            var alTop = (health.large_autoloads || []).slice(0, 3).map(function (o) { return o.name + ' (' + o.size_kb + ' KB)'; }).join(', ');
            issuesList.push({ sev: 'critical', tab: 'summary',
                title: 'Autoloaded options: ' + health.autoload_kb + ' KB loaded on every page request',
                detail: alTop || health.autoload_count + ' options', plugin: '' });
        } else if (health.autoload_kb >= 600) {
            var alTop = (health.large_autoloads || []).slice(0, 3).map(function (o) { return o.name + ' (' + o.size_kb + ' KB)'; }).join(', ');
            issuesList.push({ sev: 'warning', tab: 'summary',
                title: 'Autoloaded options: ' + health.autoload_kb + ' KB — consider auditing',
                detail: alTop || health.autoload_count + ' options', plugin: '' });
        }

        // WP-Cron backlog
        if (health.cron_overdue >= 10) {
            var cronHooks = (health.cron_overdue_list || []).slice(0, 3).map(function (e) { return e.hook; }).join(', ');
            issuesList.push({ sev: 'warning', tab: 'summary',
                title: health.cron_overdue + ' overdue cron events — WP-Cron may be backed up',
                detail: cronHooks, plugin: '' });
        } else if (health.cron_overdue > 0) {
            var cronHooks = (health.cron_overdue_list || []).map(function (e) { return e.hook; }).join(', ');
            issuesList.push({ sev: 'info', tab: 'summary',
                title: health.cron_overdue + ' overdue cron event' + (health.cron_overdue > 1 ? 's' : ''),
                detail: cronHooks, plugin: '' });
        }

        // File editing via wp-admin not locked down
        if (health.disallow_file_edit === false) {
            issuesList.push({ sev: 'warning', tab: 'summary',
                title: 'wp-admin code editor is enabled (DISALLOW_FILE_EDIT not set)',
                detail: 'Any admin can edit plugin/theme PHP files — add define(\'DISALLOW_FILE_EDIT\', true) to wp-config.php', plugin: '' });
        }

        // Plugin/theme installs not locked down (lower priority)
        if (health.disallow_file_mods === false) {
            issuesList.push({ sev: 'info', tab: 'summary',
                title: 'DISALLOW_FILE_MODS not set — plugin/theme installs allowed',
                detail: 'Add define(\'DISALLOW_FILE_MODS\', true) to wp-config.php for hardened servers', plugin: '' });
        }

        // "admin" username exists — prime brute-force target
        if (health.admin_user_exists) {
            var bfOn  = health.brute_force_enabled;
            var bfSev = bfOn ? 'warning' : 'critical';
            var bfDetail = 'Rename in Users → Profile. Transfer content first, then delete the old account.'
                + (bfOn
                    ? ' Brute-force protection is ON (mitigating risk), but renaming is still strongly recommended.'
                    : ' ⚠ Brute-force protection is OFF — enable it in DevTools → Login Security to limit attack exposure.');
            issuesList.push({ sev: bfSev, tab: 'summary',
                title: 'Username "admin" exists — prime brute-force target',
                detail: bfDetail, plugin: '' });
        }

        // Default wp_ table prefix
        if (health.db_prefix_default) {
            issuesList.push({ sev: 'warning', tab: 'summary',
                title: 'Default wp_ database prefix — easier to exploit in SQL injection',
                detail: 'Change prefix if recently set up; requires a full DB backup on existing sites', plugin: '' });
        }

        // XML-RPC enabled — standalone warning; escalates to critical below if brute force is active
        if (health.xmlrpc_enabled) {
            issuesList.push({ sev: 'warning', tab: 'summary',
                title: 'XML-RPC enabled — brute-force amplification vector',
                detail: "system.multicall allows 100s of password attempts per request — disable via add_filter('xmlrpc_enabled','__return_false')", plugin: '' });
        }

        // XML-RPC + active brute force — both signals together mean the attack is already happening
        if (health.xmlrpc_enabled && health.failed_logins_1h >= 5) {
            issuesList.push({ sev: 'critical', tab: 'summary',
                title: 'Active XML-RPC brute force — ' + health.failed_logins_1h + ' failed logins/hour with xmlrpc.php enabled',
                detail: 'Disable immediately: add_filter("xmlrpc_enabled","__return_false") in a mu-plugin, or block /xmlrpc.php at Nginx/Cloudflare WAF.', plugin: '' });
        }

        // readme.html / license.txt expose WP version
        if (health.readme_exposed || health.license_exposed) {
            var expFiles = [health.readme_exposed ? 'readme.html' : '', health.license_exposed ? 'license.txt' : ''].filter(Boolean).join(', ');
            issuesList.push({ sev: 'info', tab: 'summary',
                title: 'WP version disclosed via ' + expFiles,
                detail: 'Delete these files or block via server config to prevent version fingerprinting', plugin: '' });
        }

        // PHP EOL
        if (health.php_eol) {
            issuesList.push({ sev: 'critical', tab: 'summary',
                title: 'PHP ' + meta.php_version + ' is end-of-life — no security patches',
                detail: 'Upgrade to PHP 8.3 or 8.4 — all PHP < 8.2 reached end-of-life', plugin: '' });
        } else if (health.php_old) {
            issuesList.push({ sev: 'warning', tab: 'summary',
                title: 'PHP ' + meta.php_version + ' reaches end-of-life December 2026',
                detail: 'Plan upgrade to PHP 8.3+ — approaching end of security support', plugin: '' });
        }

        // WordPress core outdated
        if (health.wp_update_available) {
            issuesList.push({ sev: 'warning', tab: 'summary',
                title: 'WordPress update available — running ' + (meta.wp_version || '?') + ', latest is ' + (health.wp_latest_version || 'a newer version'),
                detail: 'Outdated WP core leaves known CVEs unpatched. Update via Dashboard → Updates or: wp core update', plugin: '' });
        }

        // MySQL / MariaDB version EOL
        if (meta.mysql_version) {
            var dbParts = meta.mysql_version.split('.').map(Number);
            var dbMaj = dbParts[0] || 0, dbMin = dbParts[1] || 0;
            var dbIsMariaDB = !!health.is_mariadb;
            var dbEolCrit = false, dbEolWarn = false, dbEolLabel = '';
            if (dbIsMariaDB) {
                if (dbMaj === 10 && dbMin <= 4)  { dbEolCrit = true;  dbEolLabel = 'EOL Jun 2024'; }
                else if (dbMaj === 10 && dbMin === 5) { dbEolCrit = true;  dbEolLabel = 'EOL Jun 2025'; }
                else if (dbMaj === 10 && dbMin === 6) { dbEolWarn = true; dbEolLabel = 'EOL Jul 2026'; }
                else if (dbMaj === 10 && dbMin < 11) { dbEolWarn = true; dbEolLabel = 'approaching EOL'; }
                // 10.11 LTS, 11.x — OK
            } else {
                if (dbMaj < 8)                    { dbEolCrit = true;  dbEolLabel = 'EOL — no security patches'; }
                else if (dbMaj === 8 && dbMin === 0) { dbEolWarn = true; dbEolLabel = 'EOL Apr 2026'; }
                // 8.4 LTS — OK
            }
            var dbName = dbIsMariaDB ? 'MariaDB' : 'MySQL';
            var dbTarget = dbIsMariaDB ? 'MariaDB 10.11 LTS or 11.4 LTS' : 'MySQL 8.4 LTS';
            if (dbEolCrit) {
                issuesList.push({ sev: 'critical', tab: 'summary',
                    title: dbName + ' ' + meta.mysql_version + ' is end-of-life — ' + dbEolLabel,
                    detail: 'No security patches available. Upgrade to ' + dbTarget + '.', plugin: '' });
            } else if (dbEolWarn) {
                issuesList.push({ sev: 'warning', tab: 'summary',
                    title: dbName + ' ' + meta.mysql_version + ' — ' + dbEolLabel,
                    detail: 'Plan upgrade to ' + dbTarget + ' before end-of-life.', plugin: '' });
            }
        }

        // Nginx version EOL check
        if (health.nginx_version) {
            var ngParts = health.nginx_version.split('.').map(Number);
            var ngMaj = ngParts[0] || 0, ngMin = ngParts[1] || 0;
            // Stable branch history: 1.26 (Apr 2024), 1.24 (Apr 2023 EOL), 1.22 EOL, ≤1.20 very old
            if (ngMaj < 1 || (ngMaj === 1 && ngMin < 20)) {
                issuesList.push({ sev: 'critical', tab: 'summary',
                    title: 'Nginx ' + health.nginx_version + ' is very old and unmaintained',
                    detail: 'Update to Nginx 1.26 (stable) or 1.27 (mainline). Old versions have known unpatched CVEs.', plugin: '' });
            } else if (ngMaj === 1 && ngMin < 26) {
                issuesList.push({ sev: 'warning', tab: 'summary',
                    title: 'Nginx ' + health.nginx_version + ' is outdated — stable is 1.26',
                    detail: 'Update to Nginx 1.26 (stable). Older stable branches no longer receive security patches.', plugin: '' });
            }
        }

        // Failed logins — brute-force signal (analogous to fail2ban for SSH)
        if (health.failed_logins_1h >= 10) {
            issuesList.push({ sev: 'critical', tab: 'summary',
                title: health.failed_logins_1h + ' failed logins in the last hour — active brute force',
                detail: health.failed_logins_24h + ' in 24 h — block source IP in Cloudflare or enforce 2FA', plugin: '' });
        } else if (health.failed_logins_1h >= 3) {
            issuesList.push({ sev: 'warning', tab: 'summary',
                title: health.failed_logins_1h + ' failed login attempts in the last hour',
                detail: health.failed_logins_24h + ' in 24 h', plugin: '' });
        } else if (health.failed_logins_24h >= 10) {
            issuesList.push({ sev: 'info', tab: 'summary',
                title: health.failed_logins_24h + ' failed login attempts in the last 24 hours',
                detail: 'No acute spike, but sustained probing detected', plugin: '' });
        }

        // Memory pressure — data lives in meta, not health
        var memLimitMb = parseInt(meta.memory_limit, 10) || 0;
        var memUsagePct = (memLimitMb > 0 && meta.memory_peak_mb) ? Math.round((meta.memory_peak_mb / memLimitMb) * 100) : 0;
        if (memUsagePct >= 90) {
            issuesList.push({ sev: 'critical', tab: 'summary',
                title: 'Memory critical: ' + meta.memory_peak_mb + 'MB peak (' + memUsagePct + '% of ' + meta.memory_limit + ' limit)',
                detail: 'PHP process is near memory_limit — OOM fatal errors likely. Increase limit or profile allocations.', plugin: '' });
        } else if (memUsagePct >= 75) {
            issuesList.push({ sev: 'warning', tab: 'summary',
                title: 'Elevated memory usage: ' + meta.memory_peak_mb + 'MB peak (' + memUsagePct + '% of ' + meta.memory_limit + ' limit)',
                detail: 'Approaching PHP memory_limit — audit heavy plugins or raise the limit in php.ini / wp-config.php.', plugin: '' });
        }

        // System load average — CPU pressure (Unix only; absent on Windows/shared hosting)
        var loadAvg  = health.load_avg  || [];
        var cpuCount = health.cpu_count || 1;
        if (loadAvg.length >= 1) {
            var load1m = loadAvg[0];
            if (load1m >= cpuCount * 2) {
                issuesList.push({ sev: 'critical', tab: 'summary',
                    title: 'System overloaded — load average ' + load1m + ' (' + cpuCount + '-CPU server)',
                    detail: 'Load >2× CPU count. Check for xmlrpc.php flood, WP-Cron pile-up, or stuck PHP-FPM workers.', plugin: '' });
            } else if (load1m >= cpuCount * 1.5) {
                issuesList.push({ sev: 'warning', tab: 'summary',
                    title: 'High CPU load — load average ' + load1m + ' (' + cpuCount + '-CPU server)',
                    detail: 'Load >1.5× CPU count — request spike, heavy background job, or resource-intensive plugin.', plugin: '' });
            }
        }

        // Disk space
        if (health.disk_pct_used !== null && health.disk_pct_used !== undefined) {
            if (health.disk_pct_used >= 95) {
                issuesList.push({ sev: 'critical', tab: 'summary',
                    title: 'Disk almost full — ' + health.disk_pct_used + '% used (' + health.disk_free_gb + ' GB free)',
                    detail: 'Site stops working when disk is full — uploads fail, logs stop writing, sessions break. Free space immediately.', plugin: '' });
            } else if (health.disk_pct_used >= 85) {
                issuesList.push({ sev: 'warning', tab: 'summary',
                    title: 'Low disk space — ' + health.disk_pct_used + '% used (' + health.disk_free_gb + ' GB free)',
                    detail: 'Approaching full. Check for large log files, old backups, and unused media uploads.', plugin: '' });
            }
        }

        // PHP OPcache
        var oc = health.opcache || null;
        if (oc !== null) {
            if (oc.enabled === false) {
                issuesList.push({ sev: 'warning', tab: 'summary',
                    title: 'PHP OPcache disabled — every request recompiles all PHP files',
                    detail: 'Enable in php.ini: opcache.enable=1, opcache.memory_consumption=128. Can cut CPU usage and TTFB by 30–50%.', plugin: '' });
            } else if (oc.enabled) {
                if (oc.oom_restarts > 0) {
                    issuesList.push({ sev: 'critical', tab: 'summary',
                        title: 'OPcache ran out of memory — ' + oc.oom_restarts + ' OOM restart' + (oc.oom_restarts > 1 ? 's' : '') + ' recorded',
                        detail: 'Cache was cleared under memory pressure — causes CPU spikes. Increase opcache.memory_consumption to 128–256MB.', plugin: '' });
                }
                if (oc.mem_pct >= 90) {
                    issuesList.push({ sev: 'warning', tab: 'summary',
                        title: 'OPcache memory at ' + oc.mem_pct + '% — ' + oc.used_mb + 'MB used',
                        detail: 'Cache close to full. Increase opcache.memory_consumption in php.ini before it triggers OOM restarts.', plugin: '' });
                }
                var ocTotal = oc.total_requests || 0;
                if (oc.hit_rate < 85 && ocTotal > 200) {
                    issuesList.push({ sev: 'warning', tab: 'summary',
                        title: 'Low OPcache hit rate: ' + oc.hit_rate + '% — PHP files recompiled frequently',
                        detail: 'Memory may be too small for all cached scripts, or a plugin is invalidating the cache on every request. (' + ocTotal + ' requests sampled)', plugin: '' });
                } else if (oc.hit_rate < 85 && ocTotal > 0) {
                    issuesList.push({ sev: 'info', tab: 'summary',
                        title: 'OPcache warming up: ' + oc.hit_rate + '% hit rate (' + ocTotal + ' requests so far)',
                        detail: 'Hit rate is low because the cache is still filling after a recent restart. It will reach 95%+ after a few hundred requests.', plugin: '' });
                }
            }
        }

        // Uploads directory writable
        if (health.uploads_writable === false) {
            issuesList.push({ sev: 'critical', tab: 'summary',
                title: 'Uploads directory not writable — media uploads will fail silently',
                detail: 'Fix permissions on wp-content/uploads: chmod 755 (or 775 with www-data group ownership).', plugin: '' });
        }

        // PHP upload and execution limits
        function parsePhpSizeMb(s) {
            if (!s) return 0;
            var n = parseFloat(s);
            var u = String(s).slice(-1).toUpperCase();
            if (u === 'G') return n * 1024;
            if (u === 'K') return n / 1024;
            return n; // M or bare number treated as MB
        }
        var uploadMb  = parsePhpSizeMb(health.php_upload_max);
        var postMb    = parsePhpSizeMb(health.php_post_max);
        var maxExec   = health.php_max_exec || 0;
        if (uploadMb > 0 && uploadMb < 8) {
            issuesList.push({ sev: 'warning', tab: 'summary',
                title: 'upload_max_filesize is ' + health.php_upload_max + ' — large media uploads will fail',
                detail: 'Raise to at least 64M in php.ini or .htaccess: php_value upload_max_filesize 64M', plugin: '' });
        }
        if (postMb > 0 && uploadMb > 0 && postMb < uploadMb) {
            issuesList.push({ sev: 'warning', tab: 'summary',
                title: 'post_max_size (' + health.php_post_max + ') < upload_max_filesize (' + health.php_upload_max + ')',
                detail: 'post_max_size must be ≥ upload_max_filesize or file uploads will silently fail. Raise post_max_size.', plugin: '' });
        }
        if (maxExec > 0 && maxExec < 30) {
            issuesList.push({ sev: 'warning', tab: 'summary',
                title: 'max_execution_time is ' + maxExec + 's — imports and exports may time out',
                detail: 'Raise to at least 60s in php.ini: max_execution_time = 60', plugin: '' });
        }

        // Maintenance mode loop — .maintenance file older than 10 minutes
        if (health.maintenance_stale) {
            issuesList.push({ sev: 'critical', tab: 'summary',
                title: 'Site stuck in maintenance mode — stale .maintenance file detected',
                detail: 'An interrupted update left the .maintenance lock file behind. Delete it from the WordPress root directory.', plugin: '' });
        }

        // siteurl / home URL mismatch vs current request host
        if (health.url_host_mismatch) {
            issuesList.push({ sev: 'warning', tab: 'summary',
                title: 'URL mismatch — current host differs from siteurl/home options',
                detail: 'Possible login redirect loop, broken cookies, or incorrect site migration. Verify siteurl and home in Settings → General.', plugin: '' });
        }
        if (health.url_const_override) {
            issuesList.push({ sev: 'info', tab: 'summary',
                title: 'WP_SITEURL or WP_HOME constant overrides database URL',
                detail: 'These wp-config.php constants shadow Settings → General. If set intentionally, no action needed — but they can mask migration issues.', plugin: '' });
        }

        // Rewrite rules missing — permalinks need flushing
        if (health.rewrite_rules_missing) {
            issuesList.push({ sev: 'critical', tab: 'summary',
                title: 'Rewrite rules missing — pretty permalinks will return 404',
                detail: 'Go to Settings → Permalinks and click Save Changes to regenerate the rules (no other changes needed).', plugin: '' });
        }

        // wp-config.php world-readable
        if (health.wpconfig_world_readable) {
            issuesList.push({ sev: 'warning', tab: 'summary',
                title: 'wp-config.php is world-readable — any system user can read DB credentials',
                detail: 'Run: chmod 600 /path/to/wp-config.php (or 640 if the web server needs group read access).', plugin: '' });
        }

        // debug.log growing large
        if (health.debug_log_mb !== null && health.debug_log_mb !== undefined) {
            if (health.debug_log_mb >= 100) {
                issuesList.push({ sev: 'critical', tab: 'logs',
                    title: 'debug.log is ' + health.debug_log_mb + 'MB — disk space risk',
                    detail: 'Truncate with: truncate -s 0 wp-content/debug.log — then disable WP_DEBUG_LOG or add log rotation.', plugin: '' });
            } else if (health.debug_log_mb >= 10) {
                issuesList.push({ sev: 'warning', tab: 'logs',
                    title: 'debug.log is ' + health.debug_log_mb + 'MB — consider rotating',
                    detail: 'A large debug log indicates ongoing PHP errors. Check the Logs tab for the most frequent errors, then fix the root cause.', plugin: '' });
            }
        }

        // Author enumeration
        if (health.author_enum_risk) {
            issuesList.push({ sev: 'info', tab: 'summary',
                title: 'Author enumeration — /?author=1 reveals WordPress usernames',
                detail: "Add add_filter('redirect_canonical','__return_false') or disable author archives", plugin: '' });
        }

        // Plugins with pending updates
        var pUpdates = health.plugins_with_updates || [];
        if (pUpdates.length > 0) {
            var pNames = pUpdates.slice(0, 3).map(function (p) { return p.slug + ' (' + p.current + ' → ' + p.new_version + ')'; }).join(', ');
            issuesList.push({ sev: 'warning', tab: 'summary',
                title: pUpdates.length + ' plugin' + (pUpdates.length > 1 ? 's have' : ' has') + ' pending updates',
                detail: pNames + (pUpdates.length > 3 ? ' + ' + (pUpdates.length - 3) + ' more' : ''), plugin: '' });
        }

        // Render-blocking scripts — in <head>, no defer/async
        var renderBlocking = (data.assets && data.assets.scripts || []).filter(function (s) {
            return s.src && !s.in_footer && s.strategy !== 'defer' && s.strategy !== 'async';
        });
        if (renderBlocking.length > 5) {
            issuesList.push({ sev: 'warning', tab: 'assets',
                title: renderBlocking.length + ' render-blocking scripts in <head> (no defer/async)',
                detail: renderBlocking.slice(0, 4).map(function (s) { return s.handle; }).join(', ') + (renderBlocking.length > 4 ? ' …' : ''), plugin: '' });
        } else if (renderBlocking.length > 2) {
            issuesList.push({ sev: 'info', tab: 'assets',
                title: renderBlocking.length + ' scripts in <head> without defer/async',
                detail: renderBlocking.map(function (s) { return s.handle; }).join(', '), plugin: '' });
        }

        // Editor fetch failures and JS errors
        editorLogs.forEach(function (e) {
            if (e.type === 'fail') {
                var path       = (e.url||'').replace(window.location.origin,'').split('?')[0];
                var actionHint = e.action ? ' [action: ' + e.action + ']' : '';
                issuesList.push({ sev: 'critical', tab: 'editor',
                    title: 'Editor request failed — ' + (e.method||'GET') + ' ' + path + actionHint + ' → ' + e.status,
                    detail: e.detail ? e.detail.slice(0,100) : '', plugin: '' });
            } else if (e.type === 'jserr') {
                var isCrossOrigin     = /^script error\./i.test(e.detail || '');
                var isThirdPartyFetch = /^Promise rejection: (Failed to fetch|NetworkError|The operation was aborted\.|signal timed out)/i.test(e.detail || '');
                issuesList.push({
                    sev:    isCrossOrigin ? 'info' : (isThirdPartyFetch ? 'warning' : 'critical'),
                    tab:    'editor',
                    title:  isCrossOrigin
                        ? 'Script error. (cross-origin — details hidden by browser; check DevTools console)'
                        : isThirdPartyFetch
                            ? 'Third-party fetch failed — ' + (e.detail||'').slice(0,120)
                            : 'JS Error — ' + (e.detail||'').slice(0,120),
                    detail: isCrossOrigin
                        ? 'Add crossorigin="anonymous" to the offending <script> tag if the CDN sends CORS headers. Use Site Audit → crossorigin check to find missing attributes.'
                        : isThirdPartyFetch
                            ? 'A fire-and-forget network request from a third-party script (e.g. AdSense pagead/ping, GTM analytics beacon) timed out or was cancelled — this typically happens when the browser navigates away before the request completes. These are expected and do not affect site functionality, ad revenue, or analytics accuracy. No action needed.'
                            : (e.file || ''),
                    plugin: ''
                });
            }
        });

        // Sort: critical → warning → info
        var order = { critical: 0, warning: 1, info: 2 };
        issuesList.sort(function (a, b) { return (order[a.sev] || 0) - (order[b.sev] || 0); });
    }

    // ── Issue fix database ────────────────────────────────────────────────────
    var ISSUE_FIXES = {
        admin_user: {
            why: 'Credential-stuffing bots always try "admin" first. A non-obvious username dramatically reduces automated attack success.',
            steps: [
                'Go to <b>Users → Add New</b> in wp-admin and create a new Administrator with a unique username.',
                'Log out, then log back in as the new administrator account.',
                'Go to <b>Users → All Users</b>, hover the "admin" account and click <b>Delete</b>.',
                'On the delete screen, choose <b>Attribute all content to:</b> your new account, then confirm deletion.',
                'Verify login works with the new username before closing the browser.'
            ]
        },
        db_prefix: {
            why: 'The default wp_ prefix makes blind SQL injection attacks easier — attackers know exact table names without guessing.',
            steps: [
                '<b>Take a full database backup before proceeding.</b>',
                'Choose a new prefix such as <code>wp8f3x_</code> (alphanumeric + underscore).',
                'Via phpMyAdmin or SSH/MySQL: rename every table from <code>wp_</code> to the new prefix.',
                'Fix option names: <code>UPDATE wp_options SET option_name = REPLACE(option_name, \'wp_\', \'newprefix_\') WHERE option_name LIKE \'wp_%\';</code>',
                'Fix user meta keys: <code>UPDATE wp_usermeta SET meta_key = REPLACE(meta_key, \'wp_\', \'newprefix_\') WHERE meta_key LIKE \'wp_%\';</code>',
                'In <code>wp-config.php</code> change: <code>$table_prefix = \'wp_\';</code> → <code>$table_prefix = \'newprefix_\';</code>',
                'Test thoroughly — serialised option values may hard-code the old prefix.'
            ],
            note: 'Only practical on new installs. On a mature live site the risk of breaking serialised data often outweighs the gain.'
        },
        xmlrpc: {
            why: 'XML-RPC\'s system.multicall method lets an attacker test hundreds of passwords in a single HTTP request, bypassing per-request rate limits.',
            steps: [
                'Add to a must-use plugin (<code>wp-content/mu-plugins/no-xmlrpc.php</code>):<br><code>&lt;?php add_filter( \'xmlrpc_enabled\', \'__return_false\' );</code>',
                'Or block at the server level in Nginx: <code>location = /xmlrpc.php { deny all; return 404; }</code>',
                'Or in Cloudflare: WAF → Create Rule → <code>http.request.uri.path eq "/xmlrpc.php"</code> → Block.',
                'Verify: a request to <code>/xmlrpc.php</code> should return 403 or 404.'
            ]
        },
        readme_exposed: {
            why: 'Knowing the exact WordPress version lets attackers target known unpatched CVEs during disclosure windows.',
            steps: [
                'Delete via SSH: <code>rm /var/www/html/readme.html /var/www/html/license.txt</code>',
                'Or block in Nginx: <code>location ~* ^/(readme|license)\\.(html|txt)$ { return 404; }</code>',
                'Or in Apache <code>.htaccess</code>: <code>&lt;FilesMatch "^(readme|license)\\.(html|txt)$"&gt; Deny from all &lt;/FilesMatch&gt;</code>',
                '<b>Note:</b> these files are recreated on every WP core update — add a post-update script or mu-plugin to delete them automatically.'
            ]
        },
        php_eol: {
            why: 'End-of-life PHP versions receive no security patches. Known CVEs accumulate with no fixes available from the PHP team.',
            steps: [
                'Check your hosting control panel (cPanel, Plesk, etc.) for a PHP version selector.',
                'Target <b>PHP 8.3 or 8.4</b> — test on a staging environment first.',
                'After switching, run <b>Tools → Site Health</b> in wp-admin to check plugin compatibility.',
                'If your host doesn\'t offer 8.3+, consider migrating — current PHP support is a hard security requirement.'
            ]
        },
        php_old: {
            why: 'PHP 8.2 reaches end-of-life in December 2026. Plan migration before the deadline to stay on supported versions.',
            steps: [
                'Test your site on a staging environment with <b>PHP 8.3 or 8.4</b> — most WP sites are drop-in compatible.',
                'Update via your hosting control panel before December 2026.',
                'PHP 8.3 and 8.4 include performance improvements alongside security support.'
            ]
        },
        wp_debug_display: {
            why: 'Displaying PHP errors leaks file paths, table names, and code structure to any visitor who can trigger an error — including unauthenticated ones.',
            steps: [
                'Open <code>wp-config.php</code> in a text editor via SSH or SFTP.',
                'Find <code>define( \'WP_DEBUG\', true );</code> and add below it:',
                '<code>define( \'WP_DEBUG_DISPLAY\', false );</code>',
                '<code>@ini_set( \'display_errors\', 0 );</code>',
                'Errors will now go to <code>wp-content/debug.log</code> — visible in the CS Monitor Logs tab.'
            ]
        },
        disallow_file_edit: {
            why: 'Without this, any compromised admin account (or admin-level XSS) can inject PHP backdoors directly into theme or plugin files via wp-admin.',
            steps: [
                'Open <code>wp-config.php</code> via SSH or SFTP.',
                'Add before the <code>/* That\'s all, stop editing! */</code> line:',
                '<code>define( \'DISALLOW_FILE_EDIT\', true );</code>',
                'This removes <b>Appearance → Theme File Editor</b> and <b>Plugins → Plugin File Editor</b>.',
                'Verify by checking those menu entries are gone after saving.'
            ]
        },
        disallow_file_mods: {
            why: 'Prevents plugin/theme installs and updates from wp-admin — appropriate for hardened production servers where all changes go through deployment pipelines.',
            steps: [
                'Open <code>wp-config.php</code>.',
                'Add: <code>define( \'DISALLOW_FILE_MODS\', true );</code>',
                'This disables plugin/theme installs, updates, and the file editor in one constant.',
                '<b>Note:</b> this also blocks core updates via wp-admin — use WP-CLI or your deployment script: <code>wp core update</code>'
            ]
        },
        failed_logins: {
            why: 'Repeated failed logins indicate credential-stuffing or brute-force attacks targeting the login endpoint.',
            steps: [
                'Identify the attacking IP: check Cloudflare → Security → Events, or your server access log.',
                'Block the IP in Cloudflare: Security → WAF → Tools → IP Access Rules → Block.',
                'Add a Cloudflare rate-limit rule: more than 5 POST requests to <code>' + ( data.meta && data.meta.login_slug ? '/' + data.meta.login_slug : '/wp-login.php' ) + '</code> within 60 seconds → Block.',
                'Enable 2FA on all admin accounts — this plugin\'s 2FA makes stolen passwords useless.',
                'The counter shown resets every hour — high 24h counts with a low 1h count means a slow, spread-out attack.'
            ]
        },
        author_enum: {
            why: 'Visiting /?author=1 redirects to /author/username/, revealing valid WordPress usernames which can then be used in targeted brute-force attacks.',
            steps: [
                'Add to a must-use plugin (<code>wp-content/mu-plugins/security.php</code>):',
                '<code>add_filter( \'redirect_canonical\', function( $r ) { return isset( $_GET[\'author\'] ) ? false : $r; } );</code>',
                'Verify: visiting <code>/?author=1</code> should no longer redirect to the author archive URL.',
                'The REST API is a separate enumeration vector. To block it add: <code>add_filter( \'rest_endpoints\', function( $e ) { unset( $e[\'/wp/v2/users\'] ); return $e; } );</code>'
            ]
        },
        plugin_updates: {
            why: 'Outdated plugins are the #1 cause of WordPress compromises. Most attacks exploit known CVEs patched in the latest version.',
            steps: [
                'Go to <b>Dashboard → Updates</b> in wp-admin.',
                'Select all plugins with available updates and click <b>Update Plugins</b>.',
                'Or update via WP-CLI: <code>wp plugin update --all</code>',
                'Test key site functionality after updating.',
                'Enable automatic updates for security-critical plugins: Plugins → find the plugin → Enable auto-updates.'
            ]
        },
        site_https: {
            why: 'Plain HTTP transmits auth cookies, passwords, and session data in cleartext — visible to anyone on the same network.',
            steps: [
                'Install an SSL certificate via your hosting panel (Let\'s Encrypt is free and auto-renews).',
                'In Cloudflare: SSL/TLS → set mode to <b>Full (Strict)</b>, then enable <b>Always Use HTTPS</b>.',
                'In WordPress: <b>Settings → General</b> → change both WordPress Address and Site Address to https://.',
                'Add to <code>wp-config.php</code>: <code>define( \'FORCE_SSL_ADMIN\', true );</code>',
                'Run a search-replace to update hardcoded http:// URLs: <code>wp search-replace \'http://yourdomain.com\' \'https://yourdomain.com\'</code>'
            ]
        },
        autoload_bloat: {
            why: 'Every page load executes SELECT on all autoloaded options. Large payloads here slow every single request on the site.',
            steps: [
                'Check the <b>Site Health</b> section in the Summary tab to see the largest offending options.',
                'For plugin-owned options: check the plugin\'s settings for a "clear cache" or "clear data" option.',
                'To stop an option autoloading (WP 6.4+): <code>wp_set_options_autoload( [ \'option_name\' => false ] );</code>',
                'Or via SQL: <code>UPDATE wp_options SET autoload=\'no\' WHERE option_name=\'option_name_here\';</code>',
                'Plugins storing large caches in options should use transients with expiry or wp_cache_set() instead.'
            ]
        },
        cron_overdue: {
            why: 'Overdue cron events mean scheduled tasks (email sending, cleanup, index building) are not running on time.',
            steps: [
                'Check if WP-Cron is intentionally disabled: look for <code>define( \'DISABLE_WP_CRON\', true );</code> in <code>wp-config.php</code>.',
                'If disabled, verify your system cron is running: <code>crontab -l</code> should show a <code>wp-cron.php</code> entry.',
                'Add system cron if missing: <code>*/5 * * * * php /var/www/html/wp-cron.php > /dev/null 2>&1</code>',
                'List all scheduled events: <code>wp cron event list</code>',
                'Run overdue events immediately: <code>wp cron event run --due-now</code>'
            ]
        },
        slow_query: {
            why: 'Slow queries directly increase page load time. A query over 200ms is generally waiting on a missing index or scanning too many rows.',
            steps: [
                'Click the query row in the <b>DB Queries</b> tab to see the full SQL and call stack.',
                'Click <b>EXPLAIN</b> on the query — look for <code>type: ALL</code> which means a full table scan.',
                'Add a database index on the column(s) in the WHERE clause.',
                'If the query is inside a loop, refactor to fetch all records at once (use <code>post__in</code>, <code>include</code>, or a single JOIN).',
                'If owned by a plugin, check for a performance-related update or report it as a bug.'
            ]
        },
        n1_pattern: {
            why: 'N+1 happens when code runs the same query N times in a loop instead of batching — one query per post/user/term instead of one query total.',
            steps: [
                'Check the <b>caller</b> shown in the issue detail to identify which function is in the loop.',
                'Replace individual <code>get_post()</code> / <code>get_user_by()</code> calls with a single <code>WP_Query</code> using <code>post__in</code>.',
                'Use <code>wp_cache_get()</code> / <code>wp_cache_set()</code> to cache repeated single-record lookups.',
                'For user lookups in a loop, <code>get_users( [ \'include\' => $ids ] )</code> fetches all at once.',
                'If from a third-party plugin, report it with the normalised SQL pattern as a performance bug.'
            ]
        },
        cache_hit_rate: {
            why: 'A low hit rate means most cache reads result in database queries instead of fast in-memory retrieval.',
            steps: [
                'Install a persistent object cache: <b>Redis Object Cache</b> plugin + Redis server, or Memcached.',
                'Ensure <code>define( \'WP_CACHE\', true );</code> is in <code>wp-config.php</code>.',
                'If Redis is already running, check for eviction: <code>redis-cli info stats | grep evicted_keys</code> — increase <code>maxmemory</code> if keys are being evicted.',
                'Check which cache groups have the worst hit rates in the Summary tab object cache section.',
                'Run <code>wp cache info</code> via WP-CLI for a full cache health report.'
            ]
        },
        render_blocking: {
            why: 'Scripts in <head> without defer/async pause HTML parsing — the browser stops and waits before rendering anything on screen, directly increasing First Contentful Paint.',
            steps: [
                'For scripts you control: add <code>\'strategy\' => \'defer\'</code> to <code>wp_register_script()</code> or <code>wp_enqueue_script()</code>.',
                'For third-party plugin scripts: check the plugin settings for a "load in footer" option.',
                'The <b>Assets tab</b> shows which plugin owns each blocking script — check if an update fixes it.',
                'As a last resort, use WP Rocket or Perfmatters to move scripts to footer.',
                'Only scripts that are truly needed before render (e.g. critical analytics) should stay in <head>.'
            ]
        },
        asset_bloat: {
            why: 'Each additional script and stylesheet adds a network round-trip, parse time, and execution time — multiplied across every page load.',
            steps: [
                'Use the <b>Assets tab</b> to identify which plugins load the most scripts/styles.',
                'Dequeue assets on pages where they\'re not needed — example: <code>if ( ! is_page( \'contact\' ) ) wp_dequeue_script( \'contact-form-scripts\' );</code>',
                'Check each plugin\'s settings for a "disable on" or "load only on" option.',
                'Consider Asset CleanUp or Perfmatters for no-code per-page asset management.',
                'Combine/minify remaining assets if your CDN or caching layer doesn\'t handle it.'
            ]
        },
        memory_pressure: {
            why: 'When PHP peak memory approaches memory_limit, processes become unstable and can crash with a fatal "Allowed memory size exhausted" error, killing the request.',
            steps: [
                'Check which plugin is the biggest consumer: install <b>Query Monitor</b> and look at the Memory column.',
                'Increase the limit as a short-term fix — in <code>wp-config.php</code>: <code>define( \'WP_MEMORY_LIMIT\', \'256M\' );</code>',
                'Or in <code>php.ini</code> / <code>.htaccess</code>: <code>php_value memory_limit 256M</code>',
                'Identify and deactivate heavy plugins one by one while watching the memory figure here.',
                'Check for memory leaks in custom code — large arrays held in global scope or unbounded WP_Query loops are common culprits.',
                'On WooCommerce stores: enable <code>WC_TEMPLATE_DEBUG_MODE</code> or use an APM (New Relic, Tideways) for per-request allocation traces.'
            ]
        },
        high_load: {
            why: 'When system load average exceeds CPU count, requests queue up faster than they finish — PHP-FPM workers pile up, response times spike, and the server can become unresponsive.',
            steps: [
                'Check for an xmlrpc.php flood first — it\'s the most common cause on WordPress: <code>grep "POST.*xmlrpc.php" /var/log/nginx/access.log | wc -l</code>',
                'Identify which IPs are attacking: <code>grep "xmlrpc.php" /var/log/nginx/access.log | awk \'{print $1}\' | sort | uniq -c | sort -rn | head -20</code>',
                'Check for stuck long-running PHP-FPM workers: <code>ps aux | grep php-fpm | grep www | awk \'{print $10, $11}\' | sort -rn | head -10</code>',
                'Kill workers that have been running for >2 minutes: <code>ps aux | grep php-fpm | grep www | awk \'$10 > "2:00" {print $2}\' | xargs kill -9</code>',
                'Set a request timeout in your PHP-FPM pool config to prevent pile-up: <code>request_terminate_timeout = 60</code>',
                'Block xmlrpc.php at Nginx if it\'s the source: <code>location = /xmlrpc.php { deny all; return 403; }</code>',
                'Check WP-Cron for a backlog — many overdue events running simultaneously also spike load: <code>wp cron event list --due-now</code>'
            ]
        },
        disk_space: {
            why: 'A full disk silently kills WordPress — new uploads fail, PHP sessions can\'t be written, MySQL can\'t flush to disk, and wp-cron jobs stop completing.',
            steps: [
                'Find the largest directories: <code>du -sh /* 2>/dev/null | sort -rh | head -20</code>',
                'Check for oversized log files: <code>du -sh /var/log/* 2>/dev/null | sort -rh | head -10</code>',
                'Truncate a log file without deleting it: <code>truncate -s 0 /var/log/nginx/access.log</code> (safer than rm while nginx is running)',
                'Check WordPress debug.log: <code>du -sh wp-content/debug.log</code> — set a max size or disable WP_DEBUG_LOG when not in use',
                'Find large files anywhere on the server: <code>find / -xdev -size +100M -not -path "/proc/*" 2>/dev/null | sort</code>',
                'Clean up old backup archives if stored on the same disk — use an offsite destination (S3, B2) instead.',
                'Remove unused WordPress uploads: <b>Media → Library</b> — or run <code>wp media regenerate --only-missing</code> to remove orphaned sizes.'
            ]
        },
        opcache: {
            why: 'PHP OPcache compiles each .php file once and stores the bytecode in shared memory. Without it, every single request re-parses and re-compiles every PHP file — wasting CPU and slowing response times significantly.',
            steps: [
                'Check if OPcache is installed: <code>php -m | grep -i opcache</code> — if absent, install the extension.',
                'Enable in <code>php.ini</code> (or <code>/etc/php/8.x/fpm/conf.d/10-opcache.ini</code>):<br><code>opcache.enable=1</code><br><code>opcache.enable_cli=0</code><br><code>opcache.memory_consumption=128</code><br><code>opcache.interned_strings_buffer=16</code><br><code>opcache.max_accelerated_files=10000</code><br><code>opcache.validate_timestamps=1</code><br><code>opcache.revalidate_freq=60</code>',
                'Reload PHP-FPM after changes: <code>systemctl reload php8.x-fpm</code>',
                'Verify OPcache is active: <code>php -r "var_dump(opcache_get_status()[\'opcache_enabled\']);"</code>',
                'If OOM restarts are occurring, increase memory: <code>opcache.memory_consumption=256</code>',
                'For high traffic sites, disable timestamp validation in production for a speed boost: <code>opcache.validate_timestamps=0</code> — then reload PHP-FPM after each deploy.'
            ]
        },
        uploads_writable: {
            why: 'WordPress writes uploaded files directly to wp-content/uploads. If the directory is not writable by the web server user, all media uploads silently fail — the file never lands on disk.',
            steps: [
                'Check current ownership and permissions: <code>ls -la wp-content/uploads/</code>',
                'The directory must be writable by the web server user (typically www-data on Debian/Ubuntu, nginx on RHEL/CentOS).',
                'Set correct ownership: <code>chown -R www-data:www-data wp-content/uploads/</code>',
                'Set correct permissions: <code>chmod -R 755 wp-content/uploads/</code>',
                'If on shared hosting: upload a test file via FTP and confirm it appears in <b>Media → Library</b>.',
                'Verify the upload path in Settings → Media is not set to an absolute path that doesn\'t exist.',
                'Check for a custom uploads path set via <code>UPLOADS</code> constant in wp-config.php — the target directory must also be writable.'
            ]
        },
        wp_outdated: {
            why: 'WordPress core updates patch publicly disclosed security vulnerabilities (CVEs). Running an outdated version gives attackers a known exploit window during the period between disclosure and your update.',
            steps: [
                'Go to <b>Dashboard → Updates</b> and click <b>Update Now</b>.',
                'Or via WP-CLI (faster, no browser timeout): <code>wp core update && wp core update-db</code>',
                'Always take a backup first: <code>wp db export backup-$(date +%Y%m%d).sql</code>',
                'If auto-updates are appropriate for your site, enable them in wp-config.php: <code>define( \'WP_AUTO_UPDATE_CORE\', true );</code>',
                'After updating, run <code>wp plugin verify-checksums --all</code> to confirm plugin integrity.'
            ]
        },
        mysql_eol: {
            why: 'End-of-life database versions receive no security patches. Known CVEs accumulate without fixes, and WordPress core and plugin minimum requirements continue to rise.',
            steps: [
                'Check your current version: <code>mysql --version</code> or <code>SELECT VERSION();</code>',
                'For <b>MySQL</b>: upgrade to MySQL 8.4 LTS. On Ubuntu: <code>apt-get install mysql-server-8.4</code> or use the official MySQL APT repository.',
                'For <b>MariaDB</b>: upgrade to 10.11 LTS or 11.4 LTS. See mariadb.org/download for repository setup.',
                '<b>Before upgrading:</b> <code>wp db export backup-pre-upgrade.sql</code> — always back up first.',
                'After upgrading the server, run: <code>mysql_upgrade -u root -p</code> (MySQL) or <code>mariadb-upgrade</code> (MariaDB)',
                'Verify WordPress still works: <code>wp db check</code>',
                'Check your hosting control panel — managed hosts often have a one-click version selector (cPanel, Plesk, Kinsta, etc.).'
            ]
        },
        maintenance_stuck: {
            why: 'WordPress creates a .maintenance file at the start of a core/plugin/theme update and deletes it when complete. If the update process is interrupted (browser closed, PHP timeout, server crash), the file remains and the site stays in maintenance mode indefinitely.',
            steps: [
                'Connect to your server via SSH or SFTP.',
                'Navigate to your WordPress root directory (same folder as wp-config.php).',
                'Delete the lock file: <code>rm .maintenance</code>',
                'Reload the site — it should return to normal immediately.',
                'If the interrupted update left plugins or core files in a broken state, re-run the update from <b>Dashboard → Updates</b>.'
            ]
        },
        url_mismatch: {
            why: 'WordPress stores its own URL (siteurl) and the public site URL (home) in the database. If these don\'t match the domain the site is actually served from, login cookies can\'t be set, causing an infinite redirect loop at /wp-login.php.',
            steps: [
                'Check the current configured URLs: go to <b>Settings → General</b> in wp-admin.',
                'If you can\'t log in, update via WP-CLI: <code>wp option update siteurl "https://yourdomain.com"</code> and <code>wp option update home "https://yourdomain.com"</code>.',
                'Or update directly in the database: <code>UPDATE wp_options SET option_value="https://yourdomain.com" WHERE option_name IN ("siteurl","home");</code>',
                'If <code>WP_SITEURL</code> or <code>WP_HOME</code> are defined in wp-config.php, they override the database — update them there instead.',
                'After fixing URLs, clear all caches (object cache, page cache, browser cookies).',
                'Verify cookies are working: the WordPress auth cookie domain must match the site domain.'
            ]
        },
        rewrite_rules: {
            why: 'WordPress uses URL rewriting to map pretty permalink URLs (like /2024/01/my-post/) to the actual query string (?p=123). If the rewrite rules are missing or stale, all non-home URLs return 404.',
            steps: [
                'Go to <b>Settings → Permalinks</b> in wp-admin.',
                'Click <b>Save Changes</b> — no modifications needed, just saving regenerates the rules.',
                'If that doesn\'t work, verify your <code>.htaccess</code> (Apache) or Nginx config has the WordPress rewrite block.',
                'For Apache — check that <code>AllowOverride All</code> is set in your virtual host config so .htaccess rules are applied.',
                'For Nginx — ensure your server block includes: <code>try_files $uri $uri/ /index.php?$args;</code>',
                'Force regenerate via WP-CLI: <code>wp rewrite flush --hard</code>'
            ]
        },
        wpconfig_perms: {
            why: 'wp-config.php contains database credentials, auth keys, and salts. World-readable permissions (644) allow any user or process on the same server to read these secrets — a critical risk on shared hosting.',
            steps: [
                'Set owner-only read/write: <code>chmod 600 /var/www/html/wp-config.php</code>',
                'If the web server process needs read access (and runs as a different user): <code>chmod 640 wp-config.php && chown youruser:www-data wp-config.php</code>',
                'Verify: <code>ls -la wp-config.php</code> should show <code>-rw-------</code> (600) or <code>-rw-r-----</code> (640).',
                'Optionally, move wp-config.php one level above the web root — WordPress automatically checks the parent directory.',
                'Rotate your database password and auth keys/salts after exposure: use the <a href="https://api.wordpress.org/secret-key/1.1/salt/">WordPress key generator</a>.'
            ]
        },
        debug_log: {
            why: 'A large debug.log means PHP is continuously generating errors or notices. Each write appends to the file — on a busy site this can fill disk space and mask the actual errors that need fixing.',
            steps: [
                'View the most recent errors in the <b>Logs tab</b> of CS Monitor — identify the most frequent error and fix its root cause.',
                'Truncate the file without restarting PHP: <code>truncate -s 0 wp-content/debug.log</code>',
                'Set up log rotation to prevent it growing again: <code>/etc/logrotate.d/wp-debug</code> — rotate daily, keep 7 days, compress.',
                'Once errors are resolved, disable debug logging in wp-config.php: <code>define( \'WP_DEBUG_LOG\', false );</code>',
                'Or limit to only genuine errors (not notices/warnings): <code>error_reporting( E_ERROR | E_PARSE );</code>'
            ]
        },
        nginx_eol: {
            why: 'Nginx follows a stable-branch model: older stable branches reach end-of-life when the next stable is released and stop receiving security backports. Running an outdated version leaves known CVEs unpatched.',
            steps: [
                'Check current version: <code>nginx -v</code>',
                'On Debian/Ubuntu: <code>apt-get update && apt-get install nginx</code> — or add the official Nginx APT repo for the latest stable.',
                'On Alpine (Docker): update your base image tag, e.g. <code>FROM nginx:1.26-alpine</code> in your Dockerfile.',
                'After updating, reload config: <code>nginx -t && systemctl reload nginx</code> (or <code>nginx -s reload</code>).',
                'In Docker Compose: update the image tag in docker-compose.yml and run <code>docker compose pull nginx && docker compose up -d nginx</code>.'
            ]
        },
        redis_eol: {
            why: 'Redis follows a versioned release model with defined end-of-life dates. EOL versions receive no CVE fixes — and WordPress object cache uses Redis on every single page load.',
            steps: [
                'Check current version: <code>redis-server --version</code>',
                'On Debian/Ubuntu: <code>apt-get update && apt-get install redis-server</code> — or use the official Redis APT repo for latest stable.',
                'On Alpine (Docker): update the image tag, e.g. <code>FROM redis:7.4-alpine</code> in docker-compose.yml.',
                'In Docker Compose: update the image tag and run <code>docker compose pull redis && docker compose up -d redis</code>.',
                'Redis is backwards compatible within major versions — no data migration is required for a minor version update.',
                'After upgrading, verify WP Object Cache still works: <code>wp cache flush && wp post get 1 --field=post_title</code>'
            ]
        },
        php_limits: {
            why: 'WordPress media uploads and import operations fail silently when PHP\'s upload_max_filesize or post_max_size are too small. The browser reports success but no file lands on the server.',
            steps: [
                'Locate the active php.ini: <code>php --ini</code> or check <b>Tools → Site Health → Info → Server</b>.',
                'Increase upload and post size in <code>php.ini</code>:<br><code>upload_max_filesize = 64M</code><br><code>post_max_size = 64M</code><br><code>max_execution_time = 60</code>',
                'Or via <code>.htaccess</code> on Apache: <code>php_value upload_max_filesize 64M</code><br><code>php_value post_max_size 64M</code>',
                'Or in <code>wp-config.php</code> (only affects WP memory, not upload size): <code>@ini_set(\'upload_max_filesize\', \'64M\');</code>',
                '<b>Important:</b> post_max_size must be ≥ upload_max_filesize — if post_max is smaller, PHP silently discards the upload body.',
                'Reload PHP-FPM after editing php.ini: <code>systemctl reload php8.x-fpm</code>',
                'Verify the new values at <b>Tools → Site Health → Info → Media Handling</b>.'
            ]
        }
    };

    // Deferred until ISSUE_FIXES is defined — avoids undefined reference when the
    // tab router injects this script after DOMContentLoaded has already fired.
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', csdtPerfInit );
    } else {
        csdtPerfInit();
    }

    function getIssueFix(issue) {
        var t = issue.title.toLowerCase();
        if (t.indexOf('"admin"') !== -1 || (t.indexOf('username') !== -1 && t.indexOf('admin') !== -1)) return ISSUE_FIXES.admin_user;
        if (t.indexOf('wp_') !== -1 && t.indexOf('prefix') !== -1)  return ISSUE_FIXES.db_prefix;
        if (t.indexOf('xml-rpc') !== -1 || t.indexOf('xmlrpc') !== -1) return ISSUE_FIXES.xmlrpc;
        if (t.indexOf('memory critical') !== -1 || t.indexOf('elevated memory') !== -1) return ISSUE_FIXES.memory_pressure;
        if (t.indexOf('system overloaded') !== -1 || t.indexOf('high cpu load') !== -1) return ISSUE_FIXES.high_load;
        if (t.indexOf('disk almost full') !== -1 || t.indexOf('low disk space') !== -1) return ISSUE_FIXES.disk_space;
        if (t.indexOf('maintenance mode') !== -1)                                        return ISSUE_FIXES.maintenance_stuck;
        if (t.indexOf('url mismatch') !== -1 || t.indexOf('wp_siteurl') !== -1 || t.indexOf('wp_home') !== -1) return ISSUE_FIXES.url_mismatch;
        if (t.indexOf('rewrite rules') !== -1)                                           return ISSUE_FIXES.rewrite_rules;
        if (t.indexOf('wp-config.php') !== -1)                                           return ISSUE_FIXES.wpconfig_perms;
        if (t.indexOf('debug.log') !== -1)                                               return ISSUE_FIXES.debug_log;
        if (t.indexOf('wordpress update available') !== -1)                              return ISSUE_FIXES.wp_outdated;
        if ((t.indexOf('mysql') !== -1 || t.indexOf('mariadb') !== -1) && (t.indexOf('end-of-life') !== -1 || t.indexOf('eol') !== -1 || t.indexOf('approaching eol') !== -1)) return ISSUE_FIXES.mysql_eol;
        if (t.indexOf('nginx') !== -1 && (t.indexOf('outdated') !== -1 || t.indexOf('old') !== -1 || t.indexOf('end-of-life') !== -1)) return ISSUE_FIXES.nginx_eol;
        if (t.indexOf('redis') !== -1 && (t.indexOf('end-of-life') !== -1 || t.indexOf('eol') !== -1))  return ISSUE_FIXES.redis_eol;
        if (t.indexOf('opcache') !== -1)                                                 return ISSUE_FIXES.opcache;
        if (t.indexOf('uploads directory') !== -1)                                       return ISSUE_FIXES.uploads_writable;
        if (t.indexOf('upload_max_filesize') !== -1 || t.indexOf('post_max_size') !== -1 || t.indexOf('max_execution_time') !== -1) return ISSUE_FIXES.php_limits;
        if (t.indexOf('version disclosed') !== -1 || t.indexOf('readme') !== -1 || t.indexOf('license') !== -1) return ISSUE_FIXES.readme_exposed;
        if (t.indexOf('end-of-life') !== -1 && t.indexOf('2026') === -1) return ISSUE_FIXES.php_eol;
        if (t.indexOf('end-of-life december 2026') !== -1 || (t.indexOf('php') !== -1 && t.indexOf('2026') !== -1)) return ISSUE_FIXES.php_old;
        if (t.indexOf('wp_debug_display') !== -1)                   return ISSUE_FIXES.wp_debug_display;
        if (t.indexOf('disallow_file_edit') !== -1)                  return ISSUE_FIXES.disallow_file_edit;
        if (t.indexOf('disallow_file_mods') !== -1)                  return ISSUE_FIXES.disallow_file_mods;
        if (t.indexOf('failed login') !== -1 || t.indexOf('brute force') !== -1 || t.indexOf('brute-force') !== -1) return ISSUE_FIXES.failed_logins;
        if (t.indexOf('author enum') !== -1)                         return ISSUE_FIXES.author_enum;
        if (t.indexOf('pending update') !== -1)                      return ISSUE_FIXES.plugin_updates;
        if (t.indexOf('not https') !== -1 || (t.indexOf('https') !== -1 && t.indexOf('not') !== -1)) return ISSUE_FIXES.site_https;
        if (t.indexOf('autoload') !== -1)                            return ISSUE_FIXES.autoload_bloat;
        if (t.indexOf('cron') !== -1 || t.indexOf('overdue') !== -1) return ISSUE_FIXES.cron_overdue;
        if (t.indexOf('n+1') !== -1)                                 return ISSUE_FIXES.n1_pattern;
        if (t.indexOf('cache hit rate') !== -1 || t.indexOf('object cache') !== -1) return ISSUE_FIXES.cache_hit_rate;
        if (t.indexOf('render-blocking') !== -1 || t.indexOf('without defer') !== -1) return ISSUE_FIXES.render_blocking;
        if (t.indexOf('slow query') !== -1 || t.indexOf('critical query') !== -1) return ISSUE_FIXES.slow_query;
        if (t.indexOf('heavy asset') !== -1 || t.indexOf('asset count') !== -1) return ISSUE_FIXES.asset_bloat;
        return null;
    }

    function buildFixHtml(fix) {
        if (!fix) return '';
        var h = '<div class="cs-fix-panel">';
        if (fix.why) h += '<div class="cs-fix-why">' + fix.why + '</div>';
        h += '<ol class="cs-fix-steps">';
        fix.steps.forEach(function (s) { h += '<li>' + s + '</li>'; });
        h += '</ol>';
        if (fix.note) h += '<div class="cs-fix-note">&#9432; ' + fix.note + '</div>';
        h += '</div>';
        return h;
    }

    // Strip HTML tags for plain-text output; converts <br> to newline first
    function stripHtmlToText(s) {
        var t = String(s)
            .replace(/<br\s*\/?>/gi, '\n')
            .replace(/<li>/gi, '\n')
            .replace(/<[^>]+>/g, '')
            .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&')
            .replace(/&nbsp;/g, ' ').replace(/&#?\w+;/g, '');
        return t.trim();
    }

    function buildIssuesCopyText() {
        var lines = [];
        lines.push('CS Monitor — Issues Report');
        lines.push('URL: ' + (meta.url || window.location.href));
        lines.push('Date: ' + new Date().toISOString().replace('T', ' ').slice(0, 19));
        lines.push('');

        var sevOrder  = ['critical', 'warning', 'info'];
        var sevLabels = { critical: 'CRITICAL', warning: 'WARNING', info: 'INFO' };
        var lastSev   = null;

        issuesList.forEach(function (issue) {
            if (issue.sev !== lastSev) {
                if (lastSev !== null) lines.push('');
                var cnt = issuesList.filter(function (i) { return i.sev === issue.sev; }).length;
                lines.push('=== ' + (sevLabels[issue.sev] || issue.sev.toUpperCase()) + ' (' + cnt + ') ===');
                lines.push('');
                lastSev = issue.sev;
            }
            lines.push('[' + (sevLabels[issue.sev] || issue.sev.toUpperCase()) + '] ' + issue.title);
            if (issue.detail) lines.push(issue.detail);
            var fix = getIssueFix(issue);
            if (fix) {
                if (fix.why)   lines.push('', '  Why: ' + stripHtmlToText(fix.why));
                if (fix.steps && fix.steps.length) {
                    lines.push('  Steps:');
                    fix.steps.forEach(function (s, i) {
                        lines.push('    ' + (i + 1) + '. ' + stripHtmlToText(s));
                    });
                }
                if (fix.note) lines.push('  Note: ' + stripHtmlToText(fix.note));
            }
            lines.push('');
        });

        return lines.join('\n');
    }

    function renderIssues() {
        if (!issuesWrap) return;

        if (issuesList.length === 0) {
            issuesWrap.innerHTML = '<div class="cs-empty" style="padding:24px 12px">'
                + '<span class="cs-empty-icon">&#10003;</span>'
                + 'No issues detected on this page load.</div>';
            return;
        }

        var critCnt = issuesList.filter(function (i) { return i.sev === 'critical'; }).length;
        var warnCnt = issuesList.filter(function (i) { return i.sev === 'warning';  }).length;
        var infoCnt = issuesList.filter(function (i) { return i.sev === 'info';     }).length;
        var summary = [];
        if (critCnt) summary.push(critCnt + ' critical');
        if (warnCnt) summary.push(warnCnt + ' warning' + (warnCnt > 1 ? 's' : ''));
        if (infoCnt) summary.push(infoCnt + ' info');

        var html = '<div class="cs-issues-toolbar">'
            + '<span class="cs-issues-summary">' + summary.join(' · ') + '</span>'
            + '</div>';

        var lastSev = null;
        var titles  = { critical: '&#128308;&nbsp;Critical', warning: '&#128993;&nbsp;Warnings', info: '&#128994;&nbsp;Info' };
        issuesList.forEach(function (issue, idx) {
            if (issue.sev !== lastSev) {
                if (lastSev !== null) html += '</div>';
                html += '<div class="cs-issues-group"><div class="cs-issues-group-title">' + (titles[issue.sev] || issue.sev) + '</div>';
                lastSev = issue.sev;
            }
            var fix = getIssueFix(issue);
            var fixId = 'cs-fix-' + idx;
            var tabLabels = { editor: 'browser', db: 'db', http: 'http', logs: 'logs', assets: 'assets', hooks: 'hooks' };
            var tabLink = issue.tab ? ' <span class="cs-issue-tab-link" data-tab="' + esc(issue.tab) + '" style="font-size:10px;opacity:.6;cursor:pointer;text-decoration:underline;">→ ' + esc(tabLabels[issue.tab] || issue.tab) + ' tab</span>' : '';
            var rowTab = (!fix && issue.tab) ? issue.tab : '';
            html += '<div class="cs-issue-row cs-issue-' + esc(issue.sev) + '"'
                + (rowTab ? ' data-nav-tab="' + esc(rowTab) + '" style="cursor:pointer;"' : '')
                + '>'
                + '<div class="cs-issue-top">'
                    + '<span class="cs-issue-title">' + esc(issue.title) + '</span>'
                    + (issue.plugin ? pluginChip(issue.plugin) : '')
                    + tabLink
                    + (fix ? '<span class="cs-issue-arrow cs-issue-arrow-expand">&#9658;</span>' : '')
                + '</div>'
                + (issue.detail ? '<div class="cs-issue-detail">' + esc(issue.detail) + '</div>' : '')
                + (fix ? '<div class="cs-fix-wrap" id="' + fixId + '">' + buildFixHtml(fix) + '</div>' : '')
                + '</div>';
        });
        if (lastSev !== null) html += '</div>';

        issuesWrap.innerHTML = html;

        // Row click — expand/collapse the fix panel
        Array.prototype.forEach.call(issuesWrap.querySelectorAll('.cs-issue-row'), function (row) {
            var fixWrap = row.querySelector('.cs-fix-wrap');
            var arrow   = row.querySelector('.cs-issue-arrow-expand');
            if (!fixWrap) return;
            row.addEventListener('click', function () {
                var open = fixWrap.classList.contains('cs-fix-open');
                fixWrap.classList.toggle('cs-fix-open', !open);
                if (arrow) arrow.innerHTML = open ? '&#9658;' : '&#9660;';
            });
        });

        // Tab-link chips navigate to the relevant tab
        Array.prototype.forEach.call(issuesWrap.querySelectorAll('.cs-issue-tab-link'), function (chip) {
            chip.addEventListener('click', function (e) {
                e.stopPropagation();
                switchTab(chip.dataset.tab, true);
            });
        });

        // Rows without a fix but with a tab — whole row navigates
        Array.prototype.forEach.call(issuesWrap.querySelectorAll('.cs-issue-row[data-nav-tab]'), function (row) {
            row.addEventListener('click', function (e) {
                if (e.target.classList.contains('cs-issue-tab-link')) return;
                switchTab(row.dataset.navTab, true);
            });
        });
    }

    function csFallbackCopy(text, btn) {
        try {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0';
            document.body.appendChild(ta);
            ta.focus(); ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            btn.textContent = 'Copied!';
            setTimeout(function () { btn.innerHTML = '&#128203; Copy All'; }, 2000);
        } catch (e) {
            btn.textContent = 'Copy failed';
            setTimeout(function () { btn.innerHTML = '&#128203; Copy All'; }, 2000);
        }
    }

    // ── Request tab ───────────────────────────────────────────────────────────
    function renderRequest() {
        if (!requestWrap) return;
        var req = data.request || {};

        var html = '<div class="cs-req-body">';

        // Method / URL / Rewrite
        html += '<div class="cs-req-section"><div class="cs-req-section-title">Request</div>'
            + '<div class="cs-req-kv"><span class="cs-req-k">Method</span><span class="cs-req-v">' + methodBadge(req.method || 'GET') + '</span></div>'
            + '<div class="cs-req-kv"><span class="cs-req-k">URL</span><span class="cs-req-v cs-req-mono">' + esc(meta.url || '—') + '</span></div>'
            + (req.matched_rule ? '<div class="cs-req-kv"><span class="cs-req-k">Rewrite rule</span><span class="cs-req-v cs-req-mono">' + esc(req.matched_rule) + '</span></div>' : '')
            + '</div>';

        // GET params
        var getKeys = Object.keys(req.get || {});
        html += '<div class="cs-req-section"><div class="cs-req-section-title">GET params (' + getKeys.length + ')</div>';
        if (getKeys.length === 0) {
            html += '<div class="cs-req-empty">None</div>';
        } else {
            getKeys.forEach(function (k) {
                html += '<div class="cs-req-kv"><span class="cs-req-k">' + esc(k) + '</span><span class="cs-req-v cs-req-mono">' + esc((req.get || {})[k]) + '</span></div>';
            });
        }
        html += '</div>';

        // POST keys only
        var postKeys = req.post_keys || [];
        html += '<div class="cs-req-section"><div class="cs-req-section-title">POST fields (' + postKeys.length + ') — keys only</div>';
        if (postKeys.length === 0) {
            html += '<div class="cs-req-empty">None</div>';
        } else {
            html += '<div class="cs-req-tags">'
                + postKeys.map(function (k) { return '<code class="cs-req-tag">' + esc(k) + '</code>'; }).join(' ')
                + '</div>';
        }
        html += '</div>';

        // WP query vars
        var qvKeys = Object.keys(req.query_vars || {});
        html += '<div class="cs-req-section"><div class="cs-req-section-title">WP Query Vars (' + qvKeys.length + ')</div>';
        if (qvKeys.length === 0) {
            html += '<div class="cs-req-empty">None — admin page, or parse_request has not run</div>';
        } else {
            qvKeys.forEach(function (k) {
                html += '<div class="cs-req-kv"><span class="cs-req-k">' + esc(k) + '</span><span class="cs-req-v cs-req-mono">' + esc((req.query_vars || {})[k]) + '</span></div>';
            });
        }
        html += '</div>';

        // Current user
        var roles = req.user_roles || [];
        html += '<div class="cs-req-section"><div class="cs-req-section-title">Current User</div>'
            + '<div class="cs-req-kv"><span class="cs-req-k">Roles</span><span class="cs-req-v">'
            + (roles.length
                ? roles.map(function (r) { return '<code class="cs-req-tag">' + esc(r) + '</code>'; }).join(' ')
                : '<em style="color:#666">None / not logged in</em>')
            + '</span></div></div>';

        html += '</div>';
        requestWrap.innerHTML = html;
    }

    // ── Template hierarchy tab ────────────────────────────────────────────────
    function renderTemplate() {
        if (!templateWrap) return;
        var tmpl = data.template || {};

        if (!tmpl.final && (!tmpl.hierarchy || tmpl.hierarchy.length === 0)) {
            templateWrap.innerHTML = '<div class="cs-empty" style="padding:20px 12px">'
                + '<span class="cs-empty-icon">&#128196;</span>'
                + 'Template hierarchy is only captured on frontend pages.</div>';
            return;
        }

        var html = '';

        if (tmpl.hierarchy && tmpl.hierarchy.length > 0) {
            tmpl.hierarchy.forEach(function (entry) {
                html += '<div class="cs-tmpl-group">'
                    + '<div class="cs-tmpl-type-hdr">' + esc(entry.type) + ' template</div>';
                entry.candidates.forEach(function (c) {
                    var cls  = c.active ? 'cs-tmpl-active' : c.found ? 'cs-tmpl-found' : 'cs-tmpl-miss';
                    var icon = c.active ? '&#9654;' : c.found ? '&#10003;' : '&mdash;';
                    var locTag = c.location
                        ? '<span class="cs-tmpl-loc cs-tmpl-loc-' + esc(c.location) + '">' + esc(c.location) + '</span>'
                        : '';
                    html += '<div class="cs-tmpl-row ' + cls + '">'
                        + '<span class="cs-tmpl-icon">' + icon + '</span>'
                        + '<span class="cs-tmpl-file">' + esc(c.file) + '</span>'
                        + locTag
                        + '</div>';
                });
                html += '</div>';
            });
        } else if (tmpl.final) {
            // Hierarchy not captured (e.g. full-page templates via template_include only)
            html = '<div class="cs-tmpl-group"><div class="cs-tmpl-type-hdr">Active template</div>'
                + '<div class="cs-tmpl-row cs-tmpl-active"><span class="cs-tmpl-icon">&#9654;</span>'
                + '<span class="cs-tmpl-file">' + esc(tmpl.final) + '</span></div></div>';
        }

        templateWrap.innerHTML = html;
    }

    // ── Transients tab ────────────────────────────────────────────────────────
    function renderTransients() {
        if (!transTbody) return;
        var transients = data.transients || [];

        if (transients.length === 0) {
            transTbody.innerHTML = '<tr><td colspan="7" class="cs-empty">'
                + '<span class="cs-empty-icon">&#9744;</span>'
                + 'No transients accessed or set on this page load.</td></tr>';
            return;
        }

        var html = '';
        transients.forEach(function (t) {
            var hr     = t.hit_rate !== null ? t.hit_rate : null;
            var hrCls  = hr === null ? '' : hr >= 80 ? 'cs-tv-fast' : hr >= 50 ? 'cs-tv-medium' : 'cs-tv-slow';
            var hrTxt  = hr !== null ? hr + '%' : '&mdash;';
            var missCls = t.misses > 0 ? 'cs-trans-miss' : '';
            html += '<tr>'
                + '<td class="c-tk" title="' + esc(t.key) + '">' + esc(t.key) + '</td>'
                + '<td class="c-tg">' + (t.gets    || 0) + '</td>'
                + '<td class="c-th" style="color:#4ec9b0">' + (t.hits    || 0) + '</td>'
                + '<td class="c-tm ' + missCls + '">' + (t.misses  || 0) + '</td>'
                + '<td class="c-ts" style="color:#9cdcfe">' + (t.sets    || 0) + '</td>'
                + '<td class="c-td" style="color:#888">' + (t.deletes || 0) + '</td>'
                + '<td class="c-tr"><span class="' + hrCls + '">' + hrTxt + '</span></td>'
                + '</tr>';
        });
        transTbody.innerHTML = html;
    }

    // ── Summary ───────────────────────────────────────────────────────────────
    function renderSummary() {
        if (!summaryWrap) return;
        var health  = data.health || {};
        var queries = data.queries, http = data.http, logs = data.logs || [];

        var slowQ  = queries.filter(function (q) { return q.time_ms >= T_SLOW; }).length;
        var critQ  = queries.filter(function (q) { return q.time_ms >= T_CRITICAL; }).length;
        var dupeQ  = queries.filter(function (q) { return q.is_dupe; }).length;
        var n1Cnt  = Object.keys(n1Patterns).length;
        var slowH  = http.filter(function (h) { return h.time_ms >= T_SLOW; }).length;
        var cacH   = http.filter(function (h) { return h.cached; }).length;
        var errH   = http.filter(function (h) { return !!h.error; }).length;
        var errL   = logs.filter(function (e) { return (e.level || '').toLowerCase().indexOf('error') !== -1; }).length;
        var warnL  = logs.filter(function (e) { return (e.level || '').toLowerCase().indexOf('warn') !== -1; }).length;
        var depL   = logs.filter(function (e) { return (e.level || '').toLowerCase().indexOf('dep') !== -1; }).length;

        // Plugin leaderboard
        var byP = {};
        queries.forEach(function (q) {
            if (!byP[q.plugin]) byP[q.plugin] = { count: 0, total_ms: 0, slow: 0, n1: 0 };
            byP[q.plugin].count++; byP[q.plugin].total_ms += q.time_ms;
            if (q.time_ms >= T_SLOW) byP[q.plugin].slow++;
            if (isN1(q.sql))         byP[q.plugin].n1++;
        });
        var pluginList = Object.keys(byP).map(function (p) {
            return { plugin: p, count: byP[p].count, total_ms: byP[p].total_ms, slow: byP[p].slow, n1: byP[p].n1 };
        }).sort(function (a, b) { return b.total_ms - a.total_ms; });
        var maxPMs = pluginList.length > 0 ? pluginList[0].total_ms : 1;

        var top5Q   = queries.slice().sort(function (a, b) { return b.time_ms - a.time_ms; }).slice(0, 5);
        var top5H   = http.slice().sort(function (a, b) { return b.time_ms - a.time_ms; }).slice(0, 5);
        var n1List  = Object.values(n1Patterns).sort(function (a, b) { return b.count - a.count; });

        var dupeGroups = {};
        queries.forEach(function (q) {
            var fp = q.sql.replace(/\s+/g, ' ').toLowerCase().trim();
            if (!dupeGroups[fp]) dupeGroups[fp] = { sql: q.sql, count: 0, total_ms: 0 };
            dupeGroups[fp].count++; dupeGroups[fp].total_ms += q.time_ms;
        });
        var dupeList = Object.values(dupeGroups).filter(function (g) { return g.count > 1; })
            .sort(function (a, b) { return b.count - a.count; }).slice(0, 8);

        var html = '<div class="cs-sum-toolbar">'
            + '<button id="cs-sum-pdf-btn" class="cs-sum-pdf-btn">&#8595; Download PDF</button>'
            + '</div>';

        // ── Request waterfall ──────────────────────────────────────────────
        var miles = data.milestones || [];
        if (miles.length >= 2) {
            var totalMs = miles[miles.length - 1].ms || meta.page_load_ms || 1;
            var wfColors = ['cs-wf-c0','cs-wf-c1','cs-wf-c2','cs-wf-c3','cs-wf-c4','cs-wf-c5','cs-wf-c6'];
            html += '<div class="cs-sum-section-title">Request Timeline</div><div class="cs-waterfall">';
            miles.forEach(function (m, mi) {
                var prev    = mi > 0 ? miles[mi - 1].ms : 0;
                var dur     = m.ms - prev;
                var fillPct = totalMs > 0 ? Math.min(100, (m.ms / totalMs) * 100) : 0;
                var cls     = wfColors[Math.min(mi, wfColors.length - 1)];
                html += '<div class="cs-wf-row">'
                    + '<div class="cs-wf-label">' + esc(m.label) + '</div>'
                    + '<div class="cs-wf-track">'
                        + '<div class="cs-wf-fill ' + cls + '" style="width:' + fillPct.toFixed(1) + '%"></div>'
                    + '</div>'
                    + '<div class="cs-wf-time">' + fmtMs(m.ms) + '</div>'
                    + '<div class="cs-wf-dur">' + (mi > 0 ? '+' + fmtMs(dur) : '') + '</div>'
                    + '</div>';
            });
            html += '</div>';
        }

        html += '<div class="cs-sum-cards">';

        // Environment card
        if (meta.php_version) {
            var memPct = 0;
            if (meta.memory_peak_mb && meta.memory_limit) {
                var limitMb = parseInt(meta.memory_limit, 10) || 0;
                if (limitMb > 0) memPct = Math.round((meta.memory_peak_mb / limitMb) * 100);
            }
            var memCls = memPct >= 90 ? ' cs-s-crit' : memPct >= 70 ? ' cs-s-warn' : '';
            html += '<div class="cs-sum-card cs-sum-card-env"><div class="cs-sum-card-title">&#9881; Environment</div>'
                + '<div class="cs-sum-card-sub">'
                + '<span>PHP&nbsp;' + esc(meta.php_version) + '</span>'
                + '<span>WP&nbsp;'  + esc(meta.wp_version  || '?') + '</span>'
                + (meta.mysql_version ? '<span>' + (health.is_mariadb ? 'MariaDB' : 'MySQL') + '&nbsp;' + esc(meta.mysql_version) + '</span>' : '')
                + (health.nginx_version  ? '<span>Nginx&nbsp;' + esc(health.nginx_version)  + '</span>' : '')
                + (meta.memory_peak_mb ? '<span class="' + memCls + '">Mem peak:&nbsp;' + meta.memory_peak_mb + 'MB&nbsp;/&nbsp;' + esc(meta.memory_limit || '?') + '</span>' : '')
                + (meta.active_theme   ? '<span>Theme:&nbsp;' + esc(meta.active_theme) + '</span>' : '')
                + (meta.is_multisite   ? '<span style="color:#9cdcfe">Multisite</span>' : '')
                + '</div></div>';
        }

        html += '<div class="cs-sum-card cs-sum-card-db"><div class="cs-sum-card-title">&#128200; DB Queries</div>'
            + '<div class="cs-sum-card-stat">' + meta.query_count + '</div>'
            + '<div class="cs-sum-card-sub"><span>' + fmtMs(meta.query_total_ms) + ' total</span>'
            + (critQ ? '<span class="cs-s-crit">&#9888; ' + critQ + ' critical</span>'
              : slowQ ? '<span class="cs-s-warn">&#9651; ' + slowQ + ' slow</span>'
              : '<span class="cs-s-ok">&#10003; No slow queries</span>')
            + (dupeQ ? '<span class="cs-s-warn">&#9654; ' + dupeQ + ' exact dupes</span>' : '')
            + (n1Cnt ? '<span class="cs-s-warn">&#8635; ' + n1Cnt + ' N+1 pattern' + (n1Cnt > 1 ? 's' : '') + '</span>' : '')
            + '</div></div>';

        html += '<div class="cs-sum-card cs-sum-card-http"><div class="cs-sum-card-title">&#127760; HTTP / REST</div>'
            + '<div class="cs-sum-card-stat">' + meta.http_count + '</div>'
            + '<div class="cs-sum-card-sub"><span>' + fmtMs(meta.http_total_ms) + ' total</span>'
            + (slowH ? '<span class="cs-s-warn">&#9651; ' + slowH + ' slow</span>' : '')
            + (cacH  ? '<span class="cs-s-ok">&#9632; ' + cacH + ' cached</span>' : '')
            + (errH  ? '<span class="cs-s-crit">&#10007; ' + errH + ' errors</span>' : '')
            + (http.length === 0 ? '<span>No outbound calls</span>' : '')
            + '</div></div>';

        html += '<div class="cs-sum-card cs-sum-card-log"><div class="cs-sum-card-title">&#128196; Logs</div>'
            + '<div class="cs-sum-card-stat">' + logs.length + '</div>'
            + '<div class="cs-sum-card-sub">'
            + (errL  ? '<span class="cs-s-crit">&#10007; ' + errL  + ' errors</span>' : '')
            + (warnL ? '<span class="cs-s-warn">&#9651; ' + warnL + ' warnings</span>' : '')
            + (depL  ? '<span class="cs-s-warn">&#8987; ' + depL  + ' deprecated</span>' : '')
            + (logs.length === 0 ? '<span class="cs-s-ok">&#10003; No log entries</span>' : '')
            + '</div></div>';

        // Cache card
        var cache = data.cache || {};
        if (cache.available) {
            var hitRateStr = cache.hit_rate !== null ? cache.hit_rate + '%' : '–';
            var cacheClass = cache.hit_rate !== null ? (cache.hit_rate >= 80 ? 'cs-s-ok' : cache.hit_rate >= 50 ? 'cs-s-warn' : 'cs-s-crit') : '';
            html += '<div class="cs-sum-card cs-sum-card-cache"><div class="cs-sum-card-title">&#9889; Object Cache</div>'
                + '<div class="cs-sum-card-stat">' + hitRateStr + '</div>'
                + '<div class="cs-sum-card-sub">'
                + (cache.hit_rate !== null ? '<span class="' + cacheClass + '">&#9679; ' + hitRateStr + ' hit rate</span>' : '')
                + '<span>' + (cache.hits || 0) + ' hits &middot; ' + (cache.misses || 0) + ' misses</span>'
                + (cache.persistent ? '<span class="cs-s-ok">Persistent cache active</span>' : '<span style="color:#888">Non-persistent (no Redis/Memcache)</span>')
                + '</div></div>';
        }

        // Assets card
        var assets = data.assets || {};
        var jsCount    = (assets.scripts || []).length;
        var cssCount   = (assets.styles  || []).length;
        var totalAsset = jsCount + cssCount;
        var assetWarn  = totalAsset > 40 ? ' cs-s-crit' : totalAsset > 20 ? ' cs-s-warn' : '';
        html += '<div class="cs-sum-card cs-sum-card-assets"><div class="cs-sum-card-title">&#128190; Assets</div>'
            + '<div class="cs-sum-card-stat' + assetWarn + '">' + totalAsset + '</div>'
            + '<div class="cs-sum-card-sub">'
            + '<span>' + jsCount + ' JS &middot; ' + cssCount + ' CSS</span>'
            + (totalAsset > 40 ? '<span class="cs-s-crit">&#9888; Heavy load</span>' : totalAsset > 20 ? '<span class="cs-s-warn">&#9651; Consider auditing</span>' : '')
            + '</div></div>';

        html += '</div>'; // cards

        if (pluginList.length > 0) {
            html += '<div><div class="cs-sum-section-title">Plugin Leaderboard — DB query time</div>';
            pluginList.slice(0, 8).forEach(function (p, i) {
                var bar = maxPMs > 0 ? Math.max(2, Math.round((p.total_ms / maxPMs) * 100)) : 2;
                var extras = '';
                if (p.slow) extras += ' &middot; <span style="color:#ce9178">' + p.slow + ' slow</span>';
                if (p.n1)   extras += ' &middot; <span style="color:#c586c0">' + p.n1 + ' N+1</span>';
                html += '<div class="cs-sum-lb-row">'
                    + '<span class="cs-sum-lb-rank">' + (i+1) + '</span>'
                    + '<span class="cs-sum-lb-name" title="' + esc(p.plugin) + '">' + esc(p.plugin) + '</span>'
                    + '<div class="cs-sum-lb-bar-wrap"><div class="cs-sum-lb-bar" style="width:' + bar + '%"></div></div>'
                    + '<span class="cs-sum-lb-val">' + p.count + ' &middot; ' + fmtMs(p.total_ms) + extras + '</span>'
                    + '</div>';
            });
            html += '</div>';
        }

        if (top5Q.length > 0) {
            html += '<div><div class="cs-sum-section-title">Slowest Queries</div>';
            top5Q.forEach(function (q) {
                html += '<div class="cs-sum-top-row">'
                    + '<span class="cs-sum-top-time cs-tv-' + speedClass(q.time_ms) + '">' + fmtMs(q.time_ms) + '</span>'
                    + '<span class="cs-sum-top-sql">' + esc(truncate(q.sql, 80)) + '</span>'
                    + '<span class="cs-sum-top-plugin" title="' + esc(q.plugin) + '">' + esc(q.plugin) + '</span>'
                    + '</div>';
            });
            html += '</div>';
        }

        if (n1List.length > 0) {
            html += '<div><div class="cs-sum-section-title">N+1 Query Patterns (' + n1List.length + ')'
                + ' <span style="font-size:9px;color:#888;text-transform:none;letter-spacing:0">'
                + '— same SQL structure, different values; usually a loop making individual lookups</span></div>';
            n1List.forEach(function (p) {
                html += '<div class="cs-sum-n1-row">'
                    + '<span class="cs-sum-n1-count">&times;' + p.count + '</span>'
                    + '<span class="cs-sum-n1-sql">' + esc(truncate(normalisePattern(p.example), 80)) + '</span>'
                    + '<span class="cs-sum-n1-plugin" title="' + esc(p.plugin) + '">' + esc(p.plugin) + '</span>'
                    + '</div>';
            });
            html += '</div>';
        }

        if (top5H.length > 0) {
            html += '<div><div class="cs-sum-section-title">Slowest HTTP Calls</div>';
            top5H.forEach(function (h) {
                html += '<div class="cs-sum-top-row">'
                    + '<span class="cs-sum-top-time cs-tv-' + speedClass(h.time_ms) + '">' + fmtMs(h.time_ms) + '</span>'
                    + '<span class="cs-sum-top-sql">' + methodBadge(h.method) + ' ' + esc(truncateUrl(h.url, 65)) + '</span>'
                    + '<span class="cs-sum-top-plugin" title="' + esc(h.plugin) + '">' + esc(h.plugin) + '</span>'
                    + '</div>';
            });
            html += '</div>';
        }

        if (dupeList.length > 0) {
            html += '<div><div class="cs-sum-section-title">Exact Duplicate Queries (' + dupeList.length + ' groups)</div>';
            dupeList.forEach(function (g) {
                html += '<div class="cs-sum-dupe-row">'
                    + '<span class="cs-sum-dupe-count">&times;' + g.count + '</span>'
                    + '<span class="cs-sum-dupe-sql">' + esc(truncate(g.sql, 80)) + '</span>'
                    + '<span class="cs-sum-dupe-avg">' + fmtMs(g.count > 0 ? g.total_ms / g.count : 0) + ' avg</span>'
                    + '</div>';
            });
            html += '</div>';
        }

        // Top hooks
        var topHooks = (data.hooks || []).slice(0, 8);
        if (topHooks.length > 0) {
            var maxHookMs = topHooks[0].total_ms || 1;
            html += '<div><div class="cs-sum-section-title">Slowest Hooks (top 8 by total time)</div>';
            topHooks.forEach(function (h) {
                var bar = maxHookMs > 0 ? Math.max(2, Math.round((h.total_ms / maxHookMs) * 100)) : 2;
                html += '<div class="cs-sum-lb-row">'
                    + '<span class="cs-sum-lb-name" title="' + esc(h.hook) + '">' + esc(h.hook) + '</span>'
                    + '<div class="cs-sum-lb-bar-wrap"><div class="cs-sum-lb-bar cs-sum-lb-bar-hook" style="width:' + bar + '%"></div></div>'
                    + '<span class="cs-sum-lb-val">' + h.count + '× &middot; ' + fmtMs(h.total_ms) + ' total &middot; max ' + fmtMs(h.max_ms) + '</span>'
                    + '</div>';
            });
            html += '</div>';
        }

        // ── Recent errors ─────────────────────────────────────────────────────
        var errorEntries = logs.filter(function (e) {
            var lvl = (e.level || '').toLowerCase();
            return lvl.indexOf('error') !== -1 || lvl.indexOf('warn') !== -1 || lvl.indexOf('dep') !== -1;
        });
        if (errorEntries.length > 0) {
            html += '<div><div class="cs-sum-section-title">PHP Errors &amp; Warnings (' + errorEntries.length + ')</div>';
            errorEntries.slice(0, 8).forEach(function (e) {
                var lvl  = (e.level || 'notice').toLowerCase();
                var cls  = lvl.indexOf('error') !== -1 ? 'cs-tv-critical'
                         : lvl.indexOf('warn')  !== -1 ? 'cs-tv-slow' : 'cs-tv-medium';
                html += '<div class="cs-sum-top-row">'
                    + '<span class="cs-sum-top-time ' + cls + '" style="min-width:72px">' + esc(e.level || 'notice') + '</span>'
                    + '<span class="cs-sum-top-sql">' + esc(truncate(e.message || '', 90)) + '</span>'
                    + (e.file ? '<span class="cs-sum-top-plugin" title="' + esc(e.file) + '">' + esc(e.file.split('/').pop()) + (e.line ? ':' + e.line : '') + '</span>' : '')
                    + '</div>';
            });
            if (errorEntries.length > 8) {
                html += '<div style="font-size:10px;color:#666;padding:4px 0 2px">+ ' + (errorEntries.length - 8) + ' more — see Logs tab</div>';
            }
            html += '</div>';
        }

        // ── Site Health ────────────────────────────────────────────────────────
        if (Object.keys(health).length > 0) {
            html += '<div><div class="cs-sum-section-title">Site Health</div>';

            // Helper: traffic-light badge
            function hBadge(ok, warnCond, label, detail) {
                var cls = ok ? 'cs-health-ok' : warnCond ? 'cs-health-warn' : 'cs-health-crit';
                var icon = ok ? '&#10003;' : '&#9888;';
                return '<div class="cs-health-row">'
                    + '<span class="cs-health-icon ' + cls + '">' + icon + '</span>'
                    + '<span class="cs-health-label">' + label + '</span>'
                    + (detail ? '<span class="cs-health-detail">' + detail + '</span>' : '')
                    + '</div>';
            }

            // HTTPS
            html += hBadge(health.site_https, false, 'HTTPS', health.site_https ? 'Serving over HTTPS' : 'Site is HTTP — not secure');

            // WP_DEBUG_DISPLAY
            html += hBadge(!health.wp_debug_display, true, 'WP_DEBUG_DISPLAY',
                health.wp_debug_display ? 'ON — PHP errors visible to visitors' : 'Off (safe)');

            // File editing
            html += hBadge(health.disallow_file_edit, true, 'DISALLOW_FILE_EDIT',
                health.disallow_file_edit ? 'Set (code editor disabled)' : 'Not set — wp-admin code editor is active');

            // File mods
            html += hBadge(health.disallow_file_mods, true, 'DISALLOW_FILE_MODS',
                health.disallow_file_mods ? 'Set (installs locked)' : 'Not set — plugin/theme installs allowed');

            // Autoloaded options
            var alOk = health.autoload_kb < 600;
            var alWarn = health.autoload_kb >= 600 && health.autoload_kb < 1500;
            var alTop = (health.large_autoloads || []).slice(0, 3).map(function (o) { return o.name + ' (' + o.size_kb + 'KB)'; }).join(', ');
            html += hBadge(alOk, alWarn,
                'Autoloaded options',
                health.autoload_kb + ' KB (' + health.autoload_count + ' rows)' + (alTop ? ' — largest: ' + alTop : ''));

            // Cron backlog
            var cronOk = health.cron_overdue === 0;
            var cronWarn = health.cron_overdue > 0 && health.cron_overdue < 10;
            var cronDetail = health.cron_overdue > 0
                ? health.cron_overdue + ' overdue of ' + health.cron_total + ' scheduled — ' + (health.cron_overdue_list || []).slice(0,2).map(function(e){return e.hook;}).join(', ')
                : health.cron_total + ' events scheduled, none overdue';
            html += hBadge(cronOk, cronWarn, 'WP-Cron', cronDetail);

            // "admin" username
            html += hBadge(!health.admin_user_exists, false,
                '"admin" username',
                health.admin_user_exists ? 'EXISTS — rename immediately' : 'Not in use');

            // DB prefix
            html += hBadge(!health.db_prefix_default, true,
                'DB table prefix',
                health.db_prefix_default ? 'Default wp_ prefix in use' : 'Custom prefix set');

            // XML-RPC
            html += hBadge(!health.xmlrpc_enabled, true,
                'XML-RPC',
                health.xmlrpc_enabled ? 'Enabled — disable if not needed' : 'Disabled');

            // PHP version
            var phpOk = !health.php_eol && !health.php_old;
            var phpWarn = !health.php_eol && health.php_old;
            html += hBadge(phpOk, phpWarn, 'PHP version', meta.php_version + (health.php_eol ? ' — EOL' : health.php_old ? ' — EOL Dec 2026' : ' — supported'));

            // Failed logins
            var fl1h = health.failed_logins_1h || 0;
            var fl24h = health.failed_logins_24h || 0;
            var flOk = fl1h < 3 && fl24h < 10;
            var flWarn = fl1h >= 3 && fl1h < 10;
            html += hBadge(flOk, flWarn,
                'Failed logins',
                fl1h + ' in 1 h · ' + fl24h + ' in 24 h' + (fl1h >= 10 ? ' — ACTIVE BRUTE FORCE' : ''));

            // readme / license exposure
            var exposed = [health.readme_exposed ? 'readme.html' : '', health.license_exposed ? 'license.txt' : ''].filter(Boolean);
            html += hBadge(exposed.length === 0, true,
                'Version disclosure',
                exposed.length > 0 ? exposed.join(', ') + ' present' : 'No version files found');

            // Author enumeration
            html += hBadge(!health.author_enum_risk, true,
                'Author enumeration',
                health.author_enum_risk ? '/?author=1 may reveal usernames' : 'Protected or disabled');

            // WordPress core
            html += hBadge(!health.wp_update_available, health.wp_update_available,
                'WordPress core',
                health.wp_update_available
                    ? (meta.wp_version || '?') + ' → ' + (health.wp_latest_version || 'update available')
                    : (meta.wp_version || '?') + ' — up to date');

            // MySQL / MariaDB version
            if (meta.mysql_version) {
                var dbParts2  = meta.mysql_version.split('.').map(Number);
                var dbMaj2 = dbParts2[0] || 0, dbMin2 = dbParts2[1] || 0;
                var dbIsMariaDB2 = !!health.is_mariadb;
                var dbEolOk2 = true, dbEolWarn2 = false;
                if (dbIsMariaDB2) {
                    if (dbMaj2 === 10 && dbMin2 <= 5) { dbEolOk2 = false; }
                    else if (dbMaj2 === 10 && dbMin2 < 11) { dbEolOk2 = false; dbEolWarn2 = true; }
                } else {
                    if (dbMaj2 < 8) { dbEolOk2 = false; }
                    else if (dbMaj2 === 8 && dbMin2 === 0) { dbEolOk2 = false; dbEolWarn2 = true; }
                }
                html += hBadge(dbEolOk2, dbEolWarn2,
                    (dbIsMariaDB2 ? 'MariaDB' : 'MySQL'),
                    meta.mysql_version + (dbEolOk2 ? ' — supported' : dbEolWarn2 ? ' — approaching EOL' : ' — end-of-life'));
            }

            // Nginx version
            if (health.nginx_version) {
                var ngParts2 = health.nginx_version.split('.').map(Number);
                var ngMaj2 = ngParts2[0] || 0, ngMin2 = ngParts2[1] || 0;
                var ngOk2   = ngMaj2 > 1 || (ngMaj2 === 1 && ngMin2 >= 26);
                var ngWarn2 = !ngOk2 && (ngMaj2 === 1 && ngMin2 >= 20);
                html += hBadge(ngOk2, ngWarn2, 'Nginx',
                    health.nginx_version + (ngOk2 ? ' — current' : ngWarn2 ? ' — outdated (stable: 1.26)' : ' — very old'));
            } else if (health.apache_version) {
                html += hBadge(true, false, 'Apache', health.apache_version);
            }

            // Plugin updates
            var puLen = (health.plugins_with_updates || []).length;
            html += hBadge(puLen === 0, puLen > 0,
                'Plugin updates',
                puLen === 0 ? 'All plugins up to date' : puLen + ' pending update' + (puLen > 1 ? 's' : ''));

            // Disk space
            if (health.disk_pct_used !== null && health.disk_pct_used !== undefined) {
                var diskOk   = health.disk_pct_used < 85;
                var diskWarn = health.disk_pct_used >= 85 && health.disk_pct_used < 95;
                html += hBadge(diskOk, diskWarn,
                    'Disk space',
                    health.disk_pct_used + '% used' + (health.disk_free_gb !== null ? ' · ' + health.disk_free_gb + ' GB free' : ''));
            }

            // OPcache
            if (health.opcache !== null && health.opcache !== undefined) {
                var ocEnabled = health.opcache.enabled;
                var ocOk      = ocEnabled && health.opcache.hit_rate >= 85 && health.opcache.mem_pct < 90 && health.opcache.oom_restarts === 0;
                var ocWarn    = ocEnabled && !ocOk;
                if (ocEnabled) {
                    html += hBadge(ocOk, ocWarn, 'OPcache',
                        'Enabled · ' + health.opcache.hit_rate + '% hit rate · ' + health.opcache.mem_pct + '% mem used'
                        + (health.opcache.oom_restarts > 0 ? ' · ' + health.opcache.oom_restarts + ' OOM restarts' : ''));
                } else {
                    html += hBadge(false, true, 'OPcache', 'Disabled — enable for significant performance gains');
                }
            }

            // Uploads writable
            html += hBadge(health.uploads_writable !== false, false,
                'Uploads dir',
                health.uploads_writable === false ? 'Not writable — media uploads will fail' : 'Writable');

            // PHP limits
            if (health.php_upload_max) {
                html += hBadge(true, false, 'PHP limits',
                    'upload: ' + health.php_upload_max + ' · post: ' + health.php_post_max + ' · exec: ' + health.php_max_exec + 's');
            }

            // Maintenance mode
            if (health.maintenance_stale !== undefined) {
                html += hBadge(!health.maintenance_stale, false,
                    'Maintenance mode',
                    health.maintenance_stale ? 'STUCK — stale .maintenance file present' : 'Not active');
            }

            // URL config
            if (health.url_host_mismatch !== undefined) {
                html += hBadge(!health.url_host_mismatch, health.url_host_mismatch,
                    'URL config',
                    health.url_host_mismatch ? 'Host mismatch — siteurl/home may cause login loops' : 'siteurl and home match current host');
            }

            // Rewrite rules
            if (health.rewrite_rules_missing !== undefined) {
                html += hBadge(!health.rewrite_rules_missing, false,
                    'Rewrite rules',
                    health.rewrite_rules_missing ? 'MISSING — flush permalinks at Settings → Permalinks' : 'Present');
            }

            // wp-config.php permissions
            if (health.wpconfig_world_readable !== undefined) {
                html += hBadge(!health.wpconfig_world_readable, health.wpconfig_world_readable,
                    'wp-config.php',
                    health.wpconfig_world_readable ? 'World-readable (644) — set chmod 600' : 'Permissions OK');
            }

            // debug.log
            if (health.debug_log_mb !== null && health.debug_log_mb !== undefined) {
                var logOk   = health.debug_log_mb < 10;
                var logWarn = health.debug_log_mb >= 10 && health.debug_log_mb < 100;
                html += hBadge(logOk, logWarn,
                    'debug.log',
                    health.debug_log_mb + ' MB' + (health.debug_log_mb >= 100 ? ' — DISK RISK' : health.debug_log_mb >= 10 ? ' — consider rotating' : ''));
            }

            // System load average
            if (health.load_avg && health.load_avg.length >= 1) {
                var la1 = health.load_avg[0], la5 = health.load_avg[1] || 0, la15 = health.load_avg[2] || 0;
                var cpus = health.cpu_count || 1;
                var laOk   = la1 < cpus;
                var laWarn = la1 >= cpus && la1 < cpus * 2;
                html += hBadge(laOk, laWarn, 'Load avg (' + cpus + ' CPU' + (cpus > 1 ? 's' : '') + ')',
                    la1 + ' / ' + la5 + ' / ' + la15 + ' (1m / 5m / 15m)');
            }

            html += '</div>';
        }

        summaryWrap.innerHTML = html;

        var sumPdfBtn = document.getElementById('cs-sum-pdf-btn');
        if (sumPdfBtn) sumPdfBtn.addEventListener('click', function () { exportPDF(); });
    }

    // ── Editor debug tab ──────────────────────────────────────────────────────
    function renderEditor() {
        var wrap = document.getElementById('cs-pp-editor-body');
        if (!wrap) { return; }

        if (editorLogs.length === 0) {
            wrap.innerHTML = '<div class="cs-empty">Monitoring browser requests&hellip; No activity yet.</div>';
            return;
        }

        var html = '<table class="cs-ptable"><thead><tr>'
            + '<th style="width:80px">Time</th>'
            + '<th style="width:54px">Method</th>'
            + '<th>URL</th>'
            + '<th style="width:60px">Status</th>'
            + '<th style="width:60px">Duration</th>'
            + '</tr></thead><tbody>';

        editorLogs.forEach(function (e) {
            var isFail = e.type === 'fail' || e.type === 'jserr';
            var rowCls = isFail ? ' class="cs-row-slow"' : '';
            var color  = isFail ? '#f87171' : '#a6e3a1';

            if (e.type === 'jserr') {
                var fileHtml = e.file ? '<br><span style="font-size:12px;opacity:.9;font-family:monospace;">' + esc(e.file) + '</span>' : '';
                html += '<tr' + rowCls + '>'
                    + '<td class="c-t"><span class="cs-badge cs-badge-c">JS</span></td>'
                    + '<td>ERR</td>'
                    + '<td colspan="2" style="color:' + color + ';font-size:11px;white-space:normal;word-break:break-word;">' + esc(e.detail||'') + fileHtml + '</td>'
                    + '<td>—</td>'
                    + '</tr>';
            } else {
                var path = (e.url||'').replace(window.location.origin,'').split('?')[0];
                var statusText = (isFail && (!e.status || e.status === 'ERR'))
                    ? '<span class="cs-status-err" style="font-size:10px;white-space:nowrap;">Timed out!</span>'
                    : esc(String(e.status||''));
                var methodCls  = 'cs-method cs-method-' + (e.method||'get').toLowerCase();
                html += '<tr' + rowCls + '>'
                    + '<td class="c-t">' + esc(e.ts) + '</td>'
                    + '<td><span class="' + methodCls + '">' + esc(e.method||'GET') + '</span></td>'
                    + '<td style="color:' + color + '" title="' + esc(e.url||'') + '">' + esc(path) + '</td>'
                    + '<td style="color:' + color + '">' + statusText + '</td>'
                    + '<td>' + (e.dur !== undefined ? e.dur + 'ms' : '—') + '</td>'
                    + '</tr>';
                if (isFail && e.detail) {
                    html += '<tr' + rowCls + '>'
                        + '<td></td><td colspan="4" style="font-size:10px;font-family:monospace;color:#fca5a5;white-space:pre-wrap;padding-bottom:6px;">'
                        + esc(e.detail.slice(0,400)) + '</td></tr>';
                }
            }
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;
    }

    // ── Footer ────────────────────────────────────────────────────────────────
    function updateFooter() {
        var slow  = filteredDB.filter(function (q) { return q.time_ms >= T_SLOW; }).length;
        var crit  = filteredDB.filter(function (q) { return q.time_ms >= T_CRITICAL; }).length;
        var dupes = filteredDB.filter(function (q) { return q.is_dupe; }).length;
        var n1s   = filteredDB.filter(function (q) { return isN1(q.sql); }).length;
        var total = filteredDB.reduce(function (s, q) { return s + q.time_ms; }, 0);
        var hSlow = filteredHTTP.filter(function (h) { return h.time_ms >= T_SLOW; }).length;

        var parts = [filteredDB.length + ' / ' + data.queries.length + ' queries'];
        if (crit)  parts.push('<span class="cs-foot-crit">' + crit + ' critical</span>');
        else if (slow) parts.push('<span class="cs-foot-warn">' + slow + ' slow</span>');
        if (dupes) parts.push('<span class="cs-foot-warn">' + dupes + ' dupes</span>');
        if (n1s)   parts.push('<span class="cs-foot-warn">' + n1s + ' N+1</span>');
        parts.push(fmtMs(total) + ' DB time');
        if (filteredHTTP.length > 0) parts.push(filteredHTTP.length + ' HTTP');
        if (hSlow) parts.push('<span class="cs-foot-warn">' + hSlow + ' slow HTTP</span>');
        footTxt.innerHTML = parts.join('  &middot;  ');
    }

    // ── Export JSON ───────────────────────────────────────────────────────────
    function exportJSON() {
        try {
            var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            var url  = URL.createObjectURL(blob);
            var a    = document.createElement('a');
            a.href = url; a.download = 'cs-perf-' + Date.now() + '.json';
            document.body.appendChild(a); a.click();
            document.body.removeChild(a); URL.revokeObjectURL(url);
        } catch (e) {
            var w = window.open('', '_blank');
            if (w) w.document.write('<pre>' + JSON.stringify(data, null, 2) + '</pre>');
        }
    }

    // ── Export PDF (print-to-PDF via new window) ──────────────────────────────
    function exportPDF() {
        var health  = data.health  || {};
        var miles   = data.milestones || [];
        var maxMs   = miles.length ? (miles[miles.length - 1].ms || meta.page_load_ms || 1) : 1;
        var now     = new Date().toISOString().replace('T', ' ').slice(0, 19) + ' UTC';
        var domain  = meta.url ? meta.url.replace(/^https?:\/\//, '').split('/')[0] : window.location.hostname;

        function esc2(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
        function stripForPdf(s) {
            return String(s).replace(/<br\s*\/?>/gi, ' ').replace(/<li>/gi, ' ').replace(/<[^>]+>/g, '').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&').replace(/&nbsp;/g,' ').replace(/&#?\w+;/g,'');
        }

        // ── CSS ───────────────────────────────────────────────────────────────
        var css = [
            'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:12px;color:#1a202c;margin:0;padding:28px 32px;line-height:1.5}',
            'h1{font-size:20px;font-weight:700;margin:0 0 2px}',
            '.report-meta{font-size:11px;color:#718096;margin-bottom:24px}',
            'h2{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#4a5568;border-bottom:1px solid #e2e8f0;padding-bottom:5px;margin:24px 0 12px}',
            '.issue{border-left:3px solid #cbd5e0;padding:7px 10px 6px;margin-bottom:7px;page-break-inside:avoid;background:#f7fafc;border-radius:0 3px 3px 0}',
            '.issue-critical{border-left-color:#e53e3e;background:#fff5f5}',
            '.issue-warning{border-left-color:#d69e2e;background:#fffbeb}',
            '.issue-info{border-left-color:#3182ce;background:#ebf8ff}',
            '.sev{display:inline-block;font-size:9px;font-weight:700;text-transform:uppercase;padding:1px 5px;border-radius:2px;margin-right:6px;vertical-align:middle}',
            '.sev-critical{background:#fed7d7;color:#c53030}',
            '.sev-warning{background:#fefcbf;color:#b7791f}',
            '.sev-info{background:#bee3f8;color:#2b6cb0}',
            '.issue-title{font-weight:600;font-size:12px}',
            '.issue-detail{color:#4a5568;font-size:11px;margin-top:2px}',
            '.fix{margin:8px 0 0 2px;padding:8px 10px;background:#fff;border:1px solid #e2e8f0;border-radius:3px;page-break-inside:avoid}',
            '.fix-why{font-size:11px;color:#4a5568;font-style:italic;margin-bottom:5px}',
            '.fix-steps{margin:0 0 0 16px;padding:0;font-size:11px;color:#2d3748}',
            '.fix-steps li{margin-bottom:3px}',
            '.fix-note{font-size:10px;color:#718096;margin-top:5px;font-style:italic}',
            'code{font-family:"SFMono-Regular",Consolas,monospace;font-size:10px;background:#edf2f7;padding:1px 4px;border-radius:2px}',
            '.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:4px}',
            '.card{border:1px solid #e2e8f0;border-radius:4px;padding:10px 12px}',
            '.card-title{font-size:10px;font-weight:700;text-transform:uppercase;color:#718096;margin-bottom:6px}',
            '.card-stat{font-size:24px;font-weight:700;color:#1a202c;line-height:1.1;margin-bottom:3px}',
            '.card-sub{font-size:10px;color:#718096}',
            '.tl-row{display:flex;align-items:center;margin-bottom:5px;gap:8px}',
            '.tl-label{width:130px;text-align:right;font-size:11px;color:#4a5568;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
            '.tl-track{flex:1;height:11px;background:#e2e8f0;border-radius:2px;overflow:hidden}',
            '.tl-fill{height:100%;border-radius:2px}',
            '.tl-time{font-size:10px;color:#4a5568;width:56px;text-align:right}',
            '.tl-delta{font-size:10px;color:#48bb78;width:52px}',
            '.health-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:4px 24px}',
            '.health-row{display:flex;gap:6px;align-items:baseline;font-size:11px;padding:2px 0;border-bottom:1px solid #f0f0f0}',
            '.health-icon{font-weight:700;width:14px;flex-shrink:0}',
            '.h-ok{color:#276749}.h-warn{color:#b7791f}.h-crit{color:#c53030}',
            '.health-label{font-weight:600;width:160px;flex-shrink:0}',
            '.health-detail{color:#718096;font-size:10px}',
            '.no-issues{color:#276749;font-weight:600;padding:8px 0}',
            '@media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact}h2{page-break-after:avoid}.issue,.fix{page-break-inside:avoid}}',
            '.tl-bar-0{background:#60a5fa}.tl-bar-1{background:#4ade80}.tl-bar-2{background:#34d399}.tl-bar-3{background:#a3e635}.tl-bar-4{background:#fbbf24}.tl-bar-5{background:#fb923c}'
        ].join('\n');

        // ── Header ────────────────────────────────────────────────────────────
        var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>CS Monitor — ' + esc2(domain) + '</title><style>' + css + '</style></head><body>';
        html += '<h1>CS Monitor Report</h1>';
        html += '<div class="report-meta">'
            + esc2(meta.url || window.location.href) + ' &nbsp;·&nbsp; ' + esc2(now)
            + (meta.php_version ? ' &nbsp;·&nbsp; PHP ' + esc2(meta.php_version) : '')
            + (meta.wp_version  ? ' &nbsp;·&nbsp; WP '  + esc2(meta.wp_version)  : '')
            + (meta.mysql_version ? ' &nbsp;·&nbsp; MySQL ' + esc2(meta.mysql_version) : '')
            + '</div>';

        // ── Issues ────────────────────────────────────────────────────────────
        html += '<h2>Issues (' + issuesList.length + ')</h2>';
        if (issuesList.length === 0) {
            html += '<div class="no-issues">&#10003; No issues detected on this page load.</div>';
        } else {
            var lastSev2 = null;
            issuesList.forEach(function (issue) {
                var sev  = issue.sev || 'info';
                var fix  = getIssueFix(issue);
                html += '<div class="issue issue-' + sev + '">'
                    + '<div><span class="sev sev-' + sev + '">' + sev + '</span><span class="issue-title">' + esc2(issue.title) + '</span></div>'
                    + (issue.detail ? '<div class="issue-detail">' + esc2(issue.detail) + '</div>' : '');
                if (fix) {
                    html += '<div class="fix">';
                    if (fix.why)   html += '<div class="fix-why">' + stripForPdf(fix.why) + '</div>';
                    if (fix.steps && fix.steps.length) {
                        html += '<ol class="fix-steps">';
                        fix.steps.forEach(function (s) { html += '<li>' + stripForPdf(s) + '</li>'; });
                        html += '</ol>';
                    }
                    if (fix.note)  html += '<div class="fix-note">&#9432; ' + stripForPdf(fix.note) + '</div>';
                    html += '</div>';
                }
                html += '</div>';
            });
        }

        // ── Summary cards ─────────────────────────────────────────────────────
        html += '<h2>Performance Summary</h2>';
        var memPct3 = 0, memLim = parseInt(meta.memory_limit, 10) || 0;
        if (memLim > 0 && meta.memory_peak_mb) memPct3 = Math.round(meta.memory_peak_mb / memLim * 100);
        html += '<div class="cards">';
        html += '<div class="card"><div class="card-title">&#9881; Environment</div>'
            + '<div class="card-sub">PHP ' + esc2(meta.php_version || '?') + ' &nbsp; WP ' + esc2(meta.wp_version || '?') + '</div>'
            + (meta.mysql_version ? '<div class="card-sub">MySQL ' + esc2(meta.mysql_version) + '</div>' : '')
            + (meta.memory_peak_mb ? '<div class="card-sub">Mem peak: ' + meta.memory_peak_mb + 'MB / ' + esc2(meta.memory_limit || '?') + ' (' + memPct3 + '%)</div>' : '')
            + (meta.active_theme ? '<div class="card-sub">Theme: ' + esc2(meta.active_theme) + '</div>' : '')
            + '</div>';
        html += '<div class="card"><div class="card-title">&#128200; DB Queries</div>'
            + '<div class="card-stat">' + (meta.query_count || 0) + '</div>'
            + '<div class="card-sub">' + fmtMs(meta.query_total_ms) + ' total</div>'
            + '</div>';
        html += '<div class="card"><div class="card-title">&#127760; HTTP / REST</div>'
            + '<div class="card-stat">' + (meta.http_count || 0) + '</div>'
            + '<div class="card-sub">' + fmtMs(meta.http_total_ms) + ' total</div>'
            + '</div>';
        html += '</div>';

        // ── Request timeline ──────────────────────────────────────────────────
        if (miles.length > 1) {
            html += '<h2>Request Timeline</h2>';
            var colors = ['tl-bar-0','tl-bar-1','tl-bar-2','tl-bar-3','tl-bar-4','tl-bar-5'];
            miles.forEach(function (m, i) {
                var pct   = Math.min(100, Math.round(m.ms / maxMs * 100));
                var delta = i > 0 ? (m.ms - miles[i-1].ms) : 0;
                var col   = colors[i % colors.length];
                html += '<div class="tl-row">'
                    + '<div class="tl-label">' + esc2(m.label) + '</div>'
                    + '<div class="tl-track"><div class="tl-fill ' + col + '" style="width:' + pct + '%"></div></div>'
                    + '<div class="tl-time">' + fmtMs(m.ms) + '</div>'
                    + '<div class="tl-delta">' + (delta > 0 ? '+' + fmtMs(delta) : '') + '</div>'
                    + '</div>';
            });
        }

        // ── Site Health ───────────────────────────────────────────────────────
        html += '<h2>Site Health</h2><div class="health-grid">';
        function hRow(ok, warn, label, detail) {
            var cls = ok ? 'h-ok' : warn ? 'h-warn' : 'h-crit';
            var ico = ok ? '&#10003;' : '&#9888;';
            return '<div class="health-row"><span class="health-icon ' + cls + '">' + ico + '</span>'
                + '<span class="health-label">' + esc2(label) + '</span>'
                + '<span class="health-detail">' + esc2(detail) + '</span></div>';
        }
        html += hRow(health.site_https, false, 'HTTPS', health.site_https ? 'Serving over HTTPS' : 'HTTP — not secure');
        html += hRow(!health.wp_debug_display, true, 'WP_DEBUG_DISPLAY', health.wp_debug_display ? 'ON — errors visible to visitors' : 'Off (safe)');
        html += hRow(!health.xmlrpc_enabled, true, 'XML-RPC', health.xmlrpc_enabled ? 'Enabled — disable if not needed' : 'Disabled');
        html += hRow(!health.admin_user_exists, false, '"admin" username', health.admin_user_exists ? 'EXISTS — rename immediately' : 'Not in use');
        html += hRow(!health.db_prefix_default, true, 'DB table prefix', health.db_prefix_default ? 'Default wp_ in use' : 'Custom prefix set');
        html += hRow(health.disallow_file_edit, true, 'DISALLOW_FILE_EDIT', health.disallow_file_edit ? 'Set' : 'Not set');
        html += hRow(!health.rewrite_rules_missing, false, 'Rewrite rules', health.rewrite_rules_missing ? 'MISSING — flush permalinks' : 'Present');
        html += hRow(!health.maintenance_stale, false, 'Maintenance mode', health.maintenance_stale ? 'STUCK — delete .maintenance' : 'Not active');
        html += hRow(!health.url_host_mismatch, true, 'URL config', health.url_host_mismatch ? 'Host mismatch detected' : 'OK');
        html += hRow(!health.wpconfig_world_readable, true, 'wp-config.php', health.wpconfig_world_readable ? 'World-readable (chmod 600)' : 'Permissions OK');
        var fl1h = health.failed_logins_1h || 0;
        html += hRow(fl1h < 3, fl1h >= 3 && fl1h < 10, 'Failed logins', fl1h + ' in 1h · ' + (health.failed_logins_24h || 0) + ' in 24h');
        html += hRow(!health.php_eol && !health.php_old, !health.php_eol && health.php_old, 'PHP version', (meta.php_version || '?') + (health.php_eol ? ' — EOL' : health.php_old ? ' — EOL Dec 2026' : ' — supported'));
        if (health.disk_pct_used !== null && health.disk_pct_used !== undefined) {
            html += hRow(health.disk_pct_used < 85, health.disk_pct_used >= 85 && health.disk_pct_used < 95, 'Disk space', health.disk_pct_used + '% used · ' + (health.disk_free_gb || '?') + ' GB free');
        }
        var oc = health.opcache;
        if (oc) {
            html += hRow(oc.enabled && oc.hit_rate >= 85 && oc.oom_restarts === 0, oc.enabled && oc.hit_rate < 85, 'OPcache', oc.enabled ? oc.hit_rate + '% hit rate · ' + oc.mem_pct + '% mem' : 'Disabled');
        }
        var pUpdates = (health.plugins_with_updates || []).length;
        html += hRow(pUpdates === 0, pUpdates > 0, 'Plugin updates', pUpdates === 0 ? 'All up to date' : pUpdates + ' pending');
        if (health.debug_log_mb !== null && health.debug_log_mb !== undefined) {
            html += hRow(health.debug_log_mb < 10, health.debug_log_mb >= 10 && health.debug_log_mb < 100, 'debug.log', health.debug_log_mb + ' MB');
        }
        html += '</div>';

        html += '</body></html>';

        // Open in new window and trigger print dialog
        var w = window.open('', '_blank', 'width=900,height=700');
        if (!w) { alert('Pop-up blocked — allow pop-ups for this page and try again.'); return; }
        w.document.open();
        w.document.write(html);
        w.document.close();
        w.onload = function () { w.focus(); w.print(); };
        // Fallback if onload already fired
        setTimeout(function () { try { w.focus(); w.print(); } catch(e) {} }, 600);
    }

    // ── Copy current tab to clipboard ─────────────────────────────────────────
    function copyCurrentTab() {
        var tab   = activeTab;
        var lines = ['=== CS Monitor: ' + tab.toUpperCase() + ' ===', 'URL: ' + (meta.url || window.location.href), ''];

        switch (tab) {
            case 'issues':
                if (issuesList.length === 0) {
                    lines.push('No issues detected.');
                } else {
                    issuesList.forEach(function (issue) {
                        lines.push('[' + issue.sev.toUpperCase() + '] ' + issue.title
                            + (issue.detail ? ' — ' + issue.detail : '')
                            + ' (\u2192 ' + issue.tab + ')');
                    });
                }
                break;
            case 'db':
                lines.push('Queries: ' + filteredDB.length + ' / ' + data.queries.length);
                lines.push('');
                filteredDB.forEach(function (q, i) {
                    lines.push((i + 1) + '. [' + q.keyword + '] ' + q.sql.replace(/\s+/g, ' ').trim());
                    lines.push('   Plugin: ' + q.plugin + ' | Rows: ' + (q.rows >= 0 ? q.rows : '\u2013') + ' | Time: ' + fmtMs(q.time_ms));
                    if (q.is_dupe)   lines.push('   [DUPLICATE]');
                    if (isN1(q.sql)) lines.push('   [N+1 PATTERN]');
                });
                break;
            case 'http':
                lines.push('HTTP calls: ' + filteredHTTP.length);
                lines.push('');
                filteredHTTP.forEach(function (h, i) {
                    lines.push((i + 1) + '. [' + (h.method || 'GET') + '] ' + h.url);
                    lines.push('   Plugin: ' + h.plugin + ' | Status: ' + (h.status || 'ERR') + ' | Time: ' + fmtMs(h.time_ms));
                    if (h.error) lines.push('   Error: ' + h.error);
                });
                break;
            case 'logs':
                var logs = data.logs || [];
                lines.push('Log entries: ' + logs.length);
                lines.push('');
                logs.forEach(function (l) {
                    lines.push('[' + (l.level || 'info').toUpperCase() + '] ' + (l.message || '')
                        + (l.file ? ' (' + l.file + (l.line ? ':' + l.line : '') + ')' : ''));
                });
                break;
            case 'assets':
                var assets = data.assets || {};
                var scripts = assets.scripts || [], styles = assets.styles || [];
                lines.push('Scripts: ' + scripts.length + ' | Styles: ' + styles.length);
                lines.push('');
                lines.push('--- Scripts ---');
                scripts.forEach(function (a) { lines.push(a.handle + ' | ' + a.plugin + ' | ' + (a.src || '')); });
                lines.push('');
                lines.push('--- Styles ---');
                styles.forEach(function (a) { lines.push(a.handle + ' | ' + a.plugin + ' | ' + (a.src || '')); });
                break;
            case 'hooks':
                var hooks = data.hooks || [];
                lines.push('Hooks: ' + hooks.length);
                lines.push('');
                hooks.slice(0, 50).forEach(function (h) {
                    lines.push(h.hook + ' | ' + h.count + 'x | ' + fmtMs(h.total_ms) + ' total | max ' + fmtMs(h.max_ms));
                });
                break;
            case 'request':
                var req = data.request || {};
                if (req.method)        lines.push('Method: ' + req.method);
                if (req.url)           lines.push('Request URL: ' + req.url);
                if (req.matched_rule)  lines.push('Rewrite rule: ' + req.matched_rule);
                if (req.query_vars && Object.keys(req.query_vars).length) {
                    lines.push('Query vars:');
                    Object.keys(req.query_vars).forEach(function (k) { lines.push('  ' + k + ': ' + req.query_vars[k]); });
                }
                if (req.get && Object.keys(req.get).length) {
                    lines.push('GET:');
                    Object.keys(req.get).forEach(function (k) { lines.push('  ' + k + ': ' + req.get[k]); });
                }
                if (req.post && Object.keys(req.post).length) {
                    lines.push('POST:');
                    Object.keys(req.post).forEach(function (k) { lines.push('  ' + k + ': ' + req.post[k]); });
                }
                if (req.user_roles && req.user_roles.length) lines.push('Roles: ' + req.user_roles.join(', '));
                break;
            case 'template':
                var tmpl = data.template || {};
                lines.push('Active template: ' + (tmpl.final || '(unknown)'));
                lines.push('');
                (tmpl.hierarchy || []).forEach(function (f) {
                    lines.push((f.exists ? '[x] ' : '[ ] ') + f.file);
                });
                break;
            case 'transients':
                var trans = data.transients || [];
                lines.push('Transients: ' + trans.length);
                lines.push('');
                trans.forEach(function (t) {
                    lines.push(t.key + ' | ' + (t.hit ? 'HIT' : 'MISS')
                        + ' | gets: ' + t.gets + ' | sets: ' + t.sets + ' | deletes: ' + t.deletes);
                });
                break;
            case 'summary':
                var cQueries = data.queries || [], cHttp = data.http || [], cLogs = data.logs || [];

                // Environment
                if (meta.php_version) {
                    lines.push('Environment');
                    lines.push('  PHP: ' + meta.php_version + ' | WP: ' + (meta.wp_version || '?') + (meta.mysql_version ? ' | MySQL: ' + meta.mysql_version : ''));
                    if (meta.memory_peak_mb) lines.push('  Memory peak: ' + meta.memory_peak_mb + 'MB / ' + (meta.memory_limit || '?'));
                    if (meta.active_theme)   lines.push('  Theme: ' + meta.active_theme);
                    if (meta.is_multisite)   lines.push('  Multisite: yes');
                    lines.push('');
                }

                // DB card
                var cSlowQ = cQueries.filter(function (q) { return q.time_ms >= T_SLOW; }).length;
                var cCritQ = cQueries.filter(function (q) { return q.time_ms >= T_CRITICAL; }).length;
                var cDupeQ = cQueries.filter(function (q) { return q.is_dupe; }).length;
                var cN1Cnt = Object.keys(n1Patterns).length;
                lines.push('DB Queries: ' + meta.query_count + ' | ' + fmtMs(meta.query_total_ms) + ' total'
                    + (cCritQ ? ' | ' + cCritQ + ' critical' : cSlowQ ? ' | ' + cSlowQ + ' slow' : ' | no slow queries')
                    + (cDupeQ ? ' | ' + cDupeQ + ' dupes' : '')
                    + (cN1Cnt ? ' | ' + cN1Cnt + ' N+1 pattern' + (cN1Cnt > 1 ? 's' : '') : ''));

                // HTTP card
                var cSlowH = cHttp.filter(function (h) { return h.time_ms >= T_SLOW; }).length;
                var cCacH  = cHttp.filter(function (h) { return h.cached; }).length;
                var cErrH  = cHttp.filter(function (h) { return !!h.error; }).length;
                lines.push('HTTP / REST: ' + meta.http_count + ' calls | ' + fmtMs(meta.http_total_ms) + ' total'
                    + (cSlowH ? ' | ' + cSlowH + ' slow' : '')
                    + (cCacH  ? ' | ' + cCacH  + ' cached' : '')
                    + (cErrH  ? ' | ' + cErrH  + ' errors' : '')
                    + (cHttp.length === 0 ? ' | no outbound calls' : ''));

                // Logs card
                var cErrL  = cLogs.filter(function (e) { return (e.level || '').toLowerCase().indexOf('error') !== -1; }).length;
                var cWarnL = cLogs.filter(function (e) { return (e.level || '').toLowerCase().indexOf('warn')  !== -1; }).length;
                var cDepL  = cLogs.filter(function (e) { return (e.level || '').toLowerCase().indexOf('dep')   !== -1; }).length;
                lines.push('Logs: ' + cLogs.length
                    + (cErrL  ? ' | ' + cErrL  + ' errors' : '')
                    + (cWarnL ? ' | ' + cWarnL + ' warnings' : '')
                    + (cDepL  ? ' | ' + cDepL  + ' deprecated' : '')
                    + (cLogs.length === 0 ? ' | none' : ''));

                // Cache card
                var cCache = data.cache || {};
                if (cCache.available) {
                    var cHitStr = cCache.hit_rate !== null ? cCache.hit_rate + '%' : 'n/a';
                    lines.push('Object Cache: ' + cHitStr + ' hit rate | ' + (cCache.hits || 0) + ' hits, ' + (cCache.misses || 0) + ' misses'
                        + (cCache.persistent ? ' | persistent' : ' | non-persistent'));
                }

                // Assets card
                var cAssets = data.assets || {};
                lines.push('Assets: ' + ((cAssets.scripts || []).length + (cAssets.styles || []).length)
                    + ' | ' + (cAssets.scripts || []).length + ' JS, ' + (cAssets.styles || []).length + ' CSS');

                lines.push('');

                // Plugin leaderboard
                var cByP = {};
                cQueries.forEach(function (q) {
                    if (!cByP[q.plugin]) cByP[q.plugin] = { count: 0, total_ms: 0, slow: 0, n1: 0 };
                    cByP[q.plugin].count++; cByP[q.plugin].total_ms += q.time_ms;
                    if (q.time_ms >= T_SLOW) cByP[q.plugin].slow++;
                    if (isN1(q.sql))         cByP[q.plugin].n1++;
                });
                var cPluginList = Object.keys(cByP).map(function (p) {
                    return { plugin: p, count: cByP[p].count, total_ms: cByP[p].total_ms, slow: cByP[p].slow, n1: cByP[p].n1 };
                }).sort(function (a, b) { return b.total_ms - a.total_ms; });
                if (cPluginList.length > 0) {
                    lines.push('Plugin Leaderboard — DB query time');
                    cPluginList.slice(0, 8).forEach(function (p, i) {
                        lines.push('  ' + (i + 1) + '. ' + p.plugin + ' \u2014 ' + p.count + ' queries, ' + fmtMs(p.total_ms)
                            + (p.slow ? ', ' + p.slow + ' slow' : '')
                            + (p.n1   ? ', ' + p.n1   + ' N+1'  : ''));
                    });
                    lines.push('');
                }

                // Slowest queries (top 5)
                var cTop5Q = cQueries.slice().sort(function (a, b) { return b.time_ms - a.time_ms; }).slice(0, 5);
                if (cTop5Q.length > 0) {
                    lines.push('Slowest Queries');
                    cTop5Q.forEach(function (q) {
                        lines.push('  ' + fmtMs(q.time_ms) + '  ' + q.sql.replace(/\s+/g, ' ').trim().slice(0, 100) + '  [' + q.plugin + ']');
                    });
                    lines.push('');
                }

                // N+1 patterns
                var cN1List = Object.values(n1Patterns).sort(function (a, b) { return b.count - a.count; });
                if (cN1List.length > 0) {
                    lines.push('N+1 Query Patterns');
                    cN1List.forEach(function (p) {
                        lines.push('  x' + p.count + '  ' + normalisePattern(p.example).slice(0, 100) + '  [' + p.plugin + ']');
                    });
                    lines.push('');
                }

                // Slowest HTTP (top 5)
                var cTop5H = cHttp.slice().sort(function (a, b) { return b.time_ms - a.time_ms; }).slice(0, 5);
                if (cTop5H.length > 0) {
                    lines.push('Slowest HTTP Calls');
                    cTop5H.forEach(function (h) {
                        lines.push('  ' + fmtMs(h.time_ms) + '  [' + (h.method || 'GET') + '] ' + (h.url || '').slice(0, 100) + '  [' + h.plugin + ']');
                    });
                    lines.push('');
                }

                // Duplicate queries
                var cDupeGroups = {};
                cQueries.forEach(function (q) {
                    var fp = q.sql.replace(/\s+/g, ' ').toLowerCase().trim();
                    if (!cDupeGroups[fp]) cDupeGroups[fp] = { sql: q.sql, count: 0, total_ms: 0 };
                    cDupeGroups[fp].count++; cDupeGroups[fp].total_ms += q.time_ms;
                });
                var cDupeList = Object.values(cDupeGroups).filter(function (g) { return g.count > 1; })
                    .sort(function (a, b) { return b.count - a.count; }).slice(0, 8);
                if (cDupeList.length > 0) {
                    lines.push('Exact Duplicate Queries (' + cDupeList.length + ' groups)');
                    cDupeList.forEach(function (g) {
                        lines.push('  x' + g.count + '  ' + g.sql.replace(/\s+/g, ' ').trim().slice(0, 100)
                            + '  (' + fmtMs(g.count > 0 ? g.total_ms / g.count : 0) + ' avg)');
                    });
                    lines.push('');
                }

                // Slowest hooks (top 8)
                var cTopHooks = (data.hooks || []).slice(0, 8);
                if (cTopHooks.length > 0) {
                    lines.push('Slowest Hooks');
                    cTopHooks.forEach(function (h) {
                        lines.push('  ' + h.hook + ' | ' + h.count + 'x | ' + fmtMs(h.total_ms) + ' total | max ' + fmtMs(h.max_ms));
                    });
                }
                break;
        }

        var text   = lines.join('\n');
        var copyBtn = document.getElementById('cs-perf-copy');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                flashCopyBtn(copyBtn, 'Copied!');
            }).catch(function () { fallbackCopy(text, copyBtn); });
        } else {
            fallbackCopy(text, copyBtn);
        }
    }

    function fallbackCopy(text, btn) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0';
        document.body.appendChild(ta);
        ta.focus(); ta.select();
        try { document.execCommand('copy'); flashCopyBtn(btn, 'Copied!'); }
        catch (e)                          { flashCopyBtn(btn, 'Failed'); }
        document.body.removeChild(ta);
    }

    function flashCopyBtn(btn, msg) {
        if (!btn) return;
        var orig = btn.textContent;
        btn.textContent = msg;
        setTimeout(function () { btn.textContent = orig; }, 1500);
    }

    // ── Resize ────────────────────────────────────────────────────────────────
    function bindResizeHandle() {
        var startY, startH;
        resizeHandle.addEventListener('mousedown', function (e) {
            e.preventDefault(); startY = e.clientY; startH = panel.offsetHeight;
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup',   onUp);
        });
        function onMove(e) {
            panel.style.transition = 'none';
            var h = clampHeight(startH + (startY - e.clientY));
            panel.style.height = h + 'px';
            setPadding(h);
            if (!panel.classList.contains('cs-perf-open')) {
                panel.classList.add('cs-perf-open'); panel.classList.remove('cs-perf-collapsed');
                document.getElementById('cs-perf-toggle-arrow').innerHTML = '&#9660;'; toggleBtn.setAttribute('aria-expanded', 'true');
            }
        }
        function onUp() {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup',   onUp);
            // Height not persisted — panel always opens at DEFAULT_H.
            panel.style.transition = '';
        }
    }

    // ── Sort header binding ───────────────────────────────────────────────────
    function bindSortHeaders() {
        Array.prototype.forEach.call(document.querySelectorAll('.cs-sortable'), function (th) {
            th.addEventListener('click', function () {
                var col = th.dataset.sort;
                if (sortCol === col) sortDir = sortDir === 'desc' ? 'asc' : 'desc';
                else { sortCol = col; sortDir = 'desc'; }
                updateSortHeaders();
                applyFilters();
            });
        });
    }

    // ── Events ────────────────────────────────────────────────────────────────
    function bindEvents() {
        document.getElementById('cs-perf-header').addEventListener('click', function (e) {
            if (toggleBtn.contains(e.target) || (exportBtn && exportBtn.contains(e.target))) return;
            togglePanel();
        });

        toggleBtn.addEventListener('click', function (e) { e.stopPropagation(); togglePanel(); });
        // iOS Safari fallback: touchend fires reliably even when parent has
        // overflow/fixed positioning quirks that can swallow click events.
        toggleBtn.addEventListener('touchend', function (e) {
            e.preventDefault(); // prevent the follow-up click from double-toggling
            e.stopPropagation();
            togglePanel();
        });
        if (exportBtn) exportBtn.addEventListener('click', function (e) { e.stopPropagation(); exportJSON(); });
        var clearBtn = document.getElementById('cs-perf-clear');
        if (clearBtn) clearBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            // Wipe all collected data
            data.queries   = [];
            data.http      = [];
            data.hooks     = [];
            data.logs      = [];
            data.errors    = [];
            data.transients = [];
            data.assets    = { scripts: [], styles: [] };
            data.cache     = {};
            data.template  = { final: '', hierarchy: [] };
            editorLogs     = [];
            editorFailCount = 0;
            n1Patterns     = {};
            if (editorBadgeEl) editorBadgeEl.textContent = '';
            // Reset meta counts so badges show 0 after clear.
            if (meta) { meta.query_count = 0; meta.query_total_ms = 0; meta.http_count = 0; }
            computeN1Patterns();
            computeIssues();
            applyFilters();
            renderLogs();
            renderAssets();
            renderHooks();
            renderIssues();
            renderEditor();
            renderTransients();
            renderSummary();
            updateBadges();
            if (footTxt) footTxt.textContent = 'Cleared';
        });
        var copyBtn = document.getElementById('cs-perf-copy');
        if (copyBtn) copyBtn.addEventListener('click', function (e) { e.stopPropagation(); copyCurrentTab(); });

        tabBtns.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                switchTab(btn.dataset.tab);
                if (!panelLocked && !panel.classList.contains('cs-perf-open'))
                    openPanel(DEFAULT_H, true);
            });
        });

        [searchInput, pluginSel, speedSel].forEach(function (el) {
            el.addEventListener('input',  applyFilters);
            el.addEventListener('change', applyFilters);
        });
        dupeChk.addEventListener('change', applyFilters);

        filterBar.addEventListener('click', function (e) { e.stopPropagation(); });
        document.getElementById('cs-perf-tabs').addEventListener('click', function (e) { e.stopPropagation(); });

        // Log filters
        [logSearch, logLevel, logSource].forEach(function (el) {
            if (!el) return;
            el.addEventListener('input',  renderLogs);
            el.addEventListener('change', renderLogs);
            el.addEventListener('click',  function (e) { e.stopPropagation(); });
        });
        var logFiltersEl = document.querySelector('.cs-log-filters');
        if (logFiltersEl) logFiltersEl.addEventListener('click', function (e) { e.stopPropagation(); });

        // Debug toggle
        renderDebugBar();
        var debugToggleBtn = document.getElementById('cs-debug-toggle');
        var debugBar       = document.getElementById('cs-debug-bar');
        if (debugBar) debugBar.addEventListener('click', function (e) { e.stopPropagation(); });
        if (debugToggleBtn) {
            debugToggleBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var enable   = !(meta.wp_debug && meta.wp_debug_log);
                var msgEl    = document.getElementById('cs-debug-msg');
                debugToggleBtn.disabled = true;
                debugToggleBtn.textContent = 'Saving…';
                var xhr = new XMLHttpRequest();
                xhr.open('POST', meta.ajax_url);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function () {
                    debugToggleBtn.disabled = false;
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.success) {
                            meta.wp_debug     = resp.data.enabled;
                            meta.wp_debug_log = resp.data.enabled;
                            renderDebugBar();
                            if (msgEl) { msgEl.textContent = resp.data.message; setTimeout(function () { msgEl.textContent = ''; }, 4000); }
                        } else {
                            if (msgEl) msgEl.textContent = 'Error: ' + (resp.data || 'unknown');
                        }
                    } catch (err) {
                        if (msgEl) msgEl.textContent = 'Unexpected error';
                    }
                };
                xhr.onerror = function () { debugToggleBtn.disabled = false; if (msgEl) msgEl.textContent = 'Request failed'; };
                xhr.send('action=csdt_devtools_perf_debug_toggle&nonce=' + encodeURIComponent(meta.debug_nonce) + '&enable=' + (enable ? '1' : '0'));
            });
        }

        // Assets filters
        [assetSearch, assetType, assetPlugin].forEach(function (el) {
            if (!el) return;
            el.addEventListener('input',  renderAssets);
            el.addEventListener('change', renderAssets);
            el.addEventListener('click',  function (e) { e.stopPropagation(); });
        });
        var assetsFiltersEl = document.querySelector('.cs-assets-filters');
        if (assetsFiltersEl) assetsFiltersEl.addEventListener('click', function (e) { e.stopPropagation(); });

        // Hooks filter
        if (hookSearch) {
            hookSearch.addEventListener('input',  renderHooks);
            hookSearch.addEventListener('click',  function (e) { e.stopPropagation(); });
        }
        var hooksFiltersEl = document.querySelector('.cs-hooks-filters');
        if (hooksFiltersEl) hooksFiltersEl.addEventListener('click', function (e) { e.stopPropagation(); });

        // Hooks sort headers
        Array.prototype.forEach.call(document.querySelectorAll('#cs-pp-hooks .cs-sortable'), function (th) {
            th.addEventListener('click', function (e) {
                e.stopPropagation();
                var col = th.dataset.sort;
                if (hookSortCol === col) hookSortDir = hookSortDir === 'desc' ? 'asc' : 'desc';
                else { hookSortCol = col; hookSortDir = 'desc'; }
                // Update hook sort header arrows
                Array.prototype.forEach.call(document.querySelectorAll('#cs-pp-hooks .cs-sortable'), function (h) {
                    var hCol = h.dataset.sort;
                    var labels = { total_ms: 'Total', count: 'Count', max_ms: 'Max' };
                    var arrow = hCol !== hookSortCol ? '&#8597;' : (hookSortDir === 'desc' ? '&#8595;' : '&#8593;');
                    h.innerHTML = (labels[hCol] || hCol) + '&nbsp;' + arrow;
                });
                renderHooks();
            });
        });

        bindResizeHandle();
        bindSortHeaders();

        document.addEventListener('keydown', function (e) {
            if (e.ctrlKey && e.shiftKey && (e.key === 'm' || e.key === 'M')) {
                e.preventDefault(); togglePanel();
            }
            if (e.key === 'Escape') {
                // Collapse any open EXPLAIN result divs and detail rows
                var hadOpen = false;
                Array.prototype.forEach.call(document.querySelectorAll('.cs-explain-result'), function (r) {
                    if (r.innerHTML) { r.innerHTML = ''; hadOpen = true; }
                });
                Array.prototype.forEach.call(document.querySelectorAll('.cs-row-detail'), function (d) {
                    if (d.style.display !== 'none') { d.style.display = 'none'; hadOpen = true; }
                });
                // Reset any disabled EXPLAIN buttons
                if (hadOpen) {
                    Array.prototype.forEach.call(document.querySelectorAll('.cs-explain-btn'), function (btn) {
                        btn.disabled = false; btn.textContent = 'EXPLAIN';
                    });
                }
            }
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    function clampHeight(h) { return Math.max(MIN_H, Math.min(Math.floor(window.innerHeight * MAX_H_PCT), h)); }

    function fmtMs(ms) {
        ms = +ms || 0;
        if (ms < 1000) return ms.toFixed(1) + 'ms';
        return (ms / 1000).toFixed(2) + 's';
    }

    function speedClass(ms) {
        return ms >= T_CRITICAL ? 'critical' : ms >= T_SLOW ? 'slow' : ms >= T_MEDIUM ? 'medium' : 'fast';
    }
    function rowSpeedClass(ms) {
        return ms >= T_CRITICAL ? 'cs-row-critical' : ms >= T_SLOW ? 'cs-row-slow' : '';
    }
    function timeCell(ms, maxMs) {
        var cls = speedClass(ms), w = maxMs > 0 ? Math.max(2, Math.round((ms / maxMs) * 60)) : 2;
        return '<div class="cs-time-cell"><span class="cs-lat-bar cs-lat-' + cls + '" style="width:' + w + 'px"></span>'
            + '<span class="cs-time-val cs-tv-' + cls + '">' + fmtMs(ms) + '</span></div>';
    }
    function pluginChip(plugin) {
        var core = plugin === 'WordPress Core';
        return '<span class="cs-plugin-chip' + (core ? ' cs-plugin-core' : '') + '" title="' + esc(plugin) + '">' + esc(plugin) + '</span>';
    }
    function kwColour(kw) {
        switch (kw) {
            case 'SELECT': case 'SHOW': case 'DESCRIBE': return 'cs-kw-select';
            case 'INSERT': return 'cs-kw-insert';
            case 'UPDATE': return 'cs-kw-update';
            case 'DELETE': return 'cs-kw-delete';
            default:       return 'cs-kw-other';
        }
    }
    function methodBadge(method) {
        return '<span class="cs-method cs-method-' + (method||'get').toLowerCase() + '">' + esc(method) + '</span>';
    }
    function statusBadge(status, error) {
        if (error && !status) return '<span class="cs-status-err">ERR</span>';
        var cls = 'cs-status-' + (status >= 500 ? '5xx' : status >= 400 ? '4xx' : status >= 300 ? '3xx' : status >= 200 ? '2xx' : 'err');
        return '<span class="' + cls + '">' + (status || '—') + '</span>';
    }
    function truncate(str, max) {
        if (!str) return '';
        var s = String(str).replace(/\s+/g, ' ').trim();
        return s.length > max ? s.slice(0, max - 1) + '\u2026' : s;
    }
    function truncateUrl(url, max) {
        try {
            var u = new URL(String(url)), out = u.hostname + u.pathname;
            if (u.search) out += u.search.slice(0, 20) + (u.search.length > 20 ? '\u2026' : '');
            return out.length > max ? out.slice(0, max - 1) + '\u2026' : out;
        } catch(e) { return truncate(url, max); }
    }
    function esc(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

}());
