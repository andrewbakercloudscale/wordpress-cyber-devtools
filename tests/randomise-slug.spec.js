/**
 * Randomise slug button — Playwright test
 * Tests both direct navigation and tab-router navigation to the login tab.
 *
 * Run: npx playwright test tests/randomise-slug.spec.js --headed
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
    throw new Error('CSDT_TEST_SECRET, CSDT_TEST_ROLE, and CSDT_TEST_SESSION_URL must be set');
}

const LOGIN_TAB = `${SITE}/wp-admin/tools.php?page=cloudscale-devtools&tab=login`;
const HOME_TAB  = `${SITE}/wp-admin/tools.php?page=cloudscale-devtools&tab=home`;

async function getAdminSession() {
    const ctx  = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const resp = await ctx.post(SESSION_URL, { data: { secret: SECRET, role: ROLE, ttl: 900 } });
    const body = await resp.json();
    await ctx.dispose();
    return body;
}

async function injectCookies(ctx, sess) {
    await ctx.addCookies([
        { name: sess.secure_auth_cookie_name, value: sess.secure_auth_cookie,  domain: sess.cookie_domain, path: '/', secure: true,  httpOnly: true,  sameSite: 'Lax' },
        { name: sess.logged_in_cookie_name,   value: sess.logged_in_cookie,    domain: sess.cookie_domain, path: '/', secure: true,  httpOnly: false, sameSite: 'Lax' },
    ]);
}

const HEX16 = /^[0-9a-f]{16}$/;

test.describe('Randomise slug button', () => {

    test('direct navigation — button fills a 16-char hex slug', async ({ browser }) => {
        const sess = await getAdminSession();
        const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
        await injectCookies(ctx, sess);
        const page = await ctx.newPage();

        await page.goto(LOGIN_TAB);
        await page.waitForSelector('#cs-login-slug');

        const before = await page.$eval('#cs-login-slug', el => el.value);
        await page.click('#cs-login-slug-random');
        await page.waitForTimeout(200);
        const after = await page.$eval('#cs-login-slug', el => el.value);

        console.log(`  before: "${before}"  →  after: "${after}"`);
        expect(after).toMatch(HEX16);
        expect(after).not.toBe(before);

        // URL preview updates — shown text is masked (bullets), real value in data-real attr
        const realUrl = await page.$eval('#cs-current-login-url-display', el => el.dataset.real || '');
        expect(realUrl).toContain(after);

        await ctx.close();
    });

    test('tab-router navigation — button fills a 16-char hex slug', async ({ browser }) => {
        const sess = await getAdminSession();
        const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
        await injectCookies(ctx, sess);
        const page = await ctx.newPage();

        // Land on home tab first so the login panel is NOT yet in the DOM
        await page.goto(HOME_TAB);
        await page.waitForSelector('#cs-tab-bar');

        // Click the Login tab via the tab router
        await page.click('a[href*="tab=login"]');
        await page.waitForSelector('#cs-login-slug', { timeout: 15000 });

        const before = await page.$eval('#cs-login-slug', el => el.value);
        await page.click('#cs-login-slug-random');
        await page.waitForTimeout(200);
        const after = await page.$eval('#cs-login-slug', el => el.value);

        console.log(`  before: "${before}"  →  after: "${after}"`);
        expect(after).toMatch(HEX16);
        expect(after).not.toBe(before);

        await ctx.close();
    });

});
