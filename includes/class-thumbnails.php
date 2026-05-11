<?php
/**
 * Social preview diagnostics and thumbnails for WordPress posts.
 *
 * @package CloudScale_DevTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CSDT_Thumbnails {

    /* ==================================================================
       17. THUMBNAILS — Social Preview Diagnostics
       ================================================================== */

    // ─── Nonce / constants ──────────────────────────────────────────────
    private const THUMB_NONCE = 'csdt_devtools_thumbnails';

    private const SOCIAL_PLATFORMS = [
        // target_kb = optimum file size to aim for during generation.
        // max_kb    = platform's hard limit (used only for compatibility warnings).
        'facebook'  => [ 'label' => 'Facebook',    'w' => 1200, 'h' => 630,  'target_kb' => 400, 'max_kb' => 8000 ],
        'twitter'   => [ 'label' => 'X / Twitter', 'w' => 1200, 'h' => 628,  'target_kb' => 400, 'max_kb' => 5000 ],
        'whatsapp'  => [ 'label' => 'WhatsApp',    'w' => 1200, 'h' => 630,  'target_kb' => 200, 'max_kb' => 300  ],
        'linkedin'  => [ 'label' => 'LinkedIn',    'w' => 1200, 'h' => 627,  'target_kb' => 400, 'max_kb' => 5000 ],
        'instagram' => [ 'label' => 'Instagram',   'w' => 1080, 'h' => 1080, 'target_kb' => 400, 'max_kb' => 8000 ],
    ];

    private const SOCIAL_UAS = [
        'WhatsApp'            => 'WhatsApp/2.23.24.82 A',
        'Facebook'            => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        'Facebot'             => 'Facebot',
        'LinkedInBot'         => 'LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)',
        'Twitterbot'          => 'Twitterbot/1.0',
    ];

    // ─── Panel render ────────────────────────────────────────────────────

    public static function render_thumbnails_panel(): void {
        $cf_zone  = get_option( 'csdt_devtools_cf_zone_id', '' );
        $cf_token = get_option( 'csdt_devtools_cf_api_token', '' );
        $cf_zone_masked  = $cf_zone  ? str_repeat( '•', 28 ) . substr( $cf_zone,  -4 ) : '';
        $cf_token_masked = $cf_token ? str_repeat( '•', 12 ) . substr( $cf_token, -4 ) : '';
        ?>
        <div class="cs-panel" id="cs-panel-thumbs-checker">
            <div class="cs-section-header" style="background:linear-gradient(135deg,#1565c0,#0d47a1);">
                <span>🔍 URL SOCIAL PREVIEW CHECKER</span>
                <span class="cs-header-hint"><?php esc_html_e( 'Run a full social-preview diagnostic on any URL', 'cloudscale-devtools' ); ?></span>
                <?php CloudScale_DevTools::render_explain_btn( 'social-checker', 'URL Social Preview Checker', [
                    [ 'name' => 'HTTPS',                'rec' => 'Required',     'html' => 'Social crawlers refuse to load preview images served over <code>http://</code>. The URL must use <code>https://</code>.' ],
                    [ 'name' => 'HTTP Response',        'rec' => 'Required',     'html' => 'The page must return <code>HTTP 200</code> for the crawler\'s User-Agent. A <code>403</code> or bot-block will prevent any preview from loading.' ],
                    [ 'name' => 'Response Time',        'rec' => 'Recommended',  'html' => 'Crawlers time out after ~<code>3 seconds</code>. Pages that take longer to respond will show no preview — check for slow plugins or uncached pages.' ],
                    [ 'name' => 'og:title',             'rec' => 'Required',     'html' => 'The title shown in the social card. Without <code>og:title</code>, platforms fall back to the page <code>&lt;title&gt;</code> tag or show nothing.' ],
                    [ 'name' => 'og:description',       'rec' => 'Recommended',  'html' => 'The summary text shown under the title. Recommended for all platforms; Twitter/X truncates to ~<code>200</code> chars.' ],
                    [ 'name' => 'og:image',             'rec' => 'Required',     'html' => 'The preview image. Must be an absolute <code>https://</code> URL. Recommended size: <code>1200×630 px</code>, max <code>8 MB</code>. Facebook enforces a minimum of <code>200×200 px</code>.' ],
                    [ 'name' => 'og:image dimensions',  'rec' => 'Recommended',  'html' => '<code>og:image:width</code> and <code>og:image:height</code> tell crawlers the size without downloading the image. Speeds up card rendering and avoids layout shifts.' ],
                    [ 'name' => 'robots.txt',           'rec' => 'Info',         'html' => 'Checks that <code>robots.txt</code> does not block <code>Googlebot</code>, <code>Twitterbot</code>, <code>facebookexternalhit</code>, or other social crawlers from accessing the page.' ],
                    [ 'name' => 'Crawler UA test',      'rec' => 'Info',         'html' => 'Re-fetches the page using each platform\'s real crawler User-Agent string to confirm the page is not blocked by a WAF, Cloudflare rule, or bot-protection plugin.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <p class="cs-hint" style="margin-bottom:10px"><?php esc_html_e( 'Checks OG tags, og:image size/dimensions, HTTPS, robots.txt, and verifies each platform crawler can actually read the page.', 'cloudscale-devtools' ); ?></p>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <?php
                    $recent_post = get_posts( [ 'numberposts' => 1, 'post_status' => 'publish', 'post_type' => 'post', 'has_password' => false ] );
                    if ( empty( $recent_post ) ) {
                        $recent_post = get_posts( [ 'numberposts' => 1, 'post_status' => 'publish', 'post_type' => 'page', 'has_password' => false ] );
                    }
                    $checker_default_url = ! empty( $recent_post ) ? get_permalink( $recent_post[0] ) : home_url( '/' );
                    ?>
                    <input type="url" id="cs-thumb-check-url" class="cs-input" style="max-width:520px;flex:1"
                           placeholder="<?php echo esc_attr( $checker_default_url ); ?>"
                           value="<?php echo esc_attr( $checker_default_url ); ?>">
                    <button type="button" class="cs-btn-primary" id="cs-thumb-check-btn">🔍 <?php esc_html_e( 'Run Diagnostic', 'cloudscale-devtools' ); ?></button>
                </div>
                <div id="cs-thumb-check-results" style="margin-top:14px;display:none"></div>
            </div>
        </div>

        <?php
        $csdi_id      = (int) get_option( 'cloudscale_default_image_id', 0 );
        $csdi_preview = $csdi_id ? wp_get_attachment_image_url( $csdi_id, 'medium' ) : '';
        ?>
        <div class="cs-panel" id="cs-panel-thumbs-default-image">
            <div class="cs-section-header" style="background:linear-gradient(135deg,#1565c0,#0d47a1);">
                <span>🖼️ DEFAULT FEATURED IMAGE</span>
                <span class="cs-header-hint"><?php esc_html_e( 'Fallback featured image used when a post has no thumbnail set', 'cloudscale-devtools' ); ?></span>
                <?php CloudScale_DevTools::render_explain_btn( 'default-image', 'Default Featured Image', [
                    [ 'name' => 'What it does',       'rec' => 'Overview',     'html' => 'When a post has no featured image set, WordPress normally shows nothing. This plugin intercepts the <code>post_thumbnail_html</code> and <code>has_post_thumbnail</code> filters to return your chosen fallback image instead — in theme loops, archive pages, and as the <code>og:image</code> fallback for social sharing.' ],
                    [ 'name' => 'Recommended size',   'rec' => 'Required',     'html' => 'Use a <strong>1200 × 630 px</strong> image (JPEG or PNG, under 300 KB). This is the optimal size for WhatsApp, LinkedIn, Facebook, and X/Twitter cards. Smaller images may be cropped or rejected by social crawlers.' ],
                    [ 'name' => 'og:image fallback',  'rec' => 'Important',    'html' => 'Without a default image, posts shared on social media with no featured image will show no preview card — significantly reducing click-through rates. Setting a branded default ensures every post looks professional when shared.' ],
                    [ 'name' => 'Change vs Remove',   'rec' => 'Info',         'html' => '<strong>Change Image</strong> — opens the WordPress Media Library to select a new fallback image.<br><strong>Remove</strong> — clears the fallback. Posts without a featured image will revert to showing no thumbnail.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <p class="cs-hint" style="margin-bottom:14px;"><?php esc_html_e( 'When a post has no featured image, this image is shown in theme loops and used as the og:image fallback for social sharing. Choose a branded 1200×630 px image.', 'cloudscale-devtools' ); ?></p>
                <div style="display:flex;align-items:flex-start;gap:20px;flex-wrap:wrap;">
                    <div id="csdt-defimg-preview" style="flex-shrink:0;">
                        <?php if ( $csdi_preview ) : ?>
                            <img src="<?php echo esc_url( $csdi_preview ); ?>" style="max-width:240px;height:auto;border:1px solid #ddd;border-radius:4px;display:block;" />
                        <?php else : ?>
                            <div style="width:240px;height:126px;background:#f0f0f0;border:1px dashed #ccc;border-radius:4px;display:flex;align-items:center;justify-content:center;color:#aaa;font-size:12px;">No image selected</div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <input type="hidden" id="csdt-defimg-id" value="<?php echo esc_attr( $csdi_id ); ?>" />
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                            <button type="button" class="cs-btn-primary" id="csdt-defimg-select"><?php echo $csdi_id ? esc_html__( 'Change Image', 'cloudscale-devtools' ) : esc_html__( 'Select Image', 'cloudscale-devtools' ); ?></button>
                            <?php if ( $csdi_id ) : ?>
                            <button type="button" class="cs-btn-secondary" id="csdt-defimg-remove" style="color:#dc2626;border-color:#dc2626;"><?php esc_html_e( 'Remove', 'cloudscale-devtools' ); ?></button>
                            <?php endif; ?>
                        </div>
                        <p id="csdt-defimg-status" style="font-size:12px;color:#4b5563;margin:0;"></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="cs-panel" id="cs-panel-thumbs-cloudflare">
            <div class="cs-section-header" style="background:linear-gradient(135deg,#e65100,#bf360c);">
                <span>☁️ CLOUDFLARE SETUP &amp; DIAGNOSTICS</span>
                <span class="cs-header-hint"><?php esc_html_e( 'Configure WAF bypass rules and test cache behaviour', 'cloudscale-devtools' ); ?></span>
                <?php CloudScale_DevTools::render_explain_btn( 'cloudflare', 'Cloudflare Setup & Diagnostics', [
                    [ 'name' => 'Bot Fight Mode fix',    'rec' => 'Critical',     'html' => 'Cloudflare\'s Bot Fight Mode blocks social crawler user agents (WhatsApp, Facebook, LinkedIn, X/Twitter). This prevents them from reading your OG tags, meaning no preview card when links are shared. The fix is a <strong>WAF Custom Rule</strong> that skips Bot Fight Mode for those specific user agents only.' ],
                    [ 'name' => 'WAF rule setup',        'rec' => 'Recommended',  'html' => 'In Cloudflare Dashboard → Security → WAF → Custom Rules: create a rule with <em>User Agent contains</em> (facebookexternalhit OR LinkedInBot OR WhatsApp OR Twitterbot) → Action: <strong>Skip</strong> → Bot Fight Mode. Place it above any block rules.' ],
                    [ 'name' => 'Cache purge',           'rec' => 'Optional',     'html' => 'After fixing OG tags or images, Cloudflare may serve stale cached versions to crawlers for hours. The Cache Purge tool lets you clear a specific URL or your entire zone instantly. Requires a Cloudflare API Token with Cache Purge permission and your Zone ID.' ],
                    [ 'name' => 'Crawler test',          'rec' => 'Info',         'html' => 'The URL Social Preview Checker (panel above) simulates each crawler\'s user agent and reports exactly what OG tags they see — including whether Cloudflare is blocking them. Use it to verify your WAF rule is working correctly.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">

                <!-- CF Setup Guide -->
                <div class="cs-thumb-cf-guide">
                    <h3 style="margin-top:0;font-size:14px;color:#333"><?php esc_html_e( 'Why social previews fail with Cloudflare', 'cloudscale-devtools' ); ?></h3>
                    <p class="cs-hint"><?php esc_html_e( 'Cloudflare\'s Bot Fight Mode and Super Bot Fight Mode block social crawler user agents (WhatsApp, Facebook, LinkedIn, X/Twitter) before they can read your page\'s OG tags. The fix is a WAF Custom Rule that skips Bot Fight Mode for those specific UAs.', 'cloudscale-devtools' ); ?></p>

                    <div class="cs-thumb-cf-steps">
                        <div class="cs-thumb-cf-step">
                            <span class="cs-thumb-cf-step-num">1</span>
                            <div>
                                <strong><?php esc_html_e( 'Open Cloudflare Dashboard', 'cloudscale-devtools' ); ?></strong>
                                <p class="cs-hint"><?php esc_html_e( 'Go to your Cloudflare dashboard → select your domain → Security → WAF → Custom Rules.', 'cloudscale-devtools' ); ?></p>
                            </div>
                        </div>
                        <div class="cs-thumb-cf-step">
                            <span class="cs-thumb-cf-step-num">2</span>
                            <div>
                                <strong><?php esc_html_e( 'Create a Custom Rule: "Allow Social Crawlers"', 'cloudscale-devtools' ); ?></strong>
                                <p class="cs-hint"><?php esc_html_e( 'Use the Expression Editor and paste this expression:', 'cloudscale-devtools' ); ?></p>
                                <pre class="cs-thumb-cf-code">(http.user_agent contains "WhatsApp") or (http.user_agent contains "facebookexternalhit") or (http.user_agent contains "Facebot") or (http.user_agent contains "LinkedInBot") or (http.user_agent contains "Twitterbot")</pre>
                                <p class="cs-hint"><?php esc_html_e( 'Set the Action to "Skip" and tick "Bot Fight Mode" and "Super Bot Fight Mode".', 'cloudscale-devtools' ); ?></p>
                            </div>
                        </div>
                        <div class="cs-thumb-cf-step">
                            <span class="cs-thumb-cf-step-num">3</span>
                            <div>
                                <strong><?php esc_html_e( 'Deploy and verify', 'cloudscale-devtools' ); ?></strong>
                                <p class="cs-hint"><?php esc_html_e( 'Save the rule, then use the "Test Crawler Access" button below to confirm each crawler UA gets a 200 response with OG tags present.', 'cloudscale-devtools' ); ?></p>
                            </div>
                        </div>
                        <div class="cs-thumb-cf-step">
                            <span class="cs-thumb-cf-step-num">4</span>
                            <div>
                                <strong><?php esc_html_e( 'Cache note', 'cloudscale-devtools' ); ?></strong>
                                <p class="cs-hint"><?php esc_html_e( 'If social platforms have already cached a failed preview, purge the Cloudflare cache for that URL and then use each platform\'s debug tool to force a re-scrape (Facebook Sharing Debugger, LinkedIn Post Inspector, etc.).', 'cloudscale-devtools' ); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CF Cache Purge -->
                <div style="margin-top:18px;padding-top:16px;border-top:1px solid #e0e0e0">
                    <h3 style="font-size:14px;color:#333;margin-top:0"><?php esc_html_e( 'Cloudflare Cache Purge', 'cloudscale-devtools' ); ?></h3>
                    <p class="cs-hint"><?php esc_html_e( 'After fixing OG tags or image issues, purge the Cloudflare cache to force crawlers to re-fetch the page. Requires a Cloudflare API Token with Cache Purge permission and your Zone ID.', 'cloudscale-devtools' ); ?></p>

                    <div class="cs-field-row" style="flex-wrap:wrap;gap:12px">
                        <div class="cs-field" style="min-width:240px">
                            <label class="cs-label" for="cs-cf-zone-id"><?php esc_html_e( 'Zone ID', 'cloudscale-devtools' ); ?></label>
                            <div style="display:flex;gap:6px;align-items:center">
                                <input type="text" id="cs-cf-zone-id" class="cs-input"
                                       value="<?php echo esc_attr( $cf_zone_masked ?: '' ); ?>"
                                       data-real="<?php echo esc_attr( $cf_zone ); ?>"
                                       data-masked="<?php echo $cf_zone ? '1' : '0'; ?>"
                                       <?php if ( $cf_zone ) : ?>readonly<?php endif; ?>
                                       placeholder="<?php esc_attr_e( '32-character hex string', 'cloudscale-devtools' ); ?>">
                                <button type="button" id="cs-cf-zone-eye" title="<?php esc_attr_e( 'Show / hide Zone ID', 'cloudscale-devtools' ); ?>" style="flex-shrink:0;background:none;border:1px solid #ccc;border-radius:4px;padding:5px 9px;cursor:pointer;font-size:13px;line-height:1">&#x1F441;</button>
                            </div>
                        </div>
                        <div class="cs-field" style="min-width:280px">
                            <label class="cs-label" for="cs-cf-api-token"><?php esc_html_e( 'API Token (Cache Purge permission)', 'cloudscale-devtools' ); ?></label>
                            <div style="display:flex;gap:6px;align-items:center">
                                <input type="password" id="cs-cf-api-token" class="cs-input" value=""
                                       placeholder="<?php echo esc_attr( $cf_token_masked ?: __( 'Paste token here', 'cloudscale-devtools' ) ); ?>">
                                <button type="button" id="cs-cf-token-eye" title="<?php esc_attr_e( 'Show / hide token', 'cloudscale-devtools' ); ?>" style="flex-shrink:0;background:none;border:1px solid #ccc;border-radius:4px;padding:5px 9px;cursor:pointer;font-size:13px;line-height:1">&#x1F441;</button>
                            </div>
                            <span class="cs-hint"><?php esc_html_e( 'Leave blank to keep the saved token. Clear and save to remove.', 'cloudscale-devtools' ); ?></span>
                        </div>
                    </div>

                    <div style="margin:12px 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                        <input type="url" id="cs-cf-purge-url" class="cs-input cs-input-light-placeholder" style="max-width:420px;flex:1"
                               placeholder="<?php esc_attr_e( 'https://yoursite.com/your-post/ (leave blank to purge everything)', 'cloudscale-devtools' ); ?>">
                        <button type="button" class="cs-btn-primary" id="cs-cf-purge-btn">🗑️ <?php esc_html_e( 'Purge Cache', 'cloudscale-devtools' ); ?></button>
                        <button type="button" class="cs-btn-secondary" id="cs-cf-save-btn" style="background:#555;color:#fff;padding:7px 14px;border:none;border-radius:4px;cursor:pointer;font-size:13px">💾 <?php esc_html_e( 'Save Settings', 'cloudscale-devtools' ); ?></button>
                    </div>
                    <div id="cs-cf-purge-result" style="display:none;margin-top:8px"></div>
                    <span class="cs-settings-saved" id="cs-cf-saved">✓ <?php esc_html_e( 'CF Settings Saved', 'cloudscale-devtools' ); ?></span>
                </div>
            </div>
        </div>

        <div class="cs-panel" id="cs-panel-thumbs-media">
            <div class="cs-section-header" style="background:linear-gradient(135deg,#6a1b9a,#4a148c);">
                <span>📋 POST SOCIAL PREVIEW SCAN</span>
                <span class="cs-header-hint"><?php esc_html_e( 'Check the last 50 posts — will their images work on each social platform?', 'cloudscale-devtools' ); ?></span>
                <?php CloudScale_DevTools::render_explain_btn( 'post-social-scan', 'Post Social Preview Scan', [
                    [ 'name' => 'Facebook',      'rec' => 'Min 200×200',    'html' => 'Checks the WordPress featured image file directly — no live HTTP fetch. Recommended <code>1200×630 px</code>, max <code>8 MB</code>. Optimised versions are auto-generated to <code>/wp-content/uploads/social-formats/</code> when you publish or update a post.' ],
                    [ 'name' => 'X / Twitter',   'rec' => 'Min 280×150',    'html' => '<code>summary_large_image</code> card format. Recommended <code>1200×628 px</code>, max <code>5 MB</code>. Auto-generated at the correct crop on every post save with a new featured image.' ],
                    [ 'name' => 'WhatsApp',      'rec' => 'Max 300 KB',     'html' => 'Strict <code>300 KB</code> hard limit — images over this are <strong>silently hidden</strong> with no error message. The plugin automatically compresses the image at lower JPEG quality until it fits, so your WhatsApp preview will always appear.' ],
                    [ 'name' => 'LinkedIn',      'rec' => 'Min 200×110',    'html' => 'Recommended <code>1200×627 px</code>, max <code>5 MB</code>. Auto-generated with the correct crop. Portrait-oriented or very small images often display poorly in LinkedIn feed cards.' ],
                    [ 'name' => 'Instagram',     'rec' => '1080×1080 sq',   'html' => 'Square <code>1:1</code> format for direct feed post uploads. Min <code>320×320</code>, recommended <code>1080×1080</code>, max <code>8 MB</code>.<br><br><strong>Note:</strong> Instagram does not scrape OG tags for link previews — this format is for direct uploads only.' ],
                    [ 'name' => 'Auto-generate', 'rec' => 'Automatic',      'html' => 'Every time you publish or update a post with a new featured image, the plugin automatically generates correctly sized and compressed images for each enabled platform. Nothing changes if the featured image hasn\'t changed.' ],
                    [ 'name' => 'Fix',           'rec' => 'Manual action',  'html' => 'Manually triggers generation for a single post. Use this to regenerate after changing platform settings, or for posts that existed before auto-generation was enabled.' ],
                    [ 'name' => 'Fix all',            'rec' => 'Manual action',  'html' => 'Runs <strong>Fix</strong> for every post in the current scan results (up to 50). Useful for quickly fixing the posts you just scanned.' ],
                    [ 'name' => 'Fix All Posts on Site', 'rec' => 'Bulk action', 'html' => 'Processes every published post on the entire site in batches of <code>10</code>, generating platform formats for each. Shows live progress (e.g. <code>Fixing 45 / 320</code>). Posts without a featured image are skipped automatically.' ],
                    [ 'name' => 'Refresh Stale',  'rec' => 'Targeted bulk action', 'html' => 'Scans every published post and regenerates only the ones where the featured image has changed since the last generation — either because a different attachment was selected, or because the media file was replaced in the Media Library (same attachment ID, new file). Shows a live log of how many posts were found and fixed, with a clickable link to each one. Much faster than <em>Fix All Posts on Site</em> when only a handful of posts need updating.' ],
                    [ 'name' => 'Re-check',      'rec' => 'Diagnostic',     'html' => 'Runs the full live URL diagnostic (OG tags, robots.txt, crawler UA test) on this specific post URL and scrolls to the URL checker results above.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <p class="cs-hint" style="margin-bottom:10px"><?php esc_html_e( 'Checks the featured image of your last 50 published posts and shows per-platform compatibility. Uses local file data — no live HTTP requests.', 'cloudscale-devtools' ); ?></p>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <button type="button" class="cs-btn-primary" id="cs-thumb-audit-btn" data-mode="recent">📋 <?php esc_html_e( 'Scan Last 50 Posts', 'cloudscale-devtools' ); ?></button>
                    <button type="button" class="cs-btn-primary" id="cs-thumb-audit-top-btn" data-mode="top" style="background:#1565c0">🔥 <?php esc_html_e( 'Scan Top 50 Posts', 'cloudscale-devtools' ); ?></button>
                    <button type="button" class="cs-btn-primary" id="cs-thumb-audit-broken-btn" data-mode="broken_top" style="background:#b71c1c">🚨 <?php esc_html_e( 'Top 50 Broken', 'cloudscale-devtools' ); ?></button>
                    <button type="button" class="cs-btn-secondary" id="cs-thumb-fix-all-btn" style="display:none;background:#2271b1;color:#fff;padding:7px 14px;border:none;border-radius:4px;cursor:pointer;font-size:13px">🔧 <?php esc_html_e( 'Fix all', 'cloudscale-devtools' ); ?></button>
                    <button type="button" class="cs-btn-secondary" id="cs-thumb-fix-site-btn" style="background:#6a1b9a;color:#fff;padding:7px 14px;border:none;border-radius:4px;cursor:pointer;font-size:13px">🌐 <?php esc_html_e( 'Fix All Posts on Site', 'cloudscale-devtools' ); ?></button>
                    <button type="button" class="cs-btn-secondary" id="cs-thumb-refresh-stale-btn" style="background:#1565c0;color:#fff;padding:7px 14px;border:none;border-radius:4px;cursor:pointer;font-size:13px">🔄 <?php esc_html_e( 'Refresh Stale', 'cloudscale-devtools' ); ?></button>
                    <span id="cs-thumb-audit-progress" style="font-size:12px;color:#888"></span>
                </div>
                <div id="cs-thumb-stale-log" style="display:none;margin-top:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px;font-size:13px;"></div>
                <div id="cs-thumb-audit-results" style="margin-top:14px;display:none"></div>
            </div>
        </div>

        <div class="cs-panel" id="cs-panel-regen-thumbnails">
            <div class="cs-section-header" style="background:linear-gradient(135deg,#2e7d32,#1b5e20);">
                <span>🔧 GENERATE MISSING THUMBNAILS</span>
                <span class="cs-header-hint"><?php esc_html_e( 'Regenerate WordPress image sizes deleted by a cleanup plugin', 'cloudscale-devtools' ); ?></span>
                <?php CloudScale_DevTools::render_explain_btn( 'regen-thumbnails', 'Generate Missing Thumbnails', [
                    [ 'name' => 'Why thumbnails go missing', 'rec' => 'Overview', 'html' => 'Image cleanup plugins delete "unused" files — including WordPress-generated intermediate sizes like <code>thumbnail</code>, <code>medium</code>, <code>medium_large</code>, and <code>large</code>. These are the files your theme requests when displaying featured images. When they are gone, WordPress falls back to the full-size original, which the theme then CSS-crops — causing edges to be cut off or the wrong portion to be shown.' ],
                    [ 'name' => 'Scan',                      'rec' => 'Non-destructive', 'html' => 'Checks every image in your Media Library against all registered WordPress image sizes. Reports how many images are missing one or more sizes, and flags which images are actively used as featured images on posts.' ],
                    [ 'name' => 'Regenerate All Missing',    'rec' => 'Safe to run',  'html' => 'Runs <code>wp_generate_attachment_metadata()</code> for each image that has missing sizes. Only generates what is missing — it does not touch images that are already complete. Original full-size files are never modified.' ],
                    [ 'name' => 'In-use images',             'rec' => 'Important',    'html' => 'Images marked <strong>Featured image</strong> are set as the featured image on one or more posts. These are the most important to regenerate as they directly affect how your articles look. Consider excluding the <code>/wp-content/uploads/</code> folder from your cleanup plugin, or at minimum protecting any image shown here.' ],
                    [ 'name' => 'Performance note',          'rec' => 'Info',         'html' => 'Processing runs in batches of 5 images per request to avoid server timeouts. A site with 200 images takes roughly 40 requests — expect 30–90 seconds total depending on your server speed.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <p class="cs-hint" style="margin-bottom:10px"><?php esc_html_e( 'If a cleanup plugin deleted your image thumbnails, articles will show the wrong crop. Scan to see how many are affected, then regenerate all missing sizes in one click.', 'cloudscale-devtools' ); ?></p>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <button type="button" class="cs-btn-primary" id="csdt-regen-scan-btn">🔍 <?php esc_html_e( 'Scan for Missing Sizes', 'cloudscale-devtools' ); ?></button>
                    <button type="button" class="cs-btn-primary" id="csdt-regen-all-btn" style="display:none;background:#2e7d32">⚙️ <?php esc_html_e( 'Regenerate All Missing', 'cloudscale-devtools' ); ?></button>
                    <span id="csdt-regen-progress" style="font-size:12px;color:#888"></span>
                    <span id="csdt-regen-done-label" style="display:none;font-size:12px;font-weight:600;color:#166534"></span>
                </div>
                <div id="csdt-regen-log" style="display:none;margin-top:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px;font-size:13px;"></div>
                <div id="csdt-regen-results" style="margin-top:14px;display:none"></div>
            </div>
        </div>

        <?php
        $enabled_platforms = get_option( 'csdt_devtools_social_platforms', array_keys( self::SOCIAL_PLATFORMS ) );
        ?>
        <div class="cs-panel" id="cs-panel-thumbs-platforms">
            <div class="cs-section-header" style="background:linear-gradient(135deg,#00695c,#004d40);">
                <span>🎨 SOCIAL FORMAT SETTINGS</span>
                <span class="cs-header-hint"><?php esc_html_e( 'Auto-generates on every post save — select which platforms to prepare images for', 'cloudscale-devtools' ); ?></span>
                <?php CloudScale_DevTools::render_explain_btn( 'social-formats', 'Social Format Settings', [
                    [ 'name' => 'Facebook 1200×630',    'rec' => 'Optimum ~400 KB',  'html' => 'Optimum: <code>1200×630 px</code> at under <code>400 KB</code> JPEG. Hard limit: <code>8 MB</code>. Minimum: <code>200×200 px</code>. Landscape <code>1.91:1</code> ratio — the plugin auto-crops to this exact frame so Facebook always shows your image, not a random one from the page.' ],
                    [ 'name' => 'X / Twitter 1200×628', 'rec' => 'Optimum ~400 KB',  'html' => 'Optimum: <code>1200×628 px</code> at under <code>400 KB</code> JPEG. Hard limit: <code>5 MB</code>. Minimum for large card: <code>280×150 px</code>. Slightly shorter than Facebook — a separate dedicated crop prevents the subject being letterboxed or clipped.' ],
                    [ 'name' => 'WhatsApp 1200×630',    'rec' => 'Optimum ~200 KB',  'html' => 'Optimum: <code>1200×630 px</code> at under <code>200 KB</code> JPEG. Hard limit: <strong><code>300 KB</code></strong> — images over this are <strong>silently dropped</strong> with no error message. The plugin targets <code>200 KB</code> for a safe margin, retrying at lower quality until the file fits.' ],
                    [ 'name' => 'LinkedIn 1200×627',    'rec' => 'Optimum ~400 KB',  'html' => 'Optimum: <code>1200×627 px</code> at under <code>400 KB</code> JPEG. Hard limit: <code>5 MB</code>. Minimum: <code>200×110 px</code>. Landscape cards perform best — portrait images are cropped awkwardly or shown very small in the LinkedIn feed.' ],
                    [ 'name' => 'Instagram 1080×1080',  'rec' => 'Optimum ~400 KB',  'html' => 'Optimum: <code>1080×1080 px</code> square at under <code>400 KB</code> JPEG. Hard limit: <code>8 MB</code>. Minimum: <code>320×320 px</code>. Square <code>1:1</code> crop for direct feed uploads — Instagram does not scrape OG tags for link preview cards.' ],
                    [ 'name' => 'Auto-generation',      'rec' => 'How it works',     'html' => 'Every time you publish or update a post with a new featured image, the plugin automatically generates all enabled platform formats at the optimum size and quality — not just within the hard limit. Unchanged images are skipped. <strong>Originals are never modified.</strong>' ],
                    [ 'name' => 'Save Settings',        'rec' => 'Required once',    'html' => 'Saves which platforms are enabled. Only checked platforms are generated on post save. Changes take effect on the next publish or update.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <p class="cs-hint" style="margin-bottom:14px"><?php esc_html_e( 'Social format images are generated automatically every time you publish or update a post with a new featured image. You can also generate them manually using the Fix button in the scan above. Original images are never modified.', 'cloudscale-devtools' ); ?></p>
                <div class="cs-platform-grid">
                    <?php foreach ( self::SOCIAL_PLATFORMS as $key => $p ) :
                        $checked   = in_array( $key, $enabled_platforms, true );
                        $opt_kb    = $p['target_kb'];
                        $size_note = $opt_kb >= 1000 ? 'optimum ~' . ( $opt_kb / 1000 ) . ' MB' : 'optimum ~' . $opt_kb . ' KB';
                        ?>
                        <label class="cs-platform-card <?php echo $checked ? 'cs-platform-checked' : ''; ?>">
                            <input type="checkbox" name="cs_social_platform[]" value="<?php echo esc_attr( $key ); ?>"
                                   class="cs-platform-cb" <?php checked( $checked ); ?>>
                            <div class="cs-platform-card-body">
                                <span class="cs-platform-name"><?php echo esc_html( $p['label'] ); ?></span>
                                <span class="cs-platform-dims"><?php echo esc_html( $p['w'] . '×' . $p['h'] . 'px' ); ?></span>
                                <span class="cs-platform-limit"><?php echo esc_html( $size_note ); ?></span>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:14px;display:flex;align-items:center;gap:10px">
                    <button type="button" class="cs-btn-primary" id="cs-platform-save-btn">💾 <?php esc_html_e( 'Save Settings', 'cloudscale-devtools' ); ?></button>
                    <span class="cs-settings-saved" id="cs-platform-saved">✓ <?php esc_html_e( 'Saved', 'cloudscale-devtools' ); ?></span>
                </div>
            </div>
        </div>

        <?php
        // CSS for this tab is injected via wp_add_inline_style() in enqueue_admin_assets().
        // See get_thumbnails_admin_css() for the ruleset.
    }

    // ─── Featured Images tab ─────────────────────────────────────────────────

    private const DEFAULT_IMG_SYSTEM_PROMPT = 'You write DALL-E 3 prompts for 1792x1024 WordPress blog post header images. Output ONLY the prompt — no preamble.

━━ PROCESS ━━
Read the article. Then write a DALL-E 3 prompt that creates a cinematic, dramatic image a reader would immediately connect to this specific article.

━━ BRAND ICONS ━━
CRITICAL RULE: NEVER ask DALL-E to render a brand name as text — DALL-E mis-renders words. Describe the PHYSICAL FORM instead. A brand is recognised by its shape, colour, and form — not its name written on something.

COMPANIES / PRODUCTS — use the PHYSICAL FORM, not text:
- ARM / ARM server: a green Raspberry Pi PCB (iconic green circuit board with rows of GPIO pins) OR a bare ARM processor die (small square chip with gold contact pads). NEVER "ARM" lettering.
- x86 / Intel server: a large silver Intel CPU lid (rectangular metal cap with subtle Intel logo embossed). NEVER a generic server tower with text.
- Cloudflare: a large orange shield shape with a globe/grid pattern on it — the Cloudflare logo shield in solid orange.
- AWS: the AWS orange curved smile-arrow arch (the distinctive orange arch with the arrow smile).
- NVMe / SSD storage: slim flat black M.2 NVMe sticks standing upright — small black rectangular cards with gold edge connectors.
- Docker: a blue Docker whale silhouette with coloured shipping containers stacked on its back.
- GitHub: a black Octocat silhouette — cat body with octopus tentacles.
- Raspberry Pi (brand): a green PCB board with a raspberry fruit logo. For the fruit itself: a red berry made of drupelets.
- For all other well-known brands: use your training knowledge of their visual form.

PROTOCOLS / STANDARDS / CONCEPTS (TCP, QUIC, HTTP/3, UDP, TLS, DNS, etc.): no real logo exists. Represent them with a concrete physical metaphor for what they DO — e.g. QUIC = a supersonic data packet as a glowing blue dart; TCP = a heavy gear-and-piston mechanism grinding slowly. Apply narrative conditions to these too.

━━ NARRATIVE CONDITIONS ━━
Read the article angle CAREFULLY before assigning roles — the same technology can play different roles depending on the article\'s message:

- CHAMPION / winner / rising technology → gleaming, clean, cool, powerful, radiating controlled energy
- STRUGGLING / legacy / being-disrupted → glowing red-hot edges, heat shimmer, cracking casing, smoke from vents
- DISRUPTOR / HIDDEN THREAT / WARNING → the subject LOOKS fast and modern but causes unexpected breakage — show it as a sleek projectile that leaves fractured connections, broken pipes, or error sparks behind it; its surface is clean but its WAKE is destructive
- NEUTRAL / tool → normal condition

IMPORTANT: A technology can be modern AND dangerous. An article titled "X breaks your site without warning" means X is a DISRUPTOR/THREAT, not a CHAMPION — even if X is technically superior. Read the article\'s WARNING signals, not just its technical comparisons.

━━ COMPOSITION ━━
- Fill 85%+ of the frame. No large empty backgrounds.
- Cinematic photorealism by default. Macro detail. Dramatic lighting.
- For warning / danger / unexpected-failure articles: show the moment of impact — a fast sleek element causing visible destruction: fractured cables, cracked pipes, broken connections, flying sparks.
- For tutorials / how-tos: cinematic workshop or workbench scene, hands and tools mid-action.
- BANNED: aerial data-centre city skylines, glowing server-tower cityscapes, abstract streams of light, generic split-screen rivalries.
- Safety: never imply hacking, illegal acts, or breaches — describe the defensive side instead.

━━ TEXT RULE ━━
The text rule is passed separately in the user message — follow it exactly.

━━ OUTPUT ━━
2–3 sentences. Visual style first, then the specific scene.';

    private static function get_img_system_prompt(): string {
        $saved = (string) get_option( 'csdt_devtools_img_system_prompt', self::DEFAULT_IMG_SYSTEM_PROMPT );
        // Auto-migrate: reset saved prompt if it matches any known old version.
        // v9+ adds physical form reference for key brands; any version lacking it should be replaced.
        if ( strpos( $saved, 'DALL-E mis-renders words' ) === false ) {
            $saved = self::DEFAULT_IMG_SYSTEM_PROMPT;
            update_option( 'csdt_devtools_img_system_prompt', $saved, false );
        }
        return $saved;
    }

    public static function render_ai_images_panel(): void {
        $openai_key    = (string) get_option( 'csdt_devtools_openai_key', '' );
        $anthropic_key = (string) get_option( 'csdt_devtools_anthropic_key', '' );
        $gemini_key    = (string) get_option( 'csdt_devtools_gemini_key', '' );
        $saved_vendor  = (string) get_option( 'csdt_devtools_prompt_vendor', 'openai' );
        $saved_model   = (string) get_option( 'csdt_devtools_prompt_model', 'gpt-4o' );
        $saved_style   = (string) get_option( 'csdt_devtools_img_style', 'auto' );
        $saved_quality = (string) get_option( 'csdt_devtools_img_quality', 'standard' );
        $saved_no_text = (bool)   get_option( 'csdt_devtools_img_no_text', false );
        $system_prompt = self::get_img_system_prompt();
        $keys_json     = wp_json_encode( [
            'openai'    => $openai_key,
            'anthropic' => $anthropic_key,
            'gemini'    => $gemini_key,
        ] );
        ?>
        <div class="cs-panel" id="cs-panel-ai-image-gen">
            <div class="cs-section-header" style="background:linear-gradient(135deg,#0d7377,#14a085);">
                <span>🎨 FEATURED IMAGE GENERATOR</span>
                <span class="cs-header-hint"><?php esc_html_e( 'Generate DALL-E 3 featured images for posts that have none', 'cloudscale-devtools' ); ?></span>
                <?php CloudScale_DevTools::render_explain_btn( 'ai-image-gen', 'AI Image Generator', [
                    [ 'name' => 'What it does',     'rec' => 'Overview',         'html' => 'Finds your published posts that have no featured image, then uses DALL-E 3 (OpenAI) to generate a 1792×1024 landscape header image tailored to each post. The image is automatically uploaded to your Media Library and set as the featured image.' ],
                    [ 'name' => 'How to set up',    'rec' => 'Step-by-step',     'html' => '<strong>Step 1: Pick a vendor</strong> — choose which AI writes the prompt description sent to DALL-E. OpenAI (ChatGPT) is recommended as it shares your billing account.<br><strong>Step 2: Pick a model</strong> — GPT-4o mini is fast and cheap (~$0.001/prompt). Use a larger model for better prompt quality.<br><strong>Step 3: Enter your API key</strong> for that vendor and click Save Key.<br><strong>Note:</strong> DALL-E 3 always uses OpenAI for image generation. If you choose Anthropic or Google as your prompt writer, you also need to enter an OpenAI key in the DALL-E row.' ],
                    [ 'name' => 'Image quality',    'rec' => 'Standard recommended', 'html' => '<strong>Standard</strong> — $0.04 per image (1792×1024 px).<br><strong>HD</strong> — $0.08 per image. More detail.' ],
                    [ 'name' => 'Cost estimate',    'rec' => 'Info',             'html' => 'With GPT-4o mini as prompt writer + Standard quality: ~<strong>$0.041 per image</strong>. A $5 top-up covers ~120 posts.' ],
                    [ 'name' => 'After generation', 'rec' => 'Info',             'html' => 'The image is uploaded as a WordPress attachment and set as the featured image. Social format crops (Facebook, Twitter, etc.) are generated immediately.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">

                <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- inline config vars for image generator; wp_add_inline_script not available at this render point ?>
                <script>
                var csdtImgKeys = <?php echo $keys_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded ?>;
                var csdtImgVendor = <?php echo wp_json_encode( $saved_vendor ); ?>;
                var csdtImgModel  = <?php echo wp_json_encode( $saved_model ); ?>;
                var csdtImgStyle   = <?php echo wp_json_encode( $saved_style ); ?>;
                var csdtImgQuality = <?php echo wp_json_encode( $saved_quality ); ?>;
                var csdtImgNoText  = <?php echo wp_json_encode( $saved_no_text ); ?>;
                var csdtImgDefaultSysprompt = <?php echo wp_json_encode( self::DEFAULT_IMG_SYSTEM_PROMPT ); ?>;
                </script>

                <!-- Prompt writer vendor -->
                <div class="cs-sec-row">
                    <span class="cs-sec-label"><?php esc_html_e( 'Prompt writer:', 'cloudscale-devtools' ); ?></span>
                    <div class="cs-sec-control">
                        <select id="cs-ai-img-vendor" class="cs-sec-select">
                            <option value="openai"    <?php selected( $saved_vendor, 'openai' ); ?>><?php esc_html_e( 'OpenAI (ChatGPT)', 'cloudscale-devtools' ); ?></option>
                            <option value="anthropic" <?php selected( $saved_vendor, 'anthropic' ); ?>><?php esc_html_e( 'Anthropic (Claude)', 'cloudscale-devtools' ); ?></option>
                            <option value="gemini"    <?php selected( $saved_vendor, 'gemini' ); ?>><?php esc_html_e( 'Google (Gemini)', 'cloudscale-devtools' ); ?></option>
                            <option value="none"      <?php selected( $saved_vendor, 'none' ); ?>><?php esc_html_e( 'None — use post title only', 'cloudscale-devtools' ); ?></option>
                        </select>
                        <span class="cs-hint"><?php esc_html_e( 'The AI that reads your post content and writes the DALL-E image description. OpenAI is recommended — it shares the same billing account as DALL-E.', 'cloudscale-devtools' ); ?></span>
                    </div>
                </div>

                <!-- Model (hidden when vendor = none) -->
                <div class="cs-sec-row" id="cs-ai-img-model-row">
                    <span class="cs-sec-label"><?php esc_html_e( 'Model:', 'cloudscale-devtools' ); ?></span>
                    <div class="cs-sec-control">
                        <select id="cs-ai-img-model" class="cs-sec-select">
                            <?php
                            $model_options = [
                                'openai'    => [
                                    '_auto'                  => 'Automatic (best)',
                                    'gpt-4o-mini'            => 'GPT-4o mini (fast)',
                                    'gpt-4o'                 => 'GPT-4o',
                                ],
                                'anthropic' => [
                                    '_auto'                        => 'Automatic (best)',
                                    'claude-haiku-4-5-20251001'    => 'Claude Haiku 4.5 (fast)',
                                    'claude-sonnet-4-6'            => 'Claude Sonnet 4.6',
                                    'claude-opus-4-7'              => 'Claude Opus 4.7 (best)',
                                ],
                                'gemini'    => [
                                    '_auto'              => 'Automatic (best)',
                                    'gemini-2.0-flash'   => 'Gemini 2.0 Flash (fast)',
                                    'gemini-1.5-pro'     => 'Gemini 1.5 Pro',
                                ],
                            ];
                            $opts = $model_options[ $saved_vendor ] ?? $model_options['openai'];
                            foreach ( $opts as $val => $label ) {
                                printf(
                                    '<option value="%s"%s>%s</option>',
                                    esc_attr( $val ),
                                    selected( $saved_model, $val, false ),
                                    esc_html( $label )
                                );
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <!-- API key for selected vendor (hidden when vendor = none) -->
                <div class="cs-sec-row" id="cs-ai-img-key-row">
                    <span class="cs-sec-label" id="cs-ai-img-key-label"><?php esc_html_e( 'API Key:', 'cloudscale-devtools' ); ?></span>
                    <div class="cs-sec-control">
                        <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-start">
                            <div style="position:relative;display:flex;align-items:center;width:100%;max-width:480px">
                                <input type="password" id="cs-ai-img-openai-key" class="cs-text-input cs-sec-key-input"
                                       autocomplete="off" placeholder="sk-proj-…"
                                       style="padding-right:36px;width:100%;box-sizing:border-box">
                                <button type="button" id="cs-ai-img-key-toggle" title="Show / hide key"
                                        style="position:absolute;right:8px;background:none;border:none;cursor:pointer;padding:0;line-height:1;font-size:16px;color:#94a3b8">👁</button>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                <button type="button" class="cs-btn-secondary" id="cs-ai-img-save-key"><?php esc_html_e( 'Save Key', 'cloudscale-devtools' ); ?></button>
                                <button type="button" id="cs-ai-img-test-key" style="background:#16a34a;color:#fff;border:1px solid #15803d;border-radius:4px;padding:5px 14px;font-size:13px;font-weight:600;cursor:pointer"><?php esc_html_e( 'Test', 'cloudscale-devtools' ); ?></button>
                                <button type="button" id="cs-ai-img-copy-key" style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;border-radius:4px;padding:5px 14px;font-size:13px;font-weight:600;cursor:pointer" title="Copy key to clipboard">📋 <?php esc_html_e( 'Copy', 'cloudscale-devtools' ); ?></button>
                                <span id="cs-ai-img-key-status" class="cs-sec-key-status"></span>
                            </div>
                        </div>
                        <span class="cs-hint" id="cs-ai-img-key-hint"></span>
                    </div>
                </div>

                <!-- DALL-E key (OpenAI) — only shown when prompt writer vendor ≠ openai -->
                <div class="cs-sec-row" id="cs-ai-img-dalle-key-row" style="display:none">
                    <span class="cs-sec-label"><?php esc_html_e( 'OpenAI key (for DALL-E image generation):', 'cloudscale-devtools' ); ?></span>
                    <div class="cs-sec-control">
                        <div style="position:relative;display:flex;align-items:center;width:100%;max-width:480px">
                            <input type="password" id="cs-ai-img-dalle-key" class="cs-text-input cs-sec-key-input"
                                   autocomplete="off" placeholder="sk-proj-…"
                                   style="padding-right:36px;width:100%;box-sizing:border-box"
                                   value="<?php echo esc_attr( $openai_key ); ?>">
                            <button type="button" id="cs-ai-img-dalle-key-toggle" title="Show / hide key"
                                    style="position:absolute;right:8px;background:none;border:none;cursor:pointer;padding:0;line-height:1;font-size:16px;color:#94a3b8">👁</button>
                        </div>
                        <div style="margin-top:6px;display:flex;align-items:center;gap:8px">
                            <button type="button" id="cs-ai-img-dalle-save-key" class="cs-btn-secondary"><?php esc_html_e( 'Save DALL-E Key', 'cloudscale-devtools' ); ?></button>
                            <span id="cs-ai-img-dalle-key-status" class="cs-sec-key-status"><?php echo $openai_key ? '<span style="color:#2e7d32">✓ Key saved</span>' : ''; ?></span>
                        </div>
                        <span class="cs-hint"><?php esc_html_e( 'Your Google/Anthropic key above writes the prompt. This separate OpenAI key is required because DALL-E 3 (the image generator) is an OpenAI-only product.', 'cloudscale-devtools' ); ?></span>
                    </div>
                </div>

                <div class="cs-sec-row">
                    <span class="cs-sec-label"><?php esc_html_e( 'Image quality:', 'cloudscale-devtools' ); ?></span>
                    <div class="cs-sec-control">
                        <select id="cs-ai-img-quality" class="cs-sec-select">
                            <option value="standard" <?php selected( $saved_quality, 'standard' ); ?>><?php esc_html_e( 'Standard ($0.04 / image)', 'cloudscale-devtools' ); ?></option>
                            <option value="hd" <?php selected( $saved_quality, 'hd' ); ?>><?php esc_html_e( 'HD ($0.08 / image)', 'cloudscale-devtools' ); ?></option>
                        </select>
                    </div>
                </div>

                <div class="cs-sec-row">
                    <span class="cs-sec-label"><?php esc_html_e( 'Options:', 'cloudscale-devtools' ); ?></span>
                    <div class="cs-sec-control" style="display:flex;flex-direction:column;gap:8px">
                        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
                            <input type="checkbox" id="cs-ai-img-no-text" style="width:16px;height:16px" <?php checked( $saved_no_text ); ?>>
                            <?php esc_html_e( 'No text in image (avoids misspellings — DALL-E draws no labels or titles)', 'cloudscale-devtools' ); ?>
                        </label>
                    </div>
                </div>

                <!-- Image style preset -->
                <div class="cs-sec-row">
                    <span class="cs-sec-label"><?php esc_html_e( 'Image style:', 'cloudscale-devtools' ); ?></span>
                    <div class="cs-sec-control">
                        <select id="cs-ai-img-style" class="cs-sec-select">
                            <option value="auto" <?php selected( $saved_style, 'auto' ); ?>><?php esc_html_e( 'Auto (AI picks best style)', 'cloudscale-devtools' ); ?></option>
                            <option value="cinematic_poster" <?php selected( $saved_style, 'cinematic_poster' ); ?>><?php esc_html_e( '🎬 Cinematic Poster (bold text in image)', 'cloudscale-devtools' ); ?></option>
                            <option value="photorealistic" <?php selected( $saved_style, 'photorealistic' ); ?>><?php esc_html_e( 'Cinematic Photorealistic', 'cloudscale-devtools' ); ?></option>
                            <option value="editorial" <?php selected( $saved_style, 'editorial' ); ?>><?php esc_html_e( 'Editorial Photography', 'cloudscale-devtools' ); ?></option>
                            <option value="technical_infographic" <?php selected( $saved_style, 'technical_infographic' ); ?>><?php esc_html_e( 'Technical Infographic', 'cloudscale-devtools' ); ?></option>
                            <option value="isometric" <?php selected( $saved_style, 'isometric' ); ?>><?php esc_html_e( 'Isometric 3D', 'cloudscale-devtools' ); ?></option>
                            <option value="cartoon" <?php selected( $saved_style, 'cartoon' ); ?>><?php esc_html_e( 'Cartoon / Illustration', 'cloudscale-devtools' ); ?></option>
                            <option value="minimalist" <?php selected( $saved_style, 'minimalist' ); ?>><?php esc_html_e( 'Minimalist', 'cloudscale-devtools' ); ?></option>
                        </select>
                        <span class="cs-hint"><?php esc_html_e( 'Override the visual style. "Auto" defers to your system prompt instructions below.', 'cloudscale-devtools' ); ?></span>
                    </div>
                </div>

                <!-- System prompt editor -->
                <div class="cs-sec-row">
                    <span class="cs-sec-label" style="padding-top:4px"><?php esc_html_e( 'Prompt instructions:', 'cloudscale-devtools' ); ?></span>
                    <div class="cs-sec-control">
                        <textarea id="cs-ai-img-system-prompt" rows="10"
                                  style="width:100%;max-width:560px;font-size:12px;font-family:monospace;padding:8px;border:1px solid #cbd5e1;border-radius:4px;box-sizing:border-box;resize:vertical;line-height:1.5"
                                  placeholder="Instructions sent to the AI when writing the DALL-E prompt…"><?php echo esc_textarea( $system_prompt ); ?></textarea>
                        <div style="margin-top:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                            <button type="button" id="cs-ai-img-save-sysprompt" class="cs-btn-secondary"><?php esc_html_e( 'Save Instructions', 'cloudscale-devtools' ); ?></button>
                            <button type="button" id="cs-ai-img-reset-sysprompt" style="background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1;border-radius:4px;padding:5px 12px;font-size:12px;cursor:pointer"><?php esc_html_e( 'Reset to default', 'cloudscale-devtools' ); ?></button>
                            <span id="cs-ai-img-sysprompt-status" style="font-size:12px"></span>
                        </div>
                        <span class="cs-hint"><?php esc_html_e( 'These instructions tell the AI how to write the DALL-E description. Edit to enforce a consistent style (e.g. "dark technical infographic, no photorealism"). You can also edit each prompt individually before the image is generated.', 'cloudscale-devtools' ); ?></span>
                    </div>
                </div>

                <div style="margin:16px 0 12px;display:flex;flex-direction:column;gap:8px">
                    <button type="button" class="cs-btn-primary" id="cs-ai-img-scan-btn" data-mode="missing" style="width:100%;text-align:center;font-size:13px;padding:10px 16px">🔍 <?php esc_html_e( 'Find posts without featured image', 'cloudscale-devtools' ); ?></button>
                    <button type="button" id="cs-ai-img-scan-with-btn" data-mode="with_image" style="width:100%;text-align:center;background:#475569;color:#fff;border:none;border-radius:5px;padding:10px 16px;font-size:13px;font-weight:700;cursor:pointer">🖼 <?php esc_html_e( 'Find posts with featured image', 'cloudscale-devtools' ); ?></button>
                </div>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                    <span style="font-size:12px;color:#64748b;font-weight:600"><?php esc_html_e( 'Sort:', 'cloudscale-devtools' ); ?></span>
                    <select id="cs-ai-img-sort" style="font-size:12px;padding:3px 8px;border:1px solid #cbd5e1;border-radius:4px;background:#fff;color:#334155">
                        <option value="newest"><?php esc_html_e( 'Newest first', 'cloudscale-devtools' ); ?></option>
                        <option value="oldest"><?php esc_html_e( 'Oldest first', 'cloudscale-devtools' ); ?></option>
                        <option value="img_date"><?php esc_html_e( 'Image date (newest)', 'cloudscale-devtools' ); ?></option>
                        <option value="popular"><?php esc_html_e( 'Most popular', 'cloudscale-devtools' ); ?></option>
                        <option value="longest"><?php esc_html_e( 'Longest first', 'cloudscale-devtools' ); ?></option>
                    </select>
                </div>
                <div id="cs-ai-img-results" style="display:none;margin-top:4px"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Validates that a URL does not point to a private/reserved IP address.
     *
     * Prevents SSRF attacks from admin-initiated URL checks. Only allows
     * publicly routable destinations; rejects localhost, RFC-1918 ranges,
     * link-local, and other reserved blocks.
     *
     * @param  string $url The URL to validate.
     * @return bool         True if safe to fetch, false if internal/reserved.
     */
    private static function is_safe_external_url( string $url ): bool {
        if ( ! wp_http_validate_url( $url ) ) {
            return false;
        }
        $parsed = wp_parse_url( $url );
        $host   = $parsed['host'] ?? '';
        if ( ! $host ) {
            return false;
        }
        // Resolve hostname to IP.
        $ip = gethostbyname( $host );
        // gethostbyname returns the original hostname unchanged on failure.
        if ( $ip === $host && ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            // Could not resolve — reject.
            return false;
        }
        // Reject private/reserved ranges.
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    // ─── AJAX: check a single URL ────────────────────────────────────────

    public static function ajax_social_check_url(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
        if ( ! $url ) {
            wp_send_json_error( [ 'message' => 'No URL provided.' ] );
        }
        if ( ! self::is_safe_external_url( $url ) ) {
            wp_send_json_error( [ 'message' => 'URL must be a publicly accessible address.' ] );
        }
        $post_id = url_to_postid( $url );
        if ( $post_id ) {
            $chk = get_post( $post_id );
            if ( $chk && ( $chk->post_status === 'private' || ! empty( $chk->post_password ) ) ) {
                wp_send_json_error( [ 'message' => 'This post is password-protected or private — social crawlers cannot read it. Please enter a public URL.' ] );
            }
        }
        wp_send_json_success( self::social_diagnose_url( $url ) );
    }

    // ─── AJAX: scan last 10 posts ────────────────────────────────────────

    public static function ajax_social_scan_posts(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'has_password'   => false,
            'meta_query'     => [ [ 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ] ],
        ] );
        $results = [];
        foreach ( $posts as $post ) {
            $url      = get_permalink( $post );
            $diag     = self::social_diagnose_url( $url );
            $thumb_id = get_post_thumbnail_id( $post->ID );
            $attach_id = $thumb_id ? (int) $thumb_id : null;
            $can_fix   = false;
            if ( $attach_id ) {
                $file    = get_attached_file( $attach_id );
                $can_fix = $file && file_exists( $file );
            }
            $results[] = [
                'id'        => $post->ID,
                'title'     => get_the_title( $post ),
                'url'       => $url,
                'totals'    => $diag['totals'],
                'og_image'  => $diag['og_image'] ?? '',
                'img_kb'    => $diag['img_kb'] ?? null,
                'img_w'     => $diag['img_w'] ?? null,
                'img_h'     => $diag['img_h'] ?? null,
                'attach_id' => $attach_id,
                'can_fix'   => $can_fix,
            ];
        }
        wp_send_json_success( $results );
    }

    // ─── Per-platform compatibility check ────────────────────────────────

    /**
     * Check platform compatibility for a post's featured image.
     *
     * When social format files already exist (generated by "Generate social crops"),
     * those are what actually get served as og:image — so their size/dimensions are
     * what matter, not the source image file.  Source image size is only relevant
     * when no social formats have been generated yet.
     */
    private static function check_platform_compat( int $width, int $height, float $kb, bool $https, bool $has_formats = false, array $formats = [] ): array {
        $r = [];

        if ( ! $https ) {
            // HTTPS failure applies regardless of formats.
            foreach ( array_keys( self::SOCIAL_PLATFORMS ) as $key ) {
                $r[ $key ] = [ 'status' => 'fail', 'msg' => 'Image must be HTTPS' ];
            }
            return $r;
        }

        // ── If social formats are already generated, check those files ────────
        // The source image size/format is irrelevant — what gets served is the
        // pre-cropped file in /wp-content/uploads/social-formats/.
        if ( $has_formats ) {
            $checks = [
                'facebook' => [ 'max_kb' => 8000, 'target_kb' => 400,  'min_w' => 200, 'min_h' => 200 ],
                'twitter'  => [ 'max_kb' => 5000, 'target_kb' => 400,  'min_w' => 280, 'min_h' => 150 ],
                'whatsapp' => [ 'max_kb' => 300,  'target_kb' => 200,  'min_w' => 200, 'min_h' => 200 ],
                'linkedin' => [ 'max_kb' => 5000, 'target_kb' => 400,  'min_w' => 200, 'min_h' => 110 ],
                'instagram'=> [ 'max_kb' => 8000, 'target_kb' => 400,  'min_w' => 320, 'min_h' => 320 ],
            ];
            foreach ( $checks as $key => $limits ) {
                if ( ! isset( $formats[ $key ] ) ) {
                    $r[ $key ] = [ 'status' => 'warn', 'msg' => 'Social format not generated — click Generate social crops' ];
                    continue;
                }
                $f  = $formats[ $key ];
                $fkb = (float) ( $f['kb'] ?? 0 );
                if ( $fkb > $limits['max_kb'] ) {
                    $r[ $key ] = [ 'status' => 'fail', 'msg' => "Social format {$fkb} KB — over hard limit. Re-generate to compress" ];
                } elseif ( $fkb > $limits['target_kb'] ) {
                    $r[ $key ] = [ 'status' => 'warn', 'msg' => "Social format {$fkb} KB — above optimum ~{$limits['target_kb']} KB. Re-generate to compress" ];
                } else {
                    $r[ $key ] = [ 'status' => 'pass', 'msg' => "Social format ready — {$fkb} KB" ];
                }
            }
            return $r;
        }

        // ── No social formats yet — check source image croppability ──────────
        // Flag: can this image produce a 1200×630 crop without heavy upscaling?
        $can_crop = ( max( $width, $height ) >= 1200 && min( $width, $height ) >= 630 );

        // Facebook
        if ( $width < 200 || $height < 200 ) {
            $r['facebook'] = [ 'status' => 'fail', 'msg' => 'Too small — minimum 200×200 px' ];
        } elseif ( ! $can_crop ) {
            $r['facebook'] = [ 'status' => 'warn', 'msg' => 'No social formats generated — click Generate social crops' ];
        } else {
            $r['facebook'] = [ 'status' => 'warn', 'msg' => 'No social formats generated — click Generate social crops' ];
        }

        // X / Twitter
        $can_crop_twitter = ( max( $width, $height ) >= 1200 && min( $width, $height ) >= 628 );
        if ( $width < 280 || $height < 150 ) {
            $r['twitter'] = [ 'status' => 'fail', 'msg' => 'Too small — minimum 280×150 px for large card' ];
        } else {
            $r['twitter'] = [ 'status' => 'warn', 'msg' => 'No social formats generated — click Generate social crops' ];
        }

        // WhatsApp — source image size IS relevant here since no compressed format exists yet
        if ( $kb > 300 ) {
            $r['whatsapp'] = [ 'status' => 'fail', 'msg' => "Source image {$kb} KB — over 300 KB WhatsApp limit. Generate social crops will compress it" ];
        } else {
            $r['whatsapp'] = [ 'status' => 'warn', 'msg' => 'No social formats generated — click Generate social crops' ];
        }

        // LinkedIn
        if ( $width < 200 || $height < 110 ) {
            $r['linkedin'] = [ 'status' => 'fail', 'msg' => 'Too small — minimum 200×110 px' ];
        } else {
            $r['linkedin'] = [ 'status' => 'warn', 'msg' => 'No social formats generated — click Generate social crops' ];
        }

        // Instagram — source check only (no formats case)
        if ( $width < 320 || $height < 320 ) {
            $r['instagram'] = [ 'status' => 'fail', 'msg' => 'Too small — minimum 320×320 px' ];
        } else {
            $r['instagram'] = [ 'status' => 'warn', 'msg' => 'No social formats generated — click Generate social crops' ];
        }

        return $r;
    }

    // ─── AJAX: scan featured images for last 50 posts ─────────────────────

    public static function ajax_social_scan_media(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $raw_mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'recent';
        $mode     = in_array( $raw_mode, [ 'top', 'broken_top' ], true ) ? $raw_mode : 'recent';

        // Expanded view-count meta key list — same as the AI image handler.
        $view_meta_keys = [
            '_cspv_view_count', 'ab_post_views',
            'post_views_count', 'views', '_post_views', 'wpb_post_views_count',
            'jetpack-views', '_postviews_counter', 'tally', 'total_views',
            'views_counter', 'hit_count',
        ];

        // Detect which meta key is actually in use (check once for a sample post).
        $view_meta_found = false;
        foreach ( $view_meta_keys as $mk ) {
            $sample = get_posts( [ 'post_type' => 'post', 'posts_per_page' => 1, 'meta_key' => $mk, 'orderby' => 'meta_value_num', 'order' => 'DESC', 'fields' => 'ids' ] );
            if ( ! empty( $sample ) ) {
                $view_meta_found = $mk;
                break;
            }
        }

        // For top/broken_top we need popularity data — fetch a larger pool then re-sort in PHP.
        $needs_popularity = in_array( $mode, [ 'top', 'broken_top' ], true );
        $fetch_count      = $needs_popularity ? 500 : 50;

        $query_args = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $fetch_count,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $posts = get_posts( $query_args );

        $results = [];

        foreach ( $posts as $post_id ) {
            $post_url  = get_permalink( $post_id );
            $thumb_id  = get_post_thumbnail_id( $post_id );

            // Collect view + comment counts for popularity sorting and display.
            $post_obj    = get_post( $post_id );
            $view_count  = null;
            if ( $view_meta_found ) {
                $val = get_post_meta( $post_id, $view_meta_found, true );
                if ( $val !== '' && $val !== false ) {
                    $view_count = (int) $val;
                }
            }
            $comment_count = (int) ( $post_obj ? $post_obj->comment_count : 0 );

            if ( ! $thumb_id ) {
                // No featured image — all platforms fail.
                $all_fail = [];
                foreach ( array_keys( self::SOCIAL_PLATFORMS ) as $key ) {
                    $all_fail[ $key ] = [ 'status' => 'fail', 'msg' => 'No featured image set' ];
                }
                $results[] = [
                    'post_id'       => $post_id,
                    'title'         => get_the_title( $post_id ),
                    'post_url'      => $post_url,
                    'img_url'       => '',
                    'attach_id'     => null,
                    'width'         => 0,
                    'height'        => 0,
                    'size_kb'       => null,
                    'status'        => 'fail',
                    'no_image'      => true,
                    'platforms'     => $all_fail,
                    'can_fix'       => false,
                    'view_count'    => $view_count,
                    'comment_count' => $comment_count,
                ];
                continue;
            }

            $attach_id = (int) $thumb_id;
            $img_url   = wp_get_attachment_url( $attach_id );
            $https     = $img_url && str_starts_with( $img_url, 'https://' );
            $kb        = null;
            $can_fix   = false;

            // Use the largest available registered size as the effective source dimensions —
            // a 480×720 "full" may have been generated from a 1024×1536 original upload
            // whose "large" size is still on disk and can be used without upscaling.
            $best   = self::get_best_source_file( $attach_id );
            $width  = $best ? $best['width']  : 0;
            $height = $best ? $best['height'] : 0;

            if ( $best ) {
                $bytes   = (int) filesize( $best['file'] );
                $kb      = round( $bytes / 1024, 1 );
                $ext     = strtolower( pathinfo( $best['file'], PATHINFO_EXTENSION ) );
                $can_fix = in_array( $ext, [ 'jpg', 'jpeg', 'png', 'webp' ], true );
            }

            // Fallback: if best source is still too small for a clean 1200×630 crop,
            // flag it so the UI can suggest AI Generate instead.
            $needs_ai = ( max( $width, $height ) < 1200 || min( $width, $height ) < 630 );

            // Check whether pre-generated social format files exist and are within limits.
            // These files (in /wp-content/uploads/social-formats/) are what actually gets
            // served as og:image — so their size is what matters, not the source image.
            $social_formats    = get_post_meta( $post_id, '_csdt_social_formats', true );
            $has_social_formats = ! empty( $social_formats ) && is_array( $social_formats );

            $platforms = self::check_platform_compat( $width, $height, (float) ( $kb ?? 0 ), $https, $has_social_formats, $social_formats ?: [] );

            // Derive overall status from worst platform result.
            $status = 'pass';
            foreach ( $platforms as $pc ) {
                if ( $pc['status'] === 'fail' )      { $status = 'fail'; break; }
                if ( $pc['status'] === 'warn' )       { $status = 'warn'; }
            }

            $results[] = [
                'post_id'            => $post_id,
                'title'              => get_the_title( $post_id ),
                'post_url'           => $post_url,
                'img_url'            => $img_url,
                'attach_id'          => $attach_id,
                'width'              => $width,
                'height'             => $height,
                'size_kb'            => $kb,
                'status'             => $status,
                'no_image'           => false,
                'platforms'          => $platforms,
                'can_fix'            => $can_fix,
                'has_social_formats' => $has_social_formats,
                'needs_ai'           => $needs_ai,
                'view_count'         => $view_count,
                'comment_count'      => $comment_count,
            ];
        }

        // Re-sort by popularity (views → comments as tiebreaker) for top/broken_top modes.
        if ( $needs_popularity ) {
            usort( $results, function ( $a, $b ) {
                $av = $a['view_count'] ?? 0;
                $bv = $b['view_count'] ?? 0;
                if ( $bv !== $av ) { return $bv - $av; }
                return ( $b['comment_count'] ?? 0 ) - ( $a['comment_count'] ?? 0 );
            } );
        }

        // broken_top: keep only posts with issues, then trim to 50.
        if ( $mode === 'broken_top' ) {
            $results = array_values( array_filter( $results, fn( $r ) => $r['status'] !== 'pass' ) );
        }

        // Trim to 50 after sorting/filtering.
        if ( $needs_popularity ) {
            $results = array_slice( $results, 0, 50 );
        }

        $counts    = array_count_values( array_column( $results, 'status' ) );
        $sort_note = '';
        if ( $needs_popularity ) {
            $sort_note = $view_meta_found
                ? sprintf( __( 'sorted by view count (%s)', 'cloudscale-devtools' ), $view_meta_found )
                : __( 'sorted by comment count (no view-count plugin detected)', 'cloudscale-devtools' );
        }
        wp_send_json_success( [
            'total_scanned' => count( $results ),
            'pass'          => $counts['pass'] ?? 0,
            'warn'          => $counts['warn'] ?? 0,
            'fail'          => $counts['fail'] ?? 0,
            'mode'          => $mode,
            'sort_note'     => $sort_note,
            'posts'         => $results,
        ] );
    }

    // ─── AJAX: recompress an oversized image ─────────────────────────────

    public static function ajax_social_fix_image(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $attachment_id = absint( $_POST['attachment_id'] ?? 0 );
        if ( ! $attachment_id ) {
            wp_send_json_error( [ 'message' => 'No attachment ID.' ] );
        }
        $result = self::social_recompress_image( $attachment_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( $result );
    }

    // ─── AJAX: save platform settings ────────────────────────────────────

    public static function ajax_social_platform_save(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $raw      = isset( $_POST['platforms'] ) ? (array) $_POST['platforms'] : [];
        $allowed  = array_keys( self::SOCIAL_PLATFORMS );
        $filtered = array_values( array_intersect( $raw, $allowed ) );
        update_option( 'csdt_devtools_social_platforms', $filtered );
        wp_send_json_success( [ 'saved' => $filtered ] );
    }

    /**
     * Find the largest available image file for an attachment.
     *
     * WordPress may have generated several intermediate sizes from the original upload.
     * get_attached_file() returns whichever file WordPress considers "full" — but if the
     * original upload was later replaced or the full size was downsized, an intermediate
     * size (e.g. "large" at 1024px) may actually be bigger than what get_attached_file()
     * returns.  This helper walks all registered sizes + the full file and returns the
     * path and dimensions of the largest one by pixel count.
     *
     * @return array{file:string, width:int, height:int}|null
     */
    private static function get_best_source_file( int $attach_id ): ?array {
        $full_file = get_attached_file( $attach_id );
        $meta      = wp_get_attachment_metadata( $attach_id );
        if ( ! $meta ) return $full_file && file_exists( $full_file ) ? [ 'file' => $full_file, 'width' => 0, 'height' => 0 ] : null;

        $upload_dir  = wp_upload_dir();
        $base        = trailingslashit( $upload_dir['basedir'] );
        $subdir      = isset( $meta['file'] ) ? trailingslashit( dirname( $meta['file'] ) ) : '';

        $best_file   = $full_file;
        $best_pixels = (int) ( $meta['width'] ?? 0 ) * (int) ( $meta['height'] ?? 0 );
        $best_w      = (int) ( $meta['width']  ?? 0 );
        $best_h      = (int) ( $meta['height'] ?? 0 );

        foreach ( $meta['sizes'] ?? [] as $size_data ) {
            $path   = $base . $subdir . $size_data['file'];
            $pixels = (int) $size_data['width'] * (int) $size_data['height'];
            if ( $pixels > $best_pixels && file_exists( $path ) ) {
                $best_pixels = $pixels;
                $best_file   = $path;
                $best_w      = (int) $size_data['width'];
                $best_h      = (int) $size_data['height'];
            }
        }

        if ( ! $best_file || ! file_exists( $best_file ) ) return null;
        return [ 'file' => $best_file, 'width' => $best_w, 'height' => $best_h ];
    }

    // ─── Shared: generate per-platform social format images ─────────────

    private static function generate_social_formats_for_post( int $post_id ): ?array {
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( ! $thumb_id ) return null;

        $best = self::get_best_source_file( (int) $thumb_id );
        if ( ! $best ) return null;
        $source_file = $best['file'];

        $enabled = get_option( 'csdt_devtools_social_platforms', array_keys( self::SOCIAL_PLATFORMS ) );
        if ( empty( $enabled ) ) return null;

        $upload   = wp_upload_dir();
        $dest_dir = trailingslashit( $upload['basedir'] ) . 'social-formats/' . $post_id;
        $dest_url = trailingslashit( $upload['baseurl'] ) . 'social-formats/' . $post_id;
        wp_mkdir_p( $dest_dir );

        $ext = strtolower( pathinfo( $source_file, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, [ 'jpg', 'jpeg', 'png', 'webp' ], true ) ) {
            $ext = 'jpg';
        }
        // Convert PNG/WebP to JPEG so lossy quality reduction can actually shrink the file.
        if ( in_array( $ext, [ 'png', 'webp' ], true ) ) {
            $ext = 'jpg';
        }

        $results = [];

        foreach ( self::SOCIAL_PLATFORMS as $key => $platform ) {
            if ( ! in_array( $key, $enabled, true ) ) continue;

            $filename = "{$dest_dir}/{$key}.{$ext}";
            $file_url = "{$dest_url}/{$key}.{$ext}";
            $quality  = 90;

            $actual_w = $platform['w'];
            $actual_h = $platform['h'];

            for ( $attempt = 0; $attempt < 4; $attempt++ ) {
                $editor = wp_get_image_editor( $source_file );
                if ( is_wp_error( $editor ) ) {
                    $results[ $key ] = [ 'success' => false, 'label' => $platform['label'], 'error' => $editor->get_error_message() ];
                    continue 2;
                }
                // Scale to fit within target dimensions — no crop, no content lost.
                $editor->resize( $platform['w'], $platform['h'], false );
                $editor->set_quality( $quality );
                $saved = $editor->save( $filename );
                if ( is_wp_error( $saved ) ) {
                    $results[ $key ] = [ 'success' => false, 'label' => $platform['label'], 'error' => $saved->get_error_message() ];
                    continue 2;
                }
                $actual_w = (int) ( $saved['width']  ?? $platform['w'] );
                $actual_h = (int) ( $saved['height'] ?? $platform['h'] );
                $kb = round( (int) filesize( $filename ) / 1024, 1 );
                if ( $kb <= $platform['target_kb'] || $quality <= 55 ) break;
                $quality -= 10;
            }

            $kb          = round( (int) filesize( $filename ) / 1024, 1 );
            $under_limit = $kb <= $platform['target_kb'];

            $results[ $key ] = [
                'success'     => true,
                'label'       => $platform['label'],
                'w'           => $actual_w,
                'h'           => $actual_h,
                'kb'          => $kb,
                'max_kb'      => $platform['max_kb'],
                'under_limit' => $under_limit,
                'url'         => $file_url,
                'preview_url' => $file_url . '?v=' . time(),
            ];
        }

        update_post_meta( $post_id, '_csdt_social_formats', $results );
        return $results;
    }

    // ─── Hook: auto-generate when _thumbnail_id meta is set/changed ─────
    // Gutenberg and the classic editor both call set_post_thumbnail() AFTER
    // save_post fires, so on_post_saved() sees no thumbnail on the first
    // "publish then add image" workflow. This hook catches the meta write.

    public static function on_thumbnail_meta_updated( $meta_id_or_action, int $post_id, string $meta_key, $meta_value ): void {
        if ( $meta_key !== '_thumbnail_id' ) return;
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'post' || $post->post_status !== 'publish' ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

        // Both added_post_meta and updated_post_meta pass the NEW value as the 4th arg.
        $thumb_id = (int) $meta_value;
        if ( ! $thumb_id ) return;

        $last_thumb = (int) get_post_meta( $post_id, '_csdt_social_formats_thumb_id', true );
        if ( $last_thumb === $thumb_id ) return; // already generated for this thumb

        $results = self::generate_social_formats_for_post( $post_id );
        if ( $results === null ) return;

        update_post_meta( $post_id, '_csdt_social_formats_thumb_id', $thumb_id );
        update_post_meta( $post_id, '_csdt_social_formats_gen_time', time() );

        $post_url = get_permalink( $post_id );
        if ( $post_url ) {
            self::cf_purge_urls( [ $post_url ] );
        }

        $user_id = get_current_user_id();
        set_transient( "cs_sfmt_{$user_id}_{$post_id}", $results, 120 );
    }

    // ─── Hook: auto-generate on post publish / update ────────────────────

    public static function on_post_saved( int $post_id, \WP_Post $post, bool $update ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) )                 return;
        if ( $post->post_status !== 'publish' )                return;

        $thumb_id = (int) get_post_thumbnail_id( $post_id );
        if ( ! $thumb_id ) return;

        // Skip if the thumbnail ID and file are both unchanged since last generation.
        $last_thumb    = (int) get_post_meta( $post_id, '_csdt_social_formats_thumb_id', true );
        $last_gen_time = (int) get_post_meta( $post_id, '_csdt_social_formats_gen_time', true );
        $thumb_post    = get_post( $thumb_id );
        $thumb_mtime   = $thumb_post ? strtotime( $thumb_post->post_modified_gmt ) : 0;
        if ( $last_thumb === $thumb_id && $last_gen_time >= $thumb_mtime ) return;

        $results = self::generate_social_formats_for_post( $post_id );
        if ( $results === null ) return;

        update_post_meta( $post_id, '_csdt_social_formats_thumb_id', $thumb_id );
        update_post_meta( $post_id, '_csdt_social_formats_gen_time', time() );

        // Purge Cloudflare cache for the post URL so crawlers get fresh og:image.
        $post_url = get_permalink( $post_id );
        if ( $post_url ) {
            self::cf_purge_urls( [ $post_url ] );
        }

        // Store for admin notice on next page load.
        $user_id = get_current_user_id();
        set_transient( "cs_sfmt_{$user_id}_{$post_id}", $results, 120 );
    }

    // ─── Shared: purge one or more URLs from Cloudflare cache ───────────

    private static function cf_purge_urls( array $urls ): bool {
        $zone_id = (string) get_option( 'csdt_devtools_cf_zone_id', '' );
        if ( ! $zone_id || empty( $urls ) ) return false;

        $token    = (string) get_option( 'csdt_devtools_cf_api_token', '' );
        $cf_key   = (string) get_option( 'cloudflare_api_key', '' );
        $cf_email = (string) get_option( 'cloudflare_api_email', '' );
        if ( ! $token && ( ! $cf_key || ! $cf_email ) ) return false;

        $headers = [ 'Content-Type' => 'application/json' ];
        if ( $token ) {
            $headers['Authorization'] = 'Bearer ' . $token;
        } else {
            $headers['X-Auth-Key']   = $cf_key;
            $headers['X-Auth-Email'] = $cf_email;
        }

        $response = wp_remote_post(
            "https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache",
            [
                'timeout' => 8,
                'headers' => $headers,
                'body'    => wp_json_encode( [ 'files' => array_values( $urls ) ] ),
            ]
        );

        if ( is_wp_error( $response ) ) return false;
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return ! empty( $data['success'] );
    }

    // ─── Hook: Cloudflare cache purge on post publish/update ────────────
    public static function on_post_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
        if ( $new_status !== 'publish' ) return;
        if ( ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) return;
        self::cf_purge_urls( array_filter( [ get_permalink( $post->ID ), home_url( '/' ) ] ) );
    }

    // ─── Admin notice: shown after auto-generation ───────────────────────

    public static function social_format_admin_notice(): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->base !== 'post' ) return;

        $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
        if ( ! $post_id ) return;

        $user_id = get_current_user_id();
        $results = get_transient( "cs_sfmt_{$user_id}_{$post_id}" );
        if ( ! $results ) return;
        delete_transient( "cs_sfmt_{$user_id}_{$post_id}" );

        $ok_labels = [];
        $fail_labels = [];
        foreach ( $results as $r ) {
            if ( ! empty( $r['success'] ) ) {
                $size_note = $r['under_limit'] ? '' : ' ⚠';
                $ok_labels[] = $r['label'] . ' (' . $r['w'] . '×' . $r['h'] . ', ' . $r['kb'] . ' KB' . $size_note . ')';
            } else {
                $fail_labels[] = $r['label'];
            }
        }
        if ( empty( $ok_labels ) ) return;

        echo '<div class="notice notice-success is-dismissible" style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px">';
        echo '<span style="font-size:20px;line-height:1.4">🎨</span>';
        echo '<div><strong>' . esc_html__( 'Social format images generated automatically', 'cloudscale-devtools' ) . '</strong><br>';
        echo '<span style="font-size:12px;color:#50575e">' . esc_html( implode( ' &nbsp;·&nbsp; ', $ok_labels ) ) . '</span>';
        if ( ! empty( $fail_labels ) ) {
            echo '<br><span style="font-size:12px;color:#8c2020">✘ Failed: ' . esc_html( implode( ', ', $fail_labels ) ) . '</span>';
        }
        echo '</div></div>';
    }

    // ─── AJAX: generate per-platform social formats ───────────────────────

    public static function ajax_social_generate_formats(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'No post ID.' ] );
        }
        if ( ! get_post_thumbnail_id( $post_id ) ) {
            wp_send_json_error( [ 'message' => 'No featured image set for this post.' ] );
        }
        $results = self::generate_social_formats_for_post( $post_id );
        if ( $results === null ) {
            wp_send_json_error( [ 'message' => 'Could not generate formats — check the featured image file and platform settings.' ] );
        }
        // Mark as up-to-date so the save hook won't re-run for this thumbnail.
        update_post_meta( $post_id, '_csdt_social_formats_thumb_id', (int) get_post_thumbnail_id( $post_id ) );

        // Purge Cloudflare cache so WhatsApp/social crawlers get the fresh og:image immediately.
        $post_url  = get_permalink( $post_id );
        $cf_purged = $post_url ? self::cf_purge_urls( [ $post_url ] ) : false;

        wp_send_json_success( array_merge( $results, [ 'cf_purged' => $cf_purged ] ) );
    }

    // ─── AJAX: diagnose social formats for a post ────────────────────────
    // Checks: (1) what's stored in meta, (2) whether image files exist on disk,
    // (3) whether image URLs are reachable by each crawler UA.

    public static function ajax_social_diagnose_formats(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'No post ID.' ] );
        }

        $result = [];

        // ── 1. Meta state ────────────────────────────────────────────────
        $formats   = get_post_meta( $post_id, '_csdt_social_formats', true );
        $old_formats = get_post_meta( $post_id, '_cs_social_formats', true );
        $thumb_id  = (int) get_post_meta( $post_id, '_csdt_social_formats_thumb_id', true );
        $current_thumb = (int) get_post_thumbnail_id( $post_id );

        $result['meta'] = [
            'has_new_key'    => ! empty( $formats ),
            'has_old_key'    => ! empty( $old_formats ),
            'thumb_id_saved' => $thumb_id,
            'thumb_id_now'   => $current_thumb,
            'thumb_stale'    => $thumb_id !== $current_thumb,
            'no_thumbnail'   => ! $current_thumb,
        ];

        if ( empty( $formats ) && ! empty( $old_formats ) ) {
            $formats = $old_formats;
            $result['meta']['using_old_key'] = true;
        }

        // ── 2. Per-platform: file existence + URL reachability ───────────
        $upload   = wp_upload_dir();
        $dest_dir = trailingslashit( $upload['basedir'] ) . 'social-formats/' . $post_id;

        $test_uas = [
            'LinkedInBot' => 'LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)',
            'Facebook'    => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
            'Twitterbot'  => 'Twitterbot/1.0',
        ];

        $platforms_out = [];
        foreach ( self::SOCIAL_PLATFORMS as $key => $p ) {
            $meta_entry = $formats[ $key ] ?? null;
            $entry = [
                'label'       => $p['label'],
                'meta_status' => 'missing',
                'url'         => null,
                'file_exists' => false,
                'ua_results'  => [],
            ];

            if ( $meta_entry !== null ) {
                if ( ! empty( $meta_entry['success'] ) ) {
                    $entry['meta_status'] = 'ok';
                    $entry['url']         = $meta_entry['url'] ?? null;
                    $entry['kb']          = $meta_entry['kb'] ?? null;
                    $entry['w']           = $meta_entry['w'] ?? null;
                    $entry['h']           = $meta_entry['h'] ?? null;
                } else {
                    $entry['meta_status'] = 'failed';
                    $entry['error']       = $meta_entry['error'] ?? 'Unknown error';
                }
            }

            // Check file on disk (try .jpg and .png).
            foreach ( [ 'jpg', 'png', 'webp' ] as $ext ) {
                $path = "{$dest_dir}/{$key}.{$ext}";
                if ( file_exists( $path ) ) {
                    $entry['file_exists'] = true;
                    $entry['file_path']   = $path;
                    $entry['file_kb']     = round( filesize( $path ) / 1024, 1 );
                    break;
                }
            }

            // Test URL reachability with crawler UAs (only if a URL is stored).
            if ( ! empty( $entry['url'] ) ) {
                foreach ( $test_uas as $ua_label => $ua_string ) {
                    $resp = wp_remote_head( $entry['url'], [
                        'user-agent'  => $ua_string,
                        'timeout'     => 8,
                        'redirection' => 3,
                    ] );
                    if ( is_wp_error( $resp ) ) {
                        $entry['ua_results'][ $ua_label ] = [ 'code' => 0, 'ok' => false, 'error' => $resp->get_error_message() ];
                    } else {
                        $code = (int) wp_remote_retrieve_response_code( $resp );
                        $entry['ua_results'][ $ua_label ] = [ 'code' => $code, 'ok' => $code === 200 ];
                    }
                }
            }

            $platforms_out[ $key ] = $entry;
        }

        $result['platforms'] = $platforms_out;

        // ── 3. What og:image would each crawler see ──────────────────────
        $post_url = get_permalink( $post_id );
        $og_seen = [];
        foreach ( $test_uas as $ua_label => $ua_string ) {
            $resp = wp_remote_get( $post_url, [
                'user-agent'  => $ua_string,
                'timeout'     => 10,
                'redirection' => 5,
            ] );
            if ( is_wp_error( $resp ) ) {
                $og_seen[ $ua_label ] = [ 'ok' => false, 'error' => $resp->get_error_message() ];
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code( $resp );
            $body = wp_remote_retrieve_body( $resp );
            preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']|<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/', $body, $m );
            $og_url = $m[1] ?? $m[2] ?? null;
            $og_seen[ $ua_label ] = [
                'ok'     => $code === 200,
                'code'   => $code,
                'og_url' => $og_url,
                'has_og' => ! empty( $og_url ),
            ];
        }
        $result['og_seen'] = $og_seen;

        wp_send_json_success( $result );
    }

    // ─── AJAX: batch fix all posts ────────────────────────────────────────

    public static function ajax_social_fix_all_batch(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $offset     = absint( $_POST['offset']     ?? 0 );
        $batch_size = 10; // process 10 per request to avoid timeouts

        $total = (int) wp_count_posts( 'post' )->publish;

        $posts = get_posts( [
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'posts_per_page'   => $batch_size,
            'offset'           => $offset,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'fields'           => 'ids',
            'suppress_filters' => false,
        ] );

        $batch_results = [];
        foreach ( $posts as $post_id ) {
            $thumb_id = get_post_thumbnail_id( $post_id );
            if ( ! $thumb_id ) {
                $batch_results[] = [ 'post_id' => $post_id, 'skipped' => true, 'reason' => 'no_thumbnail' ];
                continue;
            }
            $file = get_attached_file( (int) $thumb_id );
            if ( ! $file || ! file_exists( $file ) ) {
                $batch_results[] = [ 'post_id' => $post_id, 'skipped' => true, 'reason' => 'file_missing' ];
                continue;
            }
            $results = self::generate_social_formats_for_post( $post_id );
            if ( $results ) {
                update_post_meta( $post_id, '_csdt_social_formats_thumb_id', (int) $thumb_id );
            }
            $batch_results[] = [ 'post_id' => $post_id, 'skipped' => false, 'ok' => $results !== null ];
        }

        $next_offset = $offset + count( $posts );
        wp_send_json_success( [
            'total'        => $total,
            'offset'       => $offset,
            'next_offset'  => $next_offset,
            'has_more'     => $next_offset < $total,
            'batch'        => $batch_results,
        ] );
    }

    public static function ajax_social_refresh_stale_batch(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $offset     = absint( $_POST['offset'] ?? 0 );
        $batch_size = 10;

        $total = (int) wp_count_posts( 'post' )->publish;

        $posts = get_posts( [
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'posts_per_page'   => $batch_size,
            'offset'           => $offset,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'fields'           => 'ids',
            'suppress_filters' => false,
        ] );

        $stale_posts = [];
        foreach ( $posts as $post_id ) {
            $thumb_id = (int) get_post_thumbnail_id( $post_id );
            if ( ! $thumb_id ) continue;

            $last_thumb    = (int) get_post_meta( $post_id, '_csdt_social_formats_thumb_id', true );
            $last_gen_time = (int) get_post_meta( $post_id, '_csdt_social_formats_gen_time', true );
            $thumb_post    = get_post( $thumb_id );
            $thumb_mtime   = $thumb_post ? strtotime( $thumb_post->post_modified_gmt ) : 0;

            $is_stale = ( $last_thumb !== $thumb_id ) || ( $last_gen_time < $thumb_mtime );
            if ( ! $is_stale ) continue;

            $results = self::generate_social_formats_for_post( $post_id );
            $ok      = $results !== null;
            if ( $ok ) {
                update_post_meta( $post_id, '_csdt_social_formats_thumb_id', $thumb_id );
                update_post_meta( $post_id, '_csdt_social_formats_gen_time', time() );
            }

            $stale_posts[] = [
                'post_id' => $post_id,
                'title'   => get_the_title( $post_id ),
                'url'     => get_permalink( $post_id ),
                'reason'  => $last_thumb !== $thumb_id ? 'thumb_id_changed' : 'file_replaced',
                'ok'      => $ok,
            ];
        }

        $next_offset = $offset + count( $posts );
        wp_send_json_success( [
            'total'       => $total,
            'next_offset' => $next_offset,
            'has_more'    => $next_offset < $total,
            'checked'     => count( $posts ),
            'stale'       => $stale_posts,
        ] );
    }

    // ─── Always output social og:image early (priority 1) ───────────────
    // UA-based serving breaks CDN caches — Cloudflare caches the first response
    // and all subsequent visitors (including bots) get the same cached HTML.
    // Instead, always output the best available 1200×630 social image for all
    // visitors so the CDN-cached page has the correct og:image embedded.

    public static function output_crawler_og_image(): void {
        if ( ! is_singular( 'post' ) ) return;

        $post_id = get_the_ID();
        if ( ! $post_id ) return;

        $formats = get_post_meta( $post_id, '_csdt_social_formats', true );
        if ( empty( $formats ) ) {
            $formats = get_post_meta( $post_id, '_cs_social_formats', true );
        }
        if ( empty( $formats ) ) return;

        // Pick the best available format: prefer whatsapp (1200×630, ≤200 KB),
        // fall back through other 1200×630 formats.
        $order = [ 'whatsapp', 'facebook', 'linkedin', 'instagram', 'twitter' ];
        $platform = null;
        foreach ( $order as $p ) {
            if ( ! empty( $formats[ $p ]['url'] ) ) {
                $platform = $p;
                break;
            }
        }
        if ( ! $platform ) return;

        $img_url = esc_url( $formats[ $platform ]['url'] );
        $dims    = self::SOCIAL_PLATFORMS[ $platform ];

        // Output before SEO plugin tags (priority 1). First og:image wins for most crawlers.
        echo "\n<!-- CloudScale: social og:image ({$platform}) -->\n";
        echo '<meta property="og:image" content="' . $img_url . '" />' . "\n";
        echo '<meta property="og:image:width" content="' . (int) $dims['w'] . '" />' . "\n";
        echo '<meta property="og:image:height" content="' . (int) $dims['h'] . '" />' . "\n";
    }

    // ─── AJAX: Cloudflare crawler UA test ────────────────────────────────

    public static function ajax_social_cf_test(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : home_url( '/' );
        if ( ! self::is_safe_external_url( $url ) ) {
            wp_send_json_error( [ 'message' => 'URL must be a publicly accessible address.' ] );
        }
        $post_id = url_to_postid( $url );
        if ( $post_id ) {
            $chk = get_post( $post_id );
            if ( $chk && ( $chk->post_status === 'private' || ! empty( $chk->post_password ) ) ) {
                wp_send_json_error( [ 'message' => 'This post is password-protected or private — social crawlers cannot read it. Please enter a public URL.' ] );
            }
        }
        wp_send_json_success( self::social_test_crawlers( $url ) );
    }

    // ─── AJAX: Cloudflare cache purge ────────────────────────────────────

    public static function ajax_cf_purge(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $zone_id = get_option( 'csdt_devtools_cf_zone_id', '' );
        $token   = get_option( 'csdt_devtools_cf_api_token', '' );
        if ( ! $zone_id || ! $token ) {
            wp_send_json_error( [ 'message' => __( 'Cloudflare Zone ID and API Token are required. Please save them above.', 'cloudscale-devtools' ) ] );
        }
        $purge_url = isset( $_POST['purge_url'] ) ? esc_url_raw( wp_unslash( $_POST['purge_url'] ) ) : '';
        // Ensure purge_url belongs to this site — prevents purging arbitrary Cloudflare-cached URLs.
        if ( $purge_url && strpos( $purge_url, home_url() ) !== 0 ) {
            wp_send_json_error( [ 'message' => __( 'URL must belong to this site.', 'cloudscale-devtools' ) ] );
        }
        $cf_api    = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache";
        $body      = $purge_url
            ? wp_json_encode( [ 'files' => [ $purge_url ] ] )
            : wp_json_encode( [ 'purge_everything' => true ] );
        $response = wp_remote_post( $cf_api, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => $body,
            'timeout' => 15,
        ] );
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $data['success'] ) ) {
            wp_send_json_success( [
                'message' => $purge_url
                    ? sprintf( __( 'Cache purged for: %s', 'cloudscale-devtools' ), $purge_url )
                    : __( 'Entire Cloudflare cache purged successfully.', 'cloudscale-devtools' ),
            ] );
        } else {
            $errors = isset( $data['errors'] ) ? wp_json_encode( $data['errors'] ) : __( 'Unknown error', 'cloudscale-devtools' );
            wp_send_json_error( [ 'message' => $errors ] );
        }
    }

    // ─── AJAX: save CF credentials ───────────────────────────────────────

    public static function ajax_cf_save(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $zone_id = isset( $_POST['zone_id'] ) ? sanitize_text_field( wp_unslash( $_POST['zone_id'] ) ) : '';
        $token   = isset( $_POST['api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['api_token'] ) ) : '';
        update_option( 'csdt_devtools_cf_zone_id', $zone_id );
        if ( $token !== '' ) {
            update_option( 'csdt_devtools_cf_api_token', $token );
        }
        wp_send_json_success( [ 'message' => __( 'Cloudflare settings saved.', 'cloudscale-devtools' ) ] );
    }

    // ─── Default Featured Image ───────────────────────────────────────────

    public static function ajax_save_default_image(): void {
        check_ajax_referer( 'csdt_defimg', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }
        $id = absint( $_POST['image_id'] ?? 0 );
        update_option( 'cloudscale_default_image_id', $id );
        wp_send_json_success( [ 'id' => $id ] );
    }

    public static function default_image_html( string $html, int $post_id, $post_thumbnail_id, $size, $attr ): string {
        if ( ! empty( $html ) ) { return $html; }
        if ( get_post_type( $post_id ) !== 'post' ) { return $html; }
        $default_id = (int) get_option( 'cloudscale_default_image_id', 0 );
        if ( ! $default_id ) { return $html; }
        return wp_get_attachment_image( $default_id, $size, false, (array) $attr );
    }

    public static function default_image_has_thumbnail( bool $has, $post, $thumbnail_id ): bool {
        if ( $has ) { return $has; }
        $post_obj = get_post( $post );
        if ( ! $post_obj || $post_obj->post_type !== 'post' ) { return $has; }
        return (int) get_option( 'cloudscale_default_image_id', 0 ) > 0;
    }

    // ─── Hero image: show original image on single posts ─────────────────
    // Previously swapped to the 1200×630 social-format crop, which cut off
    // title text at the top of images. Now returns the unmodified thumbnail HTML
    // so the original uploaded image is displayed at natural dimensions.

    public static function hero_image_html( string $html, int $post_id, $post_thumbnail_id, $size, $attr ): string {
        return $html;
    }

    public static function enqueue_hero_styles(): void {
        if ( ! is_singular( 'post' ) ) { return; }
        wp_register_style( 'csdt-hero-styles', false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        wp_enqueue_style( 'csdt-hero-styles' );
        wp_add_inline_style( 'csdt-hero-styles', '.single .wp-post-image{width:100%;display:block;height:auto}' );
    }

    // ─── Frontend admin: Generate Featured Image button ──────────────────

    public static function enqueue_frontend_admin_scripts(): void {
        if ( ! is_singular( 'post' ) || ! current_user_can( 'manage_options' ) ) { return; }
        wp_enqueue_script(
            'csdt-frontend-admin',
            plugins_url( 'assets/cs-frontend-admin.js', dirname( __DIR__ ) . '/cs-code-block.php' ),
            [],
            CloudScale_DevTools::VERSION,
            true
        );
        wp_localize_script( 'csdt-frontend-admin', 'csdtFrontAdmin', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'csdt_devtools_thumbnails' ),
            'promptVendor'  => (string) get_option( 'csdt_devtools_prompt_vendor', 'openai' ),
            'promptModel'   => (string) get_option( 'csdt_devtools_prompt_model', 'gpt-4o' ),
            'imgStyle'      => (string) get_option( 'csdt_devtools_img_style', 'auto' ),
            'imgQuality'    => (string) get_option( 'csdt_devtools_img_quality', 'standard' ),
            'imgDual'       => get_option( 'csdt_devtools_img_dual', false ) ? '1' : '0',
        ] );
    }

    public static function inject_gen_image_button(): void {
        if ( ! is_singular( 'post' ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $post_id = get_the_ID();
        if ( ! $post_id ) { return; }
        echo '<div id="csdt-gen-bar" class="csdt-gen-bar" data-post-id="' . esc_attr( (string) $post_id ) . '" style="display:none;">'
           . '<button type="button" class="csdt-gen-img-pill">🎨 Generate Featured Image</button>'
           . '</div>' . "\n";
    }

    // ─── Private: full URL diagnostic ────────────────────────────────────

    /**
     * Runs all social-preview checks against a URL and returns a structured
     * result array with sections (title + result items) and summary totals.
     */
    private static function social_diagnose_url( string $url ): array {
        $sections = [];
        $og_image = '';
        $img_kb   = null;
        $img_w    = null;
        $img_h    = null;

        $wa_ua = self::SOCIAL_UAS['WhatsApp'];

        // 1. HTTPS
        $pass = str_starts_with( $url, 'https://' );
        $sections[] = [
            'title'   => 'HTTPS',
            'results' => [ $pass
                ? [ 'type' => 'pass', 'msg' => 'URL uses HTTPS' ]
                : [ 'type' => 'fail', 'msg' => 'URL uses HTTP — WhatsApp requires HTTPS for link previews.', 'fix' => 'Install an SSL certificate (Let\'s Encrypt is free) and update your WordPress Address and Site Address in Settings → General to use https://. Add a redirect rule to force HTTP → HTTPS.' ] ],
        ];

        // 2. HTTP response (WhatsApp UA)
        $head = wp_remote_head( $url, [ 'user-agent' => $wa_ua, 'redirection' => 5, 'timeout' => 12, 'sslverify' => true ] );
        $http_ok = false;
        if ( is_wp_error( $head ) ) {
            $sections[] = [ 'title' => 'HTTP Response', 'results' => [ [ 'type' => 'fail', 'msg' => 'Could not connect: ' . $head->get_error_message() ] ] ];
        } else {
            $code = wp_remote_retrieve_response_code( $head );
            $http_ok = ( $code === 200 );
            $is_redirect = in_array( $code, [ 301, 302, 307, 308 ], true );
            $sections[] = [ 'title' => 'HTTP Response (WhatsApp UA)', 'results' => [ $code === 200
                ? [ 'type' => 'pass', 'msg' => 'HTTP 200 OK' ]
                : [ 'type' => $is_redirect ? 'warn' : 'fail',
                    'msg'  => "HTTP $code — " . ( $is_redirect ? 'Redirect (crawlers follow, but adds latency)' : 'Non-200 response; crawler may not read OG tags' ),
                    'fix'  => $is_redirect
                        ? 'Update og:url and your canonical tag to point directly to the final URL to avoid the redirect chain.'
                        : 'Check your WAF, Cloudflare firewall rules, or bot-protection plugins — they may be blocking the WhatsApp crawler User-Agent. Add facebookexternalhit, WhatsApp, Twitterbot, and LinkedInBot to your allowlist.' ] ] ];
        }

        // 3. Fetch HTML + measure response time
        $start = microtime( true );
        $resp  = wp_remote_get( $url, [ 'user-agent' => $wa_ua, 'redirection' => 5, 'timeout' => 18 ] );
        $elapsed = round( microtime( true ) - $start, 2 );
        $html = is_wp_error( $resp ) ? '' : wp_remote_retrieve_body( $resp );

        $sections[] = [ 'title' => 'Response Time', 'results' => [ $elapsed < 3.0
            ? [ 'type' => 'pass', 'msg' => "{$elapsed}s — within 3s crawler timeout" ]
            : ( $elapsed < 5.0
                ? [ 'type' => 'warn', 'msg' => "{$elapsed}s — approaching WhatsApp 3–5s timeout", 'fix' => 'Enable a page caching plugin (e.g. WP Super Cache or W3 Total Cache) and enable Cloudflare\'s HTML caching. Crawlers hit cold pages — caching ensures they get a fast response every time.' ]
                : [ 'type' => 'fail', 'msg' => "{$elapsed}s — exceeds 5s; crawler will likely abort before reading OG tags", 'fix' => 'Enable full-page caching immediately. Check for slow database queries using the CS Monitor DB tab, deactivate heavy plugins, and enable Cloudflare in front of the origin server.' ] ) ] ];

        // 4. OG tags
        $og_results = [];
        $og_fixes = [
            'og:title'       => 'Add <meta property="og:title" content="Your Page Title"> to the <head>. Use an SEO plugin like Yoast or Rank Math — they generate this automatically from your post title.',
            'og:description' => 'Add <meta property="og:description" content="Your description (max ~200 chars)">. Most SEO plugins set this from the meta description field on each post/page.',
            'og:image'       => 'Add <meta property="og:image" content="https://yoursite.com/image.jpg">. Use a 1200×630px JPEG/PNG under 300 KB. Set a site-wide fallback in your SEO plugin settings.',
            'og:url'         => 'Add <meta property="og:url" content="https://yoursite.com/this-page/"> using the canonical URL of this page.',
            'og:type'        => 'Add <meta property="og:type" content="website"> (or "article" for blog posts). Most SEO plugins set this automatically.',
        ];
        foreach ( [ 'og:title', 'og:description', 'og:image', 'og:url', 'og:type' ] as $prop ) {
            $val = self::social_extract_property( $html, $prop );
            $og_results[] = $val
                ? [ 'type' => 'pass', 'msg' => "$prop: " . mb_substr( $val, 0, 80 ) ]
                : [ 'type' => 'fail', 'msg' => "$prop is missing", 'fix' => $og_fixes[ $prop ] ?? '' ];
        }
        foreach ( [ 'twitter:card', 'twitter:image' ] as $name ) {
            $val = self::social_extract_name( $html, $name );
            $og_results[] = $val
                ? [ 'type' => 'pass', 'msg' => "$name: " . mb_substr( $val, 0, 80 ) ]
                : [ 'type' => 'warn', 'msg' => "$name missing — X/Twitter may not render large card",
                    'fix'  => $name === 'twitter:card'
                        ? 'Add <meta name="twitter:card" content="summary_large_image"> to show the full-width image card on X/Twitter. Most SEO plugins have a Twitter Card setting.'
                        : 'Add <meta name="twitter:image" content="https://yoursite.com/image.jpg">. X/Twitter uses this over og:image if present.' ];
        }
        $sections[] = [ 'title' => 'Open Graph Tags', 'results' => $og_results ];

        // 5. og:image analysis
        $og_image = self::social_extract_property( $html, 'og:image' );
        $img_results = [];
        if ( ! $og_image ) {
            $img_results[] = [ 'type' => 'fail', 'msg' => 'og:image is missing — cannot analyse image.', 'fix' => 'Set a featured image on this post/page and ensure your SEO plugin is configured to use it as og:image. Add a site-wide fallback image in your SEO plugin settings.' ];
        } else {
            $img_head = wp_remote_head( $og_image, [ 'user-agent' => $wa_ua, 'timeout' => 10, 'redirection' => 3 ] );
            if ( is_wp_error( $img_head ) ) {
                $img_results[] = [ 'type' => 'fail', 'msg' => 'og:image URL unreachable: ' . $img_head->get_error_message(), 'fix' => 'Verify the image URL is publicly accessible. Check that the file exists in your Media Library and that no security plugin or Cloudflare rule is blocking direct image access.' ];
            } else {
                $img_code = wp_remote_retrieve_response_code( $img_head );
                $img_results[] = $img_code === 200
                    ? [ 'type' => 'pass', 'msg' => 'og:image URL returns HTTP 200' ]
                    : [ 'type' => 'fail', 'msg' => "og:image URL returns HTTP $img_code — image inaccessible", 'fix' => "The image file returned HTTP $img_code. Re-upload the image to your Media Library, update the og:image URL to the new path, and confirm the file is publicly readable (check file permissions and any WAF rules blocking image requests)." ];
                $ct = wp_remote_retrieve_header( $img_head, 'content-type' );
                $img_results[] = str_contains( (string) $ct, 'image/' )
                    ? [ 'type' => 'pass', 'msg' => "Content-Type: $ct" ]
                    : [ 'type' => 'warn', 'msg' => "Unexpected Content-Type: '$ct'", 'fix' => "The URL is not serving an image file. Verify the og:image URL points directly to a JPEG, PNG, or WebP file (not a page or redirect). If using a CDN, check that it is not transforming the response Content-Type." ];
            }
            $img_results[] = str_starts_with( $og_image, 'https://' )
                ? [ 'type' => 'pass', 'msg' => 'og:image uses HTTPS' ]
                : [ 'type' => 'fail', 'msg' => 'og:image uses HTTP — WhatsApp requires HTTPS images', 'fix' => 'Update the og:image URL to use https://. If your site has SSL, the image URL should automatically use HTTPS — check your SEO plugin settings or the post\'s custom OG image field.' ];

            $img_resp = wp_remote_get( $og_image, [ 'user-agent' => $wa_ua, 'timeout' => 20, 'redirection' => 3 ] );
            if ( ! is_wp_error( $img_resp ) ) {
                $img_body = wp_remote_retrieve_body( $img_resp );
                $img_bytes = strlen( $img_body );
                $img_kb    = round( $img_bytes / 1024, 1 );
                if ( $img_bytes > 307200 ) {
                    $img_results[] = [ 'type' => 'fail', 'msg' => "Image is {$img_kb} KB — exceeds WhatsApp's 300 KB silent-failure threshold. Compress to under 250 KB.", 'fix' => "Use the Media Library Audit below to recompress this image, or use squoosh.app / TinyPNG to manually compress it to under 250 KB. Then re-upload and update the og:image URL." ];
                } elseif ( $img_bytes > 204800 ) {
                    $img_results[] = [ 'type' => 'warn', 'msg' => "Image is {$img_kb} KB — approaching 300 KB WhatsApp limit. Consider optimising.", 'fix' => "Compress the image to under 200 KB using TinyPNG or the Media Library Audit recompress tool below. JPEG at 80% quality typically achieves good compression without visible quality loss." ];
                } else {
                    $img_results[] = [ 'type' => 'pass', 'msg' => "Image is {$img_kb} KB — within the 300 KB WhatsApp limit." ];
                }
                if ( function_exists( 'imagecreatefromstring' ) ) {
                    $res = @imagecreatefromstring( $img_body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                    if ( $res ) {
                        $img_w = imagesx( $res );
                        $img_h = imagesy( $res );
                        imagedestroy( $res );
                        if ( $img_w >= 1200 && $img_h >= 630 ) {
                            $img_results[] = [ 'type' => 'pass', 'msg' => "Dimensions: {$img_w}×{$img_h}px — meets 1200×630 minimum" ];
                        } elseif ( $img_w >= 600 ) {
                            $img_results[] = [ 'type' => 'warn', 'msg' => "Dimensions: {$img_w}×{$img_h}px — below recommended 1200×630", 'fix' => "Resize or recreate the image at 1200×630px. This is the optimal size for Facebook, LinkedIn, and WhatsApp. Use Canva or your image editor to export at exactly 1200×630px." ];
                        } else {
                            $img_results[] = [ 'type' => 'fail', 'msg' => "Dimensions: {$img_w}×{$img_h}px — too small for reliable social previews", 'fix' => "Replace this image with one at least 1200×630px. Images smaller than 600px wide are often ignored by social crawlers entirely. Create a new featured image at 1200×630px." ];
                        }
                    }
                }
            }
        }
        $sections[] = [ 'title' => 'og:image Analysis', 'results' => $img_results ];

        // 6. robots.txt
        $base_url    = preg_replace( '#(https?://[^/]+).*#', '$1', $url );
        $robots_resp = wp_remote_get( "$base_url/robots.txt", [ 'timeout' => 8 ] );
        $rb_results  = [];
        if ( is_wp_error( $robots_resp ) || wp_remote_retrieve_response_code( $robots_resp ) !== 200 ) {
            $rb_results[] = [ 'type' => 'warn', 'msg' => 'robots.txt not found — ensure crawlers are not blocked elsewhere', 'fix' => 'Create a robots.txt at your domain root. In WordPress, go to Settings → Reading and ensure "Discourage search engines" is unchecked. CloudScale SEO AI auto-generates robots.txt — enable it if available.' ];
        } else {
            $rb_body = wp_remote_retrieve_body( $robots_resp );
            foreach ( [ 'facebookexternalhit', 'WhatsApp', 'Facebot', 'LinkedInBot', 'Twitterbot' ] as $bot ) {
                if ( preg_match( '/User-agent:\s*' . preg_quote( $bot, '/' ) . '.*?Disallow:\s*\//si', $rb_body ) ) {
                    $rb_results[] = [ 'type' => 'fail', 'msg' => "robots.txt blocks $bot — this prevents all previews from that platform", 'fix' => "Remove the Disallow rule for $bot from your robots.txt. Add \"User-agent: $bot\\nDisallow:\" (empty Disallow = allow all) to explicitly permit this crawler. Edit robots.txt via your SEO plugin or directly in the site root." ];
                } else {
                    $rb_results[] = [ 'type' => 'pass', 'msg' => "robots.txt does not block $bot" ];
                }
            }
        }
        $sections[] = [ 'title' => 'robots.txt', 'results' => $rb_results ];

        // 7. Cloudflare detection
        $cf_results = [];
        $cf_ray = wp_remote_retrieve_header( is_wp_error( $head ) ? [] : $head, 'cf-ray' );
        if ( $cf_ray ) {
            $cf_cache = wp_remote_retrieve_header( is_wp_error( $head ) ? [] : $head, 'cf-cache-status' );
            $cf_results[] = [ 'type' => 'pass', 'msg' => "Cloudflare active: cf-ray $cf_ray" . ( $cf_cache ? " | Cache: $cf_cache" : '' ) ];
            $cf_results[] = [ 'type' => 'info', 'msg' => 'If any crawler UA test failed, set up a WAF Skip rule in Cloudflare for social crawler user agents — see the Cloudflare Setup panel.' ];
        } else {
            $cf_results[] = [ 'type' => 'pass', 'msg' => 'No Cloudflare detected — WAF skip rule not required' ];
        }
        $sections[] = [ 'title' => 'Cloudflare', 'results' => $cf_results ];

        // Totals
        $pass = $warn = $fail = 0;
        foreach ( $sections as $s ) {
            foreach ( $s['results'] as $r ) {
                match ( $r['type'] ) { 'pass' => $pass++, 'warn' => $warn++, 'fail' => $fail++, default => null };
            }
        }

        return [
            'url'      => $url,
            'sections' => $sections,
            'totals'   => [ 'pass' => $pass, 'warn' => $warn, 'fail' => $fail ],
            'og_image' => $og_image,
            'img_kb'   => $img_kb,
            'img_w'    => $img_w,
            'img_h'    => $img_h,
        ];
    }

    /** Runs the five social crawler UA tests against a URL — used by the CF test button. */
    private static function social_test_crawlers( string $url ): array {
        $results = [];
        foreach ( self::SOCIAL_UAS as $label => $ua ) {
            $resp = wp_remote_get( $url, [ 'user-agent' => $ua, 'redirection' => 5, 'timeout' => 15 ] );
            if ( is_wp_error( $resp ) ) {
                $results[ $label ] = [ 'type' => 'fail', 'code' => 0, 'og' => false, 'msg' => $resp->get_error_message() ];
                continue;
            }
            $code = wp_remote_retrieve_response_code( $resp );
            $body = wp_remote_retrieve_body( $resp );
            $has_og = (bool) preg_match( '/property=["\']og:image["\']/', $body );
            $challenged = str_contains( $body, 'challenge-platform' );
            if ( $code === 200 && $has_og ) {
                $results[ $label ] = [ 'type' => 'pass', 'code' => $code, 'og' => true, 'msg' => $challenged ? 'HTTP 200, og:image present (Cloudflare challenge script detected — WAF skip rule is working)' : 'HTTP 200, og:image present' ];
            } elseif ( $code === 200 && ! $has_og ) {
                $results[ $label ] = [ 'type' => 'fail', 'code' => $code, 'og' => false, 'msg' => $challenged ? 'HTTP 200 but og:image absent — Bot Fight Mode is blocking this crawler. WAF skip rule needed.' : 'HTTP 200 but og:image absent in response' ];
            } else {
                $results[ $label ] = [ 'type' => 'fail', 'code' => $code, 'og' => false, 'msg' => "HTTP $code — crawler is being blocked" ];
            }
        }
        return $results;
    }

    /** Helper: extract og:meta property content. */
    private static function social_extract_property( string $html, string $prop ): string {
        if ( preg_match( '/property=["\']' . preg_quote( $prop, '/' ) . '["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m ) ) {
            return trim( $m[1] );
        }
        if ( preg_match( '/content=["\']([^"\']+)["\'][^>]+property=["\']' . preg_quote( $prop, '/' ) . '["\']/', $html, $m ) ) {
            return trim( $m[1] );
        }
        return '';
    }

    /** Helper: extract meta name content. */
    private static function social_extract_name( string $html, string $name ): string {
        if ( preg_match( '/name=["\']' . preg_quote( $name, '/' ) . '["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m ) ) {
            return trim( $m[1] );
        }
        if ( preg_match( '/content=["\']([^"\']+)["\'][^>]+name=["\']' . preg_quote( $name, '/' ) . '["\']/', $html, $m ) ) {
            return trim( $m[1] );
        }
        return '';
    }

    /**
     * Helper: recompress an attachment to under 300 KB using WP_Image_Editor.
     *
     * @return array|\WP_Error
     */
    private static function social_recompress_image( int $attachment_id ) {
        $file_path = get_attached_file( $attachment_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return new \WP_Error( 'not_found', __( 'Attachment file not found on disk.', 'cloudscale-devtools' ) );
        }
        $ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, [ 'jpg', 'jpeg', 'png' ], true ) ) {
            return new \WP_Error( 'unsupported', __( 'Only JPEG and PNG images can be recompressed.', 'cloudscale-devtools' ) );
        }
        // Backup the original.
        $backup = $file_path . '.cs-backup';
        if ( ! copy( $file_path, $backup ) ) {
            return new \WP_Error( 'backup_failed', __( 'Could not create backup — aborting to protect original.', 'cloudscale-devtools' ) );
        }
        $editor = wp_get_image_editor( $file_path );
        if ( is_wp_error( $editor ) ) {
            wp_delete_file( $backup );
            return $editor;
        }
        // Always convert to JPEG — saves ~60–80% vs PNG for photos.
        $jpeg_path = preg_replace( '/\.(png|webp|bmp|gif)$/i', '.jpg', $file_path );
        $save_path = $jpeg_path !== $file_path ? $jpeg_path : $file_path;

        // Resize if larger than 1200×630 (maintaining aspect ratio, no upscaling).
        $size = $editor->get_size();
        if ( $size['width'] > 1200 || $size['height'] > 630 ) {
            $editor->resize( 1200, 630, false );
        }
        $editor->set_quality( 82 );
        $saved = $editor->save( $save_path, 'image/jpeg' );
        if ( is_wp_error( $saved ) ) {
            copy( $backup, $file_path );
            wp_delete_file( $backup );
            return $saved;
        }
        // If saved to a new .jpg path, remove the original PNG and update attachment.
        if ( $save_path !== $file_path ) {
            wp_delete_file( $file_path );
            update_attached_file( $attachment_id, $save_path );
            $file_path = $save_path;
        }
        $new_bytes = filesize( $file_path );
        // Still over 300 KB? Drop quality further.
        if ( $new_bytes > 307200 ) {
            $e2 = wp_get_image_editor( $backup );
            if ( ! is_wp_error( $e2 ) ) {
                $e2->set_quality( 65 );
                $e2->save( $file_path, 'image/jpeg' );
                $new_bytes = filesize( $file_path );
            }
        }
        $new_kb = round( $new_bytes / 1024, 1 );
        // Regenerate attachment metadata.
        $meta = wp_generate_attachment_metadata( $attachment_id, $file_path );
        wp_update_attachment_metadata( $attachment_id, $meta );
        return [
            'attachment_id' => $attachment_id,
            'new_size_kb'   => $new_kb,
            'backup'        => basename( $backup ),
            'under_limit'   => $new_bytes <= 307200,
            'message'       => $new_bytes <= 307200
                ? sprintf( __( 'Recompressed to %s KB — within the WhatsApp 300 KB threshold.', 'cloudscale-devtools' ), $new_kb )
                : sprintf( __( 'Recompressed to %s KB — still above threshold. Manual intervention needed.', 'cloudscale-devtools' ), $new_kb ),
        ];
    }

    // ─── AJAX: save vendor API key ──────────────────────────────────────

    public static function ajax_ai_image_save_key(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $vendor = isset( $_POST['vendor'] ) ? sanitize_key( wp_unslash( $_POST['vendor'] ) ) : 'openai';
        $raw    = isset( $_POST['key'] )    ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
        $key    = trim( $raw );

        $option_map = [
            'openai'    => 'csdt_devtools_openai_key',
            'anthropic' => 'csdt_devtools_anthropic_key',
            'gemini'    => 'csdt_devtools_gemini_key',
        ];
        $option = $option_map[ $vendor ] ?? 'csdt_devtools_openai_key';
        if ( $key !== '' ) {
            update_option( $option, $key, false );
        }

        // Also persist the selected vendor+model so the panel remembers them.
        if ( isset( $_POST['prompt_vendor'] ) ) {
            update_option( 'csdt_devtools_prompt_vendor', sanitize_key( wp_unslash( $_POST['prompt_vendor'] ) ), false );
        }
        if ( isset( $_POST['prompt_model'] ) ) {
            update_option( 'csdt_devtools_prompt_model', sanitize_key( wp_unslash( $_POST['prompt_model'] ) ), false );
        }

        $saved = (string) get_option( $option, '' );
        wp_send_json_success( [
            'saved'  => ! empty( $saved ),
            'key'    => $saved,
            'vendor' => $vendor,
        ] );
    }

    // ─── AJAX: test vendor API key ───────────────────────────────────────

    public static function ajax_ai_image_test_key(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $vendor = isset( $_POST['vendor'] ) ? sanitize_key( wp_unslash( $_POST['vendor'] ) ) : 'openai';
        try {
            switch ( $vendor ) {
                case 'anthropic':
                    CSDT_AI_Dispatcher::call( 'You are a test assistant.', 'Reply with the single word OK and nothing else.', '_auto', 5, 'anthropic' );
                    wp_send_json_success( [ 'message' => '✓ Anthropic key is valid.' ] );
                    break;
                case 'gemini':
                    CSDT_AI_Dispatcher::call( 'You are a test assistant.', 'Reply with the single word OK and nothing else.', '_auto', 5, 'gemini' );
                    wp_send_json_success( [ 'message' => '✓ Google key is valid.' ] );
                    break;
                default:
                    CSDT_AI_Dispatcher::call_openai_text( 'You are a test assistant.', 'Reply with the single word OK and nothing else.', 5 );
                    wp_send_json_success( [ 'message' => '✓ OpenAI key is valid.' ] );
                    break;
            }
        } catch ( \RuntimeException $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    // ─── AJAX: find posts without featured images ────────────────────────

    public static function ajax_ai_image_scan(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $sort = isset( $_POST['sort'] ) ? sanitize_key( wp_unslash( $_POST['sort'] ) ) : 'newest';
        $mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'missing';

        // All known view-count meta keys — checked in priority order.
        $view_meta_keys = [
            '_cspv_view_count', 'ab_post_views',
            'post_views_count', 'views', '_post_views', 'wpb_post_views_count',
            'jetpack-views', '_postviews_counter', 'tally', 'total_views',
            'views_counter', 'hit_count',
        ];

        $date_order = ( 'oldest' === $sort ) ? 'ASC' : 'DESC';
        $query_args = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'orderby'        => 'date',
            'order'          => $date_order,
            'fields'         => 'ids',
        ];
        if ( 'missing' === $mode ) {
            $query_args['meta_query'] = [ [ 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ] ];
        } elseif ( 'with_image' === $mode ) {
            $query_args['meta_query'] = [ [ 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ] ];
        }
        $posts = get_posts( $query_args );

        $results = [];

        foreach ( $posts as $post_id ) {
            $post_obj = get_post( $post_id );

            $view_count = null;
            foreach ( $view_meta_keys as $mk ) {
                $val = get_post_meta( $post_id, $mk, true );
                if ( $val !== '' && $val !== false ) {
                    $view_count = (int) $val;
                    break;
                }
            }

            $word_count = $post_obj
                ? str_word_count( wp_strip_all_tags( $post_obj->post_content ) )
                : 0;

            $thumb_id        = (int) get_post_thumbnail_id( $post_id );
            $thumb_url       = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : null;
            $thumb_post      = $thumb_id ? get_post( $thumb_id ) : null;
            $thumb_date_raw  = $thumb_post ? strtotime( $thumb_post->post_date ) : 0;

            $results[] = [
                'post_id'        => $post_id,
                'title'          => get_the_title( $post_id ),
                'edit_url'       => get_edit_post_link( $post_id, 'raw' ),
                'post_url'       => get_permalink( $post_id ),
                'date'           => get_the_date( 'j M Y', $post_id ),
                'comment_count'  => (int) ( $post_obj ? $post_obj->comment_count : 0 ),
                'view_count'     => $view_count,
                'word_count'     => $word_count,
                'has_thumb'      => (bool) $thumb_id,
                'thumb_url'      => $thumb_url ?: null,
                'thumb_date_raw' => $thumb_date_raw,
                'thumb_date'     => $thumb_date_raw ? gmdate( 'j M Y', $thumb_date_raw ) : null,
            ];
        }

        // PHP re-sort for non-date orderings.
        if ( 'popular' === $sort ) {
            usort( $results, function ( $a, $b ) {
                $av = $a['view_count'] ?? 0;
                $bv = $b['view_count'] ?? 0;
                if ( $bv !== $av ) { return $bv - $av; }
                return $b['comment_count'] - $a['comment_count'];
            } );
        } elseif ( 'longest' === $sort ) {
            usort( $results, function ( $a, $b ) {
                return ( $b['word_count'] ?? 0 ) - ( $a['word_count'] ?? 0 );
            } );
        } elseif ( 'img_date' === $sort ) {
            usort( $results, function ( $a, $b ) {
                return ( $b['thumb_date_raw'] ?? 0 ) - ( $a['thumb_date_raw'] ?? 0 );
            } );
        }

        wp_send_json_success( [ 'posts' => $results, 'sort' => $sort, 'mode' => $mode ] );
    }

    // ─── AJAX: generate DALL-E image for a post (returns 2 options) ─────

    // ─── AJAX: save system prompt ────────────────────────────────────────

    public static function ajax_ai_image_save_sysprompt(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $raw = isset( $_POST['system_prompt'] ) ? wp_unslash( $_POST['system_prompt'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $clean = sanitize_textarea_field( $raw );
        if ( '' === $clean ) {
            $clean = self::DEFAULT_IMG_SYSTEM_PROMPT;
        }
        update_option( 'csdt_devtools_img_system_prompt', $clean, false );
        wp_send_json_success( [ 'saved' => true ] );
    }

    // ─── AJAX: save image style / quality / dual settings ───────────────

    public static function ajax_ai_image_save_settings(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        if ( isset( $_POST['style'] ) )   { update_option( 'csdt_devtools_img_style',    sanitize_key( wp_unslash( $_POST['style'] ) ),        false ); }
        if ( isset( $_POST['quality'] ) ) { update_option( 'csdt_devtools_img_quality', sanitize_key( wp_unslash( $_POST['quality'] ) ),       false ); }
        if ( isset( $_POST['no_text'] ) ) { update_option( 'csdt_devtools_img_no_text', rest_sanitize_boolean( wp_unslash( $_POST['no_text'] ) ), false ); }
        wp_send_json_success();
    }

    // ─── AJAX: write DALL-E prompt via AI (step 1 of 2) ─────────────────

    public static function ajax_ai_image_write_prompt(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        set_time_limit( 120 );

        $post_id       = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        $prompt_vendor = isset( $_POST['prompt_vendor'] ) ? sanitize_key( wp_unslash( $_POST['prompt_vendor'] ) ) : 'openai';
        $prompt_model  = isset( $_POST['prompt_model'] )  ? sanitize_text_field( wp_unslash( $_POST['prompt_model'] ) ) : 'gpt-4o';
        $style         = isset( $_POST['prompt_style'] )  ? sanitize_key( wp_unslash( $_POST['prompt_style'] ) ) : 'auto';
        $no_text       = ! empty( $_POST['no_text'] ) && '1' === $_POST['no_text'];
        $force_vary    = ! empty( $_POST['force_vary'] ) && '1' === $_POST['force_vary'];

        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'Invalid post ID.' ] );
            return;
        }
        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( [ 'message' => 'Post not found.' ] );
            return;
        }

        $title      = $post->post_title;
        $content    = wp_strip_all_tags( $post->post_content );
        $excerpt    = $post->post_excerpt ?: wp_trim_words( $content, 80 );
        $categories = implode( ', ', wp_list_pluck( get_the_category( $post_id ), 'name' ) );
        $tags       = implode( ', ', wp_list_pluck( get_the_tags( $post_id ) ?: [], 'name' ) );
        $full_body  = mb_substr( $content, 0, 4000 );

        $context_parts = [ "Post title: \"{$title}\"" ];
        if ( $categories ) { $context_parts[] = "Categories: {$categories}"; }
        if ( $tags )       { $context_parts[] = "Tags: {$tags}"; }
        if ( $excerpt )    { $context_parts[] = "Excerpt: \"{$excerpt}\""; }
        $context_parts[] = "Article content:\n{$full_body}";
        $context_str = implode( "\n\n", $context_parts );

        $style_map = [
            'cinematic_poster'      => 'cinematic photorealistic movie-poster layout, ONE bold 2–3 word all-caps headline as the ONLY text element on a dark background — absolutely no subtitles, body copy, bullet points, captions, or any other text',
            'technical_infographic' => 'bold technical illustration with strong visual hierarchy, clean geometric shapes',
            'photorealistic'        => 'cinematic photorealistic photography, dramatic lighting, macro detail',
            'editorial'             => 'professional editorial photography',
            'isometric'             => 'clean isometric 3D illustration',
            'cartoon'               => 'bold cartoon illustration',
            'flat_vector'           => 'flat vector illustration, bold shapes, clean lines',
            'minimalist'            => 'minimalist design, bold shapes, clean negative space',
        ];

        // Styles that make sense as blog headers — used when auto-varying on Regenerate.
        $vary_pool = [ 'photorealistic', 'editorial', 'technical_infographic', 'minimalist', 'isometric', 'cartoon', 'flat_vector' ];

        // On force_vary, cycle through sensible blog-header styles only.
        if ( $force_vary ) {
            $candidates = array_diff( $vary_pool, [ $style ] );
            $style      = $candidates[ array_rand( $candidates ) ];
        }

        $style_instruction = isset( $style_map[ $style ] ) ? " Required visual style: {$style_map[$style]}." : '';
        $is_poster = ( $style === 'cinematic_poster' );
        $text_rule = $no_text
            ? ' TEXT RULE: The finished image must contain ZERO text — no words, no letters, no numbers, no labels, no captions, no titles. Pure visual only.'
            : ( $is_poster
                ? ' TEXT RULE: Include ONE 2–3 word all-caps bold headline as the SOLE text element. Absolutely no subtitles, body copy, bullet points, captions, or other text.'
                : ' TEXT RULE: Include ZERO text in the image — no headlines, titles, labels, captions, or descriptions. The article title is added separately by the publishing system.' );

        $vary_instruction = $force_vary
            ? ' IMPORTANT: The user just regenerated because they did not like the previous image. You MUST choose a completely different visual metaphor: different setting, different subject, different mood. If the previous prompt used a data centre or city, use a workshop, natural landscape, or organic environment instead. If it used an isometric style, use photorealistic. Never repeat the same type of scene.'
            : '';

        // Pick a random compositional POV to inject into the generated prompt itself.
        // This must be part of the GPT-4o instruction (not appended after), so it gets
        // baked into the DALL-E prompt and survives DALL-E's internal content revision.
        $pov_pool = [
            'MANDATORY COMPOSITION: extreme macro close-up — fill the entire frame with surface detail, no background visible.',
            'MANDATORY COMPOSITION: dramatic worm\'s-eye view — subjects tower above the viewer, shot from ground level looking steeply upward.',
            'MANDATORY COMPOSITION: aerial top-down bird\'s-eye view — all subjects arranged on a flat surface, viewed from directly above.',
            'MANDATORY COMPOSITION: first-person POV — viewer\'s hands visible in lower frame interacting with the subjects.',
            'MANDATORY COMPOSITION: cinematic wide shot — subjects occupy left third, vast dark space on right with distant atmospheric depth.',
            'MANDATORY COMPOSITION: over-the-shoulder mid shot — one large subject looms in the left foreground, second subject faces it from the right.',
        ];
        $pov_instruction = ' ' . $pov_pool[ array_rand( $pov_pool ) ];

        $system_msg   = self::get_img_system_prompt();
        $user_msg   = "{$context_str}\n\nStep 1 — Read the ENTIRE article — title, angle, and conclusion — then identify: (a) which technology brands/products/protocols are mentioned, (b) the ROLE each plays in THIS SPECIFIC ARTICLE'S NARRATIVE — CHAMPION (clearly praised, winning, recommended), STRUGGLING (clearly legacy, failing, being replaced), DISRUPTOR/THREAT (causes unexpected breakage, silent failure, hidden danger — even if technically modern), or NEUTRAL. WARNING: do not default to \"new = champion\". If the article warns that something breaks sites or causes failures, that subject is a DISRUPTOR/THREAT regardless of its technical modernity.\n\nStep 2 — Write the DALL-E 3 prompt using those roles. For companies with logos (AWS, Cloudflare, Docker, etc.) use their actual iconic visual. For protocols and concepts (TCP, QUIC, HTTP/3, etc.) use a concrete physical metaphor for what they DO. Champions gleam; struggling subjects glow red-hot; disruptors look sleek but leave fractured broken elements in their wake. Place subjects as large prominent foreground elements. Do not state the roles — just apply them visually.\n\nOutput ONLY the final DALL-E 3 prompt.{$style_instruction}{$text_rule}{$pov_instruction}{$vary_instruction}";

        try {
            switch ( $prompt_vendor ) {
                case 'openai':
                    $prompt = CSDT_AI_Dispatcher::call_openai_text( $system_msg, $user_msg, 600, $prompt_model );
                    break;
                case 'anthropic':
                    $prompt = CSDT_AI_Dispatcher::call( $system_msg, $user_msg, $prompt_model, 600, 'anthropic' );
                    break;
                case 'gemini':
                    $prompt = CSDT_AI_Dispatcher::call( $system_msg, $user_msg, $prompt_model, 600, 'gemini' );
                    break;
                default:
                    $prompt = "Professional blog header image for an article titled \"{$title}\". High-quality, wide landscape format.";
                    break;
            }
        } catch ( \RuntimeException $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
            return;
        }

        wp_send_json_success( [ 'prompt' => $prompt ] );
    }

    // ─── AJAX: generate image from prompt (step 2 of 2) ─────────────────

    public static function ajax_ai_image_generate(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        set_time_limit( 180 );

        $post_id  = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        $quality  = ( isset( $_POST['quality'] ) && $_POST['quality'] === 'hd' ) ? 'hd' : 'standard';
        $count    = 1;
        $prompt        = isset( $_POST['prompt'] )        ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
        $no_text       = isset( $_POST['no_text'] )       && '1' === $_POST['no_text'];
        $prompt_vendor = isset( $_POST['prompt_vendor'] ) ? sanitize_key( wp_unslash( $_POST['prompt_vendor'] ) )      : 'openai';
        $prompt_model  = isset( $_POST['prompt_model'] )  ? sanitize_text_field( wp_unslash( $_POST['prompt_model'] ) ) : 'gpt-4o';
        $prompt_style  = isset( $_POST['prompt_style'] )  ? sanitize_key( wp_unslash( $_POST['prompt_style'] ) )       : 'auto';

        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'Invalid post ID.' ] );
            return;
        }
        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( [ 'message' => 'Post not found.' ] );
            return;
        }

        $title = $post->post_title;
        $slug  = sanitize_file_name( $post->post_name ?: 'post-' . $post_id );

        if ( '' === $prompt ) {
            $content    = wp_strip_all_tags( $post->post_content );
            $excerpt    = $post->post_excerpt ?: wp_trim_words( $content, 80 );
            $categories = implode( ', ', wp_list_pluck( get_the_category( $post_id ), 'name' ) );
            $tags       = implode( ', ', wp_list_pluck( get_the_tags( $post_id ) ?: [], 'name' ) );
            $full_body  = mb_substr( $content, 0, 4000 );

            $context_parts = [ "Post title: \"{$title}\"" ];
            if ( $categories ) { $context_parts[] = "Categories: {$categories}"; }
            if ( $tags )       { $context_parts[] = "Tags: {$tags}"; }
            if ( $excerpt )    { $context_parts[] = "Excerpt: \"{$excerpt}\""; }
            $context_parts[] = "Article content:\n{$full_body}";
            $context_str = implode( "\n\n", $context_parts );

            $style_map_inline = [
                'cinematic_poster'      => 'cinematic photorealistic movie-poster layout, ONE bold 2–3 word all-caps headline as the ONLY text element on a dark background — absolutely no subtitles, body copy, bullet points, captions, or any other text',
                'technical_infographic' => 'bold technical illustration with strong visual hierarchy, clean geometric shapes',
                'photorealistic'        => 'cinematic photorealistic photography, dramatic lighting, macro detail',
                'editorial'             => 'professional editorial photography',
                'isometric'             => 'clean isometric 3D illustration',
                'cartoon'               => 'bold cartoon illustration',
                'flat_vector'           => 'flat vector illustration, bold shapes, clean lines',
                'minimalist'            => 'minimalist design, bold shapes, clean negative space',
            ];
            $style_instr_inline  = isset( $style_map_inline[ $prompt_style ] ) ? " Required visual style: {$style_map_inline[$prompt_style]}." : '';
            $is_poster_inline    = ( $prompt_style === 'cinematic_poster' );
            $text_rule_inline    = $no_text
                ? ' TEXT RULE: The finished image must contain ZERO text — no words, no letters, no numbers, no labels, no captions, no titles. Pure visual only.'
                : ( $is_poster_inline
                    ? ' TEXT RULE: Include ONE 2–3 word all-caps bold headline as the SOLE text element. Absolutely no subtitles, body copy, bullet points, captions, or other text.'
                    : ' TEXT RULE: Include ZERO text in the image — no headlines, titles, labels, captions, or descriptions. The article title is added separately by the publishing system.' );
            $pov_pool_inline = [
                'MANDATORY COMPOSITION: extreme macro close-up — fill the entire frame with surface detail, no background visible.',
                'MANDATORY COMPOSITION: dramatic worm\'s-eye view — subjects tower above the viewer, shot from ground level looking steeply upward.',
                'MANDATORY COMPOSITION: aerial top-down bird\'s-eye view — all subjects arranged on a flat surface, viewed from directly above.',
                'MANDATORY COMPOSITION: first-person POV — viewer\'s hands visible in lower frame interacting with the subjects.',
                'MANDATORY COMPOSITION: cinematic wide shot — subjects occupy left third, vast dark space on right with distant atmospheric depth.',
                'MANDATORY COMPOSITION: over-the-shoulder mid shot — one large subject looms in the left foreground, second subject faces it from the right.',
            ];
            $pov_inline = ' ' . $pov_pool_inline[ array_rand( $pov_pool_inline ) ];
            $system_msg = self::get_img_system_prompt();
            $user_msg   = "{$context_str}\n\nStep 1 — Read the ENTIRE article — title, angle, and conclusion — then identify: (a) which technology brands/products/protocols are mentioned, (b) the ROLE each plays in THIS SPECIFIC ARTICLE'S NARRATIVE — CHAMPION (clearly praised, winning, recommended), STRUGGLING (clearly legacy, failing, being replaced), DISRUPTOR/THREAT (causes unexpected breakage, silent failure, hidden danger — even if technically modern), or NEUTRAL. WARNING: do not default to \"new = champion\". If the article warns that something breaks sites or causes failures, that subject is a DISRUPTOR/THREAT regardless of its technical modernity.\n\nStep 2 — Write the DALL-E 3 prompt using those roles. For companies with logos (AWS, Cloudflare, Docker, etc.) use their actual iconic visual. For protocols and concepts (TCP, QUIC, HTTP/3, etc.) use a concrete physical metaphor for what they DO. Champions gleam; struggling subjects glow red-hot; disruptors look sleek but leave fractured broken elements in their wake. Place subjects as large prominent foreground elements. Do not state the roles — just apply them visually.\n\nOutput ONLY the final DALL-E 3 prompt.{$style_instr_inline}{$text_rule_inline}{$pov_inline}";

            try {
                switch ( $prompt_vendor ) {
                    case 'openai':
                        $prompt = CSDT_AI_Dispatcher::call_openai_text( $system_msg, $user_msg, 600, $prompt_model );
                        break;
                    case 'anthropic':
                        $prompt = CSDT_AI_Dispatcher::call( $system_msg, $user_msg, $prompt_model, 600, 'anthropic' );
                        break;
                    case 'gemini':
                        $prompt = CSDT_AI_Dispatcher::call( $system_msg, $user_msg, $prompt_model, 600, 'gemini' );
                        break;
                    default:
                        $prompt = "Professional blog header image for an article titled \"{$title}\". High-quality, wide landscape format.";
                        break;
                }
            } catch ( \RuntimeException $e ) {
                wp_send_json_error( [ 'message' => $e->getMessage() ] );
                return;
            }
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $options    = [];
        $last_error = '';

        // Enforce no-text at the DALL-E call level — DALL-E cannot reliably render
        // text and any attempt produces garbled glyphs. Append when the user has
        // checked "no text", or always (blog headers should never carry text).
        if ( $no_text ) {
            $prompt .= ' Important: NO text, words, letters, numbers, labels, captions, or typography of any kind anywhere in the image.';
        }

        for ( $i = 1; $i <= $count; $i++ ) {
            try {
                $image_url = CSDT_AI_Dispatcher::generate_image( $prompt, '1792x1024', $quality );
            } catch ( \RuntimeException $e ) {
                $last_error = $e->getMessage();
                continue;
            }

            $tmp_png = download_url( $image_url );
            if ( is_wp_error( $tmp_png ) ) {
                continue;
            }

            $tmp_jpg = self::convert_to_jpg_under_400k( $tmp_png );
            @unlink( $tmp_png ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

            if ( ! $tmp_jpg ) {
                continue;
            }

            // Skip GD title overlay for cinematic poster style — text is already
            // rendered inside the image by DALL-E as a design element.
            $is_poster = ( $prompt_style === 'cinematic_poster' )
                || ( stripos( $prompt, 'bold all-caps' ) !== false )
                || ( stripos( $prompt, 'all-caps text' ) !== false )
                || ( stripos( $prompt, 'poster layout' ) !== false )
                || ( stripos( $prompt, 'movie poster' ) !== false );
            if ( ! $is_poster ) {
                self::overlay_title( $tmp_jpg, $title );
            }

            $file = [
                'name'     => $slug . '-ai-header-' . $i . '.jpg',
                'tmp_name' => $tmp_jpg,
            ];

            $attach_id = media_handle_sideload( $file, $post_id, $title );
            @unlink( $tmp_jpg ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

            if ( is_wp_error( $attach_id ) ) {
                continue;
            }

            update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( $title ) );

            $options[] = [
                'attach_id' => $attach_id,
                'thumb_url' => wp_get_attachment_image_url( $attach_id, 'large' ),
                'full_url'  => wp_get_attachment_url( $attach_id ),
            ];
        }

        if ( empty( $options ) ) {
            wp_send_json_error( [ 'message' => $last_error ?: 'Failed to generate images.' ] );
            return;
        }

        wp_send_json_success( [ 'options' => $options, 'prompt' => $prompt ] );
    }

    // ─── AJAX: pick one of the generated options as thumbnail ────────────

    public static function ajax_ai_image_pick(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $post_id   = isset( $_POST['post_id'] )   ? (int) $_POST['post_id']   : 0;
        $attach_id = isset( $_POST['attach_id'] ) ? (int) $_POST['attach_id'] : 0;
        $discard   = isset( $_POST['discard'] ) && '' !== $_POST['discard']
            ? array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $_POST['discard'] ) ) ) )
            : [];

        if ( ! $post_id || ! $attach_id ) {
            wp_send_json_error( [ 'message' => 'Invalid IDs.' ] );
            return;
        }

        set_post_thumbnail( $post_id, $attach_id );

        // Ensure all registered WP image sizes exist for this attachment.
        $attach_file = get_attached_file( $attach_id );
        if ( $attach_file && file_exists( $attach_file ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $meta = wp_generate_attachment_metadata( $attach_id, $attach_file );
            if ( $meta ) {
                wp_update_attachment_metadata( $attach_id, $meta );
            }
        }

        // Trigger social-format generation (Facebook, Twitter, etc.) without
        // requiring a full post save — same logic as on_post_saved hook.
        self::generate_social_formats_for_post( $post_id );

        // Purge CF cache so the page HTML served to crawlers reflects the new og:image.
        $post_url = get_permalink( $post_id );
        if ( $post_url ) {
            self::cf_purge_urls( [ $post_url ] );
        }

        foreach ( $discard as $did ) {
            if ( $did !== $attach_id ) {
                wp_delete_attachment( $did, true );
            }
        }

        wp_send_json_success( [
            'thumb_url' => wp_get_attachment_image_url( $attach_id, 'medium' ),
        ] );
    }

    // ─── Discard temp attachments (Cancel in modal) ──────────────────────

    public static function ajax_ai_image_discard(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $raw = isset( $_POST['attach_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['attach_ids'] ) ) : '';
        $ids = array_filter( array_map( 'intval', explode( ',', $raw ) ) );

        foreach ( $ids as $id ) {
            wp_delete_attachment( $id, true );
        }

        wp_send_json_success();
    }

    // ─── Helper: extract recognisable tech brand/logo names from post text ──

    private static function extract_tech_logos( string ...$texts ): string {
        $haystack = mb_strtolower( implode( ' ', $texts ) );
        $brands = [
            'AWS'            => 'aws amazon',
            'Amazon'         => 'amazon',
            'Azure'          => 'azure microsoft',
            'Google Cloud'   => 'google cloud gcp',
            'Cloudflare'     => 'cloudflare',
            'Raspberry Pi'   => 'raspberry pi raspi',
            'ARM'            => '\barm\b',
            'Docker'         => 'docker',
            'Kubernetes'     => 'kubernetes k8s',
            'Nginx'          => 'nginx',
            'MySQL'          => 'mysql',
            'PostgreSQL'     => 'postgresql postgres',
            'Redis'          => 'redis',
            'Python'         => 'python',
            'WordPress'      => 'wordpress',
            'GitHub'         => 'github',
            'Linux'          => 'linux',
            'Intel'          => 'intel',
            'NVIDIA'         => 'nvidia',
        ];
        $found = [];
        foreach ( $brands as $label => $pattern ) {
            if ( preg_match( '/' . $pattern . '/i', $haystack ) ) {
                $found[] = $label;
            }
        }
        return implode( ', ', $found );
    }

    // ─── Helper: convert any image to JPEG under 400 KB ──────────────────

    private static function convert_to_jpg_under_400k( string $src_path ): ?string {
        if ( ! function_exists( 'imagecreatefromstring' ) ) {
            return null;
        }
        $data = file_get_contents( $src_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( ! $data ) {
            return null;
        }
        $src = imagecreatefromstring( $data );
        if ( ! $src ) {
            return null;
        }

        $w   = imagesx( $src );
        $h   = imagesy( $src );
        $bg  = imagecreatetruecolor( $w, $h );
        imagefill( $bg, 0, 0, imagecolorallocate( $bg, 255, 255, 255 ) );
        imagealphablending( $bg, true );
        imagecopy( $bg, $src, 0, 0, 0, 0, $w, $h );
        imagedestroy( $src );

        $jpg_path = $src_path . '.jpg';
        $limit    = 400 * 1024;

        for ( $q = 85; $q >= 40; $q -= 5 ) {
            imagejpeg( $bg, $jpg_path, $q );
            if ( file_exists( $jpg_path ) && filesize( $jpg_path ) < $limit ) {
                break;
            }
        }

        imagedestroy( $bg );

        if ( ! file_exists( $jpg_path ) || filesize( $jpg_path ) >= $limit ) {
            @unlink( $jpg_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return null;
        }

        return $jpg_path;
    }

    // ─── Overlay post title onto generated image ──────────────────────────────

    private static function overlay_title( string $jpg_path, string $title ): void {
        if ( ! function_exists( 'imagecreatefromjpeg' ) || ! function_exists( 'imagecopymerge' ) ) {
            return;
        }

        $img = @imagecreatefromjpeg( $jpg_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! $img ) {
            return;
        }

        $w       = imagesx( $img );
        $h       = imagesy( $img );
        $pad     = (int) ( $w * 0.022 );
        $strip_h = (int) ( $h * 0.155 );
        $strip_y = $h - $strip_h;

        // Semi-transparent dark strip
        $overlay = imagecreatetruecolor( $w, $strip_h );
        $black   = imagecolorallocate( $overlay, 0, 0, 0 );
        imagefilledrectangle( $overlay, 0, 0, $w - 1, $strip_h - 1, $black );
        imagecopymerge( $img, $overlay, 0, $strip_y, 0, 0, $w, $strip_h, 68 );
        imagedestroy( $overlay );

        $display = $title;
        $white   = imagecolorallocate( $img, 255, 255, 255 );
        $font    = self::find_ttf_font();

        if ( $font && function_exists( 'imagettftext' ) && function_exists( 'imagettfbbox' ) ) {
            $max_w     = $w - ( 2 * $pad );
            $font_size = (int) ( $strip_h * 0.31 );
            $min_font  = (int) ( $strip_h * 0.22 );
            $line2     = '';

            // imagettfbbox() consistently underestimates rendered string width in GD,
            // so any margin-based fix still clips. Instead decide wrap vs single-line
            // purely by character count: DejaVuSans-Bold average advance ≈ 0.60× em.
            $chars_per_line = (int) ( $max_w / ( $min_font * 0.60 ) );
            $force_wrap     = mb_strlen( $display ) > $chars_per_line;

            if ( ! $force_wrap ) {
                // Short title — try single line, shrinking font until it fits.
                $fit_w = (int) ( $max_w * 0.90 );
                while ( $font_size >= $min_font ) {
                    $bbox = imagettfbbox( $font_size, 0, $font, $display );
                    if ( $bbox && ( $bbox[2] - $bbox[0] ) <= $fit_w ) {
                        break;
                    }
                    $font_size -= 2;
                }
            }

            // Long title or imagettfbbox loop exhausted — wrap to 2 lines using char count
            // (imagettfbbox underestimates width, so we never trust it for split decisions)
            if ( $force_wrap || $font_size < $min_font ) {
                $font_size  = $min_font;
                $words      = explode( ' ', $display );
                $total      = count( $words );
                $best_split = -1;
                $best_diff  = PHP_INT_MAX;
                for ( $split = 1; $split < $total; $split++ ) {
                    $l1 = implode( ' ', array_slice( $words, 0, $split ) );
                    $l2 = implode( ' ', array_slice( $words, $split ) );
                    if ( mb_strlen( $l1 ) <= $chars_per_line && mb_strlen( $l2 ) <= $chars_per_line ) {
                        $diff = abs( mb_strlen( $l1 ) - mb_strlen( $l2 ) );
                        if ( $diff < $best_diff ) {
                            $best_diff  = $diff;
                            $best_split = $split;
                        }
                    }
                }
                if ( $best_split > 0 ) {
                    $display = implode( ' ', array_slice( $words, 0, $best_split ) );
                    $line2   = implode( ' ', array_slice( $words, $best_split ) );
                } else {
                    // Extremely long title — hard-truncate each line to chars_per_line
                    $display = mb_substr( $display, 0, $chars_per_line - 1 ) . '…';
                }
            }

            if ( '' === $line2 ) {
                // Single line — vertically centred in strip
                $text_y = $strip_y + (int) ( $strip_h * 0.68 );
                imagettftext( $img, $font_size, 0, $pad, $text_y, $white, $font, $display );
            } else {
                // Two lines — stacked with a small gap
                $line_h = (int) ( $font_size * 1.25 );
                $block  = 2 * $line_h;
                $top_y  = $strip_y + (int) ( ( $strip_h - $block ) / 2 ) + $line_h;
                imagettftext( $img, $font_size, 0, $pad, $top_y, $white, $font, $display );
                imagettftext( $img, $font_size, 0, $pad, $top_y + $line_h, $white, $font, $line2 );
            }
        } else {
            // Built-in GD bitmap font fallback
            $short  = mb_strlen( $display ) > 60 ? mb_substr( $display, 0, 59 ) . '…' : $display;
            $text_y = $strip_y + (int) ( ( $strip_h - 16 ) / 2 );
            imagestring( $img, 5, $pad, $text_y, $short, $white );
        }

        imagejpeg( $img, $jpg_path, 88 );
        imagedestroy( $img );
    }

    private static function find_ttf_font(): ?string {
        static $cached = false;
        if ( false !== $cached ) {
            return '' !== $cached ? $cached : null;
        }
        $candidates = [
            // Bundled in plugin — always available regardless of host OS
            plugin_dir_path( __FILE__ ) . '../assets/fonts/DejaVuSans-Bold.ttf',
            // System fallbacks
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/ubuntu/Ubuntu-B.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
        ];
        foreach ( $candidates as $f ) {
            if ( file_exists( $f ) ) {
                $cached = $f;
                return $f;
            }
        }
        $cached = '';
        return null;
    }


    // ─── AJAX: scan featured images for missing WordPress thumbnail sizes ────

    public static function ajax_regen_thumb_scan(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $sizes  = wp_get_registered_image_subsizes();
        $upload = wp_upload_dir();
        global $wpdb;

        // Only check images that are actually in use as featured images.
        $candidate_ids = array_map( 'intval', $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value != '0'"
        ) );
        $default_id = (int) get_option( 'cloudscale_default_image_id', 0 );
        if ( $default_id ) {
            $candidate_ids[] = $default_id;
        }
        $logo = get_theme_mod( 'custom_logo' );
        if ( $logo ) {
            $candidate_ids[] = (int) $logo;
        }
        $icon = (int) get_option( 'site_icon', 0 );
        if ( $icon ) {
            $candidate_ids[] = $icon;
        }
        $candidate_ids = array_values( array_unique( $candidate_ids ) );

        // Exclude images marked as incompatible by the batch regenerator.
        $skip_ids = array_map( 'intval', (array) get_option( 'csdt_thumb_regen_skip', [] ) );
        if ( $skip_ids ) {
            $candidate_ids = array_values( array_diff( $candidate_ids, $skip_ids ) );
        }

        $total   = count( $candidate_ids );
        $missing = [];

        foreach ( $candidate_ids as $id ) {
            $meta = wp_get_attachment_metadata( $id );
            if ( ! $meta || empty( $meta['file'] ) ) {
                continue;
            }
            $dir      = trailingslashit( $upload['basedir'] ) . trailingslashit( dirname( $meta['file'] ) );
            $img_w    = (int) ( $meta['width']  ?? 0 );
            $img_h    = (int) ( $meta['height'] ?? 0 );
            $has_miss = false;
            foreach ( $sizes as $size_name => $size_data ) {
                if ( isset( $meta['sizes'][ $size_name ] ) ) {
                    // Size is in metadata — flag missing only if the file is actually gone.
                    if ( ! file_exists( $dir . $meta['sizes'][ $size_name ]['file'] ) ) {
                        $has_miss = true;
                        break;
                    }
                } else {
                    // Size absent from metadata — only flag if the image is large enough
                    // that WordPress should have created it (avoids false positives for
                    // small images where WP intentionally skips certain sizes).
                    $sz_w  = (int) ( $size_data['width']  ?? 0 );
                    $sz_h  = (int) ( $size_data['height'] ?? 0 );
                    $crop  = ! empty( $size_data['crop'] );
                    $large_enough = $crop
                        ? ( $sz_w > 0 && $sz_h > 0 && $img_w >= $sz_w && $img_h >= $sz_h )
                        : ( ( $sz_w > 0 && $img_w > $sz_w ) || ( $sz_h > 0 && $img_h > $sz_h ) );
                    if ( $large_enough ) {
                        $has_miss = true;
                        break;
                    }
                }
            }
            if ( $has_miss ) {
                $missing[] = [
                    'id'    => $id,
                    'used'  => true,
                    'title' => get_the_title( $id ) ?: basename( get_attached_file( $id ) ?: '' ),
                    'url'   => wp_get_attachment_url( $id ),
                    'thumb' => wp_get_attachment_image_url( $id, 'thumbnail' ) ?: '',
                ];
            }
        }

        // Store the queue of IDs needing regeneration for the batch endpoint.
        set_transient( 'csdt_regen_thumb_queue', array_column( $missing, 'id' ), HOUR_IN_SECONDS );

        wp_send_json_success( [
            'total'   => $total,
            'missing' => count( $missing ),
            'images'  => $missing,
        ] );
    }

    // ─── AJAX: regenerate missing thumbnail sizes in batches ──────────────

    public static function ajax_regen_thumb_batch(): void {
        check_ajax_referer( self::THUMB_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $queue = get_transient( 'csdt_regen_thumb_queue' );
        if ( ! is_array( $queue ) ) {
            wp_send_json_error( [ 'message' => 'Queue expired — please scan again.' ] );
        }

        $offset     = absint( $_POST['offset'] ?? 0 );
        $batch_size = 5;
        $total      = count( $queue );
        $slice      = array_slice( $queue, $offset, $batch_size );

        $batch = [];
        foreach ( $slice as $id ) {
            $file = get_attached_file( $id );
            if ( ! $file || ! file_exists( $file ) ) {
                $batch[] = [ 'id' => $id, 'ok' => false, 'skipped' => true, 'error' => 'file_missing' ];
                continue;
            }
            $new_meta = wp_generate_attachment_metadata( $id, $file );
            if ( is_wp_error( $new_meta ) ) {
                $batch[] = [ 'id' => $id, 'ok' => false, 'skipped' => false, 'error' => $new_meta->get_error_message() ];
            } else {
                wp_update_attachment_metadata( $id, $new_meta );
                // If regen produced no sizes for an image that has dimensions, the
                // image format is incompatible with the server image library (e.g. CMYK
                // JPEG). Mark it permanently so the scan won't loop on it forever.
                if ( empty( $new_meta['sizes'] ) && ! empty( $new_meta['width'] ) ) {
                    $skip   = (array) get_option( 'csdt_thumb_regen_skip', [] );
                    $skip[] = $id;
                    update_option( 'csdt_thumb_regen_skip', array_unique( $skip ), false );
                }
                $batch[] = [ 'id' => $id, 'ok' => true, 'skipped' => false, 'regenerated' => true ];
            }
        }

        $next_offset = $offset + $batch_size;
        wp_send_json_success( [
            'batch'       => $batch,
            'next_offset' => $next_offset,
            'has_more'    => $next_offset < $total,
            'total'       => $total,
        ] );
    }

    /* ==================================================================
       Security tab helpers + render
       ================================================================== */

    public static function strip_asset_ver( string $src ): string {
        // Only strip on the public frontend — never on admin pages (breaks cache-busting for plugin assets).
        if ( is_admin() ) {
            return $src;
        }
        // Never strip from our own plugin assets (ver= is plugin version, not WP version).
        if ( strpos( $src, plugins_url( '', dirname( __FILE__ ) ) ) !== false ) {
            return $src;
        }
        if ( strpos( $src, 'ver=' ) !== false ) {
            $src = remove_query_arg( 'ver', $src );
        }
        return $src;
    }

}
