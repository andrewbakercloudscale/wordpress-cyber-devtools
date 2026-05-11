=== CloudScale Cyber and Devtools ===
Contributors: andrewbaker
Tags: security, code block, syntax highlighting, AI security scan, WordPress hardening
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.9.814
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free AI penetration testing, brute-force protection, 2FA, passkeys, site audit, AI debugging, performance monitor, SMTP, SQL tool, server logs, vulnerability scanner, and uptime monitoring. No subscription.

== Description ==

CloudScale Cyber and Devtools is a free, open-source WordPress developer and security toolkit. The centrepiece is an **AI Cyber Audit** powered by the world's most capable AI models — **Anthropic Claude (Sonnet and Opus 4)** and **Google Gemini (Flash and 2.5 Pro)** — performing deep security analysis of your WordPress installation and delivering prioritised, actionable findings — the kind of analysis that would normally cost hundreds of dollars from a security consultant, in under 60 seconds.

= Security Features =

* **AI Cyber Audit** — fast scan of WordPress config, plugins, users, file permissions, and wp-config.php hardening constants
* **AI Deep Dive Cyber Audit** — extends the fast scan with live HTTP probes, DNS checks (SPF, DMARC, DKIM), weak TLS detection, PHP end-of-life status, directory listing checks, plugin code static analysis, and AI-powered code triage
* **AI Cyber Audit** — fast scan of WordPress config, plugins, users, file permissions, and wp-config.php hardening constants; SSH brute-force protection detected and marked critical
* **AI Deep Dive Cyber Audit** — extends the fast scan with live HTTP probes, DNS checks (SPF, DMARC, DKIM), weak TLS detection, PHP end-of-life status, directory listing checks, plugin code static analysis, and AI-powered code triage
* **Quick Fixes** — one-click automated remediations: move debug.log outside the web root, disable XML-RPC, hide WP version, disable application passwords, disable directory browsing, rename database prefix, install fail2ban SSH protection
* **SSH Brute-Force Monitor** — reads /var/log/auth.log every 60 seconds; alerts via email and ntfy.sh push if 10+ failures detected in 60 seconds; configurable threshold; on by default
* **Scan History** — last 10 results saved automatically; click any entry to reload the full report
* **Scheduled Scans** — daily or weekly background scans with email and ntfy.sh push notifications
* **AI Code Triage** — static findings classified as Confirmed / False Positive / Needs Context before main AI analysis
* **Server Logs** — read-only browser viewer for PHP error log, WordPress debug log, web server logs, and SSH auth log with live search, level filter, and auto-refresh tail mode
* **Brute-Force Protection** — per-username account lockout after N failed logins
* **Hide Login URL** — moves /wp-login.php to a custom slug
* **Two-Factor Authentication** — email code, TOTP (authenticator app), and passkeys
* **Passkeys (WebAuthn)** — FIDO2 biometric and hardware key login
* **Test Account Manager** — temporary subscriber accounts with app passwords for Playwright/CI pipelines

= Developer Tools =

* **Syntax-highlighted code block** — Gutenberg block and shortcode powered by highlight.js 11.11.1 (bundled locally), 190+ languages, 14 colour themes, auto-detection, line numbers, copy button, dark/light toggle
* **Code Block Migrator** — batch-converts legacy wp:code blocks and shortcodes from other plugins
* **SQL Query Tool** — read-only SELECT queries against the live database with 14 built-in quick queries
* **Social Preview Diagnostics** — URL checker, post scan, og:image generation, Cloudflare integration
* **SMTP Mail** — replaces PHP mail() with authenticated SMTP delivery, test button, email log
* **Performance Monitor** — overlay panel tracking queries, HTTP requests, PHP errors, hooks, assets, transients
* **Custom 404 Page** — branded 404 with seven browser mini-games and a site-wide leaderboard

= Requirements =

* WordPress 6.0 or later
* PHP 7.4 or later

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/
2. Activate the plugin through the Plugins menu in WordPress
3. Go to Tools > CloudScale Cyber and Devtools to configure

== Frequently Asked Questions ==

= What AI providers are supported? =

Anthropic Claude (claude-sonnet-4-6 and claude-opus-4-7) and Google Gemini (gemini-2.0-flash and gemini-2.5-pro). You supply your own API key — no keys are stored anywhere other than your WordPress database (wp_options). A free Gemini tier is available.

= How does the deep dive scan avoid HTTP gateway timeouts? =

The plugin uses fastcgi_finish_request() to close the browser connection immediately, then continues the scan in the same PHP-FPM worker. A progress bar polls every 3 seconds. This does not depend on WP-Cron.

= How do I change the syntax color theme? =

Go to Tools > CloudScale Cyber and Devtools > Code Block tab > Code Block Settings panel. Select your preferred theme from the dropdown and click Save.

= Is the SQL Query Tool safe? =

Yes. Only SELECT, SHOW, DESCRIBE, DESC, and EXPLAIN are permitted. Block and line comments are stripped, semicolons are rejected, and INTO OUTFILE / LOAD_FILE are blocked. Requires manage_options capability.

= What languages are supported for code highlighting? =

highlight.js with auto-detection — 190+ languages including Bash, Python, JavaScript, TypeScript, PHP, SQL, Go, Rust, Java, C/C++, C#, Ruby, Swift, Kotlin, JSON, YAML, XML, HTML, CSS, Terraform, and more.

== Screenshots ==

1. AI Cyber Audit panel with Quick Fixes and scan controls
2. Deep dive scan results with scored findings and remediation steps
3. Server Logs tab with source picker, filters, and log viewer
4. Code block on the frontend with Atom One Dark theme and copy button
5. SQL Query Tool with quick queries and paginated results

== Changelog ==

= 1.9.524 =
* feat: expand CSP preset library with Stripe, Hotjar, Intercom, Twitter/X embeds, Disqus, and WooCommerce Payments; fix AdSense preset missing csi.gstatic.com in connect-src (was causing violations on sites using AdSense); JS serviceMap kept in sync so the live CSP preview updates correctly when any new preset is ticked
* feat: CSP violation logging and fixes log; "Log violations" toggle adds report-uri to the CSP header in both enforce and report-only modes so violations are captured in production; fixes log records every service added to the allowlist with a timestamp; both logs have clear buttons and the violation log auto-refreshes every 30 seconds

= 1.9.515 =
* fix: CSP nonce-mode now includes the nonce in style-src so CDN stylesheets (e.g. highlight.js themes) are allowed by the browser; previously syntax highlighting colours were missing on sites with the CSP nonce setting enabled

= 1.9.501 =
* feat: Thumbnails — hero image on single post pages auto-swaps to the 1200x630 social format; aspect-ratio CSS applied so any uploaded image displays at the correct landscape ratio

= 1.9.499 =
* feat: Thumbnails — "Refresh Stale" button scans all posts, regens any where the featured image was replaced since last generation, and logs found/fixed counts with clickable post links

= 1.9.496 =
* fix: Social thumbnails now auto-regenerate when the featured image file is replaced in the Media Library (not just when the attachment ID changes)
* fix: Passkey login screen shows TOTP fallback link when TOTP is also configured; back-to-picker recomputes available methods from DB to fix stale transient issue
* fix: CSP scan warnings and raw headers panel replaced table layout with div stacks so long values wrap correctly on mobile
* fix: Site Audit CTA block stacked to show description above button on narrow screens
* fix: Admin banner header uses flexbox for icon alignment

= 1.9.433 =
* fix: Explain buttons (and all data-cs-modal-open buttons) now work on iOS Safari — added touchend fallback in cs-admin-settings.js to handle overflow:hidden containers swallowing click events

= 1.9.272 =
* feat: Orphaned Table Cleanup — 🤖 Ask AI button on Unknown plugin rows (uses configured AI provider to identify table owner)
* feat: Orphaned Table Cleanup — add AIOSEO to plugin suffix map
* feat: Scan History — add Download PDF button next to View Report on each history row
* feat: Orphaned Table Cleanup — recycle bin approach: scan, archive to _trash_ prefix, then restore or permanently delete; self-contained inline script (no dependency on cs-plugin-stack.js)
* fix: Scan History text colours — type/date/summary text was grey-washed on light background; updated to legible dark colours
* feat: PHP-FPM "Setup Status Page" wizard — auto-detects www.conf, patches pm.status_path, reloads php-fpm, generates nginx snippet with copy button; one-click for everything except pasting the nginx block
* fix: PHP-FPM worker Refresh button no longer silently does nothing — cs-debug.js early-exit guard was blocking FPM code when AI Debug elements weren't present
* feat: AI audit findings now show a "Fix It →" button inline for findings that have a matching Quick Fix modal (currently: DB prefix, fail2ban)

= 1.9.208 =
* fix: CSP panel now has a white bordered card (indigo header) clearly separated from AI Cyber Audit section; AI Cyber Audit gets its own emerald section header above the scan cards

= 1.9.207 =
* fix: tab order — Optimizer and Debug AI moved to immediately after AI Security Scan

= 1.9.206 =
* feat: CSP panel — Google Fonts checkbox added; AdSense checkbox now includes fundingchoicesmessages.google.com (consent management) and ep1.adtrafficquality.google (IVT detection)
* feat: PHP-FPM Saturation Monitor — Explain button with 6 sections; live worker count (active/idle/total) with auto-load and Refresh button via /fpm-status probe

= 1.9.204 =
* feat: Deep scan — CRITICAL finding when multiple Content-Security-Policy headers detected (browser intersection breaks JS for all visitors and Googlebot)
* feat: Cloudflare cache auto-purge on post/page publish or update (replaces deactivated CF plugin's main feature); uses existing csdt_devtools_cf_zone_id / csdt_devtools_cf_api_token options

= 1.9.201 =
* feat: PHP-FPM Saturation Monitor — new panel in Debug AI tab; configurable threshold, cooldown, probe URL, and container names; shows crontab install command and config.env snippet; callback endpoint so last saturation event appears in the panel; default on

= 1.9.191 =
* fix: Uptime Monitor Worker — add cache-busting query param and Cache-Control: no-store / Pragma: no-cache headers to site probe; prevents Cloudflare edge cache from masking a down origin with a cached 200 response

= 1.9.189 =
* feat: Threat Monitor — file integrity check (wp-includes/wp-admin core files), new admin account alert, and probe attack detection; alerts once per incident via email + ntfy.sh; no spam — WP core updates rebuild baseline silently, new admin fires once per user, probe throttled to once per hour

= 1.9.183 =
* feat: PHP Error Alerting — cron polls PHP error log and WP debug log every 5 minutes; sends ntfy.sh + email alert when new fatals/errors detected; throttled to once per 15 minutes; byte-position tracking avoids re-reading old content
* feat: Debug AI tab — PHP Error Alerting settings panel with enable toggle, threshold input, and live status display

= 1.9.179 =
* feat: AI Debugging Assistant — new Debug AI tab; load PHP/WP/web error logs, click any error line, submit to Claude/Gemini for Root Cause / Why It Happens / How to Fix It analysis; auto-loads PHP error log on tab open

= 1.9.177 =
* feat: Quick Fixes — Enforce 2FA for Admins (one-click sets csdt_devtools_2fa_force_admins); Disable External wp-cron (writes DISABLE_WP_CRON to wp-config.php with system-cron reminder)
* fix: Test Account Manager — orphaned cs_devtools_test and temp-* accounts now auto-deleted by cleanup cron

= 1.9.158 =
* fix: Test Account Manager — replace single-use checkbox with Max Logins number input (0 = unlimited, 1 = single-use, N = N logins)
* fix: Test Account Manager — reduce gap between Save Settings and Create Account divider

= 1.9.152 =
* feat: Admin Bar Badge — shows worst audit severity (Critical/High/Medium/OK) + uptime status in WP toolbar on every admin page
* feat: Uptime Monitor — Cloudflare Worker auto-deploy (one-click); pings every 60s from the edge; 3h raw + 7d hourly history; response-time chart; ntfy.sh + email alerts when site is down

= 1.9.150 =
* feat: Database Intelligence Engine — new Optimizer panel; scans autoload size, expired transients, revisions, orphaned postmeta, and table fragmentation with one-click Fix It buttons for each issue

= 1.9.148 =
* feat: Site Audit — Fix It buttons on cron health and expired transient findings; SEO AI link-out buttons on missing title/desc findings
* feat: Plugin Stack Scanner — now shows inactive redundant plugins in a separate "safe to delete" section
* feat: Update Risk Scorer — new panel scans for available plugin updates and AI-assesses each as Patch / Minor / Breaking

= 1.9.146 =
* feat: Site Audit — all rule-based findings (thin content, featured images, duplicate titles, SEO) now always appear even when AI is used
* fix: Featured image, thin content, duplicate title findings now include example post URLs
* fix: AI guard blocks AI from generating duplicate thin content, featured image, word count, and duplicate title findings
* fix: Duplicate title severity lowered from high → medium

= 1.9.143 =
* feat: Default Featured Image merged from standalone plugin — Media Library picker in Thumbnails tab; post_thumbnail_html and has_post_thumbnail filters; AJAX save
* feat: Site Audit checks for missing or broken (404) default featured image
* fix: VERSION constant now matches plugin header (was stuck at 1.9.119)

= 1.9.127 =
* feat: Site Audit — SSH monitor, login BF protection, login hide, 2FA enforcement findings (info/high based on state)
* feat: Site Audit — disk space check (critical/high/medium/info thresholds)
* feat: Site Audit — WordPress core update available (critical if 2+ minor versions behind)
* feat: Site Audit — admin username "admin" exists (high)
* feat: Site Audit — writable wp-config.php check (high)
* feat: Site Audit PDF export — "Download PDF" button opens print-to-PDF in new window
* feat: Site Audit tab moved to immediately after Home (Home → Site Audit → Login Security → Security Scan)
* fix: URL Social Preview Checker now defaults to most recent published post instead of homepage
* feat: Expired transients and orphaned postmeta findings now include CloudScale Cleanup CTA

= 1.9.126 =
* fix: Site Audit meta description and title tag checks now recognise CloudScale SEO AI (_cs_seo_desc / _cs_seo_title) — posts were falsely reported as missing meta when using CloudScale SEO
* fix: Replace all Yoast SEO / Rank Math recommendations in Site Audit fix text with CloudScale SEO AI
* feat: Rule-based title tag finding added (was AI-generated); both meta desc and title tag findings now include CloudScale SEO AI CTA
* feat: AI guarded from generating meta desc, title tag, backup, SEO plugin, or revision findings independently

= 1.9.125 =
* feat: Site Audit revisions finding now fires at >20 revisions (was >500), includes cross-sell CTA for CloudScale Cleanup, and AI is told not to generate duplicate revision findings

= 1.9.124 =
* fix: Site Audit no longer flags template-rendered pages (custom theme templates, page builders with empty post_content) as thin/zero-word content; such pages are excluded from word count analysis and AI is instructed to ignore them

= 1.9.122 =
* fix: Quick Fix cards fully restructured for mobile — buttons moved below description text, eliminating word-by-word text wrapping on narrow screens
* feat: Site Audit tab moved to immediately after Security Scan in tab navigation

= 1.9.120 =
* fix: CSP unsafe-inline quick fix now visible — removed live wp_remote_get check from fixed-state evaluation; status derived from options only

= 1.9.117 =
* feat: SSH Brute-Force Monitor — WP-Cron reads /var/log/auth.log every 60 seconds, alerts via email + ntfy.sh push when ≥10 failures detected in 60 seconds; throttled to one alert per 5 minutes; configurable threshold; enabled by default
* feat: SSH auth log added as a source in the Server Logs tab (auto-detected at /var/log/auth.log and /var/log/secure)
* feat: Quick Fixes — SSH brute-force protection row shows fail2ban install/running state and live failure count; "Copy fail2ban config" modal with ready-to-paste jail.local
* feat: AI Cyber Audit (standard and deep dive) now probes SSH port, reads sshd_config, and marks unprotected SSH as CRITICAL — unprotected SSH is actively recruited into DDoS botnets
* feat: Database prefix rename — Quick Fixes "Fix Prefix…" button opens a guided modal: backup warning → pre-flight check → rename all wp_ tables and rewrite wp-config.php; rollback on failure
* fix: login slug removed from hardcoded references in cs-perf-monitor.js and tests — now read dynamically from settings; help page screenshot scrubbed of real slug

= 1.9.107 =
* feat: Home dashboard tab — security summary cards showing AI setup status, last scan score (critical/high counts), quick fixes resolved, and login security posture

= 1.9.83 =
* feat: 8 deep scan improvements — CSP quality analysis, HSTS quality, DMARC policy strength, SPF strictness, auto-updates check, PHP display_errors detection, inactive plugins list, server header version leak
* feat: MX record gate — SPF/DMARC/DKIM checks are skipped when the domain has no email configured; audit report notes "no email configured" as a good finding

= 1.9.80 =
* feat: Explain button added to AI Cyber Audit panel (covers Quick Fixes, Standard scan, Deep Dive, Code Triage, Scan History, Scheduled Scans, AI Providers)
* feat: Explain button added to Server Logs panel (covers log sources, PHP setup, filters, tail mode, custom paths, permissions)
* docs: Help page rewritten with 18 sections covering all features including Quick Fixes, Scan History, Scheduled Scans, AI Code Triage, Server Logs, Test Account Manager
* fix: Plugin menu item renamed to "Cyber and Devtools" (consistent with full plugin name)

= 1.9.79 =
* feat: Test Account Manager — temporary single-use accounts with app passwords for Playwright/CI pipelines; subscriber-level accounts auto-delete on expiry or first use; app passwords blocked for all non-test accounts

= 1.9.10 =
* feat: replace WPScan with Claude AI-powered security audit — API key, model selector, editable system prompt, scored report with critical/high/medium/low/good sections

= 1.8.141 =
* Added: "Copy All" button on every tab — copies the full text content of the active tab to clipboard with visual confirmation

= 1.8.118 =
* Fixed: Explain modals now render formatted HTML — inline code tokens styled with dark background, bold/italic emphasis, and bullet lists; all describe items converted from plain text to rich HTML markup

= 1.8.113 =
* Added: "Fix All Posts on Site" button — batch-processes every published post on the site in groups of 10, generating platform-specific social format images with live progress counter
* Added: Crawler UA detection — wp_head at priority 1 outputs platform-specific og:image meta tag before SEO plugins
* Fixed: PNG and WebP featured images now converted to JPEG during social format generation
* Security: Added SSRF protection on admin URL-check endpoints
* Security: Fixed DOM XSS in email 2FA enable flow

= 1.8.89 =
* Added: Brute-force protection — configurable per-account lockout after N failed login attempts (default 5 attempts, 5-minute lock)
* Fixed: Session persistence — login sessions now survive browser close when a custom session duration is set
* Added: Thumbnails tab — Social Preview Diagnostics with URL checker, post scan, Cloudflare integration, and Media Library auditor

= 1.7.20 =
* Security: is_safe_query() now rejects queries containing semicolons, preventing statement stacking
* Fixed: Echoed style/script blocks replaced with wp_add_inline_style() and wp_add_inline_script()
* Added: load_plugin_textdomain(); 48 strings wrapped with i18n functions

= 1.6.0 =
* Merged CloudScale SQL Command plugin into CloudScale Code Block

= 1.5.0 =
* Added: Code Block Migrator tool

= 1.0.0 =
* Initial release

== External services ==

= highlight.js (bundled locally) =

highlight.js 11.11.1 is bundled inside the plugin — no external CDN requests are made for syntax highlighting.

= Anthropic Claude API (optional — AI Cyber Audit only) =

**Service:** Anthropic PBC
**Website:** https://anthropic.com
**Endpoint:** https://api.anthropic.com/v1/messages
**Data sent:** WordPress configuration data (plugin list, PHP version, WordPress version, file permission flags, exposed debug settings, user role counts, key wp-config.php flags) and, for the deep dive, HTTP security header responses from your own site's public URLs. No post content or visitor data is transmitted.
**When data is sent:** Only when you click "Run AI Cyber Audit" or "Run AI Deep Dive Cyber Audit" on the Security tab and Anthropic is selected as your AI provider.
**API key:** You must supply your own Anthropic API key. The key is stored in your WordPress database (wp_options) and is never transmitted anywhere except directly to api.anthropic.com.

Anthropic Privacy Policy: https://www.anthropic.com/privacy
Anthropic Terms of Service: https://www.anthropic.com/terms

= Google Gemini API (optional — AI Cyber Audit only) =

**Service:** Google LLC
**Website:** https://ai.google.dev
**Endpoint:** https://generativelanguage.googleapis.com/v1beta/models/
**Data sent:** Same as Anthropic above. No post content or visitor data is transmitted.
**When data is sent:** Only when you click "Run AI Cyber Audit" or "Run AI Deep Dive Cyber Audit" on the Security tab and Google Gemini is selected as your AI provider.
**API key:** You must supply your own Google AI API key. The key is stored in your WordPress database (wp_options) and is never transmitted anywhere except directly to Google.

Google Privacy Policy: https://policies.google.com/privacy
Google Terms of Service: https://policies.google.com/terms

= OpenAI API (optional — AI Image Generator only) =

**Service:** OpenAI, LLC
**Website:** https://openai.com
**Endpoints used:**
- https://api.openai.com/v1/chat/completions (GPT-4o mini — writes the DALL-E prompt)
- https://api.openai.com/v1/images/generations (DALL-E 3 — generates the image)
**Data sent:** Your post title and excerpt are sent to GPT-4o mini to write an image prompt. The generated prompt is then sent to DALL-E 3 to produce a 1792×1024 JPEG. No visitor data or sensitive site configuration is transmitted.
**When data is sent:** Only when you click "Generate" on a post in the AI Image Generator panel on the Thumbnails tab and ChatGPT is selected as the prompt writer. Nothing is sent automatically.
**API key:** You must supply your own OpenAI API key. The key is stored in your WordPress database (wp_options) and is never transmitted anywhere except directly to api.openai.com.

OpenAI Privacy Policy: https://openai.com/policies/privacy-policy
OpenAI Terms of Service: https://openai.com/policies/terms-of-use

== Upgrade Notice ==

= 1.9.107 =
New Home dashboard tab with security summary cards. Deep scan now checks CSP/HSTS quality, DMARC/SPF policy strength, auto-updates, display_errors, inactive plugins, and server header version leaks. MX gate prevents false positives on non-email domains.
