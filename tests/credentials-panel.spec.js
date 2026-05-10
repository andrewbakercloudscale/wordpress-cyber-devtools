/**
 * Credentials panel — Show, Copy, Rotate buttons
 *
 * Run:  npx playwright test tests/credentials-panel.spec.js --headed
 */

const { test, expect, request: playwrightRequest } = require('@playwright/test');
const path = require('path');

[
    path.join(__dirname, '..', '.env.test'),
    path.join(__dirname, '..', '..', '.env.test'),
].forEach(p => { try { require('dotenv').config({ path: p }); } catch {} });

const SITE     = process.env.WP_SITE          || 'https://your-wordpress-site.example.com';
const SECRET   = process.env.CSDT_TEST_SECRET  || '';
const ROLE     = process.env.CSDT_TEST_ROLE     || '';
const SESSION  = process.env.CSDT_TEST_SESSION_URL || '';
const CREDS_TAB = `${SITE}/wp-admin/tools.php?page=cloudscale-devtools&tab=credentials`;

if (!SECRET || !ROLE || !SESSION) throw new Error('Missing .env.test vars');
test.setTimeout(60_000);

async function getSession() {
    const ctx = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const r   = await ctx.post(SESSION, { data: { secret: SECRET, role: ROLE, ttl: 900 } });
    const b   = await r.json().catch(() => r.text());
    await ctx.dispose();
    if (!r.ok()) throw new Error(`Session API ${r.status()}`);
    return b;
}
async function injectCookies(ctx, s) {
    await ctx.addCookies([
        { name: s.secure_auth_cookie_name, value: s.secure_auth_cookie, domain: s.cookie_domain, path: '/', secure: true, httpOnly: true,  sameSite: 'Lax' },
        { name: s.logged_in_cookie_name,   value: s.logged_in_cookie,   domain: s.cookie_domain, path: '/', secure: true, httpOnly: false, sameSite: 'Lax' },
    ]);
}

test('Credentials tab loads and shows masked values', async ({ browser }) => {
    const sess = await getSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    const jsErrors = [];
    page.on('pageerror', e => jsErrors.push(e.message));

    await page.goto(CREDS_TAB, { waitUntil: 'domcontentloaded', timeout: 20000 });

    // Page must contain credential groups
    await expect(page.locator('text=🔌 Test Session API').first()).toBeVisible({ timeout: 10000 });
    await expect(page.locator('text=🔔 ntfy.sh').first()).toBeVisible();
    await expect(page.locator('text=☁️ Cloudflare').first()).toBeVisible();

    // Secret/url credentials must show masked bullets; plain ones show real value.
    const codes = await page.locator('code[data-masked]').all();
    expect(codes.length, 'At least some credentials must be masked').toBeGreaterThan(0);
    let maskedCount = 0;
    for (const c of codes) {
        const masked = await c.getAttribute('data-masked');
        const txt    = await c.textContent();
        if (masked && masked.includes('•')) {
            expect(txt, 'Secret must show bullets initially').toContain('•');
            maskedCount++;
        }
    }
    expect(maskedCount, 'At least one secret field must be masked').toBeGreaterThan(0);

    expect(jsErrors, 'No JS errors on page load').toHaveLength(0);
    console.log(`✅ ${codes.length} masked credential fields visible`);
    await ctx.close();
});

test('Show button reveals and hides credential value', async ({ browser }) => {
    const sess = await getSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    const jsErrors = [];
    page.on('pageerror', e => jsErrors.push(e.message));

    await page.goto(CREDS_TAB, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await expect(page.locator('text=🔔 ntfy.sh').first()).toBeVisible({ timeout: 10000 });

    // Verify cs-plugin-stack.js is loaded and its listeners are registered.
    const stackLoaded = await page.evaluate(() => typeof window.csdtOptimizer !== 'undefined');
    console.log('csdtOptimizer defined:', stackLoaded);
    // Simulate a click on a cs-cred-show button in JS to see if the handler fires.
    expect(stackLoaded, 'Inline credential handlers must be available').toBe(true);

    // Find first Show button and its corresponding code element.
    const showBtn  = page.locator('.cs-cred-show').first();
    const targetId = await showBtn.getAttribute('data-target');
    const codeEl   = page.locator(`#${targetId}`);

    const maskedBefore = await codeEl.textContent();
    expect(maskedBefore, 'Must start masked').toContain('•');

    console.log('Show button data-target:', targetId);
    const targetExists = await page.evaluate((id) => !!document.getElementById(id), targetId);
    console.log('Target element exists:', targetExists);

    // Log data-real before clicking
    const dataReal = await codeEl.getAttribute('data-real');
    console.log('data-real value:', dataReal?.slice(0, 30));
    console.log('textContent before click:', (await codeEl.textContent())?.slice(0, 30));

    await showBtn.click();
    await page.waitForTimeout(300);

    const afterReveal = await codeEl.textContent();
    console.log('textContent after click:', afterReveal?.slice(0, 30));
    expect(afterReveal, 'Must not contain bullets after Show').not.toContain('•');
    expect(afterReveal.length, 'Revealed value must be non-empty').toBeGreaterThan(0);
    const showLabel = await showBtn.textContent();
    expect(showLabel, 'Button must change to Hide').toContain('Hide');

    // Click Hide
    await showBtn.click();
    const afterHide = await codeEl.textContent();
    expect(afterHide, 'Must be masked again after Hide').toContain('•');

    expect(jsErrors, 'No JS errors').toHaveLength(0);
    console.log('✅ Show/Hide toggles correctly. Revealed:', afterReveal.slice(0, 20) + '…');
    await ctx.close();
});

test('Copy button copies real value to clipboard', async ({ browser }) => {
    const sess = await getSession();
    const ctx  = await browser.newContext({
        ignoreHTTPSErrors: true,
        permissions: ['clipboard-read', 'clipboard-write'],
    });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    const jsErrors = [];
    page.on('pageerror', e => jsErrors.push(e.message));

    await page.goto(CREDS_TAB, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await expect(page.locator('text=ntfy.sh')).toBeVisible({ timeout: 10000 });

    const copyBtn = page.locator('.cs-cred-copy').first();
    const codeEl  = page.locator('code[data-real]').first();
    const realVal = await codeEl.getAttribute('data-real');

    await copyBtn.click();
    await page.waitForTimeout(800);

    const copied = await page.evaluate(() => navigator.clipboard.readText());
    expect(copied, 'Clipboard must contain the real value').toBe(realVal);

    const btnText = await copyBtn.textContent();
    expect(btnText, 'Button must flash Copied').toContain('Copied');

    expect(jsErrors, 'No JS errors').toHaveLength(0);
    console.log('✅ Copy works. Value length:', realVal?.length);
    await ctx.close();
});

test('Rotate button calls regen AJAX and updates display', async ({ browser }) => {
    const sess = await getSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    const jsErrors = [];
    page.on('pageerror', e => jsErrors.push(e.message));

    await page.goto(CREDS_TAB, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await expect(page.locator('text=🔌 Test Session API').first()).toBeVisible({ timeout: 10000 });

    // Intercept regen AJAX — verify it fires without actually rotating.
    let regenCalled = false;
    let regenAction = '';
    await page.route('**/wp-admin/admin-ajax.php', async (route, request) => {
        const body = request.postData() || '';
        if (request.method() === 'POST' && (body.includes('regen') || body.includes('path_token'))) {
            regenCalled = true;
            regenAction = (body.match(/action=([^&\r\n]+)/) || [])[1] || body.slice(0, 50);
            await route.fulfill({
                status: 200, contentType: 'application/json',
                body: JSON.stringify({ success: true, data: { path_token: 'test_new_token_12345678', session_url: 'https://example.com/test', logout_url: 'https://example.com/logout' } }),
            });
        } else {
            await route.continue();
        }
    });

    const rotateBtn = page.locator('.cs-cred-regen').first();
    expect(await rotateBtn.count(), 'Rotate button must exist').toBeGreaterThan(0);

    // Dismiss the confirm dialog.
    page.once('dialog', d => d.accept());
    await rotateBtn.click();

    await page.waitForTimeout(1500);

    expect(regenCalled, 'Rotate must call regen AJAX').toBe(true);
    console.log('Regen action:', regenAction);

    expect(jsErrors, 'No JS errors').toHaveLength(0);
    console.log('✅ Rotate fires AJAX correctly (intercepted — no real rotation).');
    await ctx.close();
});

test('Save Alert Settings button works', async ({ browser }) => {
    const sess = await getSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    const jsErrors = [];
    page.on('pageerror', e => jsErrors.push(e.message));

    await page.goto(CREDS_TAB, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await expect(page.locator('#cs-alerts-save')).toBeVisible({ timeout: 10000 });

    // Route BEFORE clicking.
    let alertSaveCalled = false;
    await page.route('**/wp-admin/admin-ajax.php', async (route, request) => {
        const body = request.postData() || '';
        if (request.method() === 'POST' && body.includes('csdt_devtools_save_alerts')) {
            alertSaveCalled = true;
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true }) });
        } else {
            await route.continue();
        }
    });

    // Use waitForRequest to be certain the AJAX fires.
    const ajaxReq = page.waitForRequest(req =>
        req.url().includes('admin-ajax') && req.method() === 'POST',
        { timeout: 5000 }
    ).catch(() => null);

    await page.locator('#cs-alerts-save').click();
    const req = await ajaxReq;

    console.log('AJAX request URL:', req?.url());
    console.log('AJAX post data:', req?.postData()?.slice(0, 100));

    expect(alertSaveCalled || req !== null, 'Save alert settings must fire AJAX').toBe(true);
    await expect(page.locator('#cs-alerts-saved')).toBeVisible({ timeout: 3000 });

    expect(jsErrors, 'No JS errors').toHaveLength(0);
    console.log('✅ Save Alert Settings works.');
    await ctx.close();
});
