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

test('disabling hide-login sends a save request (downgrade path)', async ({ page }) => {
    const sess = await getAdminSession();
    await injectCookies(page.context(), sess);
    await page.goto(TAB_URL, { waitUntil: 'domcontentloaded', timeout: 20000 });

    // The hide-login toggle is a visually-hidden checkbox inside a label — click the label.
    const hideLabel  = page.locator('label[for="cs-hide-enabled"], label:has(#cs-hide-enabled)').first();
    const hideToggle = page.locator('#cs-hide-enabled');
    const wasOn = await hideToggle.isChecked();

    // Only run downgrade test if hide-login is currently enabled.
    if (!wasOn) {
        console.log('⚠ Hide login is already off — enabling first then toggling off.');
        await hideLabel.click();
        await page.locator('#cs-hide-save').click();
        await expect(page.locator('#cs-hide-saved')).toBeVisible({ timeout: 5000 });
        await page.goto(TAB_URL, { waitUntil: 'domcontentloaded', timeout: 20000 });
    }

    const savePromise = page.waitForRequest(req =>
        req.url().includes('admin-ajax') && req.method() === 'POST' &&
        (req.postData() || '').includes('csdt_devtools_login_save')
    , { timeout: 10000 });

    // Uncheck via label click, then save.
    await page.locator('label:has(#cs-hide-enabled)').first().click();
    await expect(hideToggle).not.toBeChecked({ timeout: 3000 });
    await page.locator('#cs-hide-save').click();
    const req = await savePromise;
    expect(req.postData() || '', 'hide_enabled=0 sent').toContain('hide_enabled=0');

    // Re-enable hide login to leave the site secure.
    await page.locator('label:has(#cs-hide-enabled)').first().click();
    await expect(hideToggle).toBeChecked({ timeout: 3000 });
    await page.locator('#cs-hide-save').click();
    await expect(page.locator('#cs-hide-saved')).toBeVisible({ timeout: 5000 });
    console.log('✅ Hide login re-enabled after test.');
});
