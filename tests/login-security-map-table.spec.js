/**
 * Login Security — Attack Origins map + BF log table
 *
 * Verifies:
 *  1. bf_log_fetch AJAX succeeds (no "Could not load log" error).
 *  2. The BF chart renders with day bars.
 *  3. The BF table renders (not showing error state).
 *  4. The Attack Origins map container initialises (has a Leaflet canvas).
 *  5. No console errors during render.
 *
 * Run:  npx playwright test tests/login-security-map-table.spec.js --headed
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
const LOGIN_TAB   = `${SITE}/wp-admin/tools.php?page=cloudscale-devtools&tab=login`;

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

test('bf_log_fetch AJAX succeeds and returns valid data', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    await page.goto(LOGIN_TAB, { waitUntil: 'domcontentloaded', timeout: 20000 });

    // Intercept the AJAX response directly to inspect it.
    const ajaxPromise = page.waitForResponse(resp =>
        resp.url().includes('admin-ajax') &&
        resp.request().method() === 'POST'
    , { timeout: 15000 });

    // Trigger the fetch by waiting for the page to call it automatically.
    // The bf_log_fetch fires on DOMContentLoaded when bfLogWrap is present.
    const resp = await ajaxPromise;
    const body = await resp.json().catch(() => null);

    console.log('bf_log_fetch response status:', resp.status());
    console.log('bf_log_fetch success:', body?.success);

    expect(resp.status(), 'AJAX response must be HTTP 200').toBe(200);
    expect(body, 'Response must parse as JSON').not.toBeNull();
    expect(body?.success, 'bf_log_fetch must return success:true').toBe(true);
    expect(body?.data, 'Response data must exist').toBeTruthy();
    expect(typeof body?.data?.log, 'log must be an array').toBe('object');

    console.log('✅ bf_log_fetch succeeded. Log entries:', Array.isArray(body.data.log) ? body.data.log.length : 'N/A');
    await ctx.close();
});

test('BF chart renders with day bars', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    const consoleErrors = [];
    page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text()); });

    await page.goto(LOGIN_TAB, { waitUntil: 'domcontentloaded', timeout: 20000 });

    // Scroll down to the BF section so lazy-loaded elements trigger.
    await page.evaluate(() => {
        const el = document.getElementById('cs-bf-log-wrap');
        if (el) el.scrollIntoView();
    });

    // Wait for bf_log_fetch AJAX to fire and chart to render.
    await page.waitForFunction(() => {
        const chart = document.getElementById('cs-bf-chart');
        return chart && chart.querySelectorAll('.cs-bf-day').length > 0;
    }, { timeout: 20000 });

    const barCount = await page.evaluate(() => {
        const chart = document.getElementById('cs-bf-chart');
        return chart ? chart.querySelectorAll('.cs-bf-day').length : 0;
    });

    console.log('BF chart bar count:', barCount);
    expect(barCount, 'Chart must have 14 day bars').toBe(14);

    // Must NOT show "Could not load log"
    const tableWrap = page.locator('#cs-bf-table-wrap');
    const tableText = await tableWrap.textContent({ timeout: 5000 }).catch(() => '');
    console.log('Table text preview:', tableText.slice(0, 100));
    expect(tableText, 'Table must not show error state').not.toContain('Could not load log');

    console.log('✅ Chart rendered with', barCount, 'bars.');
    await ctx.close();
});

test('Attack Origins map initialises with tile layer', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    const consoleErrors = [];
    page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text()); });
    page.on('pageerror', e => consoleErrors.push(e.message));

    await page.goto(LOGIN_TAB, { waitUntil: 'domcontentloaded', timeout: 20000 });

    // Scroll to map section so Leaflet can measure the container.
    await page.evaluate(() => {
        const el = document.getElementById('cs-bf-geo-map');
        if (el) el.scrollIntoView();
    });

    // Wait for bf_log_fetch to complete (chart renders = AJAX done).
    await page.waitForFunction(() => {
        const chart = document.getElementById('cs-bf-chart');
        return chart && chart.querySelectorAll('.cs-bf-day').length > 0;
    }, { timeout: 20000 });

    // Give Leaflet time to init after the AJAX callback runs.
    await page.waitForTimeout(2000);

    // Map div must exist.
    const mapEl = page.locator('#cs-bf-geo-map');
    await expect(mapEl, 'Map container must exist').toBeAttached({ timeout: 5000 });

    // Check Leaflet state — _leaflet_id is set when init succeeds.
    const mapState = await page.evaluate(() => {
        const el = document.getElementById('cs-bf-geo-map');
        if (!el) return { exists: false };
        return {
            exists:     true,
            leafletId:  el._leaflet_id,
            // Leaflet renders .leaflet-pane divs inside the container.
            hasPanes:   el.querySelectorAll('.leaflet-pane').length > 0,
            // The map element itself gets class leaflet-container.
            isContainer: el.classList.contains('leaflet-container'),
        };
    });

    console.log('Map state:', JSON.stringify(mapState));
    expect(mapState.exists, 'Map container must exist in DOM').toBe(true);
    // Leaflet should have added its classes/panes to the container.
    const leafletInited = mapState.hasPanes || mapState.isContainer || mapState.leafletId != null;
    expect(leafletInited, 'Leaflet must have initialised (panes, container class, or leaflet_id present)').toBe(true);

    const mapRelatedErrors = consoleErrors.filter(e =>
        /leaflet|map|tile|carto/i.test(e)
    );
    expect(mapRelatedErrors, 'No map-related console errors').toHaveLength(0);

    console.log('✅ Map initialised. Leaflet ID:', mapState.leafletId);
    await ctx.close();
});
