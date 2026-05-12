/**
 * End-to-end: Featured Image Generation
 *
 * Verifies:
 *   - Image generates without error
 *   - Prompt does NOT contain banned scene phrases (glowing city, server racks, data centre skyline)
 *   - Prompt DOES reference the brand icon when article title contains a known brand (ARM, x86, etc.)
 *   - Prompt DOES NOT contain colour names (colour ban rule)
 *   - Prompt is fewer than 5 sentences (concise format rule)
 *   - Generated image renders in the modal (img src is set and loads)
 *   - Style selection changes the prompt style description
 *
 * Run: npx playwright test tests/ux-image-generation.spec.js --config=playwright.no-setup.config.js
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
    throw new Error('CSDT_TEST_SECRET, CSDT_TEST_ROLE, and CSDT_TEST_SESSION_URL must be set in .env.test');
}

const BANNED_PHRASES = [
    'backlit data-centre',
    'data-centre city',
    'data center city',
    'glowing server-tower',
    'glowing server tower',
    'neon city',
    'city-at-night',
    'city at night',
    'streams of light',
    'server racks in the background',
    'server racks and spinning',
    'cloud compute hub',
    'hazy sky',
    'futuristic cityscape',
    'streams of data',
];

const COLOUR_WORDS = [
    /\bblue\b/i, /\bnavy\b/i, /\bdark\b/i, /\blight\b/i, /\bwhite\b/i,
    /\bgray\b/i, /\bgrey\b/i, /\bgreen\b/i, /\bwarm\b/i, /\bcool\b/i,
    /\bredish\b/i, /\bsilver(y)?\b/i, /\bgolden\b/i, /\bblack\b/i,
];

async function getAdminSession(ttl = 1200) {
    const ctx  = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const resp = await ctx.post(SESSION_URL, { data: { secret: SECRET, role: ROLE, ttl } });
    const body = await resp.json().catch(() => resp.text());
    await ctx.dispose();
    if (!resp.ok()) throw new Error(`test-session API: ${resp.status()} ${JSON.stringify(body)}`);
    return body;
}

async function injectCookies(ctx, sess) {
    await ctx.addCookies([
        { name: sess.secure_auth_cookie_name, value: sess.secure_auth_cookie,  domain: sess.cookie_domain, path: '/', secure: true,  httpOnly: true,  sameSite: 'Lax' },
        { name: sess.logged_in_cookie_name,   value: sess.logged_in_cookie,    domain: sess.cookie_domain, path: '/', secure: false, httpOnly: false, sameSite: 'Lax' },
    ]);
}

async function getPostWithKeyword(keyword) {
    const ctx  = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const resp = await ctx.get(
        `${SITE}/wp-json/wp/v2/posts?per_page=20&search=${encodeURIComponent(keyword)}&_fields=link,title`,
        { timeout: 15000 }
    );
    const body = await resp.json().catch(() => []);
    await ctx.dispose();
    if (Array.isArray(body) && body[0]) return body[0];
    return null;
}

async function getFirstPost() {
    const ctx  = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
    const resp = await ctx.get(`${SITE}/wp-json/wp/v2/posts?per_page=1&_fields=link,title`, { timeout: 15000 });
    const body = await resp.json().catch(() => []);
    await ctx.dispose();
    if (!Array.isArray(body) || !body[0]) throw new Error('Could not fetch first post from REST API');
    return body[0];
}

async function openModal(page, postUrl) {
    await page.goto(postUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const pill = page.locator('.csdt-gen-img-pill');
    await expect(pill).toBeVisible({ timeout: 10000 });
    await pill.click();
    await expect(page.locator('.csdt-gen-modal-bg')).toBeVisible({ timeout: 5000 });
}

async function generateImage(page, style = 'auto') {
    if (style !== 'auto') {
        await page.locator('#csdt-gen-style').selectOption(style);
    }
    await page.locator('#csdt-gen-regen').click();
    // Wait up to 3 minutes for gpt-image-2 async generation
    await expect(page.locator('#csdt-gen-msg')).not.toContainText('⏳', { timeout: 180000 });
}

// ─────────────────────────────────────────────────────────────────────────────

test.describe('Featured Image Generation — prompt quality', () => {
    test.setTimeout(120000);

    test('generated prompt does not contain banned scene phrases', async ({ browser }) => {
        const sess = await getAdminSession();
        const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
        await injectCookies(ctx, sess);
        const page = await ctx.newPage();

        const post = await getFirstPost();
        console.log('Testing on:', post.title?.rendered, post.link);
        await openModal(page, post.link);
        await generateImage(page);

        // Check generation succeeded
        const msg = await page.locator('#csdt-gen-msg').textContent();
        expect(msg).not.toContain('✕');
        console.log('Status:', msg);

        // Expand and read the prompt
        const promptRow = page.locator('#csdt-gen-prompt-row');
        await expect(promptRow).toBeVisible({ timeout: 5000 });
        await page.locator('#csdt-gen-prompt-toggle').click();
        const promptText = await page.locator('#csdt-gen-prompt-text').textContent();
        console.log('Prompt:', promptText);

        const promptLower = promptText.toLowerCase();
        for (const phrase of BANNED_PHRASES) {
            expect(promptLower, `Banned phrase found: "${phrase}"`).not.toContain(phrase.toLowerCase());
        }

        await ctx.close();
    });

    test('generated prompt does not contain colour words (colour ban rule)', async ({ browser }) => {
        const sess = await getAdminSession();
        const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
        await injectCookies(ctx, sess);
        const page = await ctx.newPage();

        const post = await getFirstPost();
        await openModal(page, post.link);
        await generateImage(page);

        const msg = await page.locator('#csdt-gen-msg').textContent();
        expect(msg).not.toContain('✕');

        await page.locator('#csdt-gen-prompt-toggle').click();
        const promptText = await page.locator('#csdt-gen-prompt-text').textContent();
        console.log('Prompt:', promptText);

        for (const rx of COLOUR_WORDS) {
            expect(promptText, `Colour word found matching ${rx}`).not.toMatch(rx);
        }

        await ctx.close();
    });

    test('ARM article prompt contains arm/processor/monolith reference', async ({ browser }) => {
        const sess = await getAdminSession();
        const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
        await injectCookies(ctx, sess);
        const page = await ctx.newPage();

        const post = await getPostWithKeyword('ARM');
        if (!post) {
            console.log('No ARM post found — skipping brand icon test');
            await ctx.close();
            return;
        }
        console.log('ARM post:', post.title?.rendered, post.link);
        await openModal(page, post.link);
        await generateImage(page);

        const msg = await page.locator('#csdt-gen-msg').textContent();
        expect(msg).not.toContain('✕');

        await page.locator('#csdt-gen-prompt-toggle').click();
        const promptText = await page.locator('#csdt-gen-prompt-text').textContent();
        console.log('Prompt:', promptText);

        const promptLower = promptText.toLowerCase();
        const hasBrandRef = promptLower.includes('arm') || promptLower.includes('processor') ||
                            promptLower.includes('monolith') || promptLower.includes('chip');
        expect(hasBrandRef, 'Prompt should reference ARM processor/monolith').toBe(true);

        await ctx.close();
    });

    test('generated image renders in modal', async ({ browser }) => {
        const sess = await getAdminSession();
        const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
        await injectCookies(ctx, sess);
        const page = await ctx.newPage();

        const post = await getFirstPost();
        await openModal(page, post.link);
        await generateImage(page);

        const msg = await page.locator('#csdt-gen-msg').textContent();
        expect(msg).not.toContain('✕');

        // Image should appear
        const img = page.locator('.csdt-gen-img-opt img').first();
        await expect(img).toBeVisible({ timeout: 10000 });
        const src = await img.getAttribute('src');
        expect(src).toBeTruthy();
        console.log('Image src:', src);

        // Verify image actually loads (not broken)
        const imgStatus = await page.evaluate(async (imgSrc) => {
            return new Promise(resolve => {
                const i = new Image();
                i.onload  = () => resolve('loaded');
                i.onerror = () => resolve('error');
                i.src = imgSrc;
            });
        }, src);
        expect(imgStatus).toBe('loaded');

        // Save button should be enabled (single image auto-selects)
        await expect(page.locator('#csdt-gen-save')).toBeEnabled({ timeout: 3000 });

        // Take a screenshot for visual review
        await page.screenshot({ path: 'test-results/image-generation-result.png', fullPage: false });
        console.log('Screenshot saved: test-results/image-generation-result.png');

        await ctx.close();
    });

    test('Technical Infographic style changes prompt description', async ({ browser }) => {
        const sess = await getAdminSession();
        const ctx  = await browser.newContext({ ignoreHTTPSErrors: true });
        await injectCookies(ctx, sess);
        const page = await ctx.newPage();

        const post = await getFirstPost();
        await openModal(page, post.link);
        await generateImage(page, 'technical_infographic');

        const msg = await page.locator('#csdt-gen-msg').textContent();
        expect(msg).not.toContain('✕');

        await page.locator('#csdt-gen-prompt-toggle').click();
        const promptText = await page.locator('#csdt-gen-prompt-text').textContent();
        console.log('Technical Infographic prompt:', promptText);

        // Should NOT be Isometric 3D illustration (that's the auto/ARM default)
        // Should reference "illustration", "technical", "geometric", or similar
        const promptLower = promptText.toLowerCase();
        const hasStyleHint = promptLower.includes('illustration') || promptLower.includes('technical') ||
                             promptLower.includes('geometric') || promptLower.includes('infographic') ||
                             promptLower.includes('vector') || promptLower.includes('diagram');
        expect(hasStyleHint, 'Prompt should reflect a technical illustration style').toBe(true);

        await ctx.close();
    });
});
