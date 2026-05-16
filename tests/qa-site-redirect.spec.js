/**
 * Verifies qa.andrewbaker.ninja loads correctly without redirecting to production.
 * Run: npx playwright test tests/qa-site-redirect.spec.js --config playwright.qa.config.js
 */

const { test, expect } = require('@playwright/test');

const QA_URL    = 'https://qa.andrewbaker.ninja/';
const PROD_HOST = 'andrewbaker.ninja';

test('trace redirect source on qa site', async ({ page }) => {
    const navigations = [];
    const redirectResponses = [];

    // Capture every navigation (including JS-triggered ones)
    page.on('framenavigated', frame => {
        if (frame === page.mainFrame()) {
            navigations.push(frame.url());
        }
    });

    // Capture every response that is a redirect
    page.on('response', response => {
        const status = response.status();
        if (status >= 300 && status < 400) {
            redirectResponses.push({
                status,
                url:      response.url(),
                location: response.headers()['location'] ?? '(none)',
            });
        }
    });

    await page.goto(QA_URL, { waitUntil: 'domcontentloaded', timeout: 20_000 });

    console.log('\n  === Navigations ===');
    navigations.forEach((url, i) => console.log(`  [${i}] ${url}`));

    console.log('\n  === HTTP Redirects ===');
    if (redirectResponses.length === 0) {
        console.log('  (none — redirect is JavaScript-based)');
    }
    redirectResponses.forEach(r => console.log(`  ${r.status} ${r.url} → ${r.location}`));

    console.log(`\n  Final URL: ${page.url()}`);

    // Capture the page source to find JS redirects
    const bodyText = await page.content();
    const jsRedirects = bodyText.match(/(?:window\.location(?:\.href)?\s*=\s*|location\.replace\(|location\.assign\()['"](https?:\/\/[^'"]+)['"]/g) || [];
    console.log('\n  === JS window.location redirects in HTML ===');
    if (jsRedirects.length === 0) {
        console.log('  (none in static HTML — may be in a loaded script)');
    }
    jsRedirects.forEach(r => console.log(`  ${r}`));

    // The actual assertion
    expect(page.url(), 'should stay on qa.andrewbaker.ninja').not.toBe('https://andrewbaker.ninja/');
});
