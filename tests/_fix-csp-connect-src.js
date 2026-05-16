/**
 * One-shot Playwright script: read #cs-csp-custom, find any connect-src line
 * that is missing 'self', add it, and save. Prints what it found and changed.
 *
 * Run: node tests/_fix-csp-connect-src.js
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
const HEADERS_TAB = `${SITE}/wp-admin/tools.php?page=cloudscale-devtools&tab=headers`;

async function getAdminSession() {
    const ctx  = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const resp = await ctx.post(SESSION_URL, { data: { secret: SECRET, role: ROLE, ttl: 900 } });
    const body = await resp.json();
    await ctx.dispose();
    if (!resp.ok()) throw new Error(`Session API ${resp.status()}: ${JSON.stringify(body)}`);
    return body;
}

(async () => {
    const sess   = await getAdminSession();
    const browser = await chromium.launch({ headless: true });
    const ctx    = await browser.newContext({ ignoreHTTPSErrors: true });

    await ctx.addCookies([
        { name: sess.secure_auth_cookie_name, value: sess.secure_auth_cookie,  domain: sess.cookie_domain, path: '/', secure: true, httpOnly: true,  sameSite: 'Lax' },
        { name: sess.logged_in_cookie_name,   value: sess.logged_in_cookie,    domain: sess.cookie_domain, path: '/', secure: true, httpOnly: false, sameSite: 'Lax' },
    ]);

    const page = await ctx.newPage();
    await page.goto(HEADERS_TAB, { waitUntil: 'domcontentloaded', timeout: 30_000 });

    // Wait for CSP settings to load (the panel fires an AJAX load on init).
    await page.waitForFunction(() => {
        const el = document.getElementById('cs-csp-custom');
        return el !== null;
    }, { timeout: 15_000 });

    // Give the AJAX settings-load a moment to populate the textarea.
    await page.waitForTimeout(2500);

    const original = await page.$eval('#cs-csp-custom', el => el.value);
    console.log('--- Current #cs-csp-custom value ---');
    console.log(original || '(empty)');
    console.log('------------------------------------');

    // Check each line for connect-src without 'self'.
    const lines   = original.split('\n');
    let changed   = false;
    const fixed   = lines.map(line => {
        const trimmed = line.trim();
        if ( trimmed.startsWith('connect-src') && !trimmed.includes("'self'") ) {
            console.log(`\n⚠  Found connect-src line missing 'self':\n   ${trimmed}`);
            // Insert 'self' right after the directive name.
            const updated = trimmed.replace(/^connect-src\s*/, "connect-src 'self' ");
            console.log(`✅  Fixed to:\n   ${updated}`);
            changed = true;
            return updated;
        }
        return line;
    });

    if (!changed) {
        console.log("\n✅  No connect-src line is missing 'self'. No change needed.");
        console.log("    If the violation persists, a separate plugin or theme may be setting a raw Content-Security-Policy header that overrides the CSP panel.");
        await browser.close();
        return;
    }

    const newValue = fixed.join('\n');
    await page.$eval(
        '#cs-csp-custom',
        (el, val) => { el.value = val; el.dispatchEvent(new Event('input', { bubbles: true })); },
        newValue
    );

    // Click save.
    await page.click('#cs-csp-save-btn');
    await page.waitForSelector('#cs-csp-saved', { state: 'visible', timeout: 10_000 });
    console.log('\n✅  Saved successfully.');

    await browser.close();
})().catch(err => { console.error(err); process.exit(1); });
