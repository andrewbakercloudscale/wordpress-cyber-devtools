/**
 * UX M4 — Save buttons show inline ✅ Saved feedback.
 *
 * Verifies:
 *   - Clicking a Save Settings button shows an inline saved indicator
 *   - The indicator text contains "Saved" (not just a checkmark)
 *   - The indicator disappears after ~2.5s
 *
 * Tests the AI settings save (cs-sec-save) as representative of all save buttons.
 *
 * Run: npx playwright test tests/ux-m4-save-feedback.spec.js
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
const LOGOUT_URL  = process.env.CSDT_TEST_LOGOUT_URL  || '';

if (!SECRET || !ROLE || !SESSION_URL) {
    throw new Error('CSDT_TEST_SECRET, CSDT_TEST_ROLE, and CSDT_TEST_SESSION_URL must be set in .env.test');
}

const PLUGIN_URL = `${SITE}/wp-admin/tools.php?page=cloudscale-devtools`;

async function getAdminSession() {
    const ctx  = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const resp = await ctx.post(SESSION_URL, { data: { secret: SECRET, role: ROLE, ttl: 900 } });
    const body = await resp.json().catch(() => resp.text());
    await ctx.dispose();
    if (!resp.ok()) throw new Error(`test-session API: ${resp.status()}`);
    return body;
}

async function injectCookies(ctx, sess) {
    await ctx.addCookies([
        { name: sess.secure_auth_cookie_name, value: sess.secure_auth_cookie,  domain: sess.cookie_domain, path: '/', secure: true,  httpOnly: true,  sameSite: 'Lax' },
        { name: sess.logged_in_cookie_name,   value: sess.logged_in_cookie,    domain: sess.cookie_domain, path: '/', secure: true,  httpOnly: false, sameSite: 'Lax' },
    ]);
}

test.describe.configure({ mode: 'serial' });

test.describe('M4 — Save button inline feedback', () => {

    let _sess;

    test.beforeAll(async () => {
        _sess = await getAdminSession(900);
    });

    test.afterAll(async () => {
        if (!LOGOUT_URL) return;
        try {
            const ctx = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
            await ctx.post(LOGOUT_URL, { data: { secret: SECRET, role: ROLE } });
            await ctx.dispose();
        } catch {}
    });

    test('AI settings Save button shows ✅ Saved indicator', async ({ browser }) => {
        const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
        await injectCookies(ctx, _sess);
        const page = await ctx.newPage();

        await page.goto(`${PLUGIN_URL}&tab=security`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#cs-sec-save', { timeout: 15000 });

        // Click Save Settings
        await page.locator('#cs-sec-save').click();

        // The saved indicator should become visible (opacity transition)
        const savedMsg = page.locator('#cs-sec-saved');
        await expect(savedMsg).toBeVisible({ timeout: 5000 });

        // Should say "Saved" (not just a checkmark)
        const text = await savedMsg.textContent();
        expect(text).toContain('Saved');

        await ctx.close();
    });

    test('Threat monitor Save button shows ✅ Saved indicator', async ({ browser }) => {
        const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
        await injectCookies(ctx, _sess);
        const page = await ctx.newPage();

        await page.goto(`${PLUGIN_URL}&tab=security`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#csdt-tm-save', { timeout: 15000 });

        await page.locator('#csdt-tm-save').click();

        const savedMsg = page.locator('#csdt-tm-saved');
        await expect(savedMsg).toBeVisible({ timeout: 5000 });

        const text = await savedMsg.textContent();
        expect(text).toContain('Saved');

        await ctx.close();
    });

    test('Login security Save button shows ✅ Saved indicator', async ({ browser }) => {
        const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
        await injectCookies(ctx, _sess);
        const page = await ctx.newPage();

        await page.goto(`${PLUGIN_URL}&tab=login`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#cs-hide-save', { timeout: 15000 });

        // Intercept the save — we only want to verify the UI indicator, not write to server.
        // Hide login must stay enabled with slug cleanshirt007 at all times.
        await page.route('**/wp-admin/admin-ajax.php', async (route, request) => {
            if (request.method() === 'POST' && (request.postData() || '').includes('csdt_devtools_login_save')) {
                await route.fulfill({ status: 200, contentType: 'application/json',
                    body: JSON.stringify({ success: true, data: { login_url: '' } }) });
            } else {
                await route.continue();
            }
        });

        await page.locator('#cs-hide-save').click();

        const savedMsg = page.locator('#cs-hide-saved');
        await expect(savedMsg).toBeVisible({ timeout: 5000 });

        const text = await savedMsg.textContent();
        expect(text).toContain('Saved');

        await ctx.close();
    });

    test('Saved indicator loses "visible" class after ~2.5s', async ({ browser }) => {
        const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
        await injectCookies(ctx, _sess);
        const page = await ctx.newPage();

        await page.goto(`${PLUGIN_URL}&tab=security`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#csdt-tm-save', { timeout: 15000 });

        await page.locator('#csdt-tm-save').click();

        const savedMsg = page.locator('#csdt-tm-saved');
        // First confirm it becomes visible
        await expect(savedMsg).toHaveClass(/visible/, { timeout: 5000 });

        // After the 5000ms timer fires and CSS transition completes, the "visible" class is removed
        await expect(savedMsg).not.toHaveClass(/visible/, { timeout: 7000 });

        await ctx.close();
    });
});
