const { chromium } = require('playwright');
(async () => {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();
    const errors = [];
    page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text()); });
    await page.goto('https://andrewbaker.ninja/2022/08/24/auto-toggle-bluetooth-on-macbook-lid-open-close/', { waitUntil: 'domcontentloaded', timeout: 20000 });
    await page.waitForTimeout(2000);

    const info = await page.evaluate(() => {
        const sheets = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
            .map(l => ({ id: l.id, href: l.href }))
            .filter(l => /code|highlight|hljs|prism|cloudscale/i.test(l.href + l.id));
        const scripts = Array.from(document.querySelectorAll('script[src]'))
            .map(s => s.src)
            .filter(s => /code|highlight|hljs|prism|cloudscale/i.test(s));
        const firstCode = document.querySelector('code');
        const firstPre  = document.querySelector('pre');
        const wrapper   = firstPre ? firstPre.closest('[class]') : null;
        return {
            sheets,
            scripts,
            firstCodeClass: firstCode ? firstCode.className : null,
            firstPreClass:  firstPre  ? firstPre.className  : null,
            wrapperClass:   wrapper   ? wrapper.className   : null,
            wrapperOuterHtml: wrapper ? wrapper.outerHTML.slice(0, 800) : null,
            hasHljs:  typeof window.hljs  !== 'undefined',
            hasPrism: typeof window.Prism !== 'undefined',
            tokenClasses: firstCode ? Array.from(new Set(
                Array.from(firstCode.querySelectorAll('[class]')).map(el => el.className)
            )).slice(0, 20) : [],
        };
    });

    console.log(JSON.stringify(info, null, 2));
    console.log('\nJS ERRORS:', errors.slice(0, 10));
    await browser.close();
})();
