/* global csdtVulnScan, csdtCspI18n */
'use strict';

(function(){
    var base = {
        'default-src': ["'self'"],
        'script-src':  ["'self'","'unsafe-inline'"],
        'style-src':   ["'self'","'unsafe-inline'"],
        'img-src':     ["'self'","data:","https:"],
        'font-src':    ["'self'","data:"],
        'connect-src': ["'self'"],
        'frame-src':   ["'self'"],
        'object-src':  ["'none'"],
        'base-uri':    ["'self'"],
        'form-action': ["'self'"]
    };
    var serviceMap = {
        google_analytics:    { 'script-src':['https://www.googletagmanager.com','https://www.google-analytics.com'], 'img-src':['https://www.google-analytics.com','https://www.googletagmanager.com'], 'connect-src':['https://www.google-analytics.com','https://analytics.google.com','https://stats.g.doubleclick.net','https://region1.google-analytics.com'] },
        google_adsense:      { 'script-src':['https://*.googlesyndication.com','https://*.googletagservices.com','https://*.googleadservices.com','https://adservice.google.com','https://fundingchoicesmessages.google.com'], 'frame-src':['blob:','https://*.googlesyndication.com','https://*.safeframe.googlesyndication.com','https://googleads.g.doubleclick.net','https://ep2.adtrafficquality.google'], 'img-src':['https://*.googlesyndication.com','https://googleads.g.doubleclick.net'], 'connect-src':['https://*.googlesyndication.com','https://*.googletagservices.com','https://adservice.google.com','https://ep1.adtrafficquality.google','https://ep2.adtrafficquality.google','https://fundingchoicesmessages.google.com','https://csi.gstatic.com','https://statsdata.online','https://singleview.site','https://gadstat.com'] },
        google_tag_manager:  { 'script-src':['https://www.googletagmanager.com'], 'img-src':['https://www.googletagmanager.com'] },
        google_fonts:        { 'style-src':['https://fonts.googleapis.com'], 'font-src':['https://fonts.gstatic.com'] },
        cloudflare_insights: { 'script-src':['https://static.cloudflareinsights.com'], 'connect-src':['https://cloudflareinsights.com'] },
        facebook_pixel:      { 'script-src':['https://connect.facebook.net'], 'img-src':['https://www.facebook.com'], 'connect-src':['https://www.facebook.com'] },
        recaptcha:           { 'script-src':['https://www.google.com','https://www.gstatic.com'], 'frame-src':['https://www.google.com'] },
        youtube:             { 'frame-src':['https://www.youtube.com','https://www.youtube-nocookie.com'] },
        vimeo:               { 'frame-src':['https://player.vimeo.com'] },
        stripe:              { 'script-src':['https://js.stripe.com'], 'frame-src':['https://js.stripe.com','https://hooks.stripe.com'], 'connect-src':['https://api.stripe.com'] },
        hotjar:              { 'script-src':['https://static.hotjar.com','https://script.hotjar.com'], 'connect-src':['https://*.hotjar.com','wss://*.hotjar.com'], 'img-src':['https://*.hotjar.com'], 'frame-src':['https://*.hotjar.com'] },
        intercom:            { 'script-src':['https://widget.intercom.io','https://js.intercomcdn.com'], 'connect-src':['https://api.intercom.io','https://api-iam.intercom.io','wss://nexus-websocket-a.intercom.io','wss://nexus-websocket-b.intercom.io'], 'img-src':['https://*.intercom.io','https://*.intercomcdn.com'], 'frame-src':['https://intercom-sheets.com'] },
        twitter_embeds:      { 'script-src':['https://platform.twitter.com'], 'frame-src':['https://platform.twitter.com','https://syndication.twitter.com'], 'connect-src':['https://api.twitter.com'], 'img-src':['https://pbs.twimg.com','https://abs.twimg.com'] },
        disqus:              { 'script-src':['https://*.disqus.com','https://*.disquscdn.com'], 'frame-src':['https://disqus.com'], 'connect-src':['https://*.disqus.com'], 'img-src':['https://*.disquscdn.com','https://referrer.disqus.com'] },
        woocommerce_payments: { 'script-src':['https://js.stripe.com','https://pay.google.com'], 'frame-src':['https://js.stripe.com','https://hooks.stripe.com','https://pay.google.com'], 'connect-src':['https://api.stripe.com'] }
    };

    // ── Pure helpers (re-query DOM each call) ──────────────────────────────

    function buildPreview() {
        var d = JSON.parse(JSON.stringify(base));
        document.querySelectorAll('.cs-csp-service:checked').forEach(function(cb){
            var svc = serviceMap[cb.value];
            if (!svc) return;
            Object.keys(svc).forEach(function(dir){
                svc[dir].forEach(function(v){ if (d[dir].indexOf(v) === -1) d[dir].push(v); });
            });
        });
        var parts = Object.keys(d).map(function(k){ return k + ' ' + d[k].join(' '); });
        var custom = document.getElementById('cs-csp-custom');
        if (custom && custom.value.trim()) parts.push(custom.value.trim());
        var preview = document.getElementById('cs-csp-preview');
        if (preview) preview.textContent = parts.join(';\n');
    }

    // Maps blocked-URI substrings to known service checkbox values.
    var violHintMap = [
        ['googlesyndication.com',         'google_adsense'],
        ['doubleclick.net',               'google_adsense'],
        ['googleadservices.com',          'google_adsense'],
        ['adtrafficquality.google',       'google_adsense'],
        ['fundingchoicesmessages.google', 'google_adsense'],
        ['csi.gstatic.com',               'google_adsense'],
        ['statsdata.online',              'google_adsense'],
        ['singleview.site',               'google_adsense'],
        ['gadstat.com',                   'google_adsense'],
        ['google-analytics.com',          'google_analytics'],
        ['analytics.google.com',          'google_analytics'],
        ['region1.google-analytics.com',  'google_analytics'],
        ['googletagmanager.com',          'google_tag_manager'],
        ['fonts.googleapis.com',          'google_fonts'],
        ['fonts.gstatic.com',             'google_fonts'],
        ['cloudflareinsights.com',        'cloudflare_insights'],
        ['connect.facebook.net',          'facebook_pixel'],
        ['facebook.com/tr',               'facebook_pixel'],
        ['www.gstatic.com',               'recaptcha'],
        ['youtube.com',                   'youtube'],
        ['youtube-nocookie.com',          'youtube'],
        ['player.vimeo.com',              'vimeo'],
        ['js.stripe.com',                 'stripe'],
        ['hooks.stripe.com',              'stripe'],
        ['api.stripe.com',                'stripe'],
        ['hotjar.com',                    'hotjar'],
        ['intercom.io',                   'intercom'],
        ['intercomcdn.com',               'intercom'],
        ['platform.twitter.com',          'twitter_embeds'],
        ['syndication.twitter.com',       'twitter_embeds'],
        ['twimg.com',                     'twitter_embeds'],
        ['disqus.com',                    'disqus'],
        ['disquscdn.com',                 'disqus'],
        ['pay.google.com',                'woocommerce_payments'],
    ];

    var violSvcLabels = {
        google_adsense:       'Google AdSense',
        google_analytics:     'Google Analytics',
        google_tag_manager:   'Google Tag Manager',
        google_fonts:         'Google Fonts',
        cloudflare_insights:  'Cloudflare Insights',
        facebook_pixel:       'Facebook Pixel',
        recaptcha:            'Google reCAPTCHA',
        youtube:              'YouTube embeds',
        vimeo:                'Vimeo embeds',
        stripe:               'Stripe',
        hotjar:               'Hotjar',
        intercom:             'Intercom',
        twitter_embeds:       'Twitter / X embeds',
        disqus:               'Disqus',
        woocommerce_payments: 'WooCommerce Payments',
    };

    function suggestService(blocked) {
        if (!blocked || blocked === 'inline' || blocked === 'eval' || blocked === '') return null;
        for (var i = 0; i < violHintMap.length; i++) {
            if (blocked.indexOf(violHintMap[i][0]) !== -1) return violHintMap[i][1];
        }
        return null;
    }

    function highlightCheckbox(svcKey) {
        var cb = document.querySelector('.cs-csp-service[value="' + svcKey + '"]');
        if (!cb) return;
        var label = cb.closest('label') || cb.parentElement;
        label.scrollIntoView({ behavior: 'smooth', block: 'center' });
        label.style.transition = 'background 0.3s';
        label.style.background = '#fef9c3';
        setTimeout(function() { label.style.background = ''; }, 2000);
    }

    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    function humanDirective(dir) {
        var d = (dir || '').toLowerCase().replace(/-elem$|-attr$/, '');
        var map = { 'script-src': 'Script', 'style-src': 'Style', 'connect-src': 'Fetch / API',
                    'img-src': 'Image', 'font-src': 'Font', 'frame-src': 'Iframe',
                    'child-src': 'Iframe', 'worker-src': 'Worker', 'media-src': 'Media' };
        return map[d] || (dir || '—');
    }

    // Domain identification — returns {label, note, risk:'safe'|'warn'|'unknown'} or null for same-site/known-service.
    var DOMAIN_INFO = {
        // AdSense secondary connections — not from WordPress plugins.
        // Google AdSense Auto Ads (pagead2.googlesyndication.com) dynamically triggers
        // browser-side fetch calls to ad-targeting/attribution partners. These domains
        // are NOT loaded by any WordPress plugin or theme — they are injected by the
        // AdSense script at runtime and cannot be traced in PHP or plugin code.
        // To suppress these CSP violations either: (1) enable the Google AdSense
        // preset above which whitelists the known AdSense domains, or (2) accept them
        // as informational — they do not indicate a security problem with your site.
        'statsdata.online':    { label: 'AdSense secondary connection', note: '<strong>Root cause: Google AdSense Auto Ads.</strong> AdSense dynamically triggers browser-side fetch calls to ad ecosystem partners — this domain is one of them. It is NOT injected by a WordPress plugin. It will only appear if AdSense Auto Ads is active on the page. Enable the "Google AdSense" preset above to whitelist all known AdSense domains.', risk: 'warn' },
        'singleview.site':     { label: 'AdSense secondary connection', note: '<strong>Root cause: Google AdSense Auto Ads.</strong> AdSense Auto Ads makes browser-side connections to ad targeting and attribution partners including this domain. It is NOT from any WordPress plugin, theme, or post content. Enable the "Google AdSense" preset above, or add this domain to the connect-src custom field if AdSense does not cover it.', risk: 'warn' },
        'gadstat.com':         { label: 'AdSense secondary connection', note: '<strong>Root cause: Google AdSense Auto Ads.</strong> This is an ad analytics/measurement domain called by AdSense at runtime — not by any WordPress plugin. It is loaded via a browser-side fetch from the AdSense script. Enable the "Google AdSense" preset above to resolve AdSense-related CSP violations as a group.', risk: 'warn' },
        // Known safe CDNs / services
        'cdnjs.cloudflare.com':{ label: 'Cloudflare CDN', note: 'Public CDN for open-source JS/CSS libraries. Safe to add.', risk: 'safe' },
        'cdn.jsdelivr.net':    { label: 'jsDelivr CDN', note: 'Public CDN for npm packages and GitHub repos. Safe to add.', risk: 'safe' },
        'unpkg.com':           { label: 'unpkg CDN', note: 'Public CDN for npm packages. Safe to add.', risk: 'safe' },
        'ajax.googleapis.com': { label: 'Google CDN', note: 'Google-hosted CDN for jQuery and other common libraries. Safe to add.', risk: 'safe' },
        'use.fontawesome.com': { label: 'Font Awesome', note: 'Icon font CDN. Safe to add.', risk: 'safe' },
    };

    function domainInfo(origin, siteOrigin) {
        if (!origin || origin === 'eval' || origin === 'inline') return null;
        var host = origin.replace(/^https?:\/\//, '').replace(/\/.*$/, '');
        // Same-site: always allowed, flag if blocked
        if (siteOrigin && origin.indexOf(siteOrigin) === 0) {
            return { label: 'Your own site', note: 'This is a resource on your own domain. If it\'s blocked, your CSP custom field may have an entry that overrides the default \'self\' rule.', risk: 'safe' };
        }
        // Exact match
        if (DOMAIN_INFO[host]) return DOMAIN_INFO[host];
        // Subdomain match
        for (var d in DOMAIN_INFO) {
            if (host === d || host.slice(-d.length - 1) === '.' + d) return DOMAIN_INFO[d];
        }
        return null;
    }

    // Returns a special-case object {fixCell, note} for known unfixable/special violations,
    // or null if the caller should handle it normally.
    function specialCaseViolation(blocked, directive, siteOrigin) {
        // Cloudflare challenge scripts — injected by Cloudflare WAF at the edge,
        // not by WordPress. With nonces+strict-dynamic active, these have no nonce
        // so they're blocked even though they come from your own domain.
        if (blocked && blocked.indexOf('/cdn-cgi/challenge-platform') !== -1) {
            return {
                label:  'Cloudflare challenge script',
                fixCell: '<span style="color:#d97706;font-size:11px;font-weight:600;">Cannot fix from PHP</span>',
                note:   'Cloudflare injects this bot-challenge script at its edge — WordPress never sees it, so it has no CSP nonce. ' +
                        'With nonces+strict-dynamic active, it will always be blocked here. ' +
                        '<strong>What breaks:</strong> visitors Cloudflare flags for a bot check will get a broken challenge page. ' +
                        '<strong>Fix options:</strong> (1) Disable nonce mode and use \'unsafe-inline\' instead — less secure but resolves this. ' +
                        '(2) Add a Cloudflare Page Rule or Transform Rule to strip the CSP header from challenge pages. ' +
                        '(3) Create a Cloudflare Worker that injects the challenge script nonce into your CSP header.',
            };
        }
        // wasm-eval — WebAssembly evaluation, required by Cloudflare Bot Management / Turnstile.
        if (blocked === 'wasm-eval') {
            return {
                label:  'WebAssembly eval (Cloudflare Bot Management)',
                fixCell: '<button type="button" class="cs-fix-btn" style="font-size:11px;padding:2px 8px;background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;border-radius:4px;cursor:pointer;font-weight:600;" ' +
                         'data-fix-type="custom" data-fix-value="\'wasm-unsafe-eval\'" data-fix-directive="script-src">Add wasm-unsafe-eval</button>',
                note:   'Cloudflare\'s Bot Management and Turnstile use WebAssembly (WASM) for fingerprinting. ' +
                        '<strong>What breaks:</strong> Cloudflare\'s bot detection may fail or show a broken challenge page. ' +
                        '<strong>Fix:</strong> adds <code>\'wasm-unsafe-eval\'</code> to script-src — this is safe and Cloudflare-specific.',
            };
        }
        // Same-origin wp-json in connect-src — the plugin's own REST API being blocked.
        if (blocked && siteOrigin && blocked.indexOf(siteOrigin) === 0 &&
            (blocked.indexOf('/wp-json/') !== -1 || blocked.indexOf('/wp-admin/admin-ajax') !== -1) &&
            directive && directive.indexOf('connect-src') !== -1) {
            return {
                label:  'Your site\'s own REST API',
                fixCell: '<span style="color:#dc2626;font-size:11px;font-weight:600;">CSP config bug</span>',
                note:   'Your own plugin or theme is making a fetch/XHR call to the WordPress REST API, but connect-src is blocking it. ' +
                        'connect-src should always include <code>\'self\'</code> — this is a CSP configuration issue, not an external resource. ' +
                        '<strong>What breaks:</strong> any plugin feature that calls the REST API from the browser (live search, AJAX forms, admin panels). ' +
                        '<strong>Fix:</strong> the base CSP config already includes <code>\'self\'</code> in connect-src — if you have a custom connect-src line in the custom field that overrides this, remove it or add <code>\'self\'</code> to it.',
            };
        }
        return null;
    }

    function extractOrigin(url) {
        if (!url || url === 'inline' || url === 'eval' || url === '') return null;
        try {
            var u = url.replace(/^\/\//, 'https://');
            var parsed = new URL(u);
            return parsed.origin; // e.g. "https://andrewbaker.ninja"
        } catch(e) { return null; }
    }

    function applyViolationFix(btn) {
        var type      = btn.getAttribute('data-fix-type');
        var value     = btn.getAttribute('data-fix-value');
        var directive = btn.getAttribute('data-fix-directive') || 'script-src';
        btn.disabled  = true;
        btn.textContent = '⏳';

        var fd = new FormData();
        fd.append('action',    'csdt_devtools_csp_apply_fix');
        fd.append('nonce',     csdtVulnScan.nonce);
        fd.append('type',      type);
        fd.append('value',     value);
        fd.append('directive', directive);

        fetch(csdtVulnScan.ajaxUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (!resp || !resp.success) {
                    btn.disabled = false; btn.textContent = 'Retry'; return;
                }
                btn.textContent = '✓ Applied';
                btn.style.background = '#dcfce7';
                btn.style.color = '#15803d';
                btn.style.borderColor = '#86efac';

                var d = resp.data;
                if (type === 'custom' && d && d.custom !== undefined) {
                    var ci = document.getElementById('cs-csp-custom');
                    if (ci) { ci.value = d.custom; buildPreview(); }
                }
                if (type === 'service' && d && d.services) {
                    document.querySelectorAll('.cs-csp-service').forEach(function(cb) {
                        cb.checked = d.services.indexOf(cb.value) !== -1;
                    });
                    buildPreview();
                }
                // Clear the violation log so stale entries don't reappear when re-running audit.
                var fdClear = new FormData();
                fdClear.append('action', 'csdt_devtools_csp_violations_clear');
                fdClear.append('nonce',  csdtVulnScan.nonce);
                fetch(csdtVulnScan.ajaxUrl, { method: 'POST', body: fdClear }).catch(function(){});

                // Update audit body to prompt saving + re-browsing.
                var auditBody = document.getElementById('cs-csp-audit-body');
                if (auditBody) {
                    var notice = document.createElement('div');
                    notice.style.cssText = 'background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:10px 14px;font-size:12px;color:#15803d;margin-bottom:8px;';
                    notice.innerHTML = '<strong>✅ Fix applied.</strong> '
                        + 'Violation log cleared. '
                        + '<strong>Next:</strong> click <strong>Save Settings</strong> below to write the updated CSP to your headers, '
                        + 'then browse your site, then click <strong>Run Site Audit</strong> again to confirm.';
                    auditBody.insertBefore(notice, auditBody.firstChild);
                    // Scroll save button into view as a visual hint.
                    var saveBtn = document.getElementById('cs-csp-save-btn');
                    if (saveBtn) setTimeout(function() { saveBtn.scrollIntoView({ behavior: 'smooth', block: 'center' }); saveBtn.style.outline = '3px solid #16a34a'; setTimeout(function(){ saveBtn.style.outline = ''; }, 3000); }, 500);
                }

                // Refresh Fixes Applied panel
                var fd2 = new FormData();
                fd2.append('action', 'csdt_devtools_csp_fixes_get');
                fd2.append('nonce',  csdtVulnScan.nonce);
                fetch(csdtVulnScan.ajaxUrl, { method: 'POST', body: fd2 })
                    .then(function(r2) { return r2.json(); })
                    .then(function(r2) { if (r2 && r2.success) renderFixes(r2.data); })
                    .catch(function() {});
            })
            .catch(function() { btn.disabled = false; btn.textContent = 'Retry'; });
    }

    function secBox(title, content) {
        return '<div style="border:1px solid #d1d5db;border-radius:6px;overflow:hidden;margin-bottom:12px;">' +
            '<div style="background:#e8edf5;padding:9px 14px;border-bottom:1px solid #d1d5db;">' +
            '<strong style="font-size:13px;color:#1e293b;">' + title + '</strong></div>' +
            '<div style="padding:14px 16px;overflow-x:auto;-webkit-overflow-scrolling:touch;">' + content + '</div></div>';
    }

    function retryHtml() {
        return '<p style="margin-top:6px;"><button type="button" id="cs-scan-retry" style="font-size:12px;padding:3px 10px;background:#fff;border:1px solid #d1d5db;border-radius:4px;cursor:pointer;">Retry scan</button></p>';
    }

    function renderViolations(rows) {
        var violTable = document.getElementById('cs-csp-viol-table');
        var violCount = document.getElementById('cs-csp-viol-count');
        if (!violTable) return;
        if (!rows || !rows.length) {
            violTable.innerHTML = '<p style="color:#94a3b8;font-size:12px;margin:0;">No violations recorded yet. Browse your site with Report-Only enabled to capture them.</p>';
            if (violCount) violCount.style.display = 'none';
            return;
        }
        if (violCount) { violCount.textContent = '(' + rows.length + ')'; violCount.style.display = 'inline'; }

        // Current custom CSP value — used to detect already-applied fixes so they don't revert.
        var customInput  = document.getElementById('cs-csp-custom');
        var currentCustom = customInput ? customInput.value : '';
        var siteOrigin   = window.location.origin; // e.g. "https://andrewbaker.ninja"

        // Group by origin+directive — one fix covers all same-origin violations.
        var groups     = {};
        var groupOrder = [];
        rows.forEach(function(r) {
            var blocked = r.blocked || '';
            var origin  = extractOrigin(blocked) || blocked;
            var key     = origin + '||' + (r.directive || '');
            if (!groups[key]) {
                groups[key] = { rows: [], origin: origin, blocked: blocked, directive: r.directive || '' };
                groupOrder.push(key);
            }
            groups[key].rows.push(r);
        });

        // Build quick-fix banner for known services.
        var suggestedServices = {};
        rows.forEach(function(r) {
            var svc = suggestService(r.blocked || '');
            if (!svc) return;
            var cb = document.querySelector('.cs-csp-service[value="' + svc + '"]');
            if (cb && cb.checked) return;
            if (!suggestedServices[svc]) suggestedServices[svc] = 0;
            suggestedServices[svc]++;
        });
        var svcKeys = Object.keys(suggestedServices);
        var banner = '';
        if (svcKeys.length) {
            banner = '<div style="background:#fef9c3;border:1px solid #fde047;border-radius:6px;padding:10px 14px;margin-bottom:10px;font-size:12px;">' +
                '<strong style="color:#713f12;">Quick fix:</strong> tick the following services below to allow these resources — ' +
                svcKeys.map(function(k) {
                    return '<a href="#" style="color:#1d4ed8;font-weight:600;text-decoration:none;" data-csp-svc="' + k + '">' +
                        (violSvcLabels[k] || k) + ' (' + suggestedServices[k] + ')</a>';
                }).join(', ') + '</div>';
        }

        var fixBtnStyle = 'font-size:11px;padding:2px 8px;background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;border-radius:4px;cursor:pointer;font-weight:600;white-space:nowrap;';

        var html = banner +
            '<p style="font-size:11px;color:#64748b;margin:0 0 8px;">Each row is a resource your browser blocked. <strong>One fix covers all pages</strong> — adding a domain once allows it everywhere.</p>' +
            '<div style="overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid #e2e8f0;border-radius:6px;">' +
            '<table style="width:100%;min-width:480px;border-collapse:collapse;font-size:12px;">' +
            '<thead><tr style="background:#f1f5f9;">' +
            '<th style="padding:6px 8px;text-align:left;font-weight:600;color:#374151;border-bottom:1px solid #e2e8f0;">Blocked resource</th>' +
            '<th style="padding:6px 8px;text-align:left;font-weight:600;color:#374151;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Type</th>' +
            '<th style="padding:6px 8px;text-align:left;font-weight:600;color:#374151;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Fix</th>' +
            '<th style="padding:6px 8px;text-align:left;font-weight:600;color:#374151;border-bottom:1px solid #e2e8f0;">What is it?</th>' +
            '</tr></thead><tbody>';

        groupOrder.forEach(function(key, i) {
            var g       = groups[key];
            var blocked = g.blocked;
            var origin  = g.origin;
            var count   = g.rows.length;
            var bg      = i % 2 === 0 ? '#fff' : '#f8fafc';
            var isEval  = blocked === 'eval' || blocked === 'inline';

            var domainDisplay = isEval ? blocked : origin.replace(/^https?:\/\//, '');
            if (domainDisplay.length > 42) domainDisplay = domainDisplay.slice(0, 39) + '…';

            // Collect unique pages for the sub-line.
            var pages = [];
            g.rows.forEach(function(r) {
                var p = (r.page || '').replace(/^https?:\/\/[^/]+/, '');
                if (p && pages.indexOf(p) === -1) pages.push(p);
            });
            var pageNote = pages.length > 1 ? 'seen on ' + pages.length + ' pages'
                         : (pages[0] && pages[0].length > 36 ? pages[0].slice(0, 33) + '…' : (pages[0] || ''));

            // Type column — human-readable directive name.
            var typeHtml = '<span style="font-size:11px;color:#6366f1;">' + esc(humanDirective(g.directive)) + '</span>';

            // Fix button (col 3) and note (col 4) — kept separate.
            var fixCell, noteCell;
            var svc            = suggestService(blocked);
            var alreadyApplied = !isEval && origin && currentCustom.indexOf(origin) !== -1;
            var special        = !isEval && !alreadyApplied && specialCaseViolation(blocked, g.directive, siteOrigin);
            var dInfo          = !isEval && !special && domainInfo(origin, siteOrigin);

            if (special) {
                fixCell  = special.fixCell;
                noteCell = '<div style="font-size:11px;font-weight:600;color:#d97706;margin-bottom:3px;">' + esc(special.label) + '</div>' +
                    '<div style="font-size:10px;color:#374151;line-height:1.5;">' + special.note + '</div>';
                domainDisplay = special.label;
            } else if (isEval) {
                fixCell  = '<span style="color:#64748b;font-size:11px;">No action</span>';
                noteCell = '<div style="font-size:11px;font-weight:600;color:#dc2626;margin-bottom:2px;">Inline code</div>' +
                    '<div style="font-size:10px;color:#64748b;line-height:1.4;">Expected if you use inline &lt;script&gt; or &lt;style&gt; tags. No action needed unless this is unexpected.</div>';
            } else if (alreadyApplied) {
                fixCell  = '<span style="color:#16a34a;font-size:11px;font-weight:600;">✓ Applied</span>';
                noteCell = dInfo ? '<div style="font-size:10px;color:#64748b;">' + esc(dInfo.note) + '</div>' : '—';
            } else if (svc) {
                var cb = document.querySelector('.cs-csp-service[value="' + svc + '"]');
                if (cb && cb.checked) {
                    fixCell  = '<span style="color:#16a34a;font-size:11px;">✓ preset enabled</span>';
                    noteCell = '<div style="font-size:11px;color:#16a34a;font-weight:600;">Known service</div>' +
                        '<div style="font-size:10px;color:#64748b;">' + esc(violSvcLabels[svc] || svc) + ' — already in your CSP via the preset.</div>';
                } else {
                    fixCell  = '<button type="button" class="cs-fix-btn" style="' + fixBtnStyle + '" data-fix-type="service" data-fix-value="' + esc(svc) + '">' +
                        'Enable ' + esc(violSvcLabels[svc] || svc) + '</button>' +
                        (count > 1 ? '<div style="font-size:10px;color:#64748b;margin-top:2px;">fixes ' + count + ' violations</div>' : '');
                    noteCell = '<div style="font-size:11px;color:#16a34a;font-weight:600;">✓ Known service — safe to add</div>' +
                        '<div style="font-size:10px;color:#64748b;">' + esc(violSvcLabels[svc] || svc) + ' — tick it in the services list below.</div>';
                }
            } else if (origin && origin !== blocked) {
                fixCell  = '<button type="button" class="cs-fix-btn" style="' + fixBtnStyle + '" data-fix-type="custom" ' +
                    'data-fix-value="' + esc(origin) + '" data-fix-directive="' + esc(g.directive || 'script-src') + '">' +
                    'Add to CSP</button>' +
                    (count > 1 ? '<div style="font-size:10px;color:#64748b;margin-top:2px;">fixes all ' + count + ' violations</div>' : '');
                if (dInfo) {
                    var riskColor = dInfo.risk === 'warn' ? '#dc2626' : (dInfo.risk === 'safe' ? '#16a34a' : '#64748b');
                    noteCell = '<div style="font-size:11px;font-weight:600;color:' + riskColor + ';margin-bottom:2px;">' +
                        (dInfo.risk === 'warn' ? '⚠ ' : dInfo.risk === 'safe' ? '✓ ' : '') + esc(dInfo.label) + '</div>' +
                        '<div style="font-size:10px;color:#374151;line-height:1.4;">' + esc(dInfo.note) + '</div>';
                } else {
                    noteCell = '<div style="font-size:11px;color:#64748b;margin-bottom:3px;">Unrecognised domain</div>' +
                        '<div style="font-size:10px;color:#94a3b8;margin-bottom:4px;">Not in our known-service list — check plugins before adding.</div>' +
                        '<button type="button" class="cs-csp-hunt-btn" style="font-size:10px;padding:2px 8px;background:#fef3c7;color:#92400e;border:1px solid #fcd34d;border-radius:4px;cursor:pointer;font-weight:600;" data-hunt-domain="' + esc(origin.replace(/^https?:\/\//, '')) + '">🔍 Where is this from?</button>';
                }
            } else {
                fixCell  = '<span style="color:#94a3b8;font-size:11px;">Manual fix</span>';
                noteCell = '<div style="font-size:10px;color:#64748b;">No origin URL detected — check the source file directly.</div>';
            }

            html += '<tr style="background:' + bg + ';border-bottom:1px solid #f1f5f9;">' +
                '<td style="padding:6px 8px;" title="' + esc(blocked) + '">' +
                '<div style="font-family:monospace;font-size:11px;color:' + (isEval ? '#dc2626' : '#0f172a') + ';word-break:break-all;">' + esc(domainDisplay) + '</div>' +
                (pageNote ? '<div style="font-size:10px;color:#94a3b8;margin-top:2px;">' + esc(pageNote) + '</div>' : '') +
                '</td>' +
                '<td style="padding:6px 8px;white-space:nowrap;">' + typeHtml + '</td>' +
                '<td style="padding:6px 8px;white-space:nowrap;">' + fixCell + '</td>' +
                '<td style="padding:6px 8px;">' + noteCell + '</td>' +
                '</tr>';
        });

        html += '</tbody></table></div>';
        violTable.innerHTML = html;

        violTable.querySelectorAll('a[data-csp-svc]').forEach(function(a) {
            a.addEventListener('click', function(e) {
                e.preventDefault();
                highlightCheckbox(a.getAttribute('data-csp-svc'));
            });
        });

        violTable.querySelectorAll('.cs-fix-btn').forEach(function(btn) {
            btn.addEventListener('click', function() { applyViolationFix(btn); });
        });

        // "Where is this from?" — hunt for domain in cron/options/plugins
        violTable.querySelectorAll('.cs-csp-hunt-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var domain = btn.getAttribute('data-hunt-domain');
                btn.disabled = true;
                btn.textContent = '⏳ Searching…';
                var fd = new FormData();
                fd.append('action', 'csdt_csp_domain_hunt');
                fd.append('nonce',  csdtVulnScan.nonce);
                fd.append('domain', domain);
                fetch(csdtVulnScan.ajaxUrl, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        btn.disabled = false;
                        btn.textContent = '🔍 Where is this from?';
                        var res = resp && resp.success ? resp.data : null;
                        var out = '';
                        if (!res || !res.found) {
                            out = '<div style="margin-top:6px;padding:8px 10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;font-size:10px;color:#166534;">' +
                                '<strong>Not found</strong> — no references to <code>' + esc(domain) + '</code> in cron, options, or active plugins. ' +
                                'Likely from a post/page HTML, a cached script, or a previously deleted plugin\'s leftover data.</div>';
                        } else {
                            out = '<div style="margin-top:6px;padding:8px 10px;background:#fef9c3;border:1px solid #fde047;border-radius:4px;font-size:10px;color:#713f12;">' +
                                '<strong>Found references to ' + esc(domain) + ':</strong>';
                            var r = res.results;
                            if (r.cron && r.cron.length) {
                                out += '<div style="margin-top:4px;"><strong>Cron events:</strong><ul style="margin:2px 0 0 12px;padding:0;">' +
                                    r.cron.map(function(h) { return '<li>' + esc(h) + '</li>'; }).join('') + '</ul></div>';
                            }
                            if (r.options && r.options.length) {
                                out += '<div style="margin-top:4px;"><strong>Options (autoloaded):</strong> ' + r.options.map(esc).join(', ') + '</div>';
                            }
                            if (r.options_noautoload && r.options_noautoload.length) {
                                out += '<div style="margin-top:4px;"><strong>Options (non-autoloaded):</strong> ' + r.options_noautoload.map(esc).join(', ') + '</div>';
                            }
                            if (r.active_plugins && r.active_plugins.length) {
                                out += '<div style="margin-top:4px;"><strong>Active plugins:</strong> ' + r.active_plugins.map(esc).join(', ') + '</div>';
                            }
                            if (r.inactive_plugins && r.inactive_plugins.length) {
                                out += '<div style="margin-top:4px;"><strong>Inactive plugins (still installed):</strong> ' + r.inactive_plugins.map(esc).join(', ') + '</div>';
                            }
                            out += '</div>';
                        }
                        // Insert result below the button
                        var existing = btn.parentNode.querySelector('.cs-hunt-result');
                        if (existing) existing.remove();
                        var div = document.createElement('div');
                        div.className = 'cs-hunt-result';
                        div.innerHTML = out;
                        btn.parentNode.appendChild(div);
                    })
                    .catch(function() { btn.disabled = false; btn.textContent = '🔍 Where is this from?'; });
            });
        });
    }

    function fetchViolations() {
        var fd = new FormData();
        fd.append('action', 'csdt_devtools_csp_violations_get');
        fd.append('nonce',  csdtVulnScan.nonce);
        fetch(csdtVulnScan.ajaxUrl, { method:'POST', body:fd })
            .then(function(r){ return r.json(); })
            .then(function(resp){ if (resp && resp.success) renderViolations(resp.data); })
            .catch(function(){});
    }

    function updateViolWrapVisibility() {
        var violWrap = document.getElementById('cs-csp-violation-wrap');
        if (!violWrap) return;
        var cspOn    = document.getElementById('cs-csp-enabled');
        var reportOn = document.getElementById('cs-csp-reporting-enabled');
        var show = (cspOn && cspOn.checked) && (reportOn && reportOn.checked);
        violWrap.style.display = show ? '' : 'none';
        if (show) fetchViolations();
    }

    function renderFixes(rows) {
        var fixesWrap  = document.getElementById('cs-csp-fixes-wrap');
        if (!fixesWrap) return;
        var fixesTable = document.getElementById('cs-csp-fixes-table');
        var fixesCount = document.getElementById('cs-csp-fixes-count');
        if (!rows || !rows.length) {
            fixesWrap.style.display = 'none';
            return;
        }
        fixesWrap.style.display = '';
        if (fixesCount) fixesCount.textContent = rows.length;
        if (!fixesTable) return;
        var html = '';
        rows.forEach(function(f, i) {
            var d   = new Date(f.time * 1000);
            var ts  = d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) + ' ' + d.toLocaleDateString([], {month:'short',day:'numeric'});
            var bg  = i % 2 === 0 ? '#fff' : '#f8fafc';
            var lbl = f.label || 'Settings updated';
            html += '<div style="display:flex;align-items:center;gap:10px;padding:7px 12px;background:' + bg + ';' + (i > 0 ? 'border-top:1px solid #e2e8f0;' : '') + '">' +
                '<span style="color:#94a3b8;font-size:11px;white-space:nowrap;min-width:110px;">' + ts + '</span>' +
                '<span style="flex:1;font-size:12px;color:#15803d;font-weight:600;">' + lbl + '</span>' +
                '</div>';
        });
        fixesTable.innerHTML = html;
    }

    function renderScanResults(data) {
        var scanResults = document.getElementById('cs-csp-scan-results');
        if (!scanResults) return;
        var home = data && data.home;
        if (!home) { scanResults.innerHTML = '<p style="color:#94a3b8;font-size:12px;">No data returned.</p>'; return; }

        var html = '';

        if (home.error) {
            html += secBox('Security Report Summary', '<p style="color:#dc2626;font-size:12px;margin:0;">Error: ' + esc(home.error) + '</p>');
        } else {
            var grade = home.grade || '?';
            var GRADE_COLORS = {'A+':'#15803d','A':'#16a34a','B':'#1d4ed8','C':'#b45309','D':'#c2410c','F':'#991b1b'};
            var SEC_KEYS = ['content-security-policy','content-security-policy-report-only','strict-transport-security','x-frame-options','x-content-type-options','referrer-policy','permissions-policy'];
            var SEC_LABELS = {'content-security-policy':'Content-Security-Policy','content-security-policy-report-only':'CSP-Report-Only','strict-transport-security':'Strict-Transport-Security','x-frame-options':'X-Frame-Options','x-content-type-options':'X-Content-Type-Options','referrer-policy':'Referrer-Policy','permissions-policy':'Permissions-Policy'};
            var gc    = GRADE_COLORS[grade] || '#64748b';
            var sec   = home.sec || {};
            var now   = new Date();
            var ts    = now.toISOString().replace('T',' ').slice(0,19) + ' UTC';

            var pills = '';
            SEC_KEYS.forEach(function(k) {
                var s = sec[k] ? sec[k].status : 'missing';
                var lbl = SEC_LABELS[k] || k;
                if (k === 'content-security-policy-report-only' && s === 'missing') return;
                if (s === 'present') {
                    pills += '<span style="display:inline-flex;align-items:center;gap:4px;background:#15803d;color:#fff;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600;margin:2px 3px 2px 0;white-space:nowrap;">✓ ' + lbl + '</span>';
                } else if (s === 'duplicate') {
                    pills += '<span style="display:inline-flex;align-items:center;gap:4px;background:#d97706;color:#fff;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600;margin:2px 3px 2px 0;white-space:nowrap;">⚠ ' + lbl + '</span>';
                } else {
                    pills += '<span style="display:inline-flex;align-items:center;gap:4px;background:#dc2626;color:#fff;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600;margin:2px 3px 2px 0;white-space:nowrap;">✗ ' + lbl + '</span>';
                }
            });

            var warnSummary = '';
            if (home.warnings && home.warnings.length) {
                warnSummary = 'Grade capped at ' + grade + ', please see warnings below.';
            }

            var summaryInner =
                '<div style="display:flex;gap:16px;align-items:flex-start;min-width:320px;">' +
                '<div style="width:80px;height:80px;min-width:80px;background:' + gc + ';border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">' +
                '<span style="color:#fff;font-size:44px;font-weight:900;line-height:1;">' + grade + '</span></div>' +
                '<table style="flex:1;font-size:12px;border-collapse:collapse;width:100%;min-width:0;">' +
                '<tr><td style="padding:4px 8px 4px 0;font-weight:700;white-space:nowrap;vertical-align:top;color:#374151;width:110px;">Site:</td>' +
                '<td style="padding:4px 0;color:#374151;"><a href="' + esc(home.url) + '" target="_blank" rel="noopener" style="color:#2563eb;">' + esc(home.url) + '</a></td></tr>';
            if (home.ip) {
                summaryInner += '<tr><td style="padding:4px 8px 4px 0;font-weight:700;white-space:nowrap;vertical-align:top;color:#374151;">IP Address:</td>' +
                    '<td style="padding:4px 0;color:#374151;">' + esc(home.ip) + '</td></tr>';
            }
            summaryInner +=
                '<tr><td style="padding:4px 8px 4px 0;font-weight:700;white-space:nowrap;vertical-align:top;color:#374151;">Report Time:</td>' +
                '<td style="padding:4px 0;color:#374151;">' + ts + '</td></tr>' +
                '<tr><td style="padding:4px 8px 4px 0;font-weight:700;white-space:nowrap;vertical-align:top;color:#374151;">Headers:</td>' +
                '<td style="padding:4px 0;line-height:1.8;">' + pills + '</td></tr>';
            if (warnSummary) {
                summaryInner += '<tr><td style="padding:4px 8px 4px 0;font-weight:700;white-space:nowrap;vertical-align:top;color:#374151;">Warning:</td>' +
                    '<td style="padding:4px 0;color:#374151;">' + esc(warnSummary) + '</td></tr>';
            }
            summaryInner += '</table></div>';
            html += secBox('Security Report Summary', summaryInner);

            if (home.warnings && home.warnings.length) {
                var warnRows = '';
                home.warnings.forEach(function(w) {
                    warnRows += '<div style="border-bottom:1px solid #f1f5f9;padding:10px 0;">' +
                        '<div style="font-weight:700;color:#b45309;font-size:12px;margin-bottom:3px;">' + esc(w.header) + '</div>' +
                        '<div style="color:#374151;font-size:12px;word-break:break-word;">' + esc(w.msg) + '</div></div>';
                });
                html += secBox('Warnings', '<div>' + warnRows + '</div>');
            }

            if (home.all_headers) {
                var SEC_KEYS2 = ['content-security-policy','content-security-policy-report-only','strict-transport-security','x-frame-options','x-content-type-options','referrer-policy','permissions-policy'];
                var rawRows = '';
                Object.keys(home.all_headers).forEach(function(hk) {
                    var val = home.all_headers[hk];
                    var isSec = SEC_KEYS2.indexOf(hk) !== -1;
                    var valStr = Array.isArray(val) ? val.join(', ') : String(val || '');
                    rawRows += '<div style="border-bottom:1px solid #f1f5f9;padding:7px 0;">' +
                        '<div style="font-weight:700;font-size:12px;margin-bottom:2px;' + (isSec ? 'color:#15803d;' : 'color:#374151;') + '">' + esc(hk) + '</div>' +
                        '<div style="font-size:12px;word-break:break-all;' + (isSec ? 'font-weight:600;color:#1e293b;' : 'color:#374151;') + '">' + esc(valStr) + '</div></div>';
                });
                html += secBox('Raw Headers', '<div>' + rawRows + '</div>');
            }
        }

        if (data.pages && data.pages.length) {
            var PAGE_COLS   = ['content-security-policy','strict-transport-security','x-frame-options','x-content-type-options'];
            var PAGE_LABELS = {'content-security-policy':'Content-Security-Policy','strict-transport-security':'Strict-Transport-Security','x-frame-options':'X-Frame-Options','x-content-type-options':'X-Content-Type-Options'};
            var pageRows = '<tr style="background:#f8fafc;">' +
                '<th style="padding:5px 8px;text-align:left;font-weight:600;color:#374151;border-bottom:1px solid #e2e8f0;font-size:11px;">Page</th>';
            PAGE_COLS.forEach(function(k) { pageRows += '<th style="padding:5px 6px;text-align:center;font-weight:600;color:#374151;border-bottom:1px solid #e2e8f0;font-size:10px;white-space:nowrap;">' + (PAGE_LABELS[k] || k) + '</th>'; });
            pageRows += '</tr>';
            data.pages.forEach(function(row, i) {
                var bg = i % 2 ? '#f8fafc' : '#fff';
                if (row.error) { pageRows += '<tr style="background:' + bg + '"><td colspan="5" style="padding:5px 8px;color:#dc2626;font-size:11px;">' + esc(row.url) + ' — ' + esc(row.error) + '</td></tr>'; return; }
                var slug = (row.url.replace(/^https?:\/\/[^/]+/,'') || '/');
                if (slug.length > 50) slug = slug.slice(0,47) + '…';
                pageRows += '<tr style="background:' + bg + ';border-bottom:1px solid #f1f5f9;"><td style="padding:5px 8px;font-size:11px;color:#374151;" title="' + esc(row.url) + '">' + esc(slug) + '</td>';
                PAGE_COLS.forEach(function(k) {
                    var s = row.sec && row.sec[k] ? row.sec[k].status : 'missing';
                    var cell = s === 'present' ? '<span style="color:#16a34a;font-weight:700;">✓</span>'
                             : s === 'duplicate' ? '<span style="color:#d97706;font-weight:700;">⚠</span>'
                             : '<span style="color:#dc2626;font-weight:700;">✗</span>';
                    pageRows += '<td style="text-align:center;padding:5px 4px;">' + cell + '</td>';
                });
                pageRows += '</tr>';
            });
            html += secBox('Last 10 Pages', '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;">' + pageRows + '</table></div>');
        }

        scanResults.innerHTML = html;
    }

    function runHeaderScan() {
        var scanBtn     = document.getElementById('cs-csp-scan-btn');
        var scanResults = document.getElementById('cs-csp-scan-results');
        var scanSpinner = document.getElementById('cs-csp-scan-spinner');
        if (!scanBtn) { return; }
        scanBtn.disabled = true;
        if (scanSpinner) scanSpinner.style.display = 'inline';
        if (scanResults) scanResults.innerHTML = '';

        var controller = new AbortController();
        var timer = setTimeout(function() { controller.abort(); }, 30000);

        var fd = new FormData();
        fd.append('action', 'csdt_scan_headers');
        fd.append('nonce',  csdtVulnScan.nonce);
        fetch(csdtVulnScan.ajaxUrl, { method:'POST', body:fd, signal: controller.signal })
            .then(function(r) {
                return r.text().then(function(txt) { return { status: r.status, txt: txt }; });
            })
            .then(function(res) {
                clearTimeout(timer);
                var sb = document.getElementById('cs-csp-scan-btn');
                var ss = document.getElementById('cs-csp-scan-spinner');
                if (sb) sb.disabled = false;
                if (ss) ss.style.display = 'none';
                var resp = null;
                try { resp = JSON.parse(res.txt); } catch(e) {}
                var sr = document.getElementById('cs-csp-scan-results');
                if (resp && resp.success) {
                    renderScanResults(resp.data);
                    if (resp.data.scan_history && typeof window.csUpdateHeaderScanHistory === 'function') {
                        window.csUpdateHeaderScanHistory(resp.data.scan_history);
                    }
                } else if (resp) {
                    if (sr) sr.innerHTML = '<p style="color:#dc2626;font-size:12px;">Scan failed: ' + esc(resp.data || 'unknown error') + '</p>' + retryHtml();
                } else {
                    var friendlyErrors = {503:'Server temporarily unavailable (HTTP 503) — the server may be restarting. Please retry.',502:'Bad gateway (HTTP 502) — reverse proxy timeout. Please retry.',504:'Gateway timeout (HTTP 504) — scan took too long. Please retry.',500:'Internal server error (HTTP 500). Check PHP error logs.'};
                    var msg = friendlyErrors[res.status] || ('Scan error (HTTP ' + res.status + ') — unexpected response. Please retry.');
                    if (sr) sr.innerHTML = '<p style="color:#dc2626;font-size:12px;">' + esc(msg) + '</p>' + retryHtml();
                }
            })
            .catch(function(err) {
                clearTimeout(timer);
                var sb = document.getElementById('cs-csp-scan-btn');
                var ss = document.getElementById('cs-csp-scan-spinner');
                if (sb) sb.disabled = false;
                if (ss) ss.style.display = 'none';
                var isTimeout = err && err.name === 'AbortError';
                var msg = isTimeout ? 'Request timed out after 30s' : ('Request failed: ' + (err && err.message ? err.message : 'network error'));
                var sr = document.getElementById('cs-csp-scan-results');
                if (sr) sr.innerHTML = '<p style="color:#dc2626;font-size:12px;">' + esc(msg) + '</p>' + retryHtml();
            });
    }

    function wireRollback(btn) {
        if (!btn) return;
        btn.addEventListener('click', function(){
            if (!confirm('Restore the previous CSP settings? This will overwrite the current configuration.')) { return; }
            btn.disabled = true;
            var fd = new FormData();
            fd.append('action', 'csdt_devtools_csp_rollback');
            fd.append('nonce',  csdtVulnScan.nonce);
            fetch(csdtVulnScan.ajaxUrl, { method:'POST', body:fd })
                .then(function(r){ return r.json(); })
                .then(function(resp){
                    if (!resp.success) { alert('Rollback failed: ' + (resp.data || 'unknown error')); btn.disabled = false; return; }
                    var d = resp.data;
                    var en = document.getElementById('cs-csp-enabled');
                    if (en) en.checked = d.enabled === '1';
                    var modeEl = document.querySelector('input[name="cs-csp-mode"][value="' + (d.mode || 'enforce') + '"]');
                    if (modeEl) modeEl.checked = true;
                    document.querySelectorAll('.cs-csp-service').forEach(function(cb){
                        cb.checked = Array.isArray(d.services) && d.services.indexOf(cb.value) !== -1;
                    });
                    var ci = document.getElementById('cs-csp-custom');
                    if (ci) ci.value = d.custom || '';
                    buildPreview();
                    btn.remove();
                    var rb2 = document.getElementById('cs-csp-rolledback');
                    if (rb2) { rb2.style.display = 'inline'; setTimeout(function(){ rb2.style.display = 'none'; }, 3000); }
                })
                .catch(function(){ btn.disabled = false; });
        });
    }

    // ── One-time flags for document-level side-effects ──────────────────────
    var _cspDocListeners = false;
    var _cspIntervalId   = null;

    // ── Tab-safe init: re-runs each time security tab becomes active ─────────
    function csdtCspInit() {
        if (!document.getElementById('cs-csp-preview')) return;

        // Re-bind service checkboxes + custom input → preview
        document.querySelectorAll('.cs-csp-service').forEach(function(cb){
            cb.addEventListener('change', buildPreview);
        });
        var customIn = document.getElementById('cs-csp-custom');
        if (customIn) customIn.addEventListener('input', buildPreview);
        buildPreview();

        // Copy button
        var copyBtn = document.getElementById('cs-csp-copy-btn');
        if (copyBtn) {
            copyBtn.addEventListener('click', function(){
                var text = document.getElementById('cs-csp-preview').textContent;
                navigator.clipboard.writeText(text).then(function(){
                    copyBtn.textContent = '✅ Copied';
                    setTimeout(function(){ copyBtn.textContent = '📋 Copy'; }, 2000);
                });
            });
        }

        // Save button
        var saveBtn  = document.getElementById('cs-csp-save-btn');
        var savedMsg = document.getElementById('cs-csp-saved');
        if (saveBtn) {
            saveBtn.addEventListener('click', function(){
                saveBtn.disabled = true;
                var services = [];
                document.querySelectorAll('.cs-csp-service:checked').forEach(function(cb){ services.push(cb.value); });
                var modeEl = document.querySelector('input[name="cs-csp-mode"]:checked');
                var fd = new FormData();
                fd.append('action',   'csdt_devtools_csp_save');
                fd.append('nonce',    csdtVulnScan.nonce);
                fd.append('enabled',      document.getElementById('cs-csp-enabled').checked ? '1' : '0');
                fd.append('mode',         modeEl ? modeEl.value : 'enforce');
                fd.append('services',     JSON.stringify(services));
                var ci = document.getElementById('cs-csp-custom');
                fd.append('custom',       ci ? ci.value.trim() : '');
                var dbgCb = document.getElementById('cs-csp-debug-panel');
                fd.append('debug_panel',       dbgCb && dbgCb.checked ? '1' : '0');
                var reportingCb = document.getElementById('cs-csp-reporting-enabled');
                fd.append('reporting_enabled', reportingCb && reportingCb.checked ? '1' : '0');
                fetch(csdtVulnScan.ajaxUrl, { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        saveBtn.disabled = false;
                        if (!resp || !resp.success) {
                            if (savedMsg) { savedMsg.style.color = '#dc2626'; savedMsg.textContent = '❌ Error'; savedMsg.style.display = 'inline'; setTimeout(function(){ savedMsg.style.display = 'none'; savedMsg.textContent = '✓ Saved'; savedMsg.style.color = ''; }, 5000); }
                            return;
                        }
                        if (savedMsg) { savedMsg.style.color = '#16a34a'; savedMsg.style.display = 'inline'; setTimeout(function(){ savedMsg.style.display = 'none'; savedMsg.style.color = ''; }, 8000); }
                        document.dispatchEvent(new CustomEvent('csdt:csp:saved'));
                        if (resp.data && resp.data.history_entry) { prependCspHistoryEntry(resp.data.history_entry); }
                        if (resp.data && resp.data.has_backup) {
                            var rb = document.getElementById('cs-csp-rollback-btn');
                            if (!rb) {
                                rb = document.createElement('button');
                                rb.id = 'cs-csp-rollback-btn';
                                rb.type = 'button';
                                rb.className = 'cs-btn-secondary cs-btn-sm';
                                rb.style.cssText = 'border-color:#f87171;color:#dc2626;';
                                saveBtn.parentNode.insertBefore(rb, saveBtn.nextSibling);
                                wireRollback(rb);
                            }
                            rb.innerHTML = '↩ ' + ( window.csdtCspI18n ? csdtCspI18n.rollbackLabel : 'Rollback to previous settings' ) + ' <span style="font-weight:400;font-size:11px;opacity:.8;">(just now)</span>';
                        }
                    })
                    .catch(function(){ saveBtn.disabled = false; });
            });
        }

        wireRollback(document.getElementById('cs-csp-rollback-btn'));

        function wireCspRestoreBtn(btn) {
            if (!btn || btn.dataset.wired) { return; }
            btn.dataset.wired = '1';
            btn.addEventListener('click', function() {
                var idx = btn.getAttribute('data-index');
                if (!confirm('Restore this CSP configuration? The current settings will be pushed to history first.')) { return; }
                btn.disabled = true; btn.textContent = '⏳';
                var fd = new FormData();
                fd.append('action', 'csdt_devtools_csp_restore');
                fd.append('nonce',  csdtVulnScan.nonce);
                fd.append('index',  idx);
                fetch(csdtVulnScan.ajaxUrl, { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(resp) {
                        if (!resp.success) { alert('Restore failed: ' + (resp.data || 'unknown error')); btn.disabled = false; btn.textContent = '↩ Restore'; return; }
                        var d = resp.data;
                        var en = document.getElementById('cs-csp-enabled');
                        if (en) en.checked = d.enabled === '1';
                        var modeEl = document.querySelector('input[name="cs-csp-mode"][value="' + (d.mode || 'enforce') + '"]');
                        if (modeEl) modeEl.checked = true;
                        document.querySelectorAll('.cs-csp-service').forEach(function(cb) {
                            cb.checked = Array.isArray(d.services) && d.services.indexOf(cb.value) !== -1;
                        });
                        var ci = document.getElementById('cs-csp-custom');
                        if (ci) ci.value = d.custom || '';
                        var repEl = document.getElementById('cs-csp-reporting');
                        if (repEl) repEl.checked = d.reporting_enabled === '1';
                        buildPreview();
                        var msg = document.getElementById('cs-csp-restore-msg');
                        if (msg) { msg.style.display = 'block'; msg.textContent = '✅ Restored and saved.'; setTimeout(function(){ msg.style.display = 'none'; }, 5000); }
                        btn.textContent = '✅ Restored';
                    })
                    .catch(function() { btn.disabled = false; btn.textContent = '↩ Restore'; });
            });
        }

        function prependCspHistoryEntry(entry) {
            var wrap = document.getElementById('cs-csp-history-wrap');
            var label = esc(entry.label || 'Settings saved');
            var row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:8px 14px;background:#fff;';
            row.innerHTML =
                '<span style="color:#94a3b8;font-size:11px;white-space:nowrap;min-width:80px;">just now</span>' +
                '<span style="flex:1;font-size:12px;color:#334155;">' + label + '</span>' +
                '<button type="button" class="cs-csp-restore-btn" data-index="0" ' +
                'style="background:none;border:1px solid #94a3b8;color:#475569;font-size:11px;font-weight:600;padding:3px 10px;border-radius:4px;cursor:pointer;white-space:nowrap;">&#x21A9; Restore</button>';

            if (!wrap) {
                // History section doesn't exist yet (first ever save) — reload to show it.
                location.reload();
                return;
            }

            // Bump all existing restore-button indices.
            wrap.querySelectorAll('.cs-csp-restore-btn').forEach(function(b) {
                b.setAttribute('data-index', parseInt(b.getAttribute('data-index'), 10) + 1);
            });

            // Update the count in the summary heading.
            var summarySpan = wrap.querySelector('summary > span:first-child');
            if (summarySpan) {
                summarySpan.textContent = summarySpan.textContent.replace(/\d+ saves?/, function(m) {
                    return (parseInt(m, 10) + 1) + ' saves';
                });
            }

            // Find the body div (first non-summary child).
            var bodyDiv = null;
            for (var i = 0; i < wrap.children.length; i++) {
                if (wrap.children[i].tagName !== 'SUMMARY') { bodyDiv = wrap.children[i]; break; }
            }
            if (!bodyDiv) { return; }

            // Add separator to the row that will become second.
            var firstRow = bodyDiv.firstElementChild;
            if (firstRow) { firstRow.style.borderTop = '1px solid #e2e8f0'; }

            bodyDiv.insertBefore(row, bodyDiv.firstChild);
            wireCspRestoreBtn(row.querySelector('.cs-csp-restore-btn'));
            wrap.open = true;
        }

        // Change history restore buttons
        document.querySelectorAll('.cs-csp-restore-btn').forEach(wireCspRestoreBtn);

        // Violation log
        var violRefresh        = document.getElementById('cs-csp-viol-refresh');
        var violClear          = document.getElementById('cs-csp-viol-clear');
        var cspEnabledCb       = document.getElementById('cs-csp-enabled');
        var reportingEnabledCb = document.getElementById('cs-csp-reporting-enabled');
        if (cspEnabledCb)       cspEnabledCb.addEventListener('change', updateViolWrapVisibility);
        if (reportingEnabledCb) reportingEnabledCb.addEventListener('change', updateViolWrapVisibility);
        // ── Violation log collapse toggle ────────────────────────────────
        var violHeader    = document.getElementById('cs-csp-viol-header');
        var violBody      = document.getElementById('cs-csp-viol-body');
        var violChevron   = document.getElementById('cs-csp-viol-chevron');
        var violCollapsed = true; // starts collapsed
        var violLoaded    = false;
        function setViolToggle(collapsed) {
            if (!violChevron) return;
            violChevron.textContent = collapsed ? 'Show ▼' : 'Hide ▲';
        }
        setViolToggle(true);
        if (violHeader && violBody) {
            violHeader.addEventListener('click', function(e) {
                // Don't collapse when clicking Refresh or Clear buttons
                if (e.target.closest && (e.target.closest('#cs-csp-viol-refresh') || e.target.closest('#cs-csp-viol-clear'))) return;
                violCollapsed = !violCollapsed;
                violBody.style.display = violCollapsed ? 'none' : '';
                setViolToggle(violCollapsed);
                // Load violations on first expand
                if (!violCollapsed && !violLoaded) { violLoaded = true; fetchViolations(); }
            });
        }

        if (violRefresh) violRefresh.addEventListener('click', fetchViolations);
        if (violClear) {
            violClear.addEventListener('click', function() {
                var fd = new FormData();
                fd.append('action', 'csdt_devtools_csp_violations_clear');
                fd.append('nonce',  csdtVulnScan.nonce);
                fetch(csdtVulnScan.ajaxUrl, { method:'POST', body:fd })
                    .then(function(){ renderViolations([]); })
                    .catch(function(){});
            });
        }

        // Fixes clear button — stopPropagation prevents <details> summary from toggling
        var fixesClear = document.getElementById('cs-csp-fixes-clear');
        if (fixesClear) {
            fixesClear.addEventListener('click', function(e) {
                e.stopPropagation();
                var fd = new FormData();
                fd.append('action', 'csdt_devtools_csp_fixes_clear');
                fd.append('nonce',  csdtVulnScan.nonce);
                fetch(csdtVulnScan.ajaxUrl, { method:'POST', body:fd })
                    .then(function() { renderFixes([]); })
                    .catch(function() {});
            });
        }

        // Scan button
        var scanBtn = document.getElementById('cs-csp-scan-btn');
        if (scanBtn) {
            scanBtn.addEventListener('click', runHeaderScan);
        }

        // Document-level listeners added only once — survive tab switches
        if (!_cspDocListeners) {
            _cspDocListeners = true;

            document.addEventListener('click', function(e) {
                if (e.target && e.target.id === 'cs-scan-retry') { runHeaderScan(); }
            });

            document.addEventListener('csdt:csp:saved', function() {
                var fd = new FormData();
                fd.append('action', 'csdt_devtools_csp_fixes_get');
                fd.append('nonce',  csdtVulnScan.nonce);
                fetch(csdtVulnScan.ajaxUrl, { method:'POST', body:fd })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) { if (resp && resp.success) renderFixes(resp.data); })
                    .catch(function() {});
            });
        }

        // Auto-refresh violations every 30 s — start only once
        if (_cspIntervalId === null) {
            _cspIntervalId = setInterval(function() {
                var vw = document.getElementById('cs-csp-violation-wrap');
                if (vw && vw.style.display !== 'none') fetchViolations();
            }, 30000);
        }
    }

    // ── Boot ────────────────────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', csdtCspInit);
    } else {
        csdtCspInit();
    }
    document.addEventListener('csdt:tab-shown', function(e) {
        if (e.detail && e.detail.tab === 'headers') csdtCspInit();
    });

    // ── Global delegation for .cs-fix-btn — covers both violation log AND audit panel ──
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.cs-fix-btn');
        if (!btn) return;
        // Don't double-fire if already handled by the querySelectorAll listener on violTable.
        // applyViolationFix is idempotent (disables the button immediately).
        if (!btn.disabled) applyViolationFix(btn);
    });

    // ── CSP Site Audit ────────────────────────────────────────────────────
    // Loads key pages in hidden iframes, collects securitypolicyviolation
    // events, and reports results inline in the panel.

    var AUDIT_PAGES = [
        { path: '/',       label: 'Home' },
        { path: '/blog/',  label: 'Blog' },
    ];

    // Patterns for known/expected violations — never flagged as errors.
    var EXPECTED = [
        /googletagmanager\.com/,
        /googlesyndication\.com/,
        /adsbygoogle/,
        /cloudflareinsights\.com/,
        /recaptcha/,
        /youtube\.com/,
        /ytimg\.com/,
        /doubleclick\.net/,
        /google-analytics\.com/,
        /googleadservices\.com/,
        /fonts\.googleapis\.com/,
        /fonts\.gstatic\.com/,
    ];

    function isExpected(blocked) {
        return EXPECTED.some(function(p) { return p.test(blocked); });
    }

    function escH(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function runCspAudit() {
        var auditBtn    = document.getElementById('cs-csp-audit-btn');
        var auditWrap   = document.getElementById('cs-csp-audit-wrap');
        var auditBody   = document.getElementById('cs-csp-audit-body');
        var auditStatus = document.getElementById('cs-csp-audit-status');
        if (!auditWrap || !auditBody) return;

        var ajaxUrl = (window.csdtVulnScan && window.csdtVulnScan.ajaxUrl) || (window.ajaxurl || '');
        var nonce   = (window.csdtVulnScan && window.csdtVulnScan.nonce) || '';

        function wpAjax(action) {
            var fd = new FormData();
            fd.append('action', action);
            fd.append('nonce',  nonce);
            return fetch(ajaxUrl, { method: 'POST', body: fd }).then(function(r) { return r.json(); });
        }

        auditWrap.style.display = '';
        auditWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        auditBody.innerHTML = '<div style="color:#64748b;font-size:12px;padding:8px 0;">⏳ Reading violation log…</div>';
        if (auditStatus) auditStatus.textContent = 'Reading log…';
        if (auditBtn) { auditBtn.disabled = true; auditBtn.textContent = '⏳ Loading…'; }

        wpAjax('csdt_devtools_csp_violations_get')
            .then(function(resp) {
                if (auditBtn) { auditBtn.disabled = false; auditBtn.textContent = '🔍 Run Site Audit'; }

                var violations = (resp.success && Array.isArray(resp.data)) ? resp.data : [];
                var siteOrigin = window.location.origin;

                // Normalise: stored as {blocked, directive, page} — map to consistent names.
                violations = violations.map(function(v) {
                    return {
                        blocked:   v.blocked    || v.blocked_uri || '',
                        directive: v.directive  || v.effective_directive || '',
                        page:      v.page       || '',
                        source:    v.source     || v.source_file || '',
                        line:      v.line       || v.line_number || 0,
                    };
                });

                // Filter out expected/known third-party services.
                var unexpected = violations.filter(function(v) { return !isExpected(v.blocked || ''); });
                var expected   = violations.filter(function(v) { return  isExpected(v.blocked || ''); });

                var pageCount = Object.keys(unexpected.reduce(function(acc, v) { acc[v.page||'?'] = 1; return acc; }, {})).length;
                if (auditStatus) auditStatus.textContent = violations.length + ' total · ' + unexpected.length + ' unexpected';

                var html = '';

                if (unexpected.length === 0) {
                    if (violations.length === 0) {
                        html = '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:12px 14px;font-size:13px;color:#0369a1;margin-bottom:8px;">'
                            + '<strong>ℹ️ Violation log is empty.</strong><br>'
                            + '<span style="font-size:12px;">Enable CSP in <strong>Report-Only</strong> mode, browse your site for a few minutes, then click Run Site Audit again.</span>'
                            + '</div>';
                    } else {
                        html = '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:10px 14px;font-weight:700;color:#15803d;margin-bottom:8px;">'
                            + '✅ ' + violations.length + ' violation' + (violations.length !== 1 ? 's' : '') + ' — all from known third-party services. Safe to switch to Enforce.</div>';
                    }
                } else {
                    // Group by blocked resource+directive so each unique issue appears once with all affected pages.
                    var byResource = {};
                    unexpected.forEach(function(v) {
                        var key = (v.blocked || '(inline/eval)') + '||' + v.directive;
                        if (!byResource[key]) {
                            byResource[key] = { blocked: v.blocked, directive: v.directive, pages: [] };
                        }
                        if (v.page && byResource[key].pages.indexOf(v.page) === -1) {
                            byResource[key].pages.push(v.page);
                        }
                    });

                    html = '<div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:10px 14px;font-size:12px;color:#92400e;margin-bottom:12px;">'
                        + '<strong>⚠️ ' + Object.keys(byResource).length + ' blocked resource' + (Object.keys(byResource).length !== 1 ? 's' : '') + ' across ' + pageCount + ' page' + (pageCount !== 1 ? 's' : '') + '.</strong><br>'
                        + 'Each row below is a unique blocked resource. Fix each one by adding the domain to your allowlist (or use the Add button), then save and browse again.'
                        + '</div>';

                    Object.keys(byResource).forEach(function(key) {
                        var item    = byResource[key];
                        var blocked = item.blocked || '';
                        var dir     = item.directive || '';
                        var svc     = suggestService(blocked);
                        var special = specialCaseViolation(blocked, dir, siteOrigin);
                        var origin  = extractOrigin(blocked) || blocked;
                        var isInline = (!blocked || blocked === 'inline' || blocked === 'eval' || blocked === '\'unsafe-inline\'');
                        var shortBlocked = blocked.length > 80 ? blocked.slice(0, 77) + '…' : blocked;

                        html += '<div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:10px 14px;margin-bottom:8px;">';

                        // Header row: directive + resource
                        html += '<div style="display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-bottom:8px;">';
                        html += '<span style="font-size:11px;font-weight:700;padding:2px 7px;border-radius:4px;background:#fde68a;color:#92400e;white-space:nowrap;">' + escH(dir) + '</span>';
                        if (isInline) {
                            html += '<span style="font-size:12px;color:#374151;font-weight:600;">Inline script or style (\'unsafe-inline\')</span>';
                        } else {
                            html += '<span style="font-size:12px;color:#374151;font-weight:600;word-break:break-all;">' + escH(shortBlocked) + '</span>';
                        }
                        html += '</div>';

                        // What it is + actionable fix button
                        var fixBtnStyle = 'font-size:11px;font-weight:700;padding:4px 12px;border-radius:5px;border:none;cursor:pointer;background:#16a34a;color:#fff;margin-top:6px;';
                        if (special) {
                            html += '<div style="font-size:11px;color:#0369a1;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:8px 10px;margin-bottom:6px;">'
                                + '<strong>ℹ️ ' + escH(special.note || '') + '</strong>';
                            if (special.fix) {
                                // special.fix may contain a button — render it directly
                                html += '<br>' + special.fix;
                            }
                            html += '</div>';
                        } else if (svc) {
                            var svcKey   = svc; // string key like 'google_analytics'
                            var svcLabel = (violSvcLabels && violSvcLabels[svc]) ? violSvcLabels[svc] : svc;
                            html += '<div style="font-size:11px;color:#166534;background:#f0fdf4;border:1px solid #86efac;border-radius:4px;padding:8px 10px;margin-bottom:6px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">'
                                + '<span>✅ <strong>' + escH(svcLabel) + '</strong> — tick it in the services list above.</span>'
                                + '<button type="button" class="cs-fix-btn" style="' + fixBtnStyle + '" data-fix-type="service" data-fix-value="' + escH(svcKey) + '">⚡ Add ' + escH(svcLabel) + '</button>'
                                + '</div>';
                        } else if (isInline) {
                            html += '<div style="font-size:11px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:4px;padding:8px 10px;margin-bottom:6px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">'
                                + '<span>⚠️ Inline script/style — needs <code>\'unsafe-inline\'</code> in ' + escH(dir) + '.</span>'
                                + '<button type="button" class="cs-fix-btn" style="' + fixBtnStyle + 'background:#d97706;" data-fix-type="custom" data-fix-value="\'unsafe-inline\'" data-fix-directive="' + escH(dir) + '">⚡ Add \'unsafe-inline\'</button>'
                                + '</div>';
                        } else if (blocked.indexOf(siteOrigin) === 0) {
                            // When strict-dynamic is active, 'self' is ignored — must allowlist the exact path.
                            // Extract just the path prefix (e.g. /cdn-cgi/) for the fix.
                            var fixOriginSelf = extractOrigin(blocked) || siteOrigin;
                            html += '<div style="font-size:11px;color:#7c3aed;background:#f5f3ff;border:1px solid #c4b5fd;border-radius:4px;padding:8px 10px;margin-bottom:6px;">'
                                + '<span>🔌 <strong>Your own domain is being blocked.</strong> If nonces/strict-dynamic are enabled, \'self\' is ignored — you must allowlist the exact origin.</span>'
                                + '<div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;">'
                                + '<button type="button" class="cs-fix-btn" style="' + fixBtnStyle + 'background:#7c3aed;" data-fix-type="custom" data-fix-value="' + escH(fixOriginSelf) + '" data-fix-directive="' + escH(dir) + '">⚡ Allowlist ' + escH(fixOriginSelf) + '</button>'
                                + '</div>'
                                + '</div>';
                        } else {
                            html += '<div style="font-size:11px;color:#374151;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:8px 10px;margin-bottom:6px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">'
                                + '<span>Add <code>' + escH(origin) + '</code> to <strong>' + escH(dir) + '</strong>.</span>'
                                + '<button type="button" class="cs-fix-btn" style="' + fixBtnStyle + '" data-fix-type="custom" data-fix-value="' + escH(origin) + '" data-fix-directive="' + escH(dir) + '">⚡ Apply Fix</button>'
                                + '</div>';
                        }

                        // Affected pages
                        if (item.pages.length > 0) {
                            html += '<div style="font-size:10px;color:#6b7280;margin-top:4px;">Seen on: ';
                            html += item.pages.slice(0, 3).map(function(pg) {
                                var short = pg.replace(/^https?:\/\/[^/]+/, '').slice(0, 50) || '/';
                                return '<a href="' + escH(pg) + '" target="_blank" rel="noopener" style="color:#6366f1;text-decoration:none;">' + escH(short) + '</a>';
                            }).join(', ');
                            if (item.pages.length > 3) html += ' + ' + (item.pages.length - 3) + ' more';
                            html += '</div>';
                        }

                        html += '</div>';
                    });
                }

                if (expected.length > 0) {
                    html += '<p style="font-size:10px;color:#94a3b8;margin:8px 0 0;">' + expected.length + ' violation' + (expected.length !== 1 ? 's' : '') + ' from known third-party services (Google, Cloudflare, YouTube etc.) filtered out.</p>';
                }

                auditBody.innerHTML = html;
            })
            .catch(function(err) {
                if (auditBtn) { auditBtn.disabled = false; auditBtn.textContent = '🔍 Run Site Audit'; }
                if (auditStatus) auditStatus.textContent = 'Error';
                auditBody.innerHTML = '<div style="color:#dc2626;font-size:12px;padding:8px 0;">✗ Failed to load violation log: ' + escH(err && err.message || 'Network error') + '</div>';
            });
    }

    // Wire up the button — use event delegation so it works after tab-router injection.
    document.addEventListener('click', function(e) {
        if (e.target.closest('#cs-csp-audit-btn')) runCspAudit();
    });

})();
