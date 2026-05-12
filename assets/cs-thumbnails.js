/* ===========================================================
   CloudScale Devtools — Thumbnails / Social Preview  v1.9.10
   Handles: URL checker, post social scan, Cloudflare
            crawler test, CF cache purge, platform formats.
   =========================================================== */
( function () {
    'use strict';

    const { ajaxUrl, nonce, siteUrl } = window.csdtDevtoolsThumbs || {};

    // ── Helpers ───────────────────────────────────────────────────────────

    function post( action, data ) {
        const body = new URLSearchParams( { action, nonce, ...data } );
        return fetch( ajaxUrl, { method: 'POST', body } )
            .then( r => r.json() )
            .then( res => {
                if ( res === -1 || res === 0 ) {
                    return { success: false, data: { message: 'Session expired — please reload.' } };
                }
                return res;
            } );
    }

    function esc( str ) {
        return String( str )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    }

    function setLoading( el, msg ) {
        el.style.display = 'block';
        el.innerHTML = `<p style="color:#555;font-size:13px">⏳ ${esc( msg || 'Loading…' )}</p>`;
    }

    // ── URL Checker ───────────────────────────────────────────────────────

    const checkBtn     = document.getElementById( 'cs-thumb-check-btn' );
    const checkUrlEl   = document.getElementById( 'cs-thumb-check-url' );
    const checkResults = document.getElementById( 'cs-thumb-check-results' );

    if ( checkBtn ) {
        checkBtn.addEventListener( 'click', () => {
            const url = ( checkUrlEl?.value || '' ).trim();
            if ( ! url ) { alert( 'Please enter a URL.' ); return; }
            checkBtn.disabled = true;
            checkBtn.textContent = 'Running…';
            setLoading( checkResults, 'Running all diagnostic checks — this may take 10–20 seconds…' );

            post( 'csdt_devtools_social_check_url', { url } ).then( res => {
                checkBtn.disabled = false;
                checkBtn.textContent = '🔍 Run Diagnostic';
                if ( ! res.success ) {
                    checkResults.innerHTML = `<p style="color:#8c2020">${esc( res.data?.message || 'Error' )}</p>`;
                    return;
                }
                checkResults.innerHTML = renderReport( res.data, url );
            } ).catch( () => {
                checkBtn.disabled = false;
                checkBtn.textContent = '🔍 Run Diagnostic';
                checkResults.innerHTML = '<p style="color:#8c2020">AJAX request failed. Check your connection.</p>';
            } );
        } );
    }

    function renderReport( data, checkedUrl ) {
        const t = data.totals;
        const cls = t.fail > 0 ? 'fail' : t.warn > 0 ? 'warn' : 'pass';
        const msg = t.fail > 0
            ? `${t.fail} critical issue(s) found`
            : t.warn > 0 ? `${t.warn} warning(s) — review recommended` : 'All checks passed';

        const icons = { pass: '✔', warn: '⚠', fail: '✘', info: 'ℹ' };
        let html = `
            <div class="cs-thumb-report-hdr cs-thumb-${cls}-hdr">
                <strong>${esc( msg )}</strong>
                <span class="cs-thumb-tally">
                    <span style="color:#276227">✔ ${t.pass}</span>
                    <span style="color:#7a5a00">⚠ ${t.warn}</span>
                    <span style="color:#8c2020">✘ ${t.fail}</span>
                    <button class="button button-small cs-copy-results-btn" style="font-size:11px;margin-left:10px" title="Copy results to clipboard">📋 Copy</button>
                </span>
            </div>`;

        for ( const sec of data.sections ) {
            html += `<div class="cs-thumb-section">
                <div class="cs-thumb-section-title">${esc( sec.title )}</div>
                <ul class="cs-thumb-results-list">`;
            for ( const r of sec.results ) {
                const icon = icons[ r.type ] || 'ℹ';
                const fixHtml = r.fix
                    ? `<div class="cs-thumb-fix">💡 ${esc( r.fix )}</div>`
                    : '';
                html += `<li class="cs-thumb-result cs-thumb-${r.type || 'info'}">
                    <span>${icon}</span>
                    <span>${esc( r.msg )}${fixHtml}</span>
                </li>`;
            }
            html += '</ul></div>';
        }

        // Merged: Test All Crawlers button at the bottom of results.
        const urlForTest = checkedUrl || ( checkUrlEl?.value || '' ).trim();
        if ( urlForTest ) {
            html += `<div class="cs-thumb-section">
                <div class="cs-thumb-section-title">Crawler Access Test</div>
                <p style="font-size:12px;color:#555;margin:6px 0 8px">Fetches the page with each social crawler user agent to confirm none are blocked by a WAF or Bot Fight Mode rule.</p>
                <button class="button cs-btn-primary cs-inline-crawler-test-btn" data-url="${esc( urlForTest )}" style="font-size:12px">🤖 Test All Crawlers</button>
                <div class="cs-inline-crawler-results" style="margin-top:8px"></div>
            </div>`;
        }

        return html;
    }

    // Build plain-text summary for clipboard copy.
    function reportToText( container ) {
        const lines = [];
        const hdr = container.querySelector( '.cs-thumb-report-hdr strong' );
        if ( hdr ) lines.push( hdr.textContent, '' );
        container.querySelectorAll( '.cs-thumb-section' ).forEach( sec => {
            const title = sec.querySelector( '.cs-thumb-section-title' );
            if ( title ) lines.push( '── ' + title.textContent + ' ──' );
            sec.querySelectorAll( '.cs-thumb-result' ).forEach( li => {
                const spans = li.querySelectorAll( 'span' );
                const icon = spans[0]?.textContent.trim() || '';
                const msg  = spans[1]?.textContent.trim() || '';
                lines.push( `  ${icon} ${msg}` );
            } );
            lines.push( '' );
        } );
        return lines.join( '\n' );
    }

    // Copy results to clipboard.
    document.addEventListener( 'click', ( e ) => {
        const btn = e.target.closest( '.cs-copy-results-btn' );
        if ( ! btn ) return;
        const container = btn.closest( '#cs-thumb-check-results' );
        if ( ! container ) return;
        const text = reportToText( container );
        navigator.clipboard.writeText( text ).then( () => {
            const orig = btn.textContent;
            btn.textContent = '✔ Copied';
            setTimeout( () => { btn.textContent = orig; }, 2000 );
        } ).catch( () => {
            btn.textContent = '✘ Failed';
            setTimeout( () => { btn.textContent = '📋 Copy'; }, 2000 );
        } );
    } );

    // Inline crawler access test (merged from CF panel).
    document.addEventListener( 'click', ( e ) => {
        const btn = e.target.closest( '.cs-inline-crawler-test-btn' );
        if ( ! btn ) return;
        const url        = btn.dataset.url;
        const resultsDiv = btn.closest( '.cs-thumb-section' ).querySelector( '.cs-inline-crawler-results' );
        btn.disabled = true;
        btn.textContent = 'Testing…';
        if ( resultsDiv ) resultsDiv.innerHTML = '<span style="color:#555;font-size:12px">⏳ Fetching page with each crawler user agent…</span>';

        post( 'csdt_devtools_social_cf_test', { url } ).then( res => {
            btn.disabled = false;
            btn.textContent = '🤖 Test All Crawlers';
            if ( ! res.success ) {
                if ( resultsDiv ) resultsDiv.innerHTML = `<p style="color:#8c2020;font-size:12px">${esc( res.data?.message || 'Error' )}</p>`;
                return;
            }
            if ( resultsDiv ) resultsDiv.innerHTML = renderCfTestResults( res.data, url );
        } ).catch( () => {
            btn.disabled = false;
            btn.textContent = '🤖 Test All Crawlers';
            if ( resultsDiv ) resultsDiv.innerHTML = '<span style="color:#8c2020;font-size:12px">✘ Request failed</span>';
        } );
    } );

    // ── Post Social Preview Scan ──────────────────────────────────────────

    const auditBtn       = document.getElementById( 'cs-thumb-audit-btn' );
    const auditTopBtn    = document.getElementById( 'cs-thumb-audit-top-btn' );
    const auditBrokenBtn = document.getElementById( 'cs-thumb-audit-broken-btn' );
    const auditProgress  = document.getElementById( 'cs-thumb-audit-progress' );
    const auditResults      = document.getElementById( 'cs-thumb-audit-results' );
    const fixAllBtn         = document.getElementById( 'cs-thumb-fix-all-btn' );
    const fixSiteBtn        = document.getElementById( 'cs-thumb-fix-site-btn' );
    const refreshStaleBtn   = document.getElementById( 'cs-thumb-refresh-stale-btn' );
    const staleLog          = document.getElementById( 'cs-thumb-stale-log' );

    let lastScanPosts = [];

    function runScan( mode ) {
        const allBtns = [ auditBtn, auditTopBtn, auditBrokenBtn ].filter( Boolean );
        const btn = mode === 'top' ? auditTopBtn : mode === 'broken_top' ? auditBrokenBtn : auditBtn;
        const loadMsg = mode === 'top'
            ? 'Scanning top posts by view count…'
            : mode === 'broken_top'
            ? 'Finding top posts with broken or missing images…'
            : 'Reading featured images for the last 50 published posts…';
        const origLabel = btn ? btn.innerHTML : '';

        allBtns.forEach( b => { b.disabled = true; } );
        if ( btn ) btn.textContent = 'Scanning…';
        if ( auditProgress ) auditProgress.textContent = 'Checking featured images…';
        if ( fixAllBtn ) fixAllBtn.style.display = 'none';
        setLoading( auditResults, loadMsg );

        post( 'csdt_devtools_social_scan_media', { mode } ).then( res => {
            allBtns.forEach( b => { b.disabled = false; } );
            if ( btn ) btn.innerHTML = origLabel;
            if ( auditProgress ) auditProgress.textContent = '';
            if ( ! res.success ) {
                auditResults.innerHTML = `<p style="color:#8c2020">${esc( res.data?.message || 'Error' )}</p>`;
                return;
            }
            lastScanPosts = res.data.posts || [];
            auditResults.style.display = 'block';
            auditResults.innerHTML = renderPostScan( res.data );
            const fixable = lastScanPosts.filter( p => p.can_fix && p.status !== 'pass' );
            if ( fixAllBtn && fixable.length ) fixAllBtn.style.display = '';
        } ).catch( () => {
            allBtns.forEach( b => { b.disabled = false; } );
            if ( btn ) btn.innerHTML = origLabel;
            if ( auditProgress ) auditProgress.textContent = '';
            auditResults.innerHTML = '<p style="color:#8c2020">Request failed.</p>';
        } );
    }

    if ( auditBtn )       auditBtn.addEventListener(       'click', () => runScan( 'recent' ) );
    if ( auditTopBtn )    auditTopBtn.addEventListener(    'click', () => runScan( 'top' ) );
    if ( auditBrokenBtn ) auditBrokenBtn.addEventListener( 'click', () => runScan( 'broken_top' ) );

    // Collapse / expand scan results.
    document.addEventListener( 'click', ( e ) => {
        const btn = e.target.closest( '.cs-scan-collapse-btn' );
        if ( ! btn ) return;
        const rows = btn.closest( '#cs-thumb-audit-results' )?.querySelector( '.cs-scan-rows' );
        if ( ! rows ) return;
        const collapsed = rows.style.display === 'none';
        rows.style.display = collapsed ? '' : 'none';
        btn.textContent = collapsed ? '▲ Collapse' : '▼ Expand';
    } );

    // Filter scan rows by severity.
    document.addEventListener( 'click', ( e ) => {
        const btn = e.target.closest( '.cs-scan-view-btn' );
        if ( ! btn ) return;
        const filter  = btn.dataset.filter;
        const rowsEl  = document.getElementById( btn.dataset.target );
        if ( ! rowsEl ) return;
        rowsEl.querySelectorAll( '[data-scan-status]' ).forEach( row => {
            const show = filter === 'all' || row.dataset.scanStatus === filter;
            row.style.display = show ? '' : 'none';
        } );
        btn.closest( 'span' ).querySelectorAll( '.cs-scan-view-btn' ).forEach( b => {
            b.style.fontWeight = b === btn ? '700' : '';
        } );
    } );

    // Fix all posts in one go.
    if ( fixAllBtn ) {
        fixAllBtn.addEventListener( 'click', () => {
            const fixable = lastScanPosts.filter( p => p.can_fix );
            if ( ! fixable.length ) return;
            fixAllBtn.disabled = true;
            let done = 0;
            if ( auditProgress ) auditProgress.textContent = `Generating 0 / ${fixable.length}…`;
            const next = () => {
                if ( done >= fixable.length ) {
                    fixAllBtn.disabled = false;
                    if ( auditProgress ) auditProgress.textContent = `✔ Done — ${done} posts fixed`;
                    return;
                }
                const p = fixable[ done ];
                if ( auditProgress ) auditProgress.textContent = `Generating ${done + 1} / ${fixable.length}…`;
                post( 'csdt_devtools_social_generate_formats', { post_id: p.post_id } ).then( () => {
                    const fixRow = document.getElementById( `cs-scan-fix-row-${p.post_id}` );
                    if ( fixRow ) fixRow.innerHTML = '<span style="color:#276227;font-size:11px">✔ Formats generated</span>';
                    done++;
                    next();
                } ).catch( () => { done++; next(); } );
            };
            next();
        } );
    }

    // Refresh Stale — find posts where thumbnail was replaced and regen.
    if ( refreshStaleBtn && staleLog ) {
        refreshStaleBtn.addEventListener( 'click', () => {
            refreshStaleBtn.disabled = true;
            staleLog.style.display = 'block';
            staleLog.innerHTML = '<strong>Scanning for stale thumbnails…</strong>';

            let totalPosts = 0;
            let checked    = 0;
            let staleFound = 0;
            let fixed      = 0;
            const fixedPosts = [];

            function renderLog() {
                let html = `<strong>Scanned ${checked} / ${totalPosts} posts — ${staleFound} stale found, ${fixed} fixed</strong>`;
                if ( fixedPosts.length ) {
                    html += '<ul style="margin:8px 0 0 16px;line-height:1.8;">';
                    fixedPosts.forEach( p => {
                        const icon = p.ok ? '✓' : '✗';
                        const col  = p.ok ? '#166534' : '#dc2626';
                        const reason = p.reason === 'file_replaced' ? 'file replaced' : 'thumbnail changed';
                        html += `<li><span style="color:${col};font-weight:700;">${icon}</span> <a href="${p.url}" target="_blank" rel="noopener" style="color:#1d4ed8;">${p.title}</a> <span style="color:#6b7280;font-size:11px;">(${reason})</span></li>`;
                    } );
                    html += '</ul>';
                }
                staleLog.innerHTML = html;
            }

            function runBatch( offset ) {
                post( 'csdt_devtools_social_refresh_stale_batch', { offset } ).then( res => {
                    if ( ! res.success ) {
                        refreshStaleBtn.disabled = false;
                        refreshStaleBtn.textContent = '🔄 Refresh Stale';
                        staleLog.innerHTML += '<br><span style="color:#dc2626;">✗ Error — see console.</span>';
                        console.error( 'refresh_stale_batch error', res );
                        return;
                    }
                    const d = res.data;
                    if ( totalPosts === 0 ) totalPosts = d.total;
                    checked += d.checked;

                    ( d.stale || [] ).forEach( p => {
                        staleFound++;
                        if ( p.ok ) fixed++;
                        fixedPosts.push( p );
                    } );

                    renderLog();

                    if ( d.has_more ) {
                        runBatch( d.next_offset );
                    } else {
                        refreshStaleBtn.disabled = false;
                        refreshStaleBtn.textContent = '🔄 Refresh Stale';
                        const summary = staleFound === 0
                            ? '<strong style="color:#166534;">✓ All thumbnails are current — nothing to refresh.</strong>'
                            : `<strong>Done — found ${staleFound} stale, fixed ${fixed}.</strong>`;
                        staleLog.innerHTML = summary + staleLog.innerHTML.replace( /<strong>Scanned.*?<\/strong>/, '' );
                    }
                } ).catch( err => {
                    refreshStaleBtn.disabled = false;
                    refreshStaleBtn.textContent = '🔄 Refresh Stale';
                    staleLog.innerHTML += '<br><span style="color:#dc2626;">✗ Network error.</span>';
                    console.error( 'refresh_stale_batch network error', err );
                } );
            }

            runBatch( 0 );
        } );
    }

    // Fix All Posts on Site — batch endpoint, processes all published posts.
    if ( fixSiteBtn ) {
        fixSiteBtn.addEventListener( 'click', () => {
            if ( ! confirm( 'This will generate social format images for every published post on the site. It may take a few minutes for large sites. Continue?' ) ) return;

            fixSiteBtn.disabled = true;
            if ( auditProgress ) auditProgress.textContent = 'Starting…';

            let totalPosts  = 0;
            let processed   = 0;
            let skipped     = 0;
            let errored     = 0;

            function runBatch( offset ) {
                post( 'csdt_devtools_social_fix_all_batch', { offset } ).then( res => {
                    if ( ! res.success ) {
                        fixSiteBtn.disabled = false;
                        if ( auditProgress ) auditProgress.textContent = '✗ Batch error — see console.';
                        console.error( 'csdt_devtools_social_fix_all_batch error', res );
                        return;
                    }
                    const d = res.data;
                    if ( totalPosts === 0 ) totalPosts = d.total;

                    ( d.batch || [] ).forEach( item => {
                        if ( item.skipped )    skipped++;
                        else if ( item.ok )    processed++;
                        else                   errored++;
                    } );

                    const done = processed + skipped + errored;
                    if ( auditProgress ) auditProgress.textContent = `Processing ${done} / ${totalPosts} — ${processed} fixed, ${skipped} skipped${errored ? ', ' + errored + ' errors' : ''}`;

                    if ( d.has_more ) {
                        runBatch( d.next_offset );
                    } else {
                        fixSiteBtn.disabled = false;
                        if ( auditProgress ) auditProgress.textContent = `✔ Done — ${processed} fixed, ${skipped} skipped${errored ? ', ' + errored + ' errors' : ''}`;
                    }
                } ).catch( err => {
                    fixSiteBtn.disabled = false;
                    if ( auditProgress ) auditProgress.textContent = '✗ Network error.';
                    console.error( 'fix_all_batch network error', err );
                } );
            }

            runBatch( 0 );
        } );
    }

    const PLATFORM_LABELS = {
        facebook:  'FB',
        twitter:   'X',
        whatsapp:  'WA',
        linkedin:  'LI',
        instagram: 'IG',
    };

    const PLATFORM_FULL = {
        facebook:  'Facebook',
        twitter:   'X / Twitter',
        whatsapp:  'WhatsApp',
        linkedin:  'LinkedIn',
        instagram: 'Instagram',
    };

    // ── Platform detail modal (shared, one per page) ──────────────────────

    const platformModal = ( () => {
        const overlay = document.createElement( 'div' );
        overlay.id = 'cs-platform-modal-overlay';
        overlay.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99998;overflow-y:auto;padding:40px 16px';
        const box = document.createElement( 'div' );
        box.style.cssText = 'background:#fff;border-radius:8px;max-width:520px;margin:0 auto;box-shadow:0 8px 32px rgba(0,0,0,.25);overflow:hidden';
        overlay.appendChild( box );
        document.body.appendChild( overlay );
        overlay.addEventListener( 'click', ( e ) => { if ( e.target === overlay ) close(); } );
        document.addEventListener( 'keydown', ( e ) => { if ( e.key === 'Escape' ) close(); } );
        function close() { overlay.style.display = 'none'; }
        function open( title, platforms ) {
            const STATUS_LABELS = { pass: 'Ready', warn: 'Warning', fail: 'Issue' };
            const cols = { pass: '#276227', warn: '#7a5a00', fail: '#8c2020' };
            const bgs  = { pass: '#edfaed', warn: '#fff8e5', fail: '#fdf0f0' };
            const icons = { pass: '✔', warn: '⚠', fail: '✘' };
            let rows = '';
            for ( const [ key, p ] of Object.entries( platforms ) ) {
                const full  = PLATFORM_FULL[ key ] || key;
                const col   = cols[ p.status ]  || '#555';
                const bg    = bgs[ p.status ]   || '#f6f7f7';
                const icon  = icons[ p.status ] || 'ℹ';
                const badge = STATUS_LABELS[ p.status ] || p.status;
                rows += `<div style="display:flex;align-items:flex-start;gap:12px;padding:10px 20px;border-bottom:1px solid #f0f0f0">
                    <div style="min-width:96px;font-size:13px;font-weight:600;color:#333;padding-top:2px">${esc( full )}</div>
                    <div style="flex:1">
                        <span style="display:inline-block;background:${bg};color:${col};padding:1px 8px;border-radius:10px;font-size:11px;font-weight:700;margin-bottom:4px">${icon} ${esc( badge )}</span>
                        <div style="font-size:12px;color:#50575e;line-height:1.5">${esc( p.msg )}</div>
                    </div>
                </div>`;
            }
            box.innerHTML = `
                <div style="background:#1d2327;color:#fff;padding:14px 20px;display:flex;align-items:center;justify-content:space-between">
                    <strong style="font-size:14px">📋 Social Platform Compatibility</strong>
                    <button onclick="document.getElementById('cs-platform-modal-overlay').style.display='none'"
                        style="background:none;border:none;color:#fff;font-size:18px;cursor:pointer;line-height:1;padding:0 2px">&times;</button>
                </div>
                <div style="padding:12px 20px 6px;font-size:12px;color:#555;border-bottom:1px solid #eee">${esc( title )}</div>
                ${rows}
                <div style="padding:10px 20px;font-size:11px;color:#999;text-align:right">Click outside or press Esc to close</div>`;
            overlay.style.display = 'block';
        }
        return { open };
    } )();

    function renderPlatformChips( platforms, postId, title ) {
        if ( ! platforms ) return '';
        const jsonAttr = esc( JSON.stringify( platforms ) );
        const titleAttr = esc( title || '' );
        let html = `<div class="cs-platform-chips" data-platforms="${jsonAttr}" data-title="${titleAttr}" style="display:flex;flex-wrap:wrap;gap:4px;margin-top:5px;cursor:pointer" title="Click any chip to see full details">`;
        for ( const [ key, p ] of Object.entries( platforms ) ) {
            const label = PLATFORM_LABELS[ key ] || key;
            const bg    = p.status === 'pass' ? '#edfaed' : p.status === 'warn' ? '#fff8e5' : '#fdf0f0';
            const col   = p.status === 'pass' ? '#276227' : p.status === 'warn' ? '#7a5a00' : '#8c2020';
            const icon  = p.status === 'pass' ? '✔' : p.status === 'warn' ? '⚠' : '✘';
            html += `<span class="cs-chip" style="display:inline-flex;align-items:center;gap:3px;background:${bg};color:${col};padding:2px 7px;border-radius:10px;font-size:11px;font-weight:600;cursor:pointer;user-select:none"
                >${icon} ${esc( label )}</span>`;
        }
        html += '</div>';
        return html;
    }

    // Any chip click → open modal with all platforms for that post.
    document.addEventListener( 'click', ( e ) => {
        const chip = e.target.closest( '.cs-chip' );
        if ( ! chip ) return;
        const wrap = chip.closest( '.cs-platform-chips' );
        if ( ! wrap ) return;
        try {
            const platforms = JSON.parse( wrap.dataset.platforms || '{}' );
            platformModal.open( wrap.dataset.title || '', platforms );
        } catch ( err ) { /* ignore */ }
    } );

    function renderPostScan( data ) {
        const { total_scanned, pass, warn, fail, posts, mode, sort_note } = data;
        const modeLabel = mode === 'top' ? 'top' : mode === 'broken_top' ? 'top (broken only)' : 'most recent';
        const sortHint  = sort_note ? ` <span style="color:#888;font-size:11px">(${esc( sort_note )})</span>` : '';

        const filterId = 'cs-scan-filter-' + Date.now();
        let html = `<div style="margin-bottom:12px;font-size:13px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <span>Checked <strong>${esc( String( total_scanned ) )}</strong> ${esc( modeLabel )} posts${sortHint} —
            <span style="color:#276227">✔ ${esc( String( pass ) )} all platforms OK</span> &nbsp;
            <span style="color:#7a5a00">⚠ ${esc( String( warn ) )} warnings</span> &nbsp;
            <span style="color:#8c2020">✘ ${esc( String( fail ) )} issues</span></span>
            <span style="display:flex;gap:4px;margin-left:auto;flex-shrink:0">
                <button type="button" class="cs-scan-view-btn button button-small" data-filter="all"    data-target="${esc(filterId)}" style="font-size:11px;font-weight:700;background:#166534;color:#fff;border-color:#166534">All</button>
                <button type="button" class="cs-scan-view-btn button button-small" data-filter="warn"   data-target="${esc(filterId)}" style="font-size:11px;font-weight:600;background:#92400e;color:#fff;border-color:#92400e">⚠ Warnings</button>
                <button type="button" class="cs-scan-view-btn button button-small" data-filter="fail"   data-target="${esc(filterId)}" style="font-size:11px;font-weight:600;background:#991b1b;color:#fff;border-color:#991b1b">✘ Issues</button>
            </span>
            <button type="button" class="cs-scan-collapse-btn button button-small" style="font-size:11px;flex-shrink:0">▲ Collapse</button>
        </div>
        <div class="cs-scan-rows" id="${esc(filterId)}">`;

        const problem = posts.filter( p => p.status !== 'pass' );

        for ( const p of problem ) {
            const dims = p.width && p.height ? `${p.width}×${p.height}px` : '';
            const size = p.size_kb !== null ? `${p.size_kb} KB` : '';
            const meta = [ dims, size ].filter( Boolean ).join( ' · ' );

            const imgPreview = p.img_url
                ? `<img src="${esc( p.img_url )}" style="width:60px;height:40px;object-fit:cover;border-radius:3px;flex-shrink:0;border:1px solid #ddd" loading="lazy" alt="">`
                : `<div style="width:60px;height:40px;background:#f0f0f0;border-radius:3px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:18px;border:1px solid #ddd">🖼</div>`;

            const overallCol = p.status === 'fail' ? '#8c2020' : '#7a5a00';
            const overallIcon = p.status === 'fail' ? '✘' : '⚠';

            // Pick the single worst platform message to show inline.
            let worstMsg = '';
            if ( p.platforms ) {
                const failEntry = Object.values( p.platforms ).find( pl => pl.status === 'fail' );
                const warnEntry = Object.values( p.platforms ).find( pl => pl.status === 'warn' );
                const worst = failEntry || warnEntry;
                if ( worst ) worstMsg = worst.msg;
            }

            const pid = esc( String( p.post_id ) );
            const aid = esc( String( p.attach_id || '' ) );

            // Convert to JPEG — only shown when source image itself is the og:image (no social
            // formats yet) AND source is oversized for WhatsApp (>300 KB hard limit).
            const noFormats   = !p.has_social_formats;
            const srcOversized = p.size_kb !== null && p.size_kb > 300;
            const convertBtn = ( p.can_fix && aid && noFormats && srcOversized )
                ? `<button class="button button-small cs-scan-convert-btn" data-attach-id="${aid}" data-post-id="${pid}" style="font-size:11px;background:#b71c1c;color:#fff;border-color:#b71c1c" title="Convert source image to JPEG and compress below 300 KB">🔄 Convert to JPEG</button>`
                : '';

            // Generate social crops — primary action when formats haven't been made yet,
            // or when re-generation is needed (formats exist but something is wrong).
            const fixBtn = p.can_fix
                ? `<button class="button button-small cs-scan-fix-btn" data-post-id="${pid}" style="font-size:11px;${noFormats ? 'font-weight:700;background:#1565c0;color:#fff;border-color:#1565c0' : ''}" title="Generate Facebook/WhatsApp/Twitter crops from the existing featured image">🔧 ${noFormats ? 'Generate social crops' : 'Re-generate crops'}</button>`
                : '';

            // AI generate — shown when no image OR when best available source is too small to crop cleanly.
            const needsAi  = p.needs_ai || p.no_image;
            const aiLabel  = p.no_image ? '✨ AI Generate Image' : '✨ AI Replace (too small)';
            const aiStyle  = p.no_image
                ? 'font-size:11px;background:#0d7377;color:#fff;border-color:#0d7377'
                : 'font-size:11px;background:#6b21a8;color:#fff;border-color:#6b21a8';
            const aiGenBtn = needsAi
                ? `<button type="button" class="button button-small cs-scan-ai-gen-btn cs-btn-primary" data-post-id="${pid}" style="${aiStyle}">${aiLabel}</button>`
                : '';

            const diagBtn = `<button class="button button-small cs-scan-diag-btn" data-post-id="${pid}" style="font-size:11px">🔍 Diagnose</button>`;

            html += `<div data-scan-status="${esc(p.status)}" style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid #f0f0f0">
                ${imgPreview}
                <div style="flex:1;min-width:0">
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                        <span style="color:${overallCol};font-weight:700;font-size:13px">${overallIcon}</span>
                        <a href="${esc( p.post_url )}" target="_blank" rel="noopener" style="font-size:13px;font-weight:600;color:#1a3a8f;text-decoration:none;word-break:break-word">${esc( p.title )}</a>
                        ${p.view_count !== null && p.view_count !== undefined
                            ? `<span style="font-size:11px;color:#888;white-space:nowrap">👁 ${Number(p.view_count).toLocaleString()} views</span>`
                            : p.comment_count ? `<span style="font-size:11px;color:#aaa;white-space:nowrap">💬 ${p.comment_count} comments</span>` : ''}
                    </div>
                    ${meta ? `<div style="font-size:11px;color:#888;margin-top:2px">${esc( meta )}</div>` : ''}
                    ${worstMsg ? `<div style="font-size:11px;color:${overallCol};margin-top:3px;font-weight:600">${overallIcon} ${esc( worstMsg )}</div>` : ''}
                    ${p.no_image
                        ? `<div style="font-size:12px;color:#8c2020;margin-top:3px;font-weight:600">✘ No featured image — use AI Generate to create one</div>`
                        : renderPlatformChips( p.platforms, p.post_id, p.title )}
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;align-items:center">
                        <button class="button button-small cs-thumb-recheck-btn" data-url="${esc( p.post_url )}" style="font-size:11px">Re-check</button>
                        <a href="${esc( p.post_url )}" target="_blank" rel="noopener" class="button button-small" style="font-size:11px">View Post</a>
                        ${aiGenBtn}
                        ${convertBtn}
                        ${fixBtn}
                        ${diagBtn}
                    </div>
                    <div id="cs-scan-fix-row-${pid}" style="margin-top:4px"></div>
                    <div id="cs-scan-diag-row-${pid}" style="margin-top:4px"></div>
                    <div id="cs-ai-thumb-${pid}" style="margin-top:4px"></div>
                    <span id="cs-ai-status-${pid}" style="display:block;font-size:11px;color:#555;word-break:break-word;margin-top:2px"></span>
                </div>
            </div>`;
        }

        if ( fail === 0 && warn === 0 ) {
            html += `<p style="color:#276227;font-weight:600;margin-top:8px">✔ All ${esc( String( total_scanned ) )} posts are ready for all social platforms.</p>`;
        } else if ( problem.length < total_scanned ) {
            const okCount = total_scanned - problem.length;
            html += `<p style="color:#276227;font-size:12px;margin-top:10px">✔ ${esc( String( okCount ) )} post${okCount === 1 ? '' : 's'} already ready for all platforms — not shown above.</p>`;
        }

        html += '</div>'; // close cs-scan-rows

        return html;
    }

    // Re-check individual post from scan table.
    document.addEventListener( 'click', ( e ) => {
        const btn = e.target.closest( '.cs-thumb-recheck-btn' );
        if ( ! btn ) return;
        const url = btn.dataset.url;
        if ( checkUrlEl ) checkUrlEl.value = url;
        checkResults.style.display = 'block';
        setLoading( checkResults, `Re-checking ${url}…` );
        checkBtn?.scrollIntoView( { behavior: 'smooth', block: 'center' } );

        post( 'csdt_devtools_social_check_url', { url } ).then( res => {
            if ( ! res.success ) {
                checkResults.innerHTML = `<p style="color:#8c2020">${esc( res.data?.message || 'Error' )}</p>`;
                return;
            }
            checkResults.innerHTML = renderReport( res.data, url );
            checkResults.scrollIntoView( { behavior: 'smooth', block: 'start' } );
        } ).catch( () => {
            checkResults.innerHTML = '<p style="color:#8c2020">Request failed.</p>';
        } );
    } );

    // Fix individual post — generate platform formats.
    document.addEventListener( 'click', ( e ) => {
        const btn = e.target.closest( '.cs-scan-fix-btn' );
        if ( ! btn ) return;
        const postId = btn.dataset.postId;
        const fixRow = document.getElementById( `cs-scan-fix-row-${postId}` );
        btn.disabled = true;
        btn.textContent = 'Generating…';
        if ( fixRow ) fixRow.innerHTML = '<span style="color:#555;font-size:11px">⏳ Generating platform formats…</span>';

        post( 'csdt_devtools_social_generate_formats', { post_id: postId } ).then( res => {
            btn.style.display = 'none';
            if ( ! res.success ) {
                if ( fixRow ) fixRow.innerHTML = `<span style="color:#8c2020;font-size:11px">✘ ${esc( res.data?.message || 'Failed' )}</span>`;
                btn.disabled = false;
                btn.textContent = '🔧 Generate social crops';
                btn.style.display = '';
                return;
            }
            if ( fixRow ) fixRow.innerHTML = renderFixResult( res.data );
        } ).catch( () => {
            btn.disabled = false;
            btn.textContent = '🔧 Generate social crops';
            if ( fixRow ) fixRow.innerHTML = '<span style="color:#8c2020;font-size:11px">✘ Request failed</span>';
        } );
    } );

    // Convert to JPEG — recompress oversized image via existing fix handler.
    document.addEventListener( 'click', ( e ) => {
        const btn = e.target.closest( '.cs-scan-convert-btn' );
        if ( ! btn ) return;
        const attachId = btn.dataset.attachId;
        const postId   = btn.dataset.postId;
        const fixRow   = document.getElementById( `cs-scan-fix-row-${postId}` );
        btn.disabled    = true;
        btn.textContent = 'Converting…';
        if ( fixRow ) fixRow.innerHTML = '<span style="color:#555;font-size:11px">⏳ Converting to JPEG and compressing…</span>';

        post( 'csdt_devtools_social_fix_image', { attachment_id: attachId } ).then( res => {
            if ( ! res.success ) {
                btn.disabled = false;
                btn.textContent = '🔄 Convert to JPEG';
                if ( fixRow ) fixRow.innerHTML = `<span style="color:#8c2020;font-size:11px">✘ ${esc( res.data?.message || 'Failed' )}</span>`;
                return;
            }
            btn.style.display = 'none';
            const kb   = res.data?.new_size_kb ?? '?';
            const ok   = res.data?.under_limit;
            const col  = ok ? '#276227' : '#7a5a00';
            const icon = ok ? '✔' : '⚠';
            if ( fixRow ) fixRow.innerHTML = `<span style="color:${col};font-size:11px">${icon} Converted to JPEG — ${kb} KB${ok ? ' (under 300 KB limit)' : ' — still large, try Generate social crops'}</span>`;
        } ).catch( () => {
            btn.disabled = false;
            btn.textContent = '🔄 Convert to JPEG';
            if ( fixRow ) fixRow.innerHTML = '<span style="color:#8c2020;font-size:11px">✘ Request failed</span>';
        } );
    } );

    // AI Generate button in scan rows — delegates to triggerGenerate from the AI IIFE.
    document.addEventListener( 'click', ( e ) => {
        const btn = e.target.closest( '.cs-scan-ai-gen-btn' );
        if ( ! btn ) return;
        const tg = window.csdtDevtoolsThumbs?.triggerGenerate;
        if ( ! tg ) {
            // Panel hasn't initialised yet — scroll to it so the user can see it loaded
            const aiPanel = document.getElementById( 'cs-ai-img-save-key' );
            if ( aiPanel ) {
                aiPanel.scrollIntoView( { behavior: 'smooth', block: 'center' } );
                aiPanel.focus();
            }
            return;
        }
        tg( btn );
    } );

    function renderFixResult( platforms ) {
        let html = '<div class="cs-fix-modal-wrap">';
        for ( const [ key, r ] of Object.entries( platforms ) ) {
            if ( ! r.success ) {
                html += `<div class="cs-fix-platform-row">
                    <span class="cs-fix-platform-label">${esc( r.label || key )}</span>
                    <span class="cs-fix-platform-status" style="color:#8c2020">✘ ${esc( r.error || 'Failed' )}</span>
                </div>`;
                continue;
            }
            const sizeOk  = r.under_limit;
            const sizeCol = sizeOk ? '#276227' : '#8c2020';
            const sizeIcon = sizeOk ? '✔' : '⚠ over limit';
            html += `<div class="cs-fix-platform-row">
                <span class="cs-fix-platform-label">${esc( r.label )}</span>
                <span class="cs-fix-platform-dims">${esc( r.w + '×' + r.h )}</span>
                <span class="cs-fix-platform-status" style="color:${sizeCol}">${sizeIcon} ${esc( String( r.kb ) )} KB</span>
                <a href="${esc( r.preview_url )}" target="_blank" rel="noopener" title="Preview" style="flex-shrink:0">
                    <img src="${esc( r.preview_url )}" class="cs-fix-preview-thumb" alt="${esc( r.label )}">
                </a>
            </div>`;
        }
        html += '</div>';
        return html;
    }

    // ── Diagnose button — checks meta, disk, and crawler URL reachability ──
    document.addEventListener( 'click', ( e ) => {
        const btn = e.target.closest( '.cs-scan-diag-btn' );
        if ( ! btn ) return;
        const postId  = btn.dataset.postId;
        const diagRow = document.getElementById( `cs-scan-diag-row-${postId}` );
        btn.disabled  = true;
        btn.textContent = 'Diagnosing…';
        if ( diagRow ) diagRow.innerHTML = '<span style="color:#555;font-size:11px">⏳ Running diagnostics — fetching page and image URLs with crawler user agents…</span>';

        post( 'csdt_devtools_social_diagnose_formats', { post_id: postId } ).then( res => {
            btn.disabled    = false;
            btn.textContent = '🔍 Diagnose';
            if ( ! res.success ) {
                if ( diagRow ) diagRow.innerHTML = `<span style="color:#8c2020;font-size:11px">✘ ${esc( res.data?.message || 'Failed' )}</span>`;
                return;
            }
            if ( diagRow ) diagRow.innerHTML = renderDiagResult( res.data );
        } ).catch( () => {
            btn.disabled    = false;
            btn.textContent = '🔍 Diagnose';
            if ( diagRow ) diagRow.innerHTML = '<span style="color:#8c2020;font-size:11px">✘ Request failed</span>';
        } );
    } );

    function renderDiagResult( d ) {
        const { meta, platforms, og_seen } = d;

        // ── Section helper ──
        const section = ( title, body ) =>
            `<div style="margin-top:8px"><div style="font-size:11px;font-weight:700;color:#333;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">${title}</div>${body}</div>`;

        const row = ( label, content, col ) =>
            `<div style="display:flex;gap:6px;align-items:baseline;font-size:11px;padding:2px 0">
                <span style="min-width:120px;color:#555;flex-shrink:0">${esc( label )}</span>
                <span style="color:${col || '#333'}">${content}</span>
            </div>`;

        let html = `<div style="background:#f9f9f9;border:1px solid #e0e0e0;border-radius:4px;padding:10px 14px;margin-top:6px;font-size:12px">`;

        // ── 1. Meta state ──
        let metaRows = '';
        if ( meta.no_thumbnail ) {
            metaRows += row( 'Featured image', '✘ Not set — no formats can be generated', '#8c2020' );
        } else {
            metaRows += row( 'Featured image', meta.thumb_id_now ? `ID ${meta.thumb_id_now}` : '✘ Missing', meta.thumb_id_now ? '#276227' : '#8c2020' );
            if ( meta.has_new_key ) {
                metaRows += row( 'Format meta (_csdt_)', '✔ Present', '#276227' );
            } else if ( meta.has_old_key ) {
                metaRows += row( 'Format meta (_csdt_)', '⚠ Only old key (_cs_) — run migration', '#7a5a00' );
            } else {
                metaRows += row( 'Format meta (_csdt_)', '✘ Missing — formats were never generated or failed silently', '#8c2020' );
            }
            if ( meta.thumb_stale ) {
                metaRows += row( 'Thumb ID mismatch', `⚠ Saved: ${meta.thumb_id_saved} / Current: ${meta.thumb_id_now} — Fix will regenerate`, '#7a5a00' );
            }
        }
        html += section( '1. Post meta', metaRows );

        // ── 2. Per-platform formats ──
        let platRows = '';
        for ( const [ key, p ] of Object.entries( platforms ) ) {
            let statusHtml = '';
            if ( p.meta_status === 'missing' ) {
                statusHtml = '<span style="color:#8c2020">✘ Not in meta</span>';
            } else if ( p.meta_status === 'failed' ) {
                statusHtml = `<span style="color:#8c2020">✘ Generation failed — ${esc( p.error || '' )}</span>`;
            } else {
                const fileIcon = p.file_exists ? '✔' : '✘ File missing on disk';
                const fileCol  = p.file_exists ? '#276227' : '#8c2020';
                const dims     = p.w && p.h ? ` ${p.w}×${p.h}` : '';
                const kb       = p.file_kb != null ? ` · ${p.file_kb} KB on disk` : ( p.kb ? ` · ${p.kb} KB (meta)` : '' );
                statusHtml = `<span style="color:${fileCol}">${fileIcon}${dims}${kb}</span>`;

                // UA reachability badges
                if ( p.ua_results && Object.keys( p.ua_results ).length ) {
                    statusHtml += ' &nbsp;';
                    for ( const [ ua, r ] of Object.entries( p.ua_results ) ) {
                        const bg  = r.ok ? '#edfaed' : '#fdf0f0';
                        const col = r.ok ? '#276227' : '#8c2020';
                        const txt = r.ok ? `✔ ${esc( ua )} ${r.code}` : `✘ ${esc( ua )} ${r.error || r.code}`;
                        statusHtml += `<span style="background:${bg};color:${col};padding:1px 6px;border-radius:10px;font-size:10px;font-weight:600;margin-right:3px">${txt}</span>`;
                    }
                }
            }
            platRows += row( p.label, statusHtml );
        }
        html += section( '2. Generated format files', platRows );

        // ── 3. What crawlers actually see ──
        let ogRows = '';
        for ( const [ ua, r ] of Object.entries( og_seen ) ) {
            if ( ! r.ok ) {
                ogRows += row( ua, `✘ Could not fetch page — ${esc( r.error || r.code )}`, '#8c2020' );
            } else if ( r.has_og ) {
                const urlShort = r.og_url.length > 60 ? r.og_url.slice( 0, 57 ) + '…' : r.og_url;
                ogRows += row( ua, `✔ og:image found — <a href="${esc( r.og_url )}" target="_blank" rel="noopener" style="color:#1a3a8f">${esc( urlShort )}</a>` );
            } else {
                ogRows += row( ua, '✘ No og:image tag found in page HTML', '#8c2020' );
            }
        }
        html += section( '3. og:image seen by each crawler UA', ogRows );

        html += '</div>';
        return html;
    }

    // ── Cloudflare Crawler Test ───────────────────────────────────────────

    const cfTestBtn     = document.getElementById( 'cs-thumb-cf-test-btn' );
    const cfTestUrl     = document.getElementById( 'cs-thumb-cf-test-url' );
    const cfTestResults = document.getElementById( 'cs-thumb-cf-test-results' );

    if ( cfTestBtn ) {
        cfTestBtn.addEventListener( 'click', () => {
            const url = ( cfTestUrl?.value || siteUrl || '' ).trim();
            if ( ! url ) { alert( 'Please enter a URL to test.' ); return; }
            cfTestBtn.disabled = true;
            cfTestBtn.textContent = 'Testing…';
            setLoading( cfTestResults, 'Sending requests with each social crawler user agent…' );

            post( 'csdt_devtools_social_cf_test', { url } ).then( res => {
                cfTestBtn.disabled = false;
                cfTestBtn.textContent = '🤖 Test Crawler Access';
                if ( ! res.success ) {
                    cfTestResults.innerHTML = `<p style="color:#8c2020">${esc( res.data?.message || 'Error' )}</p>`;
                    return;
                }
                cfTestResults.innerHTML = renderCfTestResults( res.data, url );
            } ).catch( () => {
                cfTestBtn.disabled = false;
                cfTestBtn.textContent = '🤖 Test Crawler Access';
                cfTestResults.innerHTML = '<p style="color:#8c2020">Request failed.</p>';
            } );
        } );
    }

    function renderCfTestResults( results, url ) {
        const allPass = Object.values( results ).every( r => r.type === 'pass' );
        const header  = allPass
            ? '<p style="color:#276227;font-weight:600">✔ All crawlers can access the page — your WAF skip rule is working correctly.</p>'
            : '<p style="color:#8c2020;font-weight:600">✘ One or more crawlers are being blocked. Check your Cloudflare WAF skip rule.</p>';

        let chips = '<div class="cs-thumb-ua-grid">';
        for ( const [ label, r ] of Object.entries( results ) ) {
            const chipCls = r.type === 'pass' ? 'ok' : 'fail';
            const icon    = r.type === 'pass' ? '✔' : '✘';
            chips += `<div class="cs-thumb-ua-chip cs-thumb-ua-${chipCls}" title="${esc( r.msg )}">${icon} ${esc( label )}</div>`;
        }
        chips += '</div>';

        let detail = '<ul class="cs-thumb-results-list" style="margin-top:10px">';
        for ( const [ label, r ] of Object.entries( results ) ) {
            detail += `<li class="cs-thumb-result cs-thumb-${r.type}">
                <span>${r.type === 'pass' ? '✔' : '✘'}</span>
                <span><strong>${esc( label )}:</strong> ${esc( r.msg )}</span>
            </li>`;
        }
        detail += '</ul>';

        return header + chips + detail;
    }

    // ── Cloudflare Cache Purge ────────────────────────────────────────────

    const cfPurgeBtn    = document.getElementById( 'cs-cf-purge-btn' );
    const cfPurgeUrl    = document.getElementById( 'cs-cf-purge-url' );
    const cfPurgeResult = document.getElementById( 'cs-cf-purge-result' );
    const cfSaveBtn     = document.getElementById( 'cs-cf-save-btn' );
    const cfZoneId      = document.getElementById( 'cs-cf-zone-id' );
    const cfApiToken    = document.getElementById( 'cs-cf-api-token' );
    const cfSaved       = document.getElementById( 'cs-cf-saved' );
    const cfZoneEye     = document.getElementById( 'cs-cf-zone-eye' );
    const cfTokenEye    = document.getElementById( 'cs-cf-token-eye' );

    if ( cfZoneEye && cfZoneId ) {
        cfZoneEye.addEventListener( 'click', () => {
            if ( cfZoneId.dataset.masked === '1' ) {
                cfZoneId.value          = cfZoneId.dataset.real || '';
                cfZoneId.dataset.masked = '0';
                cfZoneId.removeAttribute( 'readonly' );
                cfZoneEye.textContent   = '🙈'; // 🙈
            } else {
                const real = cfZoneId.dataset.real || cfZoneId.value.trim();
                cfZoneId.dataset.real   = real;
                cfZoneId.value          = real ? '•'.repeat( 28 ) + real.slice( -4 ) : '';
                cfZoneId.dataset.masked = '1';
                cfZoneId.setAttribute( 'readonly', '' );
                cfZoneEye.textContent   = '👁'; // 👁
            }
        } );
    }

    if ( cfTokenEye && cfApiToken ) {
        cfTokenEye.addEventListener( 'click', () => {
            const isHidden        = cfApiToken.type === 'password';
            cfApiToken.type       = isHidden ? 'text' : 'password';
            cfTokenEye.textContent = isHidden ? '🙈' : '👁'; // 🙈 / 👁
        } );
    }

    if ( cfSaveBtn ) {
        cfSaveBtn.addEventListener( 'click', () => {
            cfSaveBtn.disabled = true;
            // If zone ID is masked, send the stored real value; otherwise send what the user typed.
            const zoneVal = cfZoneId?.dataset.masked === '1'
                ? ( cfZoneId.dataset.real || '' )
                : ( cfZoneId?.value.trim() || '' );
            post( 'csdt_devtools_cf_save', {
                zone_id:   zoneVal,
                api_token: cfApiToken?.value || '',
            } ).then( res => {
                cfSaveBtn.disabled = false;
                if ( res.success ) {
                    if ( cfSaved ) { cfSaved.classList.add( 'visible' ); setTimeout( () => cfSaved.classList.remove( 'visible' ), 10000 ); }
                    if ( cfApiToken ) cfApiToken.value = '';
                } else {
                    alert( res.data?.message || 'Save failed.' );
                }
            } ).catch( () => { cfSaveBtn.disabled = false; alert( 'Request failed.' ); } );
        } );
    }

    if ( cfPurgeBtn ) {
        cfPurgeBtn.addEventListener( 'click', () => {
            cfPurgeBtn.disabled = true;
            cfPurgeBtn.textContent = 'Purging…';
            setLoading( cfPurgeResult, 'Sending purge request to Cloudflare…' );

            post( 'csdt_devtools_cf_purge', { purge_url: cfPurgeUrl?.value.trim() || '' } ).then( res => {
                cfPurgeBtn.disabled = false;
                cfPurgeBtn.textContent = '🗑️ Purge Cache';
                cfPurgeResult.style.display = 'block';
                cfPurgeResult.innerHTML = res.success
                    ? `<p style="color:#276227;font-weight:600">✔ ${esc( res.data?.message || 'Purged.' )}</p>`
                    : `<p style="color:#8c2020">✘ ${esc( res.data?.message || 'Purge failed.' )}</p>`;
            } ).catch( () => {
                cfPurgeBtn.disabled = false;
                cfPurgeBtn.textContent = '🗑️ Purge Cache';
                cfPurgeResult.innerHTML = '<p style="color:#8c2020">Request failed.</p>';
            } );
        } );
    }

    // ── Platform settings save ────────────────────────────────────────────

    const platformSaveBtn = document.getElementById( 'cs-platform-save-btn' );
    const platformSaved   = document.getElementById( 'cs-platform-saved' );

    // Highlight card on checkbox change.
    document.querySelectorAll( '.cs-platform-cb' ).forEach( cb => {
        cb.addEventListener( 'change', () => {
            const card = cb.closest( '.cs-platform-card' );
            if ( card ) card.classList.toggle( 'cs-platform-checked', cb.checked );
        } );
    } );

    if ( platformSaveBtn ) {
        platformSaveBtn.addEventListener( 'click', () => {
            const selected = Array.from( document.querySelectorAll( '.cs-platform-cb:checked' ) )
                .map( cb => cb.value );
            platformSaveBtn.disabled = true;
            const params = { nonce, action: 'csdt_devtools_social_platform_save' };
            selected.forEach( ( v, i ) => { params[ `platforms[${i}]` ] = v; } );
            const body = new URLSearchParams( params );
            fetch( ajaxUrl, { method: 'POST', body } )
                .then( r => r.json() )
                .then( res => {
                    platformSaveBtn.disabled = false;
                    if ( res.success ) {
                        if ( platformSaved ) {
                            platformSaved.classList.add( 'visible' );
                            setTimeout( () => platformSaved.classList.remove( 'visible' ), 10000 );
                        }
                    } else {
                        alert( res.data?.message || 'Save failed.' );
                    }
                } )
                .catch( () => { platformSaveBtn.disabled = false; alert( 'Request failed.' ); } );
        } );
    }

} )();

/* ── Generate Missing Thumbnails ────────────────────────────────────────────
   Scan for missing WordPress image sizes, then regenerate in batches.
 */
( function () {
    'use strict';

    const { ajaxUrl, nonce } = window.csdtDevtoolsThumbs || {};

    function post( action, data ) {
        const body = new URLSearchParams( { action, nonce, ...data } );
        return fetch( ajaxUrl, { method: 'POST', body } )
            .then( r => r.json() )
            .then( res => {
                if ( res === -1 || res === 0 ) {
                    return { success: false, data: { message: 'Session expired — please reload.' } };
                }
                return res;
            } );
    }

    function esc( str ) {
        return String( str )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    }

    const scanBtn      = document.getElementById( 'csdt-regen-scan-btn' );
    const regenAllBtn  = document.getElementById( 'csdt-regen-all-btn' );
    const progress     = document.getElementById( 'csdt-regen-progress' );
    const log          = document.getElementById( 'csdt-regen-log' );
    const resultsDiv   = document.getElementById( 'csdt-regen-results' );
    const doneLabel    = document.getElementById( 'csdt-regen-done-label' );

    let scanData = null; // last scan result

    if ( scanBtn ) {
        scanBtn.addEventListener( 'click', () => {
            scanBtn.disabled = true;
            scanBtn.textContent = 'Scanning…';
            if ( progress ) progress.textContent = 'Checking all Media Library images…';
            if ( log ) log.style.display = 'none';
            if ( resultsDiv ) resultsDiv.style.display = 'none';
            if ( regenAllBtn ) regenAllBtn.style.display = 'none';

            post( 'csdt_devtools_regen_thumb_scan', {} ).then( res => {
                scanBtn.disabled = false;
                scanBtn.textContent = '🔍 Scan for Missing Sizes';
                if ( progress ) progress.textContent = '';
                if ( ! res.success ) {
                    if ( resultsDiv ) {
                        resultsDiv.style.display = 'block';
                        resultsDiv.innerHTML = `<p style="color:#8c2020">${esc( res.data?.message || 'Scan failed.' )}</p>`;
                    }
                    return;
                }
                scanData = res.data;
                renderScanResults( res.data );
            } ).catch( () => {
                scanBtn.disabled = false;
                scanBtn.textContent = '🔍 Scan for Missing Sizes';
                if ( progress ) progress.textContent = '';
                if ( resultsDiv ) {
                    resultsDiv.style.display = 'block';
                    resultsDiv.innerHTML = '<p style="color:#8c2020">Request failed — check your connection.</p>';
                }
            } );
        } );
    }

    function renderScanResults( data ) {
        const { total, missing, images } = data;
        if ( ! resultsDiv ) return;
        resultsDiv.style.display = 'block';

        if ( missing === 0 ) {
            resultsDiv.innerHTML = `<p style="color:#276227;font-weight:600">✔ All ${esc( String( total ) )} images have their thumbnail sizes — nothing to regenerate.</p>`;
            if ( regenAllBtn ) regenAllBtn.style.display = 'none';
            return;
        }

        let html = `<div style="margin-bottom:10px;font-size:13px">
            <span style="color:#8c2020;font-weight:600">⚠ ${esc( String( missing ) )} of ${esc( String( total ) )} images are missing one or more thumbnail sizes.</span>
            <span style="color:#555;font-size:12px;margin-left:8px">These are the files your theme uses to display featured images.</span>
        </div>`;

        const used  = images.filter( i => i.used );
        const other = images.filter( i => ! i.used );

        if ( used.length ) {
            html += `<div style="margin-bottom:8px">
                <div style="font-size:12px;font-weight:700;color:#8c2020;margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em">
                    ⚠ ${esc( String( used.length ) )} are active featured images — these affect how articles look right now
                </div>`;
            html += renderImageGrid( used );
            html += '</div>';
        }

        if ( other.length ) {
            html += `<div>
                <div style="font-size:12px;font-weight:700;color:#555;margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em">
                    ${esc( String( other.length ) )} other images
                </div>`;
            html += renderImageGrid( other );
            html += '</div>';
        }

        resultsDiv.innerHTML = html;
        if ( regenAllBtn ) {
            regenAllBtn.textContent = '⚙️ Regenerate All Missing';
            regenAllBtn.style.display = '';
        }
    }

    function renderImageGrid( images ) {
        let html = '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px">';
        const show = images.slice( 0, 24 );
        for ( const img of show ) {
            const badge = img.used
                ? '<span style="position:absolute;bottom:3px;left:3px;background:rgba(140,32,32,.85);color:#fff;font-size:9px;font-weight:700;padding:1px 5px;border-radius:3px;line-height:1.4">Featured</span>'
                : '';
            const thumb = img.thumb
                ? `<img src="${esc( img.thumb )}" style="width:60px;height:60px;object-fit:cover;border-radius:3px;display:block" alt="" loading="lazy">`
                : `<div style="width:60px;height:60px;background:#f0f0f0;border-radius:3px;display:flex;align-items:center;justify-content:center;color:#aaa;font-size:20px">🖼</div>`;
            html += `<div title="${esc( img.title )}" style="position:relative;width:60px;height:60px;border:1px solid #ddd;border-radius:3px;overflow:hidden;flex-shrink:0">
                ${thumb}${badge}
            </div>`;
        }
        if ( images.length > 24 ) {
            html += `<div style="width:60px;height:60px;background:#f0f0f0;border-radius:3px;display:flex;align-items:center;justify-content:center;color:#555;font-size:11px;font-weight:600;text-align:center;padding:4px;box-sizing:border-box">+${esc( String( images.length - 24 ) )}<br>more</div>`;
        }
        html += '</div>';
        return html;
    }

    if ( regenAllBtn ) {
        regenAllBtn.addEventListener( 'click', () => {
            if ( ! confirm( 'This will regenerate missing thumbnail sizes for all affected images. It may take a minute or two. Continue?' ) ) return;
            regenAllBtn.disabled = true;
            scanBtn.disabled = true;
            if ( log ) { log.style.display = 'block'; log.innerHTML = '<strong>Starting regeneration…</strong>'; }

            let totalImages = scanData ? scanData.total : 0;
            let processed   = 0;
            let regenerated = 0;
            let errors      = 0;
            let nextOffset  = 0;
            let inFlight    = 0;
            const BATCH     = 5;
            const THREADS   = 2;

            function updateLog() {
                if ( ! log ) return;
                const pct = totalImages ? Math.round( processed / totalImages * 100 ) : 0;
                log.innerHTML = `<strong>Processing ${processed}${totalImages ? ' / ' + totalImages : ''} images (${pct}%)</strong> — ${regenerated} regenerated, ${errors} errors`;
            }

            function onAllDone() {
                regenAllBtn.disabled = false;
                scanBtn.disabled = false;
                if ( log ) log.style.display = 'none';
                if ( doneLabel ) {
                    doneLabel.innerHTML = `✔ Done — ${regenerated} regenerated${errors ? `, <span style="color:#dc2626">${errors} errors</span>` : ''}`;
                    doneLabel.style.display = '';
                    setTimeout( () => { doneLabel.style.display = 'none'; }, 10000 );
                }
            }

            function dispatch() {
                while ( inFlight < THREADS ) {
                    if ( totalImages > 0 && nextOffset >= totalImages ) break;
                    const offset = nextOffset;
                    nextOffset += BATCH;
                    inFlight++;
                    post( 'csdt_devtools_regen_thumb_batch', { offset, total: totalImages } ).then( res => {
                        inFlight--;
                        if ( ! res.success ) {
                            errors++;
                        } else {
                            const d = res.data;
                            if ( ! totalImages ) totalImages = d.total;
                            ( d.batch || [] ).forEach( item => {
                                processed++;
                                if ( item.regenerated ) regenerated++;
                                else if ( ! item.ok )   errors++;
                            } );
                            updateLog();
                        }
                        if ( inFlight === 0 && ( totalImages > 0 && nextOffset >= totalImages ) ) {
                            onAllDone();
                        } else {
                            dispatch();
                        }
                    } ).catch( err => {
                        inFlight--;
                        errors++;
                        if ( log ) log.innerHTML += '<br><span style="color:#dc2626">✗ Network error — see console.</span>';
                        console.error( 'regen_thumb_batch error', err );
                        if ( inFlight === 0 && totalImages > 0 && nextOffset >= totalImages ) {
                            onAllDone();
                        } else {
                            dispatch();
                        }
                    } );
                }
            }

            dispatch();
        } );
    }

} )();

/* ── Default Featured Image picker ──────────────────────────────────────────
   Uses wp.media (loaded via wp_enqueue_media on the thumbnails tab).
 */
( function () {
    'use strict';

    const cfg      = window.csdtDevtoolsThumbs || {};
    const ajaxUrl  = cfg.ajaxUrl || '';
    const defNonce = cfg.defimgNonce || '';

    function saveDefImg( id ) {
        const body = new URLSearchParams( { action: 'csdt_save_default_image', nonce: defNonce, image_id: id } );
        fetch( ajaxUrl, { method: 'POST', body } )
            .then( r => r.json() )
            .then( res => {
                const status = document.getElementById( 'csdt-defimg-status' );
                if ( ! status ) return;
                status.textContent = res.success ? 'Saved.' : 'Save failed.';
                status.style.color = res.success ? '#16a34a' : '#dc2626';
            } );
    }

    function init() {
        const btnSelect = document.getElementById( 'csdt-defimg-select' );
        const btnRemove = document.getElementById( 'csdt-defimg-remove' );
        if ( ! btnSelect ) return;

        if ( typeof window.wp === 'undefined' || typeof window.wp.media !== 'function' ) {
            btnSelect.addEventListener( 'click', () => alert( 'Media library not available — try reloading the page.' ) );
            return;
        }

        var mediaFrame;
        btnSelect.addEventListener( 'click', function () {
            if ( mediaFrame ) { mediaFrame.open(); return; }
            mediaFrame = window.wp.media( {
                title: 'Select Default Featured Image',
                button: { text: 'Use this image' },
                multiple: false,
                library: { type: 'image' },
            } );
            mediaFrame.on( 'select', function () {
                const att     = mediaFrame.state().get( 'selection' ).first().toJSON();
                const preview = document.getElementById( 'csdt-defimg-preview' );
                const hiddenId = document.getElementById( 'csdt-defimg-id' );
                const src     = ( att.sizes && att.sizes.medium ) ? att.sizes.medium.url : att.url;
                if ( preview ) preview.innerHTML = '<img src="' + src + '" style="max-width:240px;height:auto;border:1px solid #ddd;border-radius:4px;display:block;" />';
                if ( hiddenId ) hiddenId.value = att.id;
                btnSelect.textContent = 'Change Image';
                if ( btnRemove ) btnRemove.style.display = '';
                saveDefImg( att.id );
            } );
            mediaFrame.open();
        } );

        if ( btnRemove ) {
            btnRemove.addEventListener( 'click', function () {
                const preview  = document.getElementById( 'csdt-defimg-preview' );
                const hiddenId = document.getElementById( 'csdt-defimg-id' );
                if ( preview ) preview.innerHTML = '<div style="width:240px;height:126px;background:#f0f0f0;border:1px dashed #ccc;border-radius:4px;display:flex;align-items:center;justify-content:center;color:#aaa;font-size:12px;">No image selected</div>';
                if ( hiddenId ) hiddenId.value = '0';
                btnSelect.textContent = 'Select Image';
                btnRemove.style.display = 'none';
                saveDefImg( 0 );
            } );
        }
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

} )();

/* ── AI Image Generator ──────────────────────────────────────────────────────
   gpt-image-2 image generation for posts without a featured image.
 */
( function () {
    'use strict';

    const cfg     = window.csdtDevtoolsThumbs || {};

    // IIFE-level state so triggerGenerate works even when init() exits early.
    let curVendor      = window.csdtImgVendor || cfg.defaultVendor || 'openai';
    let curModel       = window.csdtImgModel  || cfg.defaultModel  || 'gpt-4o-mini';
    let modelEl        = null;
    let styleEl        = null;
    let imageModal     = null;
    let promptReviewModal = null;
    const ajaxUrl = cfg.ajaxUrl || '';
    const nonce   = cfg.nonce   || '';

    function post( action, data ) {
        const body = new URLSearchParams( { action, nonce, ...data } );
        return fetch( ajaxUrl, { method: 'POST', body } )
            .then( r => r.json() )
            .then( res => {
                if ( res === -1 || res === 0 ) {
                    return { success: false, data: { message: 'Session expired — please reload.' } };
                }
                return res;
            } );
    }

    function esc( str ) {
        return String( str )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    }

    function decodeHtml( str ) {
        const el = document.createElement( 'textarea' );
        el.innerHTML = str;
        return el.value;
    }

    function init() {
        const saveKeyBtn = document.getElementById( 'cs-ai-img-save-key' );
        const scanBtn    = document.getElementById( 'cs-ai-img-scan-btn' );
        const results    = document.getElementById( 'cs-ai-img-results' );

        if ( ! saveKeyBtn ) return;

        // ── Vendor / model / key setup ────────────────────────────────────
        const MODELS = {
            openai:    [ { value: '_auto', label: 'Automatic (best)' }, { value: 'gpt-4o-mini', label: 'GPT-4o mini (fast)' }, { value: 'gpt-4o', label: 'GPT-4o' } ],
            anthropic: [ { value: '_auto', label: 'Automatic (best)' }, { value: 'claude-haiku-4-5-20251001', label: 'Claude Haiku 4.5 (fast)' }, { value: 'claude-sonnet-4-6', label: 'Claude Sonnet 4.6' }, { value: 'claude-opus-4-7', label: 'Claude Opus 4.7 (best)' } ],
            gemini:    [ { value: '_auto', label: 'Automatic (best)' }, { value: 'gemini-2.0-flash', label: 'Gemini 2.0 Flash (fast)' }, { value: 'gemini-1.5-pro', label: 'Gemini 1.5 Pro' } ],
            none:      [],
        };
        const KEY_HINTS = {
            openai:    'platform.openai.com → API keys → Create new secret key. Also used for gpt-image-2 image generation.',
            anthropic: 'console.anthropic.com → API Keys → Create Key. Starts with sk-ant-…',
            gemini:    'aistudio.google.com → Get API Key. Or Google Cloud Console → Credentials.',
            none:      '',
        };
        const KEY_PLACEHOLDERS = { openai: 'sk-proj-…', anthropic: 'sk-ant-api03-…', gemini: 'AIzaSy…', none: '' };

        const vendorEl   = document.getElementById( 'cs-ai-img-vendor' );
        modelEl          = document.getElementById( 'cs-ai-img-model' );
        const modelRow   = document.getElementById( 'cs-ai-img-model-row' );
        const keyRow     = document.getElementById( 'cs-ai-img-key-row' );
        const keyLabel   = document.getElementById( 'cs-ai-img-key-label' );
        const keyInput   = document.getElementById( 'cs-ai-img-openai-key' );
        const keyHint    = document.getElementById( 'cs-ai-img-key-hint' );
        const dalleRow   = document.getElementById( 'cs-ai-img-dalle-key-row' );
        const keyStatus  = document.getElementById( 'cs-ai-img-key-status' );

        // Keys and initial vendor/model from PHP inline script.
        const storedKeys  = window.csdtImgKeys  || {};
        // curVendor / curModel are declared at IIFE scope above — update them from DOM.

        function applyVendor( vendor ) {
            curVendor = vendor;
            const isNone = vendor === 'none';
            if ( modelRow ) modelRow.style.display = isNone ? 'none' : '';
            if ( keyRow )   keyRow.style.display   = isNone ? 'none' : '';
            if ( dalleRow ) dalleRow.style.display  = ( vendor === 'openai' || isNone ) ? 'none' : '';
            if ( keyLabel ) keyLabel.textContent = vendor === 'openai' ? 'OpenAI API Key:' : vendor === 'anthropic' ? 'Anthropic API Key:' : vendor === 'gemini' ? 'Google API Key:' : 'API Key:';
            if ( keyHint  ) keyHint.textContent  = KEY_HINTS[ vendor ] || '';
            if ( keyInput ) {
                const wasVisible     = keyInput.type === 'text';
                keyInput.placeholder = KEY_PLACEHOLDERS[ vendor ] || '';
                keyInput.value       = storedKeys[ vendor ] || '';
                keyInput.type        = wasVisible ? 'text' : 'password';
            }
            if ( keyStatus ) keyStatus.innerHTML = storedKeys[ vendor ] ? '<span style="color:#2e7d32">✓ Key saved</span>' : '';

            // Rebuild model options.
            if ( modelEl ) {
                modelEl.innerHTML = '';
                ( MODELS[ vendor ] || [] ).forEach( m => {
                    const opt = document.createElement( 'option' );
                    opt.value = m.value;
                    opt.textContent = m.label;
                    if ( m.value === curModel ) opt.selected = true;
                    modelEl.appendChild( opt );
                } );
                if ( modelEl.options.length && ! modelEl.value ) modelEl.selectedIndex = 0;
                curModel = modelEl.value || curModel;
            }
        }

        applyVendor( curVendor );
        if ( vendorEl ) vendorEl.value = curVendor;

        if ( vendorEl ) {
            vendorEl.addEventListener( 'change', () => applyVendor( vendorEl.value ) );
        }
        if ( modelEl ) {
            modelEl.addEventListener( 'change', () => { curModel = modelEl.value; } );
        }

        // ── Eye toggle ────────────────────────────────────────────────────
        const keyToggleBtn = document.getElementById( 'cs-ai-img-key-toggle' );
        if ( keyToggleBtn ) {
            keyToggleBtn.addEventListener( 'click', () => {
                if ( ! keyInput ) return;
                keyInput.type = keyInput.type === 'password' ? 'text' : 'password';
                keyToggleBtn.style.opacity = keyInput.type === 'text' ? '1' : '0.4';
            } );
        }
        const dalleToggle = document.getElementById( 'cs-ai-img-dalle-key-toggle' );
        if ( dalleToggle ) {
            dalleToggle.addEventListener( 'click', () => {
                const di = document.getElementById( 'cs-ai-img-dalle-key' );
                if ( ! di ) return;
                di.type = di.type === 'password' ? 'text' : 'password';
                dalleToggle.style.opacity = di.type === 'text' ? '1' : '0.4';
            } );
        }

        // ── Copy API key ──────────────────────────────────────────────────
        const copyKeyBtn = document.getElementById( 'cs-ai-img-copy-key' );
        if ( copyKeyBtn ) {
            copyKeyBtn.addEventListener( 'click', () => {
                const val = ( keyInput?.value || '' ).trim();
                if ( ! val ) return;
                navigator.clipboard.writeText( val ).then( () => {
                    const orig = copyKeyBtn.innerHTML;
                    copyKeyBtn.textContent = '✔ Copied';
                    copyKeyBtn.style.color = '#16a34a';
                    setTimeout( () => { copyKeyBtn.innerHTML = orig; copyKeyBtn.style.color = ''; }, 2000 );
                } ).catch( () => {
                    copyKeyBtn.textContent = '✘ Failed';
                    setTimeout( () => { copyKeyBtn.innerHTML = '📋 Copy'; }, 2000 );
                } );
            } );
        }

        // ── Test key ──────────────────────────────────────────────────────
        const testKeyBtn = document.getElementById( 'cs-ai-img-test-key' );
        if ( testKeyBtn ) {
            testKeyBtn.addEventListener( 'click', () => {
                testKeyBtn.disabled    = true;
                testKeyBtn.textContent = '⏳ Testing…';
                post( 'csdt_devtools_ai_image_test_key', { vendor: curVendor } )
                    .then( res => {
                        testKeyBtn.disabled    = false;
                        testKeyBtn.textContent = 'Test';
                        if ( keyStatus ) keyStatus.innerHTML = res.success
                            ? '<span style="color:#2e7d32">' + esc( res.data.message ) + '</span>'
                            : '<span style="color:#c62828">✗ ' + esc( res.data?.message || 'Test failed.' ) + '</span>';
                    } )
                    .catch( () => {
                        testKeyBtn.disabled    = false;
                        testKeyBtn.textContent = 'Test';
                        if ( keyStatus ) keyStatus.innerHTML = '<span style="color:#c62828">✗ Request failed</span>';
                    } );
            } );
        }

        // ── Save prompt-writer key ────────────────────────────────────────
        saveKeyBtn.addEventListener( 'click', () => {
            const rawKey = ( keyInput?.value || '' ).trim();
            if ( ! rawKey ) { alert( 'Enter an API key first.' ); return; }
            saveKeyBtn.disabled    = true;
            saveKeyBtn.textContent = 'Saving…';
            post( 'csdt_devtools_ai_image_save_key', { vendor: curVendor, key: rawKey, prompt_vendor: curVendor, prompt_model: modelEl?.value || curModel } )
                .then( res => {
                    saveKeyBtn.disabled    = false;
                    saveKeyBtn.textContent = 'Save Key';
                    if ( res.success ) {
                        storedKeys[ curVendor ] = res.data.key || rawKey;
                        if ( keyInput ) keyInput.value = storedKeys[ curVendor ];
                        if ( keyStatus ) keyStatus.innerHTML = '<span style="color:#2e7d32">✓ Key saved</span>';
                    } else {
                        if ( keyStatus ) keyStatus.innerHTML = '<span style="color:#c62828">✗ ' + esc( res.data?.message || 'Save failed.' ) + '</span>';
                    }
                } )
                .catch( () => {
                    saveKeyBtn.disabled    = false;
                    saveKeyBtn.textContent = 'Save Key';
                    alert( 'Request failed.' );
                } );
        } );

        // ── Save OpenAI key (shown when non-OpenAI vendor) ───────────────
        const dalleSaveBtn = document.getElementById( 'cs-ai-img-dalle-save-key' );
        if ( dalleSaveBtn ) {
            dalleSaveBtn.addEventListener( 'click', () => {
                const di = document.getElementById( 'cs-ai-img-dalle-key' );
                const ds = document.getElementById( 'cs-ai-img-dalle-key-status' );
                const rawKey = ( di?.value || '' ).trim();
                if ( ! rawKey ) { alert( 'Enter your OpenAI key for gpt-image-2.' ); return; }
                dalleSaveBtn.disabled    = true;
                dalleSaveBtn.textContent = 'Saving…';
                post( 'csdt_devtools_ai_image_save_key', { vendor: 'openai', key: rawKey } )
                    .then( res => {
                        dalleSaveBtn.disabled    = false;
                        dalleSaveBtn.textContent = 'Save OpenAI Key';
                        storedKeys.openai = rawKey;
                        if ( ds ) ds.innerHTML = res.success ? '<span style="color:#2e7d32">✓ Key saved</span>' : '<span style="color:#c62828">✗ Failed</span>';
                    } )
                    .catch( () => {
                        dalleSaveBtn.disabled    = false;
                        dalleSaveBtn.textContent = 'Save OpenAI Key';
                    } );
            } );
        }

        // ── Auto re-scan when sort changes and results are visible ───────
        const sortEl      = document.getElementById( 'cs-ai-img-sort' );
        const withImgBtn  = document.getElementById( 'cs-ai-img-scan-with-btn' );
                styleEl         = document.getElementById( 'cs-ai-img-style' );
        const qualityEl = document.getElementById( 'cs-ai-img-quality' );
        const noTextEl  = document.getElementById( 'cs-ai-img-no-text' );
        let   activeMode = 'missing';

        // Initialise style / quality / no_text from saved PHP values.
        if ( styleEl   && window.csdtImgStyle   ) { styleEl.value    = window.csdtImgStyle; }
        if ( qualityEl && window.csdtImgQuality ) { qualityEl.value  = window.csdtImgQuality; }
        if ( noTextEl  && window.csdtImgNoText  ) { noTextEl.checked = true; }

        // Auto-save whenever any setting changes.
        function saveSettings() {
            const style   = styleEl?.value    || 'auto';
            const quality = qualityEl?.value  || 'hd';
            const no_text = noTextEl?.checked ? '1' : '0';
            post( 'csdt_devtools_ai_image_save_settings', { style, quality, no_text } );
        }
        if ( styleEl   ) { styleEl.addEventListener(   'change', saveSettings ); }
        if ( qualityEl ) { qualityEl.addEventListener( 'change', saveSettings ); }
        if ( noTextEl  ) { noTextEl.addEventListener(  'change', saveSettings ); }

        if ( sortEl ) {
            sortEl.addEventListener( 'change', () => {
                if ( results && results.style.display !== 'none' && results.innerHTML.trim() ) {
                    doScan( activeMode );
                }
            } );
        }

        function doScan( mode ) {
            activeMode = mode;
            const sort = document.getElementById( 'cs-ai-img-sort' )?.value || 'newest';
            const isMissingMode = mode === 'missing';
            const activeBtn  = isMissingMode ? scanBtn : withImgBtn;
            const inactiveBtn = isMissingMode ? withImgBtn : scanBtn;
            if ( activeBtn )   { activeBtn.disabled = true;  activeBtn.textContent = 'Scanning…'; }
            if ( inactiveBtn ) { inactiveBtn.disabled = true; }
            if ( results ) {
                results.style.display = 'block';
                results.innerHTML     = '<p style="color:#555;font-size:13px">⏳ Scanning posts…</p>';
            }

            post( 'csdt_devtools_ai_image_scan', { sort, mode } )
                .then( res => {
                    if ( activeBtn )   { activeBtn.disabled = false;  activeBtn.textContent = isMissingMode ? '🔍 Find posts without featured image' : '🖼 Find posts with featured image'; }
                    if ( inactiveBtn ) { inactiveBtn.disabled = false; }
                    if ( ! res.success ) {
                        if ( results ) results.innerHTML = '<p style="color:#c62828">Error: ' + esc( res.data?.message || 'Scan failed.' ) + '</p>';
                        return;
                    }
                    const posts  = res.data?.posts || [];
                    const sortBy = res.data?.sort  || 'newest';
                    const retMode = res.data?.mode || mode;
                    if ( ! posts.length ) {
                        if ( results ) results.innerHTML = retMode === 'with_image'
                            ? '<p style="color:#555;font-size:13px">No posts with a featured image found.</p>'
                            : '<p style="color:#2e7d32;font-size:13px">✓ All posts have a featured image.</p>';
                        return;
                    }
                    renderPostList( posts, sortBy, retMode );
                } )
                .catch( () => {
                    if ( activeBtn )   { activeBtn.disabled = false;  activeBtn.textContent = isMissingMode ? '🔍 Find posts without featured image' : '🖼 Find posts with featured image'; }
                    if ( inactiveBtn ) { inactiveBtn.disabled = false; }
                    if ( results ) results.innerHTML = '<p style="color:#c62828">Request failed.</p>';
                } );
        }

        // ── Scan buttons ──────────────────────────────────────────────────
        scanBtn.addEventListener( 'click', () => doScan( 'missing' ) );
        if ( withImgBtn ) withImgBtn.addEventListener( 'click', () => doScan( 'with_image' ) );

        // ── System prompt save / reset ────────────────────────────────────
        const saveSyspromptBtn  = document.getElementById( 'cs-ai-img-save-sysprompt' );
        const resetSyspromptBtn = document.getElementById( 'cs-ai-img-reset-sysprompt' );
        const syspromptStatus   = document.getElementById( 'cs-ai-img-sysprompt-status' );
        const syspromptEl       = document.getElementById( 'cs-ai-img-system-prompt' );

        if ( saveSyspromptBtn ) {
            saveSyspromptBtn.addEventListener( 'click', () => {
                const text = syspromptEl?.value || '';
                saveSyspromptBtn.disabled = true;
                post( 'csdt_devtools_ai_image_save_sysprompt', { system_prompt: text } )
                    .then( res => {
                        saveSyspromptBtn.disabled = false;
                        if ( syspromptStatus ) {
                            syspromptStatus.innerHTML = res.success
                                ? '<span style="color:#2e7d32">✓ Saved</span>'
                                : '<span style="color:#c62828">✗ ' + esc( res.data?.message || 'Error' ) + '</span>';
                            setTimeout( () => { if ( syspromptStatus ) syspromptStatus.innerHTML = ''; }, 3000 );
                        }
                    } ).catch( () => {
                        saveSyspromptBtn.disabled = false;
                        if ( syspromptStatus ) syspromptStatus.innerHTML = '<span style="color:#c62828">✗ Error</span>';
                    } );
            } );
        }

        if ( resetSyspromptBtn ) {
            resetSyspromptBtn.addEventListener( 'click', () => {
                if ( ! confirm( 'Reset to the default system prompt?' ) ) return;
                post( 'csdt_devtools_ai_image_save_sysprompt', { system_prompt: '' } )
                    .then( res => {
                        if ( res.success ) {
                            if ( syspromptEl ) syspromptEl.value = window.csdtImgDefaultSysprompt || '';
                            if ( syspromptStatus ) {
                                syspromptStatus.innerHTML = '<span style="color:#2e7d32">✓ Reset to default</span>';
                                setTimeout( () => { if ( syspromptStatus ) syspromptStatus.innerHTML = ''; }, 3000 );
                            }
                        }
                    } );
            } );
        }

        // ── Render post list ──────────────────────────────────────────────
        function renderPostList( posts, sortBy, mode ) {
            if ( ! results ) return;
            const sortLabel = sortBy === 'popular' ? 'by popularity' : sortBy === 'oldest' ? 'oldest first' : sortBy === 'longest' ? 'longest first' : sortBy === 'img_date' ? 'by image date' : 'newest first';
            const headerText = mode === 'with_image'
                ? `Found <strong>${esc(String(posts.length))}</strong> post(s) with a featured image <span style="color:#94a3b8">(${esc(sortLabel)})</span> — click Regenerate to replace`
                : `Found <strong>${esc(String(posts.length))}</strong> post(s) without a featured image <span style="color:#94a3b8">(${esc(sortLabel)})</span>`;
            let html = `<p style="font-size:13px;font-weight:600;color:#1e293b;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;padding:8px 12px;margin-bottom:10px">${headerText}</p>`;
            html += '<div style="display:flex;flex-direction:column;gap:8px">';
            for ( const p of posts ) {
                const viewCount  = ( p.view_count !== null && p.view_count !== undefined ) ? p.view_count : 0;
                const wordCount  = ( p.word_count !== null && p.word_count !== undefined ) ? p.word_count : 0;
                const viewsBadge  = `<span style="font-size:11px;color:#94a3b8;margin-left:8px">👁 ${esc(String(viewCount))}</span>`;
                const wordsBadge  = wordCount > 0 ? `<span style="font-size:11px;color:#94a3b8;margin-left:8px">📝 ${esc(wordCount.toLocaleString())} words</span>` : '';
                const imgDateBadge = p.thumb_date ? `<span style="font-size:11px;color:#94a3b8;margin-left:8px">🖼 ${esc(p.thumb_date)}</span>` : '';
                const meta        = `<span style="font-size:11px;color:#94a3b8">${esc(p.date)}</span>${viewsBadge}${wordsBadge}${imgDateBadge}`;
                const btnLabel   = p.has_thumb ? '↺ Regenerate' : '✨ Generate';
                const existingThumb = p.has_thumb && p.thumb_url
                    ? `<img src="${esc(p.thumb_url)}" style="width:80px;height:42px;object-fit:cover;border-radius:3px;border:1px solid #ddd">`
                    : '';
                html += `
                <div id="cs-ai-row-${esc(String(p.post_id))}" style="display:flex;align-items:stretch;gap:0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;overflow:hidden">
                    <div style="flex:1;padding:10px 12px;min-width:0">
                        <a href="${esc(p.post_url)}" target="_blank" style="font-size:13px;font-weight:600;color:#1565c0;text-decoration:none;display:block;margin-bottom:2px">${esc(decodeHtml(p.title))}</a>
                        ${meta}
                        <span id="cs-ai-status-${esc(String(p.post_id))}" style="display:block;font-size:11px;color:#555;word-break:break-word;margin-top:4px"></span>
                    </div>
                    <div id="cs-ai-thumb-${esc(String(p.post_id))}" style="flex-shrink:0;align-self:center;padding:0 6px">${existingThumb}</div>
                    <button type="button" class="cs-btn-primary cs-ai-gen-btn" data-post-id="${esc(String(p.post_id))}"
                            style="font-size:12px;padding:0 14px;white-space:nowrap;border-radius:0;flex-shrink:0;min-width:90px">
                        ${btnLabel}
                    </button>
                </div>`;
            }
            html += '</div>';
            results.style.display = 'block';
            results.innerHTML     = html;

            // Attach click handlers to generate buttons.
            results.querySelectorAll( '.cs-ai-gen-btn' ).forEach( btn => {
                btn.addEventListener( 'click', () => {
                    if ( typeof triggerGenerate === 'function' ) triggerGenerate( btn );
                } );
            } );
        }

        // ── Image preview modal ───────────────────────────────────────────
        imageModal = ( () => {
            const overlay = document.createElement( 'div' );
            overlay.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.78);z-index:99999;overflow-y:auto;padding:32px 16px;box-sizing:border-box';
            const box = document.createElement( 'div' );
            box.style.cssText = 'background:#fff;border-radius:10px;max-width:740px;margin:0 auto;box-shadow:0 16px 56px rgba(0,0,0,.5);overflow:hidden';
            overlay.appendChild( box );
            document.body.appendChild( overlay );
            let _cb = {};
            overlay.addEventListener( 'click', e => { if ( e.target === overlay ) _cancel(); } );
            document.addEventListener( 'keydown', e => { if ( e.key === 'Escape' && overlay.style.display !== 'none' ) _cancel(); } );
            function _cancel() { overlay.style.display = 'none'; if ( _cb.onCancel ) _cb.onCancel(); }
            function open( options, callbacks, dallePrompt ) {
                _cb = callbacks || {};
                const isMult = options.length > 1;
                const titleText = isMult
                    ? `Choose a Featured Image (${options.length} options)`
                    : 'Generated Featured Image';
                const imagesHtml = isMult
                    ? `<div style="display:flex;gap:16px;flex-wrap:wrap;justify-content:center;padding:24px 20px 12px">` +
                      options.map( ( opt, i ) =>
                        `<div style="flex:1;min-width:220px;max-width:320px;text-align:center">
                            <img src="${esc(opt.thumb_url)}" style="width:100%;max-height:210px;object-fit:cover;border-radius:6px;border:2px solid #e2e8f0;display:block">
                            <button type="button" class="cs-modal-pick-btn"
                                data-attach-id="${esc(String(opt.attach_id))}"
                                style="margin-top:10px;background:#1565c0;color:#fff;border:none;border-radius:5px;padding:8px 0;font-size:13px;font-weight:600;cursor:pointer;width:100%">
                                ✓ Use Option ${i + 1}
                            </button>
                        </div>` ).join( '' ) +
                      `</div>`
                    : `<div style="padding:24px 20px 12px;text-align:center">
                          <a href="${esc(options[0].full_url || options[0].thumb_url)}" target="_blank" rel="noopener" title="Tap to view full size (1200×675)">
                              <img src="${esc(options[0].thumb_url)}?v=${Date.now()}" style="max-width:100%;border-radius:6px;border:1px solid #e2e8f0;object-fit:contain;box-shadow:0 4px 24px rgba(0,0,0,.15);cursor:zoom-in;display:block">
                          </a>
                          <p style="margin:6px 0 0;font-size:11px;color:#94a3b8">Tap image to view full size (1200×675)</p>
                       </div>`;
                const promptHtml = dallePrompt
                    ? `<div style="margin:0 20px 14px">
                           <p style="margin:0 0 4px;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.5px">Image prompt used</p>
                           <p style="margin:0;padding:10px;background:#f1f5f9;border-radius:4px;font-family:monospace;font-size:11px;line-height:1.6;white-space:pre-wrap;color:#334155;border:1px solid #e2e8f0">${esc(dallePrompt)}</p>
                       </div>`
                    : '';
                const footerBtns = isMult
                    ? `<button type="button" class="cs-modal-regen"  style="background:#6366f1;color:#fff;border:none;border-radius:5px;padding:8px 18px;font-size:13px;font-weight:600;cursor:pointer">↺ Regenerate both</button>
                       <button type="button" class="cs-modal-cancel" style="background:#fff;color:#64748b;border:1px solid #cbd5e1;border-radius:5px;padding:8px 18px;font-size:13px;cursor:pointer">✕ Cancel</button>`
                    : `<button type="button" class="cs-modal-accept" style="background:#16a34a;color:#fff;border:none;border-radius:5px;padding:8px 22px;font-size:13px;font-weight:600;cursor:pointer">✓ Accept</button>
                       <button type="button" class="cs-modal-regen"  style="background:#6366f1;color:#fff;border:none;border-radius:5px;padding:8px 18px;font-size:13px;font-weight:600;cursor:pointer">↺ Regenerate</button>
                       <button type="button" class="cs-modal-cancel" style="background:#fff;color:#64748b;border:1px solid #cbd5e1;border-radius:5px;padding:8px 18px;font-size:13px;cursor:pointer">✕ Cancel</button>`;
                box.innerHTML = `
                    <div style="background:#1d2327;color:#fff;padding:14px 20px;display:flex;align-items:center;justify-content:space-between">
                        <strong style="font-size:14px">🖼 ${esc(titleText)}</strong>
                        <button class="cs-modal-close-x" style="background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;padding:0 4px">&times;</button>
                    </div>
                    ${imagesHtml}
                    ${promptHtml}
                    <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center;padding:12px 20px;border-top:1px solid #e2e8f0;background:#f8fafc">
                        ${footerBtns}
                    </div>`;
                overlay.style.display = 'block';
                box.querySelector( '.cs-modal-close-x' )?.addEventListener( 'click', _cancel );
                box.querySelector( '.cs-modal-cancel' )?.addEventListener( 'click', _cancel );
                box.querySelector( '.cs-modal-accept' )?.addEventListener( 'click', () => {
                    overlay.style.display = 'none';
                    if ( _cb.onAccept ) _cb.onAccept( options[0].attach_id );
                } );
                box.querySelector( '.cs-modal-regen' )?.addEventListener( 'click', () => {
                    overlay.style.display = 'none';
                    if ( _cb.onRegenerate ) _cb.onRegenerate();
                } );
                box.querySelectorAll( '.cs-modal-pick-btn' ).forEach( btn => {
                    btn.addEventListener( 'click', () => {
                        overlay.style.display = 'none';
                        if ( _cb.onAccept ) _cb.onAccept( btn.dataset.attachId );
                    } );
                } );
            }
            return { open };
        } )();

        // ── Prompt review modal (step 1 of 2) ────────────────────────────
        promptReviewModal = ( () => {
            const overlay = document.createElement( 'div' );
            overlay.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.78);z-index:100000;overflow-y:auto;padding:32px 16px;box-sizing:border-box';
            const box = document.createElement( 'div' );
            box.style.cssText = 'background:#fff;border-radius:10px;max-width:640px;margin:0 auto;box-shadow:0 16px 56px rgba(0,0,0,.5);overflow:hidden';
            overlay.appendChild( box );
            document.body.appendChild( overlay );
            let _cb = {};
            overlay.addEventListener( 'click', e => { if ( e.target === overlay ) _close(); } );
            document.addEventListener( 'keydown', e => { if ( e.key === 'Escape' && overlay.style.display !== 'none' ) _close(); } );
            function _close() { overlay.style.display = 'none'; }
            function open( promptText, callbacks ) {
                _cb = callbacks || {};
                box.innerHTML = `
                    <div style="background:#0d7377;color:#fff;padding:14px 20px;display:flex;align-items:center;justify-content:space-between">
                        <strong style="font-size:14px">✏ Review Image Prompt</strong>
                        <button class="cs-prm-close" style="background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;padding:0 4px">&times;</button>
                    </div>
                    <div style="padding:20px">
                        <p style="margin:0 0 10px;font-size:13px;color:#555">The AI wrote this prompt based on your post content and system instructions. Edit it freely before generating.</p>
                        <textarea id="cs-prompt-review-text" rows="8"
                            style="width:100%;box-sizing:border-box;font-family:monospace;font-size:12px;line-height:1.6;border:1px solid #cbd5e1;border-radius:6px;padding:10px;resize:vertical;color:#334155">${esc( promptText )}</textarea>
                    </div>
                    <div style="display:flex;gap:8px;justify-content:flex-end;padding:12px 20px;border-top:1px solid #e2e8f0;background:#f8fafc">
                        <button type="button" class="cs-prm-gen" style="background:#16a34a;color:#fff;border:none;border-radius:5px;padding:8px 22px;font-size:13px;font-weight:600;cursor:pointer">🎨 Generate Image</button>
                        <button type="button" class="cs-prm-cancel" style="background:#fff;color:#64748b;border:1px solid #cbd5e1;border-radius:5px;padding:8px 18px;font-size:13px;cursor:pointer">✕ Cancel</button>
                    </div>`;
                overlay.style.display = 'block';
                box.querySelector( '.cs-prm-close' )?.addEventListener( 'click', () => { _close(); if ( _cb.onCancel ) _cb.onCancel(); } );
                box.querySelector( '.cs-prm-cancel' )?.addEventListener( 'click', () => { _close(); if ( _cb.onCancel ) _cb.onCancel(); } );
                box.querySelector( '.cs-prm-gen' )?.addEventListener( 'click', () => {
                    const edited = document.getElementById( 'cs-prompt-review-text' )?.value || promptText;
                    _close();
                    if ( _cb.onGenerate ) _cb.onGenerate( edited );
                } );
            }
            return { open };
        } )();

    }   // end init()

    function makeImageModal() {
        const overlay = document.createElement( 'div' );
        overlay.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.78);z-index:99999;overflow-y:auto;padding:32px 16px;box-sizing:border-box';
        const box = document.createElement( 'div' );
        box.style.cssText = 'background:#fff;border-radius:10px;max-width:740px;margin:0 auto;box-shadow:0 16px 56px rgba(0,0,0,.5);overflow:hidden';
        overlay.appendChild( box );
        document.body.appendChild( overlay );
        let _cb = {};
        overlay.addEventListener( 'click', e => { if ( e.target === overlay ) _cancel(); } );
        document.addEventListener( 'keydown', e => { if ( e.key === 'Escape' && overlay.style.display !== 'none' ) _cancel(); } );
        function _cancel() { overlay.style.display = 'none'; if ( _cb.onCancel ) _cb.onCancel(); }
        function open( options, callbacks ) {
            _cb = callbacks || {};
            overlay.style.display = 'block';
            box.innerHTML = '<div style="padding:24px">' + options.map( ( opt, i ) =>
                '<div style="margin-bottom:12px">' +
                '<img src="' + esc( opt.thumb_url ) + '" style="max-width:100%;border-radius:6px;display:block;margin-bottom:8px">' +
                '<button class="cs-modal-pick-btn" data-attach-id="' + esc( String( opt.attach_id ) ) + '" style="background:#1565c0;color:#fff;border:none;border-radius:5px;padding:8px 18px;font-size:13px;font-weight:600;cursor:pointer">✓ Use this image</button>' +
                '</div>'
            ).join( '' ) +
            '<div style="margin-top:12px;display:flex;gap:8px">' +
            '<button class="cs-modal-regen-btn" style="background:#fff;border:1px solid #cbd5e1;border-radius:5px;padding:8px 18px;font-size:13px;cursor:pointer">↺ Regenerate</button>' +
            '<button class="cs-modal-cancel-btn" style="background:#fff;border:1px solid #cbd5e1;border-radius:5px;padding:8px 18px;font-size:13px;cursor:pointer">✕ Cancel</button>' +
            '</div></div>';
            box.querySelectorAll( '.cs-modal-pick-btn' ).forEach( b => {
                b.addEventListener( 'click', () => { overlay.style.display = 'none'; if ( _cb.onAccept ) _cb.onAccept( b.dataset.attachId ); } );
            } );
            box.querySelector( '.cs-modal-regen-btn' )?.addEventListener( 'click', () => { overlay.style.display = 'none'; if ( _cb.onRegenerate ) _cb.onRegenerate(); } );
            box.querySelector( '.cs-modal-cancel-btn' )?.addEventListener( 'click', () => _cancel() );
        }
        return { open };
    }

    function triggerGenerate( btn, forceVary = false ) {
        // Lazily initialise modals if init() didn't run (e.g. called from scan panel).
        if ( ! imageModal ) imageModal = makeImageModal();

            const postId       = btn.dataset.postId;
            const statusEl     = document.getElementById( 'cs-ai-status-' + postId );
            const thumbEl      = document.getElementById( 'cs-ai-thumb-'  + postId );
            const quality      = document.getElementById( 'cs-ai-img-quality' )?.value || 'standard';
            const promptVendor = curVendor  || 'openai';
            const promptModel  = ( modelEl?.value || curModel || 'gpt-4o-mini' );
            const promptStyle  = styleEl?.value || 'auto';
            const noText       = document.getElementById( 'cs-ai-img-no-text' )?.checked ? '1' : '0';

            btn.disabled    = true;
            btn.textContent = '⏳ Writing prompt…';
            if ( statusEl ) statusEl.textContent = '';
            if ( thumbEl )  { thumbEl.innerHTML = ''; thumbEl.style.width = ''; }

            // Step 1 — ask AI to write the image prompt.
            post( 'csdt_devtools_ai_image_write_prompt', { post_id: postId, prompt_vendor: promptVendor, prompt_model: promptModel, prompt_style: promptStyle, no_text: noText, force_vary: forceVary ? '1' : '0' } )
                .then( res => {
                    btn.disabled    = false;
                    btn.textContent = '✨ Generate';
                    if ( ! res.success ) {
                        if ( statusEl ) statusEl.innerHTML = '<span style="color:#c62828;font-size:11px">✗ ' + esc( res.data?.message || 'Failed to write prompt' ) + '</span>';
                        return;
                    }
                    const writtenPrompt = res.data?.prompt || '';

                    // Step 2 — start async job, then poll until done.
                    ( ( editedPrompt ) => {
                            btn.disabled    = true;
                            btn.textContent = '⏳ Generating…';
                            if ( statusEl ) statusEl.innerHTML = '<span style="color:#94a3b8;font-size:11px">⏳ Generating…</span>';

                            post( 'csdt_devtools_ai_image_generate', { post_id: postId, quality, prompt_vendor: promptVendor, prompt_model: promptModel, prompt: editedPrompt, no_text: noText } )
                                .then( startRes => {
                                    if ( ! startRes.success ) {
                                        btn.disabled    = false;
                                        btn.textContent = '✨ Generate';
                                        if ( statusEl ) statusEl.innerHTML = '<span style="color:#c62828;font-size:11px">✗ ' + esc( startRes.data?.message || 'Failed to start' ) + '</span>';
                                        return;
                                    }
                                    const jobId = startRes.data.job_id;
                                    let elapsed = 0;
                                    const MAX_POLL_S = 300;
                                    const showErr = ( msg ) => {
                                        btn.disabled    = false;
                                        btn.textContent = '✨ Generate';
                                        if ( statusEl ) statusEl.innerHTML = '<span style="color:#c62828;font-size:11px" title="' + esc( msg ) + '">✗ ' + esc( msg ) + '</span>';
                                    };
                                    const pollTimer = setInterval( () => {
                                        elapsed += 4;
                                        if ( elapsed > MAX_POLL_S ) {
                                            clearInterval( pollTimer );
                                            showErr( 'Timed out after 5 min — check server error logs' );
                                            return;
                                        }
                                        const m = Math.floor( elapsed / 60 ), s = elapsed % 60;
                                        const t = m > 0 ? `${m}m ${s}s` : `${s}s`;
                                        if ( statusEl ) statusEl.innerHTML = `<span style="color:#94a3b8;font-size:11px">⏳ Generating… (${t})</span>`;
                                        post( 'csdt_devtools_ai_image_poll', { job_id: jobId } )
                                            .then( pollRes => {
                                                const st = pollRes.data?.status;
                                                if ( st === 'pending' || st === 'processing' ) return;
                                                clearInterval( pollTimer );
                                                btn.disabled = false;
                                                if ( ! pollRes.success ) {
                                                    showErr( st === 'expired' ? 'Job expired — server may have timed out' : ( pollRes.data?.error || pollRes.data?.message || 'Request failed' ) );
                                                    return;
                                                }
                                                if ( st === 'error' ) {
                                                    showErr( pollRes.data?.error || 'Generation failed (no error detail — check PHP error log)' );
                                                    return;
                                                }
                                                const genRes = { success: true, data: pollRes.data.result };
                                                handleGenResult( genRes );
                                            } )
                                            .catch( () => {} ); // swallow poll network errors — keep polling
                                    }, 4000 );
                                } );

                            function handleGenResult( genRes ) {
                                btn.disabled = false;
                                if ( ! genRes.success ) {
                                    btn.textContent = '✨ Generate';
                                    if ( statusEl ) statusEl.innerHTML = '<span style="color:#c62828;font-size:11px">✗ ' + esc( genRes.data?.message || 'Failed' ) + '</span>';
                                    return;
                                }
                                    const options     = genRes.data.options || [];
                                    const dallePrompt = genRes.data.prompt  || editedPrompt;
                                    if ( ! options.length ) {
                                        btn.textContent = '✨ Generate';
                                        if ( statusEl ) statusEl.innerHTML = '<span style="color:#c62828">✗ No images returned</span>';
                                        return;
                                    }
                                    btn.textContent = '↺ Regenerate';
                                    const allIds = options.map( o => String( o.attach_id ) );

                                    function doAccept( chosenId ) {
                                        const discard = allIds.filter( id => id !== String( chosenId ) ).join( ',' );
                                        if ( statusEl ) statusEl.innerHTML = '<span style="color:#94a3b8;font-size:11px">⏳ Setting…</span>';
                                        post( 'csdt_devtools_ai_image_pick', { post_id: postId, attach_id: chosenId, discard } )
                                            .then( pickRes => {
                                                if ( pickRes.success ) {
                                                    if ( thumbEl ) {
                                                        thumbEl.style.width = '80px';
                                                        thumbEl.innerHTML = `<img src="${esc(pickRes.data.thumb_url)}" style="width:80px;height:42px;object-fit:cover;border-radius:3px;border:1px solid #ddd;cursor:pointer" title="Click to view larger" class="cs-ai-thumb-preview" data-full-url="${esc(pickRes.data.thumb_url)}">`;
                                                        thumbEl.querySelector( '.cs-ai-thumb-preview' )?.addEventListener( 'click', function () {
                                                            imageModal.open( [ { thumb_url: this.dataset.fullUrl, attach_id: chosenId } ], {
                                                                onAccept: () => {},
                                                                onRegenerate: () => { btn.textContent = '✨ Generate'; triggerGenerate( btn, true ); },
                                                                onCancel: () => {},
                                                            }, dallePrompt );
                                                        } );
                                                    }
                                                    if ( statusEl ) statusEl.innerHTML = '<span style="color:#2e7d32">✓ Set</span>';
                                                }
                                            } );
                                    }

                                    function doDiscard() {
                                        post( 'csdt_devtools_ai_image_discard', { attach_ids: allIds.join( ',' ) } ).catch( () => {} );
                                    }

                                    imageModal.open( options, {
                                        onAccept:     ( chosenId ) => doAccept( chosenId ),
                                        onRegenerate: () => { doDiscard(); btn.textContent = '✨ Generate'; triggerGenerate( btn, true ); },
                                        onCancel:     () => { doDiscard(); btn.textContent = '✨ Generate'; if ( statusEl ) statusEl.textContent = ''; },
                                    }, dallePrompt );
                            } // end handleGenResult
                    } )( writtenPrompt );
                } )
                .catch( e => {
                    btn.disabled    = false;
                    btn.textContent = '✨ Generate';
                    const netMsg = ( e?.message || '' ).toLowerCase().includes( 'load' ) || ( e?.message || '' ).toLowerCase().includes( 'network' ) || ( e?.message || '' ).toLowerCase().includes( 'fetch' )
                        ? 'Timed out — try again on WiFi'
                        : ( e?.message || 'Request failed' );
                    if ( statusEl ) statusEl.innerHTML = '<span style="color:#c62828;font-size:11px" title="' + esc( e?.message || '' ) + '">✗ ' + esc( netMsg ) + '</span>';
                } );
    }

    function initAndExpose() {
        // Expose triggerGenerate BEFORE calling init() so the scan panel's
        // "AI Replace" button works even if init() returns early (e.g. the
        // AI panel section hasn't been scrolled into view yet).
        if ( window.csdtDevtoolsThumbs && typeof triggerGenerate === 'function' ) {
            window.csdtDevtoolsThumbs.triggerGenerate = triggerGenerate;
        }
        init();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', initAndExpose );
    } else {
        initAndExpose();
    }
} )();
