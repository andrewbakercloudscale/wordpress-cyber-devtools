/**
 * CSP Site Audit button — Playwright test
 *
 * Verifies:
 *  1. The "Run Site Audit" button is visible at the top of the CSP panel.
 *  2. Clicking it does NOT open new windows or navigate away.
 *  3. It calls csdt_devtools_csp_violations_get via AJAX.
 *  4. Results panel appears and shows meaningful content.
 *  5. No JS errors are thrown during the audit.
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

test.setTimeout(60_000);

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

test('CSP audit button is visible at top of panel', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    await page.goto(HEADERS_TAB, { waitUntil: 'domcontentloaded', timeout: 20000 });

    const auditBtn = page.locator('#cs-csp-audit-btn');
    await expect(auditBtn, 'Audit button must be visible').toBeVisible({ timeout: 10000 });

    // Button should appear before the Enable CSP checkbox (i.e. at the top of the panel).
    const btnBox    = await auditBtn.boundingBox();
    const cspToggle = page.locator('#cs-csp-enabled');
    const toggleBox = await cspToggle.boundingBox().catch(() => null);
    if (btnBox && toggleBox) {
        expect(btnBox.y, 'Audit button should be above the CSP enable checkbox').toBeLessThan(toggleBox.y);
    }

    console.log('✅ Audit button visible at top of panel.');
    await ctx.close();
});

test('CSP audit does NOT open new windows or navigate away', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    await page.goto(HEADERS_TAB, { waitUntil: 'domcontentloaded', timeout: 20000 });

    // Track any new pages opened.
    const newPages = [];
    ctx.on('page', p => newPages.push(p.url()));

    // Track navigation on the current page.
    const originalUrl = page.url();
    let navigatedAway = false;
    page.on('framenavigated', frame => {
        if (frame === page.mainFrame() && frame.url() !== originalUrl) {
            navigatedAway = true;
        }
    });

    await page.locator('#cs-csp-audit-btn').click();

    // Give it 3 seconds to do anything async.
    await page.waitForTimeout(3000);

    expect(navigatedAway, 'Page must NOT navigate away when audit button is clicked').toBe(false);
    expect(newPages.length, 'Audit must NOT open new windows or tabs').toBe(0);
    expect(page.url(), 'URL must remain on headers tab').toContain('tab=headers');

    console.log('✅ No navigation or new windows — audit stays on page.');
    await ctx.close();
});

test('CSP audit calls violations_get and shows results', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    await page.goto(HEADERS_TAB, { waitUntil: 'domcontentloaded', timeout: 20000 });

    // Intercept AJAX to verify violations_get is called.
    let violationsGetCalled = false;
    page.on('request', req => {
        if (req.url().includes('admin-ajax') && req.method() === 'POST') {
            const body = req.postData() || '';
            if (body.includes('csp_violations_get')) violationsGetCalled = true;
        }
    });

    // Collect JS errors.
    const jsErrors = [];
    page.on('pageerror', e => jsErrors.push(e.message));

    await page.locator('#cs-csp-audit-btn').click();

    // Wait for button to be re-enabled — that's the signal that the AJAX completed.
    await expect(page.locator('#cs-csp-audit-btn'), 'Button must be re-enabled after completion').toBeEnabled({ timeout: 15000 });

    // Results wrap should now be visible.
    const auditWrap = page.locator('#cs-csp-audit-wrap');
    await expect(auditWrap, 'Results panel must appear').toBeVisible({ timeout: 5000 });

    // Status should have updated.
    const status = page.locator('#cs-csp-audit-status');
    await expect(status, 'Status must show something').not.toBeEmpty({ timeout: 5000 });

    // Body must have content and NOT show the loading spinner.
    const bodyText = await page.locator('#cs-csp-audit-body').textContent({ timeout: 5000 });
    expect(bodyText.length, 'Audit body must have content').toBeGreaterThan(10);
    expect(bodyText, 'Must not show loading spinner after completion').not.toMatch(/^⏳/);

    // Button already checked above.

    console.log('violationsGetCalled:', violationsGetCalled);
    console.log('Status:', await status.textContent());
    console.log('Body preview:', bodyText.slice(0, 150));
    console.log('JS errors:', jsErrors);

    expect(violationsGetCalled, 'Must call csdt_devtools_csp_violations_get').toBe(true);
    expect(jsErrors, 'No JS errors during audit').toHaveLength(0);

    await ctx.close();
});

test('CSP audit fix buttons fire the apply_fix AJAX action', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    const jsErrors = [];
    page.on('pageerror', e => jsErrors.push(e.message));

    await page.goto(HEADERS_TAB, { waitUntil: 'domcontentloaded', timeout: 20000 });

    // Run the audit.
    await page.locator('#cs-csp-audit-btn').click();
    await expect(page.locator('#cs-csp-audit-btn'), 'Button re-enables after fetch').toBeEnabled({ timeout: 15000 });

    // If there are no violations, skip (nothing to fix).
    const bodyText = await page.locator('#cs-csp-audit-body').textContent();
    if (bodyText.includes('log is empty') || bodyText.includes('all from known')) {
        console.log('⏭ No unexpected violations — skipping fix button test.');
        await ctx.close();
        return;
    }

    // Find a fix button.
    const fixBtn = page.locator('.cs-fix-btn').first();
    const fixCount = await fixBtn.count();
    if (fixCount === 0) {
        console.log('⏭ No fix buttons rendered — skipping.');
        await ctx.close();
        return;
    }

    // Intercept the AJAX call to verify it fires (without actually applying the fix).
    let fixActionCalled = false;
    let fixPayload = '';
    await page.route('**/wp-admin/admin-ajax.php', async (route, request) => {
        const body = request.postData() || '';
        if (request.method() === 'POST' && body.includes('csdt_devtools_csp_apply_fix')) {
            fixActionCalled = true;
            fixPayload = body;
            // Fulfil with fake success so no real change is made.
            await route.fulfill({
                status: 200, contentType: 'application/json',
                body: JSON.stringify({ success: true, data: { already_applied: false } }),
            });
        } else {
            await route.continue();
        }
    });

    const btnText = await fixBtn.textContent();
    console.log('Clicking fix button:', btnText.trim());
    await fixBtn.click();

    // Give AJAX time to fire.
    await page.waitForTimeout(1500);

    console.log('fix action called:', fixActionCalled);
    console.log('fix payload:', fixPayload.slice(0, 200));

    expect(fixActionCalled, 'Fix button must call csdt_devtools_csp_apply_fix AJAX action').toBe(true);
    // FormData serialisation varies — just verify the action fired with some payload.
    expect(fixPayload.length, 'Fix payload must be non-empty').toBeGreaterThan(0);
    expect(jsErrors, 'No JS errors when clicking fix').toHaveLength(0);

    console.log('✅ Fix button fired AJAX correctly — no real change made (intercepted).');
    await ctx.close();
});
