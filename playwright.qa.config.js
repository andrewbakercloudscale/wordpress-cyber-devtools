// Lightweight config for QA site tests — no global setup (avoids prod Pi SSH dependency)
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
    testDir:   './tests',
    timeout:   30_000,
    retries:   0,
    workers:   1,
    use: {
        headless:          false,
        ignoreHTTPSErrors: true,
        screenshot:        'only-on-failure',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
});
