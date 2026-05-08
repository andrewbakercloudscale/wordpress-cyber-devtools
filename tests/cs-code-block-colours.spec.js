/**
 * Code block — syntax highlight colours
 *
 * Verifies that hljs loads and applies token colours on the live post.
 * No auth needed — page is public.
 *
 * Run: npx playwright test tests/cs-code-block-colours.spec.js --headed
 */

const { test, expect } = require('@playwright/test');

const POST_URL = 'https://andrewbaker.ninja/2022/08/24/auto-toggle-bluetooth-on-macbook-lid-open-close/';

test('code block has syntax-highlighted tokens (hljs loaded, not CSP-blocked)', async ({ page }) => {
    const cspErrors = [];
    page.on('console', msg => {
        if (msg.type() === 'error' && msg.text().includes('cdnjs.cloudflare.com')) {
            cspErrors.push(msg.text());
        }
    });

    await page.goto(POST_URL, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await page.waitForTimeout(2000);

    // hljs must be defined — script loaded from self, not blocked by CSP
    const hljsDefined = await page.evaluate(() => typeof window.hljs !== 'undefined');
    expect(hljsDefined, 'hljs should be defined (not CSP-blocked)').toBe(true);

    // At least one token span inside the first code block
    const tokenCount = await page.evaluate(() =>
        document.querySelectorAll('.cs-code-body pre code .hljs-keyword, .cs-code-body pre code .hljs-comment, .cs-code-body pre code .hljs-string, .cs-code-body pre code .hljs-built_in').length
    );
    expect(tokenCount, 'should have at least one coloured token').toBeGreaterThan(0);

    // No CDN CSP blocks
    expect(cspErrors, 'no CDN CSP errors').toHaveLength(0);

    console.log(`✅ hljs loaded, ${tokenCount} tokens coloured, no CDN CSP errors.`);
});
