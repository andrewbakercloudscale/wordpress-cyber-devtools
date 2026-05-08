/**
 * Thumbnails scan — platform compat warnings + filter buttons
 *
 * Verifies:
 *   1. "Scan Top 50 Posts" runs and returns results with view counts
 *   2. Portrait images (e.g. 1024×1536) no longer trigger a size warning
 *   3. Filter buttons (All / Warnings / Issues) show/hide rows correctly
 *
 * Run:  npx playwright test tests/thumbnails-scan.spec.js --headed
 */

const { test, expect, request: playwrightRequest } = require('@playwright/test');
const path = require('path');

[
    path.join(__dirname, '..', '.env.test'),
    path.join(__dirname, '..', '..', '.env.test'),
].forEach(p => { try { require('dotenv').config({ path: p }); } catch {} });

const SITE        = process.env.WP_SITE          || 'https://your-wordpress-site.example.com';
const SECRET      = process.env.CSDT_TEST_SECRET  || '';
const ROLE        = process.env.CSDT_TEST_ROLE     || '';
const SESSION_URL = process.env.CSDT_TEST_SESSION_URL || '';

if (!SECRET || !ROLE || !SESSION_URL) {
    throw new Error('CSDT_TEST_SECRET, CSDT_TEST_ROLE, and CSDT_TEST_SESSION_URL must be set in .env.test');
}

const TAB_URL = `${SITE}/wp-admin/tools.php?page=cloudscale-devtools&tab=thumbnails`;

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

test.describe('Thumbnails scan', () => {

    test('Scan Top 50: view counts shown, filter buttons present, portrait images pass', async ({ page }) => {
        const sess = await getAdminSession();
        await injectCookies(page.context(), sess);
        await page.goto(TAB_URL, { waitUntil: 'domcontentloaded', timeout: 20000 });

        // Hide any stale results from previous runs before clicking
        await page.evaluate(() => {
            const r = document.getElementById('cs-thumb-audit-results');
            if (r) r.innerHTML = '';
        });

        // Click "Scan Top 50 Posts"
        await page.click('#cs-thumb-audit-top-btn');

        // Wait for results — summary line appears
        await expect(page.locator('#cs-thumb-audit-results')).toContainText('Checked', { timeout: 30000 });

        // ── View counts ──────────────────────────────────────────────────────
        // At least one row should show a views badge
        const viewBadges = page.locator('#cs-thumb-audit-results').getByText(/views/);
        const viewCount  = await viewBadges.count();
        console.log('View count badges found:', viewCount);
        expect(viewCount, 'should show view counts on posts').toBeGreaterThan(0);

        // ── Filter buttons ───────────────────────────────────────────────────
        await expect(page.locator('.cs-scan-view-btn[data-filter="all"]')).toBeVisible();
        await expect(page.locator('.cs-scan-view-btn[data-filter="warn"]')).toBeVisible();
        await expect(page.locator('.cs-scan-view-btn[data-filter="fail"]')).toBeVisible();
        console.log('✅ Filter buttons present.');

        // ── Old false-positive message should be gone ─────────────────────────
        // Previously portrait images (1024×1536, max=1536≥1200, min=1024≥630) showed
        // "Below optimum 1200×630 — Fix will crop and resize to the ideal size"
        // even though they can crop cleanly. The new logic uses can_crop_landscape,
        // so that exact message should no longer appear in any scan result.
        const oldMsg = await page.locator('#cs-thumb-audit-results')
            .getByText(/Below optimum 1200.630 — Fix will crop and resize/).count();
        expect(oldMsg, 'old "crop and resize" false-positive message should be gone').toBe(0);
        console.log('✅ Old "Below optimum 1200×630 — Fix will crop and resize" message gone.');

        // ── Filter: Issues only ──────────────────────────────────────────────
        const issuesBtn = page.locator('.cs-scan-view-btn[data-filter="fail"]');
        await issuesBtn.click();

        // All visible rows should be fail status
        const allRows    = page.locator('[data-scan-status]');
        const visibleRows = page.locator('[data-scan-status]:visible');
        const failRows    = page.locator('[data-scan-status="fail"]:visible');
        const warnRows    = page.locator('[data-scan-status="warn"]:visible');

        const visibleCount = await visibleRows.count();
        const failCount    = await failRows.count();
        const warnCount    = await warnRows.count();
        const totalCount   = await allRows.count();

        console.log(`Filter "Issues": ${visibleCount} visible of ${totalCount} total, ${failCount} fail, ${warnCount} warn`);
        expect(warnCount, 'warning rows should be hidden when filtering by issues').toBe(0);
        if (failCount > 0) {
            expect(visibleCount, 'only fail rows should be visible').toBe(failCount);
        }
        console.log('✅ Issues filter hides warning rows.');

        // ── Filter: Warnings only ────────────────────────────────────────────
        await page.locator('.cs-scan-view-btn[data-filter="warn"]').click();
        const failAfterWarnFilter = await page.locator('[data-scan-status="fail"]:visible').count();
        expect(failAfterWarnFilter, 'issue rows should be hidden when filtering by warnings').toBe(0);
        console.log('✅ Warnings filter hides issue rows.');

        // ── Filter: All ──────────────────────────────────────────────────────
        await page.locator('.cs-scan-view-btn[data-filter="all"]').click();
        const afterAll = await page.locator('[data-scan-status]:visible').count();
        expect(afterAll, 'all rows should be visible after resetting filter').toBe(totalCount);
        console.log('✅ All filter restores all rows.');
    });

    test('Top 50 Broken button exists and runs', async ({ page }) => {
        const sess = await getAdminSession();
        await injectCookies(page.context(), sess);
        await page.goto(TAB_URL, { waitUntil: 'domcontentloaded', timeout: 20000 });

        await expect(page.locator('#cs-thumb-audit-broken-btn')).toBeVisible();
        await page.click('#cs-thumb-audit-broken-btn');
        await expect(page.locator('#cs-thumb-audit-results')).toContainText('Checked', { timeout: 30000 });

        // All results should be non-pass (broken only)
        const passRows = await page.locator('[data-scan-status="pass"]').count();
        expect(passRows, 'broken scan should return no passing rows').toBe(0);
        console.log('✅ Top 50 Broken returns only non-pass posts.');
    });

});
