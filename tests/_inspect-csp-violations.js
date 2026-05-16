/**
 * One-shot: dump raw connect-src violations from the CSP log.
 * Run: node tests/_inspect-csp-violations.js
 */
// @ts-check
const { chromium, request: playwrightRequest } = require('@playwright/test');
const path = require('path');

[
    path.join(__dirname, '..', '.env.test'),
    path.join(__dirname, '..', '..', '.env.test'),
].forEach(p => { try { require('dotenv').config({ path: p }); } catch {} });

const SITE        = process.env.WP_SITE              || '';
const SECRET      = process.env.CSDT_TEST_SECRET     || '';
const ROLE        = process.env.CSDT_TEST_ROLE        || '';
const SESSION_URL = process.env.CSDT_TEST_SESSION_URL || '';

async function getAdminSession() {
    const ctx  = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const resp = await ctx.post(SESSION_URL, { data: { secret: SECRET, role: ROLE, ttl: 900 } });
    const body = await resp.json();
    await ctx.dispose();
    return body;
}

(async () => {
    const sess    = await getAdminSession();
    const browser = await chromium.launch({ headless: true });
    const ctx     = await browser.newContext({ ignoreHTTPSErrors: true });

    await ctx.addCookies([
        { name: sess.secure_auth_cookie_name, value: sess.secure_auth_cookie,  domain: sess.cookie_domain, path: '/', secure: true, httpOnly: true,  sameSite: 'Lax' },
        { name: sess.logged_in_cookie_name,   value: sess.logged_in_cookie,    domain: sess.cookie_domain, path: '/', secure: true, httpOnly: false, sameSite: 'Lax' },
    ]);

    const page = await ctx.newPage();
    await page.goto(`${SITE}/wp-admin/tools.php?page=cloudscale-devtools&tab=headers`, {
        waitUntil: 'domcontentloaded', timeout: 30_000,
    });
    await page.waitForFunction(() => typeof window.csdtVulnScan !== 'undefined', { timeout: 10_000 });
    const { nonce, ajaxUrl } = await page.evaluate(() => ({
        nonce:   window.csdtVulnScan.nonce,
        ajaxUrl: window.csdtVulnScan.ajaxUrl,
    }));

    const resp = await page.request.post(ajaxUrl, {
        form: { action: 'csdt_devtools_csp_violations_get', nonce },
    });
    const data = await resp.json();
    const violations = data.data || [];

    const relevant = violations.filter(v =>
        JSON.stringify(v).toLowerCase().includes('connect') ||
        JSON.stringify(v).toLowerCase().includes('wp-json') ||
        JSON.stringify(v).toLowerCase().includes('analytics')
    );

    console.log(`Total violations: ${violations.length}, relevant: ${relevant.length}\n`);
    relevant.forEach((v, i) => {
        console.log(`--- Raw violation #${i + 1} ---`);
        console.log(JSON.stringify(v, null, 2));
        console.log('');
    });

    // Also show all keys present in first violation.
    if (violations.length) {
        console.log('Keys in violation object:', Object.keys(violations[0]).join(', '));
    }

    await browser.close();
})().catch(err => { console.error(err); process.exit(1); });
