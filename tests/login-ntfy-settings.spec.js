/**
 * Login Security — ntfy alert settings
 *
 * Verifies:
 *  1. ntfy login-alert checkboxes are present on the Login Security tab.
 *  2. Toggling them and clicking Save sends csdt_devtools_login_save with the
 *     correct ntfy_login_valid_user / ntfy_login_invalid_user values.
 *  3. After save the checkboxes reflect the persisted state on page reload.
 *  4. Disabling Hide Login sends an AJAX save (downgrade detection coverage).
 *
 * Run:  npx playwright test tests/login-ntfy-settings.spec.js --headed
 */

const { test, expect, request: playwrightRequest } = require('@playwright/test');
const path = require('path');

[
    path.join(__dirname, '..', '.env.test'),
    path.join(__dirname, '..', '..', '.env.test'),
].forEach(p => { try { require('dotenv').config({ path: p }); } catch {} });

const SITE        = process.env.WP_SITE              || 'https://your-wordpress-site.example.com';
const SECRET      = process.env.CSDT_TEST_SECRET     || '';
const ROLE        = process.env.CSDT_TEST_ROLE        || '';
const SESSION_URL = process.env.CSDT_TEST_SESSION_URL || '';
const TAB_URL     = `${SITE}/wp-admin/tools.php?page=cloudscale-devtools&tab=login`;

if (!SECRET || !ROLE || !SESSION_URL) {
    throw new Error('CSDT_TEST_SECRET, CSDT_TEST_ROLE, and CSDT_TEST_SESSION_URL must be set in .env.test');
}

async function getAdminSession() {
    const ctx  = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const resp = await ctx.post(SESSION_URL, { data: { secret: SECRET, role: ROLE, ttl: 900 } });
    const body = await resp.json().catch(() => resp.text());
    await ctx.dispose();
    if (!resp.ok()) throw new Error(`Session API: ${resp.status()} ${JSON.stringify(body)}`);
    return body;
}

async function injectCookies(ctx, sess) {
    await ctx.addCookies([
        { name: sess.secure_auth_cookie_name, value: sess.secure_auth_cookie,  domain: sess.cookie_domain, path: '/', secure: true,  httpOnly: true,  sameSite: 'Lax' },
        { name: sess.logged_in_cookie_name,   value: sess.logged_in_cookie,    domain: sess.cookie_domain, path: '/', secure: true,  httpOnly: false, sameSite: 'Lax' },
    ]);
}

test.use({ actionTimeout: 15000 });

test('ntfy login-alert checkboxes are visible', async ({ page }) => {
    const sess = await getAdminSession();
    await injectCookies(page.context(), sess);
    await page.goto(TAB_URL, { waitUntil: 'domcontentloaded', timeout: 20000 });

    await expect(page.locator('#cs-ntfy-login-valid'),   'valid-user ntfy checkbox').toBeVisible();
    await expect(page.locator('#cs-ntfy-login-invalid'), 'invalid-user ntfy checkbox').toBeVisible();
});

test('save persists ntfy_login_valid_user and ntfy_login_invalid_user', async ({ page }) => {
    const sess = await getAdminSession();
    await injectCookies(page.context(), sess);
    await page.goto(TAB_URL, { waitUntil: 'domcontentloaded', timeout: 20000 });

    // Capture the AJAX save call.
    const savePromise = page.waitForRequest(req =>
        req.url().includes('admin-ajax') && req.method() === 'POST' &&
        (req.postData() || '').includes('csdt_devtools_login_save')
    );

    // Enable both checkboxes.
    const validCb   = page.locator('#cs-ntfy-login-valid');
    const invalidCb = page.locator('#cs-ntfy-login-invalid');
    if (!(await validCb.isChecked()))   await validCb.check();
    if (!(await invalidCb.isChecked())) await invalidCb.check();

    await page.locator('#cs-bf-save').click();
    const req = await savePromise;
    const body = req.postData() || '';

    expect(body, 'valid_user flag sent').toContain('ntfy_login_valid_user=1');
    expect(body, 'invalid_user flag sent').toContain('ntfy_login_invalid_user=1');

    // Wait for save confirmation.
    await expect(page.locator('#cs-bf-saved')).toBeVisible({ timeout: 5000 });
});

test('checkboxes reflect saved state after reload', async ({ page }) => {
    const sess = await getAdminSession();
    await injectCookies(page.context(), sess);

    // First: ensure both are checked and saved.
    await page.goto(TAB_URL, { waitUntil: 'domcontentloaded', timeout: 20000 });
    const validCb   = page.locator('#cs-ntfy-login-valid');
    const invalidCb = page.locator('#cs-ntfy-login-invalid');
    if (!(await validCb.isChecked()))   await validCb.check();
    if (!(await invalidCb.isChecked())) await invalidCb.check();
    await page.locator('#cs-bf-save').click();
    await expect(page.locator('#cs-bf-saved')).toBeVisible({ timeout: 5000 });

    // Reload and verify.
    await page.goto(TAB_URL, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await expect(page.locator('#cs-ntfy-login-valid'),   'valid persisted').toBeChecked();
    await expect(page.locator('#cs-ntfy-login-invalid'), 'invalid persisted').toBeChecked();
});

test('disabling hide-login would send hide_enabled=0 (request intercepted — never written to server)', async ({ page }) => {
    const sess = await getAdminSession();
    await injectCookies(page.context(), sess);
    await page.goto(TAB_URL, { waitUntil: 'domcontentloaded', timeout: 20000 });

    const hideToggle = page.locator('#cs-hide-enabled');

    // Abort the save AJAX call at the network level so the setting is NEVER written to the server.
    // We only want to inspect the request payload — hide login must stay enabled at all times.
    let capturedBody = '';
    await page.route('**/wp-admin/admin-ajax.php', async (route, request) => {
        const body = request.postData() || '';
        if (request.method() === 'POST' && body.includes('csdt_devtools_login_save')) {
            capturedBody = body;
            // Fulfil with a fake success so the JS doesn't show an error, but the DB is never touched.
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, data: { login_url: '' } }),
            });
        } else {
            await route.continue();
        }
    });

    // Uncheck by directly setting the DOM value — the checkbox is hidden behind a
    // .cs-toggle-switch span so Playwright's click would hit the wrong element.
    // We only need the payload value; the route intercept prevents any server write.
    await page.evaluate(() => {
        const cb = document.getElementById('cs-hide-enabled');
        if (cb) { cb.checked = false; }
    });
    await expect(hideToggle).not.toBeChecked({ timeout: 3000 });
    await page.locator('#cs-hide-save').click();

    // Wait until the interceptor captures the body.
    await page.waitForFunction(() => true); // flush microtasks
    await page.waitForTimeout(500);

    expect(capturedBody, 'hide_enabled=0 is in the intercepted payload').toContain('hide_enabled=0');
    console.log('✅ Confirmed hide_enabled=0 in payload — request was intercepted and never reached the server.');

    // Verify the server option was NOT changed by reading the WP option via admin-ajax.
    // We send a nonce-free read request — the login save endpoint returns the login_url
    // which will be the hidden slug URL only if hide_enabled is still 1 on the server.
    // Simplest reliable check: re-use the test account session to call WP REST options.
    await page.unrouteAll();
    const optionValue = await page.evaluate(async (ajaxUrl) => {
        // Hit the WP heartbeat endpoint just to confirm the session is alive.
        // Then read the option via a direct AJAX action the plugin exposes.
        const fd = new FormData();
        fd.append('action', 'heartbeat');
        fd.append('interval', '15');
        const r = await fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'include' });
        return r.ok ? 'alive' : 'dead';
    }, `${SITE}/wp-admin/admin-ajax.php`);
    expect(optionValue, 'WP session still valid').toBe('alive');

    // Read the option directly via WP-CLI through the shared SSH helper.
    const { execSync } = require('child_process');
    const WP_CLI = 'docker exec pi_wordpress php /var/www/html/wp-cli.phar';
    const PI_KEY = require('path').join(__dirname, '..', '..', 'pi-monitor', 'deploy', 'pi_key');
    const SSH = `ssh -i ${PI_KEY} -o StrictHostKeyChecking=no pi@andrew-pi-5.local`;
    let hideValue = '';
    try {
        hideValue = execSync(
            `${SSH} "${WP_CLI} option get csdt_devtools_login_hide_enabled --allow-root 2>/dev/null"`,
            { stdio: 'pipe', timeout: 10000 }
        ).toString().trim();
    } catch { hideValue = 'error'; }
    expect(hideValue, 'hide login must still be 1 on server').toBe('1');
    console.log('✅ Hide login confirmed still enabled on server (WP-CLI verified).');
});
