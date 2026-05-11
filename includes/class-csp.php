<?php
/**
 * CSP (Content Security Policy) — header output, nonce injection, panel, AJAX.
 *
 * @package CloudScale_DevTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CSDT_CSP {

    private static ?string $csp_nonce = null;

    public static function output_security_headers(): void {
        if ( is_admin() ) { return; }

        // Master switch — when off, suppress all headers regardless of per-header enabled flags.
        if ( get_option( 'csdt_devtools_safe_headers_enabled', '0' ) !== '1' ) { return; }

        $config = json_decode( (string) get_option( 'csdt_sec_headers_config', 'null' ), true );

        if ( ! is_array( $config ) ) {
            // Legacy: no per-header config saved yet. Master is on (checked above), so use hardcoded defaults.
            $config = [
                'x-content-type-options'    => [ 'enabled' => true, 'value' => 'nosniff' ],
                'x-frame-options'           => [ 'enabled' => true, 'value' => 'SAMEORIGIN' ],
                'referrer-policy'           => [ 'enabled' => true, 'value' => 'strict-origin-when-cross-origin' ],
                'permissions-policy'        => [ 'enabled' => true, 'value' => 'camera=(), microphone=(), geolocation=(), payment=()' ],
                'strict-transport-security' => [ 'enabled' => true, 'value' => 'max-age=31536000; includeSubDomains' ],
            ];
        }

        if ( ! empty( $config ) ) {
            $is_https = is_ssl()
                || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' )
                || ( isset( $_SERVER['HTTP_CF_VISITOR'] ) && strpos( $_SERVER['HTTP_CF_VISITOR'], '"https"' ) !== false );

            $header_names = [
                'x-content-type-options'    => 'X-Content-Type-Options',
                'x-frame-options'           => 'X-Frame-Options',
                'referrer-policy'           => 'Referrer-Policy',
                'permissions-policy'        => 'Permissions-Policy',
                'strict-transport-security' => 'Strict-Transport-Security',
            ];
            $fixed = [ 'x-content-type-options' => 'nosniff' ];

            foreach ( $header_names as $key => $name ) {
                if ( empty( $config[ $key ]['enabled'] ) ) { continue; }
                if ( 'strict-transport-security' === $key && ! $is_https ) { continue; }
                $value = $fixed[ $key ] ?? ( $config[ $key ]['value'] ?? '' );
                if ( ! $value ) { continue; }
                header( $name . ': ' . $value );
            }
        }
        if ( get_option( 'csdt_devtools_csp_enabled', '0' ) === '1' ) {
            $csp = self::build_csp_header();
            if ( $csp ) {
                $mode         = get_option( 'csdt_devtools_csp_mode', 'enforce' );
                $hdr          = $mode === 'report_only' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
                $reporting_on = get_option( 'csdt_csp_reporting_enabled', '0' ) === '1';
                $report_uri   = $reporting_on ? '; report-uri ' . rest_url( 'csdt/v1/csp-report' ) : '';
                header( $hdr . ': ' . $csp . $report_uri );
            }
        }
    }

    private static function build_csp_header(): string {
        $services = json_decode( get_option( 'csdt_devtools_csp_services', '[]' ), true );
        if ( ! is_array( $services ) ) { $services = []; }
        $custom = trim( get_option( 'csdt_devtools_csp_custom', '' ) );

        $use_nonces  = get_option( 'csdt_csp_nonces_enabled', '0' ) === '1';
        $script_src  = $use_nonces
            ? [ "'self'", "'nonce-" . self::get_csp_nonce() . "'", "'strict-dynamic'" ]
            : [ "'self'", "'unsafe-inline'", "'unsafe-eval'" ];
        // style-src must NOT use nonces: CSP3 ignores 'unsafe-inline' when a nonce is present,
        // which breaks all style="" attributes site-wide. Instead, allow the hljs CDN host directly.
        $d = [
            'default-src'     => [ "'self'" ],
            'script-src'      => $script_src,
            // script-src-elem (CSP3) is checked independently — always include 'self' so WP's
            // own scripts (wp-includes, theme JS) are never blocked when a service map adds this.
            'script-src-elem' => array_merge( [ "'self'" ], $use_nonces ? [ "'nonce-" . self::get_csp_nonce() . "'", "'strict-dynamic'" ] : [ "'unsafe-inline'" ] ),
            'style-src'       => [ "'self'", "'unsafe-inline'", 'https://cdnjs.cloudflare.com' ],
            'img-src'         => [ "'self'", 'data:', 'https:' ],
            'font-src'        => [ "'self'", 'data:' ],
            'connect-src'     => [ "'self'" ],
            'frame-src'       => [ "'self'" ],
            'object-src'      => [ "'none'" ],
            'base-uri'        => [ "'self'" ],
            'form-action'     => [ "'self'" ],
        ];

        $map = [
            'google_analytics'    => [
                'script-src'      => [ 'https://www.googletagmanager.com', 'https://www.google-analytics.com', 'https://ssl.google-analytics.com' ],
                'script-src-elem' => [ 'https://www.googletagmanager.com', 'https://www.google-analytics.com', 'https://ssl.google-analytics.com' ],
                'img-src'         => [ 'https://www.google-analytics.com', 'https://www.googletagmanager.com', 'https://ssl.google-analytics.com' ],
                'connect-src'     => [ 'https://www.google-analytics.com', 'https://analytics.google.com', 'https://stats.g.doubleclick.net', 'https://region1.google-analytics.com', 'https://www.googletagmanager.com', 'https://region1.analytics.google.com' ],
            ],
            'google_adsense'      => [
                'script-src'      => [ 'https://*.googlesyndication.com', 'https://*.googletagservices.com', 'https://*.googleadservices.com', 'https://adservice.google.com', 'https://fundingchoicesmessages.google.com' ],
                // script-src-elem is a CSP3 directive that browsers check separately from script-src.
                // fundingchoicesmessages.google.com (Google consent/funding choices) uses it.
                'script-src-elem' => [ 'https://*.googlesyndication.com', 'https://*.googletagservices.com', 'https://*.googleadservices.com', 'https://adservice.google.com', 'https://fundingchoicesmessages.google.com' ],
                'frame-src'       => [ 'blob:', 'https://*.googlesyndication.com', 'https://*.safeframe.googlesyndication.com', 'https://googleads.g.doubleclick.net', 'https://ep2.adtrafficquality.google' ],
                'img-src'         => [ 'https://*.googlesyndication.com', 'https://googleads.g.doubleclick.net' ],
                'connect-src'     => [ 'https://*.googlesyndication.com', 'https://*.googletagservices.com', 'https://adservice.google.com', 'https://ep1.adtrafficquality.google', 'https://ep2.adtrafficquality.google', 'https://fundingchoicesmessages.google.com', 'https://csi.gstatic.com' ],
            ],
            'google_fonts'        => [
                'style-src'       => [ 'https://fonts.googleapis.com' ],
                // style-src-elem for inline style sheets injected by Google Fonts.
                'style-src-elem'  => [ 'https://fonts.googleapis.com' ],
                'font-src'        => [ 'https://fonts.gstatic.com' ],
                // Variable fonts fetch via connect-src in some browsers.
                'connect-src'     => [ 'https://fonts.googleapis.com', 'https://fonts.gstatic.com' ],
            ],
            'google_tag_manager'  => [
                'script-src'      => [ 'https://www.googletagmanager.com', 'https://tagmanager.google.com' ],
                'script-src-elem' => [ 'https://www.googletagmanager.com', 'https://tagmanager.google.com' ],
                'img-src'         => [ 'https://www.googletagmanager.com', 'https://ssl.gstatic.com', 'https://www.gstatic.com' ],
                'connect-src'     => [ 'https://www.googletagmanager.com', 'https://tagmanager.google.com' ],
                'style-src'       => [ 'https://tagmanager.google.com', 'https://fonts.googleapis.com' ],
                'font-src'        => [ 'https://fonts.gstatic.com', 'data:' ],
            ],
            'cloudflare_insights' => [
                'script-src'  => [ 'https://static.cloudflareinsights.com' ],
                'connect-src' => [ 'https://cloudflareinsights.com' ],
            ],
            'facebook_pixel'      => [
                'script-src'  => [ 'https://connect.facebook.net' ],
                'img-src'     => [ 'https://www.facebook.com' ],
                'connect-src' => [ 'https://www.facebook.com' ],
            ],
            'recaptcha'           => [
                'script-src'      => [ 'https://www.google.com', 'https://www.gstatic.com', 'https://recaptcha.google.com' ],
                'script-src-elem' => [ 'https://www.google.com', 'https://www.gstatic.com', 'https://recaptcha.google.com' ],
                'frame-src'       => [ 'https://www.google.com', 'https://recaptcha.google.com' ],
                'connect-src'     => [ 'https://www.google.com' ],
            ],
            'youtube'             => [
                'frame-src'   => [ 'https://www.youtube.com', 'https://www.youtube-nocookie.com' ],
            ],
            'vimeo'               => [
                'frame-src'   => [ 'https://player.vimeo.com' ],
            ],
            'stripe'              => [
                'script-src'  => [ 'https://js.stripe.com' ],
                'frame-src'   => [ 'https://js.stripe.com', 'https://hooks.stripe.com' ],
                'connect-src' => [ 'https://api.stripe.com' ],
            ],
            'hotjar'              => [
                'script-src'  => [ 'https://static.hotjar.com', 'https://script.hotjar.com' ],
                'connect-src' => [ 'https://*.hotjar.com', 'wss://*.hotjar.com' ],
                'img-src'     => [ 'https://*.hotjar.com' ],
                'frame-src'   => [ 'https://*.hotjar.com' ],
            ],
            'intercom'            => [
                'script-src'  => [ 'https://widget.intercom.io', 'https://js.intercomcdn.com' ],
                'connect-src' => [ 'https://api.intercom.io', 'https://api-iam.intercom.io', 'wss://nexus-websocket-a.intercom.io', 'wss://nexus-websocket-b.intercom.io' ],
                'img-src'     => [ 'https://*.intercom.io', 'https://*.intercomcdn.com' ],
                'frame-src'   => [ 'https://intercom-sheets.com' ],
            ],
            'twitter_embeds'      => [
                'script-src'  => [ 'https://platform.twitter.com' ],
                'frame-src'   => [ 'https://platform.twitter.com', 'https://syndication.twitter.com' ],
                'connect-src' => [ 'https://api.twitter.com' ],
                'img-src'     => [ 'https://pbs.twimg.com', 'https://abs.twimg.com' ],
            ],
            'disqus'              => [
                'script-src'  => [ 'https://*.disqus.com', 'https://*.disquscdn.com' ],
                'frame-src'   => [ 'https://disqus.com' ],
                'connect-src' => [ 'https://*.disqus.com' ],
                'img-src'     => [ 'https://*.disquscdn.com', 'https://referrer.disqus.com' ],
            ],
            'woocommerce_payments' => [
                'script-src'  => [ 'https://js.stripe.com', 'https://pay.google.com' ],
                'frame-src'   => [ 'https://js.stripe.com', 'https://hooks.stripe.com', 'https://pay.google.com' ],
                'connect-src' => [ 'https://api.stripe.com' ],
            ],
        ];

        foreach ( $services as $svc ) {
            if ( ! isset( $map[ $svc ] ) ) { continue; }
            foreach ( $map[ $svc ] as $dir => $vals ) {
                if ( ! isset( $d[ $dir ] ) ) { $d[ $dir ] = []; } // initialise new directives
                foreach ( $vals as $v ) {
                    if ( ! in_array( $v, $d[ $dir ], true ) ) { $d[ $dir ][] = $v; }
                }
            }
        }

        $parts = [];
        foreach ( $d as $dir => $vals ) { $parts[] = $dir . ' ' . implode( ' ', $vals ); }
        if ( $custom ) { $parts[] = $custom; }
        return implode( '; ', $parts );
    }

    // ── CSP nonce helpers ─────────────────────────────────────────────────────

    public static function get_csp_nonce(): string {
        if ( self::$csp_nonce === null ) {
            self::$csp_nonce = bin2hex( random_bytes( 16 ) );
        }
        return self::$csp_nonce;
    }

    public static function csp_nonce_script_tag( string $tag ): string {
        $nonce = self::get_csp_nonce();
        // Inject nonce into every <script ...> opening tag that doesn't already have one
        return preg_replace( '/<script(?![^>]*\bnonce\b)/i', '<script nonce="' . esc_attr( $nonce ) . '"', $tag );
    }

    public static function csp_nonce_style_tag( string $tag ): string {
        $nonce = self::get_csp_nonce();
        return preg_replace( '/<link(?![^>]*\bnonce\b)/i', '<link nonce="' . esc_attr( $nonce ) . '"', $tag );
    }

    /** @param array<string,string> $attrs */
    public static function csp_nonce_inline_attrs( array $attrs ): array {
        $attrs['nonce'] = self::get_csp_nonce();
        return $attrs;
    }

    public static function csp_ob_start(): void {
        ob_start( [ __CLASS__, 'csp_ob_inject_nonces' ] );
    }

    /**
     * Output buffer callback: injects the page nonce into every <script> tag
     * that doesn't already have one. Catches AdSense, theme scripts, and any
     * other markup that bypasses wp_enqueue_scripts.
     */
    public static function csp_ob_inject_nonces( string $html ): string {
        $nonce = self::get_csp_nonce();
        if ( ! $nonce ) {
            return $html;
        }
        return preg_replace_callback(
            '/<script(?=[>\s])(?![^>]*\bnonce\s*=)([^>]*)>/i',
            static function ( array $m ) use ( $nonce ): string {
                return '<script nonce="' . esc_attr( $nonce ) . '"' . $m[1] . '>';
            },
            $html
        ) ?? $html;
    }


    public static function render_csp_panel(): void {
        $csp_on          = get_option( 'csdt_devtools_csp_enabled', '0' ) === '1';
        $csp_mode        = get_option( 'csdt_devtools_csp_mode', 'enforce' );
        $reporting_on    = get_option( 'csdt_csp_reporting_enabled', '0' ) === '1';
        $csp_services    = json_decode( get_option( 'csdt_devtools_csp_services', '[]' ), true );
        if ( ! is_array( $csp_services ) ) { $csp_services = []; }
        $csp_custom      = get_option( 'csdt_devtools_csp_custom', '' );
        $csp_backup      = json_decode( get_option( 'csdt_devtools_csp_backup', '' ), true );
        $backup_time     = is_array( $csp_backup ) ? ( $csp_backup['saved_at'] ?? 0 ) : 0;
        $csp_history     = json_decode( get_option( 'csdt_csp_history', '[]' ), true );
        if ( ! is_array( $csp_history ) ) { $csp_history = []; }
        $fixes_log       = json_decode( get_option( 'csdt_csp_fixes_log', '[]' ), true );
        if ( ! is_array( $fixes_log ) ) { $fixes_log = []; }

        $services = [
            'google_analytics'     => 'Google Analytics (GA4 / gtag.js)',
            'google_adsense'       => 'Google AdSense',
            'google_tag_manager'   => 'Google Tag Manager',
            'google_fonts'         => 'Google Fonts',
            'cloudflare_insights'  => 'Cloudflare Web Analytics',
            'facebook_pixel'       => 'Facebook Pixel',
            'recaptcha'            => 'Google reCAPTCHA',
            'youtube'              => 'YouTube embeds',
            'vimeo'                => 'Vimeo embeds',
            'stripe'               => 'Stripe Payments',
            'hotjar'               => 'Hotjar',
            'intercom'             => 'Intercom',
            'twitter_embeds'       => 'Twitter / X embeds',
            'disqus'               => 'Disqus Comments',
            'woocommerce_payments' => 'WooCommerce Payments',
        ];
        ?>
        <hr class="cs-sec-divider">
        <div class="cs-section-header" style="background:linear-gradient(90deg,#2e1065 0%,#3730a3 100%);border-left:3px solid #818cf8;margin-bottom:0;border-radius:6px 6px 0 0;">
            <span>🛡️ <?php esc_html_e( 'Content Security Policy (CSP)', 'cloudscale-devtools' ); ?></span>
            <span class="cs-header-hint"><?php esc_html_e( 'Block unauthorised scripts and resources. Select the services your site uses before enabling.', 'cloudscale-devtools' ); ?></span>
            <?php CloudScale_DevTools::render_explain_btn( 'csp', 'Content Security Policy (CSP)', [
                [ 'name' => 'How to set this up (start here)',  'rec' => 'Critical', 'html' => '<ol style="margin:0;padding-left:18px;line-height:2;"><li>Tick every third-party service your site uses (Google Analytics, AdSense, etc.).</li><li>Select <strong>Report-Only</strong> mode.</li><li>Tick <strong>Enable CSP</strong> and click <strong>Save CSP Settings</strong>.</li><li>Browse your site for a few minutes — visit your homepage, a post, and any page with ads or analytics.</li><li>Come back here and check the <strong>Violation Log</strong> that appears below. It will list anything that <em>would</em> have been blocked.</li><li>If the log shows violations for a service you use, tick that service\'s checkbox and save again. Repeat until the log is clean.</li><li>Once the log is empty (or only shows items you don\'t care about), switch to <strong>Enforce</strong> mode and save. Your CSP is now active.</li></ol><p style="margin:10px 0 0;padding:8px 12px;background:#fef9c3;border-radius:4px;font-size:13px;">⚠️ <strong>Never start in Enforce mode</strong> — you may accidentally block your own scripts and break the site.</p>' ],
                [ 'name' => 'What is a CSP?',               'rec' => 'Info',     'html' => 'A Content Security Policy is an HTTP header that tells the browser which origins are allowed to load scripts, styles, images, and other resources. If an attacker injects a malicious script into your page (XSS), a strong CSP stops the browser from running it. Without a CSP, any injected script executes freely.' ],
                [ 'name' => 'Report-Only vs Enforce',       'rec' => 'Info',     'html' => '<strong>Report-Only</strong> — the browser loads everything normally but logs what <em>would</em> have been blocked. The Violation Log below captures these reports automatically. Safe to enable immediately.<br><br><strong>Enforce</strong> — the browser actively blocks anything not on the allowlist. Switch to this only after the Violation Log is clean.' ],
                [ 'name' => 'Third-Party Services',         'rec' => 'Info',     'html' => 'Each checkbox adds that service\'s domains to the CSP allowlist. <strong>Only tick services you actually use.</strong> In Enforce mode, any unticked service will be blocked — Google Analytics stops recording, AdSense ads disappear, Cloudflare scripts fail silently. If you\'re unsure whether you use something, leave it unticked and check the Violation Log.' ],
                [ 'name' => 'Violation Log',                'rec' => 'Info',     'html' => 'Visible when Report-Only is active. Shows exactly what the browser would block: the blocked resource URL, which CSP directive triggered, and which page of your site caused it. Use this to identify missing services before switching to Enforce. Auto-refreshes every 30 seconds. Click <strong>Clear Log</strong> to reset between test sessions.' ],
                [ 'name' => 'What if Enforce breaks my site?', 'rec' => 'Info',  'html' => 'Click <strong>Rollback to previous settings</strong> — it appears next to Save after every save. This instantly restores your previous configuration. You can also switch back to Report-Only at any time without any side effects.' ],
                [ 'name' => 'Additional Directives',        'rec' => 'Optional', 'html' => 'Advanced — leave blank unless you need it. Appended verbatim to the generated CSP. Common examples: <code>upgrade-insecure-requests</code> (force HTTP sub-resources to load over HTTPS) or <code>block-all-mixed-content</code> (block HTTP content on HTTPS pages).' ],
                [ 'name' => '\'unsafe-inline\' in the AI report', 'rec' => 'Info', 'html' => 'If the AI Cyber Audit flags <code>\'unsafe-inline\'</code>, it\'s because services like Google Analytics and AdSense inject inline scripts that require it. This is a known trade-off — having any CSP is significantly better than none, even with <code>\'unsafe-inline\'</code> present. You can safely ignore this finding if you use those services.' ],
            ],
            'Protects your site against XSS attacks by telling the browser which scripts, styles, and resources are allowed to load. Always start in Report-Only mode to check nothing breaks before switching to Enforce.' ); ?>
        </div>
        <div style="padding:20px;background:#fff;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 6px 6px;margin-bottom:0;" id="cs-csp-panel">

            <!-- ── Site Audit CTA ───────────────────────────────────────── -->
            <div style="background:linear-gradient(135deg,#1e3a8a 0%,#1d4ed8 100%);border-radius:8px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;">
                <div>
                    <div style="font-size:13px;font-weight:700;color:#fff;">🔍 <?php esc_html_e( 'CSP Site Audit', 'cloudscale-devtools' ); ?></div>
                    <div style="font-size:11px;color:#93c5fd;margin-top:2px;"><?php esc_html_e( 'Reads the violation log populated by real visitor traffic in Report-Only mode. Enable CSP + Report-Only, browse your site, then click here.', 'cloudscale-devtools' ); ?></div>
                </div>
                <button type="button" id="cs-csp-audit-btn" style="background:#fff;color:#1e40af;font-size:13px;font-weight:700;padding:9px 20px;border:none;border-radius:7px;cursor:pointer;white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,.2);">
                    🔍 <?php esc_html_e( 'Run Site Audit', 'cloudscale-devtools' ); ?>
                </button>
            </div>

            <!-- ── Site Audit Results — immediately below the button ──────── -->
            <div id="cs-csp-audit-wrap" style="display:none;margin-bottom:18px;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                <div style="background:#1e3a8a;padding:10px 14px;display:flex;align-items:center;justify-content:space-between;gap:8px;">
                    <span style="color:#fff;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">🔍 <?php esc_html_e( 'CSP Site Audit', 'cloudscale-devtools' ); ?></span>
                    <span id="cs-csp-audit-status" style="font-size:11px;color:#93c5fd;"></span>
                </div>
                <div id="cs-csp-audit-body" style="background:#fff;padding:12px 14px;font-size:12px;"></div>
            </div>

            <!-- Quick-start guide — hidden once CSP is enabled -->
            <?php if ( ! $csp_on ) : ?>
            <div id="cs-csp-quickstart" style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:14px 16px;margin-bottom:16px;">
                <p style="margin:0 0 8px;font-size:13px;font-weight:700;color:#0369a1;">⚡ Quick setup — do these steps in order:</p>
                <ol style="margin:0;padding-left:20px;font-size:13px;color:#374151;line-height:1.9;">
                    <li>Tick every service your site uses below (Google Analytics, AdSense, etc.)</li>
                    <li>Select <strong>Report-Only</strong> <em>(not Enforce)</em></li>
                    <li>Tick <strong>Enable CSP</strong> → click <strong>Save CSP Settings</strong></li>
                    <li>Browse your site for a few minutes, then come back and check the <strong>Violation Log</strong></li>
                    <li>Once the log is clean, switch to <strong>Enforce</strong> and save again</li>
                </ol>
            </div>
            <?php endif; ?>

            <!-- Enable + Mode + Reporting toggle -->
            <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;padding:0 2px 14px;border-bottom:1px solid #f1f5f9;margin-bottom:14px;">
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;cursor:pointer;">
                    <input type="checkbox" id="cs-csp-enabled" <?php checked( $csp_on ); ?>>
                    <?php esc_html_e( 'Enable CSP', 'cloudscale-devtools' ); ?>
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;">
                    <input type="radio" name="cs-csp-mode" value="enforce" <?php checked( $csp_mode, 'enforce' ); ?>>
                    <?php esc_html_e( 'Enforce', 'cloudscale-devtools' ); ?>
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;">
                    <input type="radio" name="cs-csp-mode" value="report_only" <?php checked( $csp_mode, 'report_only' ); ?>>
                    <?php esc_html_e( 'Report-Only (test mode)', 'cloudscale-devtools' ); ?>
                </label>
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;cursor:pointer;margin-left:auto;padding:5px 10px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;" title="<?php esc_attr_e( 'Adds report-uri to the CSP header so browsers send violation reports to this plugin. Turn off when you no longer need to monitor.', 'cloudscale-devtools' ); ?>">
                    <input type="checkbox" id="cs-csp-reporting-enabled" <?php checked( $reporting_on ); ?>>
                    <?php esc_html_e( 'Log violations', 'cloudscale-devtools' ); ?>
                </label>
            </div>

            <?php
            $sec_card   = 'margin-top:12px;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff;';
            $sec_header = 'display:flex;align-items:center;gap:10px;padding:10px 14px;background:#f8fafc;border-bottom:1px solid #e2e8f0;cursor:pointer;user-select:none;min-height:44px;';
            $sec_title  = 'font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#475569;flex:1;min-width:0;';
            $sec_toggle = 'cs-details-toggle';
            $sec_body   = 'padding:0;';
            ?>

            <!-- ── Violation Log sub-panel ────────────────────────────── -->
            <div id="cs-csp-violation-wrap" style="<?php echo $csp_on && $reporting_on ? '' : 'display:none;'; ?><?php echo esc_attr( $sec_card ); ?>margin-bottom:14px;">
                <div style="<?php echo esc_attr( $sec_header ); ?>" id="cs-csp-viol-header">
                    <span style="<?php echo esc_attr( $sec_title ); ?>">&#x26A0;&#xFE0F; <?php esc_html_e( 'Violation Log', 'cloudscale-devtools' ); ?> <span id="cs-csp-viol-count" style="display:none;font-weight:700;color:#dc2626;"></span></span>
                    <button type="button" id="cs-csp-viol-refresh" class="cs-btn-secondary cs-btn-sm" style="flex-shrink:0;">↻ <?php esc_html_e( 'Refresh', 'cloudscale-devtools' ); ?></button>
                    <button type="button" id="cs-csp-viol-clear" class="cs-btn-secondary cs-btn-sm" style="border-color:#f87171;color:#dc2626;flex-shrink:0;"><?php esc_html_e( 'Clear', 'cloudscale-devtools' ); ?></button>
                    <span id="cs-csp-viol-chevron" class="cs-details-toggle cs-details-toggle--js"></span>
                </div>
                <div id="cs-csp-viol-body" style="display:none;<?php echo esc_attr( $sec_body ); ?>padding:10px 14px;">
                    <div id="cs-csp-viol-table" style="font-size:12px;"></div>
                    <p style="font-size:11px;color:#94a3b8;margin:6px 0 0;">
                        <?php esc_html_e( 'The browser sends a report for every blocked (or would-be-blocked) resource. Browse your site normally to populate this log.', 'cloudscale-devtools' ); ?>
                    </p>
                </div>
            </div>

            <!-- Service checkboxes -->
            <div style="margin-bottom:14px;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#64748b;margin-bottom:10px;"><?php esc_html_e( 'Third-party services used on this site', 'cloudscale-devtools' ); ?></div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:6px;">
                    <?php foreach ( $services as $key => $label ) : ?>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;padding:7px 10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;cursor:pointer;">
                        <input type="checkbox" class="cs-csp-service" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $csp_services, true ) ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Custom directives -->
            <div style="margin-bottom:14px;">
                <div style="display:flex;align-items:baseline;gap:8px;margin-bottom:6px;">
                    <span style="font-size:13px;font-weight:700;color:#334155;"><?php esc_html_e( 'Custom directives', 'cloudscale-devtools' ); ?></span>
                    <span style="font-size:11px;color:#94a3b8;"><?php esc_html_e( 'appended verbatim to the generated CSP', 'cloudscale-devtools' ); ?></span>
                </div>
                <textarea id="cs-csp-custom" class="cs-text-input" rows="3"
                          style="width:100%;font-family:monospace;font-size:12px;line-height:1.6;resize:vertical;box-sizing:border-box;"
                          placeholder="e.g. upgrade-insecure-requests"><?php echo esc_textarea( $csp_custom ); ?></textarea>
            </div>

            <!-- Live preview -->
            <div style="margin-bottom:14px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                    <span style="font-size:13px;font-weight:700;color:#334155;"><?php esc_html_e( 'Preview', 'cloudscale-devtools' ); ?></span>
                    <button type="button" id="cs-csp-copy-btn" style="background:none;border:1px solid #cbd5e1;color:#64748b;font-size:11px;font-weight:600;padding:3px 10px;border-radius:4px;cursor:pointer;">📋 Copy</button>
                </div>
                <pre id="cs-csp-preview" style="background:#0f172a;color:#e2e8f0;padding:12px;border-radius:6px;font-size:11px;white-space:pre-wrap;word-break:break-all;margin:0;max-height:160px;overflow-y:auto;"></pre>
            </div>

            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <button type="button" id="cs-csp-save-btn" class="cs-btn-primary cs-btn-sm"><?php esc_html_e( 'Save Settings', 'cloudscale-devtools' ); ?></button>
                <span id="cs-csp-saved" style="display:none;color:#16a34a;font-size:13px;font-weight:600;">✓ <?php esc_html_e( 'Saved', 'cloudscale-devtools' ); ?></span>
                <?php if ( $backup_time ) : ?>
                <button type="button" id="cs-csp-rollback-btn" class="cs-btn-secondary cs-btn-sm" style="border-color:#f87171;color:#dc2626;">
                    ↩ <?php esc_html_e( 'Rollback to previous settings', 'cloudscale-devtools' ); ?>
                    <span style="font-weight:400;font-size:11px;opacity:.8;">(<?php echo esc_html( human_time_diff( $backup_time ) . ' ' . __( 'ago', 'cloudscale-devtools' ) ); ?>)</span>
                </button>
                <span id="cs-csp-rolledback" style="display:none;color:#d97706;font-size:13px;font-weight:600;">↩ <?php esc_html_e( 'Rolled back', 'cloudscale-devtools' ); ?></span>
                <?php endif; ?>
            </div>

            <!-- audit results moved above to sit directly below the button -->

            <?php if ( ! empty( $csp_history ) ) : ?>
            <!-- ── Change History ─────────────────────────────────────── -->
            <details id="cs-csp-history-wrap" style="<?php echo esc_attr( $sec_card ); ?>">
                <summary style="<?php echo esc_attr( $sec_header ); ?>list-style:none;">
                    <span style="<?php echo esc_attr( $sec_title ); ?>">&#x1F4CB; <?php echo esc_html( sprintf( __( 'Change History (%d saves)', 'cloudscale-devtools' ), count( $csp_history ) ) ); ?></span>
                    <span class="<?php echo esc_attr( $sec_toggle ); ?>"></span>
                </summary>
                <div style="<?php echo esc_attr( $sec_body ); ?>">
                <?php foreach ( $csp_history as $idx => $entry ) :
                    $ts    = $entry['saved_at'] ?? 0;
                    $label = esc_html( $entry['label'] ?? 'Settings saved' );
                    $age   = $ts ? esc_html( human_time_diff( $ts ) . ' ago' ) : '';
                    $bg    = $idx % 2 === 0 ? '#fff' : '#f8fafc';
                ?>
                    <div style="display:flex;align-items:center;gap:10px;padding:8px 14px;background:<?php echo esc_attr( $bg ); ?>;<?php echo $idx > 0 ? 'border-top:1px solid #e2e8f0;' : ''; ?>">
                        <span style="color:#94a3b8;font-size:11px;white-space:nowrap;min-width:80px;"><?php echo $age; ?></span>
                        <span style="flex:1;font-size:12px;color:#334155;"><?php echo $label; ?></span>
                        <button type="button" class="cs-csp-restore-btn" data-index="<?php echo (int) $idx; ?>"
                                style="background:none;border:1px solid #94a3b8;color:#475569;font-size:11px;font-weight:600;padding:3px 10px;border-radius:4px;cursor:pointer;white-space:nowrap;">↩ Restore</button>
                    </div>
                <?php endforeach; ?>
                </div>
                <div id="cs-csp-restore-msg" style="display:none;padding:6px 14px;font-size:12px;font-weight:600;color:#d97706;"></div>
            </details>
            <?php endif; ?>

            <!-- ── Fixes Applied ─────────────────────────────────────── -->
            <details id="cs-csp-fixes-wrap" style="<?php echo esc_attr( $sec_card ); ?><?php echo empty( $fixes_log ) ? 'display:none;' : ''; ?>">
                <summary style="<?php echo esc_attr( $sec_header ); ?>list-style:none;">
                    <span style="<?php echo esc_attr( $sec_title ); ?>">&#x2705; <?php echo esc_html( sprintf( __( 'CSP Fixes Applied (%d)', 'cloudscale-devtools' ), count( $fixes_log ) ) ); ?></span>
                    <button type="button" id="cs-csp-fixes-clear" class="cs-btn-secondary cs-btn-sm" style="border-color:#f87171;color:#dc2626;flex-shrink:0;" onclick="event.stopPropagation()"><?php esc_html_e( 'Clear', 'cloudscale-devtools' ); ?></button>
                    <span class="<?php echo esc_attr( $sec_toggle ); ?>"></span>
                </summary>
                <div id="cs-csp-fixes-table" style="<?php echo esc_attr( $sec_body ); ?>">
                <?php foreach ( $fixes_log as $i => $fix ) :
                    $ts  = isset( $fix['time'] ) ? human_time_diff( $fix['time'] ) . ' ago' : '';
                    $lbl = esc_html( $fix['label'] ?? 'Settings updated' );
                    $bg  = $i % 2 === 0 ? '#fff' : '#f8fafc';
                ?>
                    <div style="display:flex;align-items:center;gap:10px;padding:7px 14px;background:<?php echo esc_attr( $bg ); ?>;<?php echo $i > 0 ? 'border-top:1px solid #e2e8f0;' : ''; ?>">
                        <span style="color:#94a3b8;font-size:11px;white-space:nowrap;min-width:80px;"><?php echo esc_html( $ts ); ?></span>
                        <span style="flex:1;font-size:12px;color:#15803d;font-weight:600;"><?php echo $lbl; ?></span>
                    </div>
                <?php endforeach; ?>
                </div>
            </details>

        </div>

        <?php
    }

    private static function service_names(): array {
        return [
            'google_analytics'     => 'Google Analytics',
            'google_adsense'       => 'Google AdSense',
            'google_tag_manager'   => 'Google Tag Manager',
            'google_fonts'         => 'Google Fonts',
            'cloudflare_insights'  => 'Cloudflare Insights',
            'facebook_pixel'       => 'Facebook Pixel',
            'recaptcha'            => 'reCAPTCHA',
            'youtube'              => 'YouTube',
            'vimeo'                => 'Vimeo',
            'stripe'               => 'Stripe',
            'hotjar'               => 'Hotjar',
            'intercom'             => 'Intercom',
            'twitter_embeds'       => 'Twitter/X embeds',
            'disqus'               => 'Disqus',
            'woocommerce_payments' => 'WooCommerce Payments',
        ];
    }

    private static function append_csp_history( array $entry ): array {
        $history = json_decode( get_option( 'csdt_csp_history', '[]' ), true );
        if ( ! is_array( $history ) ) { $history = []; }
        array_unshift( $history, $entry );
        update_option( 'csdt_csp_history', wp_json_encode( array_slice( $history, 0, 10 ) ) );
        return $entry;
    }

    private static function append_fixes_log( array $entry ): void {
        $fixes = json_decode( get_option( 'csdt_csp_fixes_log', '[]' ), true );
        if ( ! is_array( $fixes ) ) { $fixes = []; }
        array_unshift( $fixes, $entry );
        update_option( 'csdt_csp_fixes_log', wp_json_encode( array_slice( $fixes, 0, 50 ) ) );
    }

    public static function ajax_csp_save(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }

        $enabled          = isset( $_POST['enabled'] )           ? sanitize_key( wp_unslash( $_POST['enabled'] ) )                             : '0';
        $mode             = isset( $_POST['mode'] )            ? sanitize_key( wp_unslash( $_POST['mode'] ) )                                : 'enforce';
        $services         = isset( $_POST['services'] )        ? json_decode( sanitize_text_field( wp_unslash( $_POST['services'] ) ), true )  : [];
        $custom           = isset( $_POST['custom'] )          ? sanitize_textarea_field( wp_unslash( $_POST['custom'] ) )                   : '';
        $reporting_enabled = isset( $_POST['reporting_enabled'] ) ? sanitize_key( wp_unslash( $_POST['reporting_enabled'] ) )                : '0';

        if ( ! is_array( $services ) ) { $services = []; }
        $services = array_values( array_intersect( $services, array_keys( self::service_names() ) ) );

        $old = [
            'enabled'           => get_option( 'csdt_devtools_csp_enabled', '0' ),
            'mode'              => get_option( 'csdt_devtools_csp_mode', 'enforce' ),
            'services'          => json_decode( get_option( 'csdt_devtools_csp_services', '[]' ), true ),
            'custom'            => get_option( 'csdt_devtools_csp_custom', '' ),
            'reporting_enabled' => get_option( 'csdt_csp_reporting_enabled', '0' ),
        ];
        $new = [ 'enabled' => $enabled, 'mode' => $mode, 'services' => $services, 'custom' => $custom, 'reporting_enabled' => $reporting_enabled ];

        $new_entry = self::append_csp_history( [
            'enabled'           => $old['enabled'],
            'mode'              => $old['mode'],
            'services'          => wp_json_encode( $old['services'] ),
            'custom'            => $old['custom'],
            'reporting_enabled' => $old['reporting_enabled'],
            'saved_at'          => time(),
            'label'             => self::csp_history_label( $old, $new ),
        ] );

        // Single-step rollback backup (legacy).
        update_option( 'csdt_devtools_csp_backup', wp_json_encode( array_merge( $old, [
            'saved_at' => time(),
            'services' => wp_json_encode( $old['services'] ),
        ] ) ) );

        update_option( 'csdt_devtools_csp_enabled',    $enabled === '1' ? '1' : '0' );
        update_option( 'csdt_devtools_csp_mode',       in_array( $mode, [ 'enforce', 'report_only' ], true ) ? $mode : 'enforce' );
        update_option( 'csdt_devtools_csp_services',   wp_json_encode( $services ) );
        update_option( 'csdt_devtools_csp_custom',     $custom );
        update_option( 'csdt_csp_reporting_enabled',   $reporting_enabled === '1' ? '1' : '0' );

        // Log a fix entry whenever new services are added to the allowlist.
        $old_svcs   = is_array( $old['services'] ) ? $old['services'] : (array) json_decode( $old['services'] ?? '[]', true );
        $added_svcs = array_values( array_diff( $services, $old_svcs ) );
        if ( $added_svcs ) {
            $svc_names    = self::service_names();
            $added_labels = array_map( fn( $s ) => $svc_names[ $s ] ?? $s, $added_svcs );
            self::append_fixes_log( [
                'time'     => time(),
                'label'    => 'Added ' . implode( ', ', $added_labels ),
                'services' => $added_svcs,
            ] );
        }

        wp_send_json_success( [
            'has_backup'    => true,
            'history_entry' => [
                'label'    => $new_entry['label'],
                'saved_at' => $new_entry['saved_at'],
            ],
        ] );
    }

    private static function csp_history_label( array $old, array $new ): string {
        $labels   = [];
        $old_svcs = is_array( $old['services'] ) ? $old['services'] : (array) json_decode( $old['services'] ?? '[]', true );
        $new_svcs = is_array( $new['services'] ) ? $new['services'] : (array) json_decode( $new['services'] ?? '[]', true );
        $names    = self::service_names();
        if ( $old['enabled'] !== $new['enabled'] ) {
            $labels[] = $new['enabled'] === '1' ? 'CSP enabled' : 'CSP disabled';
        }
        if ( $old['mode'] !== $new['mode'] ) {
            $labels[] = $new['mode'] === 'report_only' ? 'Switched to report-only' : 'Switched to enforce';
        }
        foreach ( array_diff( $new_svcs, $old_svcs ) as $s ) {
            $labels[] = 'Added ' . ( $names[ $s ] ?? $s );
        }
        foreach ( array_diff( $old_svcs, $new_svcs ) as $s ) {
            $labels[] = 'Removed ' . ( $names[ $s ] ?? $s );
        }
        if ( trim( $old['custom'] ?? '' ) !== trim( $new['custom'] ?? '' ) ) {
            $labels[] = 'Custom directives updated';
        }
        return $labels ? implode( '; ', $labels ) : 'Settings saved';
    }

    public static function ajax_csp_restore(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }

        $idx = isset( $_POST['index'] ) ? (int) wp_unslash( $_POST['index'] ) : -1;
        $history = json_decode( get_option( 'csdt_csp_history', '[]' ), true );
        if ( ! is_array( $history ) || ! isset( $history[ $idx ] ) ) {
            wp_send_json_error( 'History entry not found.' );
        }

        $entry = $history[ $idx ];

        // Push current live state to the top of history before restoring.
        $current_services = json_decode( get_option( 'csdt_devtools_csp_services', '[]' ), true );
        self::append_csp_history( [
            'enabled'           => get_option( 'csdt_devtools_csp_enabled', '0' ),
            'mode'              => get_option( 'csdt_devtools_csp_mode', 'enforce' ),
            'services'          => wp_json_encode( is_array( $current_services ) ? $current_services : [] ),
            'custom'            => get_option( 'csdt_devtools_csp_custom', '' ),
            'reporting_enabled' => get_option( 'csdt_csp_reporting_enabled', '0' ),
            'saved_at'          => time(),
            'label'             => 'Before restore to: ' . ( $entry['label'] ?? 'previous state' ),
        ] );

        $entry_services = json_decode( $entry['services'] ?? '[]', true );
        if ( ! is_array( $entry_services ) ) { $entry_services = []; }

        update_option( 'csdt_devtools_csp_enabled',    $entry['enabled'] ?? '0' );
        update_option( 'csdt_devtools_csp_mode',       $entry['mode']    ?? 'enforce' );
        update_option( 'csdt_devtools_csp_services',   wp_json_encode( $entry_services ) );
        update_option( 'csdt_devtools_csp_custom',     $entry['custom']  ?? '' );
        update_option( 'csdt_csp_reporting_enabled',   $entry['reporting_enabled'] ?? '0' );

        wp_send_json_success( [
            'enabled'           => $entry['enabled']           ?? '0',
            'mode'              => $entry['mode']              ?? 'enforce',
            'services'          => $entry_services,
            'custom'            => $entry['custom']            ?? '',
            'reporting_enabled' => $entry['reporting_enabled'] ?? '0',
        ] );
    }

    public static function ajax_csp_rollback(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }

        $raw = get_option( 'csdt_devtools_csp_backup', '' );
        if ( ! $raw ) { wp_send_json_error( 'No backup available' ); }

        $backup = json_decode( $raw, true );
        if ( ! is_array( $backup ) ) { wp_send_json_error( 'Backup corrupt' ); }

        update_option( 'csdt_devtools_csp_enabled',  $backup['enabled']  ?? '0' );
        update_option( 'csdt_devtools_csp_mode',     $backup['mode']     ?? 'enforce' );
        update_option( 'csdt_devtools_csp_services', $backup['services'] ?? '[]' );
        update_option( 'csdt_devtools_csp_custom',   $backup['custom']   ?? '' );
        delete_option( 'csdt_devtools_csp_backup' );

        wp_send_json_success( [
            'enabled'  => $backup['enabled']  ?? '0',
            'mode'     => $backup['mode']      ?? 'enforce',
            'services' => json_decode( $backup['services'] ?? '[]', true ),
            'custom'   => $backup['custom']    ?? '',
        ] );
    }

    public static function register_csp_report_route(): void {
        register_rest_route( 'csdt/v1', '/csp-report', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_csp_report' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public static function rest_csp_report( \WP_REST_Request $request ): \WP_REST_Response {
        $body = json_decode( $request->get_body(), true );
        $report = $body['csp-report'] ?? null;
        if ( ! is_array( $report ) ) {
            return new \WP_REST_Response( null, 204 );
        }

        $entry = [
            'time'      => time(),
            'blocked'   => isset( $report['blocked-uri'] )          ? (string) $report['blocked-uri']          : '',
            'directive' => isset( $report['violated-directive'] )    ? (string) $report['violated-directive']    : '',
            'page'      => isset( $report['document-uri'] )         ? (string) $report['document-uri']         : '',
            'source'    => isset( $report['source-file'] )          ? (string) $report['source-file']          : '',
            'line'      => isset( $report['line-number'] )          ? (int)    $report['line-number']          : 0,
        ];

        // Skip truly empty reports; keep eval/inline violations — they surface plugin issues
        if ( $entry['blocked'] === '' && $entry['directive'] === '' ) {
            return new \WP_REST_Response( null, 204 );
        }

        $stored = json_decode( get_option( 'csdt_csp_violations', '[]' ), true );
        if ( ! is_array( $stored ) ) { $stored = []; }
        array_unshift( $stored, $entry );
        update_option( 'csdt_csp_violations', wp_json_encode( array_slice( $stored, 0, 100 ) ), false );

        return new \WP_REST_Response( null, 204 );
    }

    public static function ajax_csp_violations_get(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }
        $stored = json_decode( get_option( 'csdt_csp_violations', '[]' ), true );
        wp_send_json_success( is_array( $stored ) ? $stored : [] );
    }

    public static function ajax_csp_violations_clear(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }
        delete_option( 'csdt_csp_violations' );
        wp_send_json_success();
    }

    public static function ajax_csp_fixes_get(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }
        $stored = json_decode( get_option( 'csdt_csp_fixes_log', '[]' ), true );
        wp_send_json_success( is_array( $stored ) ? $stored : [] );
    }

    public static function ajax_csp_fixes_clear(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }
        delete_option( 'csdt_csp_fixes_log' );
        wp_send_json_success();
    }

    public static function ajax_csp_apply_fix(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }

        $type      = isset( $_POST['type'] )      ? sanitize_key( wp_unslash( $_POST['type'] ) )         : '';
        $value     = isset( $_POST['value'] )     ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
        $directive = isset( $_POST['directive'] ) ? sanitize_key( wp_unslash( $_POST['directive'] ) )    : 'script-src';

        if ( ! in_array( $type, [ 'custom', 'service' ], true ) || ! $value ) {
            wp_send_json_error( 'Invalid parameters.' );
        }

        // Snapshot current state for history before any changes.
        $old_enabled   = get_option( 'csdt_devtools_csp_enabled', '0' );
        $old_mode      = get_option( 'csdt_devtools_csp_mode', 'enforce' );
        $old_services  = json_decode( get_option( 'csdt_devtools_csp_services', '[]' ), true );
        if ( ! is_array( $old_services ) ) { $old_services = []; }
        $old_custom    = get_option( 'csdt_devtools_csp_custom', '' );
        $old_reporting = get_option( 'csdt_csp_reporting_enabled', '0' );

        $names        = self::service_names();
        $new_services = $old_services;
        $new_custom   = $old_custom;

        if ( $type === 'service' ) {
            if ( ! isset( $names[ $value ] ) ) {
                wp_send_json_error( 'Unknown service.' );
            }
            if ( in_array( $value, $old_services, true ) ) {
                wp_send_json_success( [ 'already_applied' => true, 'custom' => $old_custom, 'services' => $old_services ] );
            }
            $new_services = array_values( array_unique( array_merge( $old_services, [ $value ] ) ) );
            $fix_label    = 'Fix applied: Added ' . ( $names[ $value ] ?? $value ) . ' to CSP allow-list';
            update_option( 'csdt_devtools_csp_services', wp_json_encode( $new_services ) );
        } else {
            // Allow CSP keyword values (quoted) OR valid origins (https://).
            $is_csp_keyword = preg_match( "/^'(unsafe-inline|unsafe-eval|unsafe-hashes|strict-dynamic|nonce-[a-zA-Z0-9+\/=]+|sha256-[a-zA-Z0-9+\/=]+|sha384-[a-zA-Z0-9+\/=]+|sha512-[a-zA-Z0-9+\/=]+|wasm-unsafe-eval|self|none)'$/", $value );
            $is_valid_origin = preg_match( '/^(https?:)?\/\//', $value );
            if ( ! $is_csp_keyword && ! $is_valid_origin ) {
                wp_send_json_error( 'Invalid CSP value — must be a URL origin (https://example.com) or a CSP keyword (\'unsafe-inline\').' );
            }
            // Escape URL origins, use raw value for CSP keywords.
            $origin     = $is_csp_keyword ? $value : esc_url_raw( $value );
            $new_custom = trim( $old_custom );
            $entry      = $directive . ' ' . $origin;
            // Only append if not already present.
            if ( strpos( $new_custom, $origin ) === false ) {
                $new_custom = $new_custom ? $new_custom . "\n" . $entry : $entry;
            }
            $fix_label = 'Fix applied: Added ' . $origin . ' to custom CSP (' . $directive . ')';
            update_option( 'csdt_devtools_csp_custom', $new_custom );
        }

        self::append_csp_history( [
            'enabled'           => $old_enabled,
            'mode'              => $old_mode,
            'services'          => wp_json_encode( $old_services ),
            'custom'            => $old_custom,
            'reporting_enabled' => $old_reporting,
            'saved_at'          => time(),
            'label'             => $fix_label,
        ] );
        self::append_fixes_log( [ 'time' => time(), 'label' => $fix_label ] );

        wp_send_json_success( [
            'custom'   => $new_custom,
            'services' => $new_services,
            'label'    => $fix_label,
        ] );
    }

}
