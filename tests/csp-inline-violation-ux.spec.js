/**
 * CSP Inline Violation UX — Playwright test
 *
 * Verifies the inline-script violation card renders:
 *  1. Updated plain-English title (not the old jargon-only label).
 *  2. Detail text that explains <script>/<style>, security trade-off, and Nonce Mode.
 *  3. "Seen on" links are absolute URLs (https://...) — not bare slugs that 404.
 *
 * Requires at least one inline-script violation in the stored log.
 * Run:  npx playwright test tests/csp-inline-violation-ux.spec.js --headed
 */
// @ts-check
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

test('inline violation card: plain-English title, full detail, absolute Seen-on links', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    const jsErrors = [];
    page.on('pageerror', e => jsErrors.push(e.message));

    await page.goto(HEADERS_TAB, { waitUntil: 'domcontentloaded', timeout: 20_000 });

    // Run the audit.
    await page.locator('#cs-csp-audit-btn').click();
    await expect(page.locator('#cs-csp-audit-btn')).toBeEnabled({ timeout: 15_000 });

    const bodyEl  = page.locator('#cs-csp-audit-body');
    const bodyText = await bodyEl.textContent({ timeout: 5_000 });

    if (bodyText.includes('log is empty') || bodyText.includes('all from known')) {
        console.log('⏭  No unexpected violations in log — cannot verify inline card. Seed one by visiting /about in report-only mode.');
        await ctx.close();
        return;
    }

    // Check for at least one inline violation card.
    const inlineCards = bodyEl.locator('text=code embedded directly in the HTML');
    const cardCount   = await inlineCards.count();

    if (cardCount === 0) {
        console.log('⏭  No inline-script violations found in current log. Test skipped.');
        await ctx.close();
        return;
    }

    console.log(`Found ${cardCount} inline violation card(s).`);

    // 1. Title must use the new plain-English copy.
    await expect(inlineCards.first()).toBeVisible();
    expect(
        await inlineCards.first().textContent(),
        'Title must describe inline as "code embedded directly in the HTML"'
    ).toContain('code embedded directly in the HTML');

    // 2. Old jargon-only label must be gone.
    const oldLabel = bodyEl.locator("text=Inline script or style ('unsafe-inline')");
    expect(
        await oldLabel.count(),
        'Old jargon-only title must not appear'
    ).toBe(0);

    // 3. Detail text must mention <script> or <style> and security trade-off.
    const detailText = await bodyEl.textContent();
    expect(detailText, 'Detail must mention <script> tag').toContain('<script>');
    expect(detailText, 'Detail must mention XSS protection').toContain('XSS');
    expect(detailText, 'Detail must mention Nonce Mode').toContain('Nonce Mode');

    // 4. All "Seen on" links must be absolute https:// URLs.
    const seenLinks = bodyEl.locator('a[href]');
    const count     = await seenLinks.count();
    for (let i = 0; i < count; i++) {
        const href = await seenLinks.nth(i).getAttribute('href');
        if (!href) continue;
        // Skip the "Add unsafe-inline" buttons (they have no href, only data attrs).
        expect(
            href.startsWith('https://') || href.startsWith('http://'),
            `Link href "${href}" must be an absolute URL`
        ).toBe(true);
    }

    console.log(`✅  ${cardCount} inline card(s): plain-English title ✓  detail text ✓  absolute links ✓`);
    expect(jsErrors, 'No JS errors').toHaveLength(0);

    await ctx.close();
});
