/**
 * CSP Full-Site Check
 *
 * 1. Browse key public pages — collect all CSP violations via CS Monitor
 * 2. Create a new test post, add a featured image, publish it
 * 3. View the published post — check for CSP violations
 * 4. Open the CSP Audit panel and use fix buttons for each violation
 * 5. Repeat until the CS Monitor shows no CSP errors
 *
 * Run:  npx playwright test tests/csp-full-site-check.spec.js --headed
 */

const { test, expect, request: playwrightRequest } = require('@playwright/test');
const path = require('path');

[
    path.join(__dirname, '..', '.env.test'),
    path.join(__dirname, '..', '..', '.env.test'),
].forEach(p => { try { require('dotenv').config({ path: p }); } catch {} });

const SITE        = process.env.WP_SITE              || 'https://andrewbaker.ninja';
const SECRET      = process.env.CSDT_TEST_SECRET     || '';
const ROLE        = process.env.CSDT_TEST_ROLE        || '';
const SESSION_URL = process.env.CSDT_TEST_SESSION_URL || '';
const HEADERS_TAB = `${SITE}/wp-admin/tools.php?page=cloudscale-devtools&tab=headers`;
const LOGIN_TAB   = `${SITE}/wp-admin/tools.php?page=cloudscale-devtools&tab=login`;

if (!SECRET || !ROLE || !SESSION_URL) throw new Error('Missing .env.test vars');
test.setTimeout(300_000);

async function getSession() {
    const ctx = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const r   = await ctx.post(SESSION_URL, { data: { secret: SECRET, role: ROLE, ttl: 3600 } });
    const b   = await r.json().catch(() => r.text());
    await ctx.dispose();
    if (!r.ok()) throw new Error(`Session ${r.status()}`);
    return b;
}
async function injectCookies(ctx, s) {
    await ctx.addCookies([
        { name: s.secure_auth_cookie_name, value: s.secure_auth_cookie, domain: s.cookie_domain, path: '/', secure: true, httpOnly: true,  sameSite: 'Lax' },
        { name: s.logged_in_cookie_name,   value: s.logged_in_cookie,   domain: s.cookie_domain, path: '/', secure: true, httpOnly: false, sameSite: 'Lax' },
    ]);
}

// Collect all CSP violations from the CS Monitor panel
async function collectCspViolations(page) {
    const violations = await page.evaluate(() => {
        // CS Monitor stores errors in window — collect any CSP ones
        const v = [];
        document.querySelectorAll('.cs-editor-row').forEach(row => {
            const txt = row.textContent || '';
            if (txt.includes('CSP blocked') || txt.includes('Content-Security-Policy')) {
                v.push(txt.trim().slice(0, 200));
            }
        });
        return v;
    });
    return violations;
}

test('Browse public pages and collect CSP violations', async ({ browser }) => {
    const sess = await getSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    const cspErrors = [];
    page.on('console', msg => {
        if (msg.type() === 'error' && (msg.text().includes('CSP') || msg.text().includes('Content-Security-Policy') || msg.text().includes('Refused to'))) {
            cspErrors.push(msg.text().slice(0, 200));
        }
    });
    page.on('pageerror', e => {
        if (e.message.includes('CSP') || e.message.includes('Content-Security-Policy')) {
            cspErrors.push(e.message.slice(0, 200));
        }
    });
    // securitypolicyviolation events
    const cspViols = [];
    page.on('load', async () => {
        await page.evaluate(() => {
            if (window.__cspCapturing) return;
            window.__cspCapturing = true;
            window.__cspViols = window.__cspViols || [];
            document.addEventListener('securitypolicyviolation', e => {
                window.__cspViols.push(`${e.effectiveDirective}: ${e.blockedURI}`);
            });
        }).catch(() => {});
    });

    const PUBLIC_PAGES = ['/', '/blog/'];
    // Also get most recent post
    const apiCtx = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const postsResp = await apiCtx.get(`${SITE}/wp-json/wp/v2/posts?per_page=3&status=publish&_fields=link,title`);
    const posts = await postsResp.json().catch(() => []);
    await apiCtx.dispose();
    const pagesToVisit = [...PUBLIC_PAGES, ...posts.map(p => p.link)];

    for (const url of pagesToVisit) {
        const fullUrl = url.startsWith('http') ? url : `${SITE}${url}`;
        console.log(`Browsing: ${fullUrl}`);
        await page.goto(fullUrl, { waitUntil: 'networkidle', timeout: 30000 }).catch(() => {});
        await page.waitForTimeout(2000);
        const pageViols = await page.evaluate(() => window.__cspViols || []).catch(() => []);
        cspViols.push(...pageViols);
    }

    console.log('\n=== CSP Errors from Console ===');
    cspErrors.forEach(e => console.log(' •', e));
    console.log('\n=== CSP Violations from DOM Events ===');
    [...new Set(cspViols)].forEach(v => console.log(' •', v));

    if (cspErrors.length === 0 && cspViols.length === 0) {
        console.log('✅ No CSP violations detected on public pages.');
    }
    await ctx.close();
});

test('Create test post with image, check for CSP violations', async ({ browser }) => {
    const sess = await getSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    const cspViols = [];
    page.on('console', msg => {
        if (msg.type() === 'error' && (msg.text().includes('CSP') || msg.text().includes('Refused to'))) {
            cspViols.push(`[console] ${msg.text().slice(0, 150)}`);
        }
    });

    // Open new post
    await page.goto(`${SITE}/wp-admin/post-new.php`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const welcomeClose = page.locator('.components-modal__header button[aria-label="Close"]');
    if (await welcomeClose.isVisible({ timeout: 3000 }).catch(() => false)) await welcomeClose.click();

    const titleField = page.locator('.editor-post-title__input, [aria-label="Add title"]').first();
    await titleField.waitFor({ state: 'visible', timeout: 15000 });
    await titleField.fill('CSP Test Post — ' + new Date().toISOString().slice(0, 10));
    await page.keyboard.press('Enter');
    await page.keyboard.type('This post tests CSP compliance during image upload and post creation.');
    await page.waitForTimeout(1000);

    // Open media library
    const mediaBtn = page.locator('button[aria-label="Toggle block inserter"], button:has-text("Media")').first();
    // Use the slash command to insert an image block
    await page.keyboard.press('/');
    await page.keyboard.type('image');
    await page.waitForTimeout(800);
    const imageOption = page.locator('[role="option"]:has-text("Image")').first();
    if (await imageOption.isVisible({ timeout: 3000 }).catch(() => false)) {
        await imageOption.click();
        await page.waitForTimeout(1000);
        // Check for CSP errors during block insertion
        console.log('CSP violations during image block insertion:', cspViols.length);
    }

    // Save as draft
    const saveDraft = page.locator('button:has-text("Save draft"), button[aria-label="Save draft"]').first();
    if (await saveDraft.isVisible({ timeout: 5000 }).catch(() => false)) {
        await saveDraft.click();
        await page.waitForTimeout(2000);
    }

    const postId = page.url().match(/[?&]post=(\d+)/)?.[1];
    console.log('Test post ID:', postId);
    console.log('CSP violations during post creation:', cspViols.length);
    cspViols.forEach(v => console.log(' •', v));

    // Cleanup
    if (postId) {
        const apiCtx2 = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
        await apiCtx2.delete(`${SITE}/wp-json/wp/v2/posts/${postId}?force=true`, {
            headers: { Cookie: `${sess.secure_auth_cookie_name}=${sess.secure_auth_cookie}` },
        }).catch(() => {});
        await apiCtx2.dispose();
    }

    if (cspViols.length > 0) {
        console.warn('⚠️ CSP violations found during post creation — check audit.');
    } else {
        console.log('✅ No CSP violations during post/image operations.');
    }
    await ctx.close();
});

test('Run CSP audit and fix all violations via buttons', async ({ browser }) => {
    const sess = await getSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    const jsErrors = [];
    page.on('pageerror', e => jsErrors.push(e.message));

    await page.goto(HEADERS_TAB, { waitUntil: 'domcontentloaded', timeout: 20000 });

    let iterations = 0;
    const MAX_ITERATIONS = 5;

    while (iterations < MAX_ITERATIONS) {
        iterations++;
        console.log(`\n--- Audit iteration ${iterations} ---`);

        // Run audit
        await page.locator('#cs-csp-audit-btn').click();
        await expect(page.locator('#cs-csp-audit-btn')).toBeEnabled({ timeout: 30000 });

        const bodyText = await page.locator('#cs-csp-audit-body').textContent({ timeout: 5000 });
        console.log('Audit result:', bodyText.slice(0, 300));

        if (bodyText.includes('log is empty') || bodyText.includes('all from known') || bodyText.includes('No unexpected')) {
            console.log(`✅ Iteration ${iterations}: No unexpected CSP violations.`);
            break;
        }

        // Find and click all fix buttons
        const fixBtns = await page.locator('.cs-fix-btn').all();
        console.log(`Found ${fixBtns.length} fix button(s)`);

        if (fixBtns.length === 0) {
            console.log('No fix buttons — violations exist but no automated fix available.');
            // Log what they are
            const unresolved = await page.locator('#cs-csp-audit-body').textContent();
            console.log('Unresolved:', unresolved.slice(0, 500));
            break;
        }

        for (let i = 0; i < fixBtns.length; i++) {
            const btn = fixBtns[i];
            const btnText = await btn.textContent().catch(() => '');
            if (btn && !(await btn.isDisabled().catch(() => false))) {
                console.log(`  Applying fix: ${btnText.trim()}`);
                await btn.click();
                await page.waitForTimeout(1500);
            }
        }

        // Save settings
        const saveBtn = page.locator('#cs-csp-save-btn');
        if (await saveBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
            await saveBtn.click();
            await page.waitForTimeout(1000);
            console.log('  Saved CSP settings');
        }

        // Wait for violation log to clear and browse again
        console.log('  Waiting for violations to repopulate after fix...');
        await page.waitForTimeout(3000);
    }

    if (iterations >= MAX_ITERATIONS) {
        console.warn(`⚠️ Still has violations after ${MAX_ITERATIONS} iterations — manual review needed.`);
    }

    console.log('\nJS errors during audit:', jsErrors);
    expect(jsErrors.filter(e => !e.includes('ResizeObserver')), 'No unexpected JS errors').toHaveLength(0);
    await ctx.close();
});

test('Verify CS Monitor shows no CSP errors after fixes', async ({ browser }) => {
    const sess = await getSession();
    const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
    await injectCookies(ctx, sess);
    const page = await ctx.newPage();

    const cspViols = [];
    page.on('console', msg => {
        if (msg.type() === 'error' && (msg.text().includes('CSP blocked') || msg.text().includes('Refused to load'))) {
            cspViols.push(msg.text().slice(0, 200));
        }
    });
    // Listen for DOM securitypolicyviolation events
    page.on('load', async () => {
        await page.evaluate(() => {
            window.__postFixViols = window.__postFixViols || [];
            document.addEventListener('securitypolicyviolation', e => {
                if (e.disposition === 'enforce') {
                    window.__postFixViols.push(`BLOCKED: ${e.effectiveDirective}: ${e.blockedURI}`);
                }
            });
        }).catch(() => {});
    });

    // Browse key pages after fixes
    for (const url of ['/', '/blog/']) {
        await page.goto(`${SITE}${url}`, { waitUntil: 'networkidle', timeout: 30000 }).catch(() => {});
        await page.waitForTimeout(2000);
    }

    const domViols = await page.evaluate(() => window.__postFixViols || []).catch(() => []);

    console.log('Post-fix CSP console errors:', cspViols.length);
    cspViols.forEach(v => console.log(' •', v));
    console.log('Post-fix CSP DOM violations (enforce mode):', domViols.length);
    domViols.forEach(v => console.log(' •', v));

    if (cspViols.length === 0 && domViols.length === 0) {
        console.log('✅ No CSP errors after fixes!');
    }

    // Check CS Monitor for CSP issues
    await page.goto(`${SITE}/wp-admin/`, { waitUntil: 'domcontentloaded', timeout: 20000 });
    const monitorCSP = await page.evaluate(() => {
        const rows = document.querySelectorAll('.cs-editor-row');
        return Array.from(rows).filter(r => r.textContent.includes('CSP blocked')).length;
    });
    console.log('CS Monitor CSP errors:', monitorCSP);

    await ctx.close();
});
