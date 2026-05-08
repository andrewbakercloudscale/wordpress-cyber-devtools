/**
 * CS Monitor — toolbar flash on browser error
 *
 * Run:  npx playwright test tests/cs-monitor-flash.spec.js --headed
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

if (!SECRET || !ROLE || !SESSION_URL) {
    throw new Error('CSDT_TEST_SECRET, CSDT_TEST_ROLE, and CSDT_TEST_SESSION_URL must be set in .env.test');
}

async function getPostUrl() {
    const ctx  = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const resp = await ctx.get(`${SITE}/wp-json/wp/v2/posts?per_page=1&_fields=link`, { timeout: 10000 });
    const body = await resp.json();
    await ctx.dispose();
    if (!Array.isArray(body) || !body[0]) throw new Error('Could not fetch post from REST API');
    return body[0].link;
}

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

// Load a single post (where CS Monitor runs in front-end mode) and wait for it
async function gotoAndWaitForMonitor(page) {
    const postUrl = await getPostUrl();
    console.log('Testing on:', postUrl);
    await page.goto(postUrl, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await page.waitForSelector('#cs-perf-toggle', { state: 'attached', timeout: 10000 });
}

test.use({ actionTimeout: 10000 });

test.describe('CS Monitor toolbar flash', () => {
    test('post-init JS error triggers flash', async ({ page }) => {
        const sess = await getAdminSession();
        await injectCookies(page.context(), sess);
        await gotoAndWaitForMonitor(page);

        const before = await page.$eval('#cs-perf-toggle', el => el.classList.contains('cs-monitor-flash'));
        expect(before, 'flash should not be present before error').toBe(false);

        await page.evaluate(() => {
            window.dispatchEvent(new ErrorEvent('error', {
                message: 'Test error from Playwright',
                filename: 'playwright-test.js',
                lineno: 1, colno: 1,
                error: new Error('Test error from Playwright'),
                bubbles: true,
            }));
        });

        await expect(page.locator('#cs-perf-toggle')).toHaveClass(/cs-monitor-flash/, { timeout: 2000 });
        console.log('✅ Post-init JS error: flash applied.');
    });

    test('pre-init JS error (race condition) still triggers flash after DOMContentLoaded', async ({ page }) => {
        const sess = await getAdminSession();
        await injectCookies(page.context(), sess);

        // Fire the error via a microtask so it lands while the page is still loading,
        // before csdtPerfInit runs on DOMContentLoaded and assigns toggleBtn.
        await page.addInitScript(() => {
            Promise.resolve().then(() => {
                window.dispatchEvent(new ErrorEvent('error', {
                    message: 'Pre-init error from Playwright',
                    filename: 'pre-init-test.js',
                    lineno: 1, colno: 1,
                    error: new Error('Pre-init error'),
                    bubbles: true,
                }));
            });
        });

        await gotoAndWaitForMonitor(page);

        // After DOMContentLoaded the deferred flash queue should have fired
        await expect(page.locator('#cs-perf-toggle')).toHaveClass(/cs-monitor-flash/, { timeout: 3000 });
        console.log('✅ Pre-init JS error: flash applied after init.');
    });

    test('failed fetch (no status) shows "Timed out!" in Browser tab', async ({ page }) => {
        const sess = await getAdminSession();
        await injectCookies(page.context(), sess);

        // Abort the test URL at the network level so the CS Monitor's fetch wrapper
        // sees a real network error (TypeError) → logs type:'fail' with no status code.
        await page.route('**/pagead/ping**', route => route.abort('failed'));

        await gotoAndWaitForMonitor(page);

        // Trigger the fetch through the normal CS Monitor-wrapped window.fetch path
        await page.evaluate(() => {
            window.fetch('https://pagead2.googlesyndication.com/pagead/ping?playwright=1', { method: 'POST' })
                .catch(() => {});
        });

        // Force-open the panel and switch to Browser tab via JS (avoids click positioning issues)
        await page.evaluate(() => {
            var p = document.getElementById('cs-perf');
            if (p) { p.classList.remove('cs-perf-collapsed'); p.classList.add('cs-perf-open'); }
            var btn = document.querySelector('[data-tab="editor"]');
            if (btn) btn.click();
        });
        await page.waitForSelector('#cs-pp-editor-body', { state: 'attached', timeout: 5000 });

        await expect(page.locator('#cs-pp-editor-body')).toContainText('Timed out!', { timeout: 3000 });
        await expect(page.locator('#cs-perf-toggle')).toHaveClass(/cs-monitor-flash/, { timeout: 2000 });
        console.log('✅ "Timed out!" label shown and flash applied.');
    });
});
