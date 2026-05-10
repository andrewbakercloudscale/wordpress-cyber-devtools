/* ===========================================================
   CloudScale Code Block — Login Security admin JS  v1.9.10
   Handles: Hide Login save, 2FA site settings save,
            session duration, brute-force protection,
            TOTP setup wizard, email 2FA enable/disable.
   =========================================================== */
( function () {
    'use strict';

    const { ajaxUrl, nonce, secNonce } = window.csdtDevtoolsLogin || {};

    // ── Helpers ───────────────────────────────────────────────────────────

    function post( action, data ) {
        const body = new URLSearchParams( { action, nonce, ...data } );
        return fetch( ajaxUrl, { method: 'POST', body } )
            .then( r => r.json() )
            .then( res => {
                // WP returns -1 for nonce failures, 0 for unknown actions.
                // Neither is a structured success/error object.
                if ( res === -1 || res === 0 ) {
                    return { success: false, data: 'Session expired — please reload the page and try again.' };
                }
                return res;
            } );
    }

    function postSec( action, data ) {
        const body = new URLSearchParams( { action, nonce: secNonce, ...data } );
        return fetch( ajaxUrl, { method: 'POST', body } )
            .then( r => r.json() )
            .then( res => {
                if ( res === -1 || res === 0 ) {
                    return { success: false, data: 'Session expired — please reload the page and try again.' };
                }
                return res;
            } );
    }

    function flash( el, ok ) {
        if ( ! el ) return;
        el.textContent = ok ? '✅ Saved' : '❌ Error';
        el.style.color = ok ? '#16a34a' : '#dc2626';
        el.classList.add( 'visible' );
        setTimeout( () => {
            el.classList.remove( 'visible' );
            el.style.color = '';
        }, 10000 );
    }

    // ── Hide Login + 2FA site settings save ──────────────────────────────

    const hideSaveBtn  = document.getElementById( 'cs-hide-save' );
    const hideSaved    = document.getElementById( 'cs-hide-saved' );
    const twoFaSaveBtn = document.getElementById( 'cs-2fa-save' );
    const twoFaSaved   = document.getElementById( 'cs-2fa-saved' );

    function collectLoginPayload() {
        return {
            hide_enabled:     document.getElementById( 'cs-hide-enabled' )?.checked ? '1' : '0',
            login_slug:       document.getElementById( 'cs-login-slug' )?.value.trim() || '',
            method:           document.querySelector( 'input[name="csdt_devtools_2fa_method"]:checked' )?.value || 'off',
            force_admins:     document.getElementById( 'cs-2fa-force' )?.checked ? '1' : '0',
            grace_logins:     document.getElementById( 'cs-2fa-grace-logins' )?.value || '0',
            session_duration: document.getElementById( 'cs-session-duration' )?.value || 'default',
            bf_enabled:              document.getElementById( 'cs-bf-enabled' )?.checked ? '1' : '0',
            bf_attempts:             document.getElementById( 'cs-bf-attempts' )?.value || '5',
            bf_lockout:              document.getElementById( 'cs-bf-lockout' )?.value || '5',
            bf_enum_protect:         document.getElementById( 'cs-bf-enum-protect' )?.checked ? '1' : '0',
            ntfy_login_valid_user:   document.getElementById( 'cs-ntfy-login-valid' )?.checked ? '1' : '0',
            ntfy_login_invalid_user: document.getElementById( 'cs-ntfy-login-invalid' )?.checked ? '1' : '0',
        };
    }

    if ( hideSaveBtn ) {
        hideSaveBtn.addEventListener( 'click', () => {
            hideSaveBtn.disabled = true;
            post( 'csdt_devtools_login_save', collectLoginPayload() ).then( res => {
                hideSaveBtn.disabled = false;
                if ( res.success ) {
                    flash( hideSaved, true );
                    // Update the displayed current login URL (keep masked unless already shown).
                    const urlEl    = document.getElementById( 'cs-current-login-url-display' );
                    const openEl   = document.getElementById( 'cs-current-login-url-open' );
                    const showBtnU = document.getElementById( 'cs-login-url-show' );
                    if ( urlEl && res.data?.login_url ) {
                        const newUrl   = res.data.login_url;
                        const masked   = '•'.repeat( 32 );
                        urlEl.dataset.real   = newUrl;
                        urlEl.dataset.masked = masked;
                        const isShown  = showBtnU && showBtnU.textContent.includes( 'Hide' );
                        urlEl.textContent    = isShown ? newUrl : masked;
                    }
                    if ( openEl && res.data?.login_url ) {
                        openEl.href = res.data.login_url;
                    }
                    // (slug input already has the current value — no update needed)
                } else {
                    flash( hideSaved, false );
                }
            } ).catch( () => {
                hideSaveBtn.disabled = false;
                flash( hideSaved, false );
            } );
        } );
    }

    const sessionSaveBtn = document.getElementById( 'cs-session-save' );
    const sessionSaved   = document.getElementById( 'cs-session-saved' );

    if ( sessionSaveBtn ) {
        sessionSaveBtn.addEventListener( 'click', () => {
            sessionSaveBtn.disabled = true;
            post( 'csdt_devtools_login_save', collectLoginPayload() ).then( res => {
                sessionSaveBtn.disabled = false;
                flash( sessionSaved, res.success );
            } ).catch( () => {
                sessionSaveBtn.disabled = false;
                flash( sessionSaved, false );
            } );
        } );
    }

    const bfSaveBtn  = document.getElementById( 'cs-bf-save' );
    const bfSaved    = document.getElementById( 'cs-bf-saved' );
    const bfEnabled  = document.getElementById( 'cs-bf-enabled' );
    const bfOptions  = document.getElementById( 'cs-bf-options' );

    // Toggle numeric fields based on the enable checkbox.
    if ( bfEnabled && bfOptions ) {
        const syncBfOptions = () => { bfOptions.style.opacity = bfEnabled.checked ? '' : '0.4'; };
        syncBfOptions();
        bfEnabled.addEventListener( 'change', syncBfOptions );
    }

    if ( bfSaveBtn ) {
        bfSaveBtn.addEventListener( 'click', () => {
            bfSaveBtn.disabled = true;
            post( 'csdt_devtools_login_save', collectLoginPayload() ).then( res => {
                bfSaveBtn.disabled = false;
                flash( bfSaved, res.success );
            } ).catch( () => {
                bfSaveBtn.disabled = false;
                flash( bfSaved, false );
            } );
        } );
    }

    if ( twoFaSaveBtn ) {
        twoFaSaveBtn.addEventListener( 'click', () => {
            twoFaSaveBtn.disabled = true;
            post( 'csdt_devtools_login_save', collectLoginPayload() ).then( res => {
                twoFaSaveBtn.disabled = false;
                flash( twoFaSaved, res.success );
            } ).catch( () => {
                twoFaSaveBtn.disabled = false;
                flash( twoFaSaved, false );
            } );
        } );
    }

    // ── Radio label active highlight ──────────────────────────────────────

    document.querySelectorAll( '.cs-2fa-method-group input[type="radio"]' ).forEach( radio => {
        radio.addEventListener( 'change', () => {
            document.querySelectorAll( '.cs-2fa-method-group .cs-radio-label' ).forEach( l => l.classList.remove( 'active' ) );
            radio.closest( '.cs-radio-label' )?.classList.add( 'active' );
        } );
    } );

    // ── Email 2FA enable / resend ─────────────────────────────────────────

    const emailEnableBtn  = document.getElementById( 'cs-email-enable-btn' );
    const emailBadge      = document.getElementById( 'cs-email-badge' );
    const emailPendingMsg = document.getElementById( 'cs-email-pending-msg' );

    if ( emailEnableBtn ) {
        emailEnableBtn.addEventListener( 'click', () => {
            emailEnableBtn.disabled    = true;
            emailEnableBtn.textContent = 'Checking ports…';

            // Clear any previous port warning
            const existingWarn = document.getElementById( 'cs-email-port-warn' );
            if ( existingWarn ) existingWarn.remove();

            post( 'csdt_devtools_email_2fa_enable', {} ).then( res => {
                const d = res.data || {};

                // Show port/config warning if present (success OR failure)
                if ( d.port_warning ) {
                    const warn = document.createElement( 'div' );
                    warn.id        = 'cs-email-port-warn';
                    warn.className = 'cs-email-port-warn';
                    warn.textContent = '⚠️ ' + d.port_warning;
                    emailPendingMsg?.parentNode?.insertBefore( warn, emailPendingMsg.nextSibling );
                }

                if ( res.success ) {
                    emailEnableBtn.textContent = 'Resend';
                    emailEnableBtn.disabled    = false;
                    if ( emailBadge ) {
                        emailBadge.textContent = 'Awaiting verification';
                        emailBadge.className   = 'cs-2fa-badge cs-2fa-badge-pending';
                    }
                    if ( emailPendingMsg ) {
                        emailPendingMsg.style.display = '';
                        const span = document.createElement( 'span' );
                        span.className   = 'cs-pending-notice';
                        span.textContent = '📬 ' + ( d.message || 'Verification email sent — click the link in the email to activate.' );
                        emailPendingMsg.textContent = '';
                        emailPendingMsg.appendChild( span );
                    }
                } else {
                    emailEnableBtn.disabled    = false;
                    emailEnableBtn.textContent = 'Enable';
                    if ( emailPendingMsg ) {
                        emailPendingMsg.style.display = '';
                        const span = document.createElement( 'span' );
                        span.style.cssText = 'color:#e53e3e;font-size:12px';
                        if ( d.smtp_not_configured ) {
                            const mailUrl = ( window.csdtDevtoolsLogin || {} ).mailTabUrl || '';
                            span.innerHTML = '✗ Email isn\'t configured on this site. '
                                + ( mailUrl ? '<a href="' + mailUrl + '" style="color:#c53030;text-decoration:underline">Set up SMTP</a> to enable email delivery.' : 'Set up SMTP to enable email delivery.' );
                        } else {
                            span.textContent = '✗ ' + ( d.message || 'Failed to send.' );
                        }
                        emailPendingMsg.textContent = '';
                        emailPendingMsg.appendChild( span );
                    }
                }
            } ).catch( () => {
                emailEnableBtn.disabled    = false;
                emailEnableBtn.textContent = 'Enable';
                if ( emailPendingMsg ) {
                    emailPendingMsg.style.display = '';
                    const span = document.createElement( 'span' );
                    span.style.cssText = 'color:#e53e3e;font-size:12px';
                    span.textContent   = '✗ Network error. Try again.';
                    emailPendingMsg.textContent = '';
                    emailPendingMsg.appendChild( span );
                }
            } );
        } );
    }

    // ── 2FA disable (email or TOTP) ───────────────────────────────────────

    document.querySelectorAll( '.cs-2fa-disable' ).forEach( btn => {
        btn.addEventListener( 'click', () => {
            const method = btn.dataset.method;
            if ( ! confirm( 'Disable ' + ( method === 'totp' ? 'Authenticator App' : 'Email' ) + ' 2FA? You can re-enable it at any time.' ) ) return;
            btn.disabled = true;
            post( 'csdt_devtools_2fa_disable', { method } ).then( res => {
                if ( res.success ) {
                    location.reload();
                } else {
                    btn.disabled = false;
                    alert( res.data || 'Failed.' );
                }
            } );
        } );
    } );

    // ── TOTP Setup Wizard ─────────────────────────────────────────────────

    const totpSetupBtn  = document.getElementById( 'cs-totp-setup-btn' );
    const totpWizard    = document.getElementById( 'cs-totp-wizard' );
    const totpCancelBtn = document.getElementById( 'cs-totp-cancel-btn' );
    const totpCopyBtn   = document.getElementById( 'cs-totp-copy-btn' );
    const totpQrLoading  = document.getElementById( 'cs-totp-qr-loading' );
    const totpQrCanvas   = document.getElementById( 'cs-totp-qr-canvas' );
    const totpManual    = document.getElementById( 'cs-totp-manual' );
    const totpSecret    = document.getElementById( 'cs-totp-secret-display' );
    const totpVerifyBtn = document.getElementById( 'cs-totp-verify-btn' );
    const totpCodeInput = document.getElementById( 'cs-totp-verify-code' );
    const totpMsg       = document.getElementById( 'cs-totp-verify-msg' );

    if ( totpSetupBtn && totpWizard ) {
        totpSetupBtn.addEventListener( 'click', () => {
            totpWizard.style.display = 'block';
            totpSetupBtn.style.display = 'none';

            // Reset state
            if ( totpQrLoading ) totpQrLoading.style.display = 'flex';
            if ( totpQrCanvas )  { totpQrCanvas.style.display = 'none'; totpQrCanvas.innerHTML = ''; }
            if ( totpManual )    totpManual.style.display = 'none';
            if ( totpMsg )       { totpMsg.style.display = 'none'; totpMsg.textContent = ''; }
            if ( totpCodeInput ) totpCodeInput.value = '';

            // Fetch secret from server
            post( 'csdt_devtools_totp_setup_start', {} ).then( res => {
                if ( totpQrLoading ) totpQrLoading.style.display = 'none';
                if ( ! res.success ) {
                    alert( res.data || 'Failed to start setup.' );
                    closeTotpWizard();
                    return;
                }
                if ( totpQrCanvas && res.data.otpauth && window.QRCode ) {
                    totpQrCanvas.innerHTML = '';
                    new window.QRCode( totpQrCanvas, {
                        text:          res.data.otpauth,
                        width:         220,
                        height:        220,
                        colorDark:     '#000000',
                        colorLight:    '#ffffff',
                        correctLevel:  window.QRCode.CorrectLevel.M,
                    } );
                    totpQrCanvas.style.display = 'block';
                }
                if ( totpSecret ) totpSecret.textContent = res.data.secret;
                if ( totpManual ) totpManual.style.display = 'block';
                if ( totpCodeInput ) totpCodeInput.focus();
            } ).catch( () => {
                if ( totpQrLoading ) totpQrLoading.style.display = 'none';
                alert( 'Network error starting TOTP setup.' );
                closeTotpWizard();
            } );
        } );
    }

    function closeTotpWizard() {
        if ( totpWizard )    totpWizard.style.display = 'none';
        if ( totpSetupBtn )  totpSetupBtn.style.display = '';
    }

    if ( totpCancelBtn ) {
        totpCancelBtn.addEventListener( 'click', closeTotpWizard );
    }

    if ( totpCopyBtn ) {
        totpCopyBtn.addEventListener( 'click', () => {
            const key = totpSecret ? totpSecret.textContent.trim() : '';
            if ( ! key ) return;
            navigator.clipboard.writeText( key ).then( () => {
                const orig = totpCopyBtn.textContent;
                totpCopyBtn.textContent = '✓ Copied';
                totpCopyBtn.style.background = '#1db954';
                setTimeout( () => {
                    totpCopyBtn.textContent = orig;
                    totpCopyBtn.style.background = '';
                }, 2000 );
            } ).catch( () => {
                // Fallback for browsers without clipboard API
                const range = document.createRange();
                range.selectNodeContents( totpSecret );
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange( range );
            } );
        } );
    }

    // Only allow digits in the TOTP code input
    if ( totpCodeInput ) {
        totpCodeInput.addEventListener( 'input', () => {
            totpCodeInput.value = totpCodeInput.value.replace( /\D/g, '' ).slice( 0, 6 );
        } );
        totpCodeInput.addEventListener( 'keydown', e => {
            if ( e.key === 'Enter' ) totpVerifyBtn?.click();
        } );
    }

    if ( totpVerifyBtn ) {
        totpVerifyBtn.addEventListener( 'click', () => {
            const code = ( totpCodeInput?.value || '' ).replace( /\D/g, '' );
            if ( code.length !== 6 ) {
                showTotpMsg( 'Please enter your 6-digit code.', false );
                return;
            }

            totpVerifyBtn.disabled = true;
            totpVerifyBtn.textContent = 'Verifying…';

            post( 'csdt_devtools_totp_setup_verify', { code } ).then( res => {
                totpVerifyBtn.disabled = false;
                totpVerifyBtn.textContent = '✓ Verify & Activate';
                if ( res.success ) {
                    showTotpMsg( '✅ ' + ( res.data?.message || 'Activated!' ), true );
                    // Clear the secret from the DOM now that setup is complete.
                    if ( totpSecret ) totpSecret.textContent = '';
                    setTimeout( () => location.reload(), 1200 );
                } else {
                    showTotpMsg( '❌ ' + ( res.data || 'Incorrect code.' ), false );
                    if ( totpCodeInput ) { totpCodeInput.value = ''; totpCodeInput.focus(); }
                }
            } ).catch( () => {
                totpVerifyBtn.disabled = false;
                totpVerifyBtn.textContent = '✓ Verify & Activate';
                showTotpMsg( 'Network error. Try again.', false );
            } );
        } );
    }

    function showTotpMsg( text, ok ) {
        if ( ! totpMsg ) return;
        totpMsg.textContent     = text;
        totpMsg.style.display   = 'block';
        totpMsg.style.color     = ok ? '#1db954' : '#e53e3e';
        totpMsg.style.fontWeight = '600';
    }

    // ── Failed login log ──────────────────────────────────────────────────

    const bfLogWrap   = document.getElementById( 'cs-bf-log-wrap' );
    const bfChart     = document.getElementById( 'cs-bf-chart' );
    const bfTableWrap = document.getElementById( 'cs-bf-table-wrap' );
    const bfLogTotal  = document.getElementById( 'cs-bf-log-total' );

    function escHtml( s ) {
        return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
    }

    function fmtAgo( secs ) {
        if ( secs < 60 )    return Math.floor( secs ) + ' secs';
        if ( secs < 3600 )  return Math.floor( secs / 60 ) + ' min ago';
        if ( secs < 86400 ) return Math.floor( secs / 3600 ) + 'h ago';
        return Math.floor( secs / 86400 ) + 'd ago';
    }

    function renderBfChart( log, now, isAttack ) {
        if ( ! bfChart ) return;
        const DAY = 86400;
        const days = [];
        for ( let i = 13; i >= 0; i-- ) {
            const dayStart = ( Math.floor( ( now - i * DAY ) / DAY ) ) * DAY;
            const dayEnd   = dayStart + DAY;
            const count    = log.filter( e => e[0] >= dayStart && e[0] < dayEnd ).length;
            const d        = new Date( dayStart * 1000 );
            days.push( {
                label: d.toLocaleDateString( 'en', { month: 'short', day: 'numeric' } ),
                count,
                isToday: i === 0,
            } );
        }
        // Outlier-resistant scale: if the top day is 4× the second-highest, cap the
        // display max so the spike doesn't flatten every other bar.
        const rawMax = Math.max( 1, ...days.map( d => d.count ) );
        const sortedDesc = days.map( d => d.count ).sort( ( a, b ) => b - a );
        const secondMax  = sortedDesc[ 1 ] || 0;
        const isCapped   = secondMax > 0 && rawMax > secondMax * 4;
        const displayMax = isCapped ? Math.max( 1, Math.ceil( secondMax * 2 ) ) : rawMax;

        const mid = displayMax === 1 ? 1 : Math.round( displayMax / 2 );
        const topLabel = isCapped ? displayMax + '+' : displayMax;
        const yAxis = `<div class="cs-bf-yaxis">
            <span class="cs-bf-ytick">${topLabel}</span>
            <span class="cs-bf-ytick">${mid}</span>
            <span class="cs-bf-ytick">0</span>
        </div>`;

        // Compute bar width so exactly 7 bars fill the visible area.
        // 14 bars total → content is ~2× container width → overflow-x scroll activates.
        const YAXIS_W = 28, GAP = 4, VISIBLE = 7;
        const chartW  = bfChart.clientWidth || 280;
        const barW    = Math.max( 24, Math.floor( ( chartW - YAXIS_W - VISIBLE * GAP ) / VISIBLE ) );

        const bars = days.map( d => {
            const truncated = d.count > displayMax;
            const pct = truncated ? 92 : Math.round( ( d.count / displayMax ) * 100 );
            let cls = d.count === 0 ? ' cs-bf-bar-zero' : d.count >= displayMax * 0.75 ? ' cs-bf-bar-high' : d.count >= displayMax * 0.4 ? ' cs-bf-bar-mid' : '';
            const extraStyle = isAttack && d.count > 0
                ? `background:#dc2626!important;${ d.isToday ? 'box-shadow:0 0 8px rgba(220,38,38,.6);' : 'opacity:.7;' }`
                : '';
            const countColor = truncated ? '#dc2626' : d.count === 0 ? '#16a34a' : '#94a3b8';
            const countText  = truncated ? d.count + '+' : d.count;
            return `<div class="cs-bf-day" style="width:${barW}px;flex-shrink:0;flex-grow:0;">
                <div class="cs-bf-bar-track">
                    <div class="cs-bf-bar${cls}" style="height:${pct}%;${extraStyle}" title="${d.count} failed attempt${d.count !== 1 ? 's' : ''}"></div>
                </div>
                <div class="cs-bf-day-label" style="${isAttack && d.isToday ? 'color:#dc2626;font-weight:700;' : ''}">${d.label}</div>
                <div style="font-size:9px;font-weight:700;color:${countColor};text-align:center;line-height:1.4;">${countText}</div>
            </div>`;
        } ).join( '' );

        bfChart.innerHTML = yAxis + bars;
        // Scroll to most-recent (right end). setTimeout gives iOS Safari time to compute scrollWidth.
        const scrollToEnd = () => { bfChart.scrollLeft = bfChart.scrollWidth; };
        scrollToEnd();
        setTimeout( scrollToEnd, 50 );
    }

    function renderBfTable( log, now, blockedIps ) {
        if ( ! bfTableWrap ) return;
        if ( log.length === 0 ) {
            bfTableWrap.innerHTML = '<div class="cs-bf-empty">No failed login attempts in the last 14 days.</div>';
            return;
        }
        const blocked = new Set( blockedIps || [] );
        const rows = log.slice().reverse().slice( 0, 200 ).map( e => {
            const d    = new Date( e[0] * 1000 );
            const time = d.toLocaleDateString( 'en', { month: 'short', day: 'numeric' } )
                       + ' ' + d.toLocaleTimeString( 'en', { hour: '2-digit', minute: '2-digit' } );
            const cc   = e[3] || '';
            const ip   = e[2] || '';
            const countryHtml = cc
                ? `<div style="font-size:10px;color:#64748b;margin-top:2px;">${bfCountryFlag( cc )}${escHtml( bfCountryNames[ cc ] || cc )}</div>`
                : '';
            const whoisHtml = ip
                ? `<a href="https://ipinfo.io/${encodeURIComponent( ip )}" target="_blank" rel="noopener" style="font-size:10px;padding:2px 6px;border:1px solid #cbd5e1;border-radius:4px;background:#f8fafc;color:#475569;text-decoration:none;margin-right:4px;">Whois</a>`
                : '';
            const blockHtml = ip
                ? ( blocked.has( ip )
                    ? `<span class="cs-bf-blocked-badge" data-ip="${escHtml( ip )}" style="font-size:10px;padding:2px 6px;border:1px solid #86efac;border-radius:4px;background:#dcfce7;color:#15803d;font-weight:600;cursor:pointer;" title="Click to unblock">🚫 Blocked</span>`
                    : `<button type="button" class="cs-bf-block-btn" data-ip="${escHtml( ip )}" style="font-size:10px;padding:2px 6px;border:1px solid #fca5a5;border-radius:4px;background:#fef2f2;color:#dc2626;cursor:pointer;font-weight:600;">Block</button>` )
                : '';
            return `<tr>
                <td class="cs-bf-td cs-bf-td-time" title="${time}">${fmtAgo( now - e[0] )}</td>
                <td class="cs-bf-td cs-bf-td-user">${escHtml( e[1] || '—' )}</td>
                <td class="cs-bf-td cs-bf-td-ip">${escHtml( ip || '—' )}${countryHtml}</td>
                <td class="cs-bf-td" style="white-space:nowrap;text-align:right;">${whoisHtml}${blockHtml}</td>
            </tr>`;
        } ).join( '' );
        bfTableWrap.innerHTML = `<table class="cs-bf-table">
            <thead><tr>
                <th class="cs-bf-th">When</th>
                <th class="cs-bf-th">Username tried</th>
                <th class="cs-bf-th">IP / Country</th>
                <th class="cs-bf-th"></th>
            </tr></thead>
            <tbody>${rows}</tbody>
        </table>`;
        wireBfBlockBtns( bfTableWrap );
    }

    function wireBfBlockBtns( container ) {
        container.querySelectorAll( '.cs-bf-block-btn' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                const ip = btn.getAttribute( 'data-ip' );
                btn.disabled = true;
                btn.textContent = '⏳';
                postSec( 'csdt_ip_block', { ip, reason: 'failed login' } ).then( res => {
                    if ( res.success ) {
                        const badge = document.createElement( 'span' );
                        badge.className = 'cs-bf-blocked-badge';
                        badge.setAttribute( 'data-ip', ip );
                        badge.style.cssText = 'font-size:10px;padding:2px 6px;border:1px solid #86efac;border-radius:4px;background:#dcfce7;color:#15803d;font-weight:600;cursor:pointer;';
                        badge.title = 'Click to unblock';
                        badge.textContent = '🚫 Blocked';
                        btn.replaceWith( badge );
                        wireBfUnblockBadge( badge );
                        bfAddBlocklistRow( ip );
                    } else {
                        btn.disabled = false;
                        btn.textContent = 'Block';
                        alert( res.data || 'Block failed.' );
                    }
                } ).catch( () => {
                    btn.disabled = false;
                    btn.textContent = 'Block';
                } );
            } );
        } );
        container.querySelectorAll( '.cs-bf-blocked-badge' ).forEach( wireBfUnblockBadge );
    }

    function wireBfUnblockBadge( badge ) {
        badge.addEventListener( 'click', () => {
            const ip = badge.getAttribute( 'data-ip' );
            if ( ! confirm( 'Unblock ' + ip + '?' ) ) return;
            badge.style.opacity = '0.5';
            postSec( 'csdt_ip_unblock', { ip } ).then( res => {
                if ( res.success ) {
                    const btn = document.createElement( 'button' );
                    btn.type = 'button';
                    btn.className = 'cs-bf-block-btn';
                    btn.setAttribute( 'data-ip', ip );
                    btn.style.cssText = 'font-size:10px;padding:2px 6px;border:1px solid #fca5a5;border-radius:4px;background:#fef2f2;color:#dc2626;cursor:pointer;font-weight:600;';
                    btn.textContent = 'Block';
                    badge.replaceWith( btn );
                    btn.addEventListener( 'click', btn.onclick );
                    wireBfBlockBtns( btn.closest( 'table' ) || bfTableWrap );
                    const blRow = document.getElementById( 'cs-bl-row-' + ip.replace( /\./g, '-' ) );
                    if ( blRow ) blRow.remove();
                    const tbody = document.getElementById( 'cs-blocklist-tbody' );
                    const wrap  = document.getElementById( 'cs-ip-blocklist-wrap' );
                    const count = document.getElementById( 'cs-blocklist-count' );
                    if ( tbody && tbody.rows.length === 0 && wrap ) wrap.style.display = 'none';
                    if ( count ) count.textContent = ( tbody ? tbody.rows.length : 0 ) + ' blocked';
                } else {
                    badge.style.opacity = '';
                    alert( res.data || 'Unblock failed.' );
                }
            } ).catch( () => { badge.style.opacity = ''; } );
        } );
    }

    function bfAddBlocklistRow( ip ) {
        const wrap  = document.getElementById( 'cs-ip-blocklist-wrap' );
        const tbody = document.getElementById( 'cs-blocklist-tbody' );
        const count = document.getElementById( 'cs-blocklist-count' );
        if ( ! tbody ) return;
        if ( wrap ) wrap.style.display = '';
        const rowId = 'cs-bl-row-' + ip.replace( /\./g, '-' );
        if ( document.getElementById( rowId ) ) return;
        const tr = document.createElement( 'tr' );
        tr.id = rowId;
        tr.innerHTML = '<td class="cs-bf-td cs-bf-td-ip">' + escHtml( ip ) + '</td>'
            + '<td class="cs-bf-td cs-bf-td-time">failed login</td>'
            + '<td class="cs-bf-td cs-bf-td-time">just now</td>'
            + '<td class="cs-bf-td" style="text-align:right;white-space:nowrap;">'
            + '<a href="https://ipinfo.io/' + encodeURIComponent( ip ) + '" target="_blank" rel="noopener" style="font-size:10px;padding:2px 6px;border:1px solid #cbd5e1;border-radius:4px;background:#f8fafc;color:#475569;text-decoration:none;margin-right:4px;">Whois</a>'
            + '<button type="button" class="cs-ip-unblock-btn" data-ip="' + escHtml( ip ) + '" style="font-size:10px;padding:2px 6px;border:1px solid #cbd5e1;border-radius:4px;background:#f8fafc;color:#475569;cursor:pointer;">Unblock</button>'
            + '</td>';
        tbody.prepend( tr );
        if ( count ) count.textContent = tbody.rows.length + ' blocked';
    }

    if ( bfLogWrap ) {
        post( 'csdt_devtools_bf_log_fetch', {} ).then( res => {
            if ( ! res.success ) return;
            const { log, now, today_count, countries_bf, blocked_ips, api_log, countries_api } = res.data;
            const isAttack = today_count >= 30;
            if ( bfLogTotal ) bfLogTotal.textContent = log.length + ' event' + ( log.length !== 1 ? 's' : '' );
            renderBfChart( log, now, isAttack );
            renderBfTable( log, now, blocked_ips );
            if ( api_log && api_log.length > 0 ) {
                renderApiAttackTable( api_log, now );
            }

            const countriesBlocked = ( window.csdtDevtoolsLogin || {} ).countriesBlocked || {};
            if ( Object.keys( countries_bf || {} ).length > 0 || Object.keys( countriesBlocked ).length > 0 || Object.keys( countries_api || {} ).length > 0 ) {
                renderBfGeoMap( countries_bf || {}, countriesBlocked, countries_api || {} );
            }

            // Fix it CTA — shown when there are active failed logins and Hide Login is not yet enabled.
            const hideLoginEnabled = ( window.csdtDevtoolsLogin || {} ).hideLoginEnabled;
            if ( log.length > 0 && hideLoginEnabled !== '1' ) {
                const existing = document.getElementById( 'cs-bf-hide-login-cta' );
                if ( existing ) existing.remove();
                const cta = document.createElement( 'div' );
                cta.id = 'cs-bf-hide-login-cta';
                cta.style.cssText = 'margin-top:14px;background:#fef2f2;border:1.5px solid #fca5a5;border-radius:8px;padding:11px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;';
                cta.innerHTML = '<div style="font-size:13px;color:#991b1b;line-height:1.5;"><strong>⚠️ wp-login.php is publicly exposed.</strong> Bots are probing your login form directly. Hiding the login URL stops the majority of automated attacks immediately.</div>'
                    + '<button type="button" id="cs-bf-fix-hide-login" style="flex-shrink:0;background:#dc2626;color:#fff;border:none;border-radius:6px;padding:7px 16px;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;">🔒 Enable Hide Login →</button>';
                if ( bfLogWrap ) bfLogWrap.appendChild( cta );
                document.addEventListener( 'click', function onFixClick( e ) {
                    if ( ! e.target.closest( '#cs-bf-fix-hide-login' ) ) return;
                    const target = document.getElementById( 'cs-panel-hide-login' );
                    if ( target ) target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
                    document.removeEventListener( 'click', onFixClick );
                } );
            }

            // Active attack banner
            if ( today_count >= 30 ) {
                const banner = document.createElement( 'div' );
                banner.style.cssText = 'display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;margin-bottom:12px;';

                const icon = document.createElement( 'span' );
                icon.textContent = '⚠';
                icon.style.cssText = 'font-size:1.1em;color:#dc2626;flex-shrink:0;';

                const label = document.createElement( 'span' );
                label.textContent = 'Brute force attack detected';
                label.style.cssText = 'font-weight:700;color:#991b1b;font-size:.9em;';

                const badge = document.createElement( 'span' );
                badge.textContent = today_count + ' attempts today';
                badge.style.cssText = 'background:#dc2626;color:#fff;font-size:.78em;font-weight:700;padding:2px 9px;border-radius:10px;white-space:nowrap;';

                const msg = document.createElement( 'span' );
                msg.textContent = 'Credential-stuffing is actively targeting this site. Ensure 2FA is enabled.';
                msg.style.cssText = 'font-size:.82em;color:#7f1d1d;flex-basis:100%;margin-top:2px;';

                banner.appendChild( icon );
                banner.appendChild( label );
                banner.appendChild( badge );
                banner.appendChild( msg );
                bfLogWrap.insertBefore( banner, bfLogWrap.firstChild );
            }
        } ).catch( () => {
            if ( bfTableWrap ) bfTableWrap.innerHTML = '<div class="cs-bf-empty">Could not load log.</div>';
        } );
    }

    // ── BF Self-Test ──────────────────────────────────────────────────────
    const bfTestBtn    = document.getElementById( 'cs-bf-test-btn' );
    const bfTestResult = document.getElementById( 'cs-bf-test-result' );

    if ( bfTestBtn ) {
        bfTestBtn.addEventListener( 'click', () => {
            bfTestBtn.disabled = true;
            bfTestBtn.textContent = '⏳ Testing…';
            if ( bfTestResult ) { bfTestResult.style.display = 'none'; bfTestResult.textContent = ''; }

            post( 'csdt_bf_self_test', {} ).then( res => {
                bfTestBtn.disabled = false;
                bfTestBtn.textContent = '🧪 Test BF Protection';
                if ( ! bfTestResult ) return;
                bfTestResult.style.display = 'inline-block';
                if ( res.success && res.data.passed ) {
                    bfTestResult.style.cssText = 'display:inline-block;background:#dcfce7;color:#166534;border:1px solid #86efac;border-radius:6px;padding:5px 12px;font-size:13px;font-weight:600;';
                    const notif = res.data.ntfy_url
                        ? ( res.data.notif_sent ? ' ntfy sent.' : ' (no ntfy configured)' )
                        : ' (no ntfy configured)';
                    bfTestResult.textContent = `✅ PASS — lockout fired after ${res.data.attempts} attempts (${res.data.lockout_mins} min).${notif}`;
                } else if ( res.success && ! res.data.passed ) {
                    bfTestResult.style.cssText = 'display:inline-block;background:#fef2f2;color:#991b1b;border:1px solid #fca5a5;border-radius:6px;padding:5px 12px;font-size:13px;font-weight:600;';
                    bfTestResult.textContent = '❌ FAIL — lockout did not trigger. Check BF protection is saved correctly.';
                } else {
                    bfTestResult.style.cssText = 'display:inline-block;background:#fef2f2;color:#991b1b;border:1px solid #fca5a5;border-radius:6px;padding:5px 12px;font-size:13px;font-weight:600;';
                    bfTestResult.textContent = '❌ ' + ( res.data || 'Test failed.' );
                }
            } ).catch( () => {
                bfTestBtn.disabled = false;
                bfTestBtn.textContent = '🧪 Test BF Protection';
                if ( bfTestResult ) {
                    bfTestResult.style.cssText = 'display:inline-block;background:#fef2f2;color:#991b1b;border:1px solid #fca5a5;border-radius:6px;padding:5px 12px;font-size:13px;font-weight:600;';
                    bfTestResult.textContent = '❌ Request failed. Check your connection.';
                }
            } );
        } );
    }

    // ── SSH Monitor save ─────────────────────────────────────────────────

    const sshMonSaveBtn = document.getElementById( 'cs-ssh-mon-save' );
    const sshMonSaved   = document.getElementById( 'cs-ssh-mon-saved' );

    if ( sshMonSaveBtn ) {
        sshMonSaveBtn.addEventListener( 'click', () => {
            sshMonSaveBtn.disabled = true;
            post( 'csdt_ssh_monitor_save', {
                enabled:   document.getElementById( 'cs-ssh-mon-enabled' )?.checked ? '1' : '0',
                threshold: document.getElementById( 'cs-ssh-mon-threshold' )?.value || '10',
            } ).then( res => {
                sshMonSaveBtn.disabled = false;
                flash( sshMonSaved, res.success );
            } ).catch( () => {
                sshMonSaveBtn.disabled = false;
                flash( sshMonSaved, false );
            } );
        } );
    }

    const sshLogClearBtn = document.getElementById( 'cs-ssh-log-clear' );
    if ( sshLogClearBtn ) {
        sshLogClearBtn.addEventListener( 'click', () => {
            if ( ! confirm( 'Clear SSH alert history?' ) ) return;
            post( 'csdt_ssh_log_clear', {} ).then( res => {
                if ( res.success ) {
                    const tbl = sshLogClearBtn.closest( 'div[style]' );
                    if ( tbl ) tbl.remove();
                }
            } );
        } );
    }

    // ── Login slug show/hide ─────────────────────────────────────────────

    ( function () {
        const showBtn = document.getElementById( 'cs-login-slug-show' );
        const inp     = document.getElementById( 'cs-login-slug' );
        if ( ! showBtn || ! inp ) return;
        showBtn.addEventListener( 'click', function () {
            const showing = inp.type === 'text';
            inp.type = showing ? 'password' : 'text';
            showBtn.textContent = showing ? '👁 Show' : '🔒 Hide';
        } );
    } )();

    // ── Login URL show/hide ──────────────────────────────────────────────

    ( function () {
        const showBtn  = document.getElementById( 'cs-login-url-show' );
        const urlCode  = document.getElementById( 'cs-current-login-url-display' );
        const openLink = document.getElementById( 'cs-current-login-url-open' );
        if ( ! showBtn || ! urlCode ) return;
        let shown = false;
        showBtn.addEventListener( 'click', function () {
            shown = !shown;
            urlCode.textContent  = shown ? ( urlCode.dataset.real || '' ) : ( urlCode.dataset.masked || '' );
            showBtn.textContent  = shown ? '🔒 Hide' : '👁 Show';
        } );
    } )();

    // ── Slug live preview + weak-slug warning ────────────────────────────

    const slugInput = document.getElementById( 'cs-login-slug' );
    const urlCode2  = document.getElementById( 'cs-current-login-url-display' );
    const baseEl    = document.querySelector( '.cs-slug-base' );
    const weakWarn  = document.getElementById( 'cs-slug-weak-warn' );

    function isWeakSlug( v ) {
        return v.length > 0 && v.length < 14 && /^[a-z]+$/i.test( v );
    }

    function updateSlugPreview() {
        const inp  = document.getElementById( 'cs-login-slug' );
        const code = document.getElementById( 'cs-current-login-url-display' );
        const base = document.querySelector( '.cs-slug-base' );
        const warn = document.getElementById( 'cs-slug-weak-warn' );
        if ( ! inp || ! code || ! base ) return;
        const slug = inp.value.trim();
        const full = slug ? base.textContent.replace( /\/$/, '' ) + '/' + slug + '/' : base.textContent.replace( /\/$/, '' ) + '/wp-login.php';
        // Update the masked and real data attrs so show/hide stays in sync.
        code.dataset.real    = full;
        const maskedFull     = '•'.repeat( 32 );
        code.dataset.masked  = maskedFull;
        // Only update visible text if currently masked.
        const showBtn = document.getElementById( 'cs-login-url-show' );
        const isShown = showBtn && showBtn.textContent.includes( 'Hide' );
        code.textContent = isShown ? full : maskedFull;
        if ( warn ) warn.style.display = isWeakSlug( slug ) ? '' : 'none';
    }

    if ( slugInput && urlCode2 && baseEl ) {
        slugInput.addEventListener( 'input', updateSlugPreview );
        updateSlugPreview();
    }

    // ── Attack Origins map ────────────────────────────────────────────────

    var bfGeoMap     = null;
    var bfGeoMarkers = [];

    var bfCountryCentroids = {AF:[33,65],AL:[41,20],DZ:[28,3],AO:[-12.5,18.5],AR:[-34,-64],AT:[47.5,14],AU:[-25,134],BD:[24,90],BE:[50.8,4.5],BG:[43,25],BR:[-10,-55],CA:[56,-96],CH:[47,8],CL:[-35.5,-71],CN:[35,105],CO:[4,-72],CZ:[49.8,15.5],DE:[51,10],DK:[56,10],EG:[27,30],ES:[40,-4],FI:[64,26],FR:[46.5,2.5],GB:[54,-2],GH:[8,-1.5],GR:[39,22],HK:[22.3,114.2],HU:[47,19],ID:[-5,120],IE:[53.5,-8],IL:[31.5,34.8],IN:[22,79],IQ:[33,44],IR:[32,53],IT:[42.5,12.5],JP:[36,138],KE:[-1,38],KR:[36,128],MA:[32,-6],MX:[23,-102],MY:[4.2,101.9],NG:[10,8],NL:[52.5,5.7],NO:[64,12],NZ:[-42,174],PH:[12,122],PK:[30,70],PL:[52,20],PT:[39.5,-8],RO:[46,25],RU:[60,100],SA:[24,45],SE:[63,16],SG:[1.35,103.8],TH:[15.5,101],TR:[39,35],TW:[23.7,121],TZ:[-6.5,35],UA:[49,32],US:[39,-98],VN:[16,106],ZA:[-29,24],ZW:[-19.5,29.8]};

    var bfCountryNames = {AF:'Afghanistan',AL:'Albania',DZ:'Algeria',AO:'Angola',AR:'Argentina',AT:'Austria',AU:'Australia',BD:'Bangladesh',BE:'Belgium',BG:'Bulgaria',BR:'Brazil',CA:'Canada',CH:'Switzerland',CL:'Chile',CN:'China',CO:'Colombia',CZ:'Czechia',DE:'Germany',DK:'Denmark',EG:'Egypt',ES:'Spain',FI:'Finland',FR:'France',GB:'United Kingdom',GH:'Ghana',GR:'Greece',HK:'Hong Kong',HU:'Hungary',ID:'Indonesia',IE:'Ireland',IL:'Israel',IN:'India',IQ:'Iraq',IR:'Iran',IT:'Italy',JP:'Japan',KE:'Kenya',KR:'South Korea',MA:'Morocco',MX:'Mexico',MY:'Malaysia',NG:'Nigeria',NL:'Netherlands',NO:'Norway',NZ:'New Zealand',PH:'Philippines',PK:'Pakistan',PL:'Poland',PT:'Portugal',RO:'Romania',RU:'Russia',SA:'Saudi Arabia',SE:'Sweden',SG:'Singapore',TH:'Thailand',TR:'Turkey',TW:'Taiwan',TZ:'Tanzania',UA:'Ukraine',US:'United States',VN:'Vietnam',ZA:'South Africa',ZW:'Zimbabwe'};

    function bfCountryFlag( cc ) {
        if ( ! cc || cc.length !== 2 ) return '';
        return String.fromCodePoint( ...Array.from( cc.toUpperCase() ).map( function ( c ) { return 0x1F1E6 - 65 + c.charCodeAt( 0 ); } ) ) + ' ';
    }

    function initBfGeoMap() {
        if ( bfGeoMap ) return;
        if ( typeof L === 'undefined' ) return;
        var mapEl = document.getElementById( 'cs-bf-geo-map' );
        if ( ! mapEl ) return;
        if ( mapEl._leaflet_id ) {
            try { mapEl._leaflet_id = undefined; mapEl.innerHTML = ''; } catch ( e ) {}
        }
        try {
            bfGeoMap = L.map( 'cs-bf-geo-map', {
                center: [ 20, 10 ],
                zoom: 2,
                minZoom: 1,
                maxZoom: 6,
                scrollWheelZoom: false,
                attributionControl: false,
                maxBounds: [ [ -90, -180 ], [ 90, 180 ] ],
                maxBoundsViscosity: 1.0,
            } );
            bfGeoMap.on( 'click', function () { bfGeoMap.scrollWheelZoom.enable(); } );
            mapEl.addEventListener( 'mouseleave', function () { bfGeoMap.scrollWheelZoom.disable(); } );
            L.tileLayer( 'https://{s}.basemaps.cartocdn.com/light_nolabels/{z}/{x}/{y}{r}.png', {
                subdomains: 'abcd',
                maxZoom: 19,
                noWrap: true,
            } ).addTo( bfGeoMap );
            setTimeout( function () { if ( bfGeoMap ) bfGeoMap.invalidateSize(); }, 200 );
            setTimeout( function () { if ( bfGeoMap ) bfGeoMap.invalidateSize(); }, 1000 );
        } catch ( e ) {
            bfGeoMap = null;
        }
    }

    function renderApiAttackTable( apiLog, now ) {
        const containerId = 'cs-api-attack-log-wrap';
        let wrap = document.getElementById( containerId );
        if ( ! wrap ) {
            wrap = document.createElement( 'div' );
            wrap.id = containerId;
            wrap.style.cssText = 'margin-top:20px;';
            if ( bfLogWrap ) bfLogWrap.appendChild( wrap );
        }
        const rows = [ ...apiLog ].reverse().slice( 0, 50 );
        let html = `<div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em;">🔌 API Attack Log <span style="font-weight:400;color:#94a3b8;font-size:11px;">(${rows.length} event${rows.length !== 1 ? 's' : ''}, last 14 days)</span></div>`;
        html += `<table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead><tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                <th style="text-align:left;padding:5px 8px;color:#6b7280;font-weight:600;">Time</th>
                <th style="text-align:left;padding:5px 8px;color:#6b7280;font-weight:600;">Event</th>
                <th style="text-align:left;padding:5px 8px;color:#6b7280;font-weight:600;">IP</th>
                <th style="text-align:left;padding:5px 8px;color:#6b7280;font-weight:600;">Country</th>
            </tr></thead><tbody>`;
        rows.forEach( function ( entry ) {
            const ts      = entry[0] * 1000;
            const title   = escHtml( entry[1] || '' );
            const ip      = escHtml( entry[2] || '—' );
            const cc      = escHtml( entry[3] || '—' );
            const age     = formatAge( Math.floor( ( now * 1000 - ts ) / 1000 ) );
            const isLock  = title.toLowerCase().includes( 'locked' );
            const rowBg   = isLock ? 'background:#fef2f2;' : '';
            const ipLink  = entry[2] ? `<a href="https://ipinfo.io/${encodeURIComponent(entry[2])}" target="_blank" rel="noopener" style="font-size:10px;padding:2px 6px;border:1px solid #cbd5e1;border-radius:4px;background:#f8fafc;color:#475569;text-decoration:none;margin-right:4px;">Whois</a>` : '';
            html += `<tr style="border-bottom:1px solid #f0f4f8;${rowBg}">
                <td style="padding:5px 8px;color:#94a3b8;white-space:nowrap;">${escHtml( age )}</td>
                <td style="padding:5px 8px;font-weight:${isLock ? '700' : '400'};color:${isLock ? '#991b1b' : '#374151'};">${title}</td>
                <td style="padding:5px 8px;">${ipLink}${ip}</td>
                <td style="padding:5px 8px;color:#64748b;">${cc}</td>
            </tr>`;
        } );
        html += `</tbody></table>`;
        wrap.innerHTML = html;
    }

    function renderBfGeoMap( bfByCountry, blockedByCountry, apiByCountry ) {
        initBfGeoMap();
        if ( ! bfGeoMap ) return;

        bfGeoMarkers.forEach( function ( m ) { bfGeoMap.removeLayer( m ); } );
        bfGeoMarkers = [];

        apiByCountry = apiByCountry || {};
        var bfMax      = Math.max( 1, ...Object.values( bfByCountry ) );
        var blockedMax = Math.max( 1, ...Object.values( blockedByCountry ) );
        var apiMax     = Math.max( 1, ...( Object.values( apiByCountry ).length ? Object.values( apiByCountry ) : [ 1 ] ) );

        // Collect all country codes present in any dataset.
        var allCCs = Object.keys( Object.assign( {}, bfByCountry, blockedByCountry, apiByCountry ) );

        allCCs.forEach( function ( cc ) {
            var coords = bfCountryCentroids[ cc ];
            if ( ! coords ) return;

            var bfCount      = bfByCountry[ cc ]      || 0;
            var blockedCount = blockedByCountry[ cc ] || 0;
            var apiCount     = apiByCountry[ cc ]     || 0;
            var name         = bfCountryNames[ cc ] || cc;
            var flag         = bfCountryFlag( cc );

            if ( blockedCount > 0 ) {
                var bRatio  = blockedCount / blockedMax;
                var bRadius = Math.max( 5, Math.min( 28, 5 + bRatio * 23 ) );
                var bMarker = L.circleMarker( coords, {
                    radius:      bRadius,
                    fillColor:   '#dc2626',
                    color:       '#991b1b',
                    weight:      1.5,
                    fillOpacity: 0.25 + bRatio * 0.45,
                    opacity:     0.8,
                } ).addTo( bfGeoMap );
                bMarker.bindTooltip(
                    '<strong>' + flag + name + '</strong><br>'
                    + '<span style="color:#dc2626;">🚫 ' + blockedCount.toLocaleString() + ' blocked probes</span>'
                    + ( bfCount > 0 ? '<br><span style="color:#d97706;">⚠ ' + bfCount.toLocaleString() + ' failed logins</span>' : '' ),
                    { direction: 'top', offset: [ 0, -bRadius ] }
                );
                bfGeoMarkers.push( bMarker );
            }

            if ( bfCount > 0 ) {
                var fRatio  = bfCount / bfMax;
                var fRadius = Math.max( 4, Math.min( 20, 4 + fRatio * 16 ) );
                var fMarker = L.circleMarker( coords, {
                    radius:      fRadius,
                    fillColor:   '#f59e0b',
                    color:       '#b45309',
                    weight:      1.5,
                    fillOpacity: 0.35 + fRatio * 0.45,
                    opacity:     0.9,
                } ).addTo( bfGeoMap );
                fMarker.bindTooltip(
                    '<strong>' + flag + name + '</strong><br>'
                    + '<span style="color:#d97706;">⚠ ' + bfCount.toLocaleString() + ' failed logins</span>'
                    + ( blockedCount > 0 ? '<br><span style="color:#dc2626;">🚫 ' + blockedCount.toLocaleString() + ' blocked probes</span>' : '' )
                    + ( apiCount > 0 ? '<br><span style="color:#7c3aed;">🔌 ' + apiCount.toLocaleString() + ' API attacks</span>' : '' ),
                    { direction: 'top', offset: [ 0, -fRadius ] }
                );
                bfGeoMarkers.push( fMarker );
            }

            if ( apiCount > 0 ) {
                var aRatio  = apiCount / apiMax;
                var aRadius = Math.max( 4, Math.min( 18, 4 + aRatio * 14 ) );
                var aMarker = L.circleMarker( coords, {
                    radius:      aRadius,
                    fillColor:   '#7c3aed',
                    color:       '#4c1d95',
                    weight:      1.5,
                    fillOpacity: 0.30 + aRatio * 0.45,
                    opacity:     0.9,
                } ).addTo( bfGeoMap );
                aMarker.bindTooltip(
                    '<strong>' + flag + name + '</strong><br>'
                    + '<span style="color:#7c3aed;">🔌 ' + apiCount.toLocaleString() + ' API attacks</span>'
                    + ( bfCount > 0 ? '<br><span style="color:#d97706;">⚠ ' + bfCount.toLocaleString() + ' failed logins</span>' : '' ),
                    { direction: 'top', offset: [ 0, -aRadius ] }
                );
                bfGeoMarkers.push( aMarker );
            }
        } );

        function fitBfMap() {
            if ( ! bfGeoMap || bfGeoMarkers.length === 0 ) return;
            var group  = L.featureGroup( bfGeoMarkers );
            var bounds = group.getBounds();
            if ( bounds.isValid() ) {
                bfGeoMap.fitBounds( bounds.pad( 0.25 ), { maxZoom: 4, animate: false } );
            }
        }
        setTimeout( function () { if ( bfGeoMap ) { bfGeoMap.invalidateSize(); fitBfMap(); } }, 200 );
        setTimeout( function () { if ( bfGeoMap ) { bfGeoMap.invalidateSize(); fitBfMap(); } }, 1000 );
    }

    // ── Randomise button — event delegation so it works whether the login
    //    panel was server-rendered or injected by the tab router. ──────────
    document.addEventListener( 'click', function ( e ) {
        if ( ! e.target.closest( '#cs-login-slug-random' ) ) return;
        const inp = document.getElementById( 'cs-login-slug' );
        if ( ! inp ) return;
        const bytes = new Uint8Array( 8 );
        crypto.getRandomValues( bytes );
        inp.value = Array.from( bytes, function ( b ) { return b.toString( 16 ).padStart( 2, '0' ); } ).join( '' );
        inp.dispatchEvent( new Event( 'input' ) );
    } );

} )();
