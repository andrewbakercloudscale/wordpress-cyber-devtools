/* global csdtTestAccounts */
(function () {
    'use strict';

    var cfg        = (typeof csdtTestAccounts !== 'undefined') ? csdtTestAccounts : {};
    var ajaxUrl    = cfg.ajaxUrl    || '';
    var nonce      = cfg.nonce      || '';
    var sessionUrl = cfg.sessionUrl || '';
    var logoutUrl  = cfg.logoutUrl  || '';
    var secret     = cfg.secret     || '';
    var secretShown = false;

    function post(action, data, cb, errCb) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce',  nonce);
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        fetch(ajaxUrl, { method: 'POST', body: body })
            .then(function (r) {
                return r.text().then(function(txt) {
                    try { return JSON.parse(txt); } catch(e) {
                        throw new Error('Non-JSON response (HTTP ' + r.status + '): ' + txt.slice(0, 100));
                    }
                });
            })
            .then(cb)
            .catch(function (e) {
                console.error('[csdt-test-accounts]', e);
                if (errCb) { errCb(e); }
            });
    }

    function el(id) { return document.getElementById(id); }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function timeAgo(ts) {
        if (!ts) { return ''; }
        var diff = Math.floor(Date.now() / 1000) - ts;
        if (diff < 60)    { return 'just now'; }
        if (diff < 3600)  { return Math.floor(diff / 60) + 'm ago'; }
        if (diff < 86400) { return Math.floor(diff / 3600) + 'h ago'; }
        var d = new Date(ts * 1000);
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    function renderUsers(users) {
        var listEl = el('cs-pwr-users-list');
        if (!listEl) { return; }

        if (!users || !users.length) {
            listEl.innerHTML = '<p style="color:#9ca3af;font-size:13px;margin:0;">No test users yet - create one above.</p>';
            updateSnippet();
            return;
        }

        listEl.innerHTML = users.map(function (u) {
            var sessColor    = u.session_count > 0 ? '#d97706' : '#9ca3af';
            var sessLabel    = u.session_count === 1 ? '1 session' : u.session_count + ' sessions';
            var killDisabled = u.session_count === 0 ? ' disabled' : '';
            var loginStr     = u.last_login ? timeAgo(u.last_login) : '';
            var loginHtml    = loginStr ? '<span style="font-size:11px;color:#9ca3af;">Last login: ' + escHtml(loginStr) + '</span>' : '';
            return '<div class="cs-pwr-user-row" style="padding:8px 12px;margin-bottom:4px;background:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;">' +
                '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;">' +
                '<code style="font-size:12px;min-width:120px;">' + escHtml(u.name) + '</code>' +
                '<span style="font-size:12px;color:#6b7280;">' + escHtml(ucFirst(u.wp_role || 'administrator')) + '</span>' +
                '<span style="font-size:12px;color:#9ca3af;font-family:monospace;">' + escHtml(u.username) + '</span>' +
                '<span class="cs-pwr-sess-count" style="font-size:12px;color:' + sessColor + ';">' + escHtml(sessLabel) + '</span>' +
                loginHtml +
                '</div>' +
                '<div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;align-items:center;">' +
                '<button type="button" class="cs-btn-secondary cs-btn-sm cs-pwr-rename" data-name="' + escHtml(u.name) + '" data-user-id="' + escHtml(String(u.user_id || '')) + '" data-current-login="' + escHtml(u.username) + '" title="Change WordPress username">✏️ Rename</button>' +
                '<button type="button" class="cs-btn-secondary cs-btn-sm cs-pwr-kill-sessions" data-name="' + escHtml(u.name) + '"' + killDisabled + '>Kill Sessions</button>' +
                '<button type="button" class="cs-btn-secondary cs-btn-sm cs-pwr-delete" data-name="' + escHtml(u.name) + '" style="color:#dc2626;border-color:#fca5a5;">Delete User</button>' +
                '</div>' +
                '</div>';
        }).join('');

        wireUserButtons();
        updateSnippet(users);
    }

    function ucFirst(str) {
        if (!str) { return ''; }
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    function wireUserButtons() {
        document.querySelectorAll('.cs-pwr-kill-sessions').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var name = btn.dataset.name;
                btn.disabled = true;
                btn.textContent = '...';
                post('csdt_kill_test_sessions', { name: name }, function (res) {
                    if (res.success) {
                        renderUsers(res.data.users);
                    } else {
                        btn.disabled = false;
                        btn.textContent = 'Kill Sessions';
                        alert('Error: ' + (res.data || 'unknown'));
                    }
                });
            });
        });

        document.querySelectorAll('.cs-pwr-rename').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var userId       = btn.dataset.userId;
                var currentLogin = btn.dataset.currentLogin;
                var newLogin     = prompt('New username for this account (current: ' + currentLogin + '):', currentLogin);
                if (!newLogin || newLogin === currentLogin) { return; }
                newLogin = newLogin.trim();
                if (newLogin.length < 3) { alert('Username must be at least 3 characters.'); return; }
                btn.disabled = true;
                btn.textContent = '⏳';
                post('csdt_rename_test_user', { user_id: userId, new_login: newLogin }, function (res) {
                    btn.disabled = false;
                    btn.textContent = '✏️ Rename';
                    if (res.success) {
                        renderUsers();
                        // Reload to reflect the updated username in the user list.
                        post('csdt_playwright_role_list', {}, function (r2) {
                            if (r2.success) { renderUsers(r2.data.users); }
                        });
                        alert('Username changed from "' + res.data.old_login + '" to "' + res.data.new_login + '". Sessions have been killed — the account must re-authenticate.');
                    } else {
                        alert('Error: ' + (res.data || 'unknown'));
                    }
                });
            });
        });

        document.querySelectorAll('.cs-pwr-delete').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var name = btn.dataset.name;
                if (!confirm('Delete test user "' + name + '"? The WordPress user will be permanently removed.')) { return; }
                btn.disabled = true;
                btn.textContent = '...';
                post('csdt_playwright_role_delete', { name: name }, function (res) {
                    if (res.success) {
                        renderUsers(res.data.users);
                    } else {
                        btn.disabled = false;
                        btn.textContent = 'Delete User';
                        alert('Error: ' + (res.data || 'unknown'));
                    }
                });
            });
        });
    }

    function updateSnippet(users) {
        var snippetEl = el('cs-pwr-snippet');
        if (!snippetEl) { return; }

        var usersArr = users || (cfg.testUsers || []);
        var exampleName = usersArr.length ? usersArr[0].name : 'my_playwright';
        var displaySecret = secretShown ? secret : 'YOUR_CSDT_TEST_SECRET';

        snippetEl.textContent = [
            '# .env.test',
            'WP_SITE=' + (cfg.siteUrl || 'https://yoursite.com'),
            'CSDT_TEST_SECRET=' + displaySecret,
            'CSDT_TEST_ROLE=' + exampleName,
            'CSDT_TEST_SESSION_URL=' + sessionUrl,
            'CSDT_TEST_LOGOUT_URL=' + logoutUrl,
        ].join('\n');
    }

    function csdtTestAccountsInit() {
        var panel = el('cs-panel-test-accounts');
        if (!panel) { return; }

        /* Create user */
        var createBtn = el('cs-pwr-create');
        if (createBtn) {
            createBtn.addEventListener('click', function () {
                var name   = (el('cs-pwr-name')    || {}).value || '';
                var wpRole = (el('cs-pwr-wp-role') || {}).value || 'administrator';
                var msgEl  = el('cs-pwr-msg');

                if (msgEl) { msgEl.style.display = 'none'; }

                if (!name) {
                    if (msgEl) { msgEl.style.cssText = 'display:block;color:#dc2626;'; msgEl.textContent = 'Name is required.'; }
                    return;
                }

                createBtn.disabled = true;
                createBtn.textContent = '...';

                post('csdt_playwright_role_create', { name: name, wp_role: wpRole }, function (res) {
                    createBtn.disabled = false;
                    createBtn.textContent = '+ Create User';
                    if (res.success) {
                        if (el('cs-pwr-name')) { el('cs-pwr-name').value = ''; }
                        renderUsers(res.data.users);
                        if (msgEl) {
                            msgEl.style.cssText = 'display:block;color:#166534;';
                            msgEl.textContent = 'User "' + res.data.username + '" created with role ' + ucFirst(res.data.wp_role) + '.';
                        }
                    } else {
                        if (msgEl) { msgEl.style.cssText = 'display:block;color:#dc2626;'; msgEl.textContent = res.data || 'Error creating user.'; }
                    }
                });
            });
        }

        /* Show/hide secret */
        var showSecretBtn  = el('cs-pwr-secret-show');
        var secretDisplay  = el('cs-pwr-secret-display');
        if (showSecretBtn && secretDisplay) {
            showSecretBtn.addEventListener('click', function () {
                secretShown = !secretShown;
                secretDisplay.textContent = secretShown ? secret : '•'.repeat(secret.length);
                showSecretBtn.textContent = secretShown ? '🔒 Hide' : '👁 Show';
                updateSnippet();
            });
        }

        /* Copy secret */
        var copySecretBtn = el('cs-pwr-secret-copy');
        if (copySecretBtn) {
            copySecretBtn.addEventListener('click', function () {
                navigator.clipboard.writeText(secret).then(function () {
                    var orig = copySecretBtn.textContent;
                    copySecretBtn.textContent = '✓ Copied';
                    setTimeout(function () { copySecretBtn.textContent = orig; }, 1500);
                });
            });
        }

        /* Regenerate secret */
        var regenBtn = el('cs-pwr-secret-regen');
        if (regenBtn) {
            regenBtn.addEventListener('click', function () {
                if (!confirm('Regenerate the shared secret? All .env files using the current secret will need to be updated.')) { return; }
                post('csdt_regen_test_secret', {}, function (res) {
                    if (res.success) {
                        secret = res.data.secret;
                        secretShown = true;
                        if (secretDisplay) { secretDisplay.textContent = secret; }
                        if (showSecretBtn) { showSecretBtn.textContent = '🔒 Hide'; }
                        updateSnippet();
                    }
                });
            });
        }

        /* Show/hide Session URL and Logout URL */
        [ ['cs-ta-session-url-show', 'cs-ta-session-url-display'],
          ['cs-ta-logout-url-show',  'cs-ta-logout-url-display'] ].forEach(function (pair) {
            var btn  = el(pair[0]);
            var code = el(pair[1]);
            if (!btn || !code) return;
            var shown = false;
            btn.addEventListener('click', function () {
                shown = !shown;
                code.textContent = shown ? (code.dataset.real || '') : (code.dataset.masked || '');
                btn.textContent  = shown ? '🔒 Hide' : '👁 Show';
            });
        });

        /* Copy URL buttons */
        document.querySelectorAll('.cs-copy-url').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var url = btn.dataset.url || '';
                navigator.clipboard.writeText(url).then(function () {
                    var orig = btn.textContent;
                    btn.textContent = '✓ Copied';
                    setTimeout(function () { btn.textContent = orig; }, 1500);
                });
            });
        });

        /* Copy .env snippet */
        var copySnippetBtn = el('cs-pwr-copy-snippet');
        if (copySnippetBtn) {
            copySnippetBtn.addEventListener('click', function () {
                var snippetEl = el('cs-pwr-snippet');
                var text = snippetEl ? snippetEl.textContent : '';
                navigator.clipboard.writeText(text).then(function () {
                    copySnippetBtn.textContent = '✓ Copied';
                    setTimeout(function () { copySnippetBtn.textContent = '⎘ Copy'; }, 1500);
                });
            });
        }

        /* Wire up initially rendered user rows and snippet */
        wireUserButtons();
        updateSnippet();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', csdtTestAccountsInit );
    } else {
        csdtTestAccountsInit();
    }
}());
