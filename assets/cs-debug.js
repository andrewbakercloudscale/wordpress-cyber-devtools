( function () {
    'use strict';

    var loadBtns      = document.querySelectorAll( '.cs-debug-load-btn' );
    var loadStatus    = document.getElementById( 'csdt-debug-load-status' );
    var logLines      = document.getElementById( 'csdt-debug-log-lines' );
    var inputArea     = document.getElementById( 'csdt-debug-input' );
    var analyzeBtn    = document.getElementById( 'csdt-debug-analyze' );
    var analyzeStatus = document.getElementById( 'csdt-debug-analyze-status' );
    var resultDiv     = document.getElementById( 'csdt-debug-result' );

    var cfg = window.csdtDebug || {};

    function esc( s ) {
        return String( s )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

    function post( action, data, nonce ) {
        var fd = new FormData();
        fd.append( 'action', action );
        fd.append( 'nonce', nonce );
        Object.keys( data ).forEach( function ( k ) { fd.append( k, data[ k ] ); } );
        return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } )
            .then( function ( r ) { return r.json(); } );
    }

    if ( analyzeBtn && inputArea ) {

    function isErrorLine( line ) {
        var t = line.toLowerCase();
        return /\b(fatal|error|critical|uncaught|exception|warning|deprecated)\b/.test( t );
    }

    function loadSource( source ) {
        if ( loadStatus ) { loadStatus.textContent = 'Loading\u2026'; }
        if ( logLines ) { logLines.style.display = 'none'; logLines.innerHTML = ''; }

        post( 'csdt_devtools_server_logs_fetch', { source: source, lines: 200 }, cfg.logsNonce )
            .then( function ( res ) {
                if ( ! res.success ) {
                    if ( loadStatus ) { loadStatus.textContent = 'Log not available.'; }
                    return;
                }
                var d = res.data;
                if ( d.status === 'not_found' ) {
                    if ( loadStatus ) { loadStatus.textContent = 'Log file not found: ' + d.path; }
                    return;
                }
                if ( d.status === 'permission_denied' ) {
                    if ( loadStatus ) { loadStatus.textContent = 'Permission denied: ' + d.path; }
                    return;
                }
                if ( d.status === 'empty' || ! d.lines || ! d.lines.length ) {
                    if ( loadStatus ) { loadStatus.textContent = 'Log is empty.'; }
                    return;
                }

                var errors = d.lines.filter( isErrorLine );
                if ( ! errors.length ) {
                    if ( loadStatus ) { loadStatus.textContent = 'No errors in the last ' + d.count + ' lines \u2014 log looks clean.'; }
                    return;
                }

                if ( loadStatus ) {
                    loadStatus.textContent = errors.length + ' error' + ( errors.length === 1 ? '' : 's' ) + ' found. Click a line to analyze.';
                }

                var recent = errors.slice( -60 );
                logLines.innerHTML = recent.map( function ( line ) {
                    return '<div class="csdt-debug-line" data-line="' + esc( line ) + '" '
                         + 'style="padding:6px 12px;border-bottom:1px solid #e2e8f0;cursor:pointer;'
                         + 'font-size:.78em;font-family:monospace;color:#dc2626;white-space:nowrap;'
                         + 'overflow:hidden;text-overflow:ellipsis;" title="' + esc( line ) + '">'
                         + esc( line )
                         + '</div>';
                } ).join( '' );
                logLines.style.display = 'block';
            } )
            .catch( function () {
                if ( loadStatus ) { loadStatus.textContent = 'Failed to fetch log.'; }
            } );
    }

    loadBtns.forEach( function ( btn ) {
        btn.addEventListener( 'click', function () { loadSource( btn.dataset.source ); } );
    } );

    if ( logLines ) {
        logLines.addEventListener( 'click', function ( e ) {
            var row = e.target.closest( '.csdt-debug-line' );
            if ( ! row ) { return; }
            inputArea.value = row.dataset.line;
            inputArea.focus();
            // Highlight selected
            logLines.querySelectorAll( '.csdt-debug-line' ).forEach( function ( r ) {
                r.style.background = '';
            } );
            row.style.background = '#1e3a5f';
        } );
    }

    function renderResult( text ) {
        if ( ! text ) { return '<p style="color:#94a3b8;">No analysis returned.</p>'; }

        var sections = [
            { key: 'Root Cause',     icon: '&#x1F534;', color: '#dc2626' },
            { key: 'Why It Happens', icon: '&#x1F7E1;', color: '#d97706' },
            { key: 'How to Fix It',  icon: '&#x1F7E2;', color: '#16a34a' },
        ];

        var html = text;

        // Replace section headers with styled divs (handle **Header** or Header:)
        sections.forEach( function ( s ) {
            html = html.replace(
                new RegExp( '\\*\\*' + s.key + '\\*\\*:?|' + s.key + ':', 'g' ),
                '<div style="color:' + s.color + ';font-weight:700;font-size:1em;margin:20px 0 6px;">'
                + s.icon + ' ' + s.key + '</div>'
            );
        } );

        // Inline code
        html = html.replace( /`([^`\n]+)`/g, '<code style="background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:.9em;color:#1e293b;">$1</code>' );
        // Bold
        html = html.replace( /\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>' );
        // Numbered list items
        html = html.replace( /^(\d+)\.\s+(.+)$/gm, '<div style="margin:4px 0 4px 8px;"><strong>$1.</strong> $2</div>' );
        // Paragraph breaks
        html = html.replace( /\n{2,}/g, '</p><p style="margin:8px 0;">' );
        html = html.replace( /\n/g, '<br>' );

        return '<div style="background:#f8fafc;border:1px solid #d1d5db;border-radius:8px;padding:22px 26px;color:#1e293b;font-size:.9em;line-height:1.75;">'
             + '<p style="margin:0 0 8px;">' + html + '</p>'
             + '</div>';
    }

    analyzeBtn.addEventListener( 'click', function () {
        var input = inputArea.value.trim();
        if ( ! input ) {
            if ( analyzeStatus ) { analyzeStatus.textContent = 'Paste an error or load from logs first.'; }
            return;
        }
        analyzeBtn.disabled = true;
        if ( analyzeStatus ) { analyzeStatus.textContent = '\u23F3 Analyzing\u2026'; }
        resultDiv.style.display = 'none';

        post( 'csdt_ai_debug_log', { input: input }, cfg.aiNonce )
            .then( function ( res ) {
                analyzeBtn.disabled = false;
                if ( analyzeStatus ) { analyzeStatus.textContent = ''; }
                if ( ! res.success ) {
                    resultDiv.innerHTML = '<p style="color:#f87171;">' + esc( ( res.data && res.data.message ) || 'Analysis failed.' ) + '</p>';
                } else {
                    resultDiv.innerHTML = renderResult( res.data.analysis || '' );
                }
                resultDiv.style.display = 'block';
                resultDiv.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
            } )
            .catch( function () {
                analyzeBtn.disabled = false;
                if ( analyzeStatus ) { analyzeStatus.textContent = 'Request failed \u2014 check your connection.'; }
            } );
    } );

    // Auto-load PHP error log on tab open if sources are available
    var sources = cfg.sources || {};
    if ( sources.php_error ) {
        loadSource( 'php_error' );
    }

    } // end if ( analyzeBtn && inputArea )

    // PHP-FPM Saturation Monitor — workers refresh
    var fpmWorkersRefresh = document.getElementById( 'csdt-fpm-workers-refresh' );
    var fpmWorkersStatus  = document.getElementById( 'csdt-fpm-workers-status' );

    function refreshFpmWorkers() {
        if ( fpmWorkersRefresh ) { fpmWorkersRefresh.disabled = true; }
        if ( fpmWorkersStatus ) { fpmWorkersStatus.textContent = '\u23F3'; }
        post( 'csdt_fpm_worker_status', {}, cfg.fpmNonce )
            .then( function ( res ) {
                if ( fpmWorkersRefresh ) { fpmWorkersRefresh.disabled = false; }
                if ( ! res.success ) {
                    if ( fpmWorkersStatus ) { fpmWorkersStatus.textContent = esc( ( res.data && res.data.message ) || 'Error' ); }
                    return;
                }
                var d = res.data || {};
                var set = function ( id, val ) {
                    var el = document.getElementById( id );
                    if ( el ) { el.textContent = val !== null && val !== undefined ? String( val ) : '\u2014'; }
                };
                set( 'csdt-fpm-w-active', d.active );
                set( 'csdt-fpm-w-idle',   d.idle );
                set( 'csdt-fpm-w-total',  d.total );
                if ( fpmWorkersStatus ) { fpmWorkersStatus.textContent = ''; }
            } )
            .catch( function () {
                if ( fpmWorkersRefresh ) { fpmWorkersRefresh.disabled = false; }
                if ( fpmWorkersStatus ) { fpmWorkersStatus.textContent = 'Request failed.'; }
            } );
    }

    if ( fpmWorkersRefresh ) {
        fpmWorkersRefresh.addEventListener( 'click', function () {
            refreshFpmWorkers();
            if ( fpmDetailOpen ) { loadFpmDetail(); }
        } );
        refreshFpmWorkers();
    }

    // Per-worker detail table
    var fpmDetailToggle = document.getElementById( 'csdt-fpm-detail-toggle' );
    var fpmDetailPanel  = document.getElementById( 'csdt-fpm-detail-panel' );
    var fpmDetailTbody  = document.getElementById( 'csdt-fpm-detail-tbody' );
    var fpmDetailTfoot  = document.getElementById( 'csdt-fpm-detail-tfoot' );
    var fpmPoolInfo     = document.getElementById( 'csdt-fpm-pool-info' );
    var fpmDetailOpen   = false;

    function fmtBytes( b ) {
        b = parseInt( b, 10 ) || 0;
        if ( b === 0 ) return '—';
        if ( b < 1024 * 1024 ) return ( b / 1024 ).toFixed( 0 ) + ' KB';
        return ( b / 1024 / 1024 ).toFixed( 1 ) + ' MB';
    }

    function fmtSince( secs ) {
        secs = parseInt( secs, 10 ) || 0;
        if ( secs < 60 )   return secs + 's';
        if ( secs < 3600 ) return Math.floor( secs / 60 ) + 'm ' + ( secs % 60 ) + 's';
        return Math.floor( secs / 3600 ) + 'h ' + Math.floor( ( secs % 3600 ) / 60 ) + 'm';
    }

    function stateColour( state ) {
        state = ( state || '' ).toLowerCase();
        if ( state === 'idle' )                     return '#16a34a';
        if ( state === 'running' )                  return '#dc2626';
        if ( state.indexOf( 'reading' ) !== -1 )    return '#d97706';
        if ( state.indexOf( 'sending' ) !== -1 )    return '#2563eb';
        return '#64748b';
    }

    function loadFpmDetail() {
        if ( fpmDetailTbody ) { fpmDetailTbody.innerHTML = '<tr><td colspan="8" style="padding:8px;color:#475569;">Loading\u2026</td></tr>'; }
        post( 'csdt_fpm_worker_detail', {}, cfg.fpmNonce )
            .then( function ( res ) {
                if ( ! res.success || ! fpmDetailTbody ) { return; }
                var d = res.data;
                var workers = d.workers || [];

                if ( fpmPoolInfo ) {
                    fpmPoolInfo.textContent = 'Pool: ' + ( d.pool || '—' )
                        + '  ·  PM: ' + ( d.pm || '—' )
                        + '  ·  Total accepted: ' + ( d.accepted || 0 ).toLocaleString();
                }

                // Active/running workers first, then idle by PID
                workers.sort( function ( a, b ) {
                    var aIdle = ( a.state || '' ).toLowerCase() === 'idle';
                    var bIdle = ( b.state || '' ).toLowerCase() === 'idle';
                    if ( aIdle !== bIdle ) { return aIdle ? 1 : -1; }
                    return ( a.pid || 0 ) - ( b.pid || 0 );
                } );

                if ( ! workers.length ) {
                    fpmDetailTbody.innerHTML = '<tr><td colspan="8" style="padding:8px;color:#475569;">No worker data returned.</td></tr>';
                    return;
                }

                fpmDetailTbody.innerHTML = workers.map( function ( w ) {
                    var stateClr = stateColour( w.state );
                    var uriText  = w.uri || '—';
                    if ( uriText.length > 80 ) { uriText = uriText.slice( 0, 77 ) + '\u2026'; }
                    var rowBg    = w.state && w.state.toLowerCase() !== 'idle' ? 'background:#fff7ed;' : '';
                    var cpuVal   = parseFloat( w.cpu || 0 );
                    var cpuDisp  = cpuVal === 0 ? '—' : cpuVal.toFixed( 1 );
                    var cpuClr   = cpuVal > 50 ? '#d97706' : '#374151';
                    return '<tr style="border-top:1px solid #e2e8f0;' + rowBg + '">'
                        + '<td style="padding:4px 8px;font-family:monospace;color:#374151;">' + esc( String( w.pid ) ) + '</td>'
                        + '<td style="padding:4px 8px;font-weight:600;color:' + stateClr + ';white-space:nowrap;">' + esc( w.state || '—' ) + '</td>'
                        + '<td style="padding:4px 8px;text-align:right;color:#374151;">' + esc( String( w.reqs ) ) + '</td>'
                        + '<td style="padding:4px 8px;white-space:nowrap;color:#374151;">' + esc( fmtSince( w.since ) ) + '</td>'
                        + '<td style="padding:4px 8px;font-family:monospace;font-size:.9em;max-width:480px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc( ( w.method ? w.method + ' ' : '' ) + ( w.uri || '' ) ) + '">'
                        + ( w.method ? '<span style="color:#94a3b8;">' + esc( w.method ) + '</span> ' : '' )
                        + '<span style="color:#374151;">' + esc( uriText ) + '</span></td>'
                        + '<td style="padding:4px 8px;font-family:monospace;font-size:.9em;color:#374151;white-space:nowrap;" title="' + esc( w.script || '' ) + '">' + esc( w.script || '—' ) + '</td>'
                        + '<td style="padding:4px 8px;text-align:right;color:' + cpuClr + ';">' + esc( cpuDisp ) + '</td>'
                        + '<td style="padding:4px 8px;text-align:right;white-space:nowrap;color:#374151;">' + esc( fmtBytes( w.mem ) ) + '</td>'
                        + '</tr>';
                } ).join( '' );

                // Memory total in tfoot and header bar
                var totalBytes = workers.reduce( function ( s, w ) { return s + ( parseInt( w.mem, 10 ) || 0 ); }, 0 );
                var totalMemStr = fmtBytes( totalBytes );
                if ( fpmDetailTfoot ) {
                    fpmDetailTfoot.innerHTML = '<tr style="border-top:2px solid #d1d5db;">'
                        + '<td colspan="7" style="padding:4px 8px;text-align:right;color:#64748b;font-size:.85em;">Total memory</td>'
                        + '<td style="padding:4px 8px;text-align:right;white-space:nowrap;color:#1e293b;font-weight:700;">' + esc( totalMemStr ) + '</td>'
                        + '</tr>';
                }
                var memHeader = document.getElementById( 'csdt-fpm-w-mem' );
                if ( memHeader ) { memHeader.textContent = totalMemStr; }
            } )
            .catch( function () {
                if ( fpmDetailTbody ) { fpmDetailTbody.innerHTML = '<tr><td colspan="8" style="padding:8px;color:#f87171;">Request failed.</td></tr>'; }
            } );
    }

    if ( fpmDetailToggle && fpmDetailPanel ) {
        fpmDetailToggle.addEventListener( 'click', function () {
            fpmDetailOpen = ! fpmDetailOpen;
            fpmDetailPanel.style.display = fpmDetailOpen ? '' : 'none';
            fpmDetailToggle.textContent  = ( fpmDetailOpen ? '\u25B2' : '\u25BC' ) + ' Workers';
            if ( fpmDetailOpen ) { loadFpmDetail(); }
        } );
    }

    // PHP-FPM Setup Wizard
    (function () {
        var modal      = document.getElementById( 'csdt-fpm-setup-modal' );
        var openBtn    = document.getElementById( 'csdt-fpm-setup-btn' );
        var closeBtn   = document.getElementById( 'csdt-fpm-setup-close' );
        if ( ! modal || ! openBtn ) { return; }

        var detectBtn  = document.getElementById( 'csdt-fpm-detect-btn' );
        var detectRes  = document.getElementById( 'csdt-fpm-detect-result' );
        var step1El    = document.getElementById( 'csdt-fpm-step-1' );
        var step2El    = document.getElementById( 'csdt-fpm-step-2' );
        var step3El    = document.getElementById( 'csdt-fpm-step-3' );
        var patchInfo  = document.getElementById( 'csdt-fpm-patch-info' );
        var patchBtn   = document.getElementById( 'csdt-fpm-patch-btn' );
        var patchRes   = document.getElementById( 'csdt-fpm-patch-result' );
        var step2Next  = document.getElementById( 'csdt-fpm-step2-next' );
        var step2Skip  = document.getElementById( 'csdt-fpm-step2-skip' );
        var nginxSnip  = document.getElementById( 'csdt-fpm-nginx-snippet' );
        var nginxCmd   = document.getElementById( 'csdt-fpm-nginx-reload-cmd' );
        var copyNginx  = document.getElementById( 'csdt-fpm-copy-nginx' );
        var copyStatus = document.getElementById( 'csdt-fpm-copy-nginx-status' );
        var testBtn    = document.getElementById( 'csdt-fpm-test-btn' );
        var testRes    = document.getElementById( 'csdt-fpm-test-result' );

        var detected = {};

        function setStep( n ) {
            [ step1El, step2El, step3El ].forEach( function ( el, i ) {
                if ( el ) { el.style.display = ( i + 1 === n ) ? '' : 'none'; }
            } );
            document.querySelectorAll( '.csdt-fpm-step' ).forEach( function ( el ) {
                var s = parseInt( el.dataset.step, 10 );
                el.style.borderBottomColor = s === n ? '#3b82f6' : s < n ? '#22c55e' : '#d1d5db';
                el.style.color = s === n ? '#2563eb' : s < n ? '#16a34a' : '#475569';
            } );
        }

        function statusRow( ok, label, value ) {
            var colour = ok ? '#16a34a' : '#dc2626';
            var icon   = ok ? '✓' : '✗';
            return '<div style="display:flex;gap:8px;padding:4px 0;border-bottom:1px solid #e2e8f0;font-size:12px;">'
                 + '<span style="color:' + colour + ';font-weight:700;width:14px;">' + icon + '</span>'
                 + '<span style="color:#64748b;flex:1;">' + esc( label ) + '</span>'
                 + '<span style="color:#1e293b;font-family:monospace;">' + esc( value ) + '</span>'
                 + '</div>';
        }

        function buildNginxSnippet( fastcgiPass ) {
            return 'location ~ ^/fpm-status$ {\n'
                 + '    access_log off;\n'
                 + '    allow 127.0.0.1;\n'
                 + '    deny all;\n'
                 + '    include fastcgi_params;\n'
                 + '    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n'
                 + '    fastcgi_pass ' + ( fastcgiPass || 'php:9000' ) + ';\n'
                 + '}';
        }

        openBtn.addEventListener( 'click', function () {
            modal.style.display = 'flex';
            setStep( 1 );
            if ( step2El ) step2El.style.display = 'none';
            if ( step3El ) step3El.style.display = 'none';
            if ( detectRes ) detectRes.innerHTML = '';
        } );

        if ( closeBtn ) {
            closeBtn.addEventListener( 'click', function () { modal.style.display = 'none'; } );
        }
        modal.addEventListener( 'click', function ( e ) {
            if ( e.target === modal ) { modal.style.display = 'none'; }
        } );

        if ( detectBtn ) {
            detectBtn.addEventListener( 'click', function () {
                detectBtn.disabled = true;
                if ( detectRes ) { detectRes.innerHTML = '<span style="color:#94a3b8;">⏳ Scanning…</span>'; }
                post( 'csdt_fpm_setup_detect', {}, cfg.fpmNonce )
                    .then( function ( res ) {
                        detectBtn.disabled = false;
                        if ( ! res.success ) {
                            if ( detectRes ) { detectRes.innerHTML = '<span style="color:#f87171;">Detection failed.</span>'; }
                            return;
                        }
                        var d = res.data;
                        detected = d;
                        var html = '';
                        html += statusRow( !! d.www_conf,          'www.conf found',      d.www_conf || 'Not found' );
                        html += statusRow( d.www_conf_writable,    'www.conf writable',   d.www_conf_writable ? 'Yes' : 'No' );
                        html += statusRow( d.status_path_set,      'pm.status_path set',  d.status_path_set ? 'Yes' : 'No' );
                        html += statusRow( !! d.nginx_url,         'nginx URL detected',  d.nginx_url || 'Not found' );
                        html += statusRow( d.fpm_status_works,     '/fpm-status works',   d.fpm_status_works ? 'Yes — already live!' : 'No' );
                        if ( detectRes ) { detectRes.innerHTML = html; }

                        if ( d.fpm_status_works ) {
                            if ( detectRes ) {
                                detectRes.innerHTML += '<div style="margin-top:12px;padding:10px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;color:#16a34a;font-size:12px;">✅ /fpm-status is already working! Closing in 3s…</div>';
                            }
                            setTimeout( function () {
                                modal.style.display = 'none';
                                refreshFpmWorkers();
                            }, 3000 );
                            return;
                        }

                        setTimeout( function () { setStep( 2 ); }, 800 );
                    } )
                    .catch( function () {
                        detectBtn.disabled = false;
                        if ( detectRes ) { detectRes.innerHTML = '<span style="color:#f87171;">Request failed.</span>'; }
                    } );
            } );
        }

        function goToStep3() {
            var snip = buildNginxSnippet( detected.fastcgi_pass );
            if ( nginxSnip ) { nginxSnip.textContent = snip; }
            var nginxUrl = detected.nginx_url || '';
            var containerName = ( document.getElementById( 'csdt-fpm-wp-container' ) || {} ).value || 'pi_wordpress';
            if ( nginxCmd ) {
                nginxCmd.textContent = 'docker exec ' + containerName + ' nginx -s reload';
            }
            setStep( 3 );
        }

        if ( step2El ) {
            step2El.addEventListener( 'csdt:show', function () {
                if ( patchInfo ) {
                    patchInfo.innerHTML = detected.www_conf
                        ? '<span style="color:#374151;">Found: <code style="color:#16a34a;">' + esc( detected.www_conf ) + '</code>'
                          + ( detected.www_conf_writable ? '' : ' <span style="color:#f87171;">(not writable — may fail)</span>' ) + '</span>'
                        : '<span style="color:#f87171;">www.conf not found at standard paths. You may need to patch manually.</span>';
                }
            } );
        }

        function showStep2() {
            if ( step2El ) {
                setStep( 2 );
                step2El.dispatchEvent( new Event( 'csdt:show' ) );
            }
        }

        // Override setStep to fire show event
        var _origSetStep = setStep;
        setStep = function ( n ) {
            _origSetStep( n );
            if ( n === 2 && step2El ) { step2El.dispatchEvent( new Event( 'csdt:show' ) ); }
        };

        if ( patchBtn ) {
            patchBtn.addEventListener( 'click', function () {
                patchBtn.disabled = true;
                if ( patchRes ) { patchRes.innerHTML = '<span style="color:#94a3b8;">⏳ Patching…</span>'; }
                post( 'csdt_fpm_setup_patch', {
                    www_conf:  detected.www_conf || '',
                    nginx_url: detected.nginx_url || '',
                }, cfg.fpmNonce )
                    .then( function ( res ) {
                        patchBtn.disabled = false;
                        if ( ! res.success ) {
                            if ( patchRes ) {
                                patchRes.innerHTML = '<span style="color:#f87171;">✗ ' + esc( ( res.data && res.data.message ) || 'Failed' ) + '</span>';
                            }
                            return;
                        }
                        var d = res.data;
                        var html = '';
                        html += '<div style="font-size:12px;">';
                        html += '<div style="color:' + ( d.patched ? '#86efac' : '#94a3b8' ) + ';">'
                              + ( d.patched ? '✓ www.conf patched' : '✓ pm.status_path already present' ) + '</div>';
                        html += '<div style="color:' + ( d.reloaded ? '#86efac' : '#fbbf24' ) + ';margin-top:4px;">'
                              + ( d.reloaded ? '✓ ' + esc( d.reload_msg ) : '⚠ ' + esc( d.reload_error ) ) + '</div>';
                        html += '</div>';
                        if ( patchRes ) { patchRes.innerHTML = html; }
                        if ( step2Next ) { step2Next.style.display = ''; }
                    } )
                    .catch( function () {
                        patchBtn.disabled = false;
                        if ( patchRes ) { patchRes.innerHTML = '<span style="color:#f87171;">Request failed.</span>'; }
                    } );
            } );
        }

        if ( step2Next ) { step2Next.addEventListener( 'click', goToStep3 ); }
        if ( step2Skip ) { step2Skip.addEventListener( 'click', goToStep3 ); }

        if ( copyNginx ) {
            copyNginx.addEventListener( 'click', function () {
                var text = nginxSnip ? ( nginxSnip.textContent || '' ) : '';
                navigator.clipboard.writeText( text ).then( function () {
                    if ( copyStatus ) {
                        copyStatus.textContent = 'Copied!';
                        setTimeout( function () { copyStatus.textContent = ''; }, 2000 );
                    }
                } );
            } );
        }

        if ( testBtn ) {
            testBtn.addEventListener( 'click', function () {
                testBtn.disabled = true;
                if ( testRes ) { testRes.textContent = '⏳ Testing…'; }
                post( 'csdt_fpm_worker_status', {}, cfg.fpmNonce )
                    .then( function ( res ) {
                        testBtn.disabled = false;
                        if ( res.success ) {
                            if ( testRes ) { testRes.textContent = ''; }
                            modal.style.display = 'none';
                            refreshFpmWorkers();
                        } else {
                            if ( testRes ) {
                                testRes.textContent = '✗ ' + ( ( res.data && res.data.message ) || 'Still not working — check nginx reload.' );
                            }
                        }
                    } )
                    .catch( function () {
                        testBtn.disabled = false;
                        if ( testRes ) { testRes.textContent = 'Request failed.'; }
                    } );
            } );
        }
    }());

    // PHP-FPM Saturation Monitor save + copy
    var fpmSaveBtn      = document.getElementById( 'csdt-fpm-save' );
    var fpmEnabledChk   = document.getElementById( 'csdt-fpm-enabled' );
    var fpmStatus       = document.getElementById( 'csdt-fpm-status' );
    var fpmCopyBtn      = document.getElementById( 'csdt-fpm-copy-snippet' );
    var fpmCopyStatus   = document.getElementById( 'csdt-fpm-copy-status' );

    if ( fpmSaveBtn && fpmEnabledChk ) {
        fpmSaveBtn.addEventListener( 'click', function () {
            fpmSaveBtn.disabled = true;
            if ( fpmStatus ) { fpmStatus.textContent = '\u23F3 Saving\u2026'; }
            post( 'csdt_fpm_monitor_save', {
                enabled:          fpmEnabledChk.checked ? '1' : '0',
                threshold:        ( document.getElementById( 'csdt-fpm-threshold'        ) || {} ).value || '3',
                cooldown:         ( document.getElementById( 'csdt-fpm-cooldown'         ) || {} ).value || '1800',
                probe_url:        ( document.getElementById( 'csdt-fpm-probe-url'        ) || {} ).value || '',
                probe_timeout:    ( document.getElementById( 'csdt-fpm-probe-timeout'    ) || {} ).value || '5',
                wp_container:     ( document.getElementById( 'csdt-fpm-wp-container'     ) || {} ).value || 'pi_wordpress',
                db_container:     ( document.getElementById( 'csdt-fpm-db-container'     ) || {} ).value || 'pi_mariadb',
                auto_restart:     ( document.getElementById( 'csdt-fpm-auto-restart'     ) || {} ).checked ? '1' : '0',
                restart_cooldown: ( document.getElementById( 'csdt-fpm-restart-cooldown' ) || {} ).value || '1200',
            }, cfg.fpmNonce )
                .then( function ( res ) {
                    fpmSaveBtn.disabled = false;
                    if ( fpmStatus ) {
                        fpmStatus.style.color = res.success ? '#16a34a' : '#dc2626'; fpmStatus.textContent = res.success ? '✅ Saved' : '❌ ' + esc( ( res.data && res.data.message ) || 'Failed' );
                        setTimeout( function () { if ( fpmStatus ) { fpmStatus.textContent = ''; fpmStatus.style.color = ''; } }, 10000 );
                    }
                } )
                .catch( function () {
                    fpmSaveBtn.disabled = false;
                    if ( fpmStatus ) { fpmStatus.textContent = 'Request failed.'; setTimeout( function () { if ( fpmStatus ) { fpmStatus.textContent = ''; fpmStatus.style.color = ''; } }, 10000 ); }
                } );
        } );
    }

    if ( fpmCopyBtn ) {
        fpmCopyBtn.addEventListener( 'click', function () {
            var snippet = document.getElementById( 'csdt-fpm-config-snippet' );
            if ( ! snippet ) { return; }
            navigator.clipboard.writeText( snippet.textContent || snippet.innerText )
                .then( function () {
                    if ( fpmCopyStatus ) {
                        fpmCopyStatus.textContent = 'Copied!';
                        setTimeout( function () { if ( fpmCopyStatus ) { fpmCopyStatus.textContent = ''; } }, 2000 );
                    }
                } )
                .catch( function () {
                    if ( fpmCopyStatus ) { fpmCopyStatus.textContent = 'Select text above and copy manually.'; }
                } );
        } );
    }

    // PHP Error Alerting save button
    var saveBtn     = document.getElementById( 'csdt-errmon-save' );
    var enabledChk  = document.getElementById( 'csdt-errmon-enabled' );
    var thresholdIn = document.getElementById( 'csdt-errmon-threshold' );
    var saveStatus  = document.getElementById( 'csdt-errmon-status' );

    if ( saveBtn && enabledChk ) {
        saveBtn.addEventListener( 'click', function () {
            saveBtn.disabled = true;
            if ( saveStatus ) { saveStatus.textContent = '\u23F3 Saving\u2026'; }
            post( 'csdt_php_error_monitor_save', {
                enabled:   enabledChk.checked ? '1' : '0',
                threshold: thresholdIn ? thresholdIn.value : '1',
            }, cfg.debugNonce )
                .then( function ( res ) {
                    saveBtn.disabled = false;
                    if ( saveStatus ) {
                        saveStatus.style.color = res.success ? '#16a34a' : '#dc2626'; saveStatus.textContent = res.success ? '✅ Saved' : '❌ ' + esc( ( res.data && res.data.message ) || 'Failed' );
                        setTimeout( function () { if ( saveStatus ) { saveStatus.textContent = ''; saveStatus.style.color = ''; } }, 10000 );
                    }
                } )
                .catch( function () {
                    saveBtn.disabled = false;
                    if ( saveStatus ) { saveStatus.textContent = 'Request failed.'; setTimeout( function () { if ( saveStatus ) { saveStatus.textContent = ''; saveStatus.style.color = ''; } }, 10000 ); }
                } );
        } );
    }

    // ── CS Monitor toggle save ─────────────────────────────────────────────
    ( function () {
        var chk     = document.getElementById( 'cs-perf-monitor-toggle' );
        var saveBtn = document.getElementById( 'cs-perf-monitor-save' );
        var savedEl = document.getElementById( 'cs-perf-monitor-saved' );
        if ( ! chk || ! saveBtn ) { return; }

        saveBtn.addEventListener( 'click', function () {
            saveBtn.disabled = true;
            var fd = new FormData();
            fd.append( 'action',  'csdt_devtools_save_perf_monitor' );
            fd.append( 'nonce',   csdtDebug.perfNonce );
            fd.append( 'enabled', chk.checked ? '1' : '0' );
            fetch( csdtDebug.ajaxUrl, { method: 'POST', body: fd } )
                .then( function ( r ) { return r.json(); } )
                .then( function ( resp ) {
                    saveBtn.disabled = false;
                    if ( savedEl ) {
                        savedEl.textContent = resp.success ? '✅ Saved' : '❌ Error';
                        savedEl.style.color = resp.success ? '#16a34a' : '#dc2626';
                        savedEl.classList.add( 'visible' );
                        setTimeout( function () { savedEl.classList.remove( 'visible' ); savedEl.style.color = ''; }, 10000 );
                    }
                    if ( resp.success ) {
                        var perfPanel = document.getElementById( 'cs-perf' );
                        if ( resp.data.perf_enabled === '1' && ! perfPanel ) {
                            window.location.reload();
                        } else if ( perfPanel ) {
                            perfPanel.style.display = resp.data.perf_enabled === '1' ? '' : 'none';
                        }
                    }
                } )
                .catch( function () {
                    saveBtn.disabled = false;
                    if ( savedEl ) {
                        savedEl.textContent = '❌ Error';
                        savedEl.style.color = '#dc2626';
                        savedEl.classList.add( 'visible' );
                        setTimeout( function () { savedEl.classList.remove( 'visible' ); savedEl.style.color = ''; }, 10000 );
                    }
                } );
        } );
    } )();

    // ── OPcache panel ────────────────────────────────────────────────────────
    ( function () {
        var flushBtn   = document.getElementById( 'csdt-opcache-flush-btn' );
        var refreshBtn = document.getElementById( 'csdt-opcache-refresh-btn' );
        var statsWrap  = document.getElementById( 'csdt-opcache-stats' );
        var statusEl   = document.getElementById( 'csdt-opcache-status' );
        var lastFlush  = document.getElementById( 'csdt-opcache-last-flush' );
        var revealBtn  = document.getElementById( 'csdt-opcache-token-reveal' );
        var copyBtn    = document.getElementById( 'csdt-opcache-token-copy' );
        var tokenEl    = document.getElementById( 'csdt-opcache-token-display' );
        var copyStatus = document.getElementById( 'csdt-opcache-copy-status' );

        if ( ! flushBtn ) { return; }

        function fmtBytes( b ) {
            if ( b >= 1048576 ) { return ( b / 1048576 ).toFixed( 1 ) + ' MB'; }
            return ( b / 1024 ).toFixed( 0 ) + ' KB';
        }

        function renderStats( d ) {
            if ( ! d || ! d.available ) {
                statsWrap.innerHTML = '<span style="color:#f87171;">OPcache not available on this server.</span>';
                return;
            }
            var pct  = Math.round( d.mem_used / d.mem_total * 100 );
            var bar  = '<div style="height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;width:140px;display:inline-block;vertical-align:middle;margin:0 6px;">' +
                       '<div style="height:100%;width:' + pct + '%;background:' + ( pct > 85 ? '#dc2626' : '#16a34a' ) + ';border-radius:3px;"></div></div>';
            statsWrap.innerHTML =
                '<div style="display:flex;flex-wrap:wrap;gap:16px;font-size:.82em;color:#64748b;">' +
                '<span>Memory: <strong style="color:#1e293b;">' + fmtBytes( d.mem_used ) + ' / ' + fmtBytes( d.mem_total ) + '</strong>' + bar + pct + '%</span>' +
                '<span>Cached scripts: <strong style="color:#1e293b;">' + d.cached_scripts + '</strong></span>' +
                '<span>Hit rate: <strong style="color:' + ( d.hit_rate >= 95 ? '#16a34a' : d.hit_rate >= 70 ? '#d97706' : '#dc2626' ) + ';">' + d.hit_rate + '%</strong></span>' +
                '<span>Wasted: <strong style="color:#1e293b;">' + fmtBytes( d.mem_wasted ) + '</strong></span>' +
                '</div>';
            if ( lastFlush ) { lastFlush.textContent = d.last_flush; }
        }

        function loadStats() {
            if ( statsWrap ) { statsWrap.innerHTML = '<span style="color:#64748b;">Loading…</span>'; }
            var fd = new FormData();
            fd.append( 'action', 'csdt_opcache_stats' );
            fd.append( 'nonce',  cfg.fpmNonce );
            fetch( cfg.ajaxUrl, { method: 'POST', body: fd } )
                .then( function ( r ) { return r.json(); } )
                .then( function ( resp ) { if ( resp.success ) { renderStats( resp.data ); } } )
                .catch( function () { if ( statsWrap ) { statsWrap.innerHTML = '<span style="color:#f87171;">Failed to load.</span>'; } } );
        }

        loadStats();

        refreshBtn.addEventListener( 'click', loadStats );

        flushBtn.addEventListener( 'click', function () {
            flushBtn.disabled = true;
            flushBtn.textContent = 'Flushing…';
            if ( statusEl ) { statusEl.textContent = ''; }
            var fd = new FormData();
            fd.append( 'action', 'csdt_opcache_flush' );
            fd.append( 'nonce',  cfg.fpmNonce );
            fetch( cfg.ajaxUrl, { method: 'POST', body: fd } )
                .then( function ( r ) { return r.json(); } )
                .then( function ( resp ) {
                    flushBtn.disabled = false;
                    flushBtn.textContent = '⚡ Flush OPcache';
                    if ( resp.success ) {
                        renderStats( resp.data.stats );
                        if ( statusEl ) {
                            statusEl.textContent = resp.data.flushed ? '✓ Flushed' : 'Not available';
                            setTimeout( function () { statusEl.textContent = ''; }, 3000 );
                        }
                    }
                } )
                .catch( function () {
                    flushBtn.disabled = false;
                    flushBtn.textContent = '⚡ Flush OPcache';
                } );
        } );

        if ( revealBtn && copyBtn && tokenEl ) {
            var revealed = false;
            var fullToken = copyBtn.dataset.token || '';
            revealBtn.addEventListener( 'click', function () {
                revealed = ! revealed;
                tokenEl.textContent = revealed ? fullToken : ( fullToken.substring( 0, 8 ) + '•'.repeat( 16 ) );
                revealBtn.textContent = revealed ? 'Hide' : 'Reveal';
            } );
            copyBtn.addEventListener( 'click', function () {
                navigator.clipboard.writeText( fullToken ).then( function () {
                    if ( copyStatus ) {
                        copyStatus.textContent = '✓ Copied';
                        setTimeout( function () { copyStatus.textContent = ''; }, 2000 );
                    }
                } );
            } );
        }
    } )();

    // ── Object cache toggle ───────────────────────────────────────────────────
    ( function () {
        var btn = document.getElementById( 'csdt-obj-cache-btn' );
        var msg = document.getElementById( 'csdt-obj-cache-msg' );
        if ( ! btn ) { return; }

        btn.addEventListener( 'click', function () {
            var action = btn.dataset.action;
            btn.disabled = true;
            btn.textContent = action === 'install' ? 'Installing…' : 'Removing…';
            if ( msg ) { msg.textContent = ''; msg.style.color = ''; }

            var fd = new FormData();
            fd.append( 'action',      'csdt_object_cache_toggle' );
            fd.append( 'nonce',       csdtDebug.objCacheNonce );
            fd.append( 'action_type', action );

            fetch( csdtDebug.ajaxUrl, { method: 'POST', body: fd } )
                .then( function ( r ) { return r.json(); } )
                .then( function ( resp ) {
                    if ( resp.success ) {
                        window.location.reload();
                    } else {
                        btn.disabled = false;
                        btn.textContent = action === 'install' ? 'Install APCu Cache' : 'Remove Drop-in';
                        if ( msg ) {
                            msg.textContent = '❌ ' + ( ( resp.data && resp.data.message ) || 'Action failed.' );
                            msg.style.color = '#dc2626';
                        }
                    }
                } )
                .catch( function () {
                    btn.disabled = false;
                    btn.textContent = action === 'install' ? 'Install APCu Cache' : 'Remove Drop-in';
                    if ( msg ) {
                        msg.textContent = '❌ Request failed.';
                        msg.style.color = '#dc2626';
                    }
                } );
        } );
    } )();

}() );
