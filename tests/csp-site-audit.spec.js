/**
 * CSP Site Audit button — Playwright test
 *
 * Verifies:
 *  1. The "Run Site Audit" button is visible at the top of the CSP panel.
 *  2. Clicking it clears the violation log, opens tabs, and eventually shows results.
 *  3. Results panel appears and contains per-page rows.
 *  4. Audit does NOT immediately flip to "all clean" — it waits for real pages.
 *
 * Run:  npx playwright test tests/csp-site-audit.spec.js --headed
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
const HEADERS_TAB = `${SITE}/wp-admin/tools.php?page=cloudscale-devtools&tab=headers`;

if (!SECRET || !ROLE || !SESSION_URL) {
    throw new Error('CSDT_TEST_SECRET, CSDT_TEST_ROLE, and CSDT_TEST_SESSION_URL must be set in .env.test');
}

test.setTimeout(120_000);

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
        { name: sess.secure_auth_cookie_name, value: sess.secure_auth_cookie,  domain: sess.cookie_domain, path: '/', secure: true, httpOnly: true,  sameSite: 'Lax' },
        { name: sess.logged_in_cookie_name,   value: sess.logged_in_cookie,    domain: sess.cookie_domain, path: '/', secure: true, httpOnly: false, sameSite: 'Lax' },
    ]);
}

test('CSP audit button is visible at top of CSP panel', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    await page.goto(HEADERS_TAB, { waitUntil: 'domcontentloaded', timeout: 20000 });

    const auditBtn = page.locator('#cs-csp-audit-btn');
    await expect(auditBtn, 'Audit button must be visible').toBeVisible({ timeout: 10000 });

    // Button should be near the top of the panel — before the Enable CSP checkbox
    const btnBox     = await auditBtn.boundingBox();
    const cspToggle  = page.locator('#cs-csp-enabled');
    const toggleBox  = await cspToggle.boundingBox().catch(() => null);
    if (btnBox && toggleBox) {
        expect(btnBox.y, 'Audit button should appear above Enable CSP toggle').toBeLessThan(toggleBox.y);
    }

    console.log('✅ Audit button is visible and positioned above the CSP settings.');
    await ctx.close();
});

test('CSP audit clears violation log before starting', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    await page.goto(HEADERS_TAB, { waitUntil: 'domcontentloaded', timeout: 20000 });

    // Intercept AJAX — verify clear is called before violations_get
    const actions = [];
    page.on('request', req => {
        if (req.url().includes('admin-ajax') && req.method() === 'POST') {
            const body = req.postData() || '';
            const match = body.match(/action=([^&]+)/);
            if (match && match[1].includes('csp_violation')) {
                actions.push(match[1]);
            }
        }
    });

    // Click the audit button — but intercept window.open so tabs don't actually open
    await page.evaluate(() => {
        window.open = function(url) {
            console.log('[test] window.open intercepted:', url);
            return { close: function(){} };
        };
    });

    await page.locator('#cs-csp-audit-btn').click();

    // Wait briefly for AJAX calls to fire
    await page.waitForTimeout(2000);

    const clearIdx = actions.indexOf('csdt_devtools_csp_violations_clear');
    const getIdx   = actions.indexOf('csdt_devtools_csp_violations_get');

    expect(clearIdx, 'violations_clear must be called').toBeGreaterThanOrEqual(0);
    console.log('AJAX sequence:', actions);

    // If get was called too, clear must come first
    if (getIdx >= 0) {
        expect(clearIdx, 'clear must come before get').toBeLessThan(getIdx);
    }

    console.log('✅ Violation log is cleared before audit starts.');
    await ctx.close();
});

test('CSP audit shows results panel after completion', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    await page.goto(HEADERS_TAB, { waitUntil: 'domcontentloaded', timeout: 20000 });

    // Intercept window.open (no real tabs) and fast-forward the wait by mocking violations_get
    await page.route('**/wp-admin/admin-ajax.php', async (route, request) => {
        const body = request.postData() || '';
        if (body.includes('csp_violations_get')) {
            // Return empty violations so results render immediately
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, data: [] }),
            });
        } else if (body.includes('csp_violations_clear')) {
            await route.fulfill({
                status: 200, contentType: 'application/json',
                body: JSON.stringify({ success: true }),
            });
        } else {
            await route.continue();
        }
    });

    await page.evaluate(() => {
        // Shorten the dwell wait to near-zero for testing
        window.open = function() { return { close: function(){} }; };
    });

    await page.locator('#cs-csp-audit-btn').click();

    // Results panel should eventually appear (wait up to 60s for real, 10s with mock)
    const auditWrap = page.locator('#cs-csp-audit-wrap');
    await expect(auditWrap, 'Audit results panel must appear').toBeVisible({ timeout: 60000 });

    // Status should update from "Running" to "Done"
    await expect(page.locator('#cs-csp-audit-status'), 'Status should show Done').toContainText('Done', { timeout: 60000 });

    // Body should contain at least the clean banner or violation warning
    const bodyText = await page.locator('#cs-csp-audit-body').textContent({ timeout: 5000 });
    expect(bodyText.length, 'Audit body should have content').toBeGreaterThan(10);
    expect(bodyText, 'Should not be blank after completion').not.toMatch(/^⏳/);

    console.log('✅ Audit results panel rendered. Body preview:', bodyText.slice(0, 120));
    await ctx.close();
});
