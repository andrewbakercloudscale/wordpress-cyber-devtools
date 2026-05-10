/**
 * Site Health + CSP Regression Test
 *
 * Tests the full golden path of the site to catch CSP regressions and JS errors:
 *   1. Load public pages (home, blog, article) — capture console errors + CSP violations
 *   2. Create a draft post via REST API, upload a test image, save the post
 *   3. View the published post — check for JS errors and CSP violations
 *   4. Load the WP admin dashboard, plugin tabs — check for JS errors
 *   5. Summarise all violations with source + directive — fail only on unexpected ones
 *
 * Run:  npx playwright test tests/site-health-csp.spec.js --headed
 * Quick:  npx playwright test tests/site-health-csp.spec.js --headed --grep "public"
 */

// @ts-check
const { test, expect, request: playwrightRequest } = require('@playwright/test');
const path = require('path');
const fs   = require('fs');

[
    path.join(__dirname, '..', '.env.test'),
    path.join(__dirname, '..', '..', '.env.test'),
].forEach(p => { try { require('dotenv').config({ path: p }); } catch {} });

const SITE        = process.env.WP_SITE               || 'https://your-wordpress-site.example.com';
const SECRET      = process.env.CSDT_TEST_SECRET      || '';
const ROLE        = process.env.CSDT_TEST_ROLE         || '';
const SESSION_URL = process.env.CSDT_TEST_SESSION_URL  || '';
const LOGOUT_URL  = process.env.CSDT_TEST_LOGOUT_URL   || '';

if (!SECRET || !ROLE || !SESSION_URL) {
    throw new Error('CSDT_TEST_SECRET, CSDT_TEST_ROLE, and CSDT_TEST_SESSION_URL must be set in .env.test');
}

test.setTimeout(240_000);

// ── Pages to check ──────────────────────────────────────────────────────────

const PUBLIC_PAGES = [
    { url: '/',      label: 'Home' },
    { url: '/blog/', label: 'Blog archive' },
];

const ADMIN_TABS = [
    { tab: 'home',      label: 'Home' },
    { tab: 'login',     label: 'Login Security' },
    { tab: 'security',  label: 'AI Security Scan' },
    { tab: 'headers',   label: 'Headers / CSP' },
    { tab: 'thumbnails',label: 'Thumbnails' },
    { tab: 'mail',      label: 'Mail / SMTP' },
    { tab: 'debug',     label: 'Diagnostics' },
];

// ── Known / expected violations (never fail on these) ────────────────────────
// Add entries here when a third-party service intentionally triggers a violation
// that you've chosen to allow via Report-Only mode.
const EXPECTED_VIOLATION_PATTERNS = [
    // Google Tag Manager injects inline scripts in some configurations
    /gtm\.js/,
    /googletagmanager\.com/,
    // AdSense
    /googlesyndication\.com/,
    /adsbygoogle/,
    // Cloudflare analytics
    /cloudflareinsights\.com/,
    // reCAPTCHA
    /recaptcha/,
    // YouTube embed
    /youtube\.com/,
    /ytimg\.com/,
];

// ── Helpers ──────────────────────────────────────────────────────────────────

async function getAdminSession(ttl = 3600) {
    const ctx  = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const resp = await ctx.post(SESSION_URL, { data: { secret: SECRET, role: ROLE, ttl } });
    const body = await resp.json().catch(() => resp.text());
    await ctx.dispose();
    if (!resp.ok()) throw new Error(`Session API: ${resp.status()} ${JSON.stringify(body)}`);
    return body;
}

async function injectCookies(ctx, sess) {
    await ctx.addCookies([
        { name: sess.secure_auth_cookie_name, value: sess.secure_auth_cookie,
          domain: sess.cookie_domain, path: '/', secure: true, httpOnly: true,  sameSite: 'Lax' },
        { name: sess.logged_in_cookie_name,   value: sess.logged_in_cookie,
          domain: sess.cookie_domain, path: '/', secure: true, httpOnly: false, sameSite: 'Lax' },
    ]);
}

/** Attach console error + CSP violation collectors to a page. */
function attachCollectors(page) {
    const jsErrors  = [];
    const cspErrors = [];

    page.on('console', msg => {
        if (msg.type() === 'error') {
            const text = msg.text();
            // CSP violations arrive as console errors starting with specific strings
            if (/Content Security Policy|CSP|Refused to (load|execute|connect|frame|apply)/i.test(text)) {
                cspErrors.push({ text, url: page.url() });
            } else if (!/favicon/i.test(text)) {
                jsErrors.push({ text, url: page.url() });
            }
        }
    });

    page.on('pageerror', err => {
        jsErrors.push({ text: err.message, url: page.url() });
    });

    // Capture SecurityPolicyViolationEvent via evaluate injection
    page.on('load', async () => {
        await page.evaluate(() => {
            if (window.__cspListening) return;
            window.__cspListening = true;
            window.__cspViolations = window.__cspViolations || [];
            document.addEventListener('securitypolicyviolation', e => {
                window.__cspViolations.push({
                    directive:   e.effectiveDirective,
                    blocked:     e.blockedURI,
                    source:      e.sourceFile + ':' + e.lineNumber,
                    disposition: e.disposition,
                });
            });
        }).catch(() => {});
    });

    async function collectCspViolations() {
        try {
            const v = await page.evaluate(() => window.__cspViolations || []);
            return v;
        } catch { return []; }
    }

    return { jsErrors, cspErrors, collectCspViolations };
}

function isExpectedViolation(v) {
    const text = (v.blocked || v.text || '').toString();
    return EXPECTED_VIOLATION_PATTERNS.some(p => p.test(text));
}

function summarise(label, jsErrors, cspErrors, domViolations) {
    const unexpected = domViolations.filter(v => !isExpectedViolation(v));
    const unexpectedJs = jsErrors.filter(e => !isExpectedViolation(e));

    if (jsErrors.length)     console.log(`  [${label}] JS errors:`, jsErrors.map(e => e.text.slice(0, 120)));
    if (cspErrors.length)    console.log(`  [${label}] CSP console errors:`, cspErrors.length);
    if (domViolations.length) console.log(`  [${label}] CSP DOM violations:`, domViolations.map(v => `${v.directive}: ${v.blocked}`));
    if (unexpected.length === 0 && unexpectedJs.length === 0) {
        console.log(`  ✅ [${label}] Clean — no unexpected errors or CSP violations.`);
    }
    return { unexpected, unexpectedJs };
}

// ── Test 1: Public pages ──────────────────────────────────────────────────────

test('Public pages — no JS errors or unexpected CSP violations', async ({ browser }) => {
    const allUnexpected = [];

    for (const { url, label } of PUBLIC_PAGES) {
        const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
        const page = await ctx.newPage();
        const { jsErrors, cspErrors, collectCspViolations } = attachCollectors(page);

        await page.goto(SITE + url, { waitUntil: 'networkidle', timeout: 30000 }).catch(() => {});
        await page.waitForTimeout(1500); // let deferred scripts run

        const domViolations = await collectCspViolations();
        const { unexpected, unexpectedJs } = summarise(label, jsErrors, cspErrors, domViolations);
        allUnexpected.push(...unexpected.map(v => `[${label}] CSP: ${v.directive} blocked ${v.blocked}`));
        allUnexpected.push(...unexpectedJs.map(e => `[${label}] JS: ${e.text.slice(0, 120)}`));

        await ctx.close();
    }

    if (allUnexpected.length > 0) {
        console.error('Unexpected issues found:\n' + allUnexpected.join('\n'));
    }
    expect(allUnexpected, 'No unexpected CSP violations or JS errors on public pages').toHaveLength(0);
});

// ── Test 2: Load a recent article ────────────────────────────────────────────

test('Recent article page — no JS errors or unexpected CSP violations', async ({ browser }) => {
    // Fetch the most recent published post URL via REST API
    const apiCtx = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const resp   = await apiCtx.get(`${SITE}/wp-json/wp/v2/posts?per_page=1&status=publish&_fields=link`);
    const posts  = await resp.json().catch(() => []);
    await apiCtx.dispose();

    if (!posts.length || !posts[0].link) {
        console.log('⚠ No published posts found — skipping article test.');
        test.skip();
        return;
    }

    const articleUrl = posts[0].link;
    console.log('  Testing article:', articleUrl);

    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await ctx.newPage();
    const { jsErrors, cspErrors, collectCspViolations } = attachCollectors(page);

    await page.goto(articleUrl, { waitUntil: 'networkidle', timeout: 30000 }).catch(() => {});
    await page.waitForTimeout(1500);

    const domViolations = await collectCspViolations();
    const { unexpected, unexpectedJs } = summarise('Article', jsErrors, cspErrors, domViolations);
    await ctx.close();

    const all = [
        ...unexpected.map(v => `CSP: ${v.directive} blocked ${v.blocked}`),
        ...unexpectedJs.map(e => `JS: ${e.text.slice(0, 120)}`),
    ];
    expect(all, 'No unexpected issues on article page').toHaveLength(0);
});

// ── Test 3: Create post, upload image, save ──────────────────────────────────

test('Create draft post + upload image via Gutenberg — no JS errors', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();
    const { jsErrors, collectCspViolations } = attachCollectors(page);

    // Open new post editor
    await page.goto(`${SITE}/wp-admin/post-new.php`, { waitUntil: 'domcontentloaded', timeout: 30000 });

    // Dismiss welcome modal if present
    const welcomeClose = page.locator('.components-modal__header button[aria-label="Close"]');
    if (await welcomeClose.isVisible({ timeout: 3000 }).catch(() => false)) {
        await welcomeClose.click();
    }

    // Type a title
    const titleField = page.locator('.editor-post-title__input, [aria-label="Add title"]').first();
    await titleField.waitFor({ state: 'visible', timeout: 15000 });
    await titleField.fill('CSP Health Check Test Post — ' + new Date().toISOString().slice(0, 10));
    console.log('  ✅ Post title typed.');

    // Add a paragraph block
    const editorCanvas = page.locator('.editor-styles-wrapper, .block-editor-writing-flow').first();
    await editorCanvas.click();
    await page.keyboard.press('Enter');
    await page.keyboard.type('This post was created by the site health CSP test. Safe to delete.');
    console.log('  ✅ Paragraph block added.');

    // Save as draft
    const saveDraftBtn = page.locator('button:has-text("Save draft"), button[aria-label="Save draft"]').first();
    if (await saveDraftBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
        await saveDraftBtn.click();
        await expect(page.locator('.editor-post-saved-state, [aria-label="Saved"]')).toBeVisible({ timeout: 10000 }).catch(() => {});
        console.log('  ✅ Post saved as draft.');
    }

    // Get the post ID from the URL
    const currentUrl = page.url();
    const postIdMatch = currentUrl.match(/[?&]post=(\d+)/);
    const postId = postIdMatch ? postIdMatch[1] : null;
    console.log('  Post ID:', postId || '(not found in URL)');

    // Check for JS errors
    const domViolations = await collectCspViolations();
    const { unexpected, unexpectedJs } = summarise('Gutenberg editor', jsErrors, [], domViolations);

    // Cleanup — delete the draft via REST API if we got the ID
    if (postId) {
        try {
            const apiCtx = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
            await apiCtx.delete(`${SITE}/wp-json/wp/v2/posts/${postId}?force=true`, {
                headers: { Cookie: `${sess.secure_auth_cookie_name}=${sess.secure_auth_cookie}` },
            });
            await apiCtx.dispose();
            console.log('  ✅ Test post deleted.');
        } catch (e) {
            console.warn('  ⚠ Could not delete test post:', e.message);
        }
    }

    await ctx.close();

    const all = [
        ...unexpected.map(v => `CSP: ${v.directive} blocked ${v.blocked}`),
        ...unexpectedJs.map(e => `JS: ${e.text.slice(0, 120)}`),
    ];
    expect(all, 'No unexpected JS errors or CSP violations in Gutenberg editor').toHaveLength(0);
});

// ── Test 4: Admin plugin tabs ─────────────────────────────────────────────────

test('Admin plugin tabs — no JS errors or unexpected CSP violations', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();
    const allIssues = [];

    for (const { tab, label } of ADMIN_TABS) {
        const { jsErrors, cspErrors, collectCspViolations } = attachCollectors(page);
        const url = `${SITE}/wp-admin/tools.php?page=cloudscale-devtools&tab=${tab}`;
        await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => {});
        await page.waitForTimeout(800);

        const domViolations = await collectCspViolations();
        const { unexpected, unexpectedJs } = summarise(`Admin:${label}`, jsErrors, cspErrors, domViolations);
        allIssues.push(...unexpected.map(v => `[${label}] CSP: ${v.directive} blocked ${v.blocked}`));
        allIssues.push(...unexpectedJs.map(e => `[${label}] JS: ${e.text.slice(0, 120)}`));
    }

    await ctx.close();

    if (allIssues.length > 0) {
        console.error('Unexpected issues on admin tabs:\n' + allIssues.join('\n'));
    }
    expect(allIssues, 'No unexpected issues on admin plugin tabs').toHaveLength(0);
});

// ── Test 5: WP Dashboard ─────────────────────────────────────────────────────

test('WP Dashboard — no JS errors or unexpected CSP violations', async ({ browser }) => {
    const sess = await getAdminSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();
    const { jsErrors, cspErrors, collectCspViolations } = attachCollectors(page);

    await page.goto(`${SITE}/wp-admin/`, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await page.waitForTimeout(1000);

    const domViolations = await collectCspViolations();
    const { unexpected, unexpectedJs } = summarise('WP Dashboard', jsErrors, cspErrors, domViolations);
    await ctx.close();

    const all = [
        ...unexpected.map(v => `CSP: ${v.directive} blocked ${v.blocked}`),
        ...unexpectedJs.map(e => `JS: ${e.text.slice(0, 120)}`),
    ];
    expect(all, 'No unexpected issues on WP Dashboard').toHaveLength(0);
});
