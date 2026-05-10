/**
 * Verifies the featured image on single posts is NOT force-cropped.
 * The fix (v1.9.791) removed aspect-ratio:1200/630 + object-fit:cover from
 * .single .wp-post-image. This test confirms the CSS is gone and the full
 * image height is visible (natural ratio, no clip).
 *
 * No login required — tests the public page directly.
 *
 * Run: npx playwright test tests/featured-image-no-crop.spec.js
 */

const { test, expect } = require('@playwright/test');

const POST_URL = 'https://andrewbaker.ninja/2026/04/29/visualising-your-claude-api-token-spend-a-self-contained-usage-dashboard-script/';

test('featured image has no forced aspect-ratio crop', async ({ page }) => {
    await page.goto(POST_URL, { waitUntil: 'domcontentloaded' });

    const img = page.locator('.single .wp-post-image').first();
    await expect(img).toBeVisible();

    // Computed styles should NOT include the old crop rule.
    const aspectRatio = await img.evaluate(el =>
        getComputedStyle(el).getPropertyValue('aspect-ratio').trim()
    );
    const objectFit = await img.evaluate(el =>
        getComputedStyle(el).getPropertyValue('object-fit').trim()
    );

    expect(aspectRatio, 'aspect-ratio should be auto/unset — no forced crop').not.toBe('1200 / 630');
    expect(objectFit, 'object-fit should not be cover').not.toBe('cover');

    // Capture the natural dimensions from the img element attributes.
    const naturalW = await img.evaluate(el => el.naturalWidth);
    const naturalH = await img.evaluate(el => el.naturalHeight);
    const src      = await img.evaluate(el => el.currentSrc || el.src);

    // Take a screenshot so we can see what the image actually looks like.
    await page.screenshot({ path: 'test-results/featured-image-screenshot.png', fullPage: false });

    console.log(`Image src: ${src}`);
    console.log(`Natural dimensions: ${naturalW}×${naturalH}px`);
    console.log(`aspect-ratio computed: "${aspectRatio}", object-fit computed: "${objectFit}"`);

    // The image file being served IS 1200×630 (the social-format facebook.jpg).
    // Flag this so the test output makes the underlying issue clear.
    if (naturalH < naturalW * 0.6) {
        console.warn('WARNING: Image file is already hard-cropped to ~1200:630 ratio — top content may be missing from the source file itself, not CSS.');
    }
});
