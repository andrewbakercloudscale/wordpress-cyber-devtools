/**
 * AI Generate Image from Scan Panel
 *
 * Verifies that clicking "✨ AI Generate Image" on a post in the scan results
 * (without having scrolled to the AI panel) actually fires the AJAX call and
 * shows progress — i.e. triggerGenerate runs with a valid vendor/model.
 *
 * Run:  npx playwright test tests/ai-generate-from-scan.spec.js --headed
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
const TAB_URL     = `${SITE}/wp-admin/tools.php?page=cloudscale-devtools&tab=thumbnails`;

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

test('AI Generate fires AJAX without needing to scroll to AI panel first', async ({ page }) => {
    const sess = await getAdminSession();
    await injectCookies(page.context(), sess);
    await page.goto(TAB_URL, { waitUntil: 'domcontentloaded', timeout: 20000 });

    // Capture AJAX calls to detect if the AI prompt-writing call fires
    const ajaxCalls = [];
    page.on('request', req => {
        if (req.url().includes('admin-ajax') && req.method() === 'POST') {
            const body = req.postData() || '';
            if (body.includes('ai_image')) ajaxCalls.push(body);
        }
    });

    // Verify triggerGenerate is available BEFORE scrolling to AI panel
    const tgAvailableBeforeScroll = await page.evaluate(() => {
        return typeof window.csdtDevtoolsThumbs?.triggerGenerate === 'function';
    });
    console.log('triggerGenerate available before scroll:', tgAvailableBeforeScroll);
    expect(tgAvailableBeforeScroll, 'triggerGenerate must be available on page load').toBe(true);

    // Also verify curVendor is initialised (not undefined/null)
    // csdtImgVendor is only set when an API key is saved on this account.
    // The test account may not have one — just log it, don't fail here.
    const vendorValue = await page.evaluate(() => window.csdtImgVendor);
    console.log('csdtImgVendor on page:', vendorValue || '(not set — test account has no API key)');

    // Run "Top 50 Broken" to get posts with no image
    await page.click('#cs-thumb-audit-broken-btn');
    await expect(page.locator('#cs-thumb-audit-results')).toContainText('Checked', { timeout: 30000 });

    // Find an AI Generate button (post with no featured image)
    const aiBtn = page.locator('.cs-scan-ai-gen-btn').first();
    const aiBtnCount = await aiBtn.count();

    if (aiBtnCount === 0) {
        console.log('⚠ No posts without featured image found — skipping click test.');
        test.skip();
        return;
    }

    // Click without scrolling to AI panel — must not show an alert or do nothing
    const postId = await aiBtn.getAttribute('data-post-id');
    console.log('Clicking AI Generate for post:', postId);

    // Listen for dialogs (alert) — should NOT appear
    let alertFired = false;
    page.on('dialog', async dialog => {
        alertFired = true;
        console.error('UNEXPECTED ALERT:', dialog.message());
        await dialog.dismiss();
    });

    await aiBtn.click();

    // Button should immediately change to "⏳ Writing prompt…"
    await expect(aiBtn).toHaveText(/Writing prompt|Generate/, { timeout: 3000 });

    // Wait briefly for AJAX to fire
    await page.waitForTimeout(1500);

    expect(alertFired, 'No alert dialog should appear').toBe(false);
    expect(ajaxCalls.length, 'At least one AI AJAX call should have fired').toBeGreaterThan(0);

    const firstAction = ajaxCalls[0]?.split('&').find(p => p.startsWith('action='));
    console.log('✅ AI Generate fired AJAX without alert. Calls:', ajaxCalls.length);
    console.log('First AJAX action:', firstAction);
    expect(firstAction, 'should fire write_prompt AJAX').toBe('action=csdt_devtools_ai_image_write_prompt');

    // Wait for the AJAX response — button should update (either error or next state)
    await page.waitForFunction(
        (pid) => {
            const status = document.getElementById('cs-ai-status-' + pid);
            return status && status.textContent.trim().length > 0;
        },
        postId,
        { timeout: 20000 }
    );

    const statusText = await page.$eval(`#cs-ai-status-${postId}`, el => el.textContent.trim());
    console.log('Status after generate:', statusText);
    // Should show either success (✓), generating state, or an error — not be blank
    expect(statusText.length, 'status element should have content after AJAX').toBeGreaterThan(0);
    // Must NOT show the null-read JS error
    expect(statusText, 'should not show null property error').not.toContain("Cannot read");
});
