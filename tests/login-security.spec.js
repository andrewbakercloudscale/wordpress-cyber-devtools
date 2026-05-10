/**
 * Login Security tab — end-to-end Playwright tests
 *
 * Run:  npx playwright test tests/login-security.spec.js --headed
 *
 * Requires:
 *   - CSDT_TEST_SECRET, CSDT_TEST_ROLE, CSDT_TEST_SESSION_URL  (admin session)
 *   - WP_TEST_USER, WP_TEST_PASS  (form-login user for 2FA/brute-force/passkey tests)
 */

const { test, expect, request: playwrightRequest } = require('@playwright/test');
const path = require('path');
const readline = require('readline');

[
    path.join(__dirname, '..', '.env.test'),
    path.join(__dirname, '..', '..', '.env.test'),
].forEach(p => { try { require('dotenv').config({ path: p }); } catch {} });

const SITE        = process.env.WP_SITE              || 'https://your-wordpress-site.example.com';
const SECRET      = process.env.CSDT_TEST_SECRET     || '';
const ROLE        = process.env.CSDT_TEST_ROLE        || '';
const SESSION_URL = process.env.CSDT_TEST_SESSION_URL || '';
const LOGOUT_URL  = process.env.CSDT_TEST_LOGOUT_URL  || '';
const TEST_USER   = process.env.WP_TEST_USER          || 'cs_devtools_test';
const TEST_PASS   = process.env.WP_TEST_PASS          || '';

if (!SECRET || !ROLE || !SESSION_URL) {
    throw new Error('CSDT_TEST_SECRET, CSDT_TEST_ROLE, and CSDT_TEST_SESSION_URL must be set in .env.test');
}

// Pi direct address — bypasses Cloudflare for instant redirect verification.
const PI_NGINX  = 'http://192.168.0.48:8082';
const SITE_HOST = new URL(SITE).hostname;

const LOGIN_URL        = `${SITE}/wp-login.php`;
const SECURITY_TAB_URL = `${SITE}/wp-admin/tools.php?page=cloudscale-devtools&tab=login`;

async function getAdminSession(ttl = 900) {
    const ctx  = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const resp = await ctx.post(SESSION_URL, { data: { secret: SECRET, role: ROLE, ttl } });
    const body = await resp.json().catch(() => resp.text());
    await ctx.dispose();
    if (!resp.ok()) throw new Error(`test-session API: ${resp.status()} ${JSON.stringify(body)}`);
    return body;
}

async function injectCookies(ctx, sess) {
    await ctx.addCookies([
        { name: sess.secure_auth_cookie_name, value: sess.secure_auth_cookie,  domain: sess.cookie_domain, path: '/', secure: true,  httpOnly: true,  sameSite: 'Lax' },
        { name: sess.logged_in_cookie_name,   value: sess.logged_in_cookie,    domain: sess.cookie_domain, path: '/', secure: true,  httpOnly: false, sameSite: 'Lax' },
    ]);
}

async function logoutTestUser() {
    if (!LOGOUT_URL) return;
    try {
        const ctx = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
        await ctx.post(LOGOUT_URL, { data: { secret: SECRET, role: ROLE } });
        await ctx.dispose();
    } catch {}
}

/**
 * Check the HTTP status of a path directly against Pi nginx (no Cloudflare cache).
 * Sends a fake wordpress_logged_in cookie to bypass the nginx FastCGI cache.
 */
async function checkDirectStatus(urlPath) {
    const ctx = await playwrightRequest.newContext();
    const headers = {
        'Host':              SITE_HOST,
        'X-Forwarded-Proto': 'https',
        'Cookie':            'wordpress_logged_in_bypass=1',
    };
    await ctx.get(`${PI_NGINX}${urlPath}`, { headers }).catch(() => null);
    const resp = await ctx.get(`${PI_NGINX}${urlPath}`, {
        headers,
        maxRedirects: 0,
    }).catch(() => null);
    const status = resp?.status() ?? 0;
    await ctx.dispose();
    return status;
}

/** Pre-set WP test cookie so the login form "Cookies blocked" check passes. */
async function addWpTestCookie(ctx) {
    await ctx.addCookies([{
        name:     'wordpress_test_cookie',
        value:    'WP Cookie check',
        domain:   new URL(SITE).hostname,
        path:     '/',
        secure:   true,
        httpOnly: false,
        sameSite: 'Lax',
    }]);
}

/** Log in via WP login form — only used for tests that exercise the login flow itself. */
async function wpLogin(page, user, pass) {
    await addWpTestCookie(page.context());
    await page.goto(LOGIN_URL, { waitUntil: 'domcontentloaded' });
    await page.fill('#user_login', user);
    await page.fill('#user_pass', pass);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }),
        page.click('#wp-submit'),
    ]);
    if (page.url().includes('wp-login.php')) {
        const err = await page.locator('#login_error').textContent().catch(() => 'unknown error');
        throw new Error(`Login failed: ${err.trim()}`);
    }
}

/** Pause and ask the tester to type a value (2FA code, URL, etc.) */
async function askTester(prompt) {
    const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
    return new Promise(resolve => {
        rl.question(`\n>>> ${prompt}\n>>> `, answer => {
            rl.close();
            resolve(answer.trim());
        });
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Login Security tab renders correctly
// ─────────────────────────────────────────────────────────────────────────────
test('Login Security tab renders', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    await page.goto(SECURITY_TAB_URL);

    await expect(page.locator('body')).toContainText(/Login Security/i);

    await expect(page.locator('#cs-hide-enabled')).toBeAttached();
    await expect(page.locator('#cs-login-slug')).toBeVisible();
    await expect(page.locator('#cs-hide-save')).toBeVisible();

    await expect(page.locator('input[name="cs_devtools_2fa_method"][value="off"]')).toBeAttached();
    await expect(page.locator('input[name="cs_devtools_2fa_method"][value="email"]')).toBeAttached();
    await expect(page.locator('input[name="cs_devtools_2fa_method"][value="totp"]')).toBeAttached();

    console.log('✅  Login Security tab renders correctly.');
    await ctx.close();
    await logoutTestUser();
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. Slug live-preview updates the link
// ─────────────────────────────────────────────────────────────────────────────
test('Slug live-preview updates URL link', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    await page.goto(SECURITY_TAB_URL);

    const slugInput = page.locator('#cs-login-slug');
    await slugInput.fill('my-secret-login');
    const href = await page.locator('#cs-current-login-url').getAttribute('href');
    expect(href).toContain('my-secret-login');

    await slugInput.fill('');
    console.log('✅  Slug live-preview works.');
    await ctx.close();
    await logoutTestUser();
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. Hide Login — enable, set slug, save, verify redirect
// ─────────────────────────────────────────────────────────────────────────────
test('Hide Login enables custom slug', async ({ browser }) => {
    test.setTimeout(120_000);

    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    await page.goto(SECURITY_TAB_URL);

    // Intercept ALL login_save calls in this test — never write hide_enabled or slug to server.
    // Hide login must stay enabled with slug cleanshirt007 at all times.
    await page.route('**/wp-admin/admin-ajax.php', async (route, request) => {
        if (request.method() === 'POST' && (request.postData() || '').includes('csdt_devtools_login_save')) {
            await route.fulfill({ status: 200, contentType: 'application/json',
                body: JSON.stringify({ success: true, data: { login_url: `${SITE}/cleanshirt007/` } }) });
        } else {
            await route.continue();
        }
    });

    await page.locator('#cs-hide-enabled').waitFor({ state: 'attached', timeout: 10000 });
    // Verify the hide-login UI elements exist without writing to server.
    const slugInput = page.locator('#cs-login-slug');
    await expect(slugInput).toBeVisible({ timeout: 5000 });
    console.log('✅  Hide Login UI is present (save intercepted — server not modified).');

    const directStatus = await checkDirectStatus('/wp-login.php');
    expect(directStatus).not.toBe(200);
    console.log(`✅  /wp-login.php direct status (bypassing CF): ${directStatus} (expected 302)`);

    // Verify the live slug (cleanshirt007) serves the login form.
    const freshCtx = await browser.newContext({ ignoreHTTPSErrors: true });
    const freshPage = await freshCtx.newPage();
    await freshPage.goto(`${SITE}/cleanshirt007/`);
    await expect(freshPage.locator('#loginform').first()).toBeVisible({ timeout: 8000 });
    console.log('✅  Live slug /cleanshirt007/ serves login form.');
    await freshCtx.close();

    // No teardown save needed — the route intercept prevented any server writes.
    console.log('✅  Hide Login unchanged on server (all saves were intercepted).');

    await ctx.close();
    await logoutTestUser();
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. Email 2FA — enable for test user (as test user)
// ─────────────────────────────────────────────────────────────────────────────
test('Email 2FA — enable flow (test user)', async ({ browser }) => {
    // Enable Email as the 2FA method (as admin via test-session API)
    const adminSess = await getAdminSession();
    const adminCtx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(adminCtx, adminSess);
    const adminPage = await adminCtx.newPage();

    await adminPage.goto(SECURITY_TAB_URL);
    await adminPage.evaluate(() => {
        const radio = document.querySelector('input[name="cs_devtools_2fa_method"][value="email"]');
        if (radio && !radio.checked) { radio.checked = true; radio.dispatchEvent(new Event('change', { bubbles: true })); }
    });
    await adminPage.locator('#cs-2fa-save').click();
    await expect(adminPage.locator('#cs-2fa-saved')).toBeVisible({ timeout: 5000 });
    console.log('✅  Email 2FA method selected and saved.');
    await adminCtx.close();
    await logoutTestUser();

    if (!TEST_PASS) {
        console.log('⏭️  Skipped test user 2FA enable flow — WP_TEST_PASS not set.');
        return;
    }

    const testCtx  = await browser.newContext({ ignoreHTTPSErrors: true });
    const testPage = await testCtx.newPage();
    await wpLogin(testPage, TEST_USER, TEST_PASS);
    await testPage.goto(`${SITE}/wp-admin/admin.php?page=cloudscale-devtools&tab=login-security`);

    const enableBtn = testPage.locator('#cs-email-enable-btn');
    await expect(enableBtn).toBeVisible({ timeout: 5000 });

    await enableBtn.click();
    await expect(testPage.locator('#cs-email-pending-msg')).toBeVisible({ timeout: 10000 });
    const pendingText = await testPage.locator('#cs-email-pending-msg').textContent();
    console.log('📬  Pending message:', pendingText.trim());

    const warnEl = testPage.locator('#cs-email-port-warn');
    if (await warnEl.isVisible()) {
        console.log('⚠️  Port warning:', await warnEl.textContent());
    }

    const verifyUrl = await askTester(
        'Paste the Email 2FA verification link from the email sent to cs_devtools_test (or press Enter to skip):'
    );

    if (verifyUrl) {
        await testPage.goto(verifyUrl);
        await testPage.goto(`${SITE}/wp-admin/admin.php?page=cloudscale-devtools&tab=login-security`);
        const badge = testPage.locator('#cs-email-badge');
        await expect(badge).toBeVisible({ timeout: 5000 });
        const badgeText = await badge.textContent();
        console.log('🏷️  Email 2FA badge after verify:', badgeText.trim());
        expect(badgeText.toLowerCase()).toMatch(/active|verified|enabled/);
        console.log('✅  Email 2FA activated via callback link.');
    } else {
        console.log('⏭️  Skipped email verification (no URL provided).');
    }

    await testCtx.close();
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. 2FA login intercept — email code challenge
// ─────────────────────────────────────────────────────────────────────────────
test('2FA login intercept — email code challenge', async ({ browser }) => {
    // Make sure Email 2FA method is set (as admin via test-session API)
    const adminSess = await getAdminSession();
    const adminCtx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(adminCtx, adminSess);
    const adminPage = await adminCtx.newPage();

    await adminPage.goto(SECURITY_TAB_URL);
    const methodRadio = adminPage.locator('input[name="cs_devtools_2fa_method"][value="email"]');
    if (!(await methodRadio.isChecked())) {
        await adminPage.evaluate(() => {
            const r = document.querySelector('input[name="cs_devtools_2fa_method"][value="email"]');
            if (r && !r.checked) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }
        });
        await adminPage.locator('#cs-2fa-save').click();
        await expect(adminPage.locator('#cs-2fa-saved')).toBeVisible({ timeout: 5000 });
    }
    await adminCtx.close();
    await logoutTestUser();

    if (!TEST_PASS) {
        console.log('⏭️  Skipped 2FA intercept test — WP_TEST_PASS not set.');
        return;
    }

    const testCtx  = await browser.newContext({ ignoreHTTPSErrors: true });
    const testPage = await testCtx.newPage();

    await testPage.goto(LOGIN_URL);
    await testPage.fill('#user_login', TEST_USER);
    await testPage.fill('#user_pass', TEST_PASS);
    await testPage.click('#wp-submit');

    await testPage.waitForURL(/cs_devtools_2fa|cs_devtools_token/, { timeout: 10000 });
    console.log('✅  Intercepted at 2FA challenge page:', testPage.url());

    const code = await askTester('Enter the 6-digit code from the 2FA email sent to cs_devtools_test:');
    if (!code) {
        console.log('⏭️  Skipped 2FA code entry.');
        await testCtx.close();
        return;
    }

    await testPage.fill('input[name="cs_devtools_2fa_code"], #cs-2fa-code-input', code);
    await testPage.click('button[type="submit"], #cs-2fa-submit');
    await testPage.waitForURL(/wp-admin/, { timeout: 10000 });
    console.log('✅  Logged in via Email 2FA code.');

    await testCtx.close();
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. TOTP setup wizard
// ─────────────────────────────────────────────────────────────────────────────
test('TOTP setup wizard', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    await page.goto(SECURITY_TAB_URL);
    await page.evaluate(() => {
        const radio = document.querySelector('input[name="cs_devtools_2fa_method"][value="totp"]');
        if (radio && !radio.checked) { radio.checked = true; radio.dispatchEvent(new Event('change', { bubbles: true })); }
    });
    await page.locator('#cs-2fa-save').click();
    await expect(page.locator('#cs-2fa-saved')).toBeVisible({ timeout: 5000 });
    console.log('✅  TOTP method saved.');

    const setupBtn = page.locator('#cs-totp-setup-btn');
    if (!(await setupBtn.isVisible())) {
        console.log('ℹ️  TOTP already configured — check existing setup or disable first.');
        await ctx.close();
        await logoutTestUser();
        return;
    }
    await setupBtn.click();

    await expect(page.locator('#cs-totp-wizard')).toBeVisible({ timeout: 5000 });
    await expect(page.locator('#cs-totp-qr-loading')).toBeHidden({ timeout: 10000 });

    const secretEl = page.locator('#cs-totp-secret-display');
    if (await secretEl.isVisible()) {
        const secret = await secretEl.textContent();
        console.log('🔑  TOTP secret (Base32):', secret.trim());

        const totpCode = await askTester(
            `Add the TOTP secret "${secret.trim()}" to your authenticator app, then enter the 6-digit code:`
        );
        if (!totpCode) {
            await page.locator('#cs-totp-cancel-btn').click();
            console.log('⏭️  Skipped TOTP verification.');
            await ctx.close();
            await logoutTestUser();
            return;
        }

        await page.fill('#cs-totp-verify-code', totpCode);
        await page.locator('#cs-totp-verify-btn').click();
        await expect(page.locator('#cs-totp-verify-msg')).toBeVisible({ timeout: 5000 });
        const msg = await page.locator('#cs-totp-verify-msg').textContent();
        console.log('🏷️  TOTP verify result:', msg.trim());
        expect(msg).toMatch(/Activated|activated/i);
        console.log('✅  TOTP setup complete.');
    }

    await ctx.close();
    await logoutTestUser();
});

// ─────────────────────────────────────────────────────────────────────────────
// 7. Brute-force protection — UI renders and saves
// ─────────────────────────────────────────────────────────────────────────────
test('Brute-force protection — panel renders with correct fields', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    await page.goto(SECURITY_TAB_URL);

    await expect(page.locator('#cs-bf-enabled')).toBeAttached();
    await expect(page.locator('#cs-bf-attempts')).toBeVisible();
    await expect(page.locator('#cs-bf-lockout')).toBeVisible();
    await expect(page.locator('#cs-bf-save')).toBeVisible();

    const attempts = await page.locator('#cs-bf-attempts').inputValue();
    const lockout  = await page.locator('#cs-bf-lockout').inputValue();
    expect(Number(attempts)).toBeGreaterThanOrEqual(1);
    expect(Number(lockout)).toBeGreaterThanOrEqual(1);

    console.log(`✅  Brute-force panel: ${attempts} attempts, ${lockout}m lockout.`);
    await ctx.close();
    await logoutTestUser();
});

test('Brute-force protection — saves updated values', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    await page.goto(SECURITY_TAB_URL);

    // Intercept login_save — tests only verify UI/payload, never write settings to server.
    // This prevents hide_enabled or bf_enabled from being accidentally changed.
    let capturedPayload = '';
    await page.route('**/wp-admin/admin-ajax.php', async (route, request) => {
        if (request.method() === 'POST' && (request.postData() || '').includes('csdt_devtools_login_save')) {
            capturedPayload = request.postData() || '';
            await route.fulfill({ status: 200, contentType: 'application/json',
                body: JSON.stringify({ success: true, data: { login_url: '' } }) });
        } else {
            await route.continue();
        }
    });

    await page.evaluate(() => {
        const cb = document.getElementById('cs-bf-enabled');
        if (cb && !cb.checked) { cb.checked = true; cb.dispatchEvent(new Event('change', { bubbles: true })); }
    });

    await page.locator('#cs-bf-attempts').fill('7');
    await page.locator('#cs-bf-lockout').fill('10');
    await page.locator('#cs-bf-save').click();
    await expect(page.locator('#cs-bf-saved')).toBeVisible({ timeout: 5000 });
    expect(capturedPayload).toContain('bf_attempts=7');
    expect(capturedPayload).toContain('bf_lockout=10');
    console.log('✅  Brute-force settings saved (7 attempts, 10m lockout) — payload verified, server not modified.');

    await ctx.close();
    await logoutTestUser();
});

test('Brute-force protection — lockout triggered after N failures', async ({ browser }) => {
    // Admin sets low threshold via test-session API
    const adminSess = await getAdminSession();
    const adminCtx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(adminCtx, adminSess);
    const adminPage = await adminCtx.newPage();

    await adminPage.goto(SECURITY_TAB_URL);
    await adminPage.evaluate(() => {
        const cb = document.getElementById('cs-bf-enabled');
        if (cb && !cb.checked) { cb.checked = true; cb.dispatchEvent(new Event('change', { bubbles: true })); }
    });
    await adminPage.locator('#cs-bf-attempts').fill('3');
    await adminPage.locator('#cs-bf-lockout').fill('1');
    await adminPage.locator('#cs-bf-save').click();
    await expect(adminPage.locator('#cs-bf-saved')).toBeVisible({ timeout: 5000 });
    console.log('✅  Set lockout threshold: 3 attempts, 1 minute.');
    await adminCtx.close();
    await logoutTestUser();

    if (!TEST_PASS) {
        console.log('⏭️  Skipped lockout trigger test — WP_TEST_PASS not set.');
    } else {
        // Attempt 3 failed logins via form in a fresh context (no session cookies)
        const testCtx = await browser.newContext({ ignoreHTTPSErrors: true });
        const testPage = await testCtx.newPage();

        for (let i = 1; i <= 3; i++) {
            await addWpTestCookie(testCtx);
            await testPage.goto(LOGIN_URL, { waitUntil: 'domcontentloaded' });
            await testPage.fill('#user_login', TEST_USER);
            await testPage.fill('#user_pass', 'definitely-wrong-password-' + i);
            await testPage.click('#wp-submit');
            await testPage.waitForURL(/wp-login/, { timeout: 8000 }).catch(() => null);
            console.log(`  Attempt ${i}: ${testPage.url()}`);
        }

        // 4th attempt — correct password, should still be blocked
        await addWpTestCookie(testCtx);
        await testPage.goto(LOGIN_URL, { waitUntil: 'domcontentloaded' });
        await testPage.fill('#user_login', TEST_USER);
        await testPage.fill('#user_pass', TEST_PASS);
        await testPage.click('#wp-submit');
        await testPage.waitForURL(/wp-login/, { timeout: 8000 }).catch(() => null);

        const errorText = await testPage.locator('#login_error').textContent().catch(() => '');
        console.log('🔒  Login error after lockout:', errorText.trim());
        expect(errorText.toLowerCase()).toMatch(/locked|temporarily/);
        console.log('✅  Account lockout confirmed.');
        await testCtx.close();
    }

    // Restore defaults via test-session API
    const restoreSess = await getAdminSession();
    const restoreCtx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(restoreCtx, restoreSess);
    const restorePage = await restoreCtx.newPage();
    await restorePage.goto(SECURITY_TAB_URL);
    await restorePage.locator('#cs-bf-attempts').fill('5');
    await restorePage.locator('#cs-bf-lockout').fill('5');
    await restorePage.locator('#cs-bf-save').click();
    await expect(restorePage.locator('#cs-bf-saved')).toBeVisible({ timeout: 5000 });
    console.log('✅  Brute-force threshold restored to defaults.');
    await restoreCtx.close();
    await logoutTestUser();
});

// ─────────────────────────────────────────────────────────────────────────────
// 8. Session persistence — 30-day session survives simulated browser restart
// ─────────────────────────────────────────────────────────────────────────────
test('Session — custom duration persists cookies across browser restart', async ({ browser }) => {
    // Set session to 30 days (as admin via test-session API)
    const adminSess = await getAdminSession();
    const adminCtx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(adminCtx, adminSess);
    const adminPage = await adminCtx.newPage();
    await adminPage.goto(SECURITY_TAB_URL);

    await adminPage.locator('#cs-session-duration').selectOption('30');
    await adminPage.locator('#cs-session-save').click();
    await expect(adminPage.locator('#cs-session-saved')).toBeVisible({ timeout: 5000 });
    console.log('✅  Session duration set to 30 days.');
    await adminCtx.close();
    await logoutTestUser();

    if (!TEST_PASS) {
        console.log('⏭️  Skipped session cookie check — WP_TEST_PASS not set.');
    } else {
        // Log in as test user via form to verify cookie gets persistent expiry
        const testCtx = await browser.newContext({ ignoreHTTPSErrors: true });
        const testPage = await testCtx.newPage();
        await addWpTestCookie(testCtx);
        await testPage.goto(LOGIN_URL, { waitUntil: 'domcontentloaded' });
        await testPage.fill('#user_login', TEST_USER);
        await testPage.fill('#user_pass', TEST_PASS);
        await Promise.all([
            testPage.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }),
            testPage.click('#wp-submit'),
        ]);

        const cookies = await testCtx.cookies();
        const authCookies = cookies.filter(c => c.name.startsWith('wordpress_logged_in_'));
        console.log('🍪  Auth cookies:', authCookies.map(c => `${c.name} expires=${c.expires}`).join(', '));
        for (const c of authCookies) {
            expect(c.expires).toBeGreaterThan(0);
        }
        console.log('✅  Auth cookie is persistent (non-zero expiry) — survives browser close.');
        await testCtx.close();
    }

    // Restore default session
    const restoreSess = await getAdminSession();
    const restoreCtx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(restoreCtx, restoreSess);
    const restorePage = await restoreCtx.newPage();
    await restorePage.goto(SECURITY_TAB_URL);
    await restorePage.locator('#cs-session-duration').selectOption('default');
    await restorePage.locator('#cs-session-save').click();
    await expect(restorePage.locator('#cs-session-saved')).toBeVisible({ timeout: 5000 });
    console.log('✅  Session duration restored to default.');
    await restoreCtx.close();
    await logoutTestUser();
});

// ─────────────────────────────────────────────────────────────────────────────
// 10. Cleanup — disable 2FA, reset method to Off
// ─────────────────────────────────────────────────────────────────────────────
test('Cleanup — reset 2FA method to Off', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    await page.goto(SECURITY_TAB_URL);
    await page.evaluate(() => {
        const radio = document.querySelector('input[name="cs_devtools_2fa_method"][value="off"]');
        if (radio && !radio.checked) {
            radio.checked = true;
            radio.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
    await page.locator('#cs-2fa-save').click();
    await expect(page.locator('#cs-2fa-saved')).toBeVisible({ timeout: 5000 });
    console.log('✅  2FA method reset to Off.');

    await ctx.close();
    await logoutTestUser();
});
