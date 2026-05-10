'use strict';
const fs      = require('fs');
const helpLib = require('/Users/cp363412/Desktop/github/shared-help-docs/help-lib.js');

// Read current version from plugin header so it never goes stale
const _phpHeader    = fs.readFileSync(`${__dirname}/../cs-code-block.php`, 'utf8');
const _versionMatch = _phpHeader.match(/^\s*\*\s+Version:\s+(.+)$/m);
const _pluginVersion = _versionMatch ? _versionMatch[1].trim() : '1.0.0';

helpLib.run({
    baseUrl:    process.env.WP_BASE_URL,
    cookies:    process.env.WP_COOKIES,
    restUser:   process.env.WP_REST_USER,
    restPass:   process.env.WP_REST_PASS,
    docsDir:    process.env.WP_DOCS_DIR,

    pluginName: 'CloudScale Cyber and Devtools - Free WordPress Security, AI Penetration Testing &amp; Developer Toolkit',
    pluginDesc: 'Most security and devtools plugins charge $49–$199/year each, and you need at least 8 of them to cover what CloudScale DevTools gives you free. The plugin runs a full AI penetration test of your WordPress site using Anthropic Claude 4 or Google Gemini 2.5 Pro: the same models security consultants charge thousands to access. Security features include brute-force login protection, hidden login URL with random slug rotation, two-factor authentication (TOTP, email, and passkeys/WebAuthn), configurable session duration, SSH brute-force log monitoring, and a live threat monitor dashboard. Developer tools include a read-only SQL query tool, server log viewer, syntax-highlighted code block editor with a legacy content migrator, PHP-FPM and OPcache monitors, plugin stack CVE scanner, update risk scorer, and a Cloudflare uptime monitor with deep readiness probe covering DB, FPM, and WP health. Also included: SMTP mailer with full email activity log, Test Account Manager for automated testing workflows, Thumbnails and Open Graph image audit, and an AI site auditor for content quality checks. No subscription, no SaaS, no data leaving your server.',
    seoTitle:  'CloudScale Cyber & Devtools | Free WordPress AI Security Scanner, 2FA & Developer Toolkit',
    seoDesc:   'Free WordPress security plugin: AI penetration testing with Claude 4 & Gemini, brute-force protection, 2FA, passkeys, AI site audit, AI debugging, PHP-FPM monitor, SMTP, SQL tool, server logs, CVE scanner, Cloudflare uptime. No subscription.',

    schema: {
        '@context': 'https://schema.org',
        '@type': 'SoftwareApplication',
        'name': 'CloudScale Cyber and Devtools',
        'applicationCategory': 'SecurityApplication',
        'operatingSystem': 'WordPress',
        'offers': { '@type': 'Offer', 'price': '0', 'priceCurrency': 'USD' },
        'description': 'Free WordPress security plugin powered by Anthropic Claude and Google Gemini AI. Features: AI-powered penetration testing, brute-force protection, two-factor authentication, passkeys (WebAuthn), hide login URL, AI site audit, AI debugging assistant, PHP-FPM monitoring, performance panel, SMTP mailer, SQL tool, server logs, plugin vulnerability scanner, Cloudflare uptime monitor with readiness probe, and syntax-highlighted code blocks.',
        'url': 'https://andrewbaker.ninja/wordpress-plugin-help/cloudscale-cyber-devtools-help/',
        'downloadUrl': 'https://andrewninjawordpress.s3.af-south-1.amazonaws.com/cloudscale-devtools.zip',
        'softwareVersion': _pluginVersion,
        'author': { '@type': 'Person', 'name': 'Andrew Baker', 'url': 'https://andrewbaker.ninja' },
        'isAccessibleForFree': true,
        'license': 'https://www.gnu.org/licenses/gpl-2.0.html',
    },
    pageTitle:  'CloudScale Cyber & Devtools - Free AI Penetration Testing & WordPress Security Plugin',
    pageSlug:   'cloudscale-cyber-devtools-help',
    downloadUrl: 'https://andrewninjawordpress.s3.af-south-1.amazonaws.com/cloudscale-devtools.zip',
    repoUrl:     'https://github.com/andrewbakercloudscale/cloudscale-cyber-devtools',
    adminUrl:   `${process.env.WP_BASE_URL}/wp-admin/tools.php?page=cloudscale-devtools`,

    pluginFile: `${__dirname}/../cs-code-block.php`,
    logoFile:   `${__dirname}/../CloudScaleCyberDevtools.jpeg`,

    pluginIntro: `

<div style="background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 60%,#1e3a5f 100%);border-radius:12px;padding:40px 36px 36px;margin:0 0 36px;color:#fff;position:relative;overflow:hidden;">
<div style="position:absolute;top:-40px;right:-40px;width:260px;height:260px;background:rgba(99,102,241,.15);border-radius:50%;pointer-events:none;"></div>
<div style="position:relative;">
<p style="margin:0 0 8px;font-size:.82em;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#a5b4fc;">Free &amp; Open Source · No Subscription · Your Own API Key</p>
<h1 style="margin:0 0 16px;font-size:2em;font-weight:900;line-height:1.2;color:#fff;background:transparent!important;padding:0!important;border:none!important;">Stop Paying $300/Year for a Plugin Stack That Doesn't Work Together.</h1>
<p style="margin:0 0 18px;font-size:1.1em;line-height:1.75;color:#cbd5e1;max-width:700px;">CloudScale replaces your security scanner, 2FA plugin, SMTP mailer, code highlighting plugin, SQL tool, and log viewer. <strong style="color:#fff;">One free, open-source plugin</strong>, running entirely on your own server. No subscriptions, no CDN dependencies, no data leaving your site without your say-so. Powered by <strong style="color:#fff;">Anthropic Claude 4</strong> and <strong style="color:#fff;">Google Gemini 2.5 Pro</strong> - frontier AI sent direct from your server to the provider's API.</p>
<div style="display:flex;flex-wrap:wrap;gap:8px;margin:0 0 24px;">
<span style="background:rgba(255,255,255,.15);color:#e2e8f0;font-size:.82em;font-weight:600;padding:5px 14px;border-radius:20px;">✓ Replaces 8+ plugins</span>
<span style="background:rgba(255,255,255,.15);color:#e2e8f0;font-size:.82em;font-weight:600;padding:5px 14px;border-radius:20px;">✓ Saves $200–$400/year</span>
<span style="background:rgba(255,255,255,.15);color:#e2e8f0;font-size:.82em;font-weight:600;padding:5px 14px;border-radius:20px;">✓ Zero CDN calls</span>
<span style="background:rgba(255,255,255,.15);color:#e2e8f0;font-size:.82em;font-weight:600;padding:5px 14px;border-radius:20px;">✓ AI audit in 60 seconds</span>
</div>
<div style="display:flex;flex-wrap:wrap;gap:12px;">
<a href="#download" style="display:inline-block;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;text-decoration:none;font-weight:700;font-size:.95em;padding:12px 28px;border-radius:8px;">Download Free Plugin</a>
<a href="#cs-section-security" style="display:inline-block;background:rgba(255,255,255,.12);color:#fff;text-decoration:none;font-weight:600;font-size:.95em;padding:12px 28px;border-radius:8px;border:1px solid rgba(255,255,255,.2);">See the AI Audit →</a>
</div>
</div>
</div>

<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:28px 32px;margin:0 0 36px;">
<h2 style="margin:0 0 6px;font-size:1.2em;font-weight:800;color:#0f172a;text-align:center;background:transparent!important;padding:0!important;border:none!important;">Before CloudScale vs After</h2>
<div style="display:flex;gap:12px;justify-content:center;margin:0 0 20px;">
<span style="font-size:.8em;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.07em;">Before</span>
<span style="color:#94a3b8;">vs</span>
<span style="font-size:.8em;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:.07em;">After CloudScale</span>
</div>
<div style="display:flex;flex-direction:column;gap:10px;">
<div style="display:flex;align-items:flex-start;gap:10px;background:#fff;border-radius:8px;padding:12px 16px;border:1px solid #f1f5f9;">
<span style="flex-shrink:0;width:24px;font-weight:800;color:#94a3b8;font-size:.85em;text-align:center;">1</span>
<span style="flex:1;min-width:0;color:#dc2626;font-size:.92em;line-height:1.5;">8 separate plugins to manage and update</span>
<span style="flex:1;min-width:0;color:#16a34a;font-size:.92em;line-height:1.5;font-weight:600;">One plugin, one place to manage</span>
</div>
<div style="display:flex;align-items:flex-start;gap:10px;background:#f8fafc;border-radius:8px;padding:12px 16px;border:1px solid #f1f5f9;">
<span style="flex-shrink:0;width:24px;font-weight:800;color:#94a3b8;font-size:.85em;text-align:center;">2</span>
<span style="flex:1;min-width:0;color:#dc2626;font-size:.92em;line-height:1.5;">$300–$400/year in premium licenses</span>
<span style="flex:1;min-width:0;color:#16a34a;font-size:.92em;line-height:1.5;font-weight:600;">Free forever. No premium tier.</span>
</div>
<div style="display:flex;align-items:flex-start;gap:10px;background:#fff;border-radius:8px;padding:12px 16px;border:1px solid #f1f5f9;">
<span style="flex-shrink:0;width:24px;font-weight:800;color:#94a3b8;font-size:.85em;text-align:center;">3</span>
<span style="flex:1;min-width:0;color:#dc2626;font-size:.92em;line-height:1.5;">Conflicts between overlapping plugin features</span>
<span style="flex:1;min-width:0;color:#16a34a;font-size:.92em;line-height:1.5;font-weight:600;">Built as a system - designed to work together</span>
</div>
<div style="display:flex;align-items:flex-start;gap:10px;background:#f8fafc;border-radius:8px;padding:12px 16px;border:1px solid #f1f5f9;">
<span style="flex-shrink:0;width:24px;font-weight:800;color:#94a3b8;font-size:.85em;text-align:center;">4</span>
<span style="flex:1;min-width:0;color:#dc2626;font-size:.92em;line-height:1.5;">CDN scripts on every page (hurts Core Web Vitals)</span>
<span style="flex:1;min-width:0;color:#16a34a;font-size:.92em;line-height:1.5;font-weight:600;">Everything runs on your own server, zero external calls</span>
</div>
<div style="display:flex;align-items:flex-start;gap:10px;background:#fff;border-radius:8px;padding:12px 16px;border:1px solid #f1f5f9;">
<span style="flex-shrink:0;width:24px;font-weight:800;color:#94a3b8;font-size:.85em;text-align:center;">5</span>
<span style="flex:1;min-width:0;color:#dc2626;font-size:.92em;line-height:1.5;">Site data routed through vendor servers</span>
<span style="flex:1;min-width:0;color:#16a34a;font-size:.92em;line-height:1.5;font-weight:600;">Data goes direct to the AI API you choose</span>
</div>
<div style="display:flex;align-items:flex-start;gap:10px;background:#f8fafc;border-radius:8px;padding:12px 16px;border:1px solid #f1f5f9;">
<span style="flex-shrink:0;width:24px;font-weight:800;color:#94a3b8;font-size:.85em;text-align:center;">6</span>
<span style="flex:1;min-width:0;color:#dc2626;font-size:.92em;line-height:1.5;">Security audit = expensive consultant or nothing</span>
<span style="flex:1;min-width:0;color:#16a34a;font-size:.92em;line-height:1.5;font-weight:600;">AI security audit in 60 seconds, on demand</span>
</div>
</div>
</div>

<h2 style="font-size:1.5em;font-weight:800;color:#0f172a;margin:0 0 14px;background:transparent!important;padding:0!important;border:none!important;">The WordPress Security Reality No One Talks About</h2>

<p style="font-size:1.05em;color:#374151;margin:0 0 16px;line-height:1.75;">WordPress powers <strong>43% of every website on the internet</strong>, over 810 million sites. That extraordinary market dominance makes it the single most targeted platform in the history of the web. Automated attack bots don't discriminate by site size or traffic. Your personal blog, your agency client's e-commerce store, your company's marketing site: they are all being probed right now, regardless of how small or "not worth hacking" you think they are.</p>

<p style="font-size:1.05em;color:#374151;margin:0 0 16px;line-height:1.75;">The numbers are stark. Approximately <strong>90,000 WordPress sites are attacked every single minute</strong>. Over 97% of those attacks are fully automated: bots running credential-stuffing scripts, plugin vulnerability scanners, and file-injection exploits around the clock, targeting millions of sites simultaneously. The bots don't care who you are. They care that you're running WordPress.</p>

<p style="font-size:1.05em;color:#374151;margin:0 0 24px;line-height:1.75;">And here is the uncomfortable truth about the typical WordPress security posture: it's almost always inadequate, and the owner almost never knows it. Debug mode left on in production, leaking PHP errors to every visitor. WordPress version number advertised in page source and RSS feeds, letting attackers search for known CVEs before you've had a chance to patch. <code>/wp-login.php</code> answering requests from every IP on earth, soaking up thousands of brute-force attempts per day. Plugins installed years ago, never updated, carrying unpatched vulnerabilities that have been in public CVE databases for months. A single administrator account with a password reused from a site that breached two years ago. None of this is unusual. All of it is standard.</p>

<p style="font-size:1.05em;color:#374151;margin:0 0 24px;line-height:1.75;">The consequences are binary and brutal. An unprotected login page or an SSH port open to the internet with no brute-force protection will either get your server recruited into a DDoS botnet (taking your site offline and potentially getting your IP blacklisted), or it hands attackers the keys to your admin dashboard. Servers with open SSH and no fail2ban are found by automated scanners within minutes of going online. Once inside, they don't just deface your site. They install backdoors, steal customer data, send spam through your mail server, and use your infrastructure to attack other targets. You often won't know for weeks.</p>

<div style="background:#fff5f5;border-left:4px solid #dc2626;border-radius:0 8px 8px 0;padding:20px 24px;margin:0 0 28px;">
<h3 style="margin:0 0 10px;font-size:1.05em;font-weight:700;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">The Checklist Security Myth</h3>
<p style="margin:0;color:#374151;line-height:1.7;">For years, WordPress security advice has come in the form of checklists: "enable these constants in wp-config.php, install a firewall plugin, keep plugins updated." This advice is correct but woefully incomplete. A checklist tells you <em>what</em> to check. It cannot tell you what your specific configuration actually means from a risk perspective, whether a combination of settings creates an exposure that no individual setting would reveal, or whether one of your installed plugins contains obfuscated code that bypasses every firewall rule written. Checklists treat all sites as identical. Your site is not identical to anyone else's.</p>
</div>

<h2 style="font-size:1.5em;font-weight:800;color:#0f172a;margin:0 0 14px;background:transparent!important;padding:0!important;border:none!important;">The Plugin Stack You're Currently Paying For</h2>

<p style="font-size:1.05em;color:#374151;margin:0 0 16px;line-height:1.75;">Here is the typical WordPress security and developer tooling stack, with real 2025 pricing for sites that take this seriously:</p>

<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:22px 24px;margin:0 0 20px;overflow-x:auto;">
<table style="width:100%;border-collapse:collapse;font-size:.92em;color:#374151;">
<thead><tr style="background:#f1f5f9;"><th style="padding:10px 14px;text-align:left;font-weight:700;border-bottom:2px solid #e2e8f0;">Plugin</th><th style="padding:10px 14px;text-align:left;font-weight:700;border-bottom:2px solid #e2e8f0;">What it does</th><th style="padding:10px 14px;text-align:right;font-weight:700;border-bottom:2px solid #e2e8f0;">Premium cost</th></tr></thead>
<tbody>
<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px;font-weight:600;">Wordfence Premium</td><td style="padding:10px 14px;">Security scanner, firewall, malware detection</td><td style="padding:10px 14px;text-align:right;color:#dc2626;font-weight:600;">$119/year</td></tr>
<tr style="background:#f8fafc;border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px;font-weight:600;">WP 2FA Pro</td><td style="padding:10px 14px;">Two-factor authentication for wp-admin</td><td style="padding:10px 14px;text-align:right;color:#dc2626;font-weight:600;">$79/year</td></tr>
<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px;font-weight:600;">WP Mail SMTP Pro</td><td style="padding:10px 14px;">Authenticated SMTP email delivery</td><td style="padding:10px 14px;text-align:right;color:#dc2626;font-weight:600;">$49/year</td></tr>
<tr style="background:#f8fafc;border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px;font-weight:600;">Prismatic</td><td style="padding:10px 14px;">Syntax-highlighted code blocks</td><td style="padding:10px 14px;text-align:right;color:#dc2626;font-weight:600;">$29/year</td></tr>
<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px;font-weight:600;">iThemes Security Pro</td><td style="padding:10px 14px;">Brute-force protection, hide login URL</td><td style="padding:10px 14px;text-align:right;color:#dc2626;font-weight:600;">$99/year</td></tr>
<tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;"><td style="padding:10px 14px;font-weight:600;">WPScan</td><td style="padding:10px 14px;">Vulnerability scanning and audit reporting</td><td style="padding:10px 14px;text-align:right;color:#dc2626;font-weight:600;">$25–$75/month</td></tr>
<tr style="background:#fff7ed;"><td style="padding:10px 14px;font-weight:800;color:#0f172a;">Total (conservative)</td><td style="padding:10px 14px;color:#64748b;font-size:.9em;">Minimum tiers, annual billing</td><td style="padding:10px 14px;text-align:right;font-weight:800;color:#dc2626;font-size:1.1em;">$375–$1,275/year</td></tr>
<tr style="background:#f0fdf4;border-top:2px solid #16a34a;"><td style="padding:10px 14px;font-weight:800;color:#16a34a;">CloudScale</td><td style="padding:10px 14px;color:#374151;">Everything above, plus frontier AI audit</td><td style="padding:10px 14px;text-align:right;font-weight:800;color:#16a34a;font-size:1.1em;">Free</td></tr>
</tbody>
</table>
</div>

<p style="font-size:1.05em;color:#374151;margin:0 0 24px;line-height:1.75;">This isn't a feature comparison where CloudScale cuts corners to hit a free price point. It's a full implementation of each category - and the AI security audit isn't a cut-down version of a paid product. It's built on frontier models that outperform the signature-based scanners you're currently paying for.</p>

<h2 style="font-size:1.5em;font-weight:800;color:#0f172a;margin:0 0 14px;background:transparent!important;padding:0!important;border:none!important;">Why the Existing Security Tools Fall Short</h2>

<div style="background:#f1f5f9;border-radius:8px;padding:20px 24px;margin:0 0 24px;">
<h3 style="margin:0 0 12px;font-size:1.02em;font-weight:700;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">Understanding the Terminology</h3>
<p style="margin:0 0 10px;color:#374151;line-height:1.7;"><strong>CVE (Common Vulnerabilities and Exposures)</strong> is a public database of known security flaws in software. Each one gets a unique ID like CVE-2024-1234. When a researcher discovers a bug in a WordPress plugin that could let an attacker take over a site, they file a CVE report. It gets added to the database. Security tools scan your plugins against this list.</p>
<p style="margin:0 0 10px;color:#374151;line-height:1.7;"><strong>CVSS score</strong> (Common Vulnerability Scoring System) rates the severity of each CVE on a scale of 0–10. The four bands you'll see in CloudScale's reports: <strong>Critical (9–10):</strong> remote code execution, full site takeover, mass data theft possible with no user interaction. <strong>High (7–8.9):</strong> significant data exposure or privilege escalation. <strong>Medium (4–6.9):</strong> real risk but requires specific conditions. <strong>Low (0.1–3.9):</strong> minimal practical impact. Any Critical finding on a live site should be treated as a fire drill.</p>
<p style="margin:0;color:#374151;line-height:1.7;"><strong>Zero-day</strong> refers to a vulnerability that is being actively exploited before a patch exists or before it has been added to any CVE database. The name comes from the fact that developers have had zero days to fix it. Zero-days are the most dangerous class of vulnerability because every signature-based scanner in the world is blind to them. The attacker knows about the flaw. The defenders don't. The only way to catch them is through code analysis and behavioural reasoning. That is exactly what CloudScale's AI Code Triage does.</p>
</div>

<p style="font-size:1.05em;color:#374151;margin:0 0 16px;line-height:1.75;"><strong>Wordfence</strong> ($119/year for premium), <strong>Sucuri</strong> ($199/year), and <strong>WPScan</strong> ($25–$75/month) are the tools most security professionals will point you to. They are legitimate products that do real things: malware signature scanning, firewall rules, IP reputation blocking. But they share a fundamental architectural limitation. They are <em>signature-based</em>. They match what they see on your site against a database of known bad patterns. If the malware or misconfiguration isn't in their database yet, they don't flag it. They are inherently reactive; they require someone to be compromised first, for the attack pattern to be captured, analysed, and written into a rule. By definition they cannot identify novel threats, unusual configuration combinations, or the specific risk profile of your particular setup.</p>

<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:22px 24px;margin:0 0 20px;overflow-x:auto;">
<h3 style="margin:0 0 14px;font-size:1.05em;font-weight:700;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">CloudScale vs The Paid Stack: Full Comparison</h3>
<table style="width:100%;border-collapse:collapse;font-size:.88em;color:#374151;">
<thead><tr style="background:#f1f5f9;">
<th style="padding:10px 14px;text-align:left;font-weight:700;border-bottom:2px solid #e2e8f0;">Capability</th>
<th style="padding:8px 10px;text-align:center;font-weight:700;border-bottom:2px solid #e2e8f0;">WPScan<br><span style="font-weight:400;color:#dc2626;font-size:.88em;">$25–$75/mo</span></th>
<th style="padding:8px 10px;text-align:center;font-weight:700;border-bottom:2px solid #e2e8f0;">Wordfence Premium<br><span style="font-weight:400;color:#dc2626;font-size:.88em;">$119/yr</span></th>
<th style="padding:8px 10px;text-align:center;font-weight:700;color:#6366f1;border-bottom:2px solid #e2e8f0;">CloudScale<br><span style="font-weight:400;color:#16a34a;font-size:.88em;">Free</span></th>
</tr></thead>
<tbody>
<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:9px 14px;">AI security analysis</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">✗</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">✗ Signature only</td><td style="padding:9px 10px;text-align:center;color:#16a34a;font-weight:600;">✓ Frontier AI</td></tr>
<tr style="background:#f8fafc;border-bottom:1px solid #f1f5f9;"><td style="padding:9px 14px;">Novel / zero-day threats</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">✗ DB only</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">✗ DB only</td><td style="padding:9px 10px;text-align:center;color:#16a34a;font-weight:600;">✓ First-principles reasoning</td></tr>
<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:9px 14px;">Context-aware findings</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">✗</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">✗</td><td style="padding:9px 10px;text-align:center;color:#16a34a;font-weight:600;">✓ Your specific config</td></tr>
<tr style="background:#f8fafc;border-bottom:1px solid #f1f5f9;"><td style="padding:9px 14px;">PHP code static analysis</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">✗</td><td style="padding:9px 10px;text-align:center;color:#64748b;">Limited</td><td style="padding:9px 10px;text-align:center;color:#16a34a;font-weight:600;">✓ AI-triaged per plugin</td></tr>
<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:9px 14px;">SSH / sshd_config checks</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">✗</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">✗</td><td style="padding:9px 10px;text-align:center;color:#16a34a;font-weight:600;">✓ CRITICAL finding if open</td></tr>
<tr style="background:#f8fafc;border-bottom:1px solid #f1f5f9;"><td style="padding:9px 14px;">DNS / SPF / DMARC analysis</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">✗</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">✗</td><td style="padding:9px 10px;text-align:center;color:#16a34a;font-weight:600;">✓</td></tr>
<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:9px 14px;">One-click remediations</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">✗</td><td style="padding:9px 10px;text-align:center;color:#64748b;">Some</td><td style="padding:9px 10px;text-align:center;color:#16a34a;font-weight:600;">✓ 7 quick fixes</td></tr>
<tr style="background:#f8fafc;border-bottom:1px solid #f1f5f9;"><td style="padding:9px 14px;">2FA + Passkeys included</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">✗</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">✗</td><td style="padding:9px 10px;text-align:center;color:#16a34a;font-weight:600;">✓ All three methods</td></tr>
<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:9px 14px;">Data via vendor server</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">Yes</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">Yes</td><td style="padding:9px 10px;text-align:center;color:#16a34a;font-weight:600;">No. Direct to AI API.</td></tr>
<tr style="background:#f8fafc;border-bottom:1px solid #f1f5f9;"><td style="padding:9px 14px;">SQL tool + server log viewer</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">✗</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">✗</td><td style="padding:9px 10px;text-align:center;color:#16a34a;font-weight:600;">✓ Included</td></tr>
<tr><td style="padding:9px 14px;">SMTP + syntax-highlighted code blocks</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">✗</td><td style="padding:9px 10px;text-align:center;color:#dc2626;">✗</td><td style="padding:9px 10px;text-align:center;color:#16a34a;font-weight:600;">✓ Included</td></tr>
</tbody>
</table>
</div>

<p style="font-size:1.05em;color:#374151;margin:0 0 16px;line-height:1.75;">The premium price also filters out the vast majority of WordPress site owners. There are 810 million WordPress sites and a fraction of them pay for premium security tooling. Everyone else: the personal bloggers, small business owners, freelancers building sites for local clients. They are either running free tools with heavily restricted capabilities, or running nothing at all.</p>

<div style="background:#fefce8;border-left:4px solid #d97706;border-radius:0 8px 8px 0;padding:20px 24px;margin:0 0 28px;">
<h3 style="margin:0 0 10px;font-size:1.05em;font-weight:700;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">The "AI Security" Marketing Trap</h3>
<p style="margin:0 0 10px;color:#374151;line-height:1.7;">Since ChatGPT became mainstream, the WordPress plugin directory has filled with plugins claiming "AI-powered security." Look closely at almost all of them and you find one of two things: either a bolt-on GPT-4 API call wrapped around the same signature-based scan output that existed before (the AI doesn't do the analysis, it just summarises it), or a marketing page full of AI language that describes what the plugin <em>could</em> detect with AI, without actually using AI to do it.</p>
<p style="margin:0;color:#374151;line-height:1.7;">Real AI security analysis means sending your actual configuration, your actual plugin list, your actual code (not a pre-processed summary) to a frontier model and asking it to reason about the specific risk profile. It means the AI can identify that <em>your combination</em> of an outdated caching plugin, a relaxed CORS policy, and a public-facing REST API endpoint creates an exposure that no individual component would trigger on its own. That requires genuine frontier intelligence, not pattern-matching dressed up with AI branding.</p>
</div>

<h2 style="font-size:1.5em;font-weight:800;color:#0f172a;margin:0 0 14px;background:transparent!important;padding:0!important;border:none!important;">What Frontier AI Actually Changes</h2>

<p style="font-size:1.05em;color:#374151;margin:0 0 16px;line-height:1.75;">Anthropic Claude Opus 4 and Google Gemini 2.5 Pro are not chatbots with a security FAQ. They are frontier reasoning systems with deep knowledge of CVEs, OWASP vulnerabilities, PHP exploitation techniques, WordPress internals, and the full threat landscape. A professional security consultant doing a WordPress audit is doing fundamentally the same thing: reading your configuration, reasoning about what it means, cross-referencing known vulnerability patterns, and applying judgement about real-world risk. The audit a consultant would charge $500–$5,000 for and take days to schedule? The AI does it in under 60 seconds, on your specific site.</p>

<p style="font-size:1.05em;color:#374151;margin:0 0 24px;line-height:1.75;">The critical difference from signature-based tools: the AI doesn't need your vulnerability to be in a database first. It reasons from first principles. When it reads your sshd_config and sees that <code>PasswordAuthentication yes</code> is set with no fail2ban equivalent running and port 22 open to the internet, it knows from its training on real-world security incidents that this configuration actively gets servers recruited into DDoS botnets. Not because that specific combination is in a signature database. Because it understands what that configuration means.</p>

<h2 style="font-size:1.5em;font-weight:800;color:#0f172a;margin:0 0 14px;background:transparent!important;padding:0!important;border:none!important;">The Mythology of AI Security</h2>

<p style="font-size:1.05em;color:#374151;margin:0 0 16px;line-height:1.75;">There is a prevailing mythology in the security industry that AI is a magic layer you bolt onto existing tools to make them better. Vendors who spent the last decade building signature databases rebranded overnight. The product didn't change. The marketing did. "AI-powered" became the new "cloud-enabled": a phrase that means everything and nothing at once.</p>

<p style="font-size:1.05em;color:#374151;margin:0 0 16px;line-height:1.75;">The mythology is seductive because it's partly true. Adding an AI summary to a Wordfence scan report does make it easier to read. Adding a chatbot that explains CVEs is marginally useful. But these are cosmetic improvements to a fundamentally reactive architecture. The underlying problem is unchanged: you can only detect what you've already catalogued.</p>

<p style="font-size:1.05em;color:#374151;margin:0 0 16px;line-height:1.75;">What frontier AI actually enables is something qualitatively different. Not a better summary of existing scan results. A different kind of analysis altogether. Claude Opus 4 has read more security research, CVE disclosures, penetration testing write-ups, and malware analyses than any human security team ever could. When it looks at your WordPress configuration, it is drawing on that entire body of knowledge simultaneously, applying it to your specific situation, and reasoning about what it actually means for you. That's not a better wrapper around signature matching. That's a different tool entirely.</p>

<div style="background:linear-gradient(135deg,#0f172a,#1e293b);border-radius:8px;padding:24px 28px;margin:0 0 24px;color:#fff;">
<h3 style="margin:0 0 14px;font-size:1.1em;font-weight:700;color:#e2e8f0;background:transparent!important;padding:0!important;border:none!important;">Where This Goes Next</h3>
<p style="margin:0 0 12px;color:#94a3b8;line-height:1.7;">We are at the beginning of a capability curve, not the middle. The models available today (Claude Sonnet 4.6, Claude Opus 4.7, Gemini 2.5 Pro) already outperform the security analysis you'd get from most paid consultants. The models coming in the next 12–24 months will make these look primitive.</p>
<p style="margin:0 0 12px;color:#94a3b8;line-height:1.7;">Claude 5 and its successors will be capable of autonomous security research: actively probing your infrastructure, reasoning about multi-step attack chains, writing and testing proposed fixes, and explaining the second and third-order consequences of every configuration decision. The gap between "AI that helps you understand a scan" and "AI that autonomously hardens your infrastructure" is closing fast.</p>
<p style="margin:0;color:#94a3b8;line-height:1.7;">CloudScale is built to absorb every new model the day it launches. No migration, no upgrade fee, no waiting. Your plugin gets smarter as the underlying AI gets smarter. The architecture was designed specifically for this: your site, your API key, your direct relationship with the provider. When the next breakthrough model drops, you flip a dropdown and you're on it.</p>
</div>

<h2 style="font-size:1.5em;font-weight:800;color:#0f172a;margin:0 0 14px;background:transparent!important;padding:0!important;border:none!important;">CloudScale Cyber and Devtools: The Breakthrough</h2>

<p style="font-size:1.05em;color:#374151;margin:0 0 16px;line-height:1.75;">CloudScale Cyber and Devtools is a <strong>free, open-source WordPress security and developer toolkit</strong> that gives every WordPress site owner access to exactly this level of analysis. No premium tier. No "upgrade to see your full results." No monthly subscription. You bring your own API key (Google Gemini has a <strong>free tier that requires no credit card</strong>), and the plugin runs on your own server. Your data never goes anywhere except directly to the AI provider you choose.</p>

<p style="font-size:1.05em;color:#374151;margin:0 0 20px;line-height:1.75;">The result is a full security audit that would normally cost hundreds of dollars from a consultant, available in your WordPress dashboard, for free, any time you want to run it. Set up daily or weekly scheduled scans and you'll get an email alert when new issues appear, so you know about problems before your users or Google do.</p>

<div style="background:linear-gradient(135deg,#ecfdf5,#f0f9ff);border:1px solid #a7f3d0;border-radius:8px;padding:22px 24px;margin:0 0 28px;">
<h3 style="margin:0 0 12px;font-size:1.1em;font-weight:700;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">No Middleman. No Data Risk. Always the Latest Models.</h3>
<p style="margin:0 0 12px;color:#374151;line-height:1.7;">Most "AI-powered" WordPress security products send your site's data to their own servers first, where it gets logged, processed, and potentially used to train their models, before eventually forwarding it to an AI provider. You're paying for a middleman who adds latency, a new privacy risk, and a business model dependency. When that vendor changes their pricing, gets acquired, or goes offline, your security tooling goes with it.</p>
<p style="margin:0 0 12px;color:#374151;line-height:1.7;">CloudScale works differently. <strong>Your WordPress data goes directly from your server to the AI provider's API</strong> (Anthropic or Google) with no intermediary, no CloudScale server, no third-party logging. You supply your own API key, so you have a direct relationship with the provider and full control over your data. CloudScale never sees your site data at all.</p>
<p style="margin:0;color:#374151;line-height:1.7;">When Anthropic releases Claude Opus 5 or Google ships Gemini 3, <strong>you get it immediately.</strong> No waiting for a plugin vendor to integrate it, no being held on an older model to protect their infrastructure margins. CloudScale ships support for the latest frontier models as soon as they launch. You choose your model, you own the key, you get the best intelligence available from day one.</p>
</div>

<h2 style="font-size:1.5em;font-weight:800;color:#0f172a;margin:0 0 14px;background:transparent!important;padding:0!important;border:none!important;">Why WordPress Plugin Stacks Are Broken (And How CloudScale Fixes It)</h2>

<p style="font-size:1.05em;color:#374151;margin:0 0 16px;line-height:1.75;">The average WordPress site runs 17 active plugins. Each one adds its own JavaScript, its own CSS, and its own HTTP requests to every page load. Each has its own update cycle, its own support forum, its own settings panel, and its own potential for conflict with every other plugin on the site. They were not designed to work together. They were each designed to solve one problem in isolation.</p>

<p style="font-size:1.05em;color:#374151;margin:0 0 16px;line-height:1.75;">The result is a fragmentation tax. You end up with five different places to check security settings. Your SMTP plugin doesn't know about your security plugin's admin restrictions. Your 2FA plugin doesn't know about your brute-force protection plugin's lockout logic. Your code highlighting plugin loads from a CDN that your Content Security Policy blocks. The more plugins you add, the more attack surface you expose, and the more cognitive overhead you carry every time you log into wp-admin.</p>

<p style="font-size:1.05em;color:#374151;margin:0 0 16px;line-height:1.75;">CloudScale is designed as a unified layer from the ground up. The security scanner knows about the login settings. The 2FA system integrates with the brute-force protection. The performance monitor shows load contribution from every component in one overlay. It was built as a system, not assembled from parts written by different teams for different purposes and then bolted together with activation hooks.</p>

<p style="font-size:1.05em;color:#374151;margin:0 0 24px;line-height:1.75;">One plugin to install. One plugin to update. One changelog to read. One GitHub repository to audit. One developer to contact when something breaks. That consolidation is itself a security feature: fewer moving parts means fewer attack vectors and fewer places for something to quietly go wrong.</p>

<div style="text-align:center;background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);border-radius:12px;padding:36px 32px;margin:0 0 36px;">
<h2 style="margin:0 0 10px;font-size:1.4em;font-weight:800;color:#fff;background:transparent!important;padding:0!important;border:none!important;">Ready to protect your site?</h2>
<p style="margin:0 0 24px;color:#94a3b8;font-size:1em;line-height:1.6;">Free, open-source, and installed in under 5 minutes. Google Gemini's free tier means zero cost for daily AI security scans.</p>
<div style="display:flex;flex-wrap:wrap;gap:12px;justify-content:center;">
<a href="https://andrewninjawordpress.s3.af-south-1.amazonaws.com/cloudscale-devtools.zip" style="display:inline-block;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;text-decoration:none;font-weight:700;font-size:.95em;padding:14px 32px;border-radius:8px;">⬇ Download Free Plugin</a>
<a href="https://github.com/andrewbakercloudscale/cloudscale-cyber-devtools" target="_blank" rel="noopener" style="display:inline-block;background:rgba(255,255,255,.12);color:#fff;text-decoration:none;font-weight:600;font-size:.95em;padding:14px 32px;border-radius:8px;border:1px solid rgba(255,255,255,.2);">View on GitHub →</a>
</div>
</div>

<h2 style="font-size:1.5em;font-weight:800;color:#0f172a;margin:0 0 14px;background:transparent!important;padding:0!important;border:none!important;">Installing the Plugin: Step by Step</h2>

<p style="font-size:1.05em;color:#374151;margin:0 0 20px;line-height:1.75;">The plugin isn't in the WordPress.org directory yet, so installation takes one extra step compared to a typical plugin. It's still under five minutes from download to your first security scan.</p>

<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:24px 28px;margin:0 0 12px;counter-reset:step;">

<div style="display:flex;gap:16px;align-items:flex-start;margin:0 0 20px;">
<div style="flex-shrink:0;width:36px;height:36px;background:#6366f1;color:#fff;font-weight:800;font-size:1em;border-radius:50%;display:flex;align-items:center;justify-content:center;">1</div>
<div>
<p style="margin:0 0 6px;font-weight:700;color:#0f172a;font-size:1.02em;">Download the plugin zip</p>
<p style="margin:0;color:#374151;line-height:1.65;">Click the <strong>Download Free Plugin</strong> button at the top of this page. Your browser will save a file called <code>cloudscale-devtools.zip</code>. Leave it zipped; WordPress handles the extraction.</p>
</div>
</div>

<div style="display:flex;gap:16px;align-items:flex-start;margin:0 0 20px;">
<div style="flex-shrink:0;width:36px;height:36px;background:#6366f1;color:#fff;font-weight:800;font-size:1em;border-radius:50%;display:flex;align-items:center;justify-content:center;">2</div>
<div>
<p style="margin:0 0 6px;font-weight:700;color:#0f172a;font-size:1.02em;">Open your WordPress dashboard</p>
<p style="margin:0;color:#374151;line-height:1.65;">Log in to your WordPress site and go to <strong>Plugins</strong> in the left sidebar. At the top of the page, click <strong>Add New Plugin</strong>, then click the <strong>Upload Plugin</strong> button that appears near the top of the screen.</p>
</div>
</div>

<div style="display:flex;gap:16px;align-items:flex-start;margin:0 0 20px;">
<div style="flex-shrink:0;width:36px;height:36px;background:#6366f1;color:#fff;font-weight:800;font-size:1em;border-radius:50%;display:flex;align-items:center;justify-content:center;">3</div>
<div>
<p style="margin:0 0 6px;font-weight:700;color:#0f172a;font-size:1.02em;">Upload and install</p>
<p style="margin:0;color:#374151;line-height:1.65;">Click <strong>Choose File</strong>, select the <code>cloudscale-devtools.zip</code> file you just downloaded, then click <strong>Install Now</strong>. WordPress uploads and unpacks the plugin in a few seconds.</p>
</div>
</div>

<div style="display:flex;gap:16px;align-items:flex-start;margin:0 0 20px;">
<div style="flex-shrink:0;width:36px;height:36px;background:#6366f1;color:#fff;font-weight:800;font-size:1em;border-radius:50%;display:flex;align-items:center;justify-content:center;">4</div>
<div>
<p style="margin:0 0 6px;font-weight:700;color:#0f172a;font-size:1.02em;">Activate</p>
<p style="margin:0;color:#374151;line-height:1.65;">After installation, WordPress shows you a success screen with an <strong>Activate Plugin</strong> button. Click it. The plugin is now running.</p>
</div>
</div>

<div style="display:flex;gap:16px;align-items:flex-start;margin:0 0 20px;">
<div style="flex-shrink:0;width:36px;height:36px;background:#6366f1;color:#fff;font-weight:800;font-size:1em;border-radius:50%;display:flex;align-items:center;justify-content:center;">5</div>
<div>
<p style="margin:0 0 6px;font-weight:700;color:#0f172a;font-size:1.02em;">Open the plugin</p>
<p style="margin:0;color:#374151;line-height:1.65;">In the WordPress sidebar, go to <strong>Tools → Cyber and Devtools</strong>. You'll land on the Home dashboard showing your current security posture at a glance.</p>
</div>
</div>

<div style="display:flex;gap:16px;align-items:flex-start;">
<div style="flex-shrink:0;width:36px;height:36px;background:#16a34a;color:#fff;font-weight:800;font-size:1em;border-radius:50%;display:flex;align-items:center;justify-content:center;">6</div>
<div>
<p style="margin:0 0 6px;font-weight:700;color:#0f172a;font-size:1.02em;">Run your first security scan</p>
<p style="margin:0;color:#374151;line-height:1.65;">Click the <strong>Security</strong> tab. If you don't have an API key yet, click the link to get a free Google Gemini key (see the AI setup guide in this page's Security section). Paste it in, click Save, then hit <strong>Run AI Cyber Audit</strong>. Your first report appears in about 30 seconds.</p>
</div>
</div>

</div>

<p style="font-size:.92em;color:#64748b;margin:0 0 16px;"><strong>Requirements:</strong> WordPress 6.0 or later, PHP 7.4 or later. Works on shared hosting, VPS, and managed WordPress hosting (WP Engine, Kinsta, Cloudways, etc.). Does not require SSH access or command-line tools.</p>

<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:18px 22px;margin:0 0 20px;">
<h3 style="margin:0 0 10px;font-size:1em;font-weight:700;color:#166534;background:transparent!important;padding:0!important;border:none!important;">Safe to try: what CloudScale does not do</h3>
<ul style="margin:0;padding-left:20px;color:#374151;font-size:.93em;line-height:1.9;">
<li>Does not modify any existing plugin settings or post content</li>
<li>No external CDN or third-party script dependencies - everything runs on your own server</li>
<li>Your site data goes direct to the AI provider API you choose; CloudScale never sees it</li>
<li>Fully open-source - every line of code is on GitHub and auditable by anyone</li>
<li>Clean uninstall: removes all plugin data from the database on deletion, no pollution</li>
<li>Does not conflict with existing security plugins - runs alongside Wordfence, iThemes, etc.</li>
</ul>
</div>

<div style="background:#fff7ed;border-left:4px solid #ea580c;border-radius:0 8px 8px 0;padding:16px 20px;margin:0 0 32px;">
<p style="margin:0;color:#374151;font-size:.95em;line-height:1.65;"><strong>Before you start hardening anything: take a backup.</strong> The Quick Fixes in this plugin modify wp-config.php, database tables, and server configuration. In the unlikely event something goes wrong, you want a restore point. The free <a href="https://andrewbaker.ninja/wordpress-plugin-help/cloudscale-backup-restore-help/" target="_blank" rel="noopener"><strong>CloudScale Backup and Restore plugin</strong></a> does one-click full-site backups (database + files) to local storage or cloud. Five minutes now saves hours later.</p>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin:0 0 32px;">
<div style="background:#fff5f5;border-left:4px solid #e53e3e;border-radius:0 8px 8px 0;padding:18px 20px;">
<h3 style="margin:0 0 10px;font-size:1em;font-weight:700;color:#1a202c;text-transform:uppercase;letter-spacing:.05em;background:transparent!important;padding:0!important;border:none!important;">🛡️ Security</h3>
<ul style="margin:0;padding-left:18px;color:#374151;font-size:.95em;line-height:1.8;">
<li><strong>AI Cyber Audit:</strong> scored security report in under 60 seconds using Claude or Gemini</li>
<li><strong>Deep Dive Scan:</strong> HTTP probes, DNS checks, TLS, PHP code analysis</li>
<li><strong>Quick Fixes:</strong> one-click hardening for common misconfigurations</li>
<li><strong>SSH Brute-Force Monitor:</strong> reads auth.log every 60 seconds, alerts on 10+ failures</li>
<li><strong>Scheduled Scans:</strong> daily/weekly background scans with email &amp; push alerts</li>
<li><strong>Server Logs:</strong> read PHP, WordPress and web server logs in-browser</li>
</ul>
</div>
<div style="background:#f0f9ff;border-left:4px solid #0e6b8f;border-radius:0 8px 8px 0;padding:18px 20px;">
<h3 style="margin:0 0 10px;font-size:1em;font-weight:700;color:#1a202c;text-transform:uppercase;letter-spacing:.05em;background:transparent!important;padding:0!important;border:none!important;">🔐 Login Security</h3>
<ul style="margin:0;padding-left:18px;color:#374151;font-size:.95em;line-height:1.8;">
<li><strong>Hide Login URL:</strong> move /wp-login.php to a secret slug</li>
<li><strong>Two-Factor Authentication:</strong> email OTP, TOTP (authenticator app), or passkeys</li>
<li><strong>Passkeys (WebAuthn):</strong> Face ID, Touch ID, Windows Hello, YubiKey</li>
<li><strong>Brute-Force Protection:</strong> per-account lockout after N failed attempts</li>
<li><strong>Force 2FA for admins:</strong> block dashboard access until 2FA is set up</li>
<li><strong>Test Account Manager:</strong> temporary accounts for Playwright / CI pipelines</li>
</ul>
</div>
<div style="background:#f0fdf4;border-left:4px solid #16a34a;border-radius:0 8px 8px 0;padding:18px 20px;">
<h3 style="margin:0 0 10px;font-size:1em;font-weight:700;color:#1a202c;text-transform:uppercase;letter-spacing:.05em;background:transparent!important;padding:0!important;border:none!important;">🛠️ Developer Tools</h3>
<ul style="margin:0;padding-left:18px;color:#374151;font-size:.95em;line-height:1.8;">
<li><strong>Syntax-highlighted Code Block:</strong> 190+ languages, 14 themes, bundled locally</li>
<li><strong>Code Block Migrator:</strong> batch-convert blocks from other plugins</li>
<li><strong>SQL Query Tool:</strong> read-only SELECT queries in-browser</li>
<li><strong>SMTP Mail:</strong> replace PHP mail() with authenticated SMTP</li>
<li><strong>CS Monitor:</strong> floating overlay showing DB queries, hooks, HTTP calls, assets, and PHP errors on every page</li>
<li><strong>PHP-FPM Monitor:</strong> live worker status, saturation alerts, and optional auto-restart from the host OS</li>
<li><strong>Custom 404 Page:</strong> branded 404 with 7 playable mini-games and leaderboard</li>
</ul>
</div>
<div style="background:#fafafa;border-left:4px solid #6366f1;border-radius:0 8px 8px 0;padding:18px 20px;">
<h3 style="margin:0 0 10px;font-size:1em;font-weight:700;color:#1a202c;text-transform:uppercase;letter-spacing:.05em;background:transparent!important;padding:0!important;border:none!important;">What's Covered Below</h3>
<ul style="margin:0;padding-left:18px;color:#374151;font-size:.95em;line-height:1.8;">
<li><a href="#cs-section-hide-login" style="color:#6366f1;">Hide Login URL</a> setup and how it works</li>
<li><a href="#cs-section-2fa" style="color:#6366f1;">Two-Factor Authentication</a> and enforcement</li>
<li><a href="#cs-section-passkeys" style="color:#6366f1;">Passkeys</a> registration and browser support</li>
<li><a href="#cs-section-security" style="color:#6366f1;">AI Cyber Audit</a> with full API key setup guides</li>
<li><a href="#cs-section-code-block" style="color:#6366f1;">Code Block</a> themes, languages, and usage</li>
<li><a href="#cs-section-sql-tool" style="color:#6366f1;">SQL Query Tool</a> and built-in queries</li>
<li><a href="#cs-section-server-logs" style="color:#6366f1;">Server Logs</a> viewer and tail mode</li>
<li><a href="#cs-section-optimizer" style="color:#6366f1;">Plugin Optimizer</a> - plugin stack scanner and AI debugging</li>
<li><a href="#cs-section-cs-monitor" style="color:#6366f1;">CS Monitor</a> - per-page performance overlay for admins</li>
<li><a href="#cs-section-fpm-monitor" style="color:#6366f1;">PHP-FPM Monitor</a> - live worker status and saturation alerting</li>
</ul>
</div>
</div>

<h2 style="font-size:1.5em;font-weight:800;color:#0f172a;margin:36px 0 20px;background:transparent!important;padding:0!important;border:none!important;">Who CloudScale Is For</h2>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px;margin:0 0 32px;">
<div style="background:#f0f9ff;border-top:3px solid #0e6b8f;border-radius:8px;padding:22px 22px;">
<h3 style="margin:0 0 10px;font-size:1em;font-weight:700;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">For Developers</h3>
<p style="margin:0 0 12px;color:#374151;font-size:.93em;line-height:1.7;">You manage multiple client sites. You need a SQL query tool, server log viewer, syntax-highlighted code blocks, and SMTP in one place - not six separate plugins to install, configure, and update on every new site.</p>
<p style="margin:0;color:#374151;font-size:.93em;line-height:1.7;">CloudScale gives you the full dev toolkit. The AI audit means every client site gets enterprise-grade security analysis at zero cost to you or them.</p>
</div>
<div style="background:#fff7ed;border-top:3px solid #ea580c;border-radius:8px;padding:22px 22px;">
<h3 style="margin:0 0 10px;font-size:1em;font-weight:700;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">For Site Owners</h3>
<p style="margin:0 0 12px;color:#374151;font-size:.93em;line-height:1.7;">You run a WooCommerce store or a content site. Security isn't your day job, but getting hacked would be catastrophic. You need protection that works without requiring you to understand every CVE or hardening flag.</p>
<p style="margin:0;color:#374151;font-size:.93em;line-height:1.7;">Run the AI audit once. Work through Quick Fixes. Enable 2FA. You're done - and better protected than most sites paying $300/year for plugin subscriptions.</p>
</div>
<div style="background:#fdf4ff;border-top:3px solid #9333ea;border-radius:8px;padding:22px 22px;">
<h3 style="margin:0 0 10px;font-size:1em;font-weight:700;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">For Agencies</h3>
<p style="margin:0 0 12px;color:#374151;font-size:.93em;line-height:1.7;">You deploy sites for clients. Every additional plugin is a support burden, a potential conflict, and an update to manage across dozens of installs. Your clients ask why their security isn't working and you're the one who has to answer.</p>
<p style="margin:0;color:#374151;font-size:.93em;line-height:1.7;">CloudScale replaces the entire standard stack in one install. One plugin to update, one changelog to read, one place to look when something goes wrong.</p>
</div>
</div>`,

    sections: [
        { id: 'home',       label: 'Home Dashboard',       file: 'panel-home.png',        tabSelector: 'a[href*="tab=home"]',       elementSelector: '#cs-panel-home',
          intro: 'The Home tab is the starting point for every CloudScale session. Configure your AI provider and API key here, enable scheduled background scans, and set up email and push alert notifications. Everything in the plugin - the AI Cyber Audit, site audit, and debugging tools - flows from the credentials you enter on this tab.',
          altText: 'CloudScale Cyber Devtools home dashboard showing AI provider setup with Claude and Gemini API key configuration',
          jsBeforeShot: () => {
            document.querySelectorAll('input[type="password"], input[id*="key"], input[id*="api"]').forEach(function(el){ el.value = ''; });
          } },
        { id: 'hide-login', label: 'Hide Login URL',        file: 'panel-hide-login.png',  tabSelector: 'a[href*="tab=login"]',    elementSelector: '#cs-panel-hide-login',
          intro: 'Moves your WordPress login page from the default <code>/wp-login.php</code> to a secret URL you choose. Bots and automated attack scripts probe the default path thousands of times a day - if they can\'t find the login form, they can\'t attack it.',
          altText: 'WordPress Hide Login URL settings panel - move wp-login.php to a secret URL to block automated bot attacks',
          jsBeforeShot: () => {
            var s = document.getElementById('cs-login-slug');
            if (s) s.value = '••••••••••••';
            var u = document.getElementById('cs-current-login-url');
            if (u) { u.textContent = window.location.origin + '/[your-secret-url]/'; u.href = '#'; }
          } },
        { id: '2fa',        label: 'Two-Factor Auth',       file: 'panel-2fa.png',         tabSelector: 'a[href*="tab=login"]',    elementSelector: '#cs-panel-2fa',
          intro: 'Adds a second authentication step after the password so a stolen or leaked password alone is never enough to break in. Supports email OTP, authenticator apps (TOTP), and passkeys - all three methods included free.',
          altText: 'WordPress two-factor authentication settings with email OTP, TOTP authenticator app, and passkeys' },
        { id: 'passkeys',   label: 'Passkeys (WebAuthn)',   file: 'panel-passkeys.png',    tabSelector: 'a[href*="tab=login"]',    elementSelector: '#cs-panel-passkeys',
          intro: 'Replace passwords entirely with biometric login: Face ID, Touch ID, Windows Hello, or a hardware security key. Passkeys are cryptographically bound to your exact domain - unlike TOTP codes, they cannot be phished by a fake login page.',
          altText: 'WordPress passkeys WebAuthn registration supporting Face ID, Touch ID and hardware security key login' },
        { id: 'session',    label: 'Session Duration',      file: 'panel-session.png',     tabSelector: 'a[href*="tab=login"]',    elementSelector: '#cs-panel-session',
          intro: 'Controls how long WordPress login sessions remain valid before users must re-authenticate. The default is 2 days. Shorten this for high-security admin accounts or extend it for trusted internal teams who find frequent re-login disruptive.',
          altText: 'WordPress session duration settings controlling how long login cookies remain valid' },
        { id: 'brute-force',label: 'Brute-Force Protection',file: 'panel-brute-force.png', tabSelector: 'a[href*="tab=login"]',    elementSelector: '#cs-panel-brute-force',
          intro: 'Locks an account temporarily after a configurable number of consecutive failed login attempts. Protection is per-username rather than per-IP, so distributed attacks spread across thousands of IP addresses are blocked just as effectively as single-source attacks.',
          altText: 'WordPress brute force login protection with account lockout and username enumeration blocking' },
        { id: 'ssh-monitor',label: 'SSH Brute-Force Monitor',file: 'panel-ssh-monitor.png', tabSelector: 'a[href*="tab=login"]',   elementSelector: '#cs-panel-ssh-monitor',
          intro: 'Reads your server\'s auth.log every 60 seconds to count SSH failed login attempts. When the count exceeds your threshold in a rolling window, it fires an instant alert via email and push notification. Works alongside fail2ban - this plugin detects and alerts; fail2ban does the blocking.',
          altText: 'WordPress SSH brute force monitor reading auth.log with email and ntfy.sh push notifications' },
        { id: 'security',   label: 'AI Cyber Audit',        file: 'panel-security.png',    tabSelector: 'a[href*="tab=security"]', elementSelector: '#cs-panel-ai-cyber-audit',
          intro: 'Uses frontier AI - Anthropic Claude or Google Gemini - to analyse your entire WordPress installation and return a prioritised, scored security report in under 60 seconds. Unlike signature-based scanners, the AI reasons from first principles: it reads your actual configuration and code, identifies risk combinations no database can match, and gives you specific fix steps for your exact setup.',
          altText: 'WordPress AI security audit showing a perfect score with Claude 4 and Gemini 2.5 Pro on a free security plugin',
          jsBeforeShot: () => {
            // Inject demo data: score 100, no real findings
            var r = document.getElementById('cs-vuln-results');
            if (r) {
                r.style.display = 'block';
                r.innerHTML =
                    '<div class="cs-audit-header">' +
                    '<div class="cs-audit-score-circle cs-audit-score-excellent">' +
                    '<span class="cs-audit-score-num">100</span>' +
                    '<span class="cs-audit-score-lbl">Excellent</span>' +
                    '</div>' +
                    '<div class="cs-audit-meta">' +
                    '<p class="cs-audit-summary-text">Your WordPress installation demonstrates exceptional security. All critical controls are in place: security headers, 2FA, hidden login URL, disabled file editing, and no vulnerable plugins. Nothing to remediate.</p>' +
                    '<span class="cs-audit-meta-line">Model: claude-sonnet-4-6 · Auto AI Model</span>' +
                    '</div></div>' +
                    '<div class="cs-audit-section cs-audit-sec-good">' +
                    '<h4 class="cs-audit-section-title">Good Practices (8)</h4>' +
                    '<div class="cs-audit-good-item"><span class="cs-audit-good-check">✓</span><div><strong>Security headers configured:</strong> X-Content-Type-Options, X-Frame-Options, Referrer-Policy, and Permissions-Policy all set.</div></div>' +
                    '<div class="cs-audit-good-item"><span class="cs-audit-good-check">✓</span><div><strong>WordPress auto-updates enabled:</strong> Core security patches applied automatically.</div></div>' +
                    '<div class="cs-audit-good-item"><span class="cs-audit-good-check">✓</span><div><strong>File editing disabled:</strong> DISALLOW_FILE_EDIT is set in wp-config.php.</div></div>' +
                    '<div class="cs-audit-good-item"><span class="cs-audit-good-check">✓</span><div><strong>Debug mode off in production:</strong> WP_DEBUG and display_errors are disabled.</div></div>' +
                    '<div class="cs-audit-good-item"><span class="cs-audit-good-check">✓</span><div><strong>Strong administrator credentials:</strong> No default or weak passwords detected.</div></div>' +
                    '<div class="cs-audit-good-item"><span class="cs-audit-good-check">✓</span><div><strong>Login URL hidden:</strong> Custom login path protects against automated brute-force attempts.</div></div>' +
                    '<div class="cs-audit-good-item"><span class="cs-audit-good-check">✓</span><div><strong>Two-factor authentication active:</strong> All administrator accounts protected with 2FA.</div></div>' +
                    '<div class="cs-audit-good-item"><span class="cs-audit-good-check">✓</span><div><strong>XML-RPC disabled:</strong> Endpoint blocked to prevent credential-stuffing attacks.</div></div>' +
                    '</div>';
            }
            // Make all quick fixes show as fixed
            document.querySelectorAll('#cs-quick-fixes-list [data-fix-id]').forEach(function(btn) {
                var wrap = btn.closest('div[style*="flex-shrink"]') || btn.parentElement;
                if (wrap) wrap.innerHTML = '<span style="font-size:12px;color:#16a34a;font-weight:600;">Fixed \u2713</span>';
            });
            // Hide AI settings form (scope to security panel - .cs-sec-settings appears in other panels too)
            var panel = document.getElementById('cs-panel-security');
            if (panel) {
                var ctrl = panel.querySelector('.cs-sec-settings');
                if (ctrl) ctrl.style.display = 'none';
                var intro = panel.querySelector('.cs-tab-intro');
                if (intro) intro.style.display = 'none';
            }
            // Also scrub any API key inputs that might be visible anywhere
            document.querySelectorAll('input[id*="key"], input[id*="api"], input[type="password"]').forEach(function(el) {
                el.value = '';
            });
          }
        },
        { id: 'site-audit', label: 'AI Site Auditor',        file: 'panel-site-audit.png',  tabSelector: 'a[href*="tab=site-audit"]', elementSelector: '#cs-panel-site-audit',
          intro: 'One button scans all your published content and database, then returns a prioritised list of SEO gaps, thin content, missing images, database bloat, and security misconfigurations - each with a specific fix instruction. No external crawlers, no data sent to third parties, no Screaming Frog licence required.',
          altText: 'WordPress AI site auditor scanning SEO, content, performance, and database health with prioritised findings' },
        { id: 'threat-monitor', label: 'Threat Monitor',    file: 'panel-threat-monitor.png', tabSelector: 'a[href*="tab=security"]', elementSelector: '#cs-panel-threat-monitor',
          intro: 'Runs three passive background checks every 5 minutes: file integrity monitoring (detects unexpected changes to WordPress core files), new administrator alerts (fires the instant an admin account is created or promoted), and web probe detection (counts requests to sensitive endpoints and alerts on sudden spikes).',
          altText: 'WordPress threat monitor showing file integrity checking, new admin alerts, and web probe detection',
          jsBeforeShot: () => {
            // Replace any red alert divs (which show real usernames/timestamps) with the clean green status
            var tm = document.getElementById('cs-panel-threat-monitor');
            if (!tm) return;
            tm.querySelectorAll('div[style*="color:#dc2626"]').forEach(function(el) {
                el.style.color = '#16a34a';
                el.style.fontWeight = '600';
                el.textContent = '✓ No new admin accounts detected.';
            });
          } },
        { id: 'code-block', label: 'Code Block',             file: 'panel-code-block.png',  tabSelector: 'a[href*="tab=debug"]', elementSelector: '#cs-panel-code-settings',
          intro: 'Syntax-highlighted code blocks powered by highlight.js, running entirely on your own server with zero CDN calls. Supports 190+ languages and 14 professional colour themes - completely free, with no impact on your Core Web Vitals score.',
          altText: 'WordPress syntax-highlighted code block settings with 190 languages, 14 themes, no CDN, completely free' },
        { id: 'migrator',   label: 'Code Block Migrator',   file: 'panel-migrator.png',    tabSelector: 'a[href*="tab=debug"]', elementSelector: '#cs-panel-migrator',
          intro: 'Converts all posts using legacy code block formats - WordPress core blocks, SyntaxHighlighter, Enlighter shortcodes - to CloudScale blocks in a single batch operation. Scan → preview the diff per post → migrate everything in one click, with no manual post editing.',
          altText: 'WordPress code block migrator for batch converting from Enlighter, SyntaxHighlighter, and other plugins' },
        { id: 'sql-tool',   label: 'SQL Query Tool',        file: 'panel-sql-tool.png',    tabSelector: 'a[href*="tab=debug"]',   elementSelector: '#cs-panel-sql',
          intro: 'A read-only SQL query interface inside wp-admin - inspect tables, check row counts, trace slow queries, and find database bloat without phpMyAdmin, SSH access, or exposing your database port. Architecturally impossible to delete or modify data.',
          altText: 'WordPress read-only SQL query tool for safe database inspection inside wp-admin without phpMyAdmin' },
        { id: 'server-logs',label: 'Server Logs',           file: 'panel-server-logs.png', tabSelector: 'a[href*="tab=debug"]',   elementSelector: '#cs-panel-logs',
          intro: 'Browse your PHP error log, WordPress debug log, and web server logs directly in the dashboard - with live search, severity filtering, and auto-refresh tail mode. No SSH, no cPanel, no asking your hosting provider to email you a file.',
          altText: 'WordPress server log viewer for PHP error logs, debug logs, and web server logs without SSH access',
          jsBeforeShot: () => {
            // Trim log output to 3 visible lines so the screenshot stays compact
            var out = document.getElementById('cs-logs-output');
            if (out) { var lines = out.querySelectorAll('.cs-log-line'); lines.forEach(function(l,i){ if(i>=3) l.style.display='none'; }); }
          },
          jsAfterShot: () => {
            var out = document.getElementById('cs-logs-output');
            if (out) { out.querySelectorAll('.cs-log-line').forEach(function(l){ l.style.display=''; }); }
          } },
        { id: 'optimizer',  label: 'Plugin Optimizer',      file: 'panel-optimizer.png',   tabSelector: 'a[href*="tab=optimizer"]',  elementSelector: '.cs-tab-content.active',
          intro: 'Two tools in one tab: a plugin stack scanner that maps your installed plugins against everything CloudScale already replaces (so you know exactly which ones to remove), and an AI debugging assistant that diagnoses PHP errors, stack traces, and WordPress warnings instantly with step-by-step fix instructions.',
          altText: 'WordPress plugin stack scanner showing which plugins CloudScale replaces with AI debugging assistant' },
        { id: 'plugin-stack',label: 'Plugin Stack Scanner',   file: 'panel-plugin-stack.png', tabSelector: 'a[href*="tab=optimizer"]', elementSelector: '#cs-panel-plugin-stack', jsClip: true,
          intro: 'Scans your installed plugins against a curated list of functionality that CloudScale already provides - security scanners, 2FA plugins, SMTP mailers, code highlighting, SQL tools, and log viewers. Shows exactly which plugins are now redundant and safe to remove, reducing your attack surface and update burden.',
          altText: 'WordPress plugin stack scanner showing redundant plugins that CloudScale replaces with fewer attack surface' },
        { id: 'update-risk',label: 'Update Risk Scorer',     file: 'panel-update-risk.png',  tabSelector: 'a[href*="tab=optimizer"]', elementSelector: '#cs-panel-update-risk', jsClip: true,
          intro: 'Uses AI to read each pending plugin update\'s changelog from WordPress.org and classify it as Patch (safe to apply now), Minor (new features, review first), or Breaking (major changes, test on staging). Prevents the most common cause of site breakage: blindly applying all updates at once.',
          altText: 'WordPress AI update risk scorer rating pending plugin updates as Patch, Minor or Breaking before applying them' },
        { id: 'uptime-monitor', label: 'Uptime Monitor',     file: 'panel-uptime-monitor.png', tabSelector: 'a[href*="tab=debug"]', elementSelector: '#cs-panel-uptime-monitor', jsClip: true,
          intro: 'Deploys a Cloudflare Worker that probes a deep readiness endpoint every 60 seconds from the Cloudflare edge. Unlike basic uptime monitors that only check for a 200 response, this endpoint verifies database connectivity, PHP-FPM worker saturation, and WordPress boot. Alerts fire via push notification even if your server is completely offline.',
          altText: 'WordPress Cloudflare uptime monitor showing deep readiness probe with database and PHP-FPM health checks' },
        { id: 'cs-monitor', label: 'CS Monitor',             file: 'panel-cs-monitor.png',  tabSelector: 'a[href*="tab=debug"]',   elementSelector: '#cs-panel-cs-monitor',
          intro: 'A floating DevTools-style performance panel that appears on every WordPress admin screen and frontend page for logged-in administrators. Surfaces database queries, HTTP calls, hook timings, PHP errors, assets, and template resolution in one overlay - without switching tools or tailing log files.',
          altText: 'CS Monitor floating performance panel showing DB queries, hooks, HTTP calls, and PHP errors on every WordPress page' },
        { id: 'fpm-monitor',label: 'FPM Monitor',            file: 'panel-fpm-monitor.png', tabSelector: 'a[href*="tab=debug"]',   elementSelector: '#cs-panel-debug',
          intro: 'PHP-FPM (FastCGI Process Manager) is the process pool that serves every WordPress page request. When all workers are occupied - during a traffic spike, a slow database query, or a runaway loop - new requests queue and the site freezes. The FPM Monitor shows live worker counts and memory usage, and alerts you the moment saturation builds - running as a host-level script outside PHP so it fires even when every PHP worker is consumed.',
          altText: 'PHP-FPM saturation monitor showing live worker counts and memory usage with auto-restart and ntfy alerts',
          jsBeforeShot: () => { const el = document.getElementById('cs-perf'); if (el) el.style.display = 'none'; },
          jsAfterShot:  () => { const el = document.getElementById('cs-perf'); if (el) el.style.display = ''; } },
        { id: 'test-accounts', label: 'Test Account Manager', file: 'panel-test-accounts.png', tabSelector: 'a[href*="tab=login"]', elementSelector: '#cs-panel-test-accounts',
          intro: 'Creates dedicated WordPress test users for Playwright and automated testing. Provides a session API that generates temporary admin cookies without triggering 2FA - so your test suite can log in as a real administrator without disabling the two-factor authentication protecting your live site.',
          altText: 'WordPress Playwright test account manager showing shared secret session API and test user list with active sessions' },
        { id: 'opcache',    label: 'OPcache Monitor',        file: 'panel-opcache.png',     tabSelector: 'a[href*="tab=debug"]',   elementSelector: '#cs-panel-opcache',
          intro: 'Displays the current PHP OPcache status: memory usage, hit rate, and the number of cached vs. uncached scripts. A hit rate below 90% or a full cache means PHP is recompiling scripts on every request, significantly slowing your site. Includes a one-click Reset button to flush the cache after code deployments.',
          altText: 'PHP OPcache status monitor showing memory usage, hit rate and cached script count in WordPress admin' },
        { id: 'smtp',       label: 'SMTP Mailer',            file: 'panel-smtp.png',        tabSelector: 'a[href*="tab=mail"]',    elementSelector: '#cs-panel-smtp',
          intro: 'Replaces WordPress\'s unreliable PHP mail() function with authenticated SMTP delivery. Supports Gmail, Outlook, Amazon SES, Mailgun, and any standard SMTP server. Includes a test-send button and a persistent activity log showing every outgoing message with delivery status.',
          altText: 'WordPress SMTP mailer settings replacing PHP mail with authenticated Gmail Outlook or Mailgun delivery' },
        { id: 'email-log',  label: 'Email Activity Log',     file: 'panel-email-log.png',   tabSelector: 'a[href*="tab=mail"]',    elementSelector: '#cs-panel-email-log', trimRows: true,
          intro: 'Logs every email sent by WordPress - regardless of whether SMTP is enabled - with the subject, recipient, timestamp, and delivery status. Click any row to view the full email body. Invaluable for debugging WooCommerce order notifications, password reset failures, and contact form delivery issues.',
          altText: 'WordPress email activity log showing all sent emails with subject, recipient and delivery status in admin' },
        { id: 'thumbnails', label: 'Thumbnails & Open Graph', file: 'panel-thumbnails.png', tabSelector: 'a[href*="tab=thumbnails"]', elementSelector: '#cs-panel-thumbs-checker',
          intro: 'Diagnoses social sharing thumbnail failures: checks your Open Graph meta tags and featured image setup, scans recent posts for missing images, and audits your Cloudflare image caching configuration. Social media platforms and link preview tools (Slack, Teams, WhatsApp) rely on Open Graph tags to generate preview cards - when these are wrong, links share as plain text with no image.',
          altText: 'WordPress Open Graph thumbnail diagnostics checking social sharing images, Cloudflare caching and media library' },
    ],

    docs: {
        'hide-login': `
<div style="background:#f0fdf4;border-left:4px solid #16a34a;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">🔐 Stop Bots Before They Even See Your Login Page</h2>
<p style="margin:0 0 10px;color:#374151;">Every WordPress site on the internet is probed by bots testing <code>/wp-login.php</code> around the clock. These are not targeted attacks against you specifically - they are automated credential-stuffing scripts running against millions of sites simultaneously, trying breached username and password combinations at scale. If they can reach your login form, they will keep trying indefinitely. Hide Login URL removes the form from the default URL entirely: bots get a 404 and move on to easier targets.</p>
<p style="margin:0;color:#374151;"><strong>Competing plugins charge $49-$99/year</strong> for this feature (iThemes Security Pro, All-in-One Security Premium). CloudScale includes it free, bundled with 2FA and Passkeys in the same plugin, so there is no juggling three separate security plugins that need to know about each other.</p>
</div>

<p>The mechanism is simple and reliable. When enabled, a WordPress <code>init</code> hook at priority 1 intercepts any request matching your chosen secret slug and serves the login form for that request. No redirect, no URL change visible in the browser's address bar - the form just loads at your secret path. Direct requests to <code>/wp-login.php</code> return a clean 404. Internal WordPress links - password reset emails, admin bar logout links, plugin redirect-after-login URLs - all automatically reference your secret URL rather than the default. You do not need to configure anything else; the change propagates through WordPress automatically.</p>

<p>Hide Login URL works best in combination with 2FA (also on this tab). Hiding the login URL removes the attack surface for automated bot traffic. 2FA ensures that even if someone discovers your secret URL (through a browser history leak or social engineering), a stolen password alone is still not enough to break in. Together they cover two different threat categories.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Settings</h3>
<ul>
<li><strong>Enable Hide Login:</strong> master switch. When enabled, <code>/wp-login.php</code> returns a 404. Your secret slug serves the login form. When disabled, everything reverts to WordPress defaults with no other changes required.</li>
<li><strong>Login slug:</strong> the path segment after your domain where the login form will live. For example, entering <code>team-portal</code> means your login URL becomes <code>yoursite.com/team-portal/</code>. Avoid predictable words: <code>login</code>, <code>admin</code>, <code>dashboard</code>, <code>wp-admin</code>, and <code>signin</code> are commonly tried by automated scanners and provide little security benefit. A two-word phrase with a number (e.g. <code>launch-control-7</code>) is both memorable and not in any scanner's dictionary.</li>
</ul>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Setup (30 seconds)</h3>
<ol>
<li>Toggle <strong>Enable Hide Login</strong> on.</li>
<li>Enter your secret slug in the Login Slug field.</li>
<li>Click <strong>Save</strong>.</li>
<li><strong>Bookmark the new URL immediately</strong> before navigating away. The current URL is shown on the settings panel after saving.</li>
</ol>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">What is not affected</h3>
<p>WP-CLI, XML-RPC, the REST API, and WP-Cron all bypass the login URL check entirely. This means automated processes that authenticate against WordPress continue working without any configuration changes. The hide-login feature targets human browser-based login attempts only.</p>

<div style="background:#fef9c3;border-left:4px solid #ca8a04;border-radius:0 8px 8px 0;padding:18px 22px;margin:20px 0 0;">
<h3 style="margin:0 0 8px;font-size:1em;font-weight:700;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">If you forget your secret URL</h3>
<p style="margin:0 0 10px;color:#374151;line-height:1.7;">If you lose track of your custom login URL, retrieve it without logging in via WP-CLI from your server:</p>
<code style="background:#fff;border:1px solid #e5e7eb;padding:6px 10px;border-radius:4px;font-size:.9em;display:block;margin:8px 0;">wp option get csdt_devtools_login_slug</code>
<p style="margin:8px 0 0;color:#374151;line-height:1.7;">Or query your database directly: <code>SELECT option_value FROM wp_options WHERE option_name = 'csdt_devtools_login_slug'</code>. If you cannot access the server at all, temporarily disabling the plugin via FTP (rename the plugin folder) will re-enable the default <code>/wp-login.php</code> path.</p>
</div>`,

        '2fa': `
<div style="background:#fdf4ff;border-left:4px solid #9333ea;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">🔑 A Stolen Password Should Never Be Enough to Break In</h2>
<p style="margin:0 0 10px;color:#374151;">Passwords get leaked in data breaches, reused across sites, and phished out of users. Two-factor authentication (2FA) means an attacker who has your password still cannot log in. They also need physical access to your phone, email inbox, or hardware key. For WordPress admins, 2FA is the single most effective account protection you can add.</p>
<p style="margin:0;color:#374151;"><strong>WP 2FA Pro charges $79/year.</strong> Wordfence Premium (which includes 2FA) charges $119/year. CloudScale gives you email OTP, TOTP authenticator apps, and Passkeys (all three methods) completely free, in the same plugin you use for everything else.</p>
</div>

<p>The attack scenario that 2FA stops is straightforward. Your WordPress admin password appears in a credential-stuffing database from a breach at an unrelated service. An automated bot tries it against your login page. Without 2FA, that is game over. With 2FA, the attacker also needs to be holding your phone or have access to your email at the same moment - a combination that is effectively impossible in a mass automated attack. Credential stuffing (trying breached username/password pairs at scale) is responsible for the majority of WordPress account compromises, and it is stopped entirely by 2FA.</p>

<p>CloudScale implements all three major 2FA methods in a single plugin. You can start with email OTP (no app required, works immediately for every user) and upgrade to TOTP or Passkeys for higher-security accounts at your own pace. All three methods are available to users simultaneously - each person can configure whichever they prefer, and you can enforce a minimum method for administrators.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Two-Factor Methods</h3>
<ul>
<li><strong>Email OTP:</strong> a 6-digit code sent to the user's WordPress email address after successful password entry. No app or prior setup required by the user. Each code expires after 10 minutes and is single-use. Best for non-technical users or as a fallback for when someone does not have their authenticator app available. Requires working SMTP - configure the SMTP mailer on the Email tab first so codes reliably reach inboxes rather than going to spam.</li>
<li><strong>Authenticator app (TOTP):</strong> standard RFC 6238 time-based one-time passwords, compatible with Google Authenticator, Authy, 1Password, Bitwarden, and any TOTP app. Generates a new 6-digit code every 30 seconds entirely on the device - no network connection required. More secure than email OTP because it is immune to email interception and works even when your email is down. Users scan a QR code once from their profile to link their account, then they are set up permanently.</li>
<li><strong>Passkey (WebAuthn):</strong> replaces the second-factor code prompt with a biometric confirmation: Face ID, Touch ID, Windows Hello, or a hardware security key tap. The fastest and most phishing-resistant method available. Unlike TOTP codes, which a fake login page can intercept and replay in real time, passkeys are cryptographically bound to your site's exact domain and cannot be used on any other URL. See the Passkeys section for full setup details.</li>
</ul>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Brute-Force Protection</h3>
<ul>
<li><strong>Maximum login attempts:</strong> the number of consecutive failed password attempts before the account is temporarily locked. The default is 5 attempts. Lower this to 3 for high-security sites where you want to be aggressive, or raise it to 10 if legitimate users frequently mistype their passwords and you are receiving lockout support requests. Each failed attempt is recorded with the IP address, timestamp, and username tried.</li>
<li><strong>Lockout duration:</strong> how long (in minutes) a locked account is blocked from attempting login. The default 5-minute lockout stops most automated credential-stuffing scripts without seriously inconveniencing real users who mistyped their password. For sites with only administrator accounts (no customer-facing logins), a longer lockout (60 minutes or more) adds significantly more friction to automated attacks with no meaningful downside.</li>
</ul>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Admin Enforcement</h3>
<ul>
<li><strong>Force 2FA for administrators:</strong> when enabled, any administrator who has not yet configured a 2FA method is blocked from accessing the WordPress dashboard after login. They see a prompt to configure 2FA and cannot proceed until they do. There is no bypass. This ensures 2FA is never accidentally skipped on high-privilege accounts, which is the most common failure mode: admins know they should set it up, intend to do it later, and never do.</li>
<li><strong>Grace period:</strong> when you first enable forced 2FA, administrators who haven't configured 2FA yet are given this many days before enforcement kicks in. This prevents locking out an existing admin team the moment you flip the switch. After the grace period expires, unconfigured accounts are blocked at login until 2FA is set up.</li>
</ul>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Session Duration</h3>
<ul>
<li><strong>Custom session length (days):</strong> overrides WordPress's default session timeout (2 days for "Remember Me" sessions, 2 hours otherwise). When set, a persistent cookie keeps the session alive for the specified number of days across browser restarts - useful for admin team members who find constant re-authentication disruptive on a daily-use machine. The session is invalidated immediately when the user logs out. Note: longer sessions extend the window during which a stolen session cookie would be usable. For high-security admin accounts, keep sessions short or leave this at WordPress defaults.</li>
</ul>

<div style="background:#fef9c3;border-left:4px solid #ca8a04;border-radius:0 8px 8px 0;padding:18px 22px;margin:20px 0 0;">
<h3 style="margin:0 0 8px;font-size:1em;font-weight:700;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">If you or a user gets locked out</h3>
<p style="margin:0 0 10px;color:#374151;line-height:1.7;">If brute-force protection has locked a legitimate account and you need to unlock it immediately, run this WP-CLI command from your server (replace the IP address with the locked address):</p>
<code style="background:#fff;border:1px solid #e5e7eb;padding:6px 10px;border-radius:4px;font-size:.9em;display:block;margin:8px 0;">wp option delete csdt_login_attempts_1.2.3.4</code>
<p style="margin:8px 0 0;color:#374151;line-height:1.7;">To clear all lockouts at once: <code>DELETE FROM wp_options WHERE option_name LIKE 'csdt_login_attempts_%'</code> via the SQL Query Tool on the Debug tab, or via phpMyAdmin.</p>
</div>`,

        'passkeys': `
<div style="background:#fff7ed;border-left:4px solid #ea580c;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">🪪 The Most Secure WordPress Login Method Available. And It's Free.</h2>
<p style="margin:0 0 10px;color:#374151;">Even TOTP codes can be phished: a fake login page captures your password and OTP code in real time and replays them instantly. Passkeys cannot be phished this way. They are cryptographically bound to your site's exact domain; a fake domain simply cannot trigger your passkey. This is the authentication standard used by Apple, Google, and Microsoft for their own products, now available for your WordPress site at no cost.</p>
<p style="margin:0;color:#374151;"><strong>Most WordPress passkey plugins don't exist as free products.</strong> The handful that do charge $50–$100/year for a commercial FIDO2 implementation. CloudScale's passkey support is a full WebAuthn/FIDO2 implementation, open-source, and completely free.</p>
</div>
<p><strong>How it works:</strong> When you register a passkey, your device generates a public/private key pair. The private key never leaves your device. At login, your server sends a random challenge; your device signs it with the private key; the server verifies the signature against your stored public key. No secret is ever transmitted over the network.</p>
<p><strong>Supported authenticators:</strong> Face ID (iPhone, iPad, Mac), Touch ID (MacBook), Windows Hello (fingerprint, face, PIN), Android biometrics, and hardware security keys (YubiKey 5 series, Google Titan, etc.).</p>
<p><strong>Registering a passkey:</strong></p>
<ol>
<li>Click <em>+ Add Passkey</em> and give it a label (e.g. "iPhone 16 Pro", "YubiKey").</li>
<li>Click <em>Register</em> and your browser will prompt for biometric confirmation or a hardware key tap.</li>
<li>The passkey is saved to your account. Register one per device you log in from.</li>
</ol>
<p><strong>Browser support:</strong> Chrome 108+, Safari 16+, Edge 108+, Firefox 122+. If a browser doesn't support passkeys, the login flow falls back to email OTP automatically, so no user is ever locked out.</p>`,

        'security': `
<div style="background:#fff5f5;border-left:4px solid #c0392b;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">🛡️ A Security Consultant in Your WordPress Dashboard, for Free</h2>
<p style="margin:0 0 10px;color:#374151;">A professional WordPress security audit costs $500–$5,000 and takes days to schedule. Generic security checklists from free plugins tell you what to check but not what it means for your specific site. CloudScale connects directly to the world's most capable AI models: <strong>Anthropic Claude 4</strong> and <strong>Google Gemini 2.5 Pro</strong>. It analyses your entire WordPress installation and delivers a scored, prioritised report with specific remediation steps in under 60 seconds. The same class of AI used by enterprise security teams, working on your site.</p>
<p style="margin:0;color:#374151;"><strong>Wordfence Premium costs $119/year. Sucuri costs $199/year. WPScan costs $25–$75/month.</strong> These tools run signature-based scans; they match known patterns against a database. They cannot identify novel threats, unusual configuration combinations, or the specific risk profile of your setup. CloudScale's AI audit reasons from first principles: it reads your actual configuration, your actual code, and delivers findings that are specific to you, not generic checklist items.</p>
</div>

<p><strong>Standard Scan</strong> audits WordPress core settings, active plugins and themes, user accounts, file permissions, and wp-config.php hardening constants. The AI scores each finding Critical / High / Medium / Low and gives you specific steps to fix it: not generic advice, but instructions for your exact configuration.</p>
<p><strong>Deep Dive Scan</strong> adds live probes your site's security team would run manually:</p>
<ul>
<li><strong>Static PHP code analysis</strong> of every active plugin, flagging <code>eval()</code>, shell execution functions, code obfuscation, and suspicious patterns that malware authors use</li>
<li><strong>Live HTTP probes:</strong> open directory listing, weak TLS (SSLv3, TLS 1.0), CORS misconfigurations, server version header leaks</li>
<li><strong>DNS security checks:</strong> SPF strictness, DMARC policy strength, DKIM probes (skipped entirely for domains with no MX records, so there are no false positives for non-email sites)</li>
<li><strong>CSP quality analysis:</strong> flags <code>unsafe-inline</code>, <code>unsafe-eval</code>, wildcard sources, and missing directives in your Content Security Policy</li>
<li><strong>SSH hardening:</strong> probes port 22, reads sshd_config, checks for fail2ban; unprotected SSH is marked CRITICAL because it is actively used to recruit servers into DDoS botnets</li>
<li><strong>AI Code Triage:</strong> the 10 highest-risk static findings are sent to the AI with surrounding code context; each is classified as Confirmed Threat / False Positive / Needs Review before the main audit runs</li>
</ul>
<p><strong>Quick Fixes</strong> appear above the scan results, providing one-click remediations for the most common misconfigurations. Each shows green (done) or amber (needs attention) at a glance.</p>
<p><strong>Scheduled Scans</strong> run automatically on a daily or weekly schedule with email and push notifications (ntfy.sh supported), so you know about problems before your users or Google do.</p>

<hr style="border:none;border-top:1px solid #e2e8f0;margin:28px 0;">

<h2 style="font-size:1.3em;font-weight:800;color:#0f172a;margin:0 0 6px;background:transparent!important;padding:0!important;border:none!important;">Setting Up Your AI Provider</h2>
<p style="color:#64748b;margin:0 0 20px;font-size:.95em;">You need one API key to use the AI Cyber Audit. Google Gemini has a free tier with no credit card needed. Anthropic Claude requires a credit card but delivers the deepest analysis. Either works; both are excellent.</p>

<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:22px 24px;margin:0 0 24px;">
<h3 style="margin:0 0 16px;font-size:1.1em;font-weight:700;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">Option A: Google Gemini (Free, No Credit Card)</h3>
<p style="margin:0 0 14px;color:#374151;line-height:1.7;">Google AI Studio's free tier gives you access to Gemini 2.0 Flash with generous daily limits, more than enough for daily WordPress security scans. No billing setup required. This is the recommended starting point if you've never used an AI API before.</p>
<ol style="margin:0 0 14px;padding-left:20px;color:#374151;line-height:1.9;">
<li>Go to <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener"><strong>aistudio.google.com/app/apikey</strong></a></li>
<li>Sign in with your Google account</li>
<li>Click <strong>"Create API key"</strong> and select any Google Cloud project (or create a new one)</li>
<li>Copy the key; it looks like <code>AIzaSy...</code></li>
<li>In WordPress: <strong>Tools → Cyber and Devtools → Security tab → AI Settings</strong></li>
<li>Select <strong>Google Gemini</strong> as provider, paste your key, select model, click <strong>Save</strong></li>
</ol>
<p style="margin:0 0 8px;color:#374151;"><strong>Free tier limits:</strong> Gemini 2.0 Flash gives you 15 requests/minute, 1,500 requests/day, and 1 million tokens/day. A standard WordPress scan uses approximately 3,000–8,000 tokens. You can run dozens of scans per day at no cost.</p>
<p style="margin:0;color:#374151;"><strong>Want Gemini 2.5 Pro?</strong> That model requires a paid Google AI Studio account. Go to <a href="https://aistudio.google.com" target="_blank" rel="noopener">aistudio.google.com</a>, click your account, then <strong>Billing</strong>, and enable pay-as-you-go. Gemini 2.5 Pro costs approximately $0.01–0.03 per scan.</p>
</div>

<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:22px 24px;margin:0 0 24px;">
<h3 style="margin:0 0 16px;font-size:1.1em;font-weight:700;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">Option B: Anthropic Claude (Deepest Analysis, Credit Card Required)</h3>
<p style="margin:0 0 14px;color:#374151;line-height:1.7;">Claude Sonnet 4.6 and Opus 4.7 deliver the most thorough security reasoning available. Anthropic does not offer a free tier, but the cost is minimal: a deep dive audit with Claude Opus 4.7 typically costs $0.05–0.15. An entire month of daily scans with Claude Sonnet 4.6 costs under $1.</p>
<ol style="margin:0 0 14px;padding-left:20px;color:#374151;line-height:1.9;">
<li>Go to <a href="https://console.anthropic.com" target="_blank" rel="noopener"><strong>console.anthropic.com</strong></a> and create an account</li>
<li>Go to <strong>Settings → Billing</strong> and add a credit card</li>
<li>Add an initial credit (<strong>$5 is plenty to get started</strong> and covers hundreds of standard scans)</li>
<li>Go to <strong>Settings → API Keys</strong> and click <strong>"Create Key"</strong></li>
<li>Give it a name like "WordPress Security" and copy the key; it looks like <code>sk-ant-api03-...</code></li>
<li>In WordPress: <strong>Tools → Cyber and Devtools → Security tab → AI Settings</strong></li>
<li>Select <strong>Anthropic Claude</strong> as provider, paste your key, select model, click <strong>Save</strong></li>
</ol>
<p style="margin:0;color:#374151;"><strong>Model guide:</strong> <em>claude-sonnet-4-6</em> is fast and excellent for standard scans and daily scheduling. <em>claude-opus-4-7</em> is the most capable model available and is recommended for deep dive scans and critical sites. Use <em>Auto</em> mode in the plugin to let it pick the right model for each scan type.</p>
</div>

<div style="background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:22px 24px;margin:0 0 24px;">
<h3 style="margin:0 0 12px;font-size:1.05em;font-weight:700;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">⚡ Setting Up Automatic Top-Ups (Anthropic)</h3>
<p style="margin:0 0 12px;color:#374151;line-height:1.7;">If you use scheduled daily scans with Claude, your credit balance will gradually decrease. Automatic top-ups ensure your scans never fail due to an empty balance. Anthropic recharges your account automatically when it drops below a threshold you set.</p>
<ol style="margin:0;padding-left:20px;color:#374151;line-height:1.9;">
<li>Go to <a href="https://console.anthropic.com/settings/billing" target="_blank" rel="noopener"><strong>console.anthropic.com/settings/billing</strong></a></li>
<li>Scroll to <strong>"Automatic recharge"</strong></li>
<li>Toggle it on</li>
<li>Set <strong>"Recharge when balance falls below"</strong> to $2 (works well for moderate usage)</li>
<li>Set <strong>"Recharge amount"</strong> to $10 (covers several months of daily scans)</li>
<li>Click <strong>Save</strong></li>
</ol>
<p style="margin:12px 0 0;color:#92400e;font-size:.9em;"><strong>Tip:</strong> Anthropic sends email receipts for each top-up. Set a usage budget alert at <strong>Settings → Limits</strong> (e.g. $5/month) so you get notified if usage spikes unexpectedly.</p>
</div>

<div style="background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:22px 24px;margin:0 0 4px;">
<h3 style="margin:0 0 12px;font-size:1.05em;font-weight:700;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">⚡ Setting Up Spend Alerts (Google Paid Tier)</h3>
<p style="margin:0 0 12px;color:#374151;line-height:1.7;">If you upgrade to Gemini 2.5 Pro on Google's pay-as-you-go tier, Google bills your card automatically as you use the API, with no manual top-up process. Usage is charged to your linked payment method at the end of each billing period.</p>
<ol style="margin:0;padding-left:20px;color:#374151;line-height:1.9;">
<li>Go to <a href="https://console.cloud.google.com/billing" target="_blank" rel="noopener"><strong>console.cloud.google.com/billing</strong></a></li>
<li>Select your project, then click <strong>Budgets &amp; Alerts</strong></li>
<li>Click <strong>"Create Budget"</strong></li>
<li>Set a monthly budget (e.g. $5) and email alert thresholds at 50%, 90%, and 100%</li>
<li>Click <strong>Save</strong> and Google will email you if spend approaches your limit</li>
</ol>
<p style="margin:12px 0 0;color:#92400e;font-size:.9em;"><strong>Note:</strong> Google does not cut off API access when a budget alert fires; it only sends a notification. To hard-cap spend, enable the <em>"Actions"</em> option in the budget and select "Disable billing" (use cautiously, as this will break any Google Cloud services in the project).</p>
</div>`,

        'code-block': `
<div style="background:#f0f9ff;border-left:4px solid #0e6b8f;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">💻 Beautiful Code Blocks Without Paying $50/Year or Slowing Your Site Down</h2>
<p style="margin:0 0 10px;color:#374151;">Most WordPress code highlighting plugins have one of two problems: they load scripts from an external CDN (adding 100–300ms to every page load, hurting your Core Web Vitals score, and breaking if the CDN goes down), or they charge $30–$50/year for features that should be free. <strong>Enlighter</strong> loads from their own servers. <strong>SyntaxHighlighter Evolved</strong> loads from WordPress.com's CDN. <strong>Prismatic</strong> charges $29/year for a theme switcher.</p>
<p style="margin:0;color:#374151;">CloudScale bundles highlight.js 11.11.1 <strong>entirely on your own server</strong>: zero external HTTP requests, zero CDN dependency, zero annual fee. Your pages load faster, your cache hit rates improve, and your syntax highlighting works even when third-party services are down.</p>
</div>
<p>The Code Block is a native Gutenberg block (<code>cloudscale/code</code>) and a <code>[cs_code]</code> shortcode. It works everywhere WordPress renders content.</p>
<p><strong>190+ languages with auto-detection.</strong> CloudScale detects the language automatically from the code content. Override it manually in the block sidebar when detection picks the wrong one.</p>
<p><strong>14 professional colour themes:</strong> Atom One Dark/Light, GitHub, Monokai, Nord, Dracula, Tokyo Night, VS Code, VS 2015, Stack Overflow, Night Owl, Gruvbox, Solarized, Panda, Shades of Purple. A toggle button switches between dark and light variants, storing the preference in <code>localStorage</code> so it follows the reader across pages.</p>
<p><strong>Copy to clipboard</strong> with one click. Line numbers are rendered via CSS counter so they are never included when someone copies the code.</p>
<p><strong>INI/TOML auto-repair:</strong> Gutenberg breaks INI and TOML files at bare <code>[section]</code> headers by treating them as block delimiters. CloudScale detects this silently and reassembles the fragments, showing a brief toast so you know it happened.</p>`,

        'migrator': `
<div style="background:#fefce8;border-left:4px solid #ca8a04;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">🔄 Switch Plugins Without Touching 100 Posts by Hand</h2>
<p style="margin:0 0 10px;color:#374151;">Switching code highlighting plugins normally means opening every post, finding the old block or shortcode, deleting it, re-inserting the new one, and republishing, for every single post on your site. On a blog with 100 posts, that's hours of tedious work with plenty of room for mistakes.</p>
<p style="margin:0;color:#374151;">No other free WordPress plugin offers automated batch migration from multiple source formats with a preview step before committing. CloudScale does it in three clicks: Scan, Preview, Migrate All.</p>
</div>
<p>The Migrator scans your database for posts and pages using any supported legacy format, shows you a precise before/after diff, and converts them all to CloudScale blocks in a single operation.</p>
<p><strong>Supported source formats:</strong></p>
<ul>
<li>WordPress core <code>&lt;!-- wp:code --&gt;</code> and <code>&lt;!-- wp:preformatted --&gt;</code> blocks</li>
<li>Code Syntax Block plugin (<code>&lt;!-- wp:code-syntax-block/code --&gt;</code>)</li>
<li>Legacy shortcodes: <code>[code]</code>, <code>[sourcecode]</code>, and common variants</li>
</ul>
<p><strong>Workflow:</strong></p>
<ol>
<li><strong>Scan:</strong> finds every post and page with supported blocks. Shows title, status, date, and block count.</li>
<li><strong>Preview:</strong> shows the exact before/after content diff per post. Nothing is written to the database at this stage.</li>
<li><strong>Migrate:</strong> convert one post at a time, or migrate everything in a single click.</li>
</ol>
<p>⚠ The migrator writes directly to <code>post_content</code>. Always take a database backup first. Use the CloudScale Backup &amp; Restore plugin for a one-click snapshot before you begin.</p>`,

        'sql-tool': `
<div style="background:#f8fafc;border-left:4px solid #64748b;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">🗄️ Query Your Live Database Safely, Without phpMyAdmin or SSH</h2>
<p style="margin:0 0 10px;color:#374151;">phpMyAdmin is powerful but complex to install securely, and leaving it exposed is a serious vulnerability. Adminer is a single PHP file that attackers actively scan for. Desktop tools like TablePlus require you to open a database port to your laptop. For WordPress administrators who just need to check table sizes, find orphaned data, or troubleshoot a slow query, those options are overkill or a security liability.</p>
<p style="margin:0;color:#374151;">CloudScale's SQL tool lives inside wp-admin, accessible only to administrators, and is <strong>read-only by design</strong>. It is architecturally impossible to delete or modify data through it. No separate installation, no open ports, no exposed files.</p>
</div>
<p><strong>Read-only enforcement:</strong> Every query passes through <code>is_safe_query()</code> which strips comments, rejects semicolons (blocking statement stacking), blocks <code>INTO OUTFILE</code> and <code>LOAD_FILE</code>, and only permits <code>SELECT</code>, <code>SHOW</code>, <code>DESCRIBE</code>, <code>EXPLAIN</code>. Even if an administrator tries to run a destructive query, it is rejected before reaching the database.</p>
<p><strong>14 built-in quick queries</strong> cover the most common diagnostic tasks without writing a single line of SQL:</p>
<ul>
<li><em>Health &amp; Diagnostics:</em> database status, site options, table sizes and row counts</li>
<li><em>Content Summary:</em> posts by type and status, latest published content</li>
<li><em>Bloat &amp; Cleanup:</em> orphaned postmeta, expired transients, revisions, largest autoloaded options (the most common cause of slow WordPress admin)</li>
<li><em>URL &amp; Migration Helpers:</em> HTTP references (for HTTP→HTTPS migrations), posts with old IP references, posts missing meta descriptions</li>
</ul>
<p><strong>Keyboard shortcuts:</strong> <kbd>Enter</kbd> or <kbd>Ctrl+Enter</kbd> runs the query. <kbd>Shift+Enter</kbd> inserts a newline for multi-line queries.</p>`,

        'server-logs': `
<div style="background:#f0fdf4;border-left:4px solid #15803d;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">📋 Read Your Server Logs Without Leaving WordPress</h2>
<p style="margin:0 0 10px;color:#374151;">When something breaks on a WordPress site, the answer is almost always in a log file. But accessing logs normally means SSH access (which many hosting plans don't provide), navigating a cPanel file manager, or asking your hosting provider to email you a file. For agency developers, that means waiting. For site owners on shared hosting, that means never seeing the logs at all.</p>
<p style="margin:0;color:#374151;"><strong>Query Monitor</strong> shows database queries and hooks but not server-level PHP or Nginx/Apache logs. <strong>Debug Bar</strong> only surfaces WP_DEBUG output. Neither replaces direct log access. CloudScale gives you the actual log files (PHP errors, WordPress debug output, and web server logs) in a clean, searchable interface inside wp-admin, with no SSH required.</p>
</div>
<p><strong>All your log sources in one place:</strong> The source picker lists every available log file with a live status indicator (readable, not found, permission denied, or empty). Switch between PHP error log, WordPress debug log, and web server access/error logs with a single click.</p>
<p><strong>Live search</strong> filters entries as you type with highlighted matches, which is essential for finding a specific error in a log with thousands of lines.</p>
<p><strong>Severity filter</strong> narrows results to Emergency, Alert, Critical, Error, Warning, Notice, Info, or Debug. Cuts through noise on busy production sites where Info and Debug lines dominate.</p>
<p><strong>Auto-refresh tail mode</strong> polls for new entries every 30 seconds. Reproduce a bug in one browser tab while watching the log update in real time in another. It's the fastest way to trace an intermittent error.</p>
<p><strong>Custom log paths:</strong> add any file path (Nginx error log, a custom application log, a cron output file). Paths persist across sessions.</p>
<p><strong>One-click PHP error logging setup:</strong> if PHP error logging isn't configured on the server, a button writes the required <code>php.ini</code> directives automatically. No server configuration knowledge required.</p>

<div style="background:#f1f5f9;border-left:4px solid #6366f1;border-radius:0 8px 8px 0;padding:18px 22px;margin:20px 0 0;">
<h3 style="margin:0 0 8px;font-size:1em;font-weight:700;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">Server Logs as a Performance and Debugging Tool</h3>
<p style="margin:0 0 10px;color:#374151;line-height:1.7;">The Server Logs panel is not just for security incidents. It's the fastest way to trace a performance problem to its root cause without SSH access. Load a slow-performing page in one tab, watch the PHP error log update in tail mode in another, and see exactly which hook or database query is generating warnings on that specific page. Reproduce an intermittent 500 error and catch the exception the moment it fires. Find the exact plugin throwing deprecated notices that is degrading your PHP performance score.</p>
<p style="margin:0;color:#374151;line-height:1.7;">For growth and marketing teams: the auth log source (where SSH brute-force attempts are recorded) gives you a real-time picture of attack traffic against your server - useful context for understanding infrastructure load and the value of the protection CloudScale provides.</p>
</div>`,

        'site-audit': `
<div style="background:linear-gradient(135deg,#f0fdf4,#ecfdf5);border-left:4px solid #10b981;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">🔍 Your Entire Site Audited in Under 60 Seconds</h2>
<p style="margin:0 0 10px;color:#374151;">One button. CloudScale scans all your published content and your database, then uses AI to return a prioritised list of issues scored by impact - SEO gaps, thin content, missing images, database bloat, inactive plugins, security misconfigurations. No external crawlers, no data sent to third parties, no Screaming Frog licence required.</p>
<p style="margin:0;color:#374151;"><strong>Works without an AI key</strong> - rule-based findings run instantly. Add an API key on the Security tab for AI-written summaries, root-cause explanations, and deeper recommendations.</p>
</div>

<p>Most WordPress site owners have no idea what their site actually looks like to Google. Their posts feel complete when written, but from the outside the picture is different: a meta description missing on half the posts so Google writes its own (usually a random sentence pulled from the article body that is rarely anyone's best pitch to a reader), featured images absent on a quarter of posts making social shares look blank, the autoload table quietly growing to 5MB so every admin page loads sluggishly, and post revisions accumulating until they consume 30% of total database storage. None of this is visible when you are reading your own content. The Site Auditor shows you what Google and your database actually see.</p>

<p>The audit runs entirely inside your WordPress installation. No external crawler visits your site, no third party receives your content. The scanner reads your database directly, inspects your published posts, checks your WordPress configuration, and assembles a prioritised findings report in seconds. If you have an AI API key configured, the gathered statistics are sent to the AI for deeper interpretation - but your actual post content is never transmitted, only counts and metadata.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">What the audit checks</h3>
<ul>
<li><strong>SEO - missing meta descriptions:</strong> posts and pages with no meta description written. Google writes its own for these pages, pulling a random sentence from your content. That sentence is rarely your best pitch to a reader deciding whether to click. Each finding links directly to the affected posts so you can fix them one by one, or use the AI Meta Description Writer (if you have the CloudScale SEO plugin) to fix them in bulk.</li>
<li><strong>SEO - missing SEO titles:</strong> pages where the title tag defaults to the raw post title with no customisation. A well-written title targets a keyword naturally, includes your brand name where appropriate, and stays under 60 characters. Posts without a custom title are flagged with a link to edit them.</li>
<li><strong>SEO - duplicate page titles:</strong> multiple posts sharing the same title tag. Google treats these as competing for the same search query, which dilutes ranking signals across both. Usually caused by applying the same template title to similar posts without customising the SEO field per post.</li>
<li><strong>Content - thin pages:</strong> published posts under 300 words. Thin content pages are unlikely to rank for competitive queries and can dilute your site's overall quality score in Google's assessment. The audit shows each thin post with its word count so you can decide whether to expand it, redirect it to a related page, or mark it noindex.</li>
<li><strong>Content - missing featured images:</strong> posts with no featured image set. Featured images are used for OpenGraph social previews (what LinkedIn, WhatsApp, Slack show when someone shares your link), related article thumbnails, and Google Discover cards. A missing featured image means blank or placeholder previews everywhere your posts are shared.</li>
<li><strong>Performance - autoloaded options bloat:</strong> WordPress loads certain plugin settings on every single page request via the <code>wp_options</code> table with <code>autoload=yes</code>. When deactivated plugins leave their data behind, or when plugins store large amounts of data in autoloaded options, the total grows and slows every page. CloudScale flags this when total autoloaded data exceeds 500KB and tells you the exact size so you can measure improvement after cleanup.</li>
<li><strong>Performance - excess active plugins:</strong> sites with more than 20 active plugins. Each plugin adds PHP execution time, potential database queries, and JavaScript or CSS assets to every page request. This is an informational finding - not every plugin is replaceable - but it prompts a review of whether every installed plugin is genuinely active and necessary.</li>
<li><strong>Database - expired transients:</strong> WordPress caches temporary data in the <code>wp_options</code> table as transients with an expiry time. Expired transients should be removed automatically by WP-Cron, but on sites with unreliable cron execution they accumulate as dead rows, contributing to autoload bloat and slower queries on a table that WordPress reads on every request.</li>
<li><strong>Database - post revisions:</strong> every draft save or published-post update creates a revision. With no limit configured, a heavily-edited post can accumulate hundreds of revisions. These rows are safe to remove once a post is live and they represent no SEO value. The audit shows the total revision count and the number of rows that can be safely cleaned.</li>
<li><strong>Database - orphaned post meta:</strong> when a post is deleted, its corresponding rows in <code>wp_postmeta</code> are not always cleaned up by WordPress or the plugin that created them. Orphaned meta rows accumulate over time and slow queries that join against the meta table.</li>
<li><strong>Plugins - inactive plugins on disk:</strong> deactivated plugins remain on your server and present an attack surface even when disabled. A plugin with a known CVE is exploitable via direct file access even if it is not active. The audit lists every inactive plugin as a reminder to remove rather than just deactivate plugins you no longer use.</li>
<li><strong>Security - WP_DEBUG in production:</strong> when <code>WP_DEBUG</code> is enabled on a live site, PHP notices, warnings, and errors are displayed to visitors. This leaks file paths, function names, database structure details, and plugin version information that attackers use to identify and target specific vulnerabilities. WP_DEBUG should only be enabled in local development environments.</li>
</ul>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Reading the results</h3>
<p>Findings are sorted by severity: <strong>Critical</strong> → <strong>High</strong> → <strong>Medium</strong> → <strong>Low</strong> → <strong>Info</strong>. The scorecard at the top shows the count at each level so you know at a glance how much work you have. Each finding card shows the severity badge and category for quick triage, the affected post count or database metric, a plain-English explanation of why the finding matters, and a specific fix instruction. For content findings, clickable post links open the post editor directly so you can address each one without leaving the audit results.</p>
<p>Use the <strong>category filter buttons</strong> (SEO, Content, Performance, Database, Security) to focus on one area at a time. Run the audit again after each fix session to see your score improve.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Quick Fix buttons</h3>
<p>Database findings (expired transients, post revisions, orphaned meta) show a <strong>Fix It</strong> button that runs the cleanup operation server-side and immediately re-checks the finding. Each operation is safe to run on any live WordPress site. The fix shows you the number of rows removed so you can see the before and after impact on your database size.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Privacy and data handling</h3>
<p>All scanning runs inside your WordPress installation - no content or metadata leaves your server during the rule-based analysis pass. If you have an AI API key configured, aggregated site statistics (post counts, word counts, database metrics) are sent to the AI provider for deeper interpretation. Your actual post content is never transmitted - only counts and aggregate measurements.</p>

<div style="background:#f1f5f9;border-left:4px solid #6366f1;border-radius:0 8px 8px 0;padding:18px 22px;margin:20px 0 0;">
<h3 style="margin:0 0 8px;font-size:1em;font-weight:700;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">Run the audit before and after making changes</h3>
<p style="margin:0;color:#374151;line-height:1.7;">Run the Site Audit before a major plugin change or cleanup sprint to establish a baseline. After making fixes, run it again and compare the scorecards. This is especially useful before and after cleaning up database bloat - the autoloaded options KB figure should drop measurably after removing redundant plugin data. Save a screenshot of the findings list before a sprint and compare it to the results afterwards to confirm the improvements are real.</p>
</div>`,

        'optimizer': `
<div style="background:linear-gradient(135deg,#f0f4ff,#f5f3ff);border-left:4px solid #6366f1;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">🔧 Reduce Your Plugin Stack. Fix Errors Faster.</h2>
<p style="margin:0 0 10px;color:#374151;">The average WordPress site runs 17 active plugins. Each one adds HTTP requests, CSS, JavaScript, and potential conflict vectors to every page load. The Optimizer tab gives you two tools to fight back: a plugin scanner that finds redundancy, and an AI assistant that diagnoses errors instantly.</p>
<p style="margin:0;color:#374151;"><strong>No other plugin does this.</strong> The Plugin Stack Scanner is the only tool that maps your installed plugins against a known replacement table and tells you which ones to remove - with direct links to the CloudScale features that replace them.</p>
</div>

<p>Most WordPress sites accumulate plugins the same way: one plugin gets installed to solve an immediate problem, then another, then another. Each one made sense in isolation. Together they form a stack of 15-20 plugins where nobody is sure which ones are still needed, several are doing overlapping jobs, and the combined page weight and PHP load is measurably slower than it needs to be. The Plugin Stack Scanner makes this visible: it shows you exactly which of your current plugins CloudScale already replaces, what the annual saving is for premium ones, and gives you a direct link to the CloudScale equivalent so you can verify it before deactivating anything.</p>

<p>The AI Debugging Assistant solves a different problem: PHP errors, stack traces, and WordPress warnings that require reading documentation, searching Stack Overflow, or posting in a forum and waiting. Paste the error, get a structured diagnosis with the root cause and numbered fix steps in under 10 seconds. The AI receives your WordPress and PHP version as context so the answer is specific to your environment rather than generic.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Plugin Stack Scanner</h3>
<p>Click <strong>Scan My Plugin Stack</strong>. CloudScale reads your active and inactive plugin list and checks each against a database of 30+ categories it replaces: security scanners, 2FA plugins, SMTP mailers, code block plugins, SQL tools, log viewers, and social preview tools.</p>
<p>The results show:</p>
<ul>
<li><strong>Plugin name and version:</strong> what you currently have installed and whether it is active or inactive</li>
<li><strong>CloudScale replacement:</strong> the specific feature within CloudScale that covers this plugin's function, with the tab name so you can find it immediately</li>
<li><strong>Annual saving:</strong> the cost of the premium licence for paid plugins. Free plugins show a dash. This figure is useful for quantifying the value of consolidation when making the case to a client or a budget holder</li>
<li><strong>Go to tab link:</strong> a direct link to the CloudScale equivalent so you can set it up and verify it is working before deactivating the original plugin</li>
</ul>
<p><strong>Safe process for removing a plugin:</strong></p>
<ol>
<li>Click the CloudScale tab link and configure the equivalent feature</li>
<li>Test the CloudScale version works correctly on your site</li>
<li>Take a full backup with <a href="https://andrewbaker.ninja/wordpress-plugin-help/cloudscale-backup-restore-help/" target="_blank" rel="noopener"><strong>CloudScale Backup and Restore</strong></a></li>
<li>Deactivate the original plugin</li>
<li>Verify nothing broke, then delete the plugin entirely rather than leaving it deactivated (inactive plugins still present an attack surface)</li>
</ol>

<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:0 0 10px;background:transparent!important;padding:0!important;border:none!important;">AI Debugging Assistant</h3>
<p>Paste any PHP error, WordPress warning, stack trace, deprecation notice, or plain-language problem description into the text area. Good inputs to try:</p>
<ul>
<li>A PHP fatal error from your server log (copy the full error including file and line number)</li>
<li>A WordPress admin notice you don't understand</li>
<li>A plugin conflict description ("when I activate X, Y breaks")</li>
<li>A 500 server error message</li>
<li>An unexplained behaviour ("checkout page goes blank after placing an order")</li>
</ul>
<p>Click <strong>Diagnose with AI</strong>. The AI returns a structured response with three sections:</p>
<ul>
<li><strong>Root Cause:</strong> what is actually broken, in plain English - not the error message itself but what it means</li>
<li><strong>Why It Happens:</strong> the underlying mechanism so you understand the problem and can prevent it recurring, not just fix it blindly this time</li>
<li><strong>How to Fix It:</strong> numbered steps specific to the error you provided, tailored to your WordPress and PHP version</li>
</ul>
<p>The AI receives your WordPress version, PHP version, and active plugin list as context with every query. This means it can identify that an error is caused by a known incompatibility between two specific plugins you have installed, rather than giving a generic answer that applies to every WordPress site.</p>

<div style="background:#f0f9ff;border-left:4px solid #0e6b8f;border-radius:0 8px 8px 0;padding:18px 22px;margin:20px 0 0;">
<h3 style="margin:0 0 8px;font-size:1em;font-weight:700;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">Using the AI Debugging Assistant with the CS Monitor</h3>
<p style="margin:0;color:#374151;line-height:1.7;">The CS Monitor panel (visible on every admin page for logged-in administrators) has a clipboard copy button on every tab. If you see a PHP error in the CS Monitor's Logs tab, click Copy to get the full error text, then paste it directly into the Debugging Assistant. The two tools are designed to work together: CS Monitor catches the error in real time, the Debugging Assistant explains it and tells you how to fix it.</p>
</div>
<p style="margin-top:16px;"><strong>Requires an AI API key.</strong> Add one on the Security tab under AI Settings. Google Gemini's free tier works perfectly for debugging queries - a single debugging session uses a fraction of the free daily quota.</p>`,

        'cs-monitor': `
<div style="background:linear-gradient(135deg,#f0f4ff,#fdf4ff);border-left:4px solid #6366f1;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">⚡ A DevTools-Style Performance Panel, Built Into Every WordPress Page</h2>
<p style="margin:0 0 10px;color:#374151;">Query Monitor shows database queries. Debug Bar surfaces WP_DEBUG output. Neither one shows you everything happening on a single page request - HTTP calls, hook timings, PHP errors, asset inventory, transients, and template resolution - in one panel without switching tools or reading raw logs. CS Monitor does.</p>
<p style="margin:0;color:#374151;"><strong>It appears automatically</strong> for logged-in administrators on every wp-admin screen and every frontend page. No configuration required. Toggle it off under <strong>Code Settings → Show the CS Monitor performance panel</strong> if you need a clean view.</p>
</div>

<p>Performance problems on WordPress sites typically involve one of a small number of root causes: too many database queries (especially N+1 query patterns from plugins iterating through posts), slow external HTTP calls blocking page generation, PHP errors generating notices that get logged and cause overhead, or an asset-heavy theme loading scripts on every page whether they are needed or not. The difficulty is that none of these are visible from the outside - your site's pages load slowly and you cannot tell from the browser why. CS Monitor captures all of these simultaneously for every page request and surfaces them in a single overlay so you can pinpoint the cause without SSH access, log file analysis, or installing additional profiling tools.</p>

<p>CS Monitor only activates for logged-in administrators. Regular visitors see no difference and incur no overhead. This means you can leave it enabled on live production sites without any impact on visitor experience or page load times. It is always-on for you, invisible to everyone else.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Panel Tabs</h3>
<ul>
<li><strong>Issues:</strong> auto-detected problems ranked by severity - N+1 query loops, slow queries over 100ms, high total query count, OPcache not enabled, missing database indexes on common lookup columns, and high memory usage. Each issue links directly to the relevant tab for investigation. Start here to see if there is anything worth investigating before browsing the raw data.</li>
<li><strong>DB Queries:</strong> every database query executed during this request, with execution time in milliseconds, the calling plugin or theme, and the PHP call stack. Click any SELECT query to run <code>EXPLAIN</code> inline and see the query execution plan - instantly shows whether a full table scan is occurring. N+1 query patterns (the same query fired repeatedly in a loop) are grouped and flagged automatically, making them easy to spot even if no individual query is slow.</li>
<li><strong>HTTP / REST:</strong> all outbound HTTP calls made via <code>wp_remote_get</code>, <code>wp_remote_post</code>, and internal REST API requests, with the full URL and response time. Slow external API calls (payment gateways, social embed fetches, external font requests) that block PHP page generation are immediately visible here. If your pages feel fast in the browser but slow to generate server-side, this tab often shows the culprit.</li>
<li><strong>Logs:</strong> PHP notices, warnings, deprecation notices, and errors captured for the current request, with the originating file and line number. No need to tail a log file or wait for a scheduled scan. If a plugin is generating deprecation warnings on every page load, you will see exactly which plugin and which line is responsible.</li>
<li><strong>Assets:</strong> every script and stylesheet registered and enqueued for the current page, with its source plugin or theme. Use this to identify asset bloat - plugins loading their full CSS and JS on pages where they have no UI, themes loading icon fonts for icons that don't appear on the current template.</li>
<li><strong>Hooks:</strong> all WordPress action and filter hooks that fired during the request, with execution time per hook. A hook taking 200ms on every page request is a performance problem regardless of how fast the database queries are. Find the slow hooks and trace them back to the plugin responsible.</li>
<li><strong>Request:</strong> current request details including HTTP method, full URL, the matched WordPress rewrite rule, active query variables, and key WordPress globals. Useful for debugging routing problems, custom post type URL issues, and template selection.</li>
<li><strong>Template:</strong> the full chain of template files WordPress evaluated to serve the current page, in the order they were loaded. Use this to understand which theme file is generating the current page and trace layout problems back to the right file without guessing.</li>
<li><strong>Transients:</strong> every transient read, write, and delete operation triggered during the request, with the transient name and expiry. Shows which plugins are caching data and whether caches are hitting or missing.</li>
<li><strong>Browser:</strong> JavaScript console errors and unhandled promise rejections captured client-side and reported back to the panel via a small inline script. Catch JS exceptions and React hydration errors without opening browser DevTools. Useful on pages where you cannot easily open DevTools (mobile admin, certain embedded views).</li>
<li><strong>Summary:</strong> the request totals at a glance - total query count, combined query execution time, hook count, HTTP call count, peak memory usage, and total wall-clock page generation time. Use this for before/after comparison when profiling plugin changes.</li>
</ul>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Copy and Export</h3>
<p>Every tab has a <strong>Copy</strong> button that puts the current tab's data on your clipboard as plain text, ready to paste into a bug report, a support ticket, or the AI Debugging Assistant. A full-panel JSON export is available from the Summary tab for sharing complete request profiles with developers or hosting support.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Performance Impact</h3>
<p>CS Monitor adds a small overhead from hooking into <code>SAVEQUERIES</code> and the WordPress <code>shutdown</code> action to collect data. This overhead is only incurred for logged-in administrators - regular visitors are completely unaffected. The panel can be disabled globally under <strong>Tools → Cyber and Devtools → Code Settings → Show the CS Monitor performance panel</strong>, or closed on a per-page basis using its close button.</p>`,

        'fpm-monitor': `
<div style="background:linear-gradient(135deg,#0f172a,#1e293b);border-left:4px solid #60a5fa;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#e2e8f0;background:transparent!important;padding:0!important;border:none!important;">🖥️ Know When Your PHP Workers Are Exhausted - Before Your Site Goes Down</h2>
<p style="margin:0 0 10px;color:#94a3b8;">PHP-FPM (FastCGI Process Manager) maintains a fixed pool of worker processes. When all workers are busy - during a traffic spike, a slow query holding workers open, or a runaway loop - new requests queue and the site freezes. WP-Cron can't alert you when this happens because WP-Cron itself runs inside PHP-FPM. The FPM Monitor runs as a shell script on the host OS, outside Docker, so it fires even when every PHP worker is consumed.</p>
<p style="margin:0;color:#94a3b8;"><strong>No other WordPress plugin does this.</strong> External uptime monitors just tell you the site is down after it's already down. The FPM Monitor tells you saturation is building while you can still act - and can restart the container automatically.</p>
</div>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:0 0 10px;background:transparent!important;padding:0!important;border:none!important;">Live Worker Status</h3>
<p>The worker bar at the top of the FPM Monitor section shows real-time counts polled from your PHP-FPM status page:</p>
<ul>
<li><strong>Active</strong> - workers currently processing a request (shown in red when high)</li>
<li><strong>Idle</strong> - workers ready to accept a new request (shown in green)</li>
<li><strong>Total</strong> - pool size: active + idle + any in graceful-finish state</li>
<li><strong>Mem</strong> - total memory consumed across all workers combined</li>
</ul>
<p>Click <strong>▼ Workers</strong> to expand a per-worker table showing PID, state, request count, running time, last URI, last script, last CPU%, and memory per worker. Running workers show <code>—</code> for CPU% because their current request hasn't completed yet. Click <strong>↻ Refresh</strong> to re-poll at any time.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:0 0 10px;background:transparent!important;padding:0!important;border:none!important;">Enabling the Status Page</h3>
<p>Worker data requires <code>pm.status_path = /fpm-status</code> in your PHP-FPM pool config (<code>www.conf</code>) and a matching Nginx location block. Click <strong>⚙ Setup Status Page</strong> and the wizard will detect whether your setup is already configured, show you the exact config changes needed, and offer to apply them automatically.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:0 0 10px;background:transparent!important;padding:0!important;border:none!important;">Saturation Detection and Alerts</h3>
<p>The monitor runs as a host-level cron job (not WP-Cron) that probes your site's HTTP URL every minute. If the probe times out or fails N consecutive times (the <strong>saturation threshold</strong>), saturation is declared. On saturation it:</p>
<ol>
<li>Sends an <strong>ntfy.sh push notification</strong> to your phone instantly</li>
<li>Sends an <strong>email alert</strong> to the WordPress admin address</li>
<li>Optionally <strong>restarts the WordPress Docker container</strong> (with a configurable restart cooldown to prevent thrashing)</li>
<li>POSTs an event back to this panel via the callback URL, logging the incident in the event log</li>
</ol>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:0 0 10px;background:transparent!important;padding:0!important;border:none!important;">Setup</h3>
<p>Configure the settings in the panel, then copy the <strong>crontab line</strong> and <strong>config.env snippet</strong> from the Host Cron Setup section. The config includes the callback URL and a secret token that authenticates events back to this panel. Add the crontab line to your host's crontab with <code>crontab -e</code>, and source the config.env from your cron script. The monitor starts detecting saturation immediately.</p>
<p><strong>Auto-restart:</strong> when enabled, the script issues <code>docker restart {container}</code> after declaring saturation. Use with care on production - a restart drops all in-flight requests. The restart cooldown (default: 20 minutes) prevents the script from restarting more than once per incident.</p>`,

        'test-accounts': `
<div style="background:linear-gradient(135deg,#f0f4ff,#f5f3ff);border-left:4px solid #6366f1;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">🤖 Playwright Testing on a Live Site Without Disabling 2FA</h2>
<p style="margin:0 0 10px;color:#374151;">Automated end-to-end testing with Playwright requires logging in as a real WordPress user. But if 2FA is enabled on your admin accounts (which it should be), your test suite cannot log in using a username and password alone. The standard workarounds - disabling 2FA for testing, creating a permanent admin account with weak credentials, or patching the login flow - all introduce real security risks on your production site.</p>
<p style="margin:0;color:#374151;">The Test Account Manager solves this properly. It creates dedicated test users with real WordPress roles, and provides a session API that generates temporary authenticated cookies without going through the login flow at all. Your 2FA stays active. Your live admin accounts are never touched. Your test suite gets a real session it can use, and the session is invalidated when the test run finishes.</p>
</div>

<p>The mechanism works through a server-side session API: a secret-protected REST endpoint that accepts a role name and TTL, then returns a set of WordPress authentication cookies issued directly by the server for a designated test user. The cookies are indistinguishable from a real browser session, so Playwright can inject them and access the WordPress admin as a fully authenticated user. No login page, no password, no 2FA prompt. The session expires after the configured TTL and can be explicitly invalidated at the end of a test run via the logout endpoint.</p>

<p>Test users are real WordPress accounts using standard WordPress roles (Administrator, Editor, Author, Subscriber, etc.). They are named with a unique identifier so they are clearly recognisable as test accounts and are never confused with real user accounts. One session API endpoint serves all test users - the caller specifies which role to get a session for in the request body, authenticated by the shared secret.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">The Four-Step Flow</h3>
<ol>
<li><strong>Create a test user:</strong> enter a name (e.g. <code>my_playwright</code>), choose the WordPress role the tests need (default: Administrator), and click <strong>+ Create User</strong>. CloudScale creates a WordPress user account named <code>csdt-playwright-my_playwright</code> with a randomly generated password you never need to know. The user appears in your test users list immediately.</li>
<li><strong>Configure your .env.test file:</strong> copy the pre-filled snippet from the panel. It contains your site URL, the shared secret, the role name, and the session and logout endpoint URLs. Store this file in your project root and load it in your Playwright config with <code>dotenv</code>. Never commit the shared secret to version control.</li>
<li><strong>Call the session API in your tests:</strong> at the start of your test suite, POST to the session endpoint with the shared secret and role name. The API returns WordPress authentication cookies. Inject them into a Playwright browser context. Your tests now run as a fully authenticated WordPress user. The Playwright helper code is shown in the .env.test snippet section below the user list.</li>
<li><strong>Log out when done:</strong> at the end of your test suite (in an <code>afterAll</code> hook), POST to the logout endpoint with the same secret and role. The session is invalidated server-side. The test users list shows active session counts so you can verify cleanup worked.</li>
</ol>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Settings and Controls</h3>
<ul>
<li><strong>Name:</strong> a short identifier for this test user (e.g. <code>playwright</code>, <code>e2e_admin</code>, <code>ci_editor</code>). The actual WordPress username will be <code>csdt-playwright-{name}</code> to make test accounts identifiable in the Users list.</li>
<li><strong>WordPress Role:</strong> the role this test user will have. Choose the minimum role your tests actually need. If you are testing editor-only flows, create an Editor account rather than an Administrator to limit the blast radius if the shared secret is ever leaked.</li>
<li><strong>Shared Secret:</strong> a randomly generated 32-character secret used to authenticate all API requests. Click <strong>Show / Hide</strong> to reveal it for copying. Click <strong>Regenerate</strong> to issue a new secret - all .env.test files using the old secret will need to be updated. The secret is stored in your WordPress database and is never transmitted in an API response (only used to authenticate incoming requests).</li>
<li><strong>Session URL:</strong> the POST endpoint for obtaining a session. The URL contains a 32-character random path token so it is not guessable. Each request must also include the shared secret in the POST body. Copy the URL with the copy button.</li>
<li><strong>Logout URL:</strong> the POST endpoint for invalidating a session. Call this in your test suite's afterAll hook. Accepts the shared secret and role, and optionally a specific session token to invalidate a single session rather than all sessions for that user.</li>
<li><strong>Active sessions:</strong> each test user row shows the number of currently live sessions, colour-coded amber when non-zero. Click <strong>Kill Sessions</strong> to manually invalidate all sessions for a user without calling the logout endpoint - useful during development when you want to force a fresh session.</li>
<li><strong>Last Login:</strong> the timestamp of the most recent session creation via the API for that test user. Shows relative time (e.g. "5m ago") for recent activity and the date for older entries.</li>
<li><strong>Delete User:</strong> permanently removes the WordPress test account. Any active sessions are also invalidated. Cannot be undone.</li>
</ul>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">The .env.test Snippet</h3>
<p>The panel generates a ready-to-use <code>.env.test</code> file snippet based on your current configuration. Copy it into a file at the root of your test project:</p>
<pre style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:12px;font-size:.82em;overflow-x:auto;">WP_SITE=https://yoursite.com
CSDT_TEST_SECRET=your_secret_here
CSDT_TEST_ROLE=my_playwright
CSDT_TEST_SESSION_URL=https://yoursite.com/wp-json/csdt/v1/test-session-{token}
CSDT_TEST_LOGOUT_URL=https://yoursite.com/wp-json/csdt/v1/test-logout-{token}</pre>
<p>Load this in your Playwright config with <code>require('dotenv').config({ path: '.env.test' })</code> and access the values as <code>process.env.CSDT_TEST_SECRET</code> etc.</p>

<div style="background:#fff5f5;border-left:4px solid #dc2626;border-radius:0 8px 8px 0;padding:18px 22px;margin:20px 0 0;">
<h3 style="margin:0 0 8px;font-size:1em;font-weight:700;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">Security: what is and is not protected</h3>
<p style="margin:0 0 10px;color:#374151;line-height:1.7;">The session endpoint is publicly accessible (no WordPress login required) but requires both the shared secret in the POST body AND the correct path token in the URL. An attacker would need to know both the secret and the path to request a session. Keep the <code>.env.test</code> file out of version control (<code>.gitignore</code> it) and regenerate the shared secret if you suspect it has leaked.</p>
<p style="margin:0;color:#374151;line-height:1.7;">Test sessions created via the API are real WordPress sessions with full role permissions. Keep TTLs short (1200 seconds is the recommended default for most test runs) and always call the logout endpoint at the end of your suite. The test users list shows active session counts so you can spot stale sessions and kill them manually.</p>
</div>`,

        'home': `
<div style="background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);border-radius:10px;padding:22px 26px;margin-bottom:24px;color:#fff;">
<h2 style="margin:0 0 8px;font-size:1.2em;font-weight:800;color:#fff;background:transparent!important;padding:0!important;border:none!important;">Start Here: Configure Your AI Provider</h2>
<p style="margin:0;color:#cbd5e1;line-height:1.7;">Every CloudScale AI feature - the security audit, site audit, and code analysis - runs through the AI provider you configure on this tab. You supply your own API key; your data goes directly from your server to the provider with no CloudScale middleman.</p>
</div>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:0 0 10px;background:transparent!important;padding:0!important;border:none!important;">AI Provider Setup</h3>
<p>Two providers are supported. Both work equally well for security audits; the choice depends on your preference and budget:</p>
<ul>
<li><strong>Anthropic Claude</strong> - the recommended choice for the most capable security analysis. Claude Opus 4 and Claude Sonnet 4 are available. Get your key at <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a>. Paid plans required (no free tier).</li>
<li><strong>Google Gemini</strong> - includes a free tier with no credit card required, making it the zero-cost entry point. Gemini 2.0 Flash (free) and Gemini 2.5 Pro (paid) are available. Get your key at <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">aistudio.google.com</a>.</li>
</ul>
<p>After entering your key, click <strong>Test Key</strong> to verify it works before running a scan. The key is stored in your WordPress database (wp_options) and is never transmitted to CloudScale's servers - it goes only to the provider's API endpoint during scans.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Scheduled Scans</h3>
<p>Enable scheduled scans to run the AI Cyber Audit automatically on a daily or weekly schedule. When a scan completes, results are saved to scan history on the Security tab. Enable email and push alerts to receive the AI summary in your inbox or on your phone the moment a scan finishes.</p>
<ul>
<li><strong>Frequency:</strong> daily or weekly. Daily is recommended for production sites with regular plugin updates or content changes.</li>
<li><strong>Scan type:</strong> Standard (fast, covers WordPress config and plugin CVEs) or Deep Dive (adds live HTTP probes, DNS checks, and PHP code analysis).</li>
<li><strong>Email alert:</strong> sends to the site's admin email address by default. Configure SMTP on the Mail tab first to ensure reliable delivery.</li>
<li><strong>ntfy.sh push:</strong> enter any ntfy.sh topic URL to receive instant push notifications on your phone. Free and open-source. No account required - just install the ntfy app and create a topic.</li>
</ul>`,

        'session': `
<div style="background:#f0f9ff;border-left:4px solid #0e6b8f;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">⏱ How Long Should a Login Session Last?</h2>
<p style="margin:0;color:#374151;">WordPress's default is 2 days. That's a reasonable balance between security (re-authenticate regularly) and convenience (don't interrupt a working developer). Adjust this to match your team's workflow and your site's security posture.</p>
</div>

<p>Session duration controls how long the WordPress auth cookie is valid before the user must enter their password again. When a custom duration is set, the <strong>Remember Me</strong> checkbox at login is overridden - all sessions get the same lifetime, and it applies to browser restarts (the cookie persists rather than expiring when the browser closes).</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Recommended durations by context</h3>
<ul>
<li><strong>1–3 days:</strong> banking sites, client portals, any site with sensitive customer data. Force frequent re-authentication to limit the window of a stolen session cookie.</li>
<li><strong>7–14 days:</strong> most business sites and WordPress blogs. Frequent enough to catch stolen credentials; infrequent enough to not frustrate legitimate users.</li>
<li><strong>30–90 days:</strong> internal tools used by a small trusted team on known devices. Convenience wins when the threat model is low.</li>
<li><strong>WordPress default (2 days):</strong> leave this setting empty or set to "Default" to keep WordPress's built-in behaviour.</li>
</ul>

<p><strong>Important:</strong> changing this setting only affects new logins. Users who are already logged in keep their current session until it expires or they log out manually. If you need to force a full re-login for all users immediately (e.g. after a suspected credential compromise), use the <strong>Log Out All Users</strong> option in the WordPress Users settings, or run <code>wp user session destroy --all</code> via WP-CLI.</p>`,

        'brute-force': `
<div style="background:#fff5f5;border-left:4px solid #dc2626;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">🔒 Stop Credential-Stuffing Attacks at the Login Form</h2>
<p style="margin:0;color:#374151;">Automated bots try thousands of username/password combinations against every reachable WordPress login page. Brute-force protection locks an account after a configurable number of failed attempts, making mass credential-stuffing attacks economically unviable - the attacker's bot moves on to the next target.</p>
</div>

<p>The lockout works per-username, not per-IP address. This is the critical difference from IP-rate-limiting: a distributed attack that uses 10,000 different IP addresses (a botnet) is blocked just as effectively as a single-machine attack, because both result in failed attempts for the same target username. Once the threshold is crossed, that account is locked for the configured duration regardless of how many IPs are trying.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Settings</h3>
<ul>
<li><strong>Maximum login attempts:</strong> consecutive failures before lockout. Default is 5. Lower to 3 for maximum security; raise to 10 if legitimate users frequently mistype passwords and you receive lockout support requests.</li>
<li><strong>Lockout duration:</strong> how long the account is blocked. Default is 10 minutes - enough to defeat most automated scripts. For admin-only sites with no public users, 60 minutes or longer adds significant friction to targeted attacks.</li>
<li><strong>Account enumeration protection:</strong> WordPress normally reveals whether a username exists via different error messages (<em>"username not found"</em> vs <em>"wrong password"</em>). Enabling this makes both errors return the same generic message, removing a reconnaissance tool attackers use to build target lists. There is no downside to enabling this.</li>
</ul>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Unlocking a locked account</h3>
<p>If a legitimate user is locked out, you can clear their lockout immediately from the SQL Query Tool or WP-CLI without waiting for the timeout to expire:</p>
<code style="background:#f8fafc;border:1px solid #e5e7eb;padding:8px 12px;border-radius:4px;font-size:.88em;display:block;margin:8px 0;">DELETE FROM wp_options WHERE option_name LIKE 'csdt_devtools_lockout_%'</code>
<p>This clears all active lockouts. To clear a specific username: replace <code>%</code> with the exact username (e.g. <code>csdt_devtools_lockout_johndoe</code>).</p>`,

        'ssh-monitor': `
<div style="background:#fff5f5;border-left:4px solid #dc2626;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">🖥️ Know the Moment Your SSH Port Is Under Attack</h2>
<p style="margin:0;color:#374151;">A server with SSH port 22 open to the internet will be targeted by automated scanners within minutes of going online. Most sites never know they're under attack because these attempts are silent unless you're watching the auth log. The SSH monitor brings that visibility to your WordPress dashboard.</p>
</div>

<p>The monitor tails <code>/var/log/auth.log</code> via an AJAX poll every 60 seconds. It counts <em>Failed password</em> and <em>Invalid user</em> entries in a rolling time window. When the count exceeds your threshold, an alert fires to your configured email and ntfy.sh topic. Alerts are throttled to once per 5 minutes to prevent notification floods during sustained attacks.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Setup</h3>
<ol>
<li>The monitor requires the web server user (<code>www-data</code>) to be able to read <code>/var/log/auth.log</code>. If the panel shows a warning, run: <code>sudo usermod -a -G adm www-data &amp;&amp; sudo systemctl restart php-fpm</code></li>
<li>Set your alert threshold - default is 10 failures in 60 seconds. This is calibrated to avoid false positives from a user mistyping their password, while catching any automated scanner instantly.</li>
<li>Save settings. The monitor polls automatically from then on.</li>
</ol>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Detection vs. Blocking: use fail2ban alongside this</h3>
<p>The SSH Monitor detects attacks and alerts you. It does not block IPs. For automatic IP blocking, install <strong>fail2ban</strong>:</p>
<code style="background:#f8fafc;border:1px solid #e5e7eb;padding:8px 12px;border-radius:4px;font-size:.88em;display:block;margin:8px 0;">sudo apt install fail2ban &amp;&amp; sudo systemctl enable fail2ban</code>
<p>With fail2ban's default configuration, an IP is banned for 10 minutes after 5 failed SSH attempts. The CloudScale monitor shows you when attacks are happening at a volume that exceeds even fail2ban's tolerance - a sign that you're under a sustained, distributed attack that warrants additional action (firewall rules, port change, or contacting your hosting provider).</p>`,

        'threat-monitor': `
<div style="background:#fff5f5;border-left:4px solid #dc2626;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">🔎 Passive Threat Detection That Runs While You Sleep</h2>
<p style="margin:0;color:#374151;">The AI Cyber Audit gives you an on-demand snapshot. The Threat Monitor runs in the background 24/7, watching for the specific events that indicate an active compromise: a core file being modified, a new admin account appearing, or a wave of probe requests hitting your login page.</p>
</div>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:0 0 10px;background:transparent!important;padding:0!important;border:none!important;">File Integrity Monitor</h3>
<p>Scans <code>wp-includes/*.php</code> and <code>wp-admin/*.php</code> every 5 minutes and compares file modification times against a baseline. If any file changes outside of a WordPress core update, you get an immediate alert. This catches the most common post-compromise action: a backdoor dropped into a core PHP file.</p>
<p><strong>Anti-spam:</strong> the baseline is rebuilt silently when WordPress updates (all core files change legitimately during updates). The same modification timestamp is never alerted twice. After a manual code change you authored, click <strong>Reset File Baseline</strong> to clear the alert state.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">New Administrator Alert</h3>
<p>Fires the instant a WordPress user is created with the Administrator role, or an existing user is promoted to Administrator. Attacker privilege escalation - gaining admin access - is a critical step in most WordPress compromises. This alert catches it the moment it happens rather than during the next scheduled audit.</p>
<p><strong>Anti-spam:</strong> each user ID is alerted exactly once. Acknowledging the alert (or adding the user legitimately) prevents repeated notifications for the same account.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Web Probe Detection</h3>
<p>Reads the web server access log (byte-offset tracking, so only new entries are processed each check). Counts requests to sensitive endpoints: <code>wp-login.php</code>, <code>xmlrpc.php</code>, <code>wp-config.php</code>, <code>.env</code>, <code>.git/</code>, and shell-injection patterns. When the count exceeds the threshold (default: 25 in 5 minutes), an alert fires. Throttled to at most once per hour to prevent alert floods during sustained scans.</p>`,

        'plugin-stack': `
<div style="background:#f0f9ff;border-left:4px solid #1e6fd9;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">🔍 Fewer Plugins = Smaller Attack Surface</h2>
<p style="margin:0;color:#374151;">Every plugin you run is a piece of code you trust to not get hacked, not conflict with anything, and not slow your site down. CloudScale already replaces entire categories of plugins. The Plugin Stack Scanner tells you exactly which of your current plugins are now redundant.</p>
</div>

<p>Click <strong>Scan My Plugin Stack</strong> to compare your active and inactive plugins against CloudScale's replacement list. The scan checks for plugins in these categories:</p>
<ul>
<li><strong>Security scanners</strong> - Wordfence, iThemes Security, All In One WP Security</li>
<li><strong>2FA plugins</strong> - WP 2FA, Google Authenticator, Duo Security</li>
<li><strong>SMTP mailers</strong> - WP Mail SMTP, Easy WP SMTP, FluentSMTP</li>
<li><strong>Code highlighting</strong> - SyntaxHighlighter, Enlighter, Prismatic</li>
<li><strong>SQL tools</strong> - Adminer, WP phpMyAdmin</li>
<li><strong>Log viewers</strong> - Error Log Monitor, WP Log Viewer</li>
</ul>
<p>Each flagged plugin shows why it's redundant and which CloudScale feature replaces it. Inactive plugins are flagged with an extra warning: inactive plugins still load autoloaded code on every page request and are still scanned for vulnerabilities - deactivate and delete, don't just deactivate.</p>`,

        'update-risk': `
<div style="background:#f0fdf4;border-left:4px solid #16a34a;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">🔄 Know What You're Applying Before You Apply It</h2>
<p style="margin:0;color:#374151;">Blindly applying all pending plugin updates is the most common cause of WordPress site breakage. A "minor" version bump can contain breaking API changes. A patch release can contain schema migrations. The Update Risk Scorer reads the changelog and tells you which updates are safe to apply right now and which need staging first.</p>
</div>

<p>Click <strong>Scan for Available Updates</strong> to fetch the list of plugins with pending updates from WordPress. For each one, the scorer reads the changelog from WordPress.org and sends it to the configured AI provider for risk assessment.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Risk Rating Meanings</h3>
<ul>
<li><strong style="color:#16a34a;">🟢 Patch</strong> - security fix or bug fix with no API changes. Safe to apply immediately on your live site.</li>
<li><strong style="color:#d97706;">🟡 Minor</strong> - new features added. Low risk but review the changelog for anything that affects your configuration. Apply during off-peak hours.</li>
<li><strong style="color:#dc2626;">🔴 Breaking</strong> - major version bump or significant API change. Test on a staging site before applying to production. The AI will describe what specifically changed and what to check.</li>
</ul>

<p>Requires an AI API key configured on the Home tab. Uses your configured provider (Claude or Gemini) to read and assess each changelog. The assessment runs locally on your server - no update decisions are sent to or logged by CloudScale.</p>`,

        'opcache': `
<div style="background:#f0f9ff;border-left:4px solid #0e6b8f;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">PHP OPcache: the Single Biggest PHP Performance Win</h2>
<p style="margin:0;color:#374151;">PHP compiles source code to bytecode every time a script runs - unless OPcache is enabled. With OPcache, the bytecode is compiled once and stored in shared memory. Every subsequent request skips compilation entirely. On a WordPress site with 200+ PHP files loading per request, this typically cuts PHP execution time by 30-50%.</p>
</div>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:0 0 10px;background:transparent!important;padding:0!important;border:none!important;">Reading the Status Panel</h3>
<ul>
<li><strong>Hit rate:</strong> the percentage of PHP script requests served from cache. A healthy hit rate is 95% or higher. Below 90% means either the cache is too small, too many unique scripts are being loaded, or OPcache is not configured correctly.</li>
<li><strong>Memory usage:</strong> how much of the configured OPcache memory is currently in use. If this is above 90%, increase <code>opcache.memory_consumption</code> in your php.ini - a full cache means PHP starts evicting cached scripts, causing recompilation and dramatically degrading performance.</li>
<li><strong>Cached scripts:</strong> the number of PHP files currently compiled and stored in the cache. For a standard WordPress installation, expect 400-800 scripts.</li>
<li><strong>Interned strings:</strong> OPcache also caches repeated string values (class names, function names, etc.) to reduce memory duplication across worker processes.</li>
</ul>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Reset button</h3>
<p>After deploying code changes (new plugin versions, theme updates, custom code), the cached bytecode may no longer match the files on disk. Click <strong>Reset OPcache</strong> to flush all cached scripts. WordPress will recompile them on the next few requests. The site remains available during the reset - there's no downtime.</p>`,

        'smtp': `
<div style="background:#f0fdf4;border-left:4px solid #16a34a;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">📧 Stop Losing Emails to the Spam Folder</h2>
<p style="margin:0;color:#374151;">By default, WordPress sends email via PHP's <code>mail()</code> function. Email sent this way - directly from your server's IP with no authentication - is rejected by Gmail, Outlook, and most modern mail services, or silently dropped into spam. Authenticated SMTP delivery solves this permanently.</p>
</div>

<p>Once configured, all WordPress email goes through your SMTP server: WooCommerce order confirmations, password reset links, admin notifications, CloudScale scan alerts, 2FA OTP codes, and any plugin that calls <code>wp_mail()</code>. No code changes required anywhere else.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Supported providers</h3>
<ul>
<li><strong>Gmail (Google Workspace):</strong> use an App Password (not your account password). Enable 2-Step Verification in your Google account first, then go to Google Account → Security → App passwords → create one for "Mail". Host: <code>smtp.gmail.com</code>, Port: <code>587</code>, Encryption: TLS.</li>
<li><strong>Outlook / Microsoft 365:</strong> Host: <code>smtp.office365.com</code>, Port: <code>587</code>, Encryption: TLS. Use your full email address as the username.</li>
<li><strong>Amazon SES:</strong> create SMTP credentials in the SES console (IAM user with SMTP permissions). Host varies by region, e.g. <code>email-smtp.us-east-1.amazonaws.com</code>, Port: <code>587</code>.</li>
<li><strong>Mailgun, SendGrid, Postmark:</strong> use the SMTP relay settings provided in your account dashboard. All use standard SMTP with API key as the password.</li>
<li><strong>Custom SMTP server:</strong> enter your host, port, encryption type, username, and password directly.</li>
</ul>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Test before you save</h3>
<p>Use the <strong>Send Test Email</strong> button to confirm delivery before saving. The test sends a real email to your WordPress admin address. Check the Email Log tab if you don't receive it within a few minutes - the log shows whether the send was attempted and what error (if any) was returned.</p>`,

        'email-log': `
<div style="background:#fdf4ff;border-left:4px solid #9333ea;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">📬 See Every Email WordPress Has Sent</h2>
<p style="margin:0;color:#374151;">The email activity log captures every outgoing email regardless of delivery method - PHP mail(), SMTP, or any third-party mailer that hooks into wp_mail(). If a user says they didn't receive a password reset or a WooCommerce order notification, this is the first place to look.</p>
</div>

<p>Each log entry records: timestamp, recipient address, subject line, and delivery status (sent successfully or failed with error). Click any row to open a modal showing the full email body - useful for verifying the content of automated emails without having to trigger them again.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Diagnosing email problems</h3>
<p>If a recipient says they didn't get an email:</p>
<ol>
<li>Check the log for their address and the expected subject - if it shows "Sent", WordPress delivered it to your SMTP server. The problem is downstream (spam folder, SPF/DKIM failure, recipient's mail server).</li>
<li>If the log shows "Failed" or the email isn't in the log at all, check the SMTP settings on the Mail tab. A common cause is an incorrect App Password or an outbound port blocked by the hosting provider.</li>
<li>If the log is empty for a plugin's emails, that plugin may be bypassing <code>wp_mail()</code> and using its own mailer - in that case, CloudScale cannot intercept it.</li>
</ol>

<p>The log retains the last 200 entries. Use the <strong>Clear Log</strong> button to reset it after debugging.</p>`,

        'thumbnails': `
<div style="background:#fefce8;border-left:4px solid #d97706;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">🖼️ Why Your Links Share Without a Preview Image</h2>
<p style="margin:0;color:#374151;">When someone shares a WordPress post link on Slack, Twitter, WhatsApp, or LinkedIn, the platform fetches your page to build a preview card. That card is driven entirely by Open Graph meta tags - specifically <code>og:image</code>. If the tag is missing, wrong, or pointing to an image that Cloudflare is caching with a wrong content-type, the link shares as bare text. This diagnostic panel finds and fixes those problems.</p>
</div>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:0 0 10px;background:transparent!important;padding:0!important;border:none!important;">Open Graph URL Checker</h3>
<p>Enter any public URL on your site to inspect its Open Graph tags. The checker fetches the page, parses the <code>&lt;head&gt;</code>, and reports all OG meta tags found (title, description, image, type) along with a visual preview of how the link will appear when shared. Immediately shows if <code>og:image</code> is missing or pointing to a non-existent URL.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Recent Posts Scan</h3>
<p>Scans your 20 most recently published posts and checks each one for a set featured image. Posts without a featured image are listed so you can add images before they're shared on social media.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Cloudflare Image Cache</h3>
<p>Cloudflare's aggressive caching can serve stale or incorrectly-typed image responses to social platform crawlers. This panel lets you configure Cloudflare cache settings for image URLs to ensure social crawlers always get a fresh, correctly-typed response. Enter your Cloudflare Zone ID and API token to enable the cache management tools.</p>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:24px 0 10px;background:transparent!important;padding:0!important;border:none!important;">Media Library Audit</h3>
<p>Lists orphaned attachment records in your database - images that exist in wp_posts as attachments but whose files are missing from the uploads directory. These cause 404 errors when social crawlers try to fetch the referenced og:image. The audit lets you identify and clean up broken attachment records.</p>`,

        'uptime-monitor': `
<div style="background:linear-gradient(135deg,#f0fdf4,#ecfdf5);border-left:4px solid #16a34a;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:24px;">
<h2 style="margin:0 0 8px;font-size:1.25em;color:#0f172a;background:transparent!important;padding:0!important;border:none!important;">⏱ Deep Uptime Monitoring - From the Cloudflare Edge</h2>
<p style="margin:0 0 10px;color:#374151;">Traditional uptime monitors check whether your site returns a 200 status code. That tells you nothing about whether WordPress is actually healthy - your site can return 200 while the database is failing, PHP-FPM workers are exhausted, or the application is silently broken.</p>
<p style="margin:0;color:#374151;">The CloudScale Uptime Monitor probes a built-in <strong>readiness endpoint</strong> every 60 seconds from the Cloudflare edge. Each probe checks: database connectivity, PHP-FPM saturation, and WordPress boot. If any check fails, you get an alert immediately - even if your server is completely offline.</p>
</div>

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:0 0 10px;background:transparent!important;padding:0!important;border:none!important;">How It Works</h3>
<ol>
<li>A <strong>Cloudflare Worker</strong> runs on a 60-second cron from the Cloudflare edge - completely independent of your server, your hosting provider, and WP-Cron.</li>
<li>Every minute, the Worker sends a <code>GET</code> request to your site's <strong>readiness endpoint</strong> (<code>/wp-json/csdt/v1/ready</code>) with a secret Bearer token in the Authorization header.</li>
<li>The readiness endpoint runs three checks internally and returns <code>HTTP 200</code> if all pass or <code>HTTP 503</code> if any fail.</li>
<li>The Worker posts the result back to your WordPress site, recording it in the uptime history and triggering alerts if the site is down.</li>
<li>The uptime panel shows <strong>Last Queried</strong> (last time Cloudflare successfully called your endpoint) and <strong>Last Failed Query</strong> (last time someone called with an invalid token) so you can see at a glance that the Worker is running and no unauthorised probing is happening.</li>
</ol>

<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:0 0 10px;background:transparent!important;padding:0!important;border:none!important;">The Readiness Endpoint</h3>
<p>The readiness endpoint is a public REST API route at <code>/wp-json/csdt/v1/ready</code>. It requires a Bearer token - requests without a valid token return <code>401 Unauthorized</code> and the timestamp is logged as "Last Failed Query".</p>
<p><strong>Checks performed on each probe:</strong></p>
<ul>
<li><strong>Database</strong> - executes <code>SELECT 1</code> against your WordPress database. Fails if the DB is unreachable or returning errors.</li>
<li><strong>PHP-FPM saturation</strong> - reads the <code>/fpm-status</code> page (if configured) and fails if active workers exceed 90% of total pool size. This catches the scenario where your site is technically reachable but is about to freeze under load.</li>
<li><strong>WordPress boot</strong> - implicit: if the endpoint responds at all, WordPress initialised successfully.</li>
</ul>
<p><strong>Response format (200 - healthy):</strong></p>
<pre style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:12px;font-size:.82em;overflow-x:auto;">{
  "ok": true,
  "checks": {
    "db":  { "ok": true, "message": "Connected" },
    "fpm": { "ok": true, "active": 2, "total": 10, "saturation_pct": 20 },
    "wp":  { "ok": true, "version": "6.8" }
  },
  "site": "https://yoursite.com",
  "checked_at": 1745000000
}</pre>
<p><strong>Response format (503 - degraded):</strong></p>
<pre style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:12px;font-size:.82em;overflow-x:auto;">{
  "ok": false,
  "checks": {
    "db":  { "ok": false, "message": "Query failed" },
    "fpm": { "ok": false, "active": 10, "total": 10, "saturation_pct": 100 },
    "wp":  { "ok": true, "version": "6.8" }
  },
  "site": "https://yoursite.com",
  "checked_at": 1745000000
}</pre>
<p>You can call this endpoint manually at any time (with the token) to check your site's health.</p>

<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:0 0 10px;background:transparent!important;padding:0!important;border:none!important;">One-Click Setup</h3>
<p><strong>Prerequisites:</strong> a Cloudflare account with your site's domain proxied through Cloudflare. Enter your Cloudflare Zone ID and API Token in the <strong>Thumbnails tab</strong> - the token needs <strong>Workers:Edit</strong> permission.</p>
<ol>
<li>Go to <strong>Tools → Cyber and Devtools → Optimizer tab</strong>.</li>
<li>Scroll to the <strong>Uptime Monitor</strong> section.</li>
<li>Optionally enter an <strong>ntfy.sh alert URL</strong> (e.g. <code>https://ntfy.sh/your-topic</code>) to receive push notifications on your phone when the site goes down. This is the same ntfy URL used by the scheduled security scan.</li>
<li>Click <strong>Deploy Worker to Cloudflare</strong>. The plugin will:
  <ul>
  <li>Generate a secure random token (or reuse an existing one)</li>
  <li>Resolve your Cloudflare account ID from your Zone ID</li>
  <li>Upload the Worker script with all environment variables pre-configured (SITE_URL, PING_URL, READY_URL, PING_TOKEN, NTFY_URL)</li>
  <li>Set the cron trigger to run every minute</li>
  </ul>
</li>
<li>Within 60 seconds, the <strong>Last Queried</strong> timestamp in the status panel will update, confirming the Worker is running and probing your endpoint.</li>
</ol>
<p>That's it. No command line required.</p>

<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:0 0 10px;background:transparent!important;padding:0!important;border:none!important;">Manual Setup (CLI)</h3>
<p>If you prefer Wrangler, click <strong>Generate Token</strong> first, then expand <strong>Manual deploy</strong>. You'll get a <code>worker.js</code> file and a pre-filled <code>wrangler.toml</code> with all variables set. Run:</p>
<pre style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:12px;font-size:.82em;">npm install -g wrangler
wrangler login
wrangler deploy</pre>
<p>Then set the cron trigger in the Cloudflare dashboard: <strong>Workers → cloudscale-uptime → Triggers → Cron Triggers → Add Cron → <code>* * * * *</code></strong></p>

<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:0 0 10px;background:transparent!important;padding:0!important;border:none!important;">Alerts and Notifications</h3>
<ul>
<li><strong>ntfy.sh push</strong> - sent directly from the Cloudflare Worker when the readiness probe fails. This fires even if your server is completely offline, because the Worker runs on Cloudflare's infrastructure independently.</li>
<li><strong>Email alert</strong> - sent from WordPress when the ping callback receives a down status. Falls back to the WordPress admin email if no specific alert email is configured.</li>
<li><strong>Recovery alert</strong> - sent when the site comes back up after a confirmed outage, including how long the site was down.</li>
<li><strong>5-minute alert cooldown</strong> - prevents notification spam during extended outages.</li>
</ul>

<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">

<h3 style="font-size:1.1em;font-weight:700;color:#0f172a;margin:0 0 10px;background:transparent!important;padding:0!important;border:none!important;">Security</h3>
<p>The readiness endpoint is public (accessible without a WordPress login) but protected by a Bearer token. All token comparisons use <code>hash_equals()</code> to prevent timing attacks. Requests with a missing or incorrect token return <code>401</code> and log the time of the attempt as <strong>Last Failed Query</strong> - visible in the uptime panel. If you see unexpected failed queries, regenerate your token using the <strong>Generate Token</strong> button and re-deploy the Worker.</p>
<p>The endpoint does not expose sensitive data - it only returns health check pass/fail status and version numbers.</p>`,
    },
}).catch(err => { console.error('ERROR:', err.message); process.exit(1); });
