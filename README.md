# CloudScale Cyber and Devtools

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue) ![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple) ![License](https://img.shields.io/badge/License-GPLv2-green) ![Version](https://img.shields.io/badge/Version-1.9.824-orange)

**AI-powered WordPress security auditing, one-click hardening, and a full developer toolkit — free, zero-dependency, everything runs on your server.**

---

## Security Features

### AI Cyber Audit
The centrepiece of the plugin. Connects to **Anthropic Claude** (claude-sonnet-4-6, claude-opus-4-7) or **Google Gemini** (gemini-2.0-flash, gemini-2.5-pro) to deliver a scored, prioritised security report in under 60 seconds — the kind of analysis that would normally cost hundreds of dollars from a consultant.

- **Standard scan** — analyses WordPress config, active plugins, user roles, file permissions, wp-config.php hardening constants, and debug settings
- **Deep Dive scan** — extends the standard scan with live HTTP probes, DNS checks, TLS quality, PHP end-of-life detection, directory listing checks, static plugin code analysis, and AI-powered code triage
- Findings scored **Critical / High / Medium / Low / Good** with prioritised remediation steps
- **Scan History** — last 10 results saved automatically; click any entry to reload the full report
- **Scheduled Scans** — daily or weekly background scans with email and ntfy.sh push notifications
- **AI Code Triage** — static findings pre-classified as Confirmed / False Positive / Needs Context before main AI analysis

### Deep Dive — What It Checks
| Category | Details |
|---|---|
| HTTP security headers | Presence + quality of CSP, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy |
| CSP quality | Flags `unsafe-inline`, `unsafe-eval`, wildcard sources, missing `default-src` |
| HSTS quality | Validates `max-age ≥ 31536000` and `includeSubDomains` |
| Email DNS | SPF strictness (`~all` vs `-all`), DMARC policy strength (`p=none` flagged), DKIM selector probes — all gated on MX record presence |
| TLS | Weak cipher / protocol detection |
| PHP EOL | Flags end-of-life PHP versions |
| Auto-updates | Detects `AUTOMATIC_UPDATER_DISABLED` and `WP_AUTO_UPDATE_CORE=false` |
| display_errors | Flags PHP error exposure in production |
| Inactive plugins | Lists deactivated plugins still on disk |
| Server header leak | Detects version strings in `Server:` response header |
| Directory listing | Checks for open directory browsing |

### Quick Fixes — One-Click Hardening
| Fix | What it does |
|---|---|
| Security Headers | Enables X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy |
| Disable Pingbacks | Closes default ping/trackback status |
| Disable Registration | Turns off open user registration |
| Disable App Passwords | Blocks REST API application passwords |
| Hide WP Version | Removes generator meta tag and strips `?ver=` query strings |
| Close Comments | Disables comments on new posts by default |
| wp-config.php Permissions | Chmods wp-config.php to 0600 |
| Move debug.log | Relocates debug.log above the web root; rewrites WP_DEBUG_LOG in wp-config.php |

### CSP Builder
Dedicated panel for building and managing a Content Security Policy without breaking your site:
- Enable/disable toggle with **Enforce** or **Report-Only** (test) mode
- Service checkboxes: Google Analytics, Google AdSense, Google Tag Manager, Cloudflare Insights, Facebook Pixel, Google reCAPTCHA, YouTube embeds, Vimeo embeds
- Custom directives field for anything not covered
- Live header preview before saving
- **Backup/rollback** — every save snapshots the previous config; a one-click Rollback button (with time-ago label) instantly restores it

### Login Security

| Feature | Details |
|---|---|
| **Hide Login URL** | Moves `/wp-login.php` to a secret slug; direct requests to the default URL return 404 — bots never find the login form |
| **Brute-Force Protection** | Per-username lockout after N failed attempts (default: 5 attempts, 5-minute lock — both configurable) |
| **Session Duration** | Override the WordPress default session length with a custom value; persistent cookies set automatically |
| **Two-Factor Authentication** | Email OTP (6-digit code, 10-min expiry), TOTP via Google Authenticator / Authy / 1Password (RFC 6238), or Passkey |
| **Passkeys (WebAuthn / FIDO2)** | Face ID, Touch ID, Windows Hello, YubiKey — private key never leaves the device, phishing-resistant by design; test button verifies each key without logging out |
| **Force 2FA for admins** | Blocks dashboard access until 2FA is configured; grace-login allowance configurable |
| **Test Account Manager** | Temporary subscriber accounts with app passwords for Playwright / CI pipelines; configurable TTL and optional single-use mode; auto-delete on expiry or first login |

### Server Logs
Read-only browser viewer for PHP error log, WordPress debug log, and web server access/error logs:
- Source picker with availability indicators (readable / not found / permission denied / empty)
- Live search, severity filter (Emergency → Debug), configurable line count
- Auto-refresh tail mode (30-second interval)
- Custom log path manager
- One-click PHP error log setup

---

## Developer Tools

### Syntax-Highlighted Code Block
- Powered by **highlight.js 11.11.1** bundled locally — zero external CDN requests
- 190+ languages with auto-detection
- **14 colour themes**: Atom One, GitHub, Monokai, Nord, Dracula, Tokyo Night, VS 2015, VS Code, Stack Overflow, Night Owl, Gruvbox, Solarized, Panda, Shades of Purple
- Dark/light toggle (stored per browser in localStorage), line numbers, copy to clipboard
- Gutenberg block (`cloudscale/code`) and `[cs_code]` shortcode
- Auto-repair for INI/TOML blocks that Gutenberg splits into fragments

### Code Block Migrator
Bulk converts `wp:code`, `wp:preformatted`, Code Syntax Block, and legacy shortcode blocks to CloudScale format. Scan → Preview (side-by-side diff) → Migrate single or all.

### SQL Query Tool
Read-only `SELECT`, `SHOW`, `DESCRIBE`, `EXPLAIN` queries from wp-admin. 14 built-in quick queries covering health diagnostics, content summary, bloat/cleanup, and URL migration. All write operations blocked; requires `manage_options` capability.

### Social Preview Diagnostics
URL checker, recent posts scan, og:image generation, Cloudflare cache purge integration, Media Library audit.

### SMTP Mail
Replaces PHP `mail()` with authenticated SMTP. Test button, email log, configurable from/reply-to.

### Performance Monitor
Overlay panel (toggleable) tracking query count, HTTP requests, PHP errors, hooks, assets, and transients per page load.

### Custom 404 Page
Branded 404 page with **seven playable canvas mini-games** and a per-game site-wide leaderboard (Top 10, rate-limited score submissions via REST API). Fully theme-independent — works even if the active theme is broken.

| Game | Controls |
|---|---|
| 🏃 Runner | Space / tap — jump over obstacles |
| 🚀 Jetpack | Space / tap — thrust upward, dodge walls |
| 🚗 Racer | Arrow keys / on-screen buttons — dodge traffic |
| ⛏ Miner | Arrow keys / on-screen buttons — collect gems |
| 🌌 Asteroids | Arrow keys + Space / on-screen buttons — shoot rocks |
| 🐍 Snake | Arrow keys / on-screen buttons — eat, don't crash |
| 👾 Space Invaders | Arrow keys + Space / on-screen ◀ Fire ▶ buttons — classic shoot-em-up |

Customisable accent colour, background, and text colour from the admin panel.

---

## AI Provider Setup

Supply your own API key — keys are stored in `wp_options` and sent only to the provider's API endpoint.

| Provider | Models | Cost |
|---|---|---|
| Anthropic Claude | claude-sonnet-4-6, claude-opus-4-7 | Pay-as-you-go |
| Google Gemini | gemini-2.0-flash, gemini-2.5-pro | Free tier available |

---

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Installation

1. Download the latest zip from [Releases](../../releases)
2. In WordPress admin: **Plugins > Add New > Upload Plugin**
3. Upload, install, activate
4. Navigate to **Tools > Cyber and Devtools**

## License

GPLv2 or later. See [LICENSE](LICENSE).

## Author

[Andrew Baker](https://your-wordpress-site.example.com/)
