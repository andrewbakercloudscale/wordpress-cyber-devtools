<?php
/**
 * Plugin Name: CloudScale Cyber and Devtools
 * Plugin URI: https://andrewbaker.ninja
 * Description: Free AI penetration testing, brute-force protection, 2FA, passkeys, AI site audit, AI debugging, performance monitor, SMTP, SQL tool, server logs, vulnerability scanner, and Cloudflare uptime monitor. No subscription, no cloud dependency.
 * Version: 1.9.759
 * Author: Andrew Baker
 * Author URI: https://andrewbaker.ninja
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: cloudscale-devtools
 *
 * @package CloudScale_DevTools
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-cs-passkey.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ai-dispatcher.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-csp.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-sec-headers.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-site-audit.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-vuln-scan.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-geo.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-login.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-smtp.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-perf-monitor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-custom-404.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-thumbnails.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-optimizer.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-threat-monitor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-monitor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-test-accounts.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-uptime.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-code-migrator.php';

// Enable DB query saving only when CS Monitor is active (avoids memory overhead when disabled).
if ( ! defined( 'SAVEQUERIES' ) && get_option( 'csdt_devtools_perf_monitor_enabled', '1' ) !== '0' ) {
    define( 'SAVEQUERIES', true );
}

/**
 * CloudScale Code Block — main plugin class.
 *
 * Handles block registration, shortcode, admin tools, settings,
 * the code block migration tool, and the SQL command tool.
 *
 * @package CloudScale_DevTools
 * @since   1.0.0
 */
class CloudScale_DevTools {

    const VERSION      = '1.9.759';
    const HLJS_VERSION = '11.11.1';
    const HLJS_CDN     = 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/';
    const TOOLS_SLUG   = 'cloudscale-devtools';

    // Nonce action strings — single source of truth used across all extracted classes.
    const MIGRATE_NONCE      = 'csdt_devtools_code_migrate_action';
    const SECURITY_NONCE     = 'csdt_devtools_security_nonce';
    const OPTIMIZER_NONCE    = 'csdt_optimizer_nonce';
    const LOGS_NONCE         = 'csdt_devtools_server_logs';
    const SQL_NONCE          = 'csdt_devtools_sql_nonce';
    const DEBUG_NONCE        = 'csdt_debug_nonce';
    const FPM_NONCE          = 'csdt_fpm_nonce';
    const SITE_AUDIT_NONCE   = 'csdt_site_audit_nonce';
    const PERF_NONCE         = 'csdt_devtools_perf_monitor_nonce';
    const LOGIN_NONCE        = 'csdt_devtools_login_nonce';
    const SMTP_NONCE         = 'csdt_devtools_smtp_nonce';

    // Auth transient key prefixes (suffixed at call-site).
    const LOGIN_2FA_TRANSIENT    = 'csdt_devtools_2fa_pending_';
    const LOGIN_OTP_TRANSIENT    = 'csdt_devtools_2fa_otp_';
    const EMAIL_VERIFY_TRANSIENT = 'csdt_devtools_email_verify_';
    const TOTP_CHARS             = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // Option / REST constants.
    const CUSTOM_404_OPTION  = 'csdt_devtools_custom_404';
    const SCHEME_404_OPTION  = 'csdt_devtools_404_scheme';
    const HISCORE_NS         = 'csdt-devtools/v1';
    const SCORE_NONCE_ACTION = 'csdt_devtools_score_post';

    /**
     * Returns the theme registry mapping slugs to CDN filenames and colour values.
     *
     * Each entry maps a slug to its dark and light CDN filenames,
     * display label, and background colours for the wrapper/toolbar.
     *
     * @since  1.7.0
     * @return array<string, array<string, string>>
     */
    public static function get_theme_registry(): array {
        return [
            'atom-one' => [
                'label'        => 'Atom One',
                'dark_css'     => 'atom-one-dark',
                'light_css'    => 'atom-one-light',
                'dark_bg'      => '#282c34',
                'dark_toolbar' => '#21252b',
                'light_bg'     => '#fafafa',
                'light_toolbar'=> '#e8eaed',
            ],
            'github' => [
                'label'        => 'GitHub',
                'dark_css'     => 'github-dark',
                'light_css'    => 'github',
                'dark_bg'      => '#24292e',
                'dark_toolbar' => '#1f2428',
                'light_bg'     => '#fff',
                'light_toolbar'=> '#f6f8fa',
            ],
            'monokai' => [
                'label'        => 'Monokai',
                'dark_css'     => 'monokai',
                'light_css'    => 'atom-one-light',
                'dark_bg'      => '#272822',
                'dark_toolbar' => '#1e1f1c',
                'light_bg'     => '#fafafa',
                'light_toolbar'=> '#e8eaed',
            ],
            'nord' => [
                'label'        => 'Nord',
                'dark_css'     => 'nord',
                'light_css'    => 'atom-one-light',
                'dark_bg'      => '#2e3440',
                'dark_toolbar' => '#272c36',
                'light_bg'     => '#fafafa',
                'light_toolbar'=> '#e8eaed',
            ],
            'dracula' => [
                'label'        => 'Dracula',
                'dark_css'     => 'dracula',
                'light_css'    => 'atom-one-light',
                'dark_bg'      => '#282a36',
                'dark_toolbar' => '#21222c',
                'light_bg'     => '#fafafa',
                'light_toolbar'=> '#e8eaed',
            ],
            'tokyo-night' => [
                'label'        => 'Tokyo Night',
                'dark_css'     => 'tokyo-night-dark',
                'light_css'    => 'tokyo-night-light',
                'dark_bg'      => '#1a1b26',
                'dark_toolbar' => '#16161e',
                'light_bg'     => '#d5d6db',
                'light_toolbar'=> '#c8c9ce',
            ],
            'vs2015' => [
                'label'        => 'VS 2015 / VS Code',
                'dark_css'     => 'vs2015',
                'light_css'    => 'vs',
                'dark_bg'      => '#1e1e1e',
                'dark_toolbar' => '#181818',
                'light_bg'     => '#fff',
                'light_toolbar'=> '#f3f3f3',
            ],
            'stackoverflow' => [
                'label'        => 'Stack Overflow',
                'dark_css'     => 'stackoverflow-dark',
                'light_css'    => 'stackoverflow-light',
                'dark_bg'      => '#1c1b1b',
                'dark_toolbar' => '#151414',
                'light_bg'     => '#f6f6f6',
                'light_toolbar'=> '#e8e8e8',
            ],
            'night-owl' => [
                'label'        => 'Night Owl',
                'dark_css'     => 'night-owl',
                'light_css'    => 'atom-one-light',
                'dark_bg'      => '#011627',
                'dark_toolbar' => '#001122',
                'light_bg'     => '#fafafa',
                'light_toolbar'=> '#e8eaed',
            ],
            'gruvbox' => [
                'label'        => 'Gruvbox',
                'dark_css'     => 'base16/gruvbox-dark-hard',
                'light_css'    => 'base16/gruvbox-light-hard',
                'dark_bg'      => '#1d2021',
                'dark_toolbar' => '#171819',
                'light_bg'     => '#f9f5d7',
                'light_toolbar'=> '#ece8c8',
            ],
            'solarized' => [
                'label'        => 'Solarized',
                'dark_css'     => 'base16/solarized-dark',
                'light_css'    => 'base16/solarized-light',
                'dark_bg'      => '#002b36',
                'dark_toolbar' => '#002530',
                'light_bg'     => '#fdf6e3',
                'light_toolbar'=> '#eee8d5',
            ],
            'panda' => [
                'label'        => 'Panda',
                'dark_css'     => 'panda-syntax-dark',
                'light_css'    => 'panda-syntax-light',
                'dark_bg'      => '#292a2b',
                'dark_toolbar' => '#222324',
                'light_bg'     => '#e6e6e6',
                'light_toolbar'=> '#d9d9d9',
            ],
            'tomorrow' => [
                'label'        => 'Tomorrow Night',
                'dark_css'     => 'tomorrow-night-bright',
                'light_css'    => 'atom-one-light',
                'dark_bg'      => '#000',
                'dark_toolbar' => '#0a0a0a',
                'light_bg'     => '#fafafa',
                'light_toolbar'=> '#e8eaed',
            ],
            'shades-of-purple' => [
                'label'        => 'Shades of Purple',
                'dark_css'     => 'shades-of-purple',
                'light_css'    => 'atom-one-light',
                'dark_bg'      => '#2d2b55',
                'dark_toolbar' => '#252347',
                'light_bg'     => '#fafafa',
                'light_toolbar'=> '#e8eaed',
            ],
        ];
    }

    private static $instance_count  = 0;
    private static $assets_enqueued = false;

    /**
     * Returns the real client IP, respecting Cloudflare and proxy headers.
     */
    public static function get_client_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $candidate = sanitize_text_field( wp_unslash( explode( ',', $_SERVER[ $key ] )[0] ) );
                if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                    return $candidate;
                }
            }
        }
        return '';
    }

    /**
     * Registers a WP-Cron action with a Throwable safety wrapper.
     *
     * An uncaught exception inside a cron callback causes PHP-FPM to SIGSEGV,
     * taking the entire worker pool down. This wrapper catches any Throwable,
     * logs it, and returns cleanly so the worker survives.
     */
    private static function cron_action( string $hook, callable $callback ): void {
        add_action( $hook, static function () use ( $hook, $callback ): void {
            try {
                $callback();
            } catch ( \Throwable $e ) {
                error_log( sprintf(
                    '[CSDT] cron "%s" exception (%s): %s in %s line %d',
                    $hook,
                    get_class( $e ),
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ) );
            }
        } );
    }

    /**
     * Registers all plugin hooks.
     *
     * @since  1.0.0
     * @return void
     */
    public static function init() {
        self::maybe_migrate_autoload();
        CSDT_SMTP::maybe_migrate_prefix();
        CSDT_SMTP::maybe_migrate_smtp_prefix();
        CSDT_SMTP::maybe_migrate_usermeta_prefix();
        add_filter( 'xmlrpc_enabled', '__return_false' );

        // One-click security hardening — option-driven filters applied at every boot
        if ( get_option( 'csdt_block_basic_auth', '0' ) === '1' ) {
            // Hard block — no app passwords / Basic Auth for anyone, including test accounts
            add_filter( 'wp_is_application_passwords_available', '__return_false' );
        } elseif ( get_option( 'csdt_devtools_disable_app_passwords', '0' ) === '1' ) {
            if ( get_option( 'csdt_test_accounts_enabled', '0' ) === '1' ) {
                // Test-account mode: block per-user (not site-wide) so test accounts still authenticate
                add_filter( 'wp_is_application_passwords_available_for_user', [ 'CSDT_Test_Accounts', 'filter_app_pw_for_user' ], 10, 2 );
            } else {
                add_filter( 'wp_is_application_passwords_available', '__return_false' );
            }
        }
        // Test-account cleanup cron + single-use hook (always when feature is enabled)
        if ( get_option( 'csdt_test_accounts_enabled', '0' ) === '1' ) {
            add_action( 'application_password_did_authenticate', [ 'CSDT_Test_Accounts', 'test_account_after_auth' ], 10, 2 );
        }
        if ( get_option( 'csdt_devtools_hide_wp_version', '0' ) === '1' ) {
            remove_action( 'wp_head', 'wp_generator' );
            add_filter( 'the_generator', '__return_empty_string' );
            // Strip ?ver= query strings from enqueued scripts/styles to prevent version fingerprinting
            add_filter( 'style_loader_src',  [ 'CSDT_Thumbnails', 'strip_asset_ver' ], 9999 );
            add_filter( 'script_loader_src', [ 'CSDT_Thumbnails', 'strip_asset_ver' ], 9999 );
        }

        add_action( 'init', [ __CLASS__, 'load_textdomain' ] );
        add_action( 'init', [ __CLASS__, 'register_block' ] );
        add_action( 'init', [ __CLASS__, 'register_shortcode' ] );
        add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_convert_script' ] );
        add_action( 'admin_menu', [ __CLASS__, 'add_tools_page' ] );
        add_action( 'wp_dashboard_setup', [ __CLASS__, 'register_dashboard_widget' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_init',       [ __CLASS__, 'redirect_legacy_slug' ] );
        add_action( 'init', [ __CLASS__, 'redirect_legacy_help_url' ], 1 );
        add_action( 'init', [ __CLASS__, 'maybe_lscache_purge' ], 1 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );

        // Migration AJAX
        add_action( 'wp_ajax_csdt_devtools_migrate_scan', [ 'CSDT_Code_Migrator', 'ajax_scan' ] );
        add_action( 'wp_ajax_csdt_devtools_migrate_preview', [ 'CSDT_Code_Migrator', 'ajax_preview' ] );
        add_action( 'wp_ajax_csdt_devtools_migrate_single', [ 'CSDT_Code_Migrator', 'ajax_migrate_single' ] );
        add_action( 'wp_ajax_csdt_devtools_migrate_all', [ 'CSDT_Code_Migrator', 'ajax_migrate_all' ] );

        // SQL AJAX
        add_action( 'wp_ajax_csdt_devtools_sql_run', [ __CLASS__, 'ajax_sql_run' ] );

        // Settings AJAX
        add_action( 'wp_ajax_csdt_devtools_save_theme_setting',  [ 'CSDT_Code_Migrator', 'ajax_save_theme_setting' ] );
        add_action( 'wp_ajax_csdt_devtools_save_perf_monitor',   [ 'CSDT_Code_Migrator', 'ajax_save_perf_monitor' ] );

        // Login security AJAX
        add_action( 'wp_ajax_csdt_devtools_login_save',          [ 'CSDT_Login', 'ajax_login_save' ] );
        add_action( 'wp_ajax_csdt_devtools_bf_log_fetch',        [ 'CSDT_Login', 'ajax_bf_log_fetch' ] );
        add_action( 'wp_ajax_csdt_ip_block',                     [ 'CSDT_Login', 'ajax_ip_block' ] );
        add_action( 'wp_ajax_csdt_ip_unblock',                   [ 'CSDT_Login', 'ajax_ip_unblock' ] );
        add_action( 'wp_ajax_csdt_download_dbip',                [ 'CSDT_Geo', 'ajax_download_dbip' ] );
        add_action( 'wp_ajax_csdt_save_dbip_settings',           [ 'CSDT_Geo', 'ajax_save_dbip_settings' ] );
        self::cron_action( 'csdt_dbip_auto_update',              [ 'CSDT_Geo', 'auto_update_run' ] );
        if ( ! wp_next_scheduled( 'csdt_dbip_auto_update' ) ) {
            wp_schedule_event( time(), 'daily', 'csdt_dbip_auto_update' );
        }
        add_action( 'parse_request',                             [ 'CSDT_Login', 'enforce_ip_blocklist' ], 1 );
        add_action( 'wp_ajax_csdt_ssh_monitor_save',             [ 'CSDT_Monitor', 'ajax_ssh_monitor_save' ] );
        add_action( 'wp_ajax_csdt_ssh_log_clear',               [ 'CSDT_Monitor', 'ajax_ssh_log_clear' ] );
        add_action( 'wp_ajax_csdt_ssh_fix_permissions',         [ 'CSDT_Monitor', 'ajax_ssh_fix_permissions' ] );
        add_action( 'wp_ajax_csdt_csp_domain_hunt',              [ __CLASS__, 'ajax_csp_domain_hunt' ] );
        add_action( 'wp_ajax_csdt_bf_self_test',                [ 'CSDT_Threat_Monitor', 'ajax_bf_self_test' ] );
        add_action( 'wp_ajax_csdt_devtools_totp_setup_start',    [ 'CSDT_Login', 'ajax_totp_setup_start' ] );
        add_action( 'wp_ajax_csdt_devtools_totp_setup_verify',   [ 'CSDT_Login', 'ajax_totp_setup_verify' ] );
        add_action( 'wp_ajax_csdt_devtools_2fa_disable',         [ 'CSDT_Login', 'ajax_2fa_disable' ] );
        add_action( 'wp_ajax_csdt_devtools_email_2fa_enable',    [ 'CSDT_Login', 'ajax_email_2fa_enable' ] );
        add_action( 'admin_init',           [ 'CSDT_Login', 'email_2fa_confirm_check' ] );
        add_action( 'after_password_reset', [ 'CSDT_Login', 'on_password_reset' ], 10, 1 );
        add_action( 'profile_update',       [ 'CSDT_Login', 'on_profile_update' ], 10, 2 );
        CSDT_DevTools_Passkey::register_hooks();

        // Default Featured Image
        add_action( 'wp_ajax_csdt_save_default_image',     [ 'CSDT_Thumbnails', 'ajax_save_default_image' ] );
        add_filter( 'post_thumbnail_html',                  [ 'CSDT_Thumbnails', 'default_image_html' ], 10, 5 );
        add_filter( 'has_post_thumbnail',                   [ 'CSDT_Thumbnails', 'default_image_has_thumbnail' ], 10, 3 );
        // Hero image: swap to 1200×630 social format on single post pages.
        add_filter( 'post_thumbnail_html',                  [ 'CSDT_Thumbnails', 'hero_image_html' ], 11, 5 );
        add_action( 'wp_enqueue_scripts',                   [ 'CSDT_Thumbnails', 'enqueue_hero_styles' ] );
        // Admin-only Generate Featured Image button (injected via footer, positioned by JS).
        add_action( 'wp_footer',          [ 'CSDT_Thumbnails', 'inject_gen_image_button' ], 5 );
        add_action( 'wp_enqueue_scripts', [ 'CSDT_Thumbnails', 'enqueue_frontend_admin_scripts' ] );

        // Thumbnails / Social Preview AJAX
        add_action( 'wp_ajax_csdt_devtools_social_check_url',   [ 'CSDT_Thumbnails', 'ajax_social_check_url' ] );
        add_action( 'wp_ajax_csdt_devtools_social_scan_posts',  [ 'CSDT_Thumbnails', 'ajax_social_scan_posts' ] );
        add_action( 'wp_ajax_csdt_devtools_social_scan_media',  [ 'CSDT_Thumbnails', 'ajax_social_scan_media' ] );
        add_action( 'wp_ajax_csdt_devtools_social_fix_image',      [ 'CSDT_Thumbnails', 'ajax_social_fix_image' ] );
        add_action( 'wp_ajax_csdt_devtools_social_generate_formats', [ 'CSDT_Thumbnails', 'ajax_social_generate_formats' ] );
        add_action( 'wp_ajax_csdt_devtools_social_platform_save',    [ 'CSDT_Thumbnails', 'ajax_social_platform_save' ] );
        add_action( 'wp_ajax_csdt_devtools_social_fix_all_batch',          [ 'CSDT_Thumbnails', 'ajax_social_fix_all_batch' ] );
        add_action( 'wp_ajax_csdt_devtools_social_refresh_stale_batch',    [ 'CSDT_Thumbnails', 'ajax_social_refresh_stale_batch' ] );
        add_action( 'wp_ajax_csdt_devtools_social_diagnose_formats', [ 'CSDT_Thumbnails', 'ajax_social_diagnose_formats' ] );
        add_action( 'wp_ajax_csdt_devtools_regen_thumb_scan',  [ 'CSDT_Thumbnails', 'ajax_regen_thumb_scan' ] );
        add_action( 'wp_ajax_csdt_devtools_regen_thumb_batch', [ 'CSDT_Thumbnails', 'ajax_regen_thumb_batch' ] );
        add_action( 'save_post_post',  [ 'CSDT_Thumbnails', 'on_post_saved' ], 100, 3 );
        // Catches the "publish first, add image later" workflow where set_post_thumbnail()
        // fires after save_post and on_post_saved() misses the new thumbnail ID.
        add_action( 'added_post_meta',   [ 'CSDT_Thumbnails', 'on_thumbnail_meta_updated' ], 10, 4 );
        add_action( 'updated_post_meta', [ 'CSDT_Thumbnails', 'on_thumbnail_meta_updated' ], 10, 4 );
        add_action( 'transition_post_status', [ 'CSDT_Thumbnails', 'on_post_status_change' ], 10, 3 );
        add_action( 'admin_notices',   [ 'CSDT_Thumbnails', 'social_format_admin_notice' ] );
        // Serve platform-specific og:image based on crawler User-Agent.
        add_action( 'wp_head', [ 'CSDT_Thumbnails', 'output_crawler_og_image' ], 1 );
        add_action( 'wp_ajax_csdt_devtools_social_cf_test',     [ 'CSDT_Thumbnails', 'ajax_social_cf_test' ] );
        add_action( 'wp_ajax_csdt_devtools_cf_purge',           [ 'CSDT_Thumbnails', 'ajax_cf_purge' ] );
        add_action( 'wp_ajax_csdt_devtools_cf_save',            [ 'CSDT_Thumbnails', 'ajax_cf_save' ] );
        add_action( 'wp_ajax_csdt_devtools_ai_image_save_key',      [ 'CSDT_Thumbnails', 'ajax_ai_image_save_key' ] );
        add_action( 'wp_ajax_csdt_devtools_ai_image_test_key',     [ 'CSDT_Thumbnails', 'ajax_ai_image_test_key' ] );
        add_action( 'wp_ajax_csdt_devtools_ai_image_scan',          [ 'CSDT_Thumbnails', 'ajax_ai_image_scan' ] );
        add_action( 'wp_ajax_csdt_devtools_ai_image_write_prompt',  [ 'CSDT_Thumbnails', 'ajax_ai_image_write_prompt' ] );
        add_action( 'wp_ajax_csdt_devtools_ai_image_generate',      [ 'CSDT_Thumbnails', 'ajax_ai_image_generate' ] );
        add_action( 'wp_ajax_csdt_devtools_ai_image_pick',          [ 'CSDT_Thumbnails', 'ajax_ai_image_pick' ] );
        add_action( 'wp_ajax_csdt_devtools_ai_image_discard',       [ 'CSDT_Thumbnails', 'ajax_ai_image_discard' ] );
        add_action( 'wp_ajax_csdt_devtools_ai_image_save_sysprompt', [ 'CSDT_Thumbnails', 'ajax_ai_image_save_sysprompt' ] );
        add_action( 'wp_ajax_csdt_devtools_ai_image_save_settings',  [ 'CSDT_Thumbnails', 'ajax_ai_image_save_settings' ] );

        // SMTP AJAX
        add_action( 'wp_ajax_csdt_devtools_smtp_save',      [ 'CSDT_SMTP', 'ajax_smtp_save' ] );
        add_action( 'wp_ajax_csdt_devtools_smtp_test',      [ 'CSDT_SMTP', 'ajax_smtp_test' ] );
        add_action( 'wp_ajax_csdt_devtools_smtp_log_clear', [ 'CSDT_SMTP', 'ajax_smtp_log_clear' ] );
        add_action( 'wp_ajax_csdt_devtools_smtp_log_fetch', [ 'CSDT_SMTP', 'ajax_smtp_log_fetch' ] );
        add_action( 'wp_ajax_csdt_devtools_smtp_log_view',  [ 'CSDT_SMTP', 'ajax_smtp_log_view' ] );

        add_action( 'wp_ajax_csdt_devtools_vuln_scan',          [ 'CSDT_Vuln_Scan', 'ajax_vuln_scan' ] );
        add_action( 'wp_ajax_csdt_devtools_deep_scan',          [ 'CSDT_Site_Audit', 'ajax_deep_scan' ] );
        add_action( 'wp_ajax_csdt_devtools_adhoc_scan',         [ 'CSDT_Site_Audit', 'ajax_adhoc_scan' ] );
        add_action( 'wp_ajax_csdt_devtools_adhoc_delete',       [ 'CSDT_Site_Audit', 'ajax_adhoc_delete' ] );
        add_action( 'wp_ajax_csdt_devtools_scan_status',        [ 'CSDT_Site_Audit', 'ajax_scan_status' ] );
        add_action( 'wp_ajax_csdt_devtools_cancel_scan',        [ 'CSDT_Site_Audit', 'ajax_cancel_scan' ] );
        add_action( 'wp_ajax_csdt_devtools_vuln_save_key',      [ 'CSDT_Vuln_Scan', 'ajax_vuln_save_key' ] );
        add_action( 'wp_ajax_csdt_devtools_security_test_key',  [ 'CSDT_Vuln_Scan', 'ajax_security_test_key' ] );
        add_action( 'wp_ajax_csdt_devtools_server_logs_status',     [ __CLASS__, 'ajax_server_logs_status' ] );
        add_action( 'wp_ajax_csdt_devtools_server_logs_fetch',      [ __CLASS__, 'ajax_server_logs_fetch' ] );
        add_action( 'wp_ajax_csdt_devtools_logs_setup_php',         [ __CLASS__, 'ajax_logs_setup_php' ] );
        add_action( 'wp_ajax_csdt_devtools_logs_fix_mu_perms',      [ __CLASS__, 'ajax_logs_fix_mu_perms' ] );
        add_action( 'wp_ajax_csdt_devtools_logs_custom_save',       [ __CLASS__, 'ajax_logs_custom_save' ] );
        add_action( 'wp_ajax_csdt_devtools_scan_history',       [ 'CSDT_Site_Audit', 'ajax_scan_history' ] );
        add_action( 'wp_ajax_csdt_devtools_save_schedule',      [ 'CSDT_Site_Audit', 'ajax_save_schedule' ] );
        add_action( 'wp_ajax_csdt_devtools_save_notify',        [ 'CSDT_Site_Audit', 'ajax_save_notify' ] );
        add_action( 'wp_ajax_csdt_sec_headers_restore',         [ 'CSDT_Security_Headers', 'ajax_sec_headers_restore' ] );
        add_action( 'wp_ajax_csdt_devtools_quick_fix',          [ 'CSDT_Site_Audit', 'ajax_apply_quick_fix' ] );
        add_action( 'wp_ajax_csdt_db_prefix_preflight',         [ 'CSDT_Site_Audit', 'ajax_db_prefix_preflight' ] );
        add_action( 'wp_ajax_csdt_db_prefix_migrate',           [ 'CSDT_Site_Audit', 'ajax_db_prefix_migrate' ] );
        add_action( 'wp_ajax_csdt_db_prefix_rollback',          [ 'CSDT_Site_Audit', 'ajax_db_prefix_rollback' ] );
        add_action( 'wp_ajax_csdt_db_orphaned_scan',            [ 'CSDT_Optimizer', 'ajax_db_orphaned_scan' ] );
        add_action( 'wp_ajax_csdt_db_identify_table',           [ 'CSDT_Optimizer', 'ajax_db_identify_table' ] );
        add_action( 'wp_ajax_csdt_db_archive_tables',           [ 'CSDT_Optimizer', 'ajax_db_archive_tables' ] );
        add_action( 'wp_ajax_csdt_db_trash_scan',               [ 'CSDT_Optimizer', 'ajax_db_trash_scan' ] );
        add_action( 'wp_ajax_csdt_db_restore_tables',           [ 'CSDT_Optimizer', 'ajax_db_restore_tables' ] );
        add_action( 'wp_ajax_csdt_db_drop_tables',              [ 'CSDT_Optimizer', 'ajax_db_drop_tables' ] );
        add_action( 'wp_ajax_csdt_sec_headers_save',            [ 'CSDT_Security_Headers', 'ajax_sec_headers_save' ] );
        add_action( 'wp_ajax_csdt_devtools_csp_save',           [ 'CSDT_CSP', 'ajax_csp_save' ] );
        add_action( 'wp_ajax_csdt_devtools_csp_rollback',       [ 'CSDT_CSP', 'ajax_csp_rollback' ] );
        add_action( 'wp_ajax_csdt_devtools_csp_restore',        [ 'CSDT_CSP', 'ajax_csp_restore' ] );
        add_action( 'wp_ajax_csdt_scan_headers',                 [ 'CSDT_Security_Headers', 'ajax_scan_headers' ] );
        add_action( 'wp_ajax_csdt_scan_history_item',            [ 'CSDT_Security_Headers', 'ajax_scan_history_item' ] );
        add_action( 'wp_ajax_csdt_devtools_csp_violations_get',  [ 'CSDT_CSP', 'ajax_csp_violations_get' ] );
        add_action( 'wp_ajax_csdt_devtools_csp_violations_clear', [ 'CSDT_CSP', 'ajax_csp_violations_clear' ] );
        add_action( 'wp_ajax_csdt_devtools_csp_fixes_get',        [ 'CSDT_CSP', 'ajax_csp_fixes_get' ] );
        add_action( 'wp_ajax_csdt_devtools_csp_fixes_clear',      [ 'CSDT_CSP', 'ajax_csp_fixes_clear' ] );
        add_action( 'wp_ajax_csdt_devtools_csp_apply_fix',        [ 'CSDT_CSP', 'ajax_csp_apply_fix' ] );
        add_action( 'send_headers',                             [ 'CSDT_CSP', 'output_security_headers' ] );
        add_action( 'wp_ajax_csdt_test_account_create',          [ 'CSDT_Test_Accounts', 'ajax_create_test_account' ] );
        add_action( 'wp_ajax_csdt_test_account_revoke',          [ 'CSDT_Test_Accounts', 'ajax_revoke_test_account' ] );
        add_action( 'wp_ajax_csdt_test_account_settings_save',   [ 'CSDT_Test_Accounts', 'ajax_save_test_account_settings' ] );
        self::cron_action( 'csdt_cleanup_test_accounts',         [ 'CSDT_Test_Accounts', 'cleanup_expired_test_accounts' ] );
        add_action( 'wp_ajax_csdt_playwright_role_create',       [ 'CSDT_Test_Accounts', 'ajax_create_playwright_role' ] );
        add_action( 'wp_ajax_csdt_playwright_role_delete',       [ 'CSDT_Test_Accounts', 'ajax_delete_playwright_role' ] );
        add_action( 'wp_ajax_csdt_kill_test_sessions',           [ 'CSDT_Test_Accounts', 'ajax_kill_test_sessions' ] );
        add_action( 'wp_ajax_csdt_regen_test_secret',            [ 'CSDT_Test_Accounts', 'ajax_regen_test_secret' ] );
        add_action( 'wp_ajax_csdt_toggle_block_basic_auth',      [ 'CSDT_Test_Accounts', 'ajax_toggle_block_basic_auth' ] );
        add_action( 'rest_api_init',                             [ 'CSDT_Test_Accounts', 'register_rest_routes' ] );
        self::cron_action( 'csdt_scheduled_scan',                [ 'CSDT_Site_Audit', 'run_scheduled_scan' ] );
        self::cron_action( 'csdt_ssh_monitor',                  [ 'CSDT_Monitor', 'monitor_ssh_failures' ] );
        self::cron_action( 'csdt_php_error_monitor',            [ 'CSDT_Monitor', 'monitor_php_errors' ] );
        add_action( 'wp_ajax_csdt_php_error_monitor_save',      [ 'CSDT_Monitor', 'ajax_php_error_monitor_save' ] );
        add_action( 'wp_ajax_csdt_fpm_monitor_save',             [ 'CSDT_Monitor', 'ajax_fpm_monitor_save' ] );
        add_action( 'wp_ajax_csdt_fpm_worker_status',            [ 'CSDT_Monitor', 'ajax_fpm_worker_status' ] );
        add_action( 'wp_ajax_csdt_fpm_setup_detect',             [ 'CSDT_Monitor', 'ajax_fpm_setup_detect' ] );
        add_action( 'wp_ajax_csdt_fpm_setup_patch',              [ 'CSDT_Monitor', 'ajax_fpm_setup_patch' ] );
        add_action( 'wp_ajax_csdt_fpm_worker_detail',            [ 'CSDT_Monitor', 'ajax_fpm_worker_detail' ] );
        add_action( 'wp_ajax_csdt_opcache_stats',                 [ 'CSDT_Monitor', 'ajax_opcache_stats' ] );
        add_action( 'wp_ajax_csdt_opcache_flush',                 [ 'CSDT_Monitor', 'ajax_opcache_flush' ] );
        add_action( 'wp_ajax_nopriv_csdt_opcache_flush',          [ 'CSDT_Monitor', 'ajax_opcache_flush' ] );
        add_action( 'wp_ajax_csdt_lscache_purge',                 [ 'CSDT_Monitor', 'ajax_lscache_purge' ] );
        add_action( 'wp_ajax_nopriv_csdt_lscache_purge',          [ 'CSDT_Monitor', 'ajax_lscache_purge' ] );
        add_action( 'wp_ajax_csdt_sql_http_fix',                  [ __CLASS__, 'ajax_sql_http_fix' ] );
        // FPM report uses the REST endpoint csdt/v1/fpm-report (CSDT_Monitor::rest_fpm_report).
        self::cron_action( 'csdt_threat_monitor',               [ 'CSDT_Threat_Monitor', 'monitor_threats' ] );
        add_action( 'wp_ajax_csdt_threat_monitor_save',         [ 'CSDT_Threat_Monitor', 'ajax_threat_monitor_save' ] );
        add_action( 'wp_ajax_csdt_threat_integrity_reset',      [ 'CSDT_Threat_Monitor', 'ajax_threat_integrity_reset' ] );
        add_action( 'user_register',                            [ 'CSDT_Threat_Monitor', 'on_user_registered' ] );
        add_action( 'set_user_role',                            [ 'CSDT_Threat_Monitor', 'on_set_user_role' ], 10, 3 );
        add_filter( 'cron_schedules',                           [ 'CSDT_Site_Audit', 'add_cron_schedules' ] );
        add_action( 'wp_ajax_csdt_plugin_stack_scan',           [ 'CSDT_Optimizer', 'ajax_plugin_stack_scan' ] );
        add_action( 'wp_ajax_csdt_ai_debug_log',                [ 'CSDT_Optimizer', 'ajax_ai_debug_log' ] );
        add_action( 'wp_ajax_csdt_site_audit',                  [ 'CSDT_Site_Audit', 'ajax_site_audit' ] );
        add_action( 'wp_ajax_csdt_update_risk_scan',            [ 'CSDT_Optimizer', 'ajax_update_risk_scan' ] );
        add_action( 'wp_ajax_csdt_update_risk_assess',          [ 'CSDT_Optimizer', 'ajax_update_risk_assess' ] );
        add_action( 'wp_ajax_csdt_db_intelligence_scan',        [ 'CSDT_Optimizer', 'ajax_db_intelligence_scan' ] );
        add_action( 'wp_ajax_csdt_db_intelligence_fix',         [ 'CSDT_Optimizer', 'ajax_db_intelligence_fix' ] );
        add_action( 'wp_ajax_nopriv_csdt_uptime_ping',          [ 'CSDT_Uptime', 'ajax_uptime_ping' ] );
        add_action( 'wp_ajax_csdt_uptime_ping',                 [ 'CSDT_Uptime', 'ajax_uptime_ping' ] );
        add_action( 'wp_ajax_csdt_uptime_setup',                [ 'CSDT_Uptime', 'ajax_uptime_setup' ] );
        add_action( 'wp_ajax_csdt_uptime_history',              [ 'CSDT_Uptime', 'ajax_uptime_history' ] );
        add_action( 'wp_ajax_csdt_uptime_deploy_worker',        [ 'CSDT_Uptime', 'ajax_uptime_deploy_worker' ] );
        add_action( 'wp_ajax_csdt_uptime_save_settings',        [ 'CSDT_Uptime', 'ajax_uptime_save_settings' ] );
        add_action( 'wp_ajax_csdt_uptime_test_endpoint',        [ 'CSDT_Uptime', 'ajax_uptime_test_endpoint' ] );
        add_action( 'wp_ajax_csdt_uptime_pause_heartbeat',      [ 'CSDT_Uptime', 'ajax_uptime_pause_heartbeat' ] );
        self::cron_action( 'csdt_uptime_heartbeat',             [ 'CSDT_Uptime', 'push_heartbeat' ] );
        add_filter( 'cron_schedules',                           [ 'CSDT_Uptime', 'add_cron_schedules' ] );
        // Ensure heartbeat cron is scheduled whenever uptime is enabled
        if ( get_option( 'csdt_uptime_enabled', '0' ) === '1'
             && get_option( 'csdt_uptime_worker_url', '' ) !== ''
             && ! wp_next_scheduled( 'csdt_uptime_heartbeat' ) ) {
            wp_schedule_event( time() + 5, 'csdt_minutely', 'csdt_uptime_heartbeat' );
        }
        add_action( 'admin_bar_menu',                           [ 'CSDT_Uptime', 'render_admin_bar_badge' ], 100 );
        add_action( 'admin_enqueue_scripts',                    [ 'CSDT_Uptime', 'admin_bar_badge_styles' ] );
        add_action( 'wp_enqueue_scripts',                       [ 'CSDT_Uptime', 'admin_bar_badge_styles' ] );

        // CSP nonce injection — only active when nonce mode is enabled
        if ( ! is_admin() && get_option( 'csdt_csp_nonces_enabled', '0' ) === '1' ) {
            add_filter( 'script_loader_tag',          [ 'CSDT_CSP', 'csp_nonce_script_tag' ], 10, 1 );
            add_filter( 'style_loader_tag',           [ 'CSDT_CSP', 'csp_nonce_style_tag' ],  10, 1 );
            // WP 6.3+ inline script attributes filter
            add_filter( 'wp_inline_script_attributes', [ 'CSDT_CSP', 'csp_nonce_inline_attrs' ], 10, 1 );
            // Output buffer to catch scripts that bypass wp_enqueue (AdSense, theme inline scripts, etc.)
            add_action( 'template_redirect', [ 'CSDT_CSP', 'csp_ob_start' ], 0 );
        }

        // Schedule SSH monitor (default on) — ensure cron is running if enabled
        if ( get_option( 'csdt_ssh_monitor_enabled', '1' ) === '1' ) {
            if ( ! wp_next_scheduled( 'csdt_ssh_monitor' ) ) {
                wp_schedule_event( time() + 60, 'csdt_every_1min', 'csdt_ssh_monitor' );
            }
        } else {
            wp_clear_scheduled_hook( 'csdt_ssh_monitor' );
        }

        // Schedule PHP error monitor (default on)
        if ( get_option( 'csdt_php_error_monitor_enabled', '1' ) === '1' ) {
            if ( ! wp_next_scheduled( 'csdt_php_error_monitor' ) ) {
                wp_schedule_event( time() + 300, 'csdt_every_5min', 'csdt_php_error_monitor' );
            }
        } else {
            wp_clear_scheduled_hook( 'csdt_php_error_monitor' );
        }

        // Schedule threat monitor (default on)
        if ( get_option( 'csdt_threat_monitor_enabled', '1' ) === '1' ) {
            if ( ! wp_next_scheduled( 'csdt_threat_monitor' ) ) {
                wp_schedule_event( time() + 300, 'csdt_every_5min', 'csdt_threat_monitor' );
            }
        } else {
            wp_clear_scheduled_hook( 'csdt_threat_monitor' );
        }

        self::cron_action( 'csdt_devtools_run_vuln_scan', [ 'CSDT_Vuln_Scan', 'cron_vuln_scan' ] );
        self::cron_action( 'csdt_devtools_run_deep_scan', [ 'CSDT_Site_Audit', 'cron_deep_scan' ] );

        // Email log — always active so every wp_mail() call is tracked site-wide,
        // regardless of whether our SMTP is enabled.
        add_filter( 'wp_mail',        [ 'CSDT_SMTP', 'smtp_log_capture' ] );
        add_action( 'wp_mail_failed', [ 'CSDT_SMTP', 'smtp_log_on_failure' ] );
        // Priority 5 so it runs before phpmailer_configure (priority 10) and sets action_function first.
        add_action( 'phpmailer_init', [ 'CSDT_SMTP', 'smtp_log_set_callback' ], 5 );

        // SMTP — configure phpmailer and override from address only when fully configured.
        // Guard: if host is empty we skip configuration entirely so other plugins' emails
        // continue to work via PHP mail() rather than silently failing.
        if ( get_option( 'csdt_devtools_smtp_enabled', '0' ) === '1'
            && '' !== trim( (string) get_option( 'csdt_devtools_smtp_host', '' ) )
        ) {
            add_action( 'phpmailer_init', [ 'CSDT_SMTP', 'phpmailer_configure' ] );
            if ( get_option( 'csdt_devtools_smtp_from_email', '' ) ) {
                add_filter( 'wp_mail_from',      [ 'CSDT_SMTP', 'smtp_from_email' ] );
            }
            if ( get_option( 'csdt_devtools_smtp_from_name', '' ) ) {
                add_filter( 'wp_mail_from_name', [ 'CSDT_SMTP', 'smtp_from_name' ] );
            }
        }

        // Login security — URL intercept / 2FA flow (early, priority 1 on init).
        add_action( 'init',        [ 'CSDT_Login', 'login_admin_intercept' ], 0 );
        add_action( 'init',        [ 'CSDT_Login', 'login_serve_custom_slug' ], 1 );
        add_action( 'login_init',  [ 'CSDT_Login', 'login_redirect_authenticated' ], 0 );
        add_action( 'login_init',  [ 'CSDT_Login', 'login_block_direct_access' ], 1 );
        add_filter( 'auth_cookie_expiration', [ 'CSDT_Login', 'login_session_expiration' ], 10, 3 );
        add_action( 'login_init',  [ 'CSDT_Login', 'login_2fa_handle' ] );
        add_filter( 'authenticate',        [ 'CSDT_Login', 'login_2fa_intercept' ], 100, 3 );
        add_filter( 'login_url',           [ 'CSDT_Login', 'login_custom_url' ], 10, 3 );
        add_filter( 'logout_url',          [ 'CSDT_Login', 'login_custom_logout_url' ], 10, 2 );
        add_filter( 'lostpassword_url',    [ 'CSDT_Login', 'login_custom_lostpassword_url' ], 10, 2 );
        add_filter( 'network_site_url',    [ 'CSDT_Login', 'login_custom_network_url' ], 10, 3 );
        add_filter( 'site_url',            [ 'CSDT_Login', 'login_custom_site_url' ], 10, 4 );

        // Brute-force protection — check before authentication (priority 1, before password check).
        add_filter( 'authenticate',    [ 'CSDT_Login', 'login_brute_force_check' ], 1, 3 );
        // Force persistent cookie when a custom session duration is configured.
        // Must be login_init (fires before the POST is processed) not login_form_login
        // (which is a display hook that never fires on a successful login POST).
        add_action( 'login_init', [ 'CSDT_Login', 'login_force_remember' ], 5 );
        // Security monitor — always track failed logins regardless of monitor toggle.
        add_action( 'wp_login_failed', [ 'CSDT_Perf_Monitor', 'perf_track_failed_login' ] );
        // ntfy alerts for failed logins and REST API auth failures.
        add_action( 'wp_login_failed', [ 'CSDT_Login', 'on_login_failed' ] );
        add_action( 'application_password_failed_authentication', [ 'CSDT_Login', 'on_rest_auth_failed' ] );
        // Style the login error panel.
        add_action( 'login_enqueue_scripts', [ 'CSDT_Login', 'login_error_styles' ] );
        // Username enumeration protection — only register if option is enabled (default on).
        if ( get_option( 'csdt_devtools_enum_protect', '1' ) === '1' ) {
            add_filter( 'wp_login_errors', [ 'CSDT_Login', 'generic_login_errors' ] );
        }

        // Custom 404 page + hiscore leaderboard.
        add_action( 'template_redirect',                        [ 'CSDT_Custom_404', 'maybe_custom_404' ], 1 );
        add_action( 'rest_api_init',                            [ 'CSDT_Custom_404', 'register_hiscore_routes' ] );
        add_action( 'rest_api_init',                            [ 'CSDT_CSP', 'register_csp_report_route' ] );
        add_action( 'rest_api_init',                            [ 'CSDT_Monitor', 'register_fpm_report_route' ] );
        add_action( 'rest_api_init',                            [ 'CSDT_Uptime', 'register_rest_routes' ] );
        add_action( 'wp_ajax_csdt_devtools_save_404_settings',    [ 'CSDT_Custom_404', 'ajax_save_404_settings' ] );

        // Performance monitor — EXPLAIN endpoint.
        add_action( 'wp_ajax_csdt_devtools_perf_explain',       [ 'CSDT_Perf_Monitor', 'ajax_perf_explain' ] );
        add_action( 'wp_ajax_csdt_devtools_perf_debug_toggle',  [ 'CSDT_Perf_Monitor', 'ajax_perf_debug_toggle' ] );

        // Performance monitor — only register data-collection hooks when the monitor is enabled.
        // This prevents SAVEQUERIES-scale memory accumulation on every request when disabled.
        if ( get_option( 'csdt_devtools_perf_monitor_enabled', '1' ) !== '0' ) {
            add_filter( 'pre_http_request', [ 'CSDT_Perf_Monitor', 'perf_http_before' ], 10, 3 );
            add_action( 'http_api_debug',   [ 'CSDT_Perf_Monitor', 'perf_http_after' ],  10, 5 );

            // If the user enabled debug logging via the panel, activate PHP error logging
            // using ini_set — this works regardless of WP_DEBUG in wp-config.php and
            // survives Docker container rebuilds because the setting lives in the DB.
            if ( get_option( 'csdt_devtools_perf_debug_logging', false ) ) {
                // phpcs:ignore WordPress.PHP.IniSet.Risky
                @ini_set( 'log_errors', '1' );
                // phpcs:ignore WordPress.PHP.IniSet.Risky
                @ini_set( 'error_log', WP_CONTENT_DIR . '/debug.log' );
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
                error_reporting( E_ALL );
            }

            // Register error handler late (priority 9999 on plugins_loaded) so we sit
            // on top of any handler registered by other plugins (e.g. Query Monitor).
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
            add_action( 'plugins_loaded', function () {
                CSDT_Perf_Monitor::$perf_prev_error_handler = set_error_handler(
                    [ 'CSDT_Perf_Monitor', 'perf_error_handler' ],
                    E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED
                );
            }, 9999 );

            // Performance monitor — panel rendering (admin pages).
            add_action( 'admin_enqueue_scripts', [ 'CSDT_Perf_Monitor', 'perf_enqueue' ] );
            // Inject JSON data at priority 15 — before wp_print_footer_scripts (priority 20) so
            // cs-perf-monitor.js reads window.csdtDevtoolsPerfData when its IIFE runs.
            add_action( 'admin_footer', [ 'CSDT_Perf_Monitor', 'perf_inject_data' ],   15 );
            add_action( 'admin_footer', [ 'CSDT_Perf_Monitor', 'perf_output_panel' ], 9999 );

            // Performance monitor — panel rendering (frontend, admin users only).
            add_action( 'wp_enqueue_scripts', [ 'CSDT_Perf_Monitor', 'perf_frontend_enqueue' ] );
            add_action( 'wp_footer', [ 'CSDT_Perf_Monitor', 'perf_inject_data' ],   15 );
            add_action( 'wp_footer', [ 'CSDT_Perf_Monitor', 'perf_output_panel' ], 9999 );

            // Capture the active template filename for the page-context strip.
            add_filter( 'template_include', [ 'CSDT_Perf_Monitor', 'perf_capture_template' ], 9999 );

            // Hook timing tracker — fires on every action/filter.
            add_action( 'all', [ 'CSDT_Perf_Monitor', 'perf_hook_tracker' ] );

            // Transient + template hierarchy observer (single all-hook for both).
            add_action( 'all',                      [ 'CSDT_Perf_Monitor', 'perf_misc_tracker' ] );
            add_action( 'setted_transient',         [ 'CSDT_Perf_Monitor', 'perf_transient_set' ] );
            add_action( 'setted_site_transient',    [ 'CSDT_Perf_Monitor', 'perf_transient_set' ] );
            add_action( 'deleted_transient',        [ 'CSDT_Perf_Monitor', 'perf_transient_delete' ] );
            add_action( 'deleted_site_transient',   [ 'CSDT_Perf_Monitor', 'perf_transient_delete' ] );

            // Scripts & styles — collect at footer time (after everything is enqueued).
            add_action( 'admin_footer', [ 'CSDT_Perf_Monitor', 'perf_capture_assets' ], 1 );
            add_action( 'wp_footer',    [ 'CSDT_Perf_Monitor', 'perf_capture_assets' ], 1 );

            // Request lifecycle milestones for the waterfall timeline.
            // Registered at PHP_INT_MAX so we capture the time after all other
            // callbacks on that hook have finished running.
            foreach ( [
                'plugins_loaded'    => 'Plugins loaded',
                'init'              => 'WP init',
                'admin_init'        => 'Admin init',
                'wp_loaded'         => 'WP loaded',
                'wp'                => 'Query setup',
                'template_redirect' => 'Template',
            ] as $_ms_hook => $_ms_label ) {
                add_action( $_ms_hook, static function () use ( $_ms_label ) {
                    CSDT_Perf_Monitor::perf_record_milestone( $_ms_label );
                }, PHP_INT_MAX );
            }
        }
    }

    /* ==================================================================
       0a. ONE-TIME MIGRATIONS
       ================================================================== */

    private static function maybe_migrate_autoload(): void {
        global $wpdb;
        $done_key = 'csdt_autoload_migrated_v1';
        if ( get_option( $done_key ) ) {
            return;
        }
        $names = [
            'csdt_uptime_enabled', 'csdt_uptime_worker_url',
            'csdt_ssh_monitor_enabled', 'csdt_ssh_monitor_threshold',
            'csdt_php_error_monitor_enabled', 'csdt_php_error_monitor_threshold',
            'csdt_threat_monitor_enabled', 'csdt_threat_file_integrity_enabled',
            'csdt_threat_new_admin_enabled', 'csdt_threat_probe_enabled', 'csdt_threat_probe_threshold',
            'csdt_devtools_ai_provider', 'csdt_devtools_anthropic_key', 'csdt_devtools_gemini_key',
            'csdt_devtools_security_model', 'csdt_devtools_deep_scan_model', 'csdt_devtools_security_prompt',
        ];
        $placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
        $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "UPDATE {$wpdb->options} SET autoload = 'yes' WHERE option_name IN ({$placeholders}) AND autoload != 'yes'",
            ...$names
        ) );
        add_option( $done_key, '1', '', 'yes' );
    }

    /* ==================================================================
       0. TEXT DOMAIN
       ================================================================== */

    /**
     * Loads the plugin text domain for translations.
     *
     * @since  1.0.0
     * @return void
     */
    public static function load_textdomain(): void {
        load_plugin_textdomain(
            'cloudscale-devtools',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );
    }

    /* ==================================================================
       1. BLOCK REGISTRATION
       ================================================================== */

    /**
     * Registers the block type and all its scripts and stylesheets.
     *
     * @since  1.0.0
     * @return void
     */
    public static function register_block() {
        // Serve hljs and its theme CSS from the plugin's own /assets/ directory so
        // they are not blocked by a strict CSP that does not allow cdnjs.cloudflare.com.
        $assets = plugins_url( 'assets/', __FILE__ );

        wp_register_script(
            'hljs-core',
            $assets . 'highlight.min.js',
            [],
            self::HLJS_VERSION,
            true
        );

        // Register both theme stylesheets from the selected pair
        $pair_slug = get_option( 'csdt_devtools_code_theme_pair', 'atom-one' );
        $registry  = self::get_theme_registry();
        $pair      = isset( $registry[ $pair_slug ] ) ? $registry[ $pair_slug ] : $registry['atom-one'];

        wp_register_style(
            'hljs-theme-dark',
            $assets . 'hljs-' . $pair['dark_css'] . '.min.css',
            [],
            self::HLJS_VERSION
        );
        wp_register_style(
            'hljs-theme-light',
            $assets . 'hljs-' . $pair['light_css'] . '.min.css',
            [],
            self::HLJS_VERSION
        );

        wp_register_style(
            'csdt-code-block-frontend',
            plugins_url( 'assets/cs-code-block.css', __FILE__ ),
            [ 'hljs-theme-dark', 'hljs-theme-light' ],
            self::VERSION
        );

        wp_register_script(
            'csdt-code-block-frontend',
            plugins_url( 'assets/cs-code-block.js', __FILE__ ),
            [ 'hljs-core' ],
            self::VERSION,
            true
        );

        wp_register_style(
            'csdt-code-block-editor',
            plugins_url( 'assets/cs-code-block-editor.css', __FILE__ ),
            [],
            self::VERSION
        );

        wp_register_script(
            'cloudscale-code-block-editor-script',
            plugins_url( 'blocks/code/editor.js', __FILE__ ),
            [ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-data', 'wp-hooks' ],
            self::VERSION,
            true
        );

        register_block_type(
            __DIR__ . '/blocks/code',
            [
                'render_callback' => [ __CLASS__, 'render_block' ],
                'editor_script'   => 'cloudscale-code-block-editor-script',
            ]
        );
    }

    /* ==================================================================
       1b. CONVERT SCRIPT
       ================================================================== */

    /**
     * Enqueues the block editor auto-convert script and attaches the toast inline style.
     *
     * @since  1.5.0
     * @return void
     */
    public static function enqueue_convert_script() {
        wp_enqueue_script(
            'csdt-code-block-convert',
            plugins_url( 'assets/cs-convert.js', __FILE__ ),
            [ 'wp-blocks', 'wp-data' ],
            self::VERSION,
            true
        );
        wp_add_inline_style( 'csdt-code-block-editor', self::get_convert_toast_css() );
    }

    /**
     * Returns the CSS string for the block editor convert-all toast notification.
     *
     * @since  1.7.17
     * @return string
     */

    private static function get_log_viewer_css(): string {
        return '.cs-log-src-btn.status-ok{border-color:#22c55e;color:#22c55e}' .
               '.cs-log-src-btn.status-not-found{border-color:#555;color:#555}' .
               '.cs-log-src-btn.status-permission-denied{border-color:#f59e0b;color:#f59e0b}' .
               '.cs-log-src-btn.status-empty{border-color:#6366f1;color:#6366f1}' .
               '.cs-log-src-btn.active{background:rgba(74,158,255,.15);border-color:#4a9eff;color:#4a9eff}' .
               '.cs-log-line{padding:1px 0;white-space:pre-wrap;word-break:break-all;border-bottom:1px solid rgba(255,255,255,.03)}' .
               '.cs-log-line.level-emerg,.cs-log-line.level-alert,.cs-log-line.level-crit{color:#ff6b6b}' .
               '.cs-log-line.level-error{color:#f87171}' .
               '.cs-log-line.level-warn{color:#fbbf24}' .
               '.cs-log-line.level-notice{color:#a78bfa}' .
               '.cs-log-line.level-info{color:#60a5fa}' .
               '.cs-log-line.level-debug{color:#6b7280}' .
               '.cs-log-line.level-default{color:#c9d1d9}';
    }

    private static function get_dashboard_widget_css(): string {
        return
            /* Dark header band — rendered as HTML inside the widget body */
            '#csdt_security_summary .cs-dw-header{background:linear-gradient(135deg,#1a0a02 0%,#5c2500 60%,#1a0a02 100%);margin:-1px -12px 14px;padding:12px 14px 10px;border-bottom:1px solid rgba(200,110,30,0.35);display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}' .
            '#csdt_security_summary .cs-dw-header-left{display:flex;align-items:center;gap:8px}' .
            '#csdt_security_summary .cs-dw-header-title{color:#e2e8f0;font-size:13px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;line-height:1.2}' .
            '#csdt_security_summary .cs-dw-header-sub{color:#94a3b8;font-size:10px;margin-top:2px;line-height:1.3}' .
            '#csdt_security_summary .cs-dw-header-right{display:flex;gap:6px;align-items:center;flex-shrink:0}' .
            '#csdt_security_summary .cs-dw-hpill{display:inline-flex;flex-direction:column;align-items:center;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);border-radius:8px;padding:4px 10px;min-width:44px;text-align:center}' .
            '#csdt_security_summary .cs-dw-hpill-num{font-size:16px;font-weight:800;line-height:1.1}' .
            '#csdt_security_summary .cs-dw-hpill-lbl{font-size:8px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;margin-top:1px;opacity:.75}' .
            '#csdt_security_summary .cs-dw-bar{display:none}' .

            /* Hero score block */
            '#csdt_security_summary .cs-dw-hero{display:flex;align-items:center;gap:14px;padding:4px 0 16px;border-bottom:1px solid #e2e8f0}' .
            '#csdt_security_summary .cs-dw-score-ring{width:62px;height:62px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:21px;font-weight:800;flex-shrink:0;border:3px solid currentColor}' .
            '#csdt_security_summary .cs-dw-hero-meta{flex:1;min-width:0}' .
            '#csdt_security_summary .cs-dw-hero-title{font-size:13px;font-weight:700;color:#1e293b;line-height:1.2}' .
            '#csdt_security_summary .cs-dw-hero-sub{font-size:11px;color:#94a3b8;margin-top:2px}' .
            '#csdt_security_summary .cs-dw-hero-pills{display:flex;gap:6px;margin-top:8px}' .
            '#csdt_security_summary .cs-dw-pill{display:flex;flex-direction:column;align-items:center;justify-content:center;border-radius:8px;padding:5px 10px;font-weight:700;line-height:1}' .
            '#csdt_security_summary .cs-dw-pill-num{font-size:18px}' .
            '#csdt_security_summary .cs-dw-pill-lbl{font-size:9px;text-transform:uppercase;letter-spacing:.06em;margin-top:2px;opacity:.8}' .

            /* Section header */
            '#csdt_security_summary .cs-dw-section{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748b;padding:12px 0 6px;display:flex;align-items:center;gap:6px}' .
            '#csdt_security_summary .cs-dw-section::after{content:"";flex:1;height:1px;background:#e2e8f0}' .

            /* Status grid */
            '#csdt_security_summary .cs-dw-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:4px}' .
            '#csdt_security_summary .cs-dw-chip{display:flex;align-items:center;gap:7px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:7px 9px;font-size:12px;font-weight:600;color:#334155}' .
            '#csdt_security_summary .cs-dw-chip .cs-dw-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}' .
            '#csdt_security_summary .cs-dw-chip-label{font-size:10px;color:#94a3b8;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' .

            /* Timestamps row */
            '#csdt_security_summary .cs-dw-times{display:flex;gap:10px;margin-top:4px}' .
            '#csdt_security_summary .cs-dw-time{flex:1;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:5px 8px;font-size:10px;color:#94a3b8;text-align:center}' .
            '#csdt_security_summary .cs-dw-time strong{display:block;font-size:11px;color:#475569;font-weight:600}' .

            /* CTA button */
            '#csdt_security_summary .cs-dw-cta{margin-top:14px}' .
            '#csdt_security_summary .cs-dw-cta a{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;box-sizing:border-box;' .
            'background:linear-gradient(135deg,#0ea5e9 0%,#0369a1 100%);color:#fff;font-weight:700;font-size:13px;' .
            'padding:11px 16px;border-radius:8px;text-decoration:none;' .
            'box-shadow:0 2px 8px rgba(14,165,233,.35);letter-spacing:.03em;transition:filter .15s,box-shadow .15s}' .
            '#csdt_security_summary .cs-dw-cta a:hover{filter:brightness(1.1);box-shadow:0 6px 20px rgba(3,105,161,.45)}';
    }

    private static function get_convert_toast_css(): string {
        return '#cs-convert-all-toast{'
            . 'position:fixed;bottom:24px;right:24px;z-index:999999;'
            . 'background:linear-gradient(135deg,#1e3a5f 0%,#0d9488 100%);'
            . 'color:#fff;padding:16px 20px;border-radius:10px;'
            . 'box-shadow:0 8px 32px rgba(0,0,0,0.3);'
            . 'display:flex;align-items:center;gap:16px;'
            . 'font-size:14px;font-weight:500;'
            . 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
            . 'animation:cs-toast-in 0.3s ease-out;'
            . '}'
            . '#cs-convert-all-toast button{'
            . 'background:#fff;color:#1e3a5f;font-weight:700;border-radius:6px;'
            . 'padding:10px 24px;font-size:14px;border:none;white-space:nowrap;'
            . 'cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,0.15);font-family:inherit;'
            . '}'
            . '#cs-convert-all-toast button:hover{background:#f0fdf4;}'
            . '@keyframes cs-toast-in{'
            . 'from{opacity:0;transform:translateY(20px);}'
            . 'to{opacity:1;transform:translateY(0);}'
            . '}';
    }

    /* ==================================================================
       2. RENDER (shared by block + shortcode)
       ================================================================== */

    /**
     * Renders a code block on the frontend.
     *
     * @since  1.0.0
     * @param  array  $attributes    Block attributes.
     * @param  string $block_content Existing block content (unused).
     * @return string HTML output.
     */
    public static function render_block( $attributes, $block_content = '' ) {
        self::maybe_enqueue_frontend();
        self::$instance_count++;

        $id    = 'cs-code-' . self::$instance_count;
        $code  = isset( $attributes['content'] )  ? $attributes['content'] : '';
        $lang  = isset( $attributes['language'] ) ? $attributes['language'] : '';
        $title = isset( $attributes['title'] )    ? $attributes['title']    : '';
        $theme = isset( $attributes['theme'] )    ? $attributes['theme']    : '';

        return self::build_html( $id, $code, $lang, $title, $theme );
    }

    /**
     * Builds the full HTML markup for a code block.
     *
     * @since  1.0.0
     * @param  string $id    Unique HTML element ID.
     * @param  string $code  Code content to display.
     * @param  string $lang  Language identifier for highlight.js, or empty for auto-detect.
     * @param  string $title Optional filename or title label.
     * @param  string $theme Per-block colour-theme override slug, or empty for site default.
     * @return string HTML markup.
     */
    private static function build_html( $id, $code, $lang, $title, $theme ) {
        $lang_class = $lang ? 'language-' . esc_attr( $lang ) : '';

        $cloudscale_link = '<a class="cs-code-brand" href="https://andrewbaker.ninja/2026/02/27/building-a-better-code-block-for-wordpress-cloudscale-code-block-plugin/" target="_blank" rel="noopener noreferrer"><span class="cs-brand-bolt">&#9889;</span> Powered by CloudScale</a>';

        $title_html = '';
        if ( $title ) {
            $title_html = '<div class="cs-code-title">' . esc_html( $title ) . '</div>';
        }

        ob_start();
        ?>
        <div class="cs-code-wrapper" id="<?php echo esc_attr( $id ); ?>"<?php if ( $theme ) { echo ' data-theme="' . esc_attr( $theme ) . '"'; } ?>>
            <div class="cs-code-toolbar">
                <?php echo wp_kses_post( $cloudscale_link ); ?>
                <?php echo wp_kses_post( $title_html ); ?>
                <div class="cs-code-actions">
                    <span class="cs-code-lang-badge"></span>
                    <button class="cs-code-lines-toggle" title="<?php esc_attr_e( 'Toggle line numbers', 'cloudscale-devtools' ); ?>" aria-label="<?php esc_attr_e( 'Toggle line numbers', 'cloudscale-devtools' ); ?>">
                        <svg class="cs-icon-lines" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><text x="4" y="7" font-size="7" fill="currentColor" stroke="none" font-family="monospace">1</text><text x="4" y="13" font-size="7" fill="currentColor" stroke="none" font-family="monospace">2</text><text x="4" y="19" font-size="7" fill="currentColor" stroke="none" font-family="monospace">3</text></svg>
                    </button>
                    <button class="cs-code-theme-toggle" title="<?php esc_attr_e( 'Toggle light/dark mode', 'cloudscale-devtools' ); ?>" aria-label="<?php esc_attr_e( 'Toggle theme', 'cloudscale-devtools' ); ?>">
                        <svg class="cs-icon-sun" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                        <svg class="cs-icon-moon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    </button>
                    <button class="cs-code-copy" title="<?php esc_attr_e( 'Copy to clipboard', 'cloudscale-devtools' ); ?>" aria-label="<?php esc_attr_e( 'Copy code', 'cloudscale-devtools' ); ?>">
                        <svg class="cs-icon-copy" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        <svg class="cs-icon-check" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        <span class="cs-copy-label"><?php esc_html_e( 'Copy', 'cloudscale-devtools' ); ?></span>
                    </button>
                </div>
            </div>
            <div class="cs-code-body">
                <pre><code class="<?php echo esc_attr( $lang_class ); ?>"><?php echo str_replace( [ '[', ']' ], [ '&#91;', '&#93;' ], esc_html( $code ) ); ?></code></pre>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Enqueues frontend scripts and styles on first block render, then localises config.
     *
     * @since  1.0.0
     * @return void
     */
    private static function maybe_enqueue_frontend() {
        if ( self::$assets_enqueued ) {
            return;
        }
        self::$assets_enqueued = true;

        wp_enqueue_style( 'hljs-theme-dark' );
        wp_enqueue_style( 'hljs-theme-light' );
        wp_enqueue_style( 'csdt-code-block-frontend' );
        wp_enqueue_script( 'hljs-core' );
        wp_enqueue_script( 'csdt-code-block-frontend' );

        $default_theme = get_option( 'csdt_devtools_code_default_theme', 'dark' );
        $pair_slug     = get_option( 'csdt_devtools_code_theme_pair', 'atom-one' );
        $registry      = self::get_theme_registry();
        $pair          = isset( $registry[ $pair_slug ] ) ? $registry[ $pair_slug ] : $registry['atom-one'];

        wp_localize_script( 'csdt-code-block-frontend', 'csdtDevtoolsCodeConfig', [
            'defaultTheme'  => $default_theme,
            'themePair'     => $pair_slug,
            'darkBg'        => $pair['dark_bg'],
            'darkToolbar'   => $pair['dark_toolbar'],
            'lightBg'       => $pair['light_bg'],
            'lightToolbar'  => $pair['light_toolbar'],
        ] );
    }

    /* ==================================================================
       3. SHORTCODE [csdt_devtools_code]
       ================================================================== */

    /**
     * Registers the [csdt_devtools_code] shortcode.
     *
     * @since  1.0.0
     * @return void
     */
    public static function register_shortcode() {
        add_shortcode( 'csdt_devtools_code', [ __CLASS__, 'render_shortcode' ] );
    }

    /**
     * Renders the [csdt_devtools_code] shortcode.
     *
     * @since  1.0.0
     * @param  array       $atts    Shortcode attributes.
     * @param  string|null $content Shortcode content.
     * @return string HTML output.
     */
    public static function render_shortcode( $atts, $content = null ) {
        $atts = shortcode_atts( [
            'lang'  => '',
            'theme' => '',
            'title' => '',
        ], $atts, 'csdt_devtools_code' );

        $code = self::decode_shortcode_content( $content );

        return self::render_block( [
            'content'  => $code,
            'language' => $atts['lang'],
            'title'    => $atts['title'],
            'theme'    => $atts['theme'],
        ] );
    }

    /**
     * Decodes WordPress-mangled HTML entities and line breaks from shortcode content.
     *
     * @since  1.0.0
     * @param  string|null $content Raw shortcode content.
     * @return string Plain-text code with entities decoded.
     */
    private static function decode_shortcode_content( $content ) {
        $content = preg_replace( '#^<p>|</p>$#i', '', trim( $content ) );
        $content = str_replace(
            [ '<br />', '<br/>', '<br>', '&#8220;', '&#8221;', '&#8216;', '&#8217;', '&nbsp;', '&#038;' ],
            [ "\n", "\n", "\n", '"', '"', "'", "'", ' ', '&' ],
            $content
        );
        $content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
        return trim( $content );
    }

    /* ==================================================================
       4. SETTINGS
       ================================================================== */

    /**
     * Registers plugin settings with sanitise callbacks.
     *
     * @since  1.0.0
     * @return void
     */
    public static function register_settings() {
        register_setting( 'csdt_devtools_code_settings', 'csdt_devtools_code_default_theme', [
            'type'              => 'string',
            'sanitize_callback' => function ( $val ) {
                return in_array( $val, [ 'dark', 'light' ] ) ? $val : 'dark';
            },
            'default' => 'dark',
        ] );

        $valid_themes = array_keys( self::get_theme_registry() );
        register_setting( 'csdt_devtools_code_settings', 'csdt_devtools_code_theme_pair', [
            'type'              => 'string',
            'sanitize_callback' => function ( $val ) use ( $valid_themes ) {
                return in_array( $val, $valid_themes, true ) ? $val : 'atom-one';
            },
            'default' => 'atom-one',
        ] );

        register_setting( 'csdt_devtools_code_settings', 'csdt_devtools_perf_monitor_enabled', [
            'type'              => 'string',
            'sanitize_callback' => function ( $val ) {
                return '0' === $val ? '0' : '1';
            },
            'default' => '1',
        ] );

        // Login security settings
        register_setting( 'csdt_devtools_login_settings', 'csdt_devtools_login_hide_enabled', [
            'type'              => 'string',
            'sanitize_callback' => function ( $v ) { return '1' === $v ? '1' : '0'; },
            'default'           => '0',
        ] );
        register_setting( 'csdt_devtools_login_settings', 'csdt_devtools_login_slug', [
            'type'              => 'string',
            'sanitize_callback' => function ( $v ) {
                $slug = sanitize_title( $v );
                // Disallow WP reserved slugs
                $reserved = [ 'wp-login', 'wp-admin', 'login', 'admin', 'dashboard' ];
                return in_array( $slug, $reserved, true ) ? '' : $slug;
            },
            'default' => '',
        ] );
        register_setting( 'csdt_devtools_login_settings', 'csdt_devtools_2fa_method', [
            'type'              => 'string',
            'sanitize_callback' => function ( $v ) {
                return in_array( $v, [ 'off', 'email', 'totp' ], true ) ? $v : 'off';
            },
            'default' => 'off',
        ] );
        register_setting( 'csdt_devtools_login_settings', 'csdt_devtools_2fa_force_admins', [
            'type'              => 'string',
            'sanitize_callback' => function ( $v ) { return '1' === $v ? '1' : '0'; },
            'default'           => '0',
        ] );
        register_setting( 'csdt_devtools_login_settings', 'csdt_devtools_2fa_grace_logins', [
            'type'              => 'string',
            'sanitize_callback' => static function ( $v ) {
                $n = (int) $v;
                return ( $n >= 0 && $n <= 10 ) ? (string) $n : '0';
            },
            'default' => '0',
        ] );
        register_setting( 'csdt_devtools_login_settings', 'csdt_devtools_session_duration', [
            'type'              => 'string',
            'sanitize_callback' => static function ( $v ) {
                $valid = [ 'default', '1', '7', '14', '30', '90', '365' ];
                return in_array( $v, $valid, true ) ? $v : 'default';
            },
            'default' => 'default',
        ] );
        register_setting( 'csdt_devtools_login_settings', 'csdt_devtools_brute_force_enabled', [
            'type'              => 'string',
            'sanitize_callback' => static function ( $v ) { return $v === '1' ? '1' : '0'; },
            'default'           => '1',
        ] );
        register_setting( 'csdt_devtools_login_settings', 'csdt_devtools_brute_force_attempts', [
            'type'              => 'string',
            'sanitize_callback' => static function ( $v ) {
                $n = (int) $v;
                return ( $n >= 1 && $n <= 100 ) ? (string) $n : '5';
            },
            'default' => '5',
        ] );
        register_setting( 'csdt_devtools_login_settings', 'csdt_devtools_brute_force_lockout', [
            'type'              => 'string',
            'sanitize_callback' => static function ( $v ) {
                $n = (int) $v;
                return ( $n >= 1 && $n <= 1440 ) ? (string) $n : '5';
            },
            'default' => '5',
        ] );

        // SMTP settings
        register_setting( 'csdt_devtools_smtp_settings', 'csdt_devtools_smtp_enabled', [
            'type'              => 'string',
            'sanitize_callback' => static function ( $v ) { return $v === '1' ? '1' : '0'; },
            'default'           => '0',
        ] );
        register_setting( 'csdt_devtools_smtp_settings', 'csdt_devtools_smtp_host', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );
        register_setting( 'csdt_devtools_smtp_settings', 'csdt_devtools_smtp_port', [
            'type'              => 'integer',
            'sanitize_callback' => static function ( $v ) {
                $v = absint( $v );
                return $v > 0 ? $v : 587;
            },
            'default'           => 587,
        ] );
        register_setting( 'csdt_devtools_smtp_settings', 'csdt_devtools_smtp_encryption', [
            'type'              => 'string',
            'sanitize_callback' => static function ( $v ) {
                return in_array( $v, [ 'tls', 'ssl', 'none' ], true ) ? $v : 'tls';
            },
            'default'           => 'tls',
        ] );
        register_setting( 'csdt_devtools_smtp_settings', 'csdt_devtools_smtp_auth', [
            'type'              => 'string',
            'sanitize_callback' => static function ( $v ) { return $v === '1' ? '1' : '0'; },
            'default'           => '1',
        ] );
        register_setting( 'csdt_devtools_smtp_settings', 'csdt_devtools_smtp_user', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );
        register_setting( 'csdt_devtools_smtp_settings', 'csdt_devtools_smtp_pass', [
            'type'              => 'string',
            'sanitize_callback' => static function ( $v ) { return $v; },
            'default'           => '',
        ] );
        register_setting( 'csdt_devtools_smtp_settings', 'csdt_devtools_smtp_from_email', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default'           => '',
        ] );
        register_setting( 'csdt_devtools_smtp_settings', 'csdt_devtools_smtp_from_name', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );

        register_setting( 'csdt_devtools_thumbs_settings', 'csdt_devtools_openai_key', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );
    }

    /* ==================================================================
       5. COMBINED TOOLS PAGE (Code Block Migrator + SQL Command)
       ================================================================== */

    /**
     * Adds the combined Tools page to the WordPress admin menu.
     *
     * @since  1.6.0
     * @return void
     */
    /**
     * Redirects legacy ?page=cloudscale-code-sql URLs to the new slug.
     *
     * @since  1.8.56
     * @return void
     */
    /**
     * Redirects the old help page URL to the current one.
     *
     * @since  1.8.56
     * @return void
     */
    public static function maybe_lscache_purge(): void {
        // Front-end endpoint: GET /?csdt_lscache_purge=TOKEN
        // Fires before WordPress loads the page, so LSWS processes the LiteSpeed
        // purge header in this normal front-end response (unlike admin-ajax.php which
        // LSWS excludes from cache header processing).
        if ( ! isset( $_GET['csdt_lscache_purge'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $token  = sanitize_text_field( (string) $_GET['csdt_lscache_purge'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $stored = get_option( 'csdt_opcache_token', '' );
        if ( ! $stored || ! $token || ! hash_equals( $stored, $token ) ) {
            wp_die( esc_html__( 'Unauthorized', 'cloudscale-devtools' ), '', 403 );
        }
        // Send the purge header directly so LSWS clears its full-page cache even
        // when exit fires before wp_headers (which is where LiteSpeed plugin normally injects it).
        header( 'X-LiteSpeed-Purge: *' );
        do_action( 'litespeed_purge_all' );
        header( 'Content-Type: application/json; charset=utf-8' );
        echo wp_json_encode( [ 'purged' => true ] );
        exit;
    }

    public static function redirect_legacy_help_url() {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        if ( strpos( $uri, 'code-block-help' ) !== false ) {
            wp_redirect( home_url( '/wordpress-plugin-help/cloudscale-cyber-devtools-help/' ), 301 );
            exit;
        }
    }

    public static function redirect_legacy_slug() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'cloudscale-code-sql' ) {
            $args = $_GET;
            $args['page'] = self::TOOLS_SLUG;
            wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php' ) ) );
            exit;
        }
    }

    public static function add_tools_page() {
        add_management_page(
            'CloudScale Cyber and Devtools',
            '🔐 Cyber and Devtools',
            'manage_options',
            self::TOOLS_SLUG,
            [ __CLASS__, 'render_tools_page' ]
        );
    }

    /**
     * Conditionally enqueues admin assets on the plugin tools page only.
     *
     * @since  1.6.0
     * @param  string $hook Current admin page hook suffix.
     * @return void
     */
    public static function enqueue_admin_assets( $hook ) {
        // Tabs CSS
        wp_enqueue_style(
            'csdt-admin-tabs',
            plugins_url( 'assets/cs-admin-tabs.css', __FILE__ ),
            [],
            self::VERSION
        );
        // Explain modal description styling — scoped to .cs-explain-desc.
        wp_add_inline_style( 'csdt-admin-tabs', CSDT_Perf_Monitor::get_explain_modal_css() );

        // Dashboard widget styles (only on wp-admin dashboard).
        if ( $hook === 'index.php' ) {
            wp_add_inline_style( 'dashboard', self::get_dashboard_widget_css() );
        }

        // Migrate CSS + JS
        wp_enqueue_style(
            'csdt-code-migrate',
            plugins_url( 'assets/cs-code-migrate.css', __FILE__ ),
            [],
            self::VERSION
        );
        wp_enqueue_script(
            'csdt-code-migrate',
            plugins_url( 'assets/cs-code-migrate.js', __FILE__ ),
            [],
            self::VERSION,
            true
        );
        wp_localize_script( 'csdt-code-migrate', 'csdtDevtoolsMigrate', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( self::MIGRATE_NONCE ),
        ] );

        // Settings save JS
        wp_enqueue_script(
            'csdt-admin-settings',
            plugins_url( 'assets/cs-admin-settings.js', __FILE__ ),
            [],
            self::VERSION,
            true
        );
        wp_localize_script( 'csdt-admin-settings', 'csdtDevtoolsAdminSettings', [
            'nonce' => wp_create_nonce( 'csdt_devtools_code_settings_inline' ),
        ] );

        // SQL editor JS
        wp_enqueue_script(
            'csdt-sql-editor',
            plugins_url( 'assets/cs-sql-editor.js', __FILE__ ),
            [],
            self::VERSION,
            true
        );
        wp_localize_script( 'csdt-sql-editor', 'csdtDevtoolsSqlEditor', [
            'nonce' => wp_create_nonce( CloudScale_DevTools::SQL_NONCE ),
        ] );

        // Tab router — client-side switching without full page reloads
        wp_enqueue_script(
            'csdt-tab-router',
            plugins_url( 'assets/cs-tab-router.js', __FILE__ ),
            [],
            self::VERSION,
            true
        );

        // Login security JS (only loaded on the login tab)
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'home'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $active_tab === 'login' ) {
            wp_enqueue_style(
                'csdt-leaflet-css',
                plugins_url( 'assets/leaflet.min.css', __FILE__ ),
                [],
                self::VERSION
            );
            wp_enqueue_script(
                'csdt-leaflet-js',
                plugins_url( 'assets/leaflet.min.js', __FILE__ ),
                [],
                self::VERSION,
                true
            );
            wp_enqueue_script(
                'csdt-qrcode',
                plugins_url( 'assets/qrcode.min.js', __FILE__ ),
                [],
                self::VERSION,
                true
            );
            wp_enqueue_script(
                'csdt-login',
                plugins_url( 'assets/cs-login.js', __FILE__ ),
                [ 'csdt-qrcode', 'csdt-leaflet-js' ],
                self::VERSION,
                true
            );
            // Build per-country blocked counts (7-day sum) for the map.
            $wplogin_map_stats = get_option( 'csdt_wplogin_blocked_stats', [] );
            $countries_blocked = [];
            if ( isset( $wplogin_map_stats['country_stats'] ) && is_array( $wplogin_map_stats['country_stats'] ) ) {
                foreach ( $wplogin_map_stats['country_stats'] as $cc => $days ) {
                    if ( is_array( $days ) ) {
                        $countries_blocked[ $cc ] = array_sum( $days );
                    }
                }
            }
            wp_localize_script( 'csdt-login', 'csdtDevtoolsLogin', [
                'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
                'nonce'            => wp_create_nonce( 'csdt_devtools_login_nonce' ),
                'secNonce'         => wp_create_nonce( CloudScale_DevTools::SECURITY_NONCE ),
                'currentUser'      => get_current_user_id(),
                'mailTabUrl'       => admin_url( 'tools.php?page=' . self::TOOLS_SLUG . '&tab=mail' ),
                'countriesBlocked' => $countries_blocked,
                'hideLoginEnabled' => get_option( 'csdt_devtools_login_hide_enabled', '0' ),
            ] );
            wp_enqueue_script(
                'csdt-passkey',
                plugins_url( 'assets/cs-passkey.js', __FILE__ ),
                [ 'csdt-login' ],
                self::VERSION,
                true
            );
            wp_enqueue_script(
                'csdt-test-accounts',
                plugins_url( 'assets/cs-test-accounts.js', __FILE__ ),
                [ 'csdt-login' ],
                self::VERSION,
                true
            );
            wp_localize_script( 'csdt-test-accounts', 'csdtTestAccounts', [
                'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                'nonce'      => wp_create_nonce( 'csdt_devtools_login_nonce' ),
                'testUsers'  => CSDT_Test_Accounts::get_test_users_with_sessions(),
                'secret'     => CSDT_Test_Accounts::get_or_create_secret(),
                'sessionUrl' => rest_url( 'csdt/v1/test-session-' . CSDT_Test_Accounts::get_or_create_path_token() ),
                'logoutUrl'  => rest_url( 'csdt/v1/test-logout-' . CSDT_Test_Accounts::get_or_create_path_token() ),
                'siteUrl'    => home_url(),
            ] );
        }

        if ( $active_tab === 'mail' ) {
            wp_enqueue_script(
                'csdt-smtp',
                plugins_url( 'assets/cs-smtp.js', __FILE__ ),
                [],
                self::VERSION,
                true
            );
            wp_localize_script( 'csdt-smtp', 'csdtDevtoolsSmtp', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( self::SMTP_NONCE ),
                'testTo'  => wp_get_current_user()->user_email,
            ] );
        }

        if ( $active_tab === 'debug' || $active_tab === '404' ) {
            wp_enqueue_script(
                'csdt-404-admin',
                plugins_url( 'assets/cs-404-admin.js', __FILE__ ),
                [],
                self::VERSION,
                true
            );
            wp_localize_script( 'csdt-404-admin', 'csdtDevtools404', [
                'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                'nonce'      => wp_create_nonce( 'csdt_devtools_404_settings' ),
                'custom_404' => get_option( self::CUSTOM_404_OPTION, 1 ) ? 1 : 0,
                'scheme'     => get_option( self::SCHEME_404_OPTION, 'ocean' ),
                'previewUrl' => home_url( '/this-page-does-not-exist' ),
            ] );
        }

        if ( in_array( $active_tab, [ 'security', 'headers', 'home' ], true ) ) {
            wp_enqueue_script(
                'csdt-vuln-scan',
                plugins_url( 'assets/cs-vuln-scan.js', __FILE__ ),
                [],
                self::VERSION,
                true
            );
            $saved_model      = get_option( 'csdt_devtools_security_model', '_auto' );
            $saved_deep_model = get_option( 'csdt_devtools_deep_scan_model', '_auto_deep' );
            $saved_prompt     = get_option( 'csdt_devtools_security_prompt', '' );
            $saved_provider   = get_option( 'csdt_devtools_ai_provider', 'anthropic' );
            $api_key          = get_option( 'csdt_devtools_anthropic_key', '' );
            $gemini_key       = get_option( 'csdt_devtools_gemini_key', '' );
            $masked_key       = $api_key    ? '••••••••' . substr( $api_key,    -4 ) : '';
            $masked_gemini    = $gemini_key ? '••••••••' . substr( $gemini_key, -4 ) : '';
            $has_key          = $saved_provider === 'gemini' ? ! empty( $gemini_key ) : ! empty( $api_key );
            wp_localize_script( 'csdt-vuln-scan', 'csdtVulnScan', [
                'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
                'nonce'          => wp_create_nonce( CloudScale_DevTools::SECURITY_NONCE ),
                'hasKey'         => $has_key,
                'savedProvider'  => $saved_provider,
                'maskedKey'      => $masked_key,
                'maskedGemini'   => $masked_gemini,
                'savedModel'     => $saved_model,
                'savedDeepModel' => $saved_deep_model,
                'savedPrompt'    => $saved_prompt,
                'defaultPrompt'  => CSDT_Site_Audit::default_security_prompt(),
                'scanHistory'    => get_option( 'csdt_scan_history', [] ),
                'homeUrl'        => home_url( '/' ),
                'adhocHistory'   => get_option( 'csdt_adhoc_scans', [] ),
            ] );
            wp_enqueue_script(
                'csdt-sec-headers',
                plugins_url( 'assets/cs-sec-headers.js', __FILE__ ),
                [ 'csdt-vuln-scan' ],
                self::VERSION,
                true
            );
            wp_enqueue_script(
                'csdt-csp',
                plugins_url( 'assets/cs-csp.js', __FILE__ ),
                [ 'csdt-vuln-scan' ],
                self::VERSION,
                true
            );
            wp_localize_script( 'csdt-csp', 'csdtCspI18n', [
                'rollbackLabel' => esc_js( __( 'Rollback to previous settings', 'cloudscale-devtools' ) ),
            ] );
            wp_enqueue_script(
                'csdt-prefix-rollback',
                plugins_url( 'assets/cs-prefix-rollback.js', __FILE__ ),
                [ 'csdt-vuln-scan' ],
                self::VERSION,
                true
            );
        }

        if ( $active_tab === 'thumbnails' || $active_tab === 'ai-images' ) {
            wp_enqueue_media();
            $thumb_js = plugin_dir_path( __FILE__ ) . 'assets/cs-thumbnails.js';
            wp_enqueue_script(
                'csdt-thumbnails',
                plugins_url( 'assets/cs-thumbnails.js', __FILE__ ),
                [ 'jquery' ],
                self::VERSION,
                true
            );
            wp_localize_script( 'csdt-thumbnails', 'csdtDevtoolsThumbs', [
                'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                'nonce'      => wp_create_nonce( 'csdt_devtools_thumbnails' ),
                'siteUrl'    => home_url( '/' ),
                'defimgNonce'=> wp_create_nonce( 'csdt_defimg' ),
            ] );
            // Thumbnails-tab-specific CSS — injected as inline style to avoid an
            // extra HTTP request and keep the render method free of <style> tags.
            wp_add_inline_style( 'csdt-admin-tabs', CSDT_Perf_Monitor::get_thumbnails_admin_css() );
        }

        if ( $active_tab === 'optimizer' ) {
            wp_enqueue_script(
                'csdt-optimizer',
                plugins_url( 'assets/cs-plugin-stack.js', __FILE__ ),
                [],
                self::VERSION,
                true
            );
            wp_localize_script( 'csdt-optimizer', 'csdtOptimizer', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( CloudScale_DevTools::OPTIMIZER_NONCE ),
                'baseUrl' => admin_url( 'tools.php?page=' . self::TOOLS_SLUG ),
                'hasAi'   => CSDT_AI_Dispatcher::has_key(),
            ] );
            wp_enqueue_script(
                'csdt-optimizer-panel',
                plugins_url( 'assets/cs-optimizer-panel.js', __FILE__ ),
                [ 'csdt-optimizer' ],
                self::VERSION,
                true
            );
        }

        if ( $active_tab === 'site-audit' ) {
            wp_enqueue_script(
                'csdt-site-audit',
                plugins_url( 'assets/cs-site-audit.js', __FILE__ ),
                [],
                self::VERSION,
                true
            );
            $audit_cache = get_option( 'csdt_site_audit_cache', null );
            wp_localize_script( 'csdt-site-audit', 'csdtSiteAudit', [
                'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( CloudScale_DevTools::SITE_AUDIT_NONCE ),
                'secNonce' => wp_create_nonce( CloudScale_DevTools::SECURITY_NONCE ),
                'seoAiUrl' => admin_url( 'tools.php?page=cs-seo-optimizer' ),
                'cached'   => $audit_cache ? $audit_cache['data']   : null,
                'cachedAt' => $audit_cache ? $audit_cache['run_at'] : null,
            ] );
        }

        if ( $active_tab === 'debug' || $active_tab === 'logs' ) {
            wp_add_inline_style( 'csdt-admin-tabs', self::get_log_viewer_css() );
            $logs_js = plugin_dir_path( __FILE__ ) . 'assets/cs-server-logs.js';
            wp_enqueue_script(
                'csdt-server-logs',
                plugins_url( 'assets/cs-server-logs.js', __FILE__ ),
                [],
                self::VERSION,
                true
            );
            wp_localize_script( 'csdt-server-logs', 'csdtServerLogs', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( CloudScale_DevTools::LOGS_NONCE ),
                'sources' => self::get_log_sources(),
            ] );
        }

        if ( $active_tab === 'debug' ) {
            wp_enqueue_script(
                'csdt-debug',
                plugins_url( 'assets/cs-debug.js', __FILE__ ),
                [],
                self::VERSION,
                true
            );
            wp_localize_script( 'csdt-debug', 'csdtDebug', [
                'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
                'logsNonce'     => wp_create_nonce( CloudScale_DevTools::LOGS_NONCE ),
                'aiNonce'       => wp_create_nonce( CloudScale_DevTools::OPTIMIZER_NONCE ),
                'debugNonce'    => wp_create_nonce( CloudScale_DevTools::DEBUG_NONCE ),
                'fpmNonce'      => wp_create_nonce( CloudScale_DevTools::FPM_NONCE ),
                'perfNonce'     => wp_create_nonce( CloudScale_DevTools::PERF_NONCE ),
                'perfEnabled'   => get_option( 'csdt_devtools_perf_monitor_enabled', '1' ),
                'sources'       => self::get_log_sources(),
            ] );
        }

        // Email-verified modal countdown — only needed when the verification
        // redirect lands back on the login tab with ?email_2fa_activated=1.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $active_tab === 'login' && isset( $_GET['email_2fa_activated'] ) && '1' === $_GET['email_2fa_activated'] ) {
            wp_add_inline_script(
                'csdt-admin-settings',
                '(function(){' .
                'var modal=document.getElementById("cs-email-verified-modal");' .
                'var cd=document.getElementById("cs-modal-countdown");' .
                'var closeBtn=document.getElementById("cs-email-modal-close");' .
                'var n=6;' .
                'var t=setInterval(function(){n--;if(cd)cd.textContent=n;if(n<=0){clearInterval(t);if(modal)modal.style.display="none";}},1000);' .
                'function dismiss(){clearInterval(t);if(modal)modal.style.display="none";}' .
                'if(closeBtn)closeBtn.addEventListener("click",dismiss);' .
                'if(modal)modal.addEventListener("click",function(e){if(e.target===modal)dismiss();});' .
                '(function(){var u=new URL(location.href);u.searchParams.delete("email_2fa_activated");history.replaceState(null,"",u.toString());})();' .
                '})()'
            );
        }
    }

    /**
     * Renders the combined Code Migrator and SQL Command tools page.
     *
     * @since  1.6.0
     * @return void
     */
    public static function render_tools_page() {
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'home';
        $base_url   = admin_url( 'tools.php?page=' . self::TOOLS_SLUG );
        ?>
        <div class="wrap">
        <div id="cs-app">

            <!-- Banner -->
            <div id="cs-banner">
                <div id="cs-banner-top">
                    <div id="cs-banner-title"><span style="flex-shrink:0;font-size:18px;line-height:1">🔐</span><span><?php esc_html_e( 'Cyber Devtools', 'cloudscale-devtools' ); ?></span></div>
                    <div id="cs-banner-right">
                        <span class="cs-badge cs-badge-version">v<?php echo esc_html( self::VERSION ); ?></span>
                        <span class="cs-badge cs-badge-green">✅ <?php esc_html_e( 'Totally Free', 'cloudscale-devtools' ); ?></span>
                        <a href="https://andrewbaker.ninja" target="_blank" rel="noopener noreferrer" class="cs-badge cs-badge-orange" style="text-decoration:none">andrewbaker.ninja</a>
                        <a href="https://andrewbaker.ninja/wordpress-plugin-help/cloudscale-cyber-devtools-help/" target="_blank" rel="noopener noreferrer" class="cs-badge cs-badge-help" style="text-decoration:none">❓ <?php esc_html_e( 'Help', 'cloudscale-devtools' ); ?></a>
                    </div>
                </div>
                <div id="cs-banner-sub"><?php esc_html_e( 'AI security scanner · 2FA · SMTP mailer · SQL tools · developer toolkit', 'cloudscale-devtools' ); ?></div>
            </div>

            <!-- Tab bar -->
            <div id="cs-tab-bar">
                <a href="<?php echo esc_url( $base_url . '&tab=home' ); ?>"
                   class="cs-tab <?php echo $active_tab === 'home' ? 'active' : ''; ?>">
                    🏠 <?php esc_html_e( 'Home', 'cloudscale-devtools' ); ?>
                </a>
                <a href="<?php echo esc_url( $base_url . '&tab=site-audit' ); ?>"
                   class="cs-tab <?php echo $active_tab === 'site-audit' ? 'active' : ''; ?>">
                    🔍 <?php esc_html_e( 'Site Audit', 'cloudscale-devtools' ); ?>
                </a>
                <a href="<?php echo esc_url( $base_url . '&tab=login' ); ?>"
                   class="cs-tab <?php echo $active_tab === 'login' ? 'active' : ''; ?>">
                    🔐 <?php esc_html_e( 'Login Security', 'cloudscale-devtools' ); ?>
                </a>
                <a href="<?php echo esc_url( $base_url . '&tab=security' ); ?>"
                   class="cs-tab <?php echo $active_tab === 'security' ? 'active' : ''; ?>">
                    🛡️ <?php esc_html_e( 'AI Security Scan', 'cloudscale-devtools' ); ?>
                </a>
                <a href="<?php echo esc_url( $base_url . '&tab=headers' ); ?>"
                   class="cs-tab <?php echo $active_tab === 'headers' ? 'active' : ''; ?>">
                    🔒 <?php esc_html_e( 'Headers', 'cloudscale-devtools' ); ?>
                </a>
                <a href="<?php echo esc_url( $base_url . '&tab=ai-images' ); ?>"
                   class="cs-tab <?php echo $active_tab === 'ai-images' ? 'active' : ''; ?>">
                    🎨 <?php esc_html_e( 'Featured Images', 'cloudscale-devtools' ); ?>
                </a>
                <a href="<?php echo esc_url( $base_url . '&tab=optimizer' ); ?>"
                   class="cs-tab <?php echo $active_tab === 'optimizer' ? 'active' : ''; ?>">
                    ⚡ <?php esc_html_e( 'Performance', 'cloudscale-devtools' ); ?>
                </a>
                <a href="<?php echo esc_url( $base_url . '&tab=debug' ); ?>"
                   class="cs-tab <?php echo $active_tab === 'debug' ? 'active' : ''; ?>">
                    🩺 <?php esc_html_e( 'Diagnostics', 'cloudscale-devtools' ); ?>
                </a>
                <a href="<?php echo esc_url( $base_url . '&tab=mail' ); ?>"
                   class="cs-tab <?php echo $active_tab === 'mail' ? 'active' : ''; ?>">
                    📧 <?php esc_html_e( 'Mail / SMTP', 'cloudscale-devtools' ); ?>
                </a>
                <a href="<?php echo esc_url( $base_url . '&tab=thumbnails' ); ?>"
                   class="cs-tab <?php echo $active_tab === 'thumbnails' ? 'active' : ''; ?>">
                    🖼️ <?php esc_html_e( 'Thumbnails', 'cloudscale-devtools' ); ?>
                </a>
            </div>
            <!-- Copy All action bar -->
            <div id="cs-tab-actions">
                <button id="cs-copy-all-btn" class="cs-copy-all-btn" title="<?php esc_attr_e( 'Copy all content from this tab to clipboard', 'cloudscale-devtools' ); ?>">
                    &#128203; <?php esc_html_e( 'Copy All', 'cloudscale-devtools' ); ?>
                </button>
            </div>

            <?php if ( $active_tab === 'home' ) : ?>
                <div class="cs-tab-content active">
                    <?php self::render_home_panel(); ?>
                </div>

            <?php elseif ( $active_tab === 'login' ) : ?>
                <div class="cs-tab-content active">
                    <?php self::render_login_panel(); ?>
                </div>
            <?php elseif ( $active_tab === 'mail' ) : ?>
                <div class="cs-tab-content active">
                    <?php CSDT_SMTP::render_smtp_panel(); ?>
                </div>
            <?php elseif ( $active_tab === 'thumbnails' ) : ?>
                <div class="cs-tab-content active">
                    <?php CSDT_Thumbnails::render_thumbnails_panel(); ?>
                </div>
            <?php elseif ( $active_tab === 'ai-images' ) : ?>
                <div class="cs-tab-content active">
                    <?php CSDT_Thumbnails::render_ai_images_panel(); ?>
                </div>
            <?php elseif ( $active_tab === 'security' ) : ?>
                <div class="cs-tab-content active">
                    <?php self::render_security_panel(); ?>
                </div>
            <?php elseif ( $active_tab === 'headers' ) : ?>
                <div class="cs-tab-content active">
                    <?php CSDT_Security_Headers::render_header_scan_panel(); ?>
                    <?php CSDT_Security_Headers::render_security_headers_panel( false ); ?>
                    <?php CSDT_CSP::render_csp_panel(); ?>
                </div>
            <?php elseif ( $active_tab === 'optimizer' ) : ?>
                <div class="cs-tab-content active">
                    <?php self::render_optimizer_panel(); ?>
                </div>
            <?php elseif ( $active_tab === 'site-audit' ) : ?>
                <div class="cs-tab-content active">
                    <?php CSDT_Site_Audit::render_site_audit_panel(); ?>
                </div>
            <?php elseif ( in_array( $active_tab, [ 'debug', 'logs', 'sql', '404', 'migrate' ], true ) ) : ?>
                <div class="cs-tab-content active">
                    <?php self::render_debug_panel(); ?>
                </div>
            <?php endif; ?>

            <?php CSDT_Site_Audit::render_quick_fix_modals(); ?>

        </div>
        </div>
        <?php
    }

    /* ==================================================================
       5a. Settings panel (inline on Migrator tab)
       ================================================================== */

    /**
     * Renders the Code Block Settings panel (colour theme and default mode selectors).
     *
     * @since  1.6.0
     * @return void
     */
    /**
     * Renders an "Explain…" button and its associated modal for a panel header.
     *
     * @param string $id    Unique slug used to build element IDs.
     * @param string $title Modal title.
     * @param array  $items Array of ['name'=>'', 'rec'=>'', 'desc'=>''] entries.
     */
    /**
     * Allowed HTML tags/attrs for item descriptions that contain links.
     *
     * @var array<string,array<string,bool>>
     */
    private static array $explain_kses = [
        'a'      => [ 'href' => true, 'target' => true, 'rel' => true ],
        'strong' => [],
        'em'     => [],
        'code'   => [],
        'br'     => [],
        'ul'     => [],
        'ol'     => [],
        'li'     => [],
        'p'      => [],
        'h4'     => [],
    ];

    /**
     * Renders an "Explain…" button + inline modal.
     *
     * Each item in $items may have:
     *   'name' => string   — section heading
     *   'rec'  => string   — badge label (Recommended | Note | Optional)
     *   'desc' => string   — plain-text description (escaped with esc_html)
     *   'html' => string   — HTML description rendered via wp_kses (overrides 'desc')
     *
     * @param string $id    Unique slug used to build element IDs.
     * @param string $title Modal title.
     * @param array  $items Array of item arrays.
     */
    public static function render_explain_btn( string $id, string $title, array $items, string $intro = '' ): void {
        $btn_id   = 'cs-explain-btn-' . $id;
        $modal_id = 'cs-explain-modal-' . $id;
        ?>
        <button type="button" id="<?php echo esc_attr( $btn_id ); ?>"
            data-cs-modal-open="<?php echo esc_attr( $modal_id ); ?>"
            style="background:#fff!important;border:1px solid rgba(255,255,255,0.6)!important;border-radius:5px!important;color:#1e40af!important;font-size:12px!important;font-weight:700!important;padding:5px 14px!important;cursor:pointer!important;margin-left:auto!important;flex-shrink:0!important;display:block!important;box-shadow:none!important;text-shadow:none!important;text-transform:none!important;letter-spacing:normal!important;line-height:1.4!important">
            Explain&hellip;
        </button>
        <div id="<?php echo esc_attr( $modal_id ); ?>"
             style="display:none;position:fixed;inset:0;z-index:100002;background:rgba(0,0,0,0.55);align-items:center;justify-content:center;padding:16px;text-transform:none;letter-spacing:normal;font-weight:normal"
             data-cs-modal-backdrop="true">
            <div style="background:#fff;border-radius:10px;max-width:600px;width:100%;max-height:88vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.25)">
                <div style="padding:18px 22px 12px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:10px">
                    <strong style="font-size:15px;color:#111"><?php echo esc_html( $title ); ?></strong>
                    <button type="button"
                        data-cs-modal-close="<?php echo esc_attr( $modal_id ); ?>"
                        style="margin-left:auto;background:none;border:none;font-size:20px;cursor:pointer;color:#888;line-height:1;padding:0">&times;</button>
                </div>
                <div style="padding:16px 22px 20px">
                    <?php if ( $intro ) : ?>
                    <div style="background:#f0f6ff;border-left:4px solid #2271b1;border-radius:0 6px 6px 0;padding:12px 16px;margin-bottom:14px;font-size:13px;color:#1a1a1a;line-height:1.6">
                        <?php echo wp_kses( $intro, self::$explain_kses ); ?>
                    </div>
                    <?php endif; ?>
                    <?php foreach ( $items as $item ) :
                        $rec = $item['rec'];
                        $rl  = strtolower( $rec );
                        if ( str_contains( $rl, 'critical' ) || str_contains( $rl, 'not recommended' ) ) {
                            $bg = '#fef2f2'; $col = '#991b1b'; $bdr = '#dc2626';
                        } elseif ( str_contains( $rl, 'high' ) ) {
                            $bg = '#fff7ed'; $col = '#9a3412'; $bdr = '#f97316';
                        } elseif ( str_contains( $rl, 'recommended' ) ) {
                            $bg = '#f0fdf4'; $col = '#14532d'; $bdr = '#16a34a';
                        } elseif ( str_contains( $rl, 'important' ) || str_contains( $rl, 'required' ) ) {
                            $bg = '#fffbeb'; $col = '#92400e'; $bdr = '#d97706';
                        } elseif ( str_contains( $rl, 'optional' ) || str_contains( $rl, 'note' ) ) {
                            $bg = '#f6f7f7'; $col = '#50575e'; $bdr = '#c3c4c7';
                        } elseif ( str_contains( $rl, 'info' ) || str_contains( $rl, 'overview' ) || str_contains( $rl, 'diagnostic' ) || str_contains( $rl, 'technical' ) || str_contains( $rl, 'automatic' ) ) {
                            $bg = '#eff6ff'; $col = '#1e40af'; $bdr = '#3b82f6';
                        } else {
                            $bg = '#faf5ff'; $col = '#6b21a8'; $bdr = '#a855f7';
                        }
                    ?>
                    <div style="border:1px solid #e0e0e0;border-radius:6px;padding:14px 16px;margin-bottom:10px">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px">
                            <strong style="font-size:13px;color:#111;line-height:1.3"><?php echo esc_html( $item['name'] ); ?></strong>
                            <span style="flex-shrink:0;display:inline-block;background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $col ); ?>;border:1px solid <?php echo esc_attr( $bdr ); ?>;border-radius:4px;font-size:10px;font-weight:700;padding:2px 8px;white-space:nowrap;letter-spacing:0.02em;text-transform:uppercase"><?php echo esc_html( $rec ); ?></span>
                        </div>
                        <div class="cs-explain-desc">
                            <?php
                            if ( ! empty( $item['html'] ) ) {
                                echo wp_kses( $item['html'], self::$explain_kses ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitised via wp_kses with restricted allowlist
                            } else {
                                echo esc_html( $item['desc'] ?? '' );
                            }
                            ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="padding:10px 22px 16px;border-top:1px solid #e5e7eb;text-align:right">
                    <button type="button"
                        data-cs-modal-close="<?php echo esc_attr( $modal_id ); ?>"
                        style="background:#f3f4f6;border:1px solid #d1d5db;border-radius:5px;padding:6px 18px;font-size:12px;font-weight:600;cursor:pointer;color:#374151">
                        <?php esc_html_e( 'Got it', 'cloudscale-devtools' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    private static function render_settings_panel() {
        $theme       = get_option( 'csdt_devtools_code_default_theme', 'dark' );
        $pair_slug   = get_option( 'csdt_devtools_code_theme_pair', 'atom-one' );
        $registry    = self::get_theme_registry();
        ?>
        <div class="cs-panel" id="cs-panel-code-settings">
            <div class="cs-section-header cs-section-header-teal">
                <span>🎨 CODE BLOCK SETTINGS</span>
                <?php self::render_explain_btn( 'code-settings', 'Code Block Settings', [
                    [ 'name' => 'Theme Pair',   'rec' => 'Recommended', 'desc' => 'Choose a light/dark colour-scheme pair for syntax-highlighted code blocks. The pair is applied automatically based on the visitor\'s OS colour preference.' ],
                    [ 'name' => 'Default Mode', 'rec' => 'Optional',    'desc' => 'Force all code blocks to always use light or dark mode, ignoring the visitor\'s system preference. Leave unset to follow the OS setting.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <div class="cs-field-row">
                    <div class="cs-field">
                        <label class="cs-label" for="cs-settings-pair"><?php esc_html_e( 'Color Theme:', 'cloudscale-devtools' ); ?></label>
                        <select id="cs-settings-pair" name="csdt_devtools_code_theme_pair" class="cs-input">
                            <?php foreach ( $registry as $slug => $info ) : ?>
                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $pair_slug, $slug ); ?>>
                                    <?php echo esc_html( $info['label'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="cs-hint"><?php esc_html_e( 'Syntax highlighting color scheme loaded from CDN.', 'cloudscale-devtools' ); ?></span>
                    </div>
                    <div class="cs-field">
                        <label class="cs-label" for="cs-settings-theme"><?php esc_html_e( 'Default Mode:', 'cloudscale-devtools' ); ?></label>
                        <select id="cs-settings-theme" name="csdt_devtools_code_default_theme" class="cs-input">
                            <option value="dark" <?php selected( $theme, 'dark' ); ?>><?php esc_html_e( 'Dark', 'cloudscale-devtools' ); ?></option>
                            <option value="light" <?php selected( $theme, 'light' ); ?>><?php esc_html_e( 'Light', 'cloudscale-devtools' ); ?></option>
                        </select>
                        <span class="cs-hint"><?php esc_html_e( 'Visitors can still toggle per block.', 'cloudscale-devtools' ); ?></span>
                    </div>
                </div>
<div style="margin-top:14px;display:flex;align-items:center;gap:10px">
                    <button type="button" class="cs-btn-primary" id="cs-settings-save">💾 <?php esc_html_e( 'Save Settings', 'cloudscale-devtools' ); ?></button>
                    <span class="cs-settings-saved" id="cs-settings-saved">✓ <?php esc_html_e( 'Saved', 'cloudscale-devtools' ); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    /* ==================================================================
       5b. Migrate panel
       ================================================================== */

    /**
     * Renders the Code Block Migrator panel.
     *
     * @since  1.5.0
     * @return void
     */
    /* ==================================================================
       Server Logs tab
    ================================================================== */

    public static function get_log_sources(): array {
        $sources = [];

        // PHP error log — prefer path set by our mu-plugin; fall back to php.ini if it's a real file
        $php_log = get_option( 'csdt_php_error_log_path', '' );
        if ( ! $php_log ) {
            $ini_log = ini_get( 'error_log' );
            if ( $ini_log && is_file( $ini_log ) ) {
                $php_log = $ini_log;
            }
        }
        if ( $php_log ) {
            $sources['php_error'] = [ 'label' => 'PHP Error Log', 'path' => $php_log ];
        }

        // WordPress debug.log — prefer relocated path set by quick-fix mu-plugin
        $relocated    = get_option( 'csdt_debug_log_path', '' );
        $wp_debug_log = $relocated ?: WP_CONTENT_DIR . '/debug.log';
        $sources['wp_debug'] = [ 'label' => 'WordPress Debug Log', 'path' => $wp_debug_log ];

        // Web server error log — check common paths that may be readable by the web user
        $web_error_candidates = [
            '/var/log/apache2/error.log',
            '/var/log/httpd/error_log',
            '/var/log/nginx/error.log',
            '/var/log/apache2/error_log',
        ];
        foreach ( $web_error_candidates as $path ) {
            if ( is_readable( $path ) ) {
                $sources['web_error'] = [ 'label' => 'Web Server Error Log', 'path' => $path ];
                break;
            }
        }

        // Web server access log
        $web_access_candidates = [
            '/var/log/apache2/access.log',
            '/var/log/httpd/access_log',
            '/var/log/nginx/access.log',
        ];
        foreach ( $web_access_candidates as $path ) {
            if ( is_readable( $path ) ) {
                $sources['web_access'] = [ 'label' => 'Web Server Access Log', 'path' => $path ];
                break;
            }
        }

        // SSH auth log — readable if www-data is in the adm group
        $auth_log_candidates = [
            '/var/log/auth.log',   // Debian/Ubuntu
            '/var/log/secure',     // RHEL/CentOS/Fedora
            '/var/log/messages',   // some RHEL variants
        ];
        foreach ( $auth_log_candidates as $path ) {
            if ( is_readable( $path ) ) {
                $sources['auth_ssh'] = [ 'label' => 'SSH Auth Log', 'path' => $path ];
                break;
            }
        }

        // WP Cron log
        $cron_log = WP_CONTENT_DIR . '/cron.log';
        if ( file_exists( $cron_log ) ) {
            $sources['wp_cron'] = [ 'label' => 'WP Cron Log', 'path' => $cron_log ];
        }

        // Admin-configured custom paths
        $custom = get_option( 'csdt_custom_log_paths', [] );
        if ( is_array( $custom ) ) {
            foreach ( $custom as $i => $cp ) {
                if ( ! empty( $cp['label'] ) && ! empty( $cp['path'] ) ) {
                    $sources[ 'custom_' . $i ] = [
                        'label'  => sanitize_text_field( $cp['label'] ),
                        'path'   => $cp['path'],
                        'custom' => true,
                    ];
                }
            }
        }

        return $sources;
    }

    public static function ajax_logs_setup_php(): void {
        check_ajax_referer( CloudScale_DevTools::LOGS_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $log_path = WP_CONTENT_DIR . '/php-error.log';
        $mu_dir   = WP_CONTENT_DIR . '/mu-plugins';
        if ( ! is_dir( $mu_dir ) ) {
            wp_mkdir_p( $mu_dir );
        }
        // If the directory exists but is not writable (e.g. owned by a different OS user),
        // attempt a one-time chmod so the web-server user can write the mu-plugin file.
        if ( is_dir( $mu_dir ) && ! is_writable( $mu_dir ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
            @chmod( $mu_dir, 0755 );
        }

        $mu_file = $mu_dir . '/csdt-php-error-log.php';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $written = file_put_contents(
            $mu_file,
            '<?php' . "\n" .
            '// Redirects PHP error_log to a readable file — managed by CloudScale DevTools.' . "\n" .
            '// phpcs:ignore WordPress.PHP.IniSet.Risky' . "\n" .
            '@ini_set( \'error_log\', ' . var_export( $log_path, true ) . ' );' . "\n"
        );

        if ( false === $written ) {
            wp_send_json_error( [ 'message' => __( 'Could not write mu-plugin. Run: docker exec pi_wordpress chown www-data:www-data /var/www/html/wp-content/mu-plugins', 'cloudscale-devtools' ) ] );
            return;
        }

        update_option( 'csdt_php_error_log_path', $log_path, false );
        wp_send_json_success( [ 'path' => $log_path, 'sources' => self::get_log_sources() ] );
    }

    public static function ajax_logs_fix_mu_perms(): void {
        check_ajax_referer( CloudScale_DevTools::LOGS_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $mu_dir = WP_CONTENT_DIR . '/mu-plugins';
        if ( ! is_dir( $mu_dir ) ) {
            wp_mkdir_p( $mu_dir );
        }

        $uid = posix_getuid();
        if ( $uid !== 0 ) {
            wp_send_json_error( [ 'message' => __( 'PHP is not running as root — cannot chown automatically. Run manually: docker exec pi_wordpress chown www-data:www-data /var/www/html/wp-content/mu-plugins', 'cloudscale-devtools' ) ] );
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions
        $ok = @chown( $mu_dir, 'www-data' ) && @chgrp( $mu_dir, 'www-data' );
        if ( ! $ok ) {
            wp_send_json_error( [ 'message' => __( 'chown failed. Run manually: docker exec pi_wordpress chown www-data:www-data /var/www/html/wp-content/mu-plugins', 'cloudscale-devtools' ) ] );
            return;
        }

        wp_send_json_success( [ 'message' => __( 'Permissions fixed. mu-plugins is now writable.', 'cloudscale-devtools' ) ] );
    }

    public static function ajax_logs_custom_save(): void {
        check_ajax_referer( CloudScale_DevTools::LOGS_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $raw   = isset( $_POST['paths'] ) ? wp_unslash( $_POST['paths'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $paths = json_decode( $raw, true );
        if ( ! is_array( $paths ) ) {
            $paths = [];
        }

        $clean = [];
        foreach ( $paths as $p ) {
            $label = sanitize_text_field( $p['label'] ?? '' );
            $path  = sanitize_text_field( $p['path']  ?? '' );
            if ( $label !== '' && $path !== '' ) {
                $clean[] = [ 'label' => $label, 'path' => $path ];
            }
        }

        update_option( 'csdt_custom_log_paths', $clean );
        wp_send_json_success( [ 'sources' => self::get_log_sources() ] );
    }

    private static function render_debug_panel(): void {
        $has_key = CSDT_AI_Dispatcher::has_key();
        $key_url   = admin_url( 'tools.php?page=' . self::TOOLS_SLUG . '&tab=security' );
        $perf_on   = get_option( 'csdt_devtools_perf_monitor_enabled', '1' ) !== '0';
        ?>

        <!-- ── CS Monitor toggle ── -->
        <div class="cs-panel" id="cs-panel-cs-monitor" style="margin-bottom:12px;">
            <div class="cs-section-header" style="background:linear-gradient(90deg,#0f4c75 0%,#1b6ca8 100%);border-left:3px solid #38bdf8;">
                <span>⚡ <?php esc_html_e( 'CS Monitor', 'cloudscale-devtools' ); ?></span>
                <span class="cs-header-hint"><?php esc_html_e( 'Frontend performance overlay panel', 'cloudscale-devtools' ); ?></span>
            </div>
            <div class="cs-panel-body" style="padding:14px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;flex:1;min-width:200px;">
                    <input type="checkbox" id="cs-perf-monitor-toggle" <?php checked( $perf_on ); ?>>
                    <span style="font-size:13px;color:#1d2327;"><?php esc_html_e( 'Show the ⚡ CS Monitor performance panel on all pages', 'cloudscale-devtools' ); ?></span>
                </label>
                <span class="cs-hint" style="flex:2;min-width:200px;"><?php esc_html_e( 'Visible to admins only. Tracks DB queries, HTTP requests, PHP errors, and hook counts. Disable in production when not debugging.', 'cloudscale-devtools' ); ?></span>
                <div style="display:flex;align-items:center;gap:10px;">
                    <button type="button" id="cs-perf-monitor-save" class="cs-btn-primary cs-btn-sm">💾 <?php esc_html_e( 'Save', 'cloudscale-devtools' ); ?></button>
                    <span id="cs-perf-monitor-saved" class="cs-settings-saved">✓ <?php esc_html_e( 'Saved', 'cloudscale-devtools' ); ?></span>
                </div>
            </div>
        </div>

        <div class="cs-panel" id="cs-panel-debug">
            <div class="cs-section-header" style="background:linear-gradient(90deg,#1e3a5f 0%,#1d4ed8 100%);border-left:3px solid #60a5fa;">
                <span>🔔 <?php esc_html_e( 'Error Monitoring', 'cloudscale-devtools' ); ?></span>
                <span class="cs-header-hint"><?php esc_html_e( 'PHP error alerting and PHP-FPM saturation monitoring', 'cloudscale-devtools' ); ?></span>
            </div>
            <div style="padding:24px;">
                <!-- PHP Error Alerting settings -->
                <?php
                $mon_enabled   = get_option( 'csdt_php_error_monitor_enabled', '1' ) === '1';
                $mon_threshold = (int) get_option( 'csdt_php_error_monitor_threshold', '1' );
                $last_trigger  = get_option( 'csdt_php_error_monitor_last_trigger', null );
                $ntfy_set      = ! empty( get_option( 'csdt_scan_schedule_ntfy_url', '' ) );
                $last_pos      = get_option( 'csdt_php_error_last_pos', [] );
                ?>
                <div class="cs-panel" style="margin:0;">
                    <div class="cs-section-header" style="background:linear-gradient(90deg,#92400e 0%,#b45309 100%);border-left:3px solid #fcd34d;">
                        <span>🔔 <?php esc_html_e( 'PHP Error Alerting', 'cloudscale-devtools' ); ?></span>
                        <span class="cs-header-hint"><?php esc_html_e( 'Polls PHP + WP debug logs every 5 min — alerts via email and ntfy.sh when new fatals appear', 'cloudscale-devtools' ); ?></span>
                        <?php self::render_explain_btn( 'php-error-alerting', 'PHP Error Alerting', [
                            [ 'name' => 'How it works',     'rec' => 'Info',        'html' => 'A WP-Cron job runs every 5 minutes. It tracks the last byte-offset read for each log file and only reads <em>new</em> lines since the previous check — you are only alerted about errors that just appeared, not the full log history.' ],
                            [ 'name' => 'Alert channels',   'rec' => 'Recommended', 'html' => '<strong>Email</strong> — sent to the WordPress admin email address. <strong>ntfy.sh</strong> — instant push notification to any device with the ntfy app. Configure your ntfy topic URL under <strong>Security Scan → Scheduled Scans → Notification URL</strong>. Both channels fire independently.' ],
                            [ 'name' => 'Threshold',        'rec' => 'Info',        'html' => 'Minimum new errors per 5-minute check before an alert fires. Set to <code>1</code> to be notified about every error. <strong>PHP Fatal</strong> and <strong>WordPress die()</strong> always trigger an alert regardless of threshold.' ],
                            [ 'name' => 'Log paths',        'rec' => 'Recommended', 'html' => 'Watches the same log sources configured in the Server Logs panel. If PHP is logging to <code>/dev/stderr</code> (default in many Docker setups), errors are not readable here. Enable the mu-plugin under <strong>Server Logs → PHP Error Log</strong> to redirect errors to <code>wp-content/php-error.log</code>.' ],
                        ] ); ?>
                        <div style="display:flex;align-items:center;gap:10px;margin-left:auto;">
                            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                                <input type="checkbox" id="csdt-errmon-enabled" <?php checked( $mon_enabled ); ?>>
                                <span style="font-size:.82em;color:rgba(255,255,255,.8);"><?php esc_html_e( 'Enabled', 'cloudscale-devtools' ); ?></span>
                            </label>
                            <button type="button" id="csdt-errmon-save" class="cs-btn-sm cs-btn-primary"><?php esc_html_e( 'Save', 'cloudscale-devtools' ); ?></button>
                            <span id="csdt-errmon-status" style="font-size:.82em;color:rgba(255,255,255,.8);"></span>
                        </div>
                    </div>
                    <div class="cs-panel-body" style="display:flex;flex-direction:column;gap:8px;font-size:.82em;color:#64748b;">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <span style="white-space:nowrap;"><?php esc_html_e( 'Alert after', 'cloudscale-devtools' ); ?></span>
                            <input type="number" id="csdt-errmon-threshold" min="1" max="50" value="<?php echo esc_attr( $mon_threshold ); ?>" style="width:52px;background:#fff;color:#1e293b;border:1px solid #d1d5db;border-radius:4px;padding:2px 6px;font-size:1em;text-align:center;">
                            <span style="white-space:nowrap;"><?php esc_html_e( 'new error(s) per check', 'cloudscale-devtools' ); ?></span>
                            <span style="color:#94a3b8;font-size:.9em;"><?php esc_html_e( '(fatals always alert)', 'cloudscale-devtools' ); ?></span>
                        </div>
                        <?php if ( ! $ntfy_set ) : ?>
                            <span style="color:#f59e0b;">
                                <?php
                                printf(
                                    wp_kses(
                                        /* translators: %s: link to site audit settings */
                                        __( '⚠ No ntfy.sh topic set — <a href="%s" style="color:#f59e0b;">configure in Site Audit → Scheduled Scans</a>', 'cloudscale-devtools' ),
                                        [ 'a' => [ 'href' => [], 'style' => [] ] ]
                                    ),
                                    esc_url( admin_url( 'tools.php?page=' . self::TOOLS_SLUG . '&tab=site-audit' ) )
                                );
                                ?>
                            </span>
                        <?php endif; ?>
                        <?php if ( $last_trigger ) : ?>
                            <span>
                                <?php
                                printf(
                                    /* translators: 1: human time diff, 2: fatal count, 3: error count */
                                    esc_html__( 'Last alert: %1$s ago (%2$d fatal, %3$d error)', 'cloudscale-devtools' ),
                                    esc_html( human_time_diff( (int) $last_trigger['ts'] ) ),
                                    (int) $last_trigger['fatal'],
                                    (int) $last_trigger['errors']
                                );
                                ?>
                            </span>
                        <?php elseif ( $mon_enabled && ! empty( $last_pos ) ) : ?>
                            <span style="color:#86efac;"><?php esc_html_e( 'Monitoring — no new errors detected', 'cloudscale-devtools' ); ?></span>
                        <?php elseif ( $mon_enabled ) : ?>
                            <span style="color:#94a3b8;"><?php esc_html_e( 'Will begin monitoring on next cron run (within 5 min)', 'cloudscale-devtools' ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <hr style="border:none;border-top:1px solid #e2e8f0;margin:28px 0;">

                <!-- PHP-FPM Saturation Monitor -->
                <?php
                $fpm_enabled          = get_option( 'csdt_fpm_enabled',          '1' ) === '1';
                $fpm_threshold        = (int) get_option( 'csdt_fpm_threshold',        '3' );
                $fpm_cooldown         = (int) get_option( 'csdt_fpm_cooldown',         '1800' );
                $fpm_probe_url        = get_option( 'csdt_fpm_probe_url',              'http://localhost:8082/' );
                $fpm_timeout          = (int) get_option( 'csdt_fpm_probe_timeout',    '5' );
                $fpm_wp_ctr           = get_option( 'csdt_fpm_wp_container',           'pi_wordpress' );
                $fpm_db_ctr           = get_option( 'csdt_fpm_db_container',           'pi_mariadb' );
                $fpm_auto_restart     = get_option( 'csdt_fpm_auto_restart',           '0' ) === '1';
                $fpm_restart_cooldown = (int) get_option( 'csdt_fpm_restart_cooldown', '1200' );
                $fpm_token            = get_option( 'csdt_fpm_token',                  '' );
                if ( empty( $fpm_token ) ) {
                    $fpm_token = wp_generate_password( 32, false );
                    update_option( 'csdt_fpm_token', $fpm_token, false );
                }
                $fpm_last       = get_option( 'csdt_fpm_last_event', null );
                $fpm_event_log  = is_array( get_option( 'csdt_fpm_event_log', [] ) ) ? get_option( 'csdt_fpm_event_log', [] ) : [];
                $fpm_report_url = admin_url( 'admin-ajax.php' );
                $fpm_auto_restart_val = $fpm_auto_restart ? 'true' : 'false';
                $fpm_config_snippet = "# ── PHP-FPM Saturation Monitor ─────────────────────────────────────────────\n"
                    . "FPM_SATURATION_THRESHOLD={$fpm_threshold}\n"
                    . "FPM_PROBE_URL={$fpm_probe_url}\n"
                    . "FPM_PROBE_TIMEOUT={$fpm_timeout}\n"
                    . "FPM_WP_CONTAINER={$fpm_wp_ctr}\n"
                    . "FPM_DB_CONTAINER={$fpm_db_ctr}\n"
                    . "FPM_ALERT_COOLDOWN={$fpm_cooldown}\n"
                    . "FPM_AUTO_RESTART={$fpm_auto_restart_val}\n"
                    . "FPM_RESTART_COOLDOWN={$fpm_restart_cooldown}\n"
                    . "FPM_CALLBACK_URL={$fpm_report_url}\n"
                    . "FPM_CALLBACK_TOKEN={$fpm_token}";
                ?>
                <div class="cs-panel" style="margin:0;">
                    <div class="cs-section-header" style="background:linear-gradient(90deg,#3730a3 0%,#6366f1 100%);border-left:3px solid #a5b4fc;">
                        <span>🖥️ <?php esc_html_e( 'PHP-FPM Saturation Monitor', 'cloudscale-devtools' ); ?></span>
                        <span style="display:inline-flex;align-items:center;padding:1px 8px;background:rgba(255,255,255,.15);border-radius:10px;font-size:.72em;color:#e0e7ff;margin-left:4px;">HOST CRON</span>
                        <span class="cs-header-hint"><?php esc_html_e( 'Detects when all PHP-FPM workers are exhausted. Runs on the host (not WP-Cron), so it fires even when PHP is fully saturated.', 'cloudscale-devtools' ); ?></span>
                        <?php self::render_explain_btn( 'fpm_monitor', 'PHP-FPM Saturation Monitor', [
                            [ 'name' => 'What is PHP-FPM saturation?', 'rec' => 'Info', 'html' => 'PHP-FPM (FastCGI Process Manager) maintains a pool of worker processes that handle requests. When all workers are busy (e.g. a traffic spike, a slow DB query holding workers open, or a runaway loop), new requests queue up and the site appears frozen or times out. This is called saturation.' ],
                            [ 'name' => 'Why a host cron, not WP-Cron?', 'rec' => 'Critical', 'html' => 'WP-Cron runs inside PHP-FPM. If PHP-FPM is fully saturated, WP-Cron can\'t execute — so a WordPress-based monitor would be silenced exactly when you need it most. This monitor runs as a shell script on the host OS (outside Docker), so it fires even when every PHP worker is consumed.' ],
                            [ 'name' => 'How the detection works', 'rec' => 'Info', 'html' => 'Every minute the script probes the HTTP URL. If the probe times out or fails N consecutive times (the threshold), saturation is declared. It then sends an ntfy.sh push notification and email alert, optionally restarts the WordPress container, and POSTs an event to this panel via the callback URL.' ],
                            [ 'name' => 'Current Workers display', 'rec' => 'Info', 'html' => 'Shows live active / idle / total worker counts from the PHP-FPM status page (<code>pm.status_path</code>). Requires <code>pm.status_path = /fpm-status</code> in your <code>www.conf</code> and a matching nginx location block. Click Refresh at any time to re-poll.' ],
                            [ 'name' => 'Auto-restart', 'rec' => 'Optional', 'html' => 'When enabled, the script issues a <code>docker restart {container}</code> command after declaring saturation. A restart cooldown prevents thrashing. Use with care on production — a restart drops all in-flight requests.' ],
                            [ 'name' => 'Setup', 'rec' => 'Info', 'html' => 'Copy the crontab line and config.env snippet from the Host Cron Setup section below. The callback URL and token wire the script back to this panel so saturation events appear in the audit trail automatically.' ],
                        ], 'Monitors PHP-FPM worker exhaustion from the host OS. Alerts via ntfy + email, can auto-restart the container, and logs events back to this panel.' ); ?>
                        <div style="display:flex;align-items:center;gap:10px;margin-left:auto;">
                            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                                <input type="checkbox" id="csdt-fpm-enabled" <?php checked( $fpm_enabled ); ?>>
                                <span style="font-size:.82em;color:rgba(255,255,255,.8);"><?php esc_html_e( 'Enabled', 'cloudscale-devtools' ); ?></span>
                            </label>
                            <button type="button" id="csdt-fpm-save" class="cs-btn-sm cs-btn-primary"><?php esc_html_e( 'Save', 'cloudscale-devtools' ); ?></button>
                            <span id="csdt-fpm-status" style="font-size:.82em;color:rgba(255,255,255,.8);"></span>
                        </div>
                    </div>
                    <div class="cs-panel-body">

                    <!-- Workers live status -->
                    <div style="background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                        <span style="font-size:.8em;color:#64748b;font-weight:600;"><?php esc_html_e( 'Current workers', 'cloudscale-devtools' ); ?></span>
                        <span id="csdt-fpm-workers-active" style="font-size:.82em;color:#1e293b;">
                            <span style="color:#64748b;"><?php esc_html_e( 'Active:', 'cloudscale-devtools' ); ?></span>
                            <span id="csdt-fpm-w-active" style="color:#dc2626;font-weight:700;">—</span>
                        </span>
                        <span style="font-size:.82em;color:#1e293b;">
                            <span style="color:#64748b;"><?php esc_html_e( 'Idle:', 'cloudscale-devtools' ); ?></span>
                            <span id="csdt-fpm-w-idle" style="color:#16a34a;font-weight:700;">—</span>
                        </span>
                        <span style="font-size:.82em;color:#1e293b;">
                            <span style="color:#64748b;"><?php esc_html_e( 'Total:', 'cloudscale-devtools' ); ?></span>
                            <span id="csdt-fpm-w-total" style="color:#374151;font-weight:700;">—</span>
                        </span>
                        <span style="font-size:.82em;color:#1e293b;">
                            <span style="color:#64748b;"><?php esc_html_e( 'Mem:', 'cloudscale-devtools' ); ?></span>
                            <span id="csdt-fpm-w-mem" style="color:#1e293b;font-weight:700;" title="Total memory across all workers">—</span>
                        </span>
                        <button type="button" id="csdt-fpm-workers-refresh" class="cs-btn-sm cs-btn-secondary" style="padding:5px 12px;font-size:.78em;line-height:1.4;">↻ <?php esc_html_e( 'Refresh', 'cloudscale-devtools' ); ?></button>
                        <button type="button" id="csdt-fpm-detail-toggle" class="cs-btn-sm cs-btn-secondary" style="padding:5px 12px;font-size:.78em;line-height:1.4;">▼ <?php esc_html_e( 'Workers', 'cloudscale-devtools' ); ?></button>
                        <button type="button" id="csdt-fpm-setup-btn" class="cs-btn-sm cs-btn-secondary" style="padding:5px 12px;font-size:.78em;line-height:1.4;background:#dbeafe;color:#1d4ed8;border-color:#93c5fd;">⚙ <?php esc_html_e( 'Setup Status Page', 'cloudscale-devtools' ); ?></button>
                        <span id="csdt-fpm-workers-status" style="font-size:.78em;color:#64748b;"></span>
                    </div>

                    <!-- Per-worker detail table -->
                    <div id="csdt-fpm-detail-panel" style="display:none;margin-bottom:14px;">
                        <div style="overflow-x:auto;">
                            <table id="csdt-fpm-detail-table" style="width:100%;border-collapse:collapse;font-size:.76em;color:#1e293b;">
                                <thead>
                                    <tr style="border-bottom:1px solid #e2e8f0;color:#64748b;text-align:left;">
                                        <th style="padding:5px 8px;white-space:nowrap;">PID</th>
                                        <th style="padding:5px 8px;white-space:nowrap;">State</th>
                                        <th style="padding:5px 8px;white-space:nowrap;">Reqs</th>
                                        <th style="padding:5px 8px;white-space:nowrap;">Running</th>
                                        <th style="padding:5px 8px;white-space:nowrap;">Last URI</th>
                                        <th style="padding:5px 8px;white-space:nowrap;">Script</th>
                                        <th style="padding:5px 8px;white-space:nowrap;" title="CPU% used by the last completed request. Running workers show — until their current request finishes.">Last CPU%</th>
                                        <th style="padding:5px 8px;white-space:nowrap;">Mem</th>
                                    </tr>
                                </thead>
                                <tbody id="csdt-fpm-detail-tbody">
                                    <tr><td colspan="8" style="padding:8px;color:#475569;">Loading…</td></tr>
                                </tbody>
                                <tfoot id="csdt-fpm-detail-tfoot"></tfoot>
                            </table>
                        </div>
                        <p style="margin:4px 0 8px;font-size:.72em;color:#475569;">Last CPU% = CPU used by the most recently <em>completed</em> request. Idle workers show their last value; Running workers show — because their current request hasn't finished yet.</p>
                        <div id="csdt-fpm-pool-info" style="margin-top:4px;font-size:.74em;color:#94a3b8;"></div>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px;">
                        <div>
                            <label style="display:block;font-size:.78em;color:#64748b;margin-bottom:4px;"><?php esc_html_e( 'Saturation threshold (consecutive checks)', 'cloudscale-devtools' ); ?></label>
                            <input type="number" id="csdt-fpm-threshold" min="1" max="30" value="<?php echo esc_attr( $fpm_threshold ); ?>" style="width:80px;background:#fff;color:#1e293b;border:1px solid #d1d5db;border-radius:4px;padding:3px 8px;font-size:.9em;">
                        </div>
                        <div>
                            <label style="display:block;font-size:.78em;color:#64748b;margin-bottom:4px;"><?php esc_html_e( 'Alert cooldown (seconds)', 'cloudscale-devtools' ); ?></label>
                            <input type="number" id="csdt-fpm-cooldown" min="60" max="86400" value="<?php echo esc_attr( $fpm_cooldown ); ?>" style="width:100px;background:#fff;color:#1e293b;border:1px solid #d1d5db;border-radius:4px;padding:3px 8px;font-size:.9em;">
                        </div>
                        <div>
                            <label style="display:block;font-size:.78em;color:#64748b;margin-bottom:4px;"><?php esc_html_e( 'HTTP probe URL', 'cloudscale-devtools' ); ?></label>
                            <input type="text" id="csdt-fpm-probe-url" value="<?php echo esc_attr( $fpm_probe_url ); ?>" style="width:100%;box-sizing:border-box;background:#fff;color:#1e293b;border:1px solid #d1d5db;border-radius:4px;padding:3px 8px;font-size:.9em;">
                        </div>
                        <div>
                            <label style="display:block;font-size:.78em;color:#64748b;margin-bottom:4px;"><?php esc_html_e( 'Probe timeout (seconds)', 'cloudscale-devtools' ); ?></label>
                            <input type="number" id="csdt-fpm-probe-timeout" min="1" max="30" value="<?php echo esc_attr( $fpm_timeout ); ?>" style="width:80px;background:#fff;color:#1e293b;border:1px solid #d1d5db;border-radius:4px;padding:3px 8px;font-size:.9em;">
                        </div>
                        <div>
                            <label style="display:block;font-size:.78em;color:#64748b;margin-bottom:4px;"><?php esc_html_e( 'WordPress container name', 'cloudscale-devtools' ); ?></label>
                            <input type="text" id="csdt-fpm-wp-container" value="<?php echo esc_attr( $fpm_wp_ctr ); ?>" style="width:100%;box-sizing:border-box;background:#fff;color:#1e293b;border:1px solid #d1d5db;border-radius:4px;padding:3px 8px;font-size:.9em;">
                        </div>
                        <div>
                            <label style="display:block;font-size:.78em;color:#64748b;margin-bottom:4px;"><?php esc_html_e( 'MariaDB container name', 'cloudscale-devtools' ); ?></label>
                            <input type="text" id="csdt-fpm-db-container" value="<?php echo esc_attr( $fpm_db_ctr ); ?>" style="width:100%;box-sizing:border-box;background:#fff;color:#1e293b;border:1px solid #d1d5db;border-radius:4px;padding:3px 8px;font-size:.9em;">
                        </div>
                        <div>
                            <label style="display:block;font-size:.78em;color:#64748b;margin-bottom:4px;"><?php esc_html_e( 'Auto-restart on saturation', 'cloudscale-devtools' ); ?></label>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:4px;">
                                <input type="checkbox" id="csdt-fpm-auto-restart" <?php checked( $fpm_auto_restart ); ?>>
                                <span style="font-size:.85em;color:#94a3b8;"><?php esc_html_e( 'Restart container automatically', 'cloudscale-devtools' ); ?></span>
                            </label>
                        </div>
                        <div>
                            <label style="display:block;font-size:.78em;color:#64748b;margin-bottom:4px;"><?php esc_html_e( 'Restart cooldown (seconds)', 'cloudscale-devtools' ); ?></label>
                            <input type="number" id="csdt-fpm-restart-cooldown" min="60" max="86400" value="<?php echo esc_attr( $fpm_restart_cooldown ); ?>" style="width:100px;background:#fff;color:#1e293b;border:1px solid #d1d5db;border-radius:4px;padding:3px 8px;font-size:.9em;">
                            <span style="font-size:.75em;color:#475569;margin-left:6px;"><?php /* translators: %d is the number of minutes */ echo esc_html( sprintf( __( '%d min', 'cloudscale-devtools' ), (int) round( $fpm_restart_cooldown / 60 ) ) ); ?></span>
                        </div>
                    </div>

                    <div style="background:#f1f5f9;border:1px solid #d1d5db;border-radius:6px;padding:14px 16px;margin-bottom:16px;">
                        <div style="font-size:.82em;color:#2563eb;font-weight:600;margin-bottom:10px;">📋 <?php esc_html_e( 'Host Cron Setup', 'cloudscale-devtools' ); ?></div>
                        <div style="font-size:.78em;color:#64748b;margin-bottom:4px;"><?php esc_html_e( 'Add to crontab on your Pi host (crontab -e):', 'cloudscale-devtools' ); ?></div>
                        <code style="display:block;background:#fff;border:1px solid #d1d5db;border-radius:4px;padding:8px 12px;font-size:.8em;color:#16a34a;white-space:nowrap;overflow-x:auto;margin-bottom:10px;">* * * * * /home/pi/pi2s3/fpm-saturation-monitor.sh 2&gt;/dev/null</code>
                        <div style="font-size:.78em;color:#64748b;margin-bottom:4px;"><?php esc_html_e( 'Add to ~/pi2s3/config.env (includes callback so last event appears above):', 'cloudscale-devtools' ); ?></div>
                        <code id="csdt-fpm-config-snippet" style="display:block;background:#fff;border:1px solid #d1d5db;border-radius:4px;padding:8px 12px;font-size:.78em;color:#374151;white-space:pre;overflow-x:auto;margin-bottom:10px;"><?php echo esc_html( $fpm_config_snippet ); ?></code>
                        <button type="button" id="csdt-fpm-copy-snippet" class="cs-btn-sm cs-btn-secondary"><?php esc_html_e( 'Copy config.env snippet', 'cloudscale-devtools' ); ?></button>
                        <span id="csdt-fpm-copy-status" style="font-size:.78em;color:#16a34a;margin-left:8px;"></span>
                    </div>

                    <!-- Event audit trail -->
                    <?php if ( ! empty( $fpm_event_log ) ) : ?>
                    <div style="margin-top:4px;">
                        <div style="font-size:.78em;color:#64748b;margin-bottom:6px;display:flex;align-items:center;justify-content:space-between;">
                            <span><?php /* translators: %d is the number of events */ printf( esc_html__( 'Last %d events (newest first)', 'cloudscale-devtools' ), count( $fpm_event_log ) ); ?></span>
                        </div>
                        <div style="max-height:240px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:6px;">
                            <table style="width:100%;border-collapse:collapse;font-size:.78em;">
                                <thead>
                                    <tr style="background:#f1f5f9;position:sticky;top:0;">
                                        <th style="text-align:left;padding:5px 10px;color:#475569;font-weight:600;white-space:nowrap;"><?php esc_html_e( 'Time', 'cloudscale-devtools' ); ?></th>
                                        <th style="text-align:left;padding:5px 10px;color:#475569;font-weight:600;"><?php esc_html_e( 'Event', 'cloudscale-devtools' ); ?></th>
                                        <th style="text-align:left;padding:5px 10px;color:#475569;font-weight:600;"><?php esc_html_e( 'Detail', 'cloudscale-devtools' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( $fpm_event_log as $i => $ev ) :
                                    $ev_color = match( $ev['type'] ?? '' ) {
                                        'recovered' => '#16a34a',
                                        'restarted' => '#d97706',
                                        default     => '#dc2626',
                                    };
                                    $ev_icon = match( $ev['type'] ?? '' ) {
                                        'recovered' => '✓',
                                        'restarted' => '🔄',
                                        default     => '🚨',
                                    };
                                    $ev_label = match( $ev['type'] ?? '' ) {
                                        'recovered' => __( 'Recovered', 'cloudscale-devtools' ),
                                        'restarted' => __( 'Auto-restarted', 'cloudscale-devtools' ),
                                        default     => __( 'Saturated', 'cloudscale-devtools' ),
                                    };
                                    $row_bg = $i % 2 === 0 ? '#ffffff' : '#f8fafc';
                                ?>
                                    <tr style="background:<?php echo esc_attr( $row_bg ); ?>;border-top:1px solid #e2e8f0;">
                                        <td style="padding:5px 10px;color:#64748b;white-space:nowrap;" title="<?php echo esc_attr( wp_date( 'Y-m-d H:i:s', (int) $ev['ts'] ) ); ?>">
                                            <?php echo esc_html( human_time_diff( (int) $ev['ts'] ) . ' ago' ); ?>
                                        </td>
                                        <td style="padding:5px 10px;white-space:nowrap;">
                                            <span style="color:<?php echo esc_attr( $ev_color ); ?>;"><?php echo $ev_icon . ' ' . esc_html( $ev_label ); ?></span>
                                        </td>
                                        <td style="padding:5px 10px;color:#94a3b8;"><?php echo esc_html( $ev['msg'] ?? '' ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php else : ?>
                    <div style="font-size:.82em;">
                        <span style="color:#475569;"><?php esc_html_e( 'No saturation events recorded yet. Install the host cron and set FPM_CALLBACK_URL + FPM_CALLBACK_TOKEN to enable the audit trail.', 'cloudscale-devtools' ); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- PHP-FPM Status Page Setup Modal (inline so it's always in the DOM with the button) -->
                <div id="csdt-fpm-setup-modal" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.7);align-items:center;justify-content:center;">
                    <div style="background:#fff;border:1px solid #d1d5db;border-radius:10px;max-width:620px;width:94%;padding:24px;position:relative;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15);">
                        <button id="csdt-fpm-setup-close" style="position:absolute;top:12px;right:14px;background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;line-height:1;" title="Close">✕</button>
                        <h3 style="margin:0 0 4px;font-size:15px;font-weight:700;color:#1e293b;">⚙ PHP-FPM Status Page Setup</h3>
                        <p style="font-size:12px;color:#64748b;margin:0 0 18px;">Enables the <code style="background:#f1f5f9;padding:1px 5px;border-radius:3px;color:#16a34a;">/fpm-status</code> endpoint so the Current Workers panel shows live counts.</p>
                        <div id="csdt-fpm-setup-steps" style="display:flex;gap:0;margin-bottom:20px;">
                            <?php foreach ( [ 1 => 'Detect', 2 => 'www.conf', 3 => 'nginx' ] as $n => $lbl ) : ?>
                            <div class="csdt-fpm-step" data-step="<?php echo $n; ?>" style="flex:1;text-align:center;padding:6px 0;font-size:11px;font-weight:600;border-bottom:2px solid #e2e8f0;color:#475569;"><?php echo $n; ?>. <?php echo esc_html( $lbl ); ?></div>
                            <?php endforeach; ?>
                        </div>
                        <div id="csdt-fpm-step-1">
                            <p style="font-size:13px;color:#94a3b8;margin:0 0 14px;">Scans for your PHP-FPM config file and probes common URLs to find nginx.</p>
                            <button type="button" id="csdt-fpm-detect-btn" class="button button-primary" style="font-size:13px;">🔍 Run Detection</button>
                            <div id="csdt-fpm-detect-result" style="margin-top:14px;font-size:12px;"></div>
                        </div>
                        <div id="csdt-fpm-step-2" style="display:none;">
                            <div id="csdt-fpm-patch-info" style="font-size:13px;color:#94a3b8;margin-bottom:14px;"></div>
                            <button type="button" id="csdt-fpm-patch-btn" class="button button-primary" style="font-size:13px;">✏️ Patch www.conf &amp; Reload php-fpm</button>
                            <div id="csdt-fpm-patch-result" style="margin-top:14px;font-size:12px;"></div>
                            <div style="margin-top:14px;display:flex;gap:8px;">
                                <button type="button" id="csdt-fpm-step2-next" class="button" style="font-size:12px;display:none;">Next →</button>
                                <button type="button" id="csdt-fpm-step2-skip" class="button" style="font-size:12px;">Skip (already done)</button>
                            </div>
                        </div>
                        <div id="csdt-fpm-step-3" style="display:none;">
                            <p style="font-size:13px;color:#475569;margin:0 0 10px;">Add this location block inside your nginx <code style="background:#f1f5f9;padding:1px 5px;border-radius:3px;color:#16a34a;">server {}</code> block, then reload nginx.</p>
                            <pre id="csdt-fpm-nginx-snippet" style="background:#f1f5f9;border:1px solid #d1d5db;border-radius:6px;padding:12px;font-size:.78em;color:#374151;overflow-x:auto;white-space:pre;margin:0 0 10px;"></pre>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                <button type="button" id="csdt-fpm-copy-nginx" class="button" style="font-size:12px;">📋 Copy snippet</button>
                                <span id="csdt-fpm-copy-nginx-status" style="font-size:12px;color:#16a34a;"></span>
                            </div>
                            <p style="font-size:12px;color:#64748b;margin:12px 0 6px;">Then reload nginx:</p>
                            <code id="csdt-fpm-nginx-reload-cmd" style="display:block;background:#f1f5f9;border:1px solid #d1d5db;border-radius:4px;padding:6px 10px;font-size:.78em;color:#16a34a;"></code>
                            <div style="margin-top:14px;display:flex;gap:8px;align-items:center;">
                                <button type="button" id="csdt-fpm-test-btn" class="button button-primary" style="font-size:12px;">✅ Test &amp; Finish</button>
                                <span id="csdt-fpm-test-result" style="font-size:12px;color:#94a3b8;"></span>
                            </div>
                        </div>
                    </div>
                    </div><!-- /.cs-panel-body -->
                </div><!-- /.cs-panel -->

            </div>
        </div>

        <!-- ── Uptime Monitor ────────────────────────────────────────────── -->
        <div class="cs-panel" id="cs-panel-uptime-monitor">
            <div class="cs-section-header cs-section-header-green">
                <span>⏱ <?php esc_html_e( 'Uptime Monitor', 'cloudscale-devtools' ); ?></span>
                <span class="cs-header-hint"><?php esc_html_e( 'WordPress pushes a heartbeat to a Cloudflare Worker every 3 minutes. No heartbeat for 8 minutes = site down alert.', 'cloudscale-devtools' ); ?></span>
                <?php self::render_explain_btn( 'uptime-monitor', 'Uptime Monitor', [
                    [ 'name' => 'Setup — step by step', 'rec' => 'Required',    'html' => '<ol style="margin:0;padding-left:1.4em;line-height:1.8;"><li><strong>Cloudflare credentials</strong> — Enter your Cloudflare Zone ID and an API Token with <code>Workers:Edit</code> and <code>Workers KV Storage:Edit</code> permissions, then click <em>Save Settings</em>.</li><li><strong>ntfy.sh Alert URL</strong> (optional) — Enter your ntfy.sh topic URL to receive push notifications when the site goes down or recovers.</li><li><strong>Deploy Worker</strong> — Click <em>Deploy Worker to Cloudflare</em>. This creates a KV namespace, uploads the Worker, and schedules the <code>* * * * *</code> cron trigger.</li><li><strong>Host cron</strong> — Run <code>deploy-cf-worker.sh</code> on your server to install a host cron that triggers WP-Cron locally (bypasses Cloudflare cache). WP-Cron pushes a heartbeat to the Worker every 3 minutes. The cron line is shown in the Host cron section below.</li><li><strong>Test</strong> — Click <em>Test Endpoint</em> to send a heartbeat to the Worker immediately and confirm the connection.</li></ol>' ],
                    [ 'name' => 'How it works',          'rec' => 'Overview',    'html' => 'A Pi host cron hits <code>http://127.0.0.1:PORT/wp-cron.php</code> (localhost, bypasses Cloudflare cache) every minute. PHP-FPM processes the request, WP-Cron runs, and WordPress pushes a small heartbeat POST to the Cloudflare Worker every 3 minutes. The Worker stores the timestamp in CF KV and its own cron checks every minute: no heartbeat for 8+ minutes = site down, ntfy fires. Recovery alert is sent when heartbeats resume. Localhost is required because Cloudflare caches the public wp-cron.php URL and returns a cached 200 without PHP ever executing.' ],
                    [ 'name' => 'Down vs recovery',      'rec' => 'Overview',    'html' => 'Down alerts fire after ~8 minutes of missed heartbeats. Repeat alerts are throttled to once every 30 minutes while the site remains down. Recovery fires as soon as one heartbeat arrives after a down period, with the total outage duration included in the message.' ],
                    [ 'name' => 'Test Alert (pause 5 min)', 'rec' => 'Testing',  'html' => 'Click <em>Test Alert (pause 5 min)</em> to pause heartbeats for 5 minutes. After about 8 minutes of silence the Cloudflare Worker will treat the site as down and fire your ntfy down alert. When the 5-minute pause ends, heartbeats resume automatically and you should receive a recovery alert. A live countdown is shown while paused. Click <em>Cancel Pause</em> at any time to resume heartbeats immediately (you will still get a recovery alert once the next heartbeat is sent).' ],
                    [ 'name' => 'Alert notifications',   'rec' => 'Recommended', 'html' => 'Enter your ntfy.sh topic URL (e.g. <code>https://ntfy.sh/your-topic</code>) to receive instant push notifications. Alerts fire from the Cloudflare edge — they arrive even if your PHP, database, and server are all offline, because the down detection happens in the Worker watching for stale heartbeats.' ],
                    [ 'name' => 'KV storage',            'rec' => 'Info',        'html' => 'A Cloudflare KV namespace (<code>csdt-uptime-state</code>) is created automatically on first deploy. It stores three small values: last heartbeat timestamp, down-since timestamp, and last-alert timestamp. The deploy script and Deploy button both handle KV creation — no manual setup needed.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <div>
                    <p style="color:#4b5563;margin:0 0 6px;line-height:1.65;font-size:.95em;">
                        <?php esc_html_e( 'A host cron job hits WordPress locally every minute (bypassing Cloudflare cache), triggering a heartbeat push to the Cloudflare Worker every 3 minutes. If no heartbeat arrives for 8 minutes, the Worker fires a down alert via ntfy.sh. When heartbeats resume, a recovery alert is sent automatically with outage duration.', 'cloudscale-devtools' ); ?>
                    </p>
                    <div id="csdt-uptime-setup-wrap">
                        <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;margin-bottom:12px;">
                            <p style="margin:0 0 12px;font-weight:700;color:#0f172a;font-size:.9em;">Cloudflare credentials <span style="font-weight:400;color:#6b7280;">(<?php esc_html_e( 'required — API Token needs Workers:Edit and Workers KV Storage:Edit permissions', 'cloudscale-devtools' ); ?>)</span></p>
                            <div style="display:flex;flex-direction:column;gap:10px;max-width:420px;">
                                <div>
                                    <label for="csdt-cf-zone-id" style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Zone ID</label>
                                    <div style="position:relative;display:flex;align-items:center;">
                                        <?php $z = get_option( 'csdt_devtools_cf_zone_id', '' ); ?>
                                        <input id="csdt-cf-zone-id" type="text" class="cs-input" style="width:100%;padding-right:36px;"
                                               placeholder="<?php esc_attr_e( 'Cloudflare Zone ID', 'cloudscale-devtools' ); ?>"
                                               value="<?php echo esc_attr( $z ? str_repeat( '•', 16 ) . substr( $z, -4 ) : '' ); ?>"
                                               data-real="<?php echo esc_attr( $z ); ?>"
                                               data-masked="<?php echo esc_attr( $z ? str_repeat( '•', 16 ) . substr( $z, -4 ) : '' ); ?>"
                                               autocomplete="off">
                                        <button type="button" class="csdt-cf-eye-btn" data-target="csdt-cf-zone-id" title="Show / hide"
                                                style="position:absolute;right:8px;background:none;border:none;cursor:pointer;padding:0;color:#94a3b8;font-size:15px;line-height:1;">👁</button>
                                    </div>
                                </div>
                                <div>
                                    <label for="csdt-cf-api-token" style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">API Token</label>
                                    <div style="position:relative;display:flex;align-items:center;">
                                        <?php $t = get_option( 'csdt_devtools_cf_api_token', '' ); ?>
                                        <input id="csdt-cf-api-token" type="text" class="cs-input" style="width:100%;padding-right:36px;"
                                               placeholder="<?php esc_attr_e( 'Cloudflare API Token', 'cloudscale-devtools' ); ?>"
                                               value="<?php echo esc_attr( $t ? str_repeat( '•', 16 ) . substr( $t, -4 ) : '' ); ?>"
                                               data-real="<?php echo esc_attr( $t ); ?>"
                                               data-masked="<?php echo esc_attr( $t ? str_repeat( '•', 16 ) . substr( $t, -4 ) : '' ); ?>"
                                               autocomplete="new-password">
                                        <button type="button" class="csdt-cf-eye-btn" data-target="csdt-cf-api-token" title="Show / hide"
                                                style="position:absolute;right:8px;background:none;border:none;cursor:pointer;padding:0;color:#94a3b8;font-size:15px;line-height:1;">👁</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;margin-bottom:16px;">
                            <p style="margin:0 0 10px;font-weight:700;color:#0f172a;font-size:.9em;">ntfy.sh Alert URL <span style="font-weight:400;color:#6b7280;">(<?php esc_html_e( 'optional — push notification when site goes down or recovers', 'cloudscale-devtools' ); ?>)</span></p>
                            <input id="csdt-uptime-ntfy-url" type="text" class="cs-input" style="max-width:420px;"
                                   placeholder="https://ntfy.sh/your-topic"
                                   value="<?php echo esc_attr( get_option( 'csdt_uptime_ntfy_url', get_option( 'csdt_scan_schedule_ntfy_url', '' ) ) ); ?>">
                        </div>
                        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                            <button id="csdt-uptime-save-btn" class="cs-btn-secondary">
                                💾 <?php esc_html_e( 'Save Settings', 'cloudscale-devtools' ); ?>
                            </button>
                            <span id="csdt-uptime-save-status" style="display:none;font-size:.88em;"></span>
                        </div>
                        <div style="margin-top:16px;padding-top:16px;border-top:1px solid #e5e7eb;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                            <button id="csdt-uptime-deploy-btn" class="cs-btn-primary">
                                🚀 <?php esc_html_e( 'Deploy Worker to Cloudflare', 'cloudscale-devtools' ); ?>
                            </button>
                            <button id="csdt-uptime-test-btn" class="cs-btn-secondary">
                                🧪 <?php esc_html_e( 'Test Endpoint', 'cloudscale-devtools' ); ?>
                            </button>
                            <span id="csdt-uptime-deploying" style="display:none;color:#6b7280;font-size:13px;">⏳ <?php esc_html_e( 'Deploying…', 'cloudscale-devtools' ); ?></span>
                        </div>
                        <div id="csdt-uptime-deploy-result" style="margin-top:12px;"></div>
                        <details style="margin-top:16px;">
                            <summary style="cursor:pointer;font-size:.85em;font-weight:600;color:#6366f1;">🛠 Manual deploy (copy-paste Worker script)</summary>
                            <div id="csdt-uptime-manual-wrap" style="margin-top:12px;"></div>
                        </details>
                        <details style="margin-top:10px;" open>
                            <summary style="cursor:pointer;font-size:.85em;font-weight:600;color:#374151;">⏱ Host cron — reliable every-3-minute heartbeats</summary>
                            <div style="margin-top:10px;background:#f1f5f9;border:1px solid #d1d5db;border-radius:6px;padding:12px 14px;">
                                <p style="margin:0 0 6px;font-size:.82em;color:#475569;"><?php esc_html_e( 'Must use localhost (not the public URL) — Cloudflare caches wp-cron.php and returns a cached 200 without WordPress ever executing. Hitting nginx directly bypasses CF. Replace PORT with your nginx host port (run', 'cloudscale-devtools' ); ?> <code>docker port pi_nginx 80/tcp</code><?php esc_html_e( '):', 'cloudscale-devtools' ); ?></p>
                                <code id="csdt-uptime-cron-line" style="display:block;background:#fff;border:1px solid #d1d5db;border-radius:4px;padding:8px 12px;font-size:.8em;color:#16a34a;word-break:break-all;margin-bottom:8px;">* * * * * curl -sf -m 10 -H 'Host: <?php echo esc_html( wp_parse_url( get_site_url(), PHP_URL_HOST ) ); ?>' 'http://127.0.0.1:PORT/wp-cron.php?doing_wp_cron' -o /dev/null 2&gt;/dev/null</code>
                                <p style="margin:0;font-size:.78em;color:#64748b;"><?php esc_html_e( 'If FPM is down nginx returns 502, curl exits non-zero, WP-Cron does not run, no heartbeat is pushed, and the CF Worker alerts after 8 minutes. deploy-cf-worker.sh detects the port and installs this automatically.', 'cloudscale-devtools' ); ?></p>
                            </div>
                        </details>
                    </div>
                    <div id="csdt-uptime-status-wrap" style="display:none;margin-top:4px;">
                        <div id="csdt-uptime-status-inner"></div>
                        <div style="margin-top:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <button id="csdt-uptime-refresh-btn" class="cs-btn-secondary" style="font-size:.82em;">↻ <?php esc_html_e( 'Push Heartbeat + Refresh', 'cloudscale-devtools' ); ?></button>
                            <button id="csdt-uptime-pause-btn" class="cs-btn-secondary" style="font-size:.82em;">🔕 <?php esc_html_e( 'Test Alert (pause 5 min)', 'cloudscale-devtools' ); ?></button>
                            <button id="csdt-uptime-cancel-pause-btn" class="cs-btn-secondary" style="font-size:.82em;display:none;">✕ <?php esc_html_e( 'Cancel Pause', 'cloudscale-devtools' ); ?></button>
                            <span id="csdt-uptime-push-status" style="display:none;font-size:.82em;"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php
        self::render_opcache_panel();
        self::render_server_logs_panel();
        self::render_sql_panel();
        CSDT_Custom_404::render_404_panel();
        self::render_settings_panel();
        self::render_migrate_panel();
    }

    private static function render_opcache_panel(): void {
        $opcache_token = get_option( 'csdt_opcache_token', '' );
        if ( empty( $opcache_token ) ) {
            $opcache_token = wp_generate_password( 32, false );
            update_option( 'csdt_opcache_token', $opcache_token, false );
        }
        $last_flush = (int) get_option( 'csdt_opcache_last_flush', 0 );
        ?>
        <div class="cs-panel" id="cs-panel-opcache">
            <div class="cs-section-header" style="background:linear-gradient(90deg,#164e63 0%,#0e7490 100%);border-left:3px solid #22d3ee;">
                <span>⚡ <?php esc_html_e( 'OPcache', 'cloudscale-devtools' ); ?></span>
                <span class="cs-header-hint"><?php esc_html_e( 'PHP OPcache status, statistics, and flush control', 'cloudscale-devtools' ); ?></span>
                <?php self::render_explain_btn( 'opcache', 'OPcache', [
                    [ 'name' => 'What is OPcache?',        'rec' => 'Info',     'html' => 'PHP OPcache compiles PHP scripts to bytecode and caches them in shared memory, so they don\'t need to be parsed and compiled on every request. This typically reduces response time by 30-50% and is enabled by default in PHP 5.5+.' ],
                    [ 'name' => 'When to flush',           'rec' => 'Info',     'html' => 'OPcache must be flushed after PHP files change on disk, otherwise running workers may serve stale bytecode. This can cause <strong>SIGSEGV crashes</strong> if a worker has an old cached version that conflicts with the new file. The deploy script flushes OPcache automatically via this endpoint after every plugin update.' ],
                    [ 'name' => 'Deploy script flush',     'rec' => 'Recommended', 'html' => 'The <code>deploy-wordpress.sh</code> script calls this endpoint using the deploy token after syncing plugin files. This ensures PHP-FPM workers reload fresh bytecode without a full container restart. The token is stored in <code>csdt_opcache_token</code> and is separate from other credentials.' ],
                    [ 'name' => 'Hit rate',                'rec' => 'Info',     'html' => 'A healthy OPcache hit rate is above 95%. Low hit rates (below 70%) indicate the cache is too small for your codebase, or that scripts are being invalidated too frequently. Increase <code>opcache.memory_consumption</code> in <code>php.ini</code> if the cache is frequently full.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">

                <div id="csdt-opcache-stats-wrap">
                    <div id="csdt-opcache-stats" style="font-size:.82em;color:#64748b;"><?php esc_html_e( 'Loading…', 'cloudscale-devtools' ); ?></div>
                </div>

                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px;">
                    <button type="button" id="csdt-opcache-flush-btn" class="cs-btn-primary">
                        ⚡ <?php esc_html_e( 'Flush OPcache', 'cloudscale-devtools' ); ?>
                    </button>
                    <button type="button" id="csdt-opcache-refresh-btn" class="cs-btn-secondary">
                        🔄 <?php esc_html_e( 'Refresh Stats', 'cloudscale-devtools' ); ?>
                    </button>
                    <span id="csdt-opcache-status" style="font-size:.82em;color:#16a34a;"></span>
                </div>

                <div style="margin-top:18px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;">
                    <div style="font-size:.78em;color:#64748b;margin-bottom:6px;"><?php esc_html_e( 'Deploy token — used by deploy-wordpress.sh to flush OPcache after each deploy', 'cloudscale-devtools' ); ?></div>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <code id="csdt-opcache-token-display" style="font-size:.78em;background:#f1f5f9;padding:4px 10px;border-radius:4px;color:#475569;word-break:break-all;"><?php echo esc_html( substr( $opcache_token, 0, 8 ) . str_repeat( '•', 16 ) ); ?></code>
                        <button type="button" id="csdt-opcache-token-reveal" class="cs-btn-secondary cs-btn-sm"><?php esc_html_e( 'Reveal', 'cloudscale-devtools' ); ?></button>
                        <button type="button" id="csdt-opcache-token-copy" class="cs-btn-secondary cs-btn-sm" data-token="<?php echo esc_attr( $opcache_token ); ?>"><?php esc_html_e( 'Copy', 'cloudscale-devtools' ); ?></button>
                        <span id="csdt-opcache-copy-status" style="font-size:.78em;color:#16a34a;"></span>
                    </div>
                    <div style="margin-top:10px;font-size:.75em;color:#475569;">
                        <?php esc_html_e( 'Last flush:', 'cloudscale-devtools' ); ?>
                        <span id="csdt-opcache-last-flush" style="color:#94a3b8;">
                            <?php echo $last_flush ? esc_html( human_time_diff( $last_flush ) . ' ago' ) : esc_html__( 'never', 'cloudscale-devtools' ); ?>
                        </span>
                    </div>
                </div>

            </div>
        </div>
        <?php
    }

    private static function render_server_logs_panel(): void {
        $sources        = self::get_log_sources();
        $php_configured = ! empty( get_option( 'csdt_php_error_log_path', '' ) );
        $custom_paths   = get_option( 'csdt_custom_log_paths', [] );
        ?>
        <div class="cs-panel" id="cs-panel-logs">
            <div class="cs-section-header" style="background:linear-gradient(90deg,#1e3a8a 0%,#1d4ed8 100%);border-left:3px solid #60a5fa;">
                <span>📋 <?php esc_html_e( 'Server Logs', 'cloudscale-devtools' ); ?></span>
                <span class="cs-header-hint"><?php esc_html_e( 'Read-only view of PHP error log, WordPress debug log, and web server logs', 'cloudscale-devtools' ); ?></span>
                <?php self::render_explain_btn( 'server-logs', 'Server Logs', [
                    [ 'name' => 'Log Sources',       'rec' => 'Info',        'html' => 'The panel automatically detects common log file locations for your server stack — Apache, Nginx, PHP-FPM, WordPress debug log, and any custom paths you add. Each source button shows a colour-coded status: <strong>green</strong> (readable), <strong>amber</strong> (empty), <strong>red</strong> (not found or permission denied).' ],
                    [ 'name' => 'PHP Error Log',     'rec' => 'Recommended', 'html' => 'If PHP is logging to <code>/dev/stderr</code> (the default in many Docker/container setups), errors cannot be read here. Click <strong>Enable</strong> to install a mu-plugin that redirects PHP errors to <code>wp-content/php-error.log</code>. The mu-plugin runs on every request before other plugins load.' ],
                    [ 'name' => 'Filters',           'rec' => 'Info',        'html' => '<ul><li><strong>Search</strong> — live text filter across all visible lines</li><li><strong>Level</strong> — show only lines at or above a severity (emergency → debug)</li><li><strong>Lines</strong> — how many tail lines to fetch from the server (100–2000)</li></ul>Colour coding: red = error/critical, amber = warning, blue = notice/info, grey = debug.' ],
                    [ 'name' => 'Auto-refresh',      'rec' => 'Optional',    'html' => 'Enable <em>Tail mode</em> to poll the selected log every 30 seconds automatically. Useful when watching a running process or debugging a live issue without leaving the page.' ],
                    [ 'name' => 'Custom Log Paths',  'rec' => 'Optional',    'html' => 'Add any absolute file path your web server user can read. Common extras: application logs (Laravel <code>storage/logs/laravel.log</code>), cron output files, or a custom PHP-FPM pool log. Labels are free-text — choose something descriptive. Custom paths are saved as a WordPress option and survive plugin updates.' ],
                    [ 'name' => 'Permissions',       'rec' => 'Info',        'html' => 'System logs (e.g. <code>/var/log/syslog</code>, <code>/var/log/auth.log</code>) are typically owned by <code>root</code> and not readable by <code>www-data</code>. This is intentional OS hardening — the plugin shows a clear "permission denied" message rather than an error. To expose a system log, add your web server user to the <code>adm</code> group or use a log-shipping tool.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">

                <?php if ( ! $php_configured ) : ?>
                <?php $mu_dir = WP_CONTENT_DIR . '/mu-plugins'; $mu_writable = is_dir( $mu_dir ) && is_writable( $mu_dir ); ?>
                <div id="cs-logs-php-setup" style="padding:14px 16px;margin-bottom:16px;background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:6px;">
                        <strong style="font-size:13px;color:#92400e;"><?php esc_html_e( 'PHP Error Log not writing to a file', 'cloudscale-devtools' ); ?></strong>
                        <button type="button" class="cs-btn-primary cs-btn-sm" id="cs-logs-php-setup-btn"
                                style="white-space:nowrap;flex-shrink:0;"
                                <?php echo ! $mu_writable ? 'disabled title="' . esc_attr__( 'Fix the permissions warning first', 'cloudscale-devtools' ) . '"' : ''; ?>>
                            ⚡ <?php esc_html_e( 'Enable', 'cloudscale-devtools' ); ?>
                        </button>
                    </div>
                    <p style="margin:0 0 0;font-size:12px;color:#78350f;line-height:1.5;"><?php esc_html_e( 'PHP is currently logging to a system stream (e.g. /dev/stderr) that cannot be read here. Click Enable to install a mu-plugin that redirects PHP errors to wp-content/php-error.log.', 'cloudscale-devtools' ); ?></p>
                    <?php if ( ! $mu_writable ) : ?>
                    <div id="cs-logs-perm-warning" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:10px;padding:8px 12px;background:#fef3c7;border-radius:4px;font-size:12px;color:#78350f;">
                        <span style="flex:1;">⚠️ <?php esc_html_e( 'wp-content/mu-plugins is not writable by the web server.', 'cloudscale-devtools' ); ?></span>
                        <button type="button" class="cs-btn-secondary cs-btn-sm" id="cs-logs-fix-perm-btn" style="flex-shrink:0;white-space:nowrap;">
                            🔧 <?php esc_html_e( 'Fix Permissions', 'cloudscale-devtools' ); ?>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Source picker -->
                <div id="cs-logs-sources" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                    <?php foreach ( $sources as $key => $src ) : ?>
                        <button class="cs-btn-secondary cs-log-src-btn" data-source="<?php echo esc_attr( $key ); ?>">
                            <?php echo esc_html( $src['label'] ); ?>
                        </button>
                    <?php endforeach; ?>
                    <?php if ( empty( $sources ) ) : ?>
                        <span style="color:#888;font-size:13px;"><?php esc_html_e( 'No log paths detected on this server.', 'cloudscale-devtools' ); ?></span>
                    <?php endif; ?>
                </div>

                <!-- Toolbar -->
                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:10px;">
                    <input type="text" id="cs-logs-search" placeholder="<?php esc_attr_e( 'Search…', 'cloudscale-devtools' ); ?>"
                           style="flex:1;min-width:160px;max-width:320px;" class="cs-text-input">
                    <select id="cs-logs-level" class="cs-sec-select" style="width:auto;">
                        <option value=""><?php esc_html_e( 'All levels', 'cloudscale-devtools' ); ?></option>
                        <option value="error"><?php esc_html_e( 'Error+', 'cloudscale-devtools' ); ?></option>
                        <option value="warn"><?php esc_html_e( 'Warning only', 'cloudscale-devtools' ); ?></option>
                        <option value="notice"><?php esc_html_e( 'Notice only', 'cloudscale-devtools' ); ?></option>
                    </select>
                    <select id="cs-logs-lines" class="cs-sec-select" style="width:auto;">
                        <option value="100">100 lines</option>
                        <option value="300" selected>300 lines</option>
                        <option value="500">500 lines</option>
                        <option value="1000">1000 lines</option>
                    </select>
                    <button id="cs-logs-refresh" class="cs-btn-secondary">🔄 <?php esc_html_e( 'Refresh', 'cloudscale-devtools' ); ?></button>
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#9da5b4;cursor:pointer;">
                        <input type="checkbox" id="cs-logs-tail" style="cursor:pointer;">
                        <?php esc_html_e( 'Auto-refresh (30s)', 'cloudscale-devtools' ); ?>
                    </label>
                    <span id="cs-logs-status" style="font-size:12px;color:#888;margin-left:auto;"></span>
                </div>

                <!-- Log viewer -->
                <div id="cs-logs-viewer" style="
                    background:#f8fafc;
                    border:1px solid #d1d5db;
                    border-radius:6px;
                    padding:12px;
                    height:520px;
                    overflow-y:auto;
                    font-family:'SF Mono','Fira Code',monospace;
                    font-size:12px;
                    line-height:1.6;
                    color:#1e293b;
                ">
                    <div class="cs-logs-placeholder" style="color:#94a3b8;padding:20px;text-align:center;">
                        <?php esc_html_e( 'Select a log source above to view entries.', 'cloudscale-devtools' ); ?>
                    </div>
                </div>

                <!-- Custom log paths -->
                <div style="margin-top:20px;border-top:1px solid #e8edf5;padding-top:16px;">
                    <div style="font-weight:600;font-size:13px;color:#1d2327;margin-bottom:8px;">
                        <?php esc_html_e( 'Custom log paths', 'cloudscale-devtools' ); ?>
                    </div>
                    <p style="font-size:12px;color:#6b7280;margin:0 0 10px;">
                        <?php esc_html_e( 'Add any log file your web server user can read (e.g. a custom nginx log, a container log written to a shared volume, or any application log file).', 'cloudscale-devtools' ); ?>
                    </p>
                    <div id="cs-logs-custom-list">
                        <?php foreach ( (array) $custom_paths as $i => $cp ) : ?>
                        <div class="cs-logs-custom-row" style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">
                            <input type="text" class="cs-text-input cs-logs-custom-label" placeholder="<?php esc_attr_e( 'Label', 'cloudscale-devtools' ); ?>" value="<?php echo esc_attr( $cp['label'] ?? '' ); ?>" style="width:140px;flex-shrink:0;">
                            <input type="text" class="cs-text-input cs-logs-custom-path" placeholder="<?php esc_attr_e( '/path/to/file.log', 'cloudscale-devtools' ); ?>" value="<?php echo esc_attr( $cp['path'] ?? '' ); ?>" style="flex:1;min-width:0;">
                            <button type="button" class="cs-btn-secondary cs-btn-sm cs-logs-custom-remove" style="color:#dc2626;border-color:#fca5a5;flex-shrink:0;">✕</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;gap:8px;margin-top:8px;">
                        <button type="button" class="cs-btn-secondary cs-btn-sm" id="cs-logs-custom-add">+ <?php esc_html_e( 'Add path', 'cloudscale-devtools' ); ?></button>
                        <button type="button" class="cs-btn-primary cs-btn-sm" id="cs-logs-custom-save">💾 <?php esc_html_e( 'Save', 'cloudscale-devtools' ); ?></button>
                        <span id="cs-logs-custom-saved" class="cs-settings-saved">✓ <?php esc_html_e( 'Saved', 'cloudscale-devtools' ); ?></span>
                    </div>
                </div>

            </div>
        </div>
        <?php
    }

    public static function ajax_server_logs_status(): void {
        check_ajax_referer( CloudScale_DevTools::LOGS_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $sources  = self::get_log_sources();
        $statuses = [];
        foreach ( $sources as $key => $src ) {
            $path = $src['path'];
            if ( ! file_exists( $path ) ) {
                $statuses[ $key ] = [ 'status' => 'not_found' ];
            } elseif ( ! is_readable( $path ) ) {
                $statuses[ $key ] = [ 'status' => 'permission_denied' ];
            } elseif ( filesize( $path ) === 0 ) {
                $statuses[ $key ] = [ 'status' => 'empty' ];
            } else {
                $statuses[ $key ] = [ 'status' => 'ok' ];
            }
        }
        wp_send_json_success( $statuses );
    }

    public static function ajax_server_logs_fetch(): void {
        check_ajax_referer( CloudScale_DevTools::LOGS_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $source_key = isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : '';
        $lines_req  = min( 1000, max( 50, (int) ( $_POST['lines'] ?? 300 ) ) );
        $sources    = self::get_log_sources();

        if ( ! isset( $sources[ $source_key ] ) ) {
            wp_send_json_error( [ 'message' => 'Unknown source.' ] );
            return;
        }

        $path = $sources[ $source_key ]['path'];

        if ( ! file_exists( $path ) ) {
            wp_send_json_success( [ 'status' => 'not_found', 'lines' => [], 'count' => 0, 'path' => $path ] );
            return;
        }
        if ( ! is_readable( $path ) ) {
            wp_send_json_success( [ 'status' => 'permission_denied', 'lines' => [], 'count' => 0, 'path' => $path ] );
            return;
        }

        // Read last N lines efficiently without loading the entire file
        $handle = is_readable( $path ) ? fopen( $path, 'rb' ) : false;
        if ( ! $handle ) {
            wp_send_json_success( [ 'status' => 'error', 'lines' => [], 'count' => 0, 'path' => $path ] );
            return;
        }

        fseek( $handle, 0, SEEK_END );
        $file_size  = ftell( $handle );
        $chunk_size = 65536; // 64 KB chunks, read from end
        $buffer     = '';
        $pos        = $file_size;
        $line_count = 0;

        while ( $pos > 0 && $line_count < $lines_req + 1 ) {
            $read = min( $chunk_size, $pos );
            $pos -= $read;
            fseek( $handle, $pos );
            $buffer     = fread( $handle, $read ) . $buffer;
            $line_count = substr_count( $buffer, "\n" );
        }
        fclose( $handle );

        $all_lines = explode( "\n", $buffer );
        // Remove trailing empty line
        if ( end( $all_lines ) === '' ) { array_pop( $all_lines ); }
        $lines = array_slice( $all_lines, -$lines_req );

        wp_send_json_success( [
            'status' => count( $lines ) > 0 ? 'ok' : 'empty',
            'lines'  => $lines,
            'count'  => count( $lines ),
            'path'   => $path,
        ] );
    }

    private static function render_migrate_panel() {
        ?>
        <div class="cs-panel" id="cs-panel-migrator">
            <div class="cs-section-header">
                <span>🔄 CODE BLOCK MIGRATOR</span>
                <?php self::render_explain_btn( 'migrator', 'Code Block Migrator', [
                    [ 'name' => 'Scan Posts',    'rec' => 'Informational', 'html' => 'Scans all posts and pages for legacy WordPress <code>wp:code</code> and <code>wp:preformatted</code> blocks that can be upgraded to CloudScale Code Blocks with full syntax highlighting.' ],
                    [ 'name' => 'Preview',       'rec' => 'Recommended',   'html' => 'Shows a side-by-side before/after diff for each post <em>before</em> committing any changes, so you can review exactly what will be converted.' ],
                    [ 'name' => 'Migrate',       'rec' => 'Optional',      'html' => 'Converts detected legacy blocks to CloudScale format. Each post is saved with the converted markup.<br><br><strong>Take a backup first</strong> — this cannot be undone without one.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <p style="color:#555;margin:0 0 16px;font-size:13px;line-height:1.6">
                    <?php esc_html_e( 'Scan your posts for legacy WordPress code blocks, preview changes, then migrate one at a time or all at once.', 'cloudscale-devtools' ); ?>
                </p>

                <div class="cs-migrate-toolbar">
                    <button id="cs-scan-btn" class="cs-btn-primary" style="padding:8px 20px;font-size:13px">
                        <span class="dashicons dashicons-search" style="font-size:14px;width:14px;height:14px;margin-top:1px"></span> <?php esc_html_e( 'Scan Posts', 'cloudscale-devtools' ); ?>
                    </button>
                    <button id="cs-migrate-all-btn" class="cs-btn-orange" style="padding:8px 20px;font-size:13px" disabled>
                        <span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;margin-top:1px"></span> <?php esc_html_e( 'Migrate All Remaining', 'cloudscale-devtools' ); ?>
                    </button>
                    <span id="cs-scan-status" class="cs-status"></span>
                </div>

                <div id="cs-results-area">
                    <p class="cs-migrate-hint"><?php printf( __( 'Click %s to find all posts with legacy code blocks.', 'cloudscale-devtools' ), '<strong>' . esc_html__( 'Scan Posts', 'cloudscale-devtools' ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format string is hardcoded, only %s is user-visible and escaped above ?></p>
                </div>
            </div>
        </div>

        <div id="cs-preview-modal" class="cs-modal" style="display:none;">
            <div class="cs-modal-backdrop"></div>
            <div class="cs-modal-content">
                <div class="cs-modal-header">
                    <h2 id="cs-modal-title"><?php esc_html_e( 'Preview', 'cloudscale-devtools' ); ?></h2>
                    <button class="cs-modal-close">&times;</button>
                </div>
                <div class="cs-modal-body" id="cs-modal-body">
                    <?php esc_html_e( 'Loading...', 'cloudscale-devtools' ); ?>
                </div>
                <div class="cs-modal-footer">
                    <button id="cs-modal-migrate-btn" class="cs-btn-primary" data-post-id="" style="padding:8px 20px">
                        <span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Migrate This Post', 'cloudscale-devtools' ); ?>
                    </button>
                    <button class="cs-modal-close-btn" style="background:#fff;border:1.5px solid #dce3ef;border-radius:5px;padding:6px 16px;font-size:12px;font-weight:600;cursor:pointer"><?php esc_html_e( 'Cancel', 'cloudscale-devtools' ); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    /* ==================================================================
       5c. SQL Command panel
       ================================================================== */

    /**
     * Renders the SQL Command query panel including quick-query buttons.
     *
     * @since  1.6.0
     * @return void
     */
    private static function render_sql_panel() {
        global $wpdb;
        $prefix = $wpdb->prefix;
        ?>
        <div class="cs-panel" id="cs-panel-sql">
            <div class="cs-section-header cs-section-header-purple">
                <span>🗄️ <?php esc_html_e( 'SQL Query', 'cloudscale-devtools' ); ?></span>
                <span class="cs-header-hint"><code style="background:rgba(255,255,255,.15);padding:1px 6px;border-radius:3px;"><?php echo esc_html( $prefix ); ?></code> &nbsp;·&nbsp; ⚠ <?php esc_html_e( 'Read only (SELECT, SHOW, DESCRIBE, EXPLAIN)', 'cloudscale-devtools' ); ?></span>
                <?php self::render_explain_btn( 'sql', 'SQL Query Tool', [
                    [ 'name' => 'Read-only',     'rec' => 'Informational', 'html' => 'Only <code>SELECT</code>, <code>SHOW</code>, <code>DESCRIBE</code>, and <code>EXPLAIN</code> queries are permitted. Write operations (<code>INSERT</code>, <code>UPDATE</code>, <code>DELETE</code>, <code>DROP</code>, <code>ALTER</code>, <code>TRUNCATE</code>) are blocked to prevent accidental data loss.' ],
                    [ 'name' => 'Table Prefix',  'rec' => 'Informational', 'html' => 'Your WordPress table prefix is shown in the header. Use it in your queries, e.g. <code>SELECT * FROM wp_posts LIMIT 10</code> or <code>SELECT * FROM wp_options WHERE option_name = \'siteurl\'</code>.' ],
                    [ 'name' => 'Quick Queries', 'rec' => 'Recommended',   'html' => 'Use the preset queries below for common diagnostics without needing to write SQL from scratch. Press <code>Enter</code> or <code>Ctrl+Enter</code> to run a query, <code>Shift+Enter</code> to insert a newline.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <textarea id="cs-sql-input" class="cs-sql-textarea" placeholder="SELECT option_name, option_value FROM <?php echo esc_attr( $prefix ); ?>options WHERE option_name = 'siteurl';"></textarea>
                <div style="display:flex;align-items:center;gap:10px;margin-top:12px">
                    <button type="button" class="cs-btn-primary" id="cs-sql-run" style="padding:8px 20px;font-size:13px">▶ <?php esc_html_e( 'Run Query', 'cloudscale-devtools' ); ?></button>
                    <button type="button" class="cs-btn-pink" id="cs-sql-clear">🧹 <?php esc_html_e( 'Clear', 'cloudscale-devtools' ); ?></button>
                    <span id="cs-sql-status" style="font-size:12px;color:#888"></span>
                    <span style="margin-left:auto;font-size:11px;color:#999"><?php esc_html_e( 'Enter or Ctrl+Enter to run', 'cloudscale-devtools' ); ?></span>
                </div>

                <!-- Results subsection -->
                <div style="margin:24px 0 10px;padding-bottom:8px;border-bottom:2px solid #e2e8f0;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <span style="font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.06em;">📊 <?php esc_html_e( 'Results', 'cloudscale-devtools' ); ?></span>
                    <span id="cs-sql-meta" style="font-size:12px;color:#64748b;"></span>
                    <?php self::render_explain_btn( 'sql-results', 'SQL Results', [
                        [ 'name' => 'Table output',       'rec' => 'Informational', 'html' => 'Query results are shown in a scrollable table with column headers. HTTP URLs in cells are highlighted for easy identification.' ],
                        [ 'name' => 'Row count / timing', 'rec' => 'Informational', 'html' => 'The header shows the number of rows returned and the query execution time in milliseconds.' ],
                    ] ); ?>
                </div>
                <div id="cs-sql-results" style="overflow-x:auto;font-size:13px">
                    <div style="text-align:center;color:#999;padding:40px 0"><?php esc_html_e( 'Run a query to see results here', 'cloudscale-devtools' ); ?></div>
                </div>

                <!-- Quick Queries subsection -->
                <div style="margin:28px 0 12px;padding-bottom:8px;border-bottom:2px solid #e2e8f0;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <span style="font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.06em;">⚡ <?php esc_html_e( 'Quick Queries', 'cloudscale-devtools' ); ?></span>
                    <?php self::render_explain_btn( 'quick-queries', 'Quick Queries', [
                        [ 'name' => 'Health & Diagnostics', 'rec' => 'Recommended',   'html' => 'MySQL version, table sizes, connection limits, and WordPress table row counts at a glance. Good first check when diagnosing slow sites.' ],
                        [ 'name' => 'Content Summary',      'rec' => 'Informational', 'html' => 'Counts posts by type and status, revisions, auto-drafts, spam comments, and users for a quick content audit. Useful before a site migration.' ],
                        [ 'name' => 'Cleanup Candidates',   'rec' => 'Optional',      'html' => 'Identifies orphaned <code>postmeta</code> rows, expired transients, and bloated <code>wp_options</code> autoloaded rows that may be slowing down your database.' ],
                        [ 'name' => 'Security Checks',      'rec' => 'Optional',      'html' => 'Looks for <code>http://</code> (non-HTTPS) URLs or stale IP addresses in <code>wp_options</code> and post GUIDs — common indicators of old content or unfinished HTTP→HTTPS migrations.' ],
                    ] ); ?>
                </div>
                <p class="cs-quick-group-label">🏥 <?php esc_html_e( 'Health and Diagnostics', 'cloudscale-devtools' ); ?></p>
                <div class="cs-quick-grid">
                    <button type="button" class="cs-quick-btn cs-sql-quick" data-sql="SELECT @@version AS mysql_version, @@global.max_connections AS max_connections, @@global.wait_timeout AS wait_timeout_sec, @@global.max_allowed_packet / 1024 / 1024 AS max_packet_mb, DATABASE() AS current_db;">
                        🩺 <?php esc_html_e( 'Database health check', 'cloudscale-devtools' ); ?>
                    </button>
                    <button type="button" class="cs-quick-btn cs-sql-quick" data-sql="SELECT option_id, option_name, LEFT(option_value, 200) AS option_value_preview FROM <?php echo esc_attr( $prefix ); ?>options WHERE option_name IN ('siteurl','home','blogname','blogdescription','wp_version','db_version');">
                        🏠 <?php esc_html_e( 'Site identity options', 'cloudscale-devtools' ); ?>
                    </button>
                    <button type="button" class="cs-quick-btn cs-sql-quick" data-sql="SELECT table_name, engine, table_rows, ROUND(data_length/1024/1024, 2) AS data_mb, ROUND(index_length/1024/1024, 2) AS index_mb, ROUND((data_length + index_length)/1024/1024, 2) AS total_mb FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY (data_length + index_length) DESC;">
                        📊 <?php esc_html_e( 'Table names, sizes and rows', 'cloudscale-devtools' ); ?>
                    </button>
                </div>

                <p class="cs-quick-group-label">📈 <?php esc_html_e( 'Content Summary', 'cloudscale-devtools' ); ?></p>
                <div class="cs-quick-grid">
                    <button type="button" class="cs-quick-btn cs-sql-quick" data-sql="SELECT post_type, post_status, COUNT(*) AS total FROM <?php echo esc_attr( $prefix ); ?>posts GROUP BY post_type, post_status ORDER BY total DESC;">
                        📰 <?php esc_html_e( 'Posts by type and status', 'cloudscale-devtools' ); ?>
                    </button>
                    <button type="button" class="cs-quick-btn cs-sql-quick" data-sql="SELECT (SELECT COUNT(*) FROM <?php echo esc_attr( $prefix ); ?>posts WHERE post_status='publish') AS published_posts, (SELECT COUNT(*) FROM <?php echo esc_attr( $prefix ); ?>posts WHERE post_type='revision') AS revisions, (SELECT COUNT(*) FROM <?php echo esc_attr( $prefix ); ?>posts WHERE post_status='auto-draft') AS auto_drafts, (SELECT COUNT(*) FROM <?php echo esc_attr( $prefix ); ?>posts WHERE post_status='trash') AS trashed, (SELECT COUNT(*) FROM <?php echo esc_attr( $prefix ); ?>comments) AS total_comments, (SELECT COUNT(*) FROM <?php echo esc_attr( $prefix ); ?>comments WHERE comment_approved='spam') AS spam_comments, (SELECT COUNT(*) FROM <?php echo esc_attr( $prefix ); ?>users) AS users, (SELECT COUNT(*) FROM <?php echo esc_attr( $prefix ); ?>options WHERE option_name LIKE '%_transient_%') AS transients;">
                        📋 <?php esc_html_e( 'Site stats summary', 'cloudscale-devtools' ); ?>
                    </button>
                    <button type="button" class="cs-quick-btn cs-sql-quick" data-sql="SELECT ID, post_title, post_date, post_status FROM <?php echo esc_attr( $prefix ); ?>posts WHERE post_status = 'publish' AND post_type = 'post' ORDER BY post_date DESC LIMIT 20;">
                        📝 <?php esc_html_e( 'Latest 20 published posts', 'cloudscale-devtools' ); ?>
                    </button>
                </div>

                <p class="cs-quick-group-label">🧹 <?php esc_html_e( 'Bloat and Cleanup Checks', 'cloudscale-devtools' ); ?></p>
                <div class="cs-quick-grid">
                    <button type="button" class="cs-quick-btn cs-sql-quick" data-sql="SELECT COUNT(*) AS orphaned_postmeta FROM <?php echo esc_attr( $prefix ); ?>postmeta pm LEFT JOIN <?php echo esc_attr( $prefix ); ?>posts p ON pm.post_id = p.ID WHERE p.ID IS NULL;">
                        🗑️ <?php esc_html_e( 'Orphaned postmeta count', 'cloudscale-devtools' ); ?>
                    </button>
                    <button type="button" class="cs-quick-btn cs-sql-quick" data-sql="SELECT COUNT(*) AS expired_transients FROM <?php echo esc_attr( $prefix ); ?>options WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP();">
                        ⏰ <?php esc_html_e( 'Expired transients count', 'cloudscale-devtools' ); ?>
                    </button>
                    <button type="button" class="cs-quick-btn cs-sql-quick" data-sql="SELECT post_type, COUNT(*) AS total FROM <?php echo esc_attr( $prefix ); ?>posts WHERE post_type = 'revision' OR post_status = 'auto-draft' OR post_status = 'trash' GROUP BY post_type, post_status ORDER BY total DESC;">
                        📦 <?php esc_html_e( 'Revisions, drafts and trash', 'cloudscale-devtools' ); ?>
                    </button>
                    <button type="button" class="cs-quick-btn cs-sql-quick" data-sql="SELECT LEFT(option_name, 40) AS option_name, LENGTH(option_value) AS value_bytes FROM <?php echo esc_attr( $prefix ); ?>options WHERE autoload = 'yes' ORDER BY LENGTH(option_value) DESC LIMIT 30;">
                        ⚖️ <?php esc_html_e( 'Largest autoloaded options', 'cloudscale-devtools' ); ?>
                    </button>
                </div>

                <p class="cs-quick-group-label">🔍 <?php esc_html_e( 'URL and Migration Helpers', 'cloudscale-devtools' ); ?></p>
                <div class="cs-quick-grid">
                    <button type="button" class="cs-quick-btn cs-sql-quick" data-sql="SELECT option_id, option_name, option_value FROM <?php echo esc_attr( $prefix ); ?>options WHERE option_value LIKE '%http://andrewbaker%';">
                        🔗 <?php esc_html_e( 'HTTP references (andrewbaker)', 'cloudscale-devtools' ); ?>
                    </button>
                    <button type="button" class="cs-quick-btn cs-sql-quick" data-sql="SELECT ID, post_title, post_type, post_status, guid FROM <?php echo esc_attr( $prefix ); ?>posts WHERE guid LIKE '%http://%' LIMIT 50;">
                        📰 <?php esc_html_e( 'Posts with HTTP GUIDs', 'cloudscale-devtools' ); ?>
                    </button>
                    <button type="button" class="cs-quick-btn cs-sql-quick" data-sql="SELECT post_id, meta_key, LEFT(meta_value, 200) AS meta_value_preview FROM <?php echo esc_attr( $prefix ); ?>postmeta WHERE meta_value LIKE '%http://54.195%' LIMIT 50;">
                        🖥️ <?php esc_html_e( 'Old IP references (postmeta)', 'cloudscale-devtools' ); ?>
                    </button>
                    <button type="button" class="cs-quick-btn cs-sql-quick" data-sql="SELECT ID, post_title, post_type FROM <?php echo esc_attr( $prefix ); ?>posts WHERE post_status = 'publish' AND ID NOT IN (SELECT post_id FROM <?php echo esc_attr( $prefix ); ?>postmeta WHERE meta_key = '_csdt_devtools_seo_desc' AND meta_value != '') ORDER BY post_date DESC LIMIT 50;">
                        📝 <?php esc_html_e( 'Posts missing meta descriptions', 'cloudscale-devtools' ); ?>
                    </button>
                </div>

                <div style="margin-top:12px;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:14px 18px;">
                    <p style="margin:0 0 6px;font-size:13px;font-weight:600;color:#92400e;">🔧 <?php esc_html_e( 'Fix HTTP → HTTPS', 'cloudscale-devtools' ); ?></p>
                    <p style="margin:0 0 12px;font-size:12px;color:#78350f;line-height:1.5;"><?php esc_html_e( 'Runs WP-CLI search-replace server-side — safely handles serialised data. Dry Run previews the count without making changes.', 'cloudscale-devtools' ); ?></p>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <button type="button" id="cs-http-fix-dry" class="cs-btn-secondary cs-btn-sm" style="border-color:#f59e0b;color:#92400e;">🔍 <?php esc_html_e( 'Dry Run', 'cloudscale-devtools' ); ?></button>
                        <button type="button" id="cs-http-fix-run" class="cs-btn-primary cs-btn-sm" style="background:#d97706;border-color:#d97706;">⚡ <?php esc_html_e( 'Fix It', 'cloudscale-devtools' ); ?></button>
                        <span id="cs-http-fix-status" style="font-size:12px;color:#92400e;"></span>
                    </div>
                    <pre id="cs-http-fix-output" style="display:none;margin-top:12px;background:#f1f5f9;color:#1e293b;padding:10px 14px;border-radius:6px;font-size:11px;line-height:1.6;white-space:pre-wrap;word-break:break-all;max-height:200px;overflow-y:auto;border:1px solid #d1d5db;"></pre>
                </div>
            </div>
        </div>

        <?php
    }

    /* ==================================================================
       5d. Login Security panel
       ================================================================== */

    /**
     * Renders the Login Security admin panel (Hide Login + 2FA settings).
     *
     * @since  1.9.4
     * @return void
     */
    private static function render_login_panel(): void {
        $hide_on      = get_option( 'csdt_devtools_login_hide_enabled', '0' ) === '1';
        $slug         = get_option( 'csdt_devtools_login_slug', '' );
        $method       = get_option( 'csdt_devtools_2fa_method', 'off' );
        $force        = get_option( 'csdt_devtools_2fa_force_admins', '0' ) === '1';
        $user_id      = get_current_user_id();
        $totp_active  = get_user_meta( $user_id, 'csdt_devtools_totp_enabled', true ) === '1';
        $email_active = get_user_meta( $user_id, 'csdt_devtools_2fa_email_enabled', true ) === '1';
        $current_url  = empty( $slug ) ? wp_login_url() : home_url( '/' . $slug );

        // Success notice after email verification callback.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $email_just_activated = isset( $_GET['email_2fa_activated'] ) && '1' === $_GET['email_2fa_activated'];
        ?>

        <?php if ( $email_just_activated ) : ?>
        <div class="cs-modal-overlay" id="cs-email-verified-modal" role="dialog" aria-modal="true" aria-labelledby="cs-modal-title">
            <div class="cs-modal-card">
                <div class="cs-modal-icon">✅</div>
                <h2 class="cs-modal-title" id="cs-modal-title"><?php esc_html_e( 'Email Verified!', 'cloudscale-devtools' ); ?></h2>
                <p class="cs-modal-msg"><?php esc_html_e( 'Email 2FA is now active on your account. You\'ll receive a one-time code after each password login.', 'cloudscale-devtools' ); ?></p>
                <button type="button" class="cs-btn-primary cs-modal-btn" id="cs-email-modal-close">
                    <?php esc_html_e( 'Got it', 'cloudscale-devtools' ); ?>
                </button>
                <p class="cs-modal-auto"><?php esc_html_e( 'Closing in', 'cloudscale-devtools' ); ?> <span id="cs-modal-countdown">6</span>s…</p>
            </div>
        </div>
        <?php endif; ?>
        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['email_verify_expired'] ) && '1' === $_GET['email_verify_expired'] ) :
        ?>
        <div class="notice notice-error" style="margin:0 0 18px">
            <p>⏰ <strong><?php esc_html_e( 'Verification link expired. Please click Enable again to send a new one.', 'cloudscale-devtools' ); ?></strong></p>
        </div>
        <?php endif; ?>

        <!-- ── Hide Login ─────────────────────────────── -->
        <div class="cs-panel" id="cs-panel-hide-login">
            <div class="cs-section-header cs-section-header-purple">
                <span>🔒 HIDE LOGIN URL</span>
                <span class="cs-header-hint"><?php esc_html_e( 'Move wp-login.php to a secret address', 'cloudscale-devtools' ); ?></span>
                <?php self::render_explain_btn( 'hide-login', 'Hide Login URL', [
                    [ 'name' => 'Enable Hide Login',  'rec' => 'Critical',    'html' => 'Moves your login page to a secret URL. Direct requests to <code>/wp-login.php</code> immediately return a 404 and the hit is recorded in the Blocked Probes chart. This stops the vast majority of automated credential-stuffing bots — they probe the default path and give up when they get a 404.<br><br>If your Failed Login Attempts chart shows active events, enabling this is the single most effective first step.' ],
                    [ 'name' => 'Custom Login Path',  'rec' => 'Recommended', 'html' => 'The URL slug that serves your login page, e.g. <code>/my-secret-login</code>. Use letters, numbers, and hyphens only. The Randomise button generates a cryptographically random 16-character slug.<br><br><strong>⚠️ Save the full login URL somewhere safe before saving</strong> — if you forget it you will need SSH access to recover.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <p class="cs-login-desc"><?php esc_html_e( 'Disables direct access to wp-login.php and serves your login page at a custom URL. Bots and scanners that probe /wp-login.php will get a 404.', 'cloudscale-devtools' ); ?></p>

                <div class="cs-toggle-row">
                    <label class="cs-toggle-label">
                        <input type="checkbox" id="cs-hide-enabled" <?php checked( $hide_on ); ?>>
                        <span class="cs-toggle-switch"></span>
                        <span class="cs-toggle-text"><?php esc_html_e( 'Enable Hide Login', 'cloudscale-devtools' ); ?></span>
                    </label>
                </div>

                <div class="cs-field-row" style="margin-top:16px">
                    <div class="cs-field">
                        <label class="cs-label" for="cs-login-slug"><?php esc_html_e( 'Custom Login Path:', 'cloudscale-devtools' ); ?></label>
                        <div class="cs-slug-row">
                            <span class="cs-slug-base"><?php echo esc_html( trailingslashit( home_url() ) ); ?></span>
                            <input type="text" id="cs-login-slug" class="cs-input cs-slug-input"
                                   value="<?php echo esc_attr( $slug ); ?>"
                                   placeholder="my-secret-login"
                                   maxlength="60" autocomplete="off" spellcheck="false">
                            <button type="button" id="cs-login-slug-random" title="<?php esc_attr_e( 'Generate a random, unguessable login path', 'cloudscale-devtools' ); ?>" style="margin-left:8px;padding:5px 10px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;cursor:pointer;font-size:13px;white-space:nowrap;">🎲 <?php esc_html_e( 'Randomise', 'cloudscale-devtools' ); ?></button>
                        </div>
                        <span class="cs-hint"><?php esc_html_e( 'Letters, numbers, and hyphens only. Save this URL — you will need it to log in.', 'cloudscale-devtools' ); ?></span>
                        <span id="cs-slug-weak-warn" style="display:none;margin-top:4px;font-size:12px;font-weight:600;color:#92400e;">⚠ <?php esc_html_e( 'This looks guessable. Use the Randomise button for a secure path.', 'cloudscale-devtools' ); ?></span>
                    </div>
                </div>

                <div class="cs-login-current-url" style="margin-top:14px">
                    <span class="cs-label" style="display:inline"><?php esc_html_e( 'Current Login URL:', 'cloudscale-devtools' ); ?></span>
                    <a id="cs-current-login-url" href="<?php echo esc_url( $current_url ); ?>" target="_blank" style="margin-left:8px;font-size:13px;color:#1e6fd9"><?php echo esc_html( $current_url ); ?></a>
                </div>

                <div style="margin-top:18px;display:flex;align-items:center;gap:10px">
                    <button type="button" class="cs-btn-primary" id="cs-hide-save">💾 <?php esc_html_e( 'Save Settings', 'cloudscale-devtools' ); ?></button>
                    <span class="cs-settings-saved" id="cs-hide-saved">✓ <?php esc_html_e( 'Saved', 'cloudscale-devtools' ); ?></span>
                </div>
            </div>
        </div>

        <!-- ── Session Duration ──────────────────────── -->
        <?php
        $session_duration = get_option( 'csdt_devtools_session_duration', 'default' );
        $duration_options = [
            'default' => __( 'WordPress default (2 days / 14 days with Remember Me)', 'cloudscale-devtools' ),
            '1'       => __( '1 day', 'cloudscale-devtools' ),
            '7'       => __( '7 days', 'cloudscale-devtools' ),
            '14'      => __( '14 days', 'cloudscale-devtools' ),
            '30'      => __( '30 days', 'cloudscale-devtools' ),
            '90'      => __( '90 days', 'cloudscale-devtools' ),
            '365'     => __( '1 year', 'cloudscale-devtools' ),
        ];
        ?>
        <div class="cs-panel" id="cs-panel-session">
            <div class="cs-section-header cs-section-header-blue">
                <span>⏱ SESSION DURATION</span>
                <span class="cs-header-hint"><?php esc_html_e( 'How long login sessions stay valid', 'cloudscale-devtools' ); ?></span>
                <?php self::render_explain_btn( 'session-duration', 'Session Duration', [
                    [ 'name' => 'Session Lifetime',     'rec' => 'Recommended', 'html' => 'Sets how long the WordPress auth cookie stays valid before the user must log in again.<br><br><ul><li><strong>1–7 days</strong> — higher-security environments (banking, staging, admin-heavy sites)</li><li><strong>30–90 days</strong> — convenience for trusted personal devices</li><li><strong>WordPress default</strong> — 2 days (48 hours), or 14 days when "Remember Me" is checked at login</li></ul>' ],
                    [ 'name' => 'Remember Me & timing', 'rec' => 'Note',        'html' => 'When a custom duration is set, the <strong>Remember Me</strong> checkbox is overridden — all new sessions get the same lifetime regardless.<br><br>Changing this setting only affects <em>new</em> logins. Users already logged in keep their current session cookie until it expires or they log out.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <div class="cs-field-row">
                    <div class="cs-field">
                        <label class="cs-label" for="cs-session-duration"><?php esc_html_e( 'Session expires after:', 'cloudscale-devtools' ); ?></label>
                        <select id="cs-session-duration" class="cs-input" style="max-width:360px">
                            <?php foreach ( $duration_options as $val => $label ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $session_duration, (string) $val ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="cs-hint"><?php esc_html_e( 'Applies from the next login. Existing sessions are not affected.', 'cloudscale-devtools' ); ?></span>
                    </div>
                </div>
                <div style="margin-top:18px;display:flex;align-items:center;gap:10px">
                    <button type="button" class="cs-btn-primary" id="cs-session-save">💾 <?php esc_html_e( 'Save Settings', 'cloudscale-devtools' ); ?></button>
                    <span class="cs-settings-saved" id="cs-session-saved">✓ <?php esc_html_e( 'Saved', 'cloudscale-devtools' ); ?></span>
                </div>
            </div>
        </div>

        <!-- ── Brute-Force Protection ───────────────── -->
        <?php
        $bf_enabled            = get_option( 'csdt_devtools_brute_force_enabled', '1' );
        $bf_attempts           = get_option( 'csdt_devtools_brute_force_attempts', '5' );
        $bf_lockout            = get_option( 'csdt_devtools_brute_force_lockout', '10' );
        $bf_enum_protect       = get_option( 'csdt_devtools_enum_protect', '1' );
        $ntfy_login_valid      = get_option( 'csdt_ntfy_login_valid_user', '0' );
        $ntfy_login_invalid    = get_option( 'csdt_ntfy_login_invalid_user', '0' );
        $ntfy_configured       = ! empty( get_option( 'csdt_scan_schedule_ntfy_url', '' ) );
        $wplogin_stats         = get_option( 'csdt_wplogin_blocked_stats', [] );
        ?>
        <div class="cs-panel" id="cs-panel-brute-force">
            <div class="cs-section-header cs-section-header-red">
                <span>🔒 BRUTE-FORCE PROTECTION</span>
                <span class="cs-header-hint"><?php esc_html_e( 'Temporarily lock accounts after repeated failed logins', 'cloudscale-devtools' ); ?></span>
                <?php self::render_explain_btn( 'brute-force', 'Brute-Force Protection', [
                    [ 'name' => 'How it works',        'rec' => 'Info',        'html' => 'After <em>N</em> consecutive failed login attempts for the same username, the account is locked for the configured duration. The lock is <strong>per-username, not per-IP</strong> — distributed attacks hitting the same account from thousands of IPs are still caught. The counter resets automatically when the lockout expires.' ],
                    [ 'name' => 'Failed attempts',     'rec' => 'Recommended', 'html' => '<ul><li><code>3</code> — tightest, but may lock out users who mistype their password twice</li><li><code>5</code> — default, good balance for most sites</li><li><code>10</code> — more forgiving for sites with non-technical users</li></ul><br><strong>Unlock one account via SSH:</strong><br><code>wp transient delete csdt_devtools_bf_lock_$(php -r "echo md5(strtolower(\'USERNAME\'));") --path=/var/www/html</code><br><br><strong>Unlock all locked accounts:</strong><br><code>wp eval \'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"%csdt_devtools_bf_lock%\"");\' --path=/var/www/html</code>' ],
                    [ 'name' => 'Lockout period',      'rec' => 'Recommended', 'html' => 'Default is <code>10</code> minutes. The lock lifts automatically — no admin action needed.<br><br><ul><li><strong>10 min</strong> — default, stops automated attacks cold</li><li><strong>30–60 min</strong> — better for targeted attacks, small UX cost for forgotten-password users</li></ul>' ],
                    [ 'name' => 'Account enumeration', 'rec' => 'Critical',    'html' => '<p>WordPress by default reveals whether a username exists: wrong username → <em>"The username xyz is not registered."</em>, right username wrong password → <em>"The password is incorrect."</em> An attacker can automate this to map every account on your site in minutes.</p><p>Enable this to make both failures return the same generic message. The <strong>✓ Active</strong> badge appears next to the checkbox when it is on. Legitimate users who forget their username can still recover via <em>Lost your password?</em> using their email.</p>' ],
                    [ 'name' => 'Attack Origins map',  'rec' => 'Info',        'html' => 'A world map shows the country of origin for failed logins (amber circles) and wp-login.php blocked probes (red circles). Each type uses its own independent scale so low-volume countries still appear.<br><br>Country is resolved from the Cloudflare CF-IPCountry header first (zero overhead). If CF is absent, the DB-IP Lite database (~30 MB local file) is used as a fallback — download it using the button below the map. Auto-update keeps it current monthly. Data accumulates from new events.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <div class="cs-field-row">
                    <div class="cs-field">
                        <label class="cs-label">
                            <input type="checkbox" id="cs-bf-enabled" <?php checked( $bf_enabled, '1' ); ?>>
                            <?php esc_html_e( 'Enable brute-force account lockout', 'cloudscale-devtools' ); ?>
                        </label>
                        <span class="cs-hint"><?php esc_html_e( 'Locks the account after too many failed login attempts.', 'cloudscale-devtools' ); ?></span>
                    </div>
                </div>
                <div class="cs-field-row" id="cs-bf-options">
                    <div class="cs-field" style="margin-right:32px">
                        <label class="cs-label" for="cs-bf-attempts"><?php esc_html_e( 'Failed attempts before lockout:', 'cloudscale-devtools' ); ?></label>
                        <input type="number" id="cs-bf-attempts" class="cs-input" min="1" max="100"
                               value="<?php echo esc_attr( $bf_attempts ); ?>" style="max-width:100px">
                        <span class="cs-hint"><?php esc_html_e( 'Consecutive failures for the same username. Default: 5.', 'cloudscale-devtools' ); ?></span>
                    </div>
                    <div class="cs-field">
                        <label class="cs-label" for="cs-bf-lockout"><?php esc_html_e( 'Lockout duration (minutes):', 'cloudscale-devtools' ); ?></label>
                        <input type="number" id="cs-bf-lockout" class="cs-input" min="1" max="1440"
                               value="<?php echo esc_attr( $bf_lockout ); ?>" style="max-width:100px">
                        <span class="cs-hint"><?php esc_html_e( 'How long the account stays locked. Default: 10.', 'cloudscale-devtools' ); ?></span>
                    </div>
                </div>
                <div class="cs-field-row" style="margin-top:14px;">
                    <div class="cs-field">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:500;color:#334155;">
                            <input type="checkbox" id="cs-bf-enum-protect" <?php checked( $bf_enum_protect, '1' ); ?>>
                            <?php esc_html_e( 'Prevent account enumeration by using generic login error messages', 'cloudscale-devtools' ); ?>
                            <?php if ( $bf_enum_protect === '1' ) : ?>
                            <span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;background:#dcfce7;color:#166534;border:1px solid #86efac;white-space:nowrap;">✓ Active</span>
                            <?php endif; ?>
                        </label>
                        <span class="cs-hint" style="margin-top:4px;display:block;"><?php esc_html_e( 'Returns "Invalid username or password." for all credential failures — prevents attackers from discovering which usernames are registered on this site.', 'cloudscale-devtools' ); ?></span>
                    </div>
                </div>
                <!-- ntfy login alerts -->
                <div class="cs-field-row" style="margin-top:18px;border-top:1px solid #e2e8f0;padding-top:16px;">
                    <div class="cs-field">
                        <div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:8px;text-transform:uppercase;letter-spacing:.04em;">
                            🔔 <?php esc_html_e( 'ntfy.sh Login Alerts', 'cloudscale-devtools' ); ?>
                            <?php if ( ! $ntfy_configured ) : ?>
                            <span style="font-size:10px;font-weight:600;padding:2px 7px;border-radius:10px;background:#fef3c7;color:#92400e;border:1px solid #fcd34d;margin-left:6px;">
                                ⚠ <?php esc_html_e( 'ntfy not configured', 'cloudscale-devtools' ); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:500;color:#334155;margin-bottom:6px;">
                            <input type="checkbox" id="cs-ntfy-login-valid" <?php checked( $ntfy_login_valid, '1' ); ?>>
                            <?php esc_html_e( 'Alert on failed login for a valid (known) username', 'cloudscale-devtools' ); ?>
                            <span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;"><?php esc_html_e( 'High priority', 'cloudscale-devtools' ); ?></span>
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:500;color:#334155;">
                            <input type="checkbox" id="cs-ntfy-login-invalid" <?php checked( $ntfy_login_invalid, '1' ); ?>>
                            <?php esc_html_e( 'Alert on failed login for an unknown username', 'cloudscale-devtools' ); ?>
                            <span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;background:#fefce8;color:#854d0e;border:1px solid #fde047;"><?php esc_html_e( 'Default priority', 'cloudscale-devtools' ); ?></span>
                        </label>
                        <span class="cs-hint" style="margin-top:6px;display:block;"><?php esc_html_e( 'Security control changes (hide login off, brute-force off, 2FA off) always send an urgent ntfy alert regardless of these settings.', 'cloudscale-devtools' ); ?></span>
                    </div>
                </div>

                <div style="margin-top:18px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <button type="button" class="cs-btn-primary" id="cs-bf-save">💾 <?php esc_html_e( 'Save Settings', 'cloudscale-devtools' ); ?></button>
                    <span class="cs-settings-saved" id="cs-bf-saved">✓ <?php esc_html_e( 'Saved', 'cloudscale-devtools' ); ?></span>
                    <button type="button" id="cs-bf-test-btn" style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;border-radius:6px;padding:6px 14px;font-size:13px;font-weight:600;cursor:pointer;">🧪 <?php esc_html_e( 'Test Brute-Force Protection', 'cloudscale-devtools' ); ?></button>
                    <span id="cs-bf-test-result" style="display:none;font-size:13px;font-weight:600;padding:5px 12px;border-radius:6px;"></span>
                </div>

                <div style="margin-top:10px;font-size:12px;color:#374151;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px;line-height:1.7;">
                    🔒 <strong><?php esc_html_e( 'Account lock, not IP lock:', 'cloudscale-devtools' ); ?></strong>
                    <?php esc_html_e( 'Lockout is per-username. Distributed attacks using many IPs against the same account are still caught.', 'cloudscale-devtools' ); ?>
                </div>

                <div id="cs-bf-log-wrap" class="cs-bf-log-wrap">
                    <div class="cs-bf-log-header">
                        <span class="cs-bf-log-title">📊 <?php esc_html_e( 'Failed Login Attempts - Last 14 Days', 'cloudscale-devtools' ); ?></span>
                        <span id="cs-bf-log-total" class="cs-bf-log-total"></span>
                    </div>
                    <div class="cs-bf-layout">
                        <div id="cs-bf-chart" class="cs-bf-chart"></div>
                        <div id="cs-bf-table-wrap" class="cs-bf-table-wrap">
                            <div class="cs-bf-loading"><?php esc_html_e( 'Loading…', 'cloudscale-devtools' ); ?></div>
                        </div>
                    </div>
                </div>

                <?php
                // ── wp-login.php blocked hits — chart + probe IP table ────────
                $ip_blocklist = get_option( 'csdt_ip_blocklist', [] );
                if ( ! is_array( $ip_blocklist ) ) { $ip_blocklist = []; }
                $daily_hits   = isset( $wplogin_stats['daily'] ) && is_array( $wplogin_stats['daily'] ) ? $wplogin_stats['daily'] : [];
                $today        = gmdate( 'Y-m-d' );
                $days         = [];
                for ( $i = 6; $i >= 0; $i-- ) {
                    $d        = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
                    $days[$d] = $daily_hits[$d] ?? 0;
                }
                $total_hits   = array_sum( $days );
                $last_hit_ts  = $wplogin_stats['last_ts'] ?? 0;
                $last_hit_ip  = $wplogin_stats['last_ip'] ?? '';
                $day_max      = max( 1, max( array_values( $days ) ) );
                $day_mid      = (int) round( $day_max / 2 );
                $ip_stats_raw = isset( $wplogin_stats['ip_stats'] ) && is_array( $wplogin_stats['ip_stats'] ) ? $wplogin_stats['ip_stats'] : [];
                uasort( $ip_stats_raw, fn( $a, $b ) => $b['last_ts'] - $a['last_ts'] );
                $probe_recent = array_slice( $ip_stats_raw, 0, 50, true );
                ?>
                <div style="margin-top:22px;border-top:1px solid #e8edf5;padding-top:20px;">
                    <div class="cs-bf-log-header">
                        <span class="cs-bf-log-title">🚫 <?php esc_html_e( 'wp-login.php Blocked — Last 7 Days', 'cloudscale-devtools' ); ?></span>
                        <span class="cs-bf-log-total"><?php echo (int) $total_hits; ?> blocked</span>
                    </div>
                    <div class="cs-bf-layout">
                        <div class="cs-bf-chart">
                            <div class="cs-bf-yaxis">
                                <span class="cs-bf-ytick"><?php echo esc_html( number_format( $day_max ) ); ?></span>
                                <span class="cs-bf-ytick"><?php echo esc_html( number_format( $day_mid ) ); ?></span>
                                <span class="cs-bf-ytick">0</span>
                            </div>
                            <?php foreach ( $days as $d => $cnt ) :
                                $pct      = $cnt > 0 ? max( 2, (int) round( $cnt / $day_max * 100 ) ) : 0;
                                $cls      = $cnt === 0 ? ' cs-bf-bar-zero' : ( $cnt >= $day_max * 0.75 ? ' cs-bf-bar-high' : ( $cnt >= $day_max * 0.4 ? ' cs-bf-bar-mid' : '' ) );
                                $lbl_clr  = $cnt === 0 ? '#16a34a' : '#64748b';
                            ?>
                            <div class="cs-bf-day" style="flex:1;min-width:28px;">
                                <div class="cs-bf-bar-track" style="position:relative;">
                                    <span style="position:absolute;top:-15px;left:50%;transform:translateX(-50%);font-size:9px;font-weight:700;color:<?php echo esc_attr( $lbl_clr ); ?>;white-space:nowrap;"><?php echo esc_html( number_format( $cnt ) ); ?></span>
                                    <div class="cs-bf-bar<?php echo esc_attr( $cls ); ?>"
                                         style="height:<?php echo $pct; ?>%;"
                                         title="<?php echo esc_attr( number_format( $cnt ) . ' blocked on ' . gmdate( 'M j', strtotime( $d ) ) ); ?>"></div>
                                </div>
                                <div class="cs-bf-day-label"><?php echo esc_html( gmdate( 'M j', strtotime( $d ) ) ); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="cs-bf-table-wrap">
                            <?php if ( ! empty( $probe_recent ) ) : ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;flex-wrap:wrap;gap:4px;">
                                <span style="font-size:11px;color:#64748b;"><?php echo esc_html( sprintf( _n( '%d IP', '%d IPs', count( $probe_recent ), 'cloudscale-devtools' ), count( $probe_recent ) ) ); ?></span>
                                <div style="display:flex;gap:4px;">
                                    <button type="button" id="cs-probe-sort-ts"
                                            style="font-size:10px;padding:2px 8px;border-radius:4px;border:1px solid #3b82f6;background:#3b82f6;color:#fff;cursor:pointer;font-weight:600;">
                                        Last attempt ↓
                                    </button>
                                    <button type="button" id="cs-probe-sort-cnt"
                                            style="font-size:10px;padding:2px 8px;border-radius:4px;border:1px solid #cbd5e1;background:#f8fafc;color:#475569;cursor:pointer;">
                                        Count ↓
                                    </button>
                                </div>
                            </div>
                            <table class="cs-bf-table" id="cs-probe-table">
                                <thead>
                                    <tr>
                                        <th class="cs-bf-th"><?php esc_html_e( 'Last attempt', 'cloudscale-devtools' ); ?></th>
                                        <th class="cs-bf-th"><?php esc_html_e( 'IP / Country', 'cloudscale-devtools' ); ?></th>
                                        <th class="cs-bf-th" style="text-align:right;"><?php esc_html_e( 'Attempts', 'cloudscale-devtools' ); ?></th>
                                        <th class="cs-bf-th"></th>
                                    </tr>
                                </thead>
                                <tbody id="cs-probe-tbody">
                                <?php foreach ( $probe_recent as $probe_ip => $probe_data ) :
                                    $probe_count  = array_sum( $probe_data['days'] ?? [] );
                                    $is_blocked   = isset( $ip_blocklist[ $probe_ip ] );
                                ?>
                                    <tr data-ts="<?php echo (int) $probe_data['last_ts']; ?>" data-cnt="<?php echo (int) $probe_count; ?>" data-ip="<?php echo esc_attr( $probe_ip ); ?>">
                                        <td class="cs-bf-td cs-bf-td-time"><?php
                                            $ts_diff = (int) ( time() - $probe_data['last_ts'] );
                                            if ( $ts_diff < 60 ) {
                                                echo esc_html( $ts_diff . ' secs ago' );
                                            } elseif ( $ts_diff < 3600 ) {
                                                echo esc_html( (int) floor( $ts_diff / 60 ) . ' min ago' );
                                            } elseif ( $ts_diff < 86400 ) {
                                                echo esc_html( (int) floor( $ts_diff / 3600 ) . 'h ago' );
                                            } else {
                                                echo esc_html( (int) floor( $ts_diff / 86400 ) . 'd ago' );
                                            }
                                        ?></td>
                                        <td class="cs-bf-td cs-bf-td-ip"><?php echo esc_html( $probe_ip ); ?>
                                            <?php if ( ! empty( $probe_data['country'] ) ) :
                                                $probe_cc   = strtoupper( $probe_data['country'] );
                                                $probe_flag = mb_chr( 0x1F1E6 + ord( $probe_cc[0] ) - 65 ) . mb_chr( 0x1F1E6 + ord( $probe_cc[1] ) - 65 );
                                            ?>
                                            <div style="font-size:10px;color:#64748b;margin-top:2px;"><?php echo esc_html( $probe_flag . ' ' . $probe_cc ); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="cs-bf-td" style="text-align:right;font-weight:700;color:#dc2626;"><?php echo number_format( (int) $probe_count ); ?></td>
                                        <td class="cs-bf-td" style="white-space:nowrap;text-align:right;">
                                            <a href="https://ipinfo.io/<?php echo esc_attr( $probe_ip ); ?>" target="_blank" rel="noopener"
                                               style="font-size:10px;padding:2px 6px;border:1px solid #cbd5e1;border-radius:4px;background:#f8fafc;color:#475569;text-decoration:none;margin-right:4px;">Whois</a>
                                            <?php if ( $is_blocked ) : ?>
                                            <span style="font-size:10px;padding:2px 6px;border:1px solid #86efac;border-radius:4px;background:#dcfce7;color:#15803d;font-weight:600;">🚫 Blocked</span>
                                            <?php else : ?>
                                            <button type="button" class="cs-ip-block-btn"
                                                    data-ip="<?php echo esc_attr( $probe_ip ); ?>"
                                                    style="font-size:10px;padding:2px 6px;border:1px solid #fca5a5;border-radius:4px;background:#fef2f2;color:#dc2626;cursor:pointer;font-weight:600;">Block</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- inline sort handler; wp_add_inline_script not available at this render point ?>
                            <script>
                            (function(){
                                var btnTs  = document.getElementById('cs-probe-sort-ts');
                                var btnCnt = document.getElementById('cs-probe-sort-cnt');
                                var tbody  = document.getElementById('cs-probe-tbody');
                                if (!btnTs || !btnCnt || !tbody) return;

                                function styleActive(active, inactive) {
                                    active.style.background   = '#3b82f6';
                                    active.style.color        = '#fff';
                                    active.style.borderColor  = '#3b82f6';
                                    inactive.style.background = '#f8fafc';
                                    inactive.style.color      = '#475569';
                                    inactive.style.borderColor = '#cbd5e1';
                                }

                                function sortBy(attr) {
                                    var rows = Array.from(tbody.querySelectorAll('tr'));
                                    rows.sort(function(a, b) {
                                        return parseInt(b.getAttribute(attr), 10) - parseInt(a.getAttribute(attr), 10);
                                    });
                                    rows.forEach(function(r) { tbody.appendChild(r); });
                                }

                                btnTs.addEventListener('click', function() {
                                    sortBy('data-ts');
                                    styleActive(btnTs, btnCnt);
                                });
                                btnCnt.addEventListener('click', function() {
                                    sortBy('data-cnt');
                                    styleActive(btnCnt, btnTs);
                                });
                            })();
                            </script>
                            <?php else : ?>
                            <div class="cs-bf-empty"><?php echo $total_hits > 0 ? esc_html__( 'Per-IP log populates as new probes arrive.', 'cloudscale-devtools' ) : esc_html__( 'No wp-login.php hits recorded yet.', 'cloudscale-devtools' ); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ( $last_hit_ts > 0 ) : ?>
                    <div style="font-size:12px;color:#475569;margin-top:8px;">
                        <?php esc_html_e( 'Last attempt:', 'cloudscale-devtools' ); ?>
                        <strong><?php echo esc_html( human_time_diff( $last_hit_ts ) . ' ago' ); ?></strong>
                        <?php if ( $last_hit_ip ) : ?>
                        &nbsp;from&nbsp;<code style="font-size:11px;"><?php echo esc_html( $last_hit_ip ); ?></code>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Attack Origins map -->
                    <div style="margin-top:22px;border-top:1px solid #e8edf5;padding-top:20px;">
                        <div class="cs-bf-log-header">
                            <span class="cs-bf-log-title">🌍 <?php esc_html_e( 'Attack Origins', 'cloudscale-devtools' ); ?></span>
                            <span style="display:flex;align-items:center;gap:12px;font-size:11px;">
                                <span style="display:inline-flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:50%;background:#f59e0b;display:inline-block;opacity:0.8;"></span><?php esc_html_e( 'Failed Logins', 'cloudscale-devtools' ); ?></span>
                                <span style="display:inline-flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:50%;background:#dc2626;display:inline-block;opacity:0.8;"></span><?php esc_html_e( 'Blocked Probes', 'cloudscale-devtools' ); ?></span>
                            </span>
                        </div>
                        <div id="cs-bf-geo-map" style="height:280px;border-radius:6px;overflow:hidden;border:1px solid #e2e8f0;background:#e8f4f4;margin-top:8px;"></div>
                        <div style="margin-top:6px;font-size:11px;color:#94a3b8;"><?php esc_html_e( 'Each ring scales independently — circle size reflects volume within that category only. Country via Cloudflare CF-IPCountry or DB-IP Lite fallback.', 'cloudscale-devtools' ); ?></div>
                        <?php CSDT_Geo::render_dbip_status(); ?>
                    </div>

                    <div id="cs-ip-blocklist-wrap" class="cs-bf-log-wrap" style="margin-top:22px;<?php echo empty( $ip_blocklist ) ? 'display:none;' : ''; ?>">
                        <div class="cs-bf-log-header">
                            <span class="cs-bf-log-title">🚫 <?php esc_html_e( 'Blocked IPs', 'cloudscale-devtools' ); ?></span>
                            <span class="cs-bf-log-total" id="cs-blocklist-count"><?php echo count( $ip_blocklist ); ?> blocked</span>
                        </div>
                        <div class="cs-bf-table-wrap">
                            <table class="cs-bf-table">
                                <thead>
                                    <tr>
                                        <th class="cs-bf-th"><?php esc_html_e( 'IP address', 'cloudscale-devtools' ); ?></th>
                                        <th class="cs-bf-th"><?php esc_html_e( 'Reason', 'cloudscale-devtools' ); ?></th>
                                        <th class="cs-bf-th"><?php esc_html_e( 'Blocked', 'cloudscale-devtools' ); ?></th>
                                        <th class="cs-bf-th"></th>
                                    </tr>
                                </thead>
                                <tbody id="cs-blocklist-tbody">
                                <?php foreach ( $ip_blocklist as $bl_ip => $bl_data ) : ?>
                                    <tr id="cs-bl-row-<?php echo esc_attr( str_replace( '.', '-', $bl_ip ) ); ?>">
                                        <td class="cs-bf-td cs-bf-td-ip"><?php echo esc_html( $bl_ip ); ?></td>
                                        <td class="cs-bf-td cs-bf-td-time"><?php echo esc_html( $bl_data['reason'] ?? 'Manual block' ); ?></td>
                                        <td class="cs-bf-td cs-bf-td-time"><?php echo esc_html( isset( $bl_data['blocked_at'] ) ? human_time_diff( $bl_data['blocked_at'] ) . ' ago' : '—' ); ?></td>
                                        <td class="cs-bf-td" style="text-align:right;white-space:nowrap;">
                                            <a href="https://ipinfo.io/<?php echo esc_attr( $bl_ip ); ?>" target="_blank" rel="noopener"
                                               style="font-size:10px;padding:2px 6px;border:1px solid #cbd5e1;border-radius:4px;background:#f8fafc;color:#475569;text-decoration:none;margin-right:4px;">Whois</a>
                                            <button type="button" class="cs-ip-unblock-btn" data-ip="<?php echo esc_attr( $bl_ip ); ?>"
                                                    style="font-size:10px;padding:2px 6px;border:1px solid #cbd5e1;border-radius:4px;background:#f8fafc;color:#475569;cursor:pointer;">Unblock</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- inline nonce config; wp_add_inline_script not available at this render point ?>
                    <script>
                    (function(){
                        var nonce = <?php echo wp_json_encode( wp_create_nonce( CloudScale_DevTools::SECURITY_NONCE ) ); ?>;

                        function doAjax(action, ip, reason, cb) {
                            var fd = new FormData();
                            fd.append('action', action);
                            fd.append('nonce', nonce);
                            fd.append('ip', ip);
                            if (reason) fd.append('reason', reason);
                            fetch(ajaxurl, {method:'POST', body:fd})
                                .then(function(r){ return r.json(); })
                                .then(cb)
                                .catch(function(){ alert('Request failed.'); });
                        }

                        function addBlocklistRow(ip) {
                            var wrap  = document.getElementById('cs-ip-blocklist-wrap');
                            var tbody = document.getElementById('cs-blocklist-tbody');
                            var count = document.getElementById('cs-blocklist-count');
                            if (!wrap || !tbody) return;
                            wrap.style.display = '';
                            var rowId = 'cs-bl-row-' + ip.replace(/\./g,'-');
                            if (document.getElementById(rowId)) return;
                            var n = tbody.rows.length + 1;
                            if (count) count.textContent = n + ' blocked';
                            var tr = document.createElement('tr');
                            tr.id = rowId;
                            tr.innerHTML =
                                '<td class="cs-bf-td cs-bf-td-ip">' + ip + '</td>' +
                                '<td class="cs-bf-td cs-bf-td-time">wp-login probe</td>' +
                                '<td class="cs-bf-td cs-bf-td-time">just now</td>' +
                                '<td class="cs-bf-td" style="text-align:right;white-space:nowrap;">' +
                                '<a href="https://ipinfo.io/' + encodeURIComponent(ip) + '" target="_blank" rel="noopener" style="font-size:10px;padding:2px 6px;border:1px solid #cbd5e1;border-radius:4px;background:#f8fafc;color:#475569;text-decoration:none;margin-right:4px;">Whois</a>' +
                                '<button type="button" class="cs-ip-unblock-btn" data-ip="' + ip + '" style="font-size:10px;padding:2px 6px;border:1px solid #cbd5e1;border-radius:4px;background:#f8fafc;color:#475569;cursor:pointer;">Unblock</button>' +
                                '</td>';
                            tbody.prepend(tr);
                            wireUnblock(tr.querySelector('.cs-ip-unblock-btn'));
                        }

                        function wireUnblock(btn) {
                            if (!btn) return;
                            btn.addEventListener('click', function(){
                                var ip = btn.getAttribute('data-ip');
                                btn.disabled = true; btn.textContent = '⏳';
                                doAjax('csdt_ip_unblock', ip, null, function(resp){
                                    if (resp.success) {
                                        var row = document.getElementById('cs-bl-row-' + ip.replace(/\./g,'-'));
                                        if (row) row.remove();
                                        // Restore Block button on probe table row
                                        var probeRow = document.querySelector('#cs-probe-tbody tr[data-ip="' + ip + '"]');
                                        if (probeRow) {
                                            var cell = probeRow.querySelector('td:last-child');
                                            if (cell) {
                                                var blocked = cell.querySelector('span');
                                                if (blocked) {
                                                    var nb = document.createElement('button');
                                                    nb.type = 'button'; nb.className = 'cs-ip-block-btn';
                                                    nb.setAttribute('data-ip', ip);
                                                    nb.style.cssText = 'font-size:10px;padding:2px 6px;border:1px solid #fca5a5;border-radius:4px;background:#fef2f2;color:#dc2626;cursor:pointer;font-weight:600;';
                                                    nb.textContent = 'Block';
                                                    blocked.replaceWith(nb);
                                                    wireBlock(nb);
                                                }
                                            }
                                        }
                                        var tbody = document.getElementById('cs-blocklist-tbody');
                                        var wrap  = document.getElementById('cs-ip-blocklist-wrap');
                                        var count = document.getElementById('cs-blocklist-count');
                                        if (tbody && tbody.rows.length === 0 && wrap) { wrap.style.display = 'none'; }
                                        if (count) count.textContent = (tbody ? tbody.rows.length : 0) + ' blocked';
                                    } else {
                                        btn.disabled = false; btn.textContent = 'Unblock';
                                        alert(resp.data || 'Unblock failed.');
                                    }
                                });
                            });
                        }

                        function wireBlock(btn) {
                            if (!btn) return;
                            btn.addEventListener('click', function(){
                                var ip = btn.getAttribute('data-ip');
                                btn.disabled = true; btn.textContent = '⏳';
                                doAjax('csdt_ip_block', ip, 'wp-login probe', function(resp){
                                    if (resp.success) {
                                        btn.replaceWith((function(){
                                            var s = document.createElement('span');
                                            s.style.cssText = 'font-size:10px;padding:2px 6px;border:1px solid #86efac;border-radius:4px;background:#dcfce7;color:#15803d;font-weight:600;';
                                            s.textContent = '🚫 Blocked';
                                            return s;
                                        })());
                                        addBlocklistRow(ip);
                                    } else {
                                        btn.disabled = false; btn.textContent = 'Block';
                                        alert(resp.data || 'Block failed.');
                                    }
                                });
                            });
                        }

                        document.querySelectorAll('.cs-ip-block-btn').forEach(wireBlock);
                        document.querySelectorAll('.cs-ip-unblock-btn').forEach(wireUnblock);

                        // DB-IP download + auto-update toggle
                        (function(){
                            var dlBtn   = document.getElementById('csdt-dbip-download-btn');
                            var autoChk = document.getElementById('csdt-dbip-auto-update');
                            var msg     = document.getElementById('csdt-dbip-msg');
                            function showMsg(text, ok) {
                                if (!msg) return;
                                msg.textContent = text;
                                msg.style.color = ok ? '#15803d' : '#b91c1c';
                                msg.style.display = 'inline';
                            }
                            if (autoChk) {
                                autoChk.addEventListener('change', function() {
                                    var fd = new FormData();
                                    fd.append('action', 'csdt_save_dbip_settings');
                                    fd.append('nonce', nonce);
                                    fd.append('auto_update', autoChk.checked ? 'yes' : 'no');
                                    fetch(ajaxurl, {method:'POST', body:fd})
                                        .then(function(r){ return r.json(); })
                                        .then(function(res){ showMsg(res.success ? '✓ Saved' : '⚠ Save failed', res.success); setTimeout(function(){ if(msg) msg.style.display='none'; }, 2000); })
                                        .catch(function(){ showMsg('⚠ Error', false); });
                                });
                            }
                            if (dlBtn) {
                                dlBtn.addEventListener('click', function() {
                                    dlBtn.disabled = true;
                                    dlBtn.textContent = '⏳ Downloading…';
                                    var fd = new FormData();
                                    fd.append('action', 'csdt_download_dbip');
                                    fd.append('nonce', nonce);
                                    fetch(ajaxurl, {method:'POST', body:fd})
                                        .then(function(r){ return r.json(); })
                                        .then(function(res){
                                            dlBtn.disabled = false;
                                            if (res.success) {
                                                dlBtn.textContent = '🔄 Update DB-IP Lite';
                                                showMsg('✓ Installed — ' + (res.data.size || ''), true);
                                                var row = document.getElementById('csdt-dbip-status-row');
                                                if (row) {
                                                    row.style.background = '#f0fdf4';
                                                    row.style.borderColor = '#86efac';
                                                    var s = row.querySelector('span');
                                                    if (s) s.innerHTML = '<span style="color:#15803d;font-weight:600;">✓ DB-IP Lite installed</span>&nbsp;&mdash;&nbsp;' + (res.data.size || '') + '&nbsp;<span style="color:#64748b;">(country shown for all sites, not just Cloudflare)</span>';
                                                }
                                            } else {
                                                dlBtn.textContent = '⬇️ Download DB-IP Lite';
                                                showMsg('⚠ ' + (res.data || 'Download failed'), false);
                                            }
                                        })
                                        .catch(function(){
                                            dlBtn.disabled = false;
                                            dlBtn.textContent = '⬇️ Download DB-IP Lite';
                                            showMsg('⚠ Network error', false);
                                        });
                                });
                            }
                        })();
                    })();
                    </script>
                </div>
            </div>
        </div>

        <!-- ── SSH Brute-Force Monitor ─────────────────── -->
        <?php
        $ssh_mon_enabled   = get_option( 'csdt_ssh_monitor_enabled', '1' ) === '1';
        $ssh_mon_threshold = get_option( 'csdt_ssh_monitor_threshold', '10' );
        $ssh_last_check    = get_option( 'csdt_ssh_monitor_last_check', null );
        $ssh_last_alert    = (int) get_option( 'csdt_ssh_monitor_last_alert', 0 );
        $ssh_alert_log     = get_option( 'csdt_ssh_monitor_alert_log', [] );
        $auth_log_readable = false;
        foreach ( [ '/var/log/auth.log', '/var/log/secure', '/var/log/messages' ] as $_p ) {
            if ( is_readable( $_p ) ) { $auth_log_readable = true; break; }
        }
        ?>
        <div class="cs-panel" id="cs-panel-ssh-monitor">
            <div class="cs-section-header cs-section-header-red">
                <span>🖥️ SSH BRUTE-FORCE MONITOR</span>
                <span class="cs-header-hint"><?php esc_html_e( 'Real-time SSH attack detection via auth.log — alerts via email and ntfy.sh', 'cloudscale-devtools' ); ?></span>
                <?php self::render_explain_btn( 'ssh-monitor', 'SSH Brute-Force Monitor', [
                    [ 'name' => 'How it works',      'rec' => 'Overview',     'html' => 'A WP-Cron job runs every minute and reads new lines appended to <code>/var/log/auth.log</code> since the last check (byte-offset tracking — only new content is read, never the whole file). It counts <code>Failed password</code> lines in that window and fires an alert when the count exceeds your threshold.' ],
                    [ 'name' => 'Threshold',         'rec' => 'Recommended',  'html' => 'Default is <strong>10 failed attempts per minute</strong>. Lower (3–5) is appropriate if your server has only known, trusted users. Higher (20–50) reduces noise on servers exposed to constant internet scanning.' ],
                    [ 'name' => 'Alert channels',    'rec' => 'Recommended',  'html' => '<strong>Email</strong> — sent to the WordPress admin email address.<br><strong>ntfy.sh</strong> — instant push notification to any device with the ntfy app. Configure your ntfy topic URL under Security Scan → Scheduled Scans.' ],
                    [ 'name' => 'fail2ban',          'rec' => 'Critical',     'html' => 'This monitor <em>detects</em> and alerts — it does not block IPs. <strong>fail2ban</strong> must be installed and configured to automatically ban offending IPs. Without it, SSH attacks continue unimpeded regardless of how many alerts you receive. Install: <code>sudo apt install fail2ban</code> — the default SSH jail is enabled automatically.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <?php if ( ! $auth_log_readable ) : ?>
                <div class="cs-notice cs-notice-warn" style="margin-bottom:16px;" id="cs-ssh-perm-notice">
                    <div style="display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap;">
                        <div style="flex:1;min-width:0;">
                            ⚠️ <strong><?php esc_html_e( 'Auth log not readable.', 'cloudscale-devtools' ); ?></strong>
                            <?php esc_html_e( 'The web server user needs read access to the auth log.', 'cloudscale-devtools' ); ?>
                        </div>
                        <button type="button" id="cs-ssh-fix-btn"
                                style="flex-shrink:0;background:#2563eb;color:#fff;border:none;border-radius:5px;padding:6px 14px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;">
                            🔧 <?php esc_html_e( 'Fix it automatically', 'cloudscale-devtools' ); ?>
                        </button>
                    </div>
                    <div id="cs-ssh-fix-result" style="margin-top:8px;display:none;font-size:12px;padding:6px 10px;border-radius:4px;"></div>
                    <details style="margin-top:8px;">
                        <summary style="font-size:11px;color:rgba(255,255,255,.7);cursor:pointer;">Manual command</summary>
                        <div style="position:relative;margin-top:6px;">
                            <code id="cs-ssh-adm-cmd" style="display:block;padding:8px 44px 8px 10px;background:#f6f7f7;border-radius:4px;color:#1a1a1a;font-size:12px;line-height:1.5;word-break:break-all;">sudo usermod -a -G adm www-data &amp;&amp; sudo systemctl restart php-fpm</code>
                            <button type="button" onclick="(function(b){var t=document.getElementById('cs-ssh-adm-cmd');navigator.clipboard.writeText(t.textContent).then(function(){var o=b.textContent;b.textContent='✓';setTimeout(function(){b.textContent=o;},1500);});})(this)"
                                    style="position:absolute;top:50%;right:6px;transform:translateY(-50%);background:#e2e8f0;border:none;border-radius:4px;padding:3px 7px;font-size:11px;cursor:pointer;color:#334155;white-space:nowrap;">Copy</button>
                        </div>
                    </details>
                </div>
                <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- inline SSH fix button handler; wp_add_inline_script not available at this render point ?>
                <script>
                (function(){
                    var btn = document.getElementById('cs-ssh-fix-btn');
                    if (!btn) return;
                    btn.addEventListener('click', function(){
                        btn.disabled = true;
                        btn.textContent = '⏳ Running…';
                        var fd = new FormData();
                        fd.append('action', 'csdt_ssh_fix_permissions');
                        fd.append('nonce', csdtVulnScan.nonce);
                        fetch(ajaxurl, {method:'POST', body:fd})
                            .then(function(r){ return r.json(); })
                            .then(function(resp){
                                var el = document.getElementById('cs-ssh-fix-result');
                                el.style.display = 'block';
                                if (resp.success) {
                                    el.style.background = '#dcfce7';
                                    el.style.color = '#15803d';
                                    el.textContent = '✅ ' + (resp.data.note || 'Done.');
                                    btn.textContent = '✅ Fixed';
                                    if (resp.data.readable) {
                                        setTimeout(function(){ location.reload(); }, 2000);
                                    }
                                } else {
                                    el.style.background = '#fff7ed';
                                    el.style.color = '#c2410c';
                                    el.textContent = '❌ ' + (resp.data || 'Failed — use the manual command below.');
                                    btn.disabled = false;
                                    btn.textContent = '🔧 Retry';
                                }
                            })
                            .catch(function(){
                                var el = document.getElementById('cs-ssh-fix-result');
                                el.style.display = 'block';
                                el.style.background = '#fff7ed';
                                el.style.color = '#c2410c';
                                el.textContent = '❌ Request failed — use the manual command below.';
                                btn.disabled = false;
                                btn.textContent = '🔧 Retry';
                            });
                    });
                })();
                </script>
                <?php endif; ?>

                <div class="cs-field-row">
                    <div class="cs-field">
                        <label class="cs-label">
                            <input type="checkbox" id="cs-ssh-mon-enabled" <?php checked( $ssh_mon_enabled ); ?>>
                            <?php esc_html_e( 'Enable SSH brute-force monitor (checks every 60 seconds)', 'cloudscale-devtools' ); ?>
                        </label>
                        <span class="cs-hint"><?php esc_html_e( 'Reads /var/log/auth.log every minute and alerts if the failure threshold is crossed. On by default.', 'cloudscale-devtools' ); ?></span>
                    </div>
                </div>
                <div class="cs-field-row">
                    <div class="cs-field">
                        <label class="cs-label" for="cs-ssh-mon-threshold"><?php esc_html_e( 'Alert threshold (failures per 60 s):', 'cloudscale-devtools' ); ?></label>
                        <input type="number" id="cs-ssh-mon-threshold" class="cs-input" min="1" max="1000"
                               value="<?php echo esc_attr( $ssh_mon_threshold ); ?>" style="max-width:100px">
                        <span class="cs-hint"><?php esc_html_e( 'Default: 10. Sends email + ntfy.sh alert when this many failures occur in the last 60 seconds. Alerts are throttled to once per 5 minutes.', 'cloudscale-devtools' ); ?></span>
                    </div>
                </div>
                <?php if ( $ssh_last_check ) : ?>
                <div class="cs-field-row" style="padding:10px 0 0;">
                    <div style="font-size:12px;color:#64748b;">
                        <strong><?php esc_html_e( 'Last check:', 'cloudscale-devtools' ); ?></strong>
                        <?php echo esc_html( human_time_diff( $ssh_last_check['ts'] ) . ' ago' ); ?> —
                        <strong><?php echo (int) $ssh_last_check['count']; ?></strong> <?php esc_html_e( 'failure(s) in last 60 s', 'cloudscale-devtools' ); ?>
                        <?php if ( $ssh_last_alert > 0 ) : ?>
                        &nbsp;|&nbsp; <strong><?php esc_html_e( 'Last alert:', 'cloudscale-devtools' ); ?></strong>
                        <?php echo esc_html( human_time_diff( $ssh_last_alert ) . ' ago' ); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ( ! empty( $ssh_last_check['lines'] ) ) : ?>
                <div style="margin-top:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 14px;font-size:11px;color:#475569;font-family:monospace;max-height:140px;overflow-y:auto;">
                    <?php foreach ( array_reverse( $ssh_last_check['lines'] ) as $line ) : ?>
                    <div><?php echo esc_html( $line ); ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                <div style="margin-top:18px;display:flex;align-items:center;gap:10px">
                    <button type="button" class="cs-btn-primary" id="cs-ssh-mon-save">💾 <?php esc_html_e( 'Save Settings', 'cloudscale-devtools' ); ?></button>
                    <span class="cs-settings-saved" id="cs-ssh-mon-saved">✓ <?php esc_html_e( 'Saved', 'cloudscale-devtools' ); ?></span>
                </div>

                <?php if ( ! empty( $ssh_alert_log ) ) : ?>
                <div style="margin-top:22px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                        <span style="font-weight:600;font-size:13px;">🚨 <?php esc_html_e( 'Alert History', 'cloudscale-devtools' ); ?></span>
                        <button type="button" id="cs-ssh-log-clear" style="background:none;border:none;color:#94a3b8;font-size:11px;cursor:pointer;padding:0;"><?php esc_html_e( 'Clear log', 'cloudscale-devtools' ); ?></button>
                    </div>
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead>
                            <tr style="background:#f8fafc;">
                                <th style="text-align:left;padding:6px 10px;border-bottom:1px solid #e2e8f0;color:#64748b;font-weight:600;"><?php esc_html_e( 'Time', 'cloudscale-devtools' ); ?></th>
                                <th style="text-align:center;padding:6px 10px;border-bottom:1px solid #e2e8f0;color:#64748b;font-weight:600;"><?php esc_html_e( 'Attempts', 'cloudscale-devtools' ); ?></th>
                                <th style="text-align:left;padding:6px 10px;border-bottom:1px solid #e2e8f0;color:#64748b;font-weight:600;"><?php esc_html_e( 'Targeted accounts', 'cloudscale-devtools' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( array_reverse( $ssh_alert_log ) as $entry ) :
                                $users = $entry['users'] ?? [];
                                arsort( $users );
                                $user_parts = [];
                                foreach ( array_slice( $users, 0, 5, true ) as $u => $c ) {
                                    $user_parts[] = esc_html( $u ) . ( $c > 1 ? ' &times;' . $c : '' );
                                }
                            ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:6px 10px;white-space:nowrap;color:#475569;"><?php echo esc_html( human_time_diff( $entry['ts'] ) . ' ago' ); ?></td>
                                <td style="padding:6px 10px;text-align:center;font-weight:700;color:#dc2626;"><?php echo (int) $entry['count']; ?></td>
                                <td style="padding:6px 10px;color:#334155;"><?php echo $user_parts ? implode( ', ', $user_parts ) : '&mdash;'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Your 2FA Setup (current user) ─────────── -->
        <div class="cs-panel">
            <div class="cs-section-header cs-section-header-green">
                <span>👤 YOUR 2FA SETUP</span>
                <span class="cs-header-hint"><?php echo esc_html( wp_get_current_user()->user_login ); ?></span>
                <?php self::render_explain_btn( '2fa-setup', 'Your 2FA Setup', [
                    [ 'name' => 'Authenticator App (TOTP)', 'rec' => 'Recommended', 'html' => 'Generates a <strong>6-digit code every 30 seconds</strong> using a TOTP app. Works offline and is the most secure 2FA method.<br><br><ul><li><strong>Google Authenticator</strong> — iOS / Android</li><li><strong>Authy</strong> — iOS / Android / Desktop</li><li><strong>1Password</strong> — built-in TOTP support</li><li><strong>Apple Passwords</strong> — iOS 18+ / macOS 15+</li></ul>' ],
                    [ 'name' => 'Email Code',               'rec' => 'Optional',    'html' => 'Sends a one-time code to your account email on each login. Simpler to set up but depends on email deliverability — if your site\'s outgoing email is unreliable, use an authenticator app instead.' ],
                    [ 'name' => 'Passkey',                  'rec' => 'Recommended', 'html' => 'Uses <strong>Face ID</strong>, <strong>Touch ID</strong>, <strong>Windows Hello</strong>, or a hardware security key (YubiKey, etc.) as your second factor. Register a passkey in the <strong>Passkeys</strong> panel, then select this method here.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">

                <!-- Email 2FA status -->
                <?php
                // Check if a verification email is already pending for this user.
                $email_pending = (bool) get_user_meta( $user_id, 'csdt_devtools_email_verify_pending', true );
                ?>
                <div class="cs-2fa-row" id="cs-email-row">
                    <div class="cs-2fa-row-icon">📧</div>
                    <div class="cs-2fa-row-body">
                        <div class="cs-2fa-row-title"><?php esc_html_e( 'Email Code', 'cloudscale-devtools' ); ?></div>
                        <div class="cs-2fa-row-desc"><?php esc_html_e( 'A 6-digit code is emailed to you after your password is accepted.', 'cloudscale-devtools' ); ?></div>
                        <div class="cs-email-pending-msg" id="cs-email-pending-msg" style="<?php echo $email_pending ? '' : 'display:none'; ?>">
                            <span class="cs-pending-notice">📬 <?php esc_html_e( 'Verification email sent — click the link in the email to activate.', 'cloudscale-devtools' ); ?></span>
                        </div>
                    </div>
                    <div class="cs-2fa-row-action">
                        <?php if ( $email_active ) : ?>
                            <span class="cs-2fa-badge cs-2fa-badge-on"><?php esc_html_e( 'Active', 'cloudscale-devtools' ); ?></span>
                            <button type="button" class="cs-btn-pink cs-2fa-disable" data-method="email" style="margin-left:10px">
                                <?php esc_html_e( 'Disable', 'cloudscale-devtools' ); ?>
                            </button>
                        <?php elseif ( $email_pending ) : ?>
                            <span class="cs-2fa-badge cs-2fa-badge-pending" id="cs-email-badge"><?php esc_html_e( 'Awaiting verification', 'cloudscale-devtools' ); ?></span>
                            <button type="button" class="cs-btn-orange cs-email-enable" id="cs-email-enable-btn" style="margin-left:10px">
                                <?php esc_html_e( 'Resend', 'cloudscale-devtools' ); ?>
                            </button>
                        <?php else : ?>
                            <span class="cs-2fa-badge cs-2fa-badge-off" id="cs-email-badge"><?php esc_html_e( 'Off', 'cloudscale-devtools' ); ?></span>
                            <button type="button" class="cs-btn-orange cs-email-enable" id="cs-email-enable-btn" style="margin-left:10px">
                                <?php esc_html_e( 'Enable', 'cloudscale-devtools' ); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="cs-2fa-divider"></div>

                <!-- TOTP status + setup wizard -->
                <div class="cs-2fa-row" id="cs-totp-row">
                    <div class="cs-2fa-row-icon">📱</div>
                    <div class="cs-2fa-row-body">
                        <div class="cs-2fa-row-title"><?php esc_html_e( 'Authenticator App (TOTP)', 'cloudscale-devtools' ); ?></div>
                        <div class="cs-2fa-row-desc"><?php esc_html_e( 'Google Authenticator, Authy, 1Password, or any TOTP app. Generates a fresh 6-digit code every 30 seconds.', 'cloudscale-devtools' ); ?></div>
                    </div>
                    <div class="cs-2fa-row-action">
                        <?php if ( $totp_active ) : ?>
                            <span class="cs-2fa-badge cs-2fa-badge-on" id="cs-totp-badge"><?php esc_html_e( 'Active', 'cloudscale-devtools' ); ?></span>
                            <button type="button" class="cs-btn-pink cs-2fa-disable" data-method="totp" style="margin-left:10px" id="cs-totp-disable-btn">
                                <?php esc_html_e( 'Disable', 'cloudscale-devtools' ); ?>
                            </button>
                        <?php else : ?>
                            <span class="cs-2fa-badge cs-2fa-badge-off" id="cs-totp-badge"><?php esc_html_e( 'Not set up', 'cloudscale-devtools' ); ?></span>
                            <button type="button" class="cs-btn-primary" id="cs-totp-setup-btn" style="margin-left:10px">
                                <?php esc_html_e( 'Set Up', 'cloudscale-devtools' ); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- TOTP Setup Wizard (hidden until triggered) -->
                <div id="cs-totp-wizard" class="cs-totp-wizard" style="display:none">
                    <div class="cs-totp-wizard-inner">
                        <h3 class="cs-totp-wizard-title">📱 <?php esc_html_e( 'Set Up Authenticator App', 'cloudscale-devtools' ); ?></h3>

                        <div class="cs-totp-steps">
                            <div class="cs-totp-step">
                                <span class="cs-totp-step-num">1</span>
                                <?php esc_html_e( 'Open your authenticator app (Google Authenticator, Authy, 1Password, etc.) and scan this QR code:', 'cloudscale-devtools' ); ?>
                            </div>
                            <div class="cs-totp-qr-wrap">
                                <div id="cs-totp-qr-loading" class="cs-totp-qr-loading">
                                    <span class="spinner is-active" style="float:none;margin:0"></span>
                                    <?php esc_html_e( 'Generating…', 'cloudscale-devtools' ); ?>
                                </div>
                                <div id="cs-totp-qr-canvas" class="cs-totp-qr-img" style="display:none"></div>
                            </div>
                            <div class="cs-totp-manual-wrap" style="display:none" id="cs-totp-manual">
                                <span class="cs-label" style="font-size:12px"><?php esc_html_e( "Can't scan? Enter this key manually:", 'cloudscale-devtools' ); ?></span>
                                <div class="cs-totp-secret-row">
                                    <code id="cs-totp-secret-display" class="cs-totp-secret"></code>
                                    <button type="button" id="cs-totp-copy-btn" class="cs-totp-copy-btn" title="<?php esc_attr_e( 'Copy key', 'cloudscale-devtools' ); ?>">
                                        <?php esc_html_e( 'Copy', 'cloudscale-devtools' ); ?>
                                    </button>
                                </div>
                            </div>

                            <div class="cs-totp-step" style="margin-top:16px">
                                <span class="cs-totp-step-num">2</span>
                                <?php esc_html_e( 'Enter the 6-digit code from your app to confirm setup:', 'cloudscale-devtools' ); ?>
                            </div>
                            <div class="cs-totp-verify-row">
                                <input type="text" id="cs-totp-verify-code" class="cs-input cs-totp-code-input"
                                       placeholder="000000" maxlength="6" inputmode="numeric" autocomplete="one-time-code">
                                <button type="button" class="cs-btn-primary" id="cs-totp-verify-btn">
                                    ✓ <?php esc_html_e( 'Verify & Activate', 'cloudscale-devtools' ); ?>
                                </button>
                            </div>
                            <div id="cs-totp-verify-msg" class="cs-totp-verify-msg" style="display:none"></div>
                        </div>

                        <div style="margin-top:12px">
                            <button type="button" class="cs-btn-pink" id="cs-totp-cancel-btn" style="font-size:11px;padding:5px 12px">
                                <?php esc_html_e( 'Cancel', 'cloudscale-devtools' ); ?>
                            </button>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Two-Factor Authentication ─────────────── -->
        <div class="cs-panel" id="cs-panel-2fa">
            <div class="cs-section-header cs-section-header-orange">
                <span>🔑 TWO-FACTOR AUTHENTICATION</span>
                <span class="cs-header-hint"><?php esc_html_e( 'Email code or Authenticator app', 'cloudscale-devtools' ); ?></span>
                <?php self::render_explain_btn( '2fa', 'Two-Factor Authentication', [
                    [ 'name' => 'Off',                      'rec' => 'Not Recommended', 'html' => 'Disables 2FA site-wide. Passwords alone are vulnerable to phishing and brute-force attacks — not recommended for any public site.' ],
                    [ 'name' => 'Email Code',               'rec' => 'Optional',        'html' => 'Requires users to enter a code sent to their email after each password login. Works out of the box with no app required — but depends on your site\'s outgoing email working reliably.' ],
                    [ 'name' => 'Authenticator App (TOTP)', 'rec' => 'Recommended',     'html' => 'Each user configures their own TOTP app (<strong>Google Authenticator</strong>, <strong>Authy</strong>, <strong>1Password</strong>). Most secure option — works offline, no email dependency.' ],
                    [ 'name' => 'Force 2FA for Admins',     'rec' => 'Recommended',     'html' => 'Blocks <code>administrator</code>-role users from accessing the dashboard until they have set up 2FA. Strongly recommended on any multi-user site.' ],
                    [ 'name' => 'Grace Logins',             'rec' => 'Advanced',        'html' => 'Allows a user to log in up to <em>N</em> times before 2FA is enforced. The counter is per-user and never resets automatically. Default is <code>0</code> (2FA required from the first login).<br><br><strong>Tip for automated test accounts:</strong> set to <code>1</code>. Tools like Playwright cannot complete a real 2FA challenge — one grace login lets a test account authenticate for setup steps without disabling 2FA site-wide.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <p class="cs-login-desc"><?php esc_html_e( 'Require a second verification step after password login. Email sends a one-time code; Authenticator uses Google Authenticator, Authy, or any TOTP app.', 'cloudscale-devtools' ); ?></p>

                <!-- Site-wide default -->
                <?php
                $has_passkeys = ! empty( CSDT_DevTools_Passkey::get_passkeys( $user_id ) );
                ?>
                <div class="cs-field-row">
                    <div class="cs-field">
                        <label class="cs-label"><?php esc_html_e( 'Site-wide Default Method:', 'cloudscale-devtools' ); ?></label>
                        <div class="cs-2fa-method-group">
                            <label class="cs-radio-label <?php echo $method === 'off' ? 'active' : ''; ?>">
                                <input type="radio" name="csdt_devtools_2fa_method" value="off" <?php checked( $method, 'off' ); ?>>
                                <span class="cs-radio-icon">🚫</span> <?php esc_html_e( 'Off', 'cloudscale-devtools' ); ?>
                            </label>
                            <label class="cs-radio-label <?php echo $method === 'email' ? 'active' : ''; ?> <?php echo ! $email_active ? 'cs-radio-disabled' : ''; ?>"
                                   <?php echo ! $email_active ? 'title="' . esc_attr__( 'Enable Email Code for your account first', 'cloudscale-devtools' ) . '"' : ''; ?>>
                                <input type="radio" name="csdt_devtools_2fa_method" value="email" <?php checked( $method, 'email' ); ?> <?php disabled( ! $email_active ); ?>>
                                <span class="cs-radio-icon">📧</span> <?php esc_html_e( 'Email Code', 'cloudscale-devtools' ); ?>
                            </label>
                            <label class="cs-radio-label <?php echo $method === 'totp' ? 'active' : ''; ?> <?php echo ! $totp_active ? 'cs-radio-disabled' : ''; ?>"
                                   <?php echo ! $totp_active ? 'title="' . esc_attr__( 'Set up Authenticator App for your account first', 'cloudscale-devtools' ) . '"' : ''; ?>>
                                <input type="radio" name="csdt_devtools_2fa_method" value="totp" <?php checked( $method, 'totp' ); ?> <?php disabled( ! $totp_active ); ?>>
                                <span class="cs-radio-icon">📱</span> <?php esc_html_e( 'Authenticator App', 'cloudscale-devtools' ); ?>
                            </label>
                            <label class="cs-radio-label <?php echo $method === 'passkey' ? 'active' : ''; ?> <?php echo ! $has_passkeys ? 'cs-radio-disabled' : ''; ?>"
                                   <?php echo ! $has_passkeys ? 'title="' . esc_attr__( 'Register a passkey for your account first', 'cloudscale-devtools' ) . '"' : ''; ?>>
                                <input type="radio" name="csdt_devtools_2fa_method" value="passkey" <?php checked( $method, 'passkey' ); ?> <?php disabled( ! $has_passkeys ); ?>>
                                <span class="cs-radio-icon">🔑</span> <?php esc_html_e( 'Passkey', 'cloudscale-devtools' ); ?>
                            </label>
                        </div>
                        <span class="cs-hint"><?php esc_html_e( 'Sets the default method. Individual users can override if force is not enabled.', 'cloudscale-devtools' ); ?></span>
                    </div>
                    <div class="cs-field">
                        <label class="cs-label"><?php esc_html_e( 'Enforcement:', 'cloudscale-devtools' ); ?></label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:2px">
                            <input type="checkbox" id="cs-2fa-force" <?php checked( $force ); ?>>
                            <span style="font-size:13px;color:#555"><?php esc_html_e( 'Force 2FA for all administrators', 'cloudscale-devtools' ); ?></span>
                        </label>
                        <span class="cs-hint"><?php esc_html_e( 'Admins without 2FA set up will be blocked from the dashboard until they configure it.', 'cloudscale-devtools' ); ?></span>
                    </div>
                </div>

                <?php $grace_logins = (int) get_option( 'csdt_devtools_2fa_grace_logins', '0' ); ?>
                <div class="cs-field-row" style="margin-top:16px">
                    <div class="cs-field">
                        <label class="cs-label" for="cs-2fa-grace-logins"><?php esc_html_e( 'Grace logins before 2FA is required:', 'cloudscale-devtools' ); ?></label>
                        <input type="number" id="cs-2fa-grace-logins" class="cs-input" min="0" max="10"
                               value="<?php echo esc_attr( $grace_logins ); ?>" style="max-width:100px">
                        <span class="cs-hint"><?php esc_html_e( 'Allow N logins without 2FA per user. 0 = 2FA required from first login. For automated test accounts use 1.', 'cloudscale-devtools' ); ?></span>
                    </div>
                </div>

                <div style="margin-top:16px;display:flex;align-items:center;gap:10px">
                    <button type="button" class="cs-btn-primary" id="cs-2fa-save">💾 <?php esc_html_e( 'Save Settings', 'cloudscale-devtools' ); ?></button>
                    <span class="cs-settings-saved" id="cs-2fa-saved">✓ <?php esc_html_e( 'Saved', 'cloudscale-devtools' ); ?></span>
                </div>
            </div>
        </div>

        <!-- ── Passkeys (WebAuthn) ────────────────────── -->
        <div class="cs-panel" id="cs-panel-passkeys">
            <div class="cs-section-header" style="background:linear-gradient(135deg,#1e1b4b,#3730a3)">
                <span>🔑 PASSKEYS (WEBAUTHN)</span>
                <span class="cs-header-hint"><?php esc_html_e( 'Face ID · Touch ID · Windows Hello · Security Keys', 'cloudscale-devtools' ); ?></span>
                <?php self::render_explain_btn( 'passkeys', 'Passkeys (WebAuthn)', [
                    [ 'name' => 'What is a passkey?',    'rec' => 'Informational', 'html' => 'A passkey is a cryptographic credential stored on your device. It replaces passwords with biometrics (<strong>Face ID</strong>, <strong>Touch ID</strong>, <strong>Windows Hello</strong>) or hardware keys (YubiKey, etc.). No secret is ever sent over the network — the private key never leaves your device.' ],
                    [ 'name' => 'Registering a passkey', 'rec' => 'Recommended',  'html' => 'Click <strong>+ Add Passkey</strong>, give it a name (e.g. <code>iPhone 16</code> or <code>MacBook Touch ID</code>), then follow your device\'s biometric prompt.<br><br>Register multiple passkeys for different devices so you always have a backup.' ],
                    [ 'name' => 'Test',                  'rec' => 'Optional',     'html' => 'Verifies a passkey is working correctly <em>without</em> logging out. Use this after registering a new passkey to confirm the credential round-trips successfully.' ],
                    [ 'name' => 'Remove',                'rec' => 'Optional',     'html' => 'Deletes the passkey from your account. You can re-register it at any time — the device credential itself is not affected.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <?php CSDT_DevTools_Passkey::render_section( $user_id ); ?>
            </div>
        </div>

        <?php
        /* ── Test Account Manager ─────────────────────────────────────── */
        $test_secret   = CSDT_Test_Accounts::get_or_create_secret();
        $path_token    = CSDT_Test_Accounts::get_or_create_path_token();
        $masked_secret = str_repeat( '•', min( 20, strlen( $test_secret ) ) ) . substr( $test_secret, -4 );
        $session_url   = rest_url( 'csdt/v1/test-session-' . $path_token );
        $logout_url    = rest_url( 'csdt/v1/test-logout-' . $path_token );
        $test_users    = CSDT_Test_Accounts::get_test_users_with_sessions();
        ?>
        <div class="cs-panel" id="cs-panel-test-accounts">
            <div class="cs-section-header" style="background:linear-gradient(135deg,#1e3a8a,#1d4ed8)">
                <span>🧪 <?php esc_html_e( 'TEST ACCOUNT MANAGER', 'cloudscale-devtools' ); ?></span>
                <span class="cs-header-hint"><?php esc_html_e( 'Persistent test users for Playwright / CI — bypasses 2FA via server-side cookies', 'cloudscale-devtools' ); ?></span>
                <?php self::render_explain_btn( 'test-accounts', 'Test Account Manager', [
                    [ 'name' => 'How it works',      'rec' => 'Overview',    'html' => 'Create a persistent WordPress user for Playwright. Each test run calls the Session URL to get short-lived auth cookies — this happens server-side and never triggers wp-login.php or 2FA hooks. When the test run finishes, call the Logout URL to destroy the session.' ],
                    [ 'name' => 'Session URL',       'rec' => 'Required',    'html' => '<code>POST {session_url}</code><br>Body: <code>{ "secret": "...", "role": "your_name", "ttl": 1200 }</code><br>Returns auth cookies to inject into Playwright context. TTL max is 3600 seconds.' ],
                    [ 'name' => 'Logout URL',        'rec' => 'Recommended', 'html' => '<code>POST {logout_url}</code><br>Body: <code>{ "secret": "...", "role": "your_name" }</code> (or add <code>"session_token": "..."</code> to kill only that session). Call this in afterAll to clean up.' ],
                    [ 'name' => 'Security',          'rec' => 'Info',        'html' => 'Both the URL path token (32 random chars) and the shared secret are required to obtain a session. After 5 bad secret attempts the API locks for 10 minutes and sends an ntfy alert. Never commit the secret to git.' ],
                    [ 'name' => 'Block Basic Auth',  'rec' => 'Recommended', 'html' => 'WordPress Application Passwords allow REST API authentication via HTTP Basic Auth (<code>Authorization: Basic base64(user:app_password)</code>). This completely bypasses 2FA — an attacker who steals an app password gets full REST API access with no second factor.<br><br>The <strong>Block Basic Auth</strong> toggle disables app passwords site-wide so no user can create or use one. Your test accounts are unaffected — they authenticate via server-side session cookies, not Basic Auth.<br><br>To roll back: uncheck the toggle. Nothing is deleted.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <div class="cs-sec-settings">

                    <!-- Step 1: Create test user -->
                    <div class="cs-sec-row" style="align-items:flex-start;">
                        <span class="cs-sec-label" style="padding-top:4px;"><?php esc_html_e( 'Create test user:', 'cloudscale-devtools' ); ?></span>
                        <div class="cs-sec-control">
                            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                                <div style="display:flex;flex-direction:column;gap:4px;">
                                    <input type="text" id="cs-pwr-name" placeholder="e.g. my_playwright" maxlength="40" style="width:200px;" class="cs-sec-select">
                                    <span style="font-size:11px;color:#9ca3af;"><?php esc_html_e( 'Name — use as CSDT_TEST_ROLE in .env', 'cloudscale-devtools' ); ?></span>
                                </div>
                                <div style="display:flex;flex-direction:column;gap:4px;">
                                    <select id="cs-pwr-wp-role" class="cs-sec-select" style="width:auto;">
                                        <option value="administrator"><?php esc_html_e( 'Administrator', 'cloudscale-devtools' ); ?></option>
                                        <option value="editor"><?php esc_html_e( 'Editor', 'cloudscale-devtools' ); ?></option>
                                        <option value="author"><?php esc_html_e( 'Author', 'cloudscale-devtools' ); ?></option>
                                        <option value="contributor"><?php esc_html_e( 'Contributor', 'cloudscale-devtools' ); ?></option>
                                        <option value="subscriber"><?php esc_html_e( 'Subscriber', 'cloudscale-devtools' ); ?></option>
                                    </select>
                                    <span style="font-size:11px;color:#9ca3af;"><?php esc_html_e( 'WordPress role', 'cloudscale-devtools' ); ?></span>
                                </div>
                                <button type="button" id="cs-pwr-create" class="cs-btn-primary" style="align-self:flex-start;">
                                    + <?php esc_html_e( 'Create User', 'cloudscale-devtools' ); ?>
                                </button>
                            </div>
                            <span class="cs-hint" style="margin-top:6px;"><?php esc_html_e( 'Creates a persistent WP user. Reuse across all test runs — sessions are short-lived, the user account is not.', 'cloudscale-devtools' ); ?></span>
                            <div id="cs-pwr-msg" style="display:none;margin-top:8px;font-size:13px;"></div>
                        </div>
                    </div>

                    <hr class="cs-sec-divider" style="margin:8px 0;">

                    <!-- Step 2: Shared secret -->
                    <div class="cs-sec-row">
                        <span class="cs-sec-label"><?php esc_html_e( 'Shared secret:', 'cloudscale-devtools' ); ?></span>
                        <div class="cs-sec-control">
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <code id="cs-pwr-secret-display" style="background:#f1f5f9;border:1px solid #e2e8f0;border-radius:4px;padding:4px 10px;font-size:12px;letter-spacing:0.05em;"><?php echo esc_html( $masked_secret ); ?></code>
                                <button type="button" id="cs-pwr-secret-show" class="cs-btn-secondary cs-btn-sm">👁 <?php esc_html_e( 'Show', 'cloudscale-devtools' ); ?></button>
                                <button type="button" id="cs-pwr-secret-regen" class="cs-btn-secondary cs-btn-sm">↺ <?php esc_html_e( 'Regenerate', 'cloudscale-devtools' ); ?></button>
                            </div>
                            <span class="cs-hint" style="margin-top:6px;"><?php esc_html_e( 'Store in .env (never commit). Regenerating invalidates all existing .env files.', 'cloudscale-devtools' ); ?></span>
                        </div>
                    </div>

                    <!-- Session URL -->
                    <div class="cs-sec-row" style="margin-top:8px;">
                        <span class="cs-sec-label"><?php esc_html_e( 'Session URL:', 'cloudscale-devtools' ); ?></span>
                        <div class="cs-sec-control">
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <code style="background:#f1f5f9;border:1px solid #e2e8f0;border-radius:4px;padding:4px 10px;font-size:11px;word-break:break-all;"><?php echo esc_html( $session_url ); ?></code>
                                <button type="button" class="cs-btn-secondary cs-btn-sm cs-copy-url" data-url="<?php echo esc_attr( $session_url ); ?>">⎘ <?php esc_html_e( 'Copy', 'cloudscale-devtools' ); ?></button>
                            </div>
                        </div>
                    </div>

                    <!-- Logout URL -->
                    <div class="cs-sec-row" style="margin-top:8px;">
                        <span class="cs-sec-label"><?php esc_html_e( 'Logout URL:', 'cloudscale-devtools' ); ?></span>
                        <div class="cs-sec-control">
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <code style="background:#f1f5f9;border:1px solid #e2e8f0;border-radius:4px;padding:4px 10px;font-size:11px;word-break:break-all;"><?php echo esc_html( $logout_url ); ?></code>
                                <button type="button" class="cs-btn-secondary cs-btn-sm cs-copy-url" data-url="<?php echo esc_attr( $logout_url ); ?>">⎘ <?php esc_html_e( 'Copy', 'cloudscale-devtools' ); ?></button>
                            </div>
                        </div>
                    </div>

                    <!-- Block Basic Auth toggle -->
                    <div class="cs-sec-row" style="margin-top:8px;">
                        <span class="cs-sec-label"><?php esc_html_e( 'Block Basic Auth:', 'cloudscale-devtools' ); ?></span>
                        <div class="cs-sec-control">
                            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                <label class="cs-toggle-label" style="margin:0;">
                                    <input type="checkbox" id="cs-block-basic-auth-toggle" <?php checked( get_option( 'csdt_block_basic_auth', '0' ), '1' ); ?>>
                                    <span class="cs-toggle-switch"></span>
                                    <span class="cs-toggle-text"><?php esc_html_e( 'Disable REST API app passwords / Basic Auth for all users', 'cloudscale-devtools' ); ?></span>
                                </label>
                                <button type="button" id="cs-block-basic-auth-save" class="cs-btn-secondary cs-btn-sm"><?php esc_html_e( 'Save', 'cloudscale-devtools' ); ?></button>
                                <span id="cs-block-basic-auth-hint" style="font-size:12px;color:#6b7280;"></span>
                            </div>
                            <span class="cs-hint" style="margin-top:4px;"><?php esc_html_e( 'Session-based test auth is unaffected. Roll back by unchecking and saving.', 'cloudscale-devtools' ); ?></span>
                        </div>
                    </div>
                    <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- inline auth block button handler; wp_add_inline_script not available at this render point ?>
                    <script>
                    (function() {
                        var btn = document.getElementById('cs-block-basic-auth-save');
                        if (!btn) { return; }
                        btn.addEventListener('click', function() {
                            var toggle  = document.getElementById('cs-block-basic-auth-toggle');
                            var hint    = document.getElementById('cs-block-basic-auth-hint');
                            var enabled = toggle && toggle.checked ? '1' : '0';
                            var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
                            var nonce   = (typeof csdtTestAccounts !== 'undefined' ? csdtTestAccounts.nonce : '<?php echo esc_js( wp_create_nonce( 'csdt_devtools_login_nonce' ) ); ?>');
                            btn.disabled = true;
                            btn.textContent = '...';
                            var fd = new FormData();
                            fd.append('action',  'csdt_toggle_block_basic_auth');
                            fd.append('nonce',   nonce);
                            fd.append('enabled', enabled);
                            fetch(ajaxUrl, { method: 'POST', body: fd })
                                .then(function(r) { return r.text().then(function(t) { return { status: r.status, txt: t }; }); })
                                .then(function(res) {
                                    btn.disabled = false;
                                    btn.textContent = '<?php esc_html_e( 'Save', 'cloudscale-devtools' ); ?>';
                                    var resp = null;
                                    try { resp = JSON.parse(res.txt); } catch(e) {}
                                    if (resp && resp.success) {
                                        if (hint) { hint.textContent = '✓ Saved'; hint.style.color = '#166534'; setTimeout(function(){ hint.textContent = ''; }, 2000); }
                                    } else {
                                        var msg = resp ? (resp.data || 'Unknown error') : 'Server error (HTTP ' + res.status + ')';
                                        if (hint) { hint.textContent = '✗ ' + msg; hint.style.color = '#dc2626'; }
                                    }
                                })
                                .catch(function(e) {
                                    btn.disabled = false;
                                    btn.textContent = '<?php esc_html_e( 'Save', 'cloudscale-devtools' ); ?>';
                                    if (hint) { hint.textContent = '✗ ' + (e && e.message ? e.message : 'network error'); hint.style.color = '#dc2626'; }
                                });
                        });
                    }());
                    </script>

                    <hr class="cs-sec-divider" style="margin:8px 0;">

                    <!-- Step 4: Active test users + sessions -->
                    <div class="cs-sec-row" style="align-items:flex-start;">
                        <span class="cs-sec-label" style="padding-top:4px;"><?php esc_html_e( 'Test users:', 'cloudscale-devtools' ); ?></span>
                        <div class="cs-sec-control" style="flex:1;">
                            <div id="cs-pwr-users-list">
                            <?php if ( empty( $test_users ) ) : ?>
                                <p style="color:#9ca3af;font-size:13px;margin:0;"><?php esc_html_e( 'No test users yet - create one above.', 'cloudscale-devtools' ); ?></p>
                            <?php else : ?>
                                <?php foreach ( $test_users as $u ) :
                                    $last_login_str = '';
                                    if ( ! empty( $u['last_login'] ) ) {
                                        $diff = time() - (int) $u['last_login'];
                                        if ( $diff < 60 )        { $last_login_str = __( 'just now', 'cloudscale-devtools' ); }
                                        /* translators: %d is the number of minutes */
                                        elseif ( $diff < 3600 )  { $last_login_str = sprintf( __( '%dm ago', 'cloudscale-devtools' ), (int) floor( $diff / 60 ) ); }
                                        /* translators: %d is the number of hours */
                                        elseif ( $diff < 86400 ) { $last_login_str = sprintf( __( '%dh ago', 'cloudscale-devtools' ), (int) floor( $diff / 3600 ) ); }
                                        else                     { $last_login_str = wp_date( 'M j', $u['last_login'] ); }
                                    }
                                ?>
                                <div class="cs-pwr-user-row" style="display:flex;align-items:center;gap:10px;padding:8px 12px;margin-bottom:4px;background:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;flex-wrap:wrap;">
                                    <code style="font-size:12px;min-width:120px;"><?php echo esc_html( $u['name'] ); ?></code>
                                    <span style="font-size:12px;color:#6b7280;"><?php echo esc_html( ucfirst( $u['wp_role'] ?: 'administrator' ) ); ?></span>
                                    <span style="font-size:12px;color:#9ca3af;font-family:monospace;"><?php echo esc_html( $u['username'] ); ?></span>
                                    <span class="cs-pwr-sess-count" style="font-size:12px;color:<?php echo $u['session_count'] > 0 ? '#d97706' : '#9ca3af'; ?>">
                                        <?php echo esc_html( $u['session_count'] ); ?> <?php echo $u['session_count'] === 1 ? esc_html__( 'session', 'cloudscale-devtools' ) : esc_html__( 'sessions', 'cloudscale-devtools' ); ?>
                                    </span>
                                    <?php if ( $last_login_str ) : ?>
                                    <span style="font-size:11px;color:#9ca3af;"><?php echo esc_html__( 'Last login:', 'cloudscale-devtools' ); ?> <?php echo esc_html( $last_login_str ); ?></span>
                                    <?php endif; ?>
                                    <div style="margin-left:auto;display:flex;gap:6px;flex-shrink:0;">
                                        <button type="button" class="cs-btn-secondary cs-btn-sm cs-pwr-kill-sessions" data-name="<?php echo esc_attr( $u['name'] ); ?>" <?php echo $u['session_count'] === 0 ? 'disabled' : ''; ?>>
                                            <?php esc_html_e( 'Kill Sessions', 'cloudscale-devtools' ); ?>
                                        </button>
                                        <button type="button" class="cs-btn-secondary cs-btn-sm cs-pwr-delete" data-name="<?php echo esc_attr( $u['name'] ); ?>" style="color:#dc2626;border-color:#fca5a5;">
                                            <?php esc_html_e( 'Delete User', 'cloudscale-devtools' ); ?>
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </div>

                            <!-- .env.test snippet -->
                            <details style="margin-top:16px;" id="cs-pwr-snippet-details">
                                <summary style="cursor:pointer;font-size:12px;font-weight:600;color:#1e40af;user-select:none;"><?php esc_html_e( 'Show .env.test snippet', 'cloudscale-devtools' ); ?></summary>
                                <div style="margin-top:8px;">
                                    <pre id="cs-pwr-snippet" style="margin:0 0 8px;padding:12px;background:#0f172a;color:#e2e8f0;border-radius:6px;font-size:11px;line-height:1.7;overflow-x:auto;white-space:pre;"></pre>
                                    <button type="button" id="cs-pwr-copy-snippet" class="cs-btn-secondary cs-btn-sm">⎘ <?php esc_html_e( 'Copy', 'cloudscale-devtools' ); ?></button>
                                </div>
                            </details>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <?php
    }

    /* ==================================================================
       6. SQL COMMAND: Query validation + AJAX
       ================================================================== */

    /**
     * Returns true when the SQL string begins with a read-only keyword and contains no semicolons.
     *
     * @since  1.6.0
     * @param  string $sql Raw SQL string to validate.
     * @return bool
     */
    private static function is_safe_query( string $sql ): bool {
        $clean = trim( $sql );
        // Strip all block comments (including mid-query MySQL /*!...*/ optimizer hints),
        // line comments (-- and #), and surrounding whitespace before keyword check.
        $clean = preg_replace( '/\/\*.*?\*\//s', '', $clean );
        $clean = preg_replace( '/(--|#)[^\n]*/m', '', $clean );
        $clean = trim( $clean );
        // Strip a single trailing semicolon — a statement terminator is harmless on its own.
        $clean = rtrim( rtrim( $clean ), ';' );
        // Reject any semicolon remaining mid-query — prevents statement stacking
        // (e.g. SELECT 1; DROP TABLE wp_users).
        if ( strpos( $clean, ';' ) !== false ) {
            return false;
        }
        // Reject file-system abuse clauses regardless of SELECT keyword.
        if ( preg_match( '/\b(INTO\s+OUTFILE|INTO\s+DUMPFILE|LOAD_FILE)\b/i', $clean ) ) {
            return false;
        }
        if ( preg_match( '/^(\w+)/i', $clean, $m ) ) {
            $first = strtoupper( $m[1] );
            return in_array( $first, [ 'SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN' ], true );
        }
        return false;
    }

    /**
     * AJAX handler: executes a validated read-only SQL query and returns results as JSON.
     *
     * @since  1.6.0
     * @return void Sends JSON response and exits.
     */
    public static function ajax_sql_run(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }
        if ( ! check_ajax_referer( CloudScale_DevTools::SQL_NONCE, 'nonce', false ) ) {
            wp_send_json_error( 'Bad nonce', 403 );
        }

        $raw = isset( $_POST['sql'] ) ? $_POST['sql'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- raw SQL for admin tool; unslashed on next line, validated via is_safe_query()
        $sql = trim( wp_unslash( $raw ) );
        if ( ! $sql ) {
            wp_send_json_error( 'Empty query' );
        }

        if ( ! self::is_safe_query( $sql ) ) {
            wp_send_json_error( 'Only SELECT, SHOW, DESCRIBE, and EXPLAIN queries are allowed. Do not include shell commands like sudo or mysql.' );
        }

        global $wpdb;
        $wpdb->suppress_errors( true );
        $start = microtime( true );
        // prepare() cannot be applied to a free-form admin SQL tool — the entire
        // query is the user's input, leaving no placeholders to bind. Safety is
        // provided by is_safe_query() (read-only keywords + no semicolons),
        // manage_options capability gate, and nonce verification above.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results( $sql, ARRAY_A );
        $elapsed = round( ( microtime( true ) - $start ) * 1000, 2 );
        $error   = $wpdb->last_error;
        $wpdb->suppress_errors( false );

        if ( $error ) {
            wp_send_json_error( $error );
        }

        wp_send_json_success( [
            'rows'    => $results,
            'count'   => count( $results ),
            'elapsed' => $elapsed,
        ] );
    }

    public static function ajax_sql_http_fix(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }
        if ( ! check_ajax_referer( CloudScale_DevTools::SQL_NONCE, 'nonce', false ) ) {
            wp_send_json_error( 'Bad nonce', 403 );
        }

        $dry_run = isset( $_POST['dry_run'] ) && '1' === $_POST['dry_run'];
        $host    = wp_parse_url( home_url(), PHP_URL_HOST );
        $from    = 'http://' . $host;
        $to      = 'https://' . $host;

        global $wpdb;

        $tables      = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $total_cells = 0;
        $lines       = [];

        foreach ( $tables as $table ) {
            if ( strpos( $table, '_trash_' ) !== false ) {
                continue;
            }
            // Get primary key and all text-like columns.
            $columns   = $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $pk        = null;
            $text_cols = [];
            foreach ( $columns as $col ) {
                if ( strtolower( $col['Key'] ) === 'pri' && ! $pk ) {
                    $pk = $col['Field'];
                }
                $type = strtolower( $col['Type'] );
                if ( strpos( $type, 'char' ) !== false || strpos( $type, 'text' ) !== false
                    || strpos( $type, 'blob' ) !== false || strpos( $type, 'json' ) !== false ) {
                    // Skip guid column.
                    if ( $col['Field'] !== 'guid' ) {
                        $text_cols[] = $col['Field'];
                    }
                }
            }
            if ( empty( $text_cols ) || ! $pk ) {
                continue;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results( "SELECT `{$pk}`, `" . implode( '`, `', $text_cols ) . "` FROM `{$table}`", ARRAY_A );
            $changed = 0;
            foreach ( (array) $rows as $row ) {
                $pk_val  = $row[ $pk ];
                $updates = [];
                foreach ( $text_cols as $col ) {
                    $orig = $row[ $col ];
                    if ( $orig === null || strpos( $orig, $from ) === false ) {
                        continue;
                    }
                    $new = CSDT_Code_Migrator::recursive_str_replace( $from, $to, $orig );
                    if ( $new !== $orig ) {
                        $updates[ $col ] = $new;
                    }
                }
                if ( $updates ) {
                    ++$changed;
                    ++$total_cells;
                    if ( ! $dry_run ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->update( $table, $updates, [ $pk => $pk_val ] );
                    }
                }
            }
            if ( $changed ) {
                $lines[] = sprintf( '%s: %d row(s) updated', $table, $changed );
            }
        }

        $lines[] = sprintf( '--- Total: %d cell(s) %s', $total_cells, $dry_run ? 'would be updated (dry run)' : 'updated' );

        wp_send_json_success( [
            'output'  => implode( "\n", $lines ),
            'dry_run' => $dry_run,
            'from'    => $from,
            'to'      => $to,
            'total'   => $total_cells,
        ] );
    }

    /* ==================================================================
       8. LOGIN SECURITY — Hook registrations (class extracted to class-login.php)
       ================================================================== */


    public static function register_dashboard_widget(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        wp_add_dashboard_widget(
            'csdt_security_summary',
            '🔐 Cyber Devtools',
            [ __CLASS__, 'render_dashboard_widget' ]
        );
    }

    public static function render_dashboard_widget(): void {
        wp_prime_option_caches( [
            'csdt_scan_history', 'csdt_adhoc_scans', 'csdt_site_audit_cache',
            'csdt_devtools_bf_log', 'csdt_ip_blocklist', 'csdt_wplogin_blocked_stats',
            'csdt_uptime_last_ping',
        ] );
        $ai_cfg        = CSDT_AI_Dispatcher::get_config();
        $ai_provider   = $ai_cfg['provider'];
        $has_key       = ! empty( $ai_cfg['key'] );
        $provider_lbl  = $ai_provider === 'gemini' ? 'Google Gemini' : 'Anthropic Claude';

        $history   = get_option( 'csdt_scan_history', [] );
        $last_scan = ! empty( $history ) ? $history[0] : null;
        $score_cls = '#888';
        if ( $last_scan ) {
            $s = (int) ( $last_scan['score'] ?? 0 );
            $score_cls = $s >= 75 ? '#16a34a' : ( $s >= 55 ? '#d97706' : '#dc2626' );
        }

        $audit_cache       = get_option( 'csdt_site_audit_cache', null );
        $audit_counts      = $audit_cache['data']['counts'] ?? null;
        $audit_run_at      = $audit_cache['run_at'] ?? 0;
        $audit_critical    = isset( $audit_counts ) ? (int) ( $audit_counts['critical'] ?? 0 ) : null;
        $audit_high        = isset( $audit_counts ) ? (int) ( $audit_counts['high'] ?? 0 ) : null;

        $bf_on      = get_option( 'csdt_devtools_brute_force_enabled', '1' ) === '1';
        $login_slug = get_option( 'csdt_devtools_login_slug', '' );
        $force_2fa  = get_option( 'csdt_devtools_2fa_force_admins', '0' ) === '1';
        $email_2fa  = get_option( 'csdt_devtools_2fa_method', 'off' ) === 'email';
        $admins     = get_users( [ 'role' => 'administrator' ] );
        $adm_tot    = count( $admins );
        $adm_2fa    = 0;
        foreach ( $admins as $u ) {
            if ( get_user_meta( $u->ID, 'csdt_devtools_totp_enabled', true ) === '1'
                 || ! empty( get_user_meta( $u->ID, 'csdt_devtools_passkeys', true ) )
                 || $email_2fa ) {
                $adm_2fa++;
            }
        }

        // Extra login security stats.
        $bf_log          = get_option( 'csdt_devtools_bf_log', [] );
        $today_str       = gmdate( 'Y-m-d' );
        $failed_today    = 0;
        $failed_7d       = 0;
        $cutoff_7d       = time() - 7 * DAY_IN_SECONDS;
        foreach ( $bf_log as $entry ) {
            $ts = (int) ( $entry['time'] ?? 0 );
            if ( gmdate( 'Y-m-d', $ts ) === $today_str ) { $failed_today++; }
            if ( $ts >= $cutoff_7d )                      { $failed_7d++; }
        }
        $blocklist      = get_option( 'csdt_ip_blocklist', [] );
        $blocked_count  = is_array( $blocklist ) ? count( $blocklist ) : 0;
        $probe_stats    = get_option( 'csdt_wplogin_blocked_stats', [] );
        $probes_today   = (int) ( $probe_stats[ $today_str ]['count'] ?? 0 );
        $session_dur    = get_option( 'csdt_devtools_session_duration', 'default' );

        $base_url = admin_url( 'tools.php?page=cloudscale-devtools' );
        ?>

        <?php
        $combined_critical = (int) ( $last_scan['critical_count'] ?? 0 ) + ( $audit_critical ?? 0 );
        $combined_high     = (int) ( $last_scan['high_count'] ?? 0 ) + ( $audit_high ?? 0 );
        $either_run        = $last_scan || null !== $audit_critical;

        // Score ring colours
        if ( $last_scan ) {
            $s = (int) ( $last_scan['score'] ?? 0 );
            if ( $s >= 75 ) {
                $ring_color = '#16a34a'; $ring_bg = '#f0fdf4';
            } elseif ( $s >= 55 ) {
                $ring_color = '#d97706'; $ring_bg = '#fffbeb';
            } else {
                $ring_color = '#dc2626'; $ring_bg = '#fef2f2';
            }
        } else {
            $ring_color = '#94a3b8'; $ring_bg = '#f8fafc';
        }

        // 2FA chip colour
        $tfa_all  = ( $adm_tot > 0 && $adm_2fa === $adm_tot );
        $tfa_some = ( $adm_2fa > 0 && ! $tfa_all );
        $tfa_dot  = $tfa_all ? '#16a34a' : ( $tfa_some ? '#d97706' : '#dc2626' );
        ?>

        <!-- ── Dark header band ───────────────────────────────────────── -->
        <div class="cs-dw-header">
            <div class="cs-dw-header-left">
                <span style="font-size:17px;line-height:1;flex-shrink:0">🔐</span>
                <div>
                    <div class="cs-dw-header-title"><?php esc_html_e( 'Cyber Devtools', 'cloudscale-devtools' ); ?></div>
                    <div class="cs-dw-header-sub"><?php esc_html_e( 'AI security · 2FA · Login protection', 'cloudscale-devtools' ); ?></div>
                </div>
            </div>
            <div class="cs-dw-header-right">
                <?php if ( $either_run ) : ?>
                <div class="cs-dw-hpill" style="color:<?php echo $combined_critical > 0 ? '#fca5a5' : '#86efac'; ?>">
                    <span class="cs-dw-hpill-num"><?php echo esc_html( $combined_critical ); ?></span>
                    <span class="cs-dw-hpill-lbl"><?php esc_html_e( 'Critical', 'cloudscale-devtools' ); ?></span>
                </div>
                <div class="cs-dw-hpill" style="color:<?php echo $combined_high > 0 ? '#fcd34d' : '#86efac'; ?>">
                    <span class="cs-dw-hpill-num"><?php echo esc_html( $combined_high ); ?></span>
                    <span class="cs-dw-hpill-lbl"><?php esc_html_e( 'High', 'cloudscale-devtools' ); ?></span>
                </div>
                <?php endif; ?>
                <div class="cs-dw-hpill" style="color:<?php echo $failed_today > 0 ? '#fca5a5' : '#86efac'; ?>">
                    <span class="cs-dw-hpill-num"><?php echo esc_html( $failed_today ); ?></span>
                    <span class="cs-dw-hpill-lbl"><?php esc_html_e( 'Failed', 'cloudscale-devtools' ); ?></span>
                </div>
                <div class="cs-dw-hpill" style="color:<?php echo $blocked_count > 0 ? '#fcd34d' : '#86efac'; ?>">
                    <span class="cs-dw-hpill-num"><?php echo esc_html( $blocked_count ); ?></span>
                    <span class="cs-dw-hpill-lbl"><?php esc_html_e( 'Blocked', 'cloudscale-devtools' ); ?></span>
                </div>
                <span style="background:rgba(15,184,224,0.15);border:1px solid rgba(15,184,224,0.4);color:#67e8f9;font-size:10px;font-weight:700;padding:3px 8px;border-radius:20px;letter-spacing:0.04em">v<?php echo esc_html( self::VERSION ); ?></span>
            </div>
        </div>

        <!-- ── Hero: score + scan times ──────────────────────────────── -->
        <div class="cs-dw-hero">
            <div class="cs-dw-score-ring" style="color:<?php echo esc_attr( $ring_color ); ?>;background:<?php echo esc_attr( $ring_bg ); ?>;">
                <?php echo $either_run ? esc_html( $last_scan['score'] ?? '—' ) : '—'; ?>
            </div>
            <div class="cs-dw-hero-meta">
                <div class="cs-dw-hero-title">
                    <?php
                    if ( $either_run && $last_scan ) {
                        echo esc_html( $last_scan['score_label'] ?? __( 'Scan complete', 'cloudscale-devtools' ) );
                    } elseif ( $has_key ) {
                        esc_html_e( 'No scan yet', 'cloudscale-devtools' );
                    } else {
                        esc_html_e( 'AI key not configured', 'cloudscale-devtools' );
                    }
                    ?>
                </div>
                <div class="cs-dw-hero-sub">
                    <?php
                    if ( $either_run ) {
                        if ( $last_scan ) {
                            /* translators: %s time diff */
                            printf( esc_html__( 'Scanned %s ago', 'cloudscale-devtools' ), esc_html( human_time_diff( (int) ( $last_scan['scanned_at'] ?? 0 ) ) ) );
                        }
                        if ( null !== $audit_critical ) {
                            echo $last_scan ? ' &middot; ' : '';
                            /* translators: %s time diff */
                            printf( esc_html__( 'Audited %s ago', 'cloudscale-devtools' ), esc_html( human_time_diff( $audit_run_at ) ) );
                        }
                    } else {
                        echo esc_html( $has_key ? $provider_lbl : __( 'Add a free Gemini key to start', 'cloudscale-devtools' ) );
                    }
                    ?>
                </div>
                <?php if ( $last_scan ) : ?>
                <div style="margin-top:6px"><a href="<?php echo esc_url( $base_url . '&tab=security' ); ?>" style="font-size:11px;font-weight:600;color:#0369a1;text-decoration:none"><?php esc_html_e( 'View full report →', 'cloudscale-devtools' ); ?></a></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Login Security ─────────────────────────────────────────── -->
        <div class="cs-dw-section">🔒 <?php esc_html_e( 'Login Security', 'cloudscale-devtools' ); ?></div>
        <div class="cs-dw-grid">
            <div class="cs-dw-chip">
                <span class="cs-dw-dot" style="background:<?php echo $bf_on ? '#16a34a' : '#dc2626'; ?>;"></span>
                <span>
                    <span class="cs-dw-chip-label"><?php esc_html_e( 'Brute Force', 'cloudscale-devtools' ); ?></span><br>
                    <?php echo $bf_on ? esc_html__( 'Protected', 'cloudscale-devtools' ) : esc_html__( 'Off', 'cloudscale-devtools' ); ?>
                </span>
            </div>
            <div class="cs-dw-chip">
                <span class="cs-dw-dot" style="background:<?php echo esc_attr( $tfa_dot ); ?>;"></span>
                <span>
                    <span class="cs-dw-chip-label"><?php esc_html_e( '2FA Admins', 'cloudscale-devtools' ); ?></span><br>
                    <?php echo esc_html( $adm_2fa . ' / ' . $adm_tot ); ?>
                </span>
            </div>
            <div class="cs-dw-chip">
                <span class="cs-dw-dot" style="background:<?php echo ! empty( $login_slug ) ? '#16a34a' : '#dc2626'; ?>;"></span>
                <span>
                    <span class="cs-dw-chip-label"><?php esc_html_e( 'Hide Login', 'cloudscale-devtools' ); ?></span><br>
                    <?php echo ! empty( $login_slug ) ? '/' . esc_html( $login_slug ) : esc_html__( 'Off', 'cloudscale-devtools' ); ?>
                </span>
            </div>
            <div class="cs-dw-chip">
                <span class="cs-dw-dot" style="background:<?php echo $force_2fa ? '#16a34a' : '#dc2626'; ?>;"></span>
                <span>
                    <span class="cs-dw-chip-label"><?php esc_html_e( 'Force 2FA', 'cloudscale-devtools' ); ?></span><br>
                    <?php echo $force_2fa ? esc_html__( 'On', 'cloudscale-devtools' ) : esc_html__( 'Off', 'cloudscale-devtools' ); ?>
                </span>
            </div>
            <div class="cs-dw-chip">
                <span class="cs-dw-dot" style="background:<?php echo $failed_today > 0 ? '#dc2626' : '#16a34a'; ?>;"></span>
                <span>
                    <span class="cs-dw-chip-label"><?php esc_html_e( 'Failed Logins Today', 'cloudscale-devtools' ); ?></span><br>
                    <?php echo esc_html( $failed_today ); ?>
                    <?php if ( $failed_7d > $failed_today ) : ?>
                        <span style="font-size:10px;color:#94a3b8">&nbsp;(<?php echo esc_html( $failed_7d ); ?> / 7d)</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="cs-dw-chip">
                <span class="cs-dw-dot" style="background:<?php echo $probes_today > 0 ? '#d97706' : '#16a34a'; ?>;"></span>
                <span>
                    <span class="cs-dw-chip-label"><?php esc_html_e( 'wp-login Probes Today', 'cloudscale-devtools' ); ?></span><br>
                    <?php echo esc_html( $probes_today ); ?>
                </span>
            </div>
            <div class="cs-dw-chip">
                <span class="cs-dw-dot" style="background:<?php echo $blocked_count > 0 ? '#d97706' : '#16a34a'; ?>;"></span>
                <span>
                    <span class="cs-dw-chip-label"><?php esc_html_e( 'Blocked IPs', 'cloudscale-devtools' ); ?></span><br>
                    <?php echo esc_html( $blocked_count ); ?>
                </span>
            </div>
            <div class="cs-dw-chip">
                <span class="cs-dw-dot" style="background:#16a34a;"></span>
                <span>
                    <span class="cs-dw-chip-label"><?php esc_html_e( 'Session Length', 'cloudscale-devtools' ); ?></span><br>
                    <?php
                    $dur_labels = [
                        'default' => __( 'WP default', 'cloudscale-devtools' ),
                        '1h'      => '1 hour',
                        '4h'      => '4 hours',
                        '8h'      => '8 hours',
                        '24h'     => '24 hours',
                        '48h'     => '2 days',
                        '7d'      => '7 days',
                        '30d'     => '30 days',
                    ];
                    echo esc_html( $dur_labels[ $session_dur ] ?? $session_dur );
                    ?>
                </span>
            </div>
        </div>

        <!-- ── Recent Security Events ────────────────────────────────── -->
        <?php
        $sec_events = get_option( 'csdt_security_events', [] );
        $sec_events = is_array( $sec_events ) ? array_reverse( $sec_events ) : [];
        $sec_events = array_slice( $sec_events, 0, 5 );
        if ( ! empty( $sec_events ) ) :
        ?>
        <div class="cs-dw-section">⚠️ <?php esc_html_e( 'Recent Security Events', 'cloudscale-devtools' ); ?></div>
        <div style="display:flex;flex-direction:column;gap:5px;margin-bottom:12px;">
            <?php foreach ( $sec_events as $ev ) :
                $ev_time   = (int) ( $ev['time'] ?? 0 );
                $ev_type   = (string) ( $ev['type'] ?? '' );
                $ev_title  = (string) ( $ev['title'] ?? '' );
                $ev_detail = (string) ( $ev['detail'] ?? '' );
                $age       = $ev_time ? human_time_diff( $ev_time ) . ' ago' : '';
                if ( $ev_type === 'downgrade' ) {
                    $icon = '🔓'; $bg = '#fef2f2'; $border = '#fca5a5'; $tx = '#991b1b';
                } elseif ( $ev_type === 'attack' ) {
                    $icon = '🎯'; $bg = '#fff7ed'; $border = '#fdba74'; $tx = '#9a3412';
                } else {
                    $icon = '🔌'; $bg = '#fefce8'; $border = '#fde047'; $tx = '#854d0e';
                }
            ?>
            <div style="background:<?php echo esc_attr( $bg ); ?>;border:1px solid <?php echo esc_attr( $border ); ?>;border-radius:6px;padding:6px 9px;display:flex;align-items:flex-start;gap:7px;">
                <span style="font-size:13px;flex-shrink:0;line-height:1.4"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <div style="flex:1;min-width:0">
                    <div style="font-size:11px;font-weight:700;color:<?php echo esc_attr( $tx ); ?>;line-height:1.3"><?php echo esc_html( $ev_title ); ?></div>
                    <?php if ( $ev_detail ) : ?>
                    <div style="font-size:10px;color:#64748b;margin-top:1px"><?php echo esc_html( $ev_detail ); ?></div>
                    <?php endif; ?>
                </div>
                <?php if ( $age ) : ?>
                <span style="font-size:9px;color:#94a3b8;white-space:nowrap;flex-shrink:0;margin-top:2px"><?php echo esc_html( $age ); ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── CTA ────────────────────────────────────────────────────── -->
        <div class="cs-dw-cta">
            <a href="<?php echo esc_url( $base_url ); ?>">
                <span style="font-size:15px;">🛡️</span>
                <?php esc_html_e( 'Open Cyber and Devtools', 'cloudscale-devtools' ); ?>
            </a>
        </div>
        <?php
    }

    private static function render_home_panel(): void {
        $ai_cfg         = CSDT_AI_Dispatcher::get_config();
        $ai_provider    = $ai_cfg['provider'];
        $has_key        = ! empty( $ai_cfg['key'] );
        $next_run       = wp_next_scheduled( 'csdt_scheduled_scan' );
        $sec_url        = admin_url( 'tools.php?page=' . self::TOOLS_SLUG . '&tab=security' );
        $rollback_info  = get_option( 'csdt_db_prefix_rollback' );
        // Status dashboard data
        $history        = get_option( 'csdt_scan_history', [] );
        $last_scan      = ! empty( $history ) ? $history[0] : null;
        $tm_enabled     = get_option( 'csdt_threat_monitor_enabled', '1' ) === '1';
        $smtp_enabled   = get_option( 'csdt_devtools_smtp_enabled', '0' ) === '1';
        $smtp_host      = trim( (string) get_option( 'csdt_devtools_smtp_host', '' ) );
        $login_slug     = get_option( 'csdt_devtools_login_slug', '' );
        $bf_on          = get_option( 'csdt_devtools_brute_force_enabled', '1' ) === '1';
        $force_2fa      = get_option( 'csdt_devtools_2fa_force_admins', '0' ) === '1';
        $uptime_enabled   = get_option( 'csdt_uptime_enabled', '0' ) === '1';
        $uptime_url       = trim( (string) get_option( 'csdt_uptime_worker_url', '' ) );
        $tm_last_run      = (int) get_option( 'csdt_threat_last_run', 0 );
        $uptime_last_ping = get_option( 'csdt_uptime_last_ping', null );
        // URLs for status cards
        $login_url      = admin_url( 'tools.php?page=' . self::TOOLS_SLUG . '&tab=login' );
        $mail_url       = admin_url( 'tools.php?page=' . self::TOOLS_SLUG . '&tab=mail' );
        $debug_url      = admin_url( 'tools.php?page=' . self::TOOLS_SLUG . '&tab=debug' );
        ?>
        <div id="cs-panel-home" class="cs-panel" style="margin-bottom:0;">

        <!-- ── Status Dashboard ─────────────────────────────────────────── -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;padding:12px;">

            <?php
            // ── Card helper: reuse to keep markup DRY
            // Card 1: AI Cyber Scan
            if ( $has_key ) :
                $provider_label = $ai_provider === 'gemini' ? 'Google Gemini' : 'Anthropic Claude';
                /* translators: %s is a human-readable time difference e.g. "5 minutes ago" */
                $scan_detail    = $last_scan
                    ? sprintf( __( 'Last scan: %s', 'cloudscale-devtools' ), human_time_diff( (int) ( $last_scan['scanned_at'] ?? 0 ) ) . ' ' . __( 'ago', 'cloudscale-devtools' ) )
                    : $provider_label;
            ?>
            <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:12px 14px;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;"><?php esc_html_e( 'AI Cyber Scan', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:13px;font-weight:700;color:#15803d;">&#x2705; <?php esc_html_e( 'Configured', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:11px;color:#6b7280;margin-top:2px;line-height:1.4;"><?php echo esc_html( $scan_detail ); ?></div>
                <a href="<?php echo esc_url( $sec_url ); ?>" style="font-size:11px;color:#6366f1;font-weight:600;display:inline-block;margin-top:6px;text-decoration:none;"><?php esc_html_e( 'Run Scan', 'cloudscale-devtools' ); ?> &rarr;</a>
            </div>
            <?php else : ?>
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:12px 14px;">
                <div style="font-size:10px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;"><?php esc_html_e( 'AI Cyber Scan', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:13px;font-weight:700;color:#dc2626;">&#x274C; <?php esc_html_e( 'Not configured', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:11px;color:#dc2626;margin-top:2px;line-height:1.4;"><?php esc_html_e( 'Free Gemini tier available, no card needed.', 'cloudscale-devtools' ); ?></div>
                <a href="<?php echo esc_url( $sec_url ); ?>" style="font-size:11px;color:#6366f1;font-weight:600;display:inline-block;margin-top:6px;text-decoration:none;"><?php esc_html_e( 'Configure', 'cloudscale-devtools' ); ?> &rarr;</a>
            </div>
            <?php endif; ?>

            <!-- Card 2: Threat Monitor -->
            <div style="background:<?php echo $tm_enabled ? '#f0fdf4' : '#fef2f2'; ?>;border:1px solid <?php echo $tm_enabled ? '#86efac' : '#fca5a5'; ?>;border-radius:6px;padding:12px 14px;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;"><?php esc_html_e( 'Threat Monitor', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:13px;font-weight:700;color:<?php echo $tm_enabled ? '#15803d' : '#dc2626'; ?>;">
                    <?php echo $tm_enabled ? '&#x2705; ' . esc_html__( 'Active', 'cloudscale-devtools' ) : '&#x274C; ' . esc_html__( 'Disabled', 'cloudscale-devtools' ); ?>
                </div>
                <div style="font-size:11px;color:#6b7280;margin-top:2px;line-height:1.4;">
                    <?php if ( $tm_last_run ) : ?>
                        <?php /* translators: %s is a human-readable time difference e.g. "5 minutes" */ printf( esc_html__( 'Last check: %s ago', 'cloudscale-devtools' ), esc_html( human_time_diff( $tm_last_run ) ) ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'File integrity, new-admin and probe detection.', 'cloudscale-devtools' ); ?>
                    <?php endif; ?>
                </div>
                <a href="<?php echo esc_url( $sec_url ); ?>" style="font-size:11px;color:#6366f1;font-weight:600;display:inline-block;margin-top:6px;text-decoration:none;"><?php esc_html_e( 'Security', 'cloudscale-devtools' ); ?> &rarr;</a>
            </div>

            <!-- Card 3: SMTP Mail -->
            <?php if ( $smtp_enabled && $smtp_host ) : ?>
            <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:12px 14px;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;"><?php esc_html_e( 'SMTP Mail', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:13px;font-weight:700;color:#15803d;">&#x2705; <?php esc_html_e( 'Configured', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:11px;color:#6b7280;margin-top:2px;line-height:1.4;"><?php echo esc_html( $smtp_host ); ?></div>
                <a href="<?php echo esc_url( $mail_url ); ?>" style="font-size:11px;color:#6366f1;font-weight:600;display:inline-block;margin-top:6px;text-decoration:none;"><?php esc_html_e( 'Mail / SMTP', 'cloudscale-devtools' ); ?> &rarr;</a>
            </div>
            <?php else : ?>
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:12px 14px;">
                <div style="font-size:10px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;"><?php esc_html_e( 'SMTP Mail', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:13px;font-weight:700;color:#dc2626;">&#x274C; <?php esc_html_e( 'Not configured', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:11px;color:#dc2626;margin-top:2px;line-height:1.4;"><?php esc_html_e( 'WordPress default mail (unreliable).', 'cloudscale-devtools' ); ?></div>
                <a href="<?php echo esc_url( $mail_url ); ?>" style="font-size:11px;color:#6366f1;font-weight:600;display:inline-block;margin-top:6px;text-decoration:none;"><?php esc_html_e( 'Configure', 'cloudscale-devtools' ); ?> &rarr;</a>
            </div>
            <?php endif; ?>

            <!-- Card 4: Login Security -->
            <?php
            $login_ok = $login_slug && $bf_on && $force_2fa;
            $login_bg = $login_ok ? '#f0fdf4' : ( $login_slug || $bf_on ? '#fffbeb' : '#fef2f2' );
            $login_bd = $login_ok ? '#86efac' : ( $login_slug || $bf_on ? '#fcd34d' : '#fca5a5' );
            $login_tx = $login_ok ? '#15803d' : ( $login_slug || $bf_on ? '#92400e' : '#dc2626' );
            $login_details = [];
            if ( $login_slug ) $login_details[] = __( 'Hidden login', 'cloudscale-devtools' );
            if ( $bf_on )      $login_details[] = __( 'Brute-force lock', 'cloudscale-devtools' );
            if ( $force_2fa )  $login_details[] = __( 'Forced 2FA', 'cloudscale-devtools' );
            ?>
            <div style="background:<?php echo esc_attr( $login_bg ); ?>;border:1px solid <?php echo esc_attr( $login_bd ); ?>;border-radius:6px;padding:12px 14px;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;"><?php esc_html_e( 'Login Security', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:13px;font-weight:700;color:<?php echo esc_attr( $login_tx ); ?>;">
                    <?php echo $login_ok ? '&#x2705; ' . esc_html__( 'Hardened', 'cloudscale-devtools' ) : ( $login_details ? '&#x26A0;&#xFE0F; ' . esc_html__( 'Partial', 'cloudscale-devtools' ) : '&#x274C; ' . esc_html__( 'Default settings', 'cloudscale-devtools' ) ); ?>
                </div>
                <div style="font-size:11px;color:#6b7280;margin-top:2px;line-height:1.4;">
                    <?php echo $login_details ? esc_html( implode( ', ', $login_details ) ) : esc_html__( 'No protections active.', 'cloudscale-devtools' ); ?>
                </div>
                <a href="<?php echo esc_url( $login_url ); ?>" style="font-size:11px;color:#6366f1;font-weight:600;display:inline-block;margin-top:6px;text-decoration:none;"><?php esc_html_e( 'Login Security', 'cloudscale-devtools' ); ?> &rarr;</a>
            </div>

            <!-- Card 5: Uptime Monitor -->
            <?php if ( $uptime_enabled && $uptime_url ) : ?>
            <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:12px 14px;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;"><?php esc_html_e( 'Uptime Monitor', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:13px;font-weight:700;color:#15803d;">&#x2705; <?php esc_html_e( 'Active', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:11px;color:#6b7280;margin-top:2px;line-height:1.4;">
                    <?php if ( $uptime_last_ping && isset( $uptime_last_ping['time'] ) ) : ?>
                        <?php /* translators: %s is a human-readable time difference e.g. "5 minutes" */ printf( esc_html__( 'Last ping: %s ago', 'cloudscale-devtools' ), esc_html( human_time_diff( (int) $uptime_last_ping['time'] ) ) ); ?>
                        <?php if ( isset( $uptime_last_ping['ms'] ) ) : ?>&nbsp;&middot;&nbsp;<?php echo esc_html( $uptime_last_ping['ms'] ); ?>ms<?php endif; ?>
                    <?php else : ?>
                        <?php esc_html_e( 'Cloudflare Worker heartbeat enabled.', 'cloudscale-devtools' ); ?>
                    <?php endif; ?>
                </div>
                <a href="<?php echo esc_url( $debug_url ); ?>" style="font-size:11px;color:#6366f1;font-weight:600;display:inline-block;margin-top:6px;text-decoration:none;"><?php esc_html_e( 'Diagnostics', 'cloudscale-devtools' ); ?> &rarr;</a>
            </div>
            <?php else : ?>
            <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:12px 14px;">
                <div style="font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;"><?php esc_html_e( 'Uptime Monitor', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:13px;font-weight:700;color:#92400e;">&#x26A0;&#xFE0F; <?php esc_html_e( 'Not configured', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:11px;color:#92400e;margin-top:2px;line-height:1.4;"><?php esc_html_e( 'Ping your site from Cloudflare edge every minute.', 'cloudscale-devtools' ); ?></div>
                <a href="<?php echo esc_url( $debug_url ); ?>" style="font-size:11px;color:#6366f1;font-weight:600;display:inline-block;margin-top:6px;text-decoration:none;"><?php esc_html_e( 'Set up', 'cloudscale-devtools' ); ?> &rarr;</a>
            </div>
            <?php endif; ?>

            <!-- Card 6: Scheduled Scan -->
            <?php if ( $has_key && $next_run ) :
                $next_run_str = wp_date( 'D j M, g:ia', $next_run );
            ?>
            <div style="background:#f0f9ff;border:1px solid #7dd3fc;border-radius:6px;padding:12px 14px;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;"><?php esc_html_e( 'Scheduled Scan', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:13px;font-weight:700;color:#0284c7;">&#x1F551; <?php esc_html_e( 'Scheduled', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:11px;color:#6b7280;margin-top:2px;line-height:1.4;"><?php echo esc_html( $next_run_str ); ?></div>
                <a href="<?php echo esc_url( $sec_url ); ?>" style="font-size:11px;color:#6366f1;font-weight:600;display:inline-block;margin-top:6px;text-decoration:none;"><?php esc_html_e( 'Settings', 'cloudscale-devtools' ); ?> &rarr;</a>
            </div>
            <?php elseif ( $has_key ) : ?>
            <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:12px 14px;">
                <div style="font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;"><?php esc_html_e( 'Scheduled Scan', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:13px;font-weight:700;color:#92400e;">&#x26A0;&#xFE0F; <?php esc_html_e( 'Not scheduled', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:11px;color:#92400e;margin-top:2px;line-height:1.4;"><?php esc_html_e( 'Auto-run a scan weekly or monthly.', 'cloudscale-devtools' ); ?></div>
                <a href="<?php echo esc_url( $sec_url ); ?>" style="font-size:11px;color:#6366f1;font-weight:600;display:inline-block;margin-top:6px;text-decoration:none;"><?php esc_html_e( 'Schedule', 'cloudscale-devtools' ); ?> &rarr;</a>
            </div>
            <?php else : ?>
            <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:12px 14px;">
                <div style="font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;"><?php esc_html_e( 'Scheduled Scan', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:13px;font-weight:700;color:#92400e;">&#x26A0;&#xFE0F; <?php esc_html_e( 'No AI key', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:11px;color:#92400e;margin-top:2px;line-height:1.4;"><?php esc_html_e( 'Configure AI to enable scheduled scans.', 'cloudscale-devtools' ); ?></div>
                <a href="<?php echo esc_url( $sec_url ); ?>" style="font-size:11px;color:#6366f1;font-weight:600;display:inline-block;margin-top:6px;text-decoration:none;"><?php esc_html_e( 'Configure', 'cloudscale-devtools' ); ?> &rarr;</a>
            </div>
            <?php endif; ?>

            <!-- Card 7: AI Image Generator -->
            <?php
            $openai_key    = get_option( 'csdt_devtools_openai_key', '' );
            $thumbs_url    = admin_url( 'tools.php?page=' . self::TOOLS_SLUG . '&tab=thumbnails' );
            if ( $openai_key ) :
                $missing_images = (int) ( new WP_Query( [
                    'post_type'      => 'post',
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'meta_query'     => [ [ 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ] ],
                ] ) )->found_posts;
            ?>
            <div style="background:<?php echo $missing_images ? '#fffbeb' : '#f0fdf4'; ?>;border:1px solid <?php echo $missing_images ? '#fcd34d' : '#86efac'; ?>;border-radius:6px;padding:12px 14px;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;"><?php esc_html_e( 'AI Image Generator', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:13px;font-weight:700;color:<?php echo $missing_images ? '#92400e' : '#15803d'; ?>;">
                    <?php /* translators: %d is the number of posts missing featured images */
                    echo $missing_images
                        ? '&#x26A0;&#xFE0F; ' . sprintf( esc_html__( '%d posts need images', 'cloudscale-devtools' ), $missing_images )
                        : '&#x2705; ' . esc_html__( 'All posts have images', 'cloudscale-devtools' ); ?>
                </div>
                <div style="font-size:11px;color:#6b7280;margin-top:2px;line-height:1.4;"><?php esc_html_e( 'DALL-E 3 · OpenAI key configured', 'cloudscale-devtools' ); ?></div>
                <a href="<?php echo esc_url( $thumbs_url ); ?>" style="font-size:11px;color:#6366f1;font-weight:600;display:inline-block;margin-top:6px;text-decoration:none;"><?php esc_html_e( 'Generate Images', 'cloudscale-devtools' ); ?> &rarr;</a>
            </div>
            <?php else : ?>
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:12px 14px;">
                <div style="font-size:10px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;"><?php esc_html_e( 'AI Image Generator', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:13px;font-weight:700;color:#dc2626;">&#x274C; <?php esc_html_e( 'Not configured', 'cloudscale-devtools' ); ?></div>
                <div style="font-size:11px;color:#dc2626;margin-top:2px;line-height:1.4;"><?php esc_html_e( 'Add an OpenAI key to generate featured images with DALL-E 3.', 'cloudscale-devtools' ); ?></div>
                <a href="<?php echo esc_url( $thumbs_url ); ?>" style="font-size:11px;color:#6366f1;font-weight:600;display:inline-block;margin-top:6px;text-decoration:none;"><?php esc_html_e( 'Set up', 'cloudscale-devtools' ); ?> &rarr;</a>
            </div>
            <?php endif; ?>

        </div><!-- /status grid -->

        <!-- ── DB Prefix Rollback Banner ──────────────────────────────────── -->
        <?php
        if ( $rollback_info && ! empty( $rollback_info['old_prefix'] ) ) :
            $age_h = round( ( time() - ( $rollback_info['time'] ?? 0 ) ) / 3600, 1 );
        ?>
        <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:12px 16px;margin:12px 24px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;min-width:0;">
                <span style="font-weight:700;color:#dc2626;font-size:13px;">&#x21A9; DB Prefix Rollback Available</span>
                <span style="font-size:12px;color:#6b7280;margin-left:6px;"><?php echo esc_html( $age_h ); ?>h ago</span>
                <div style="font-size:12px;color:#374151;margin-top:2px;">
                    Tables were renamed from <code><?php echo esc_html( $rollback_info['old_prefix'] ); ?></code> &rarr; <code><?php echo esc_html( $rollback_info['new_prefix'] ); ?></code>
                    (<?php echo count( $rollback_info['tables'] ?? [] ); ?> tables). Rollback restores all tables and wp-config.php.
                </div>
            </div>
            <button type="button" id="csdt-prefix-rollback-persistent-btn" class="cs-btn-secondary cs-btn-sm" style="border-color:#ef4444;color:#dc2626;white-space:nowrap;">&#x21A9; Rollback Now</button>
            <span id="csdt-prefix-rollback-persistent-msg" style="display:none;font-size:12px;"></span>
        </div>
        <?php endif; ?>

        <!-- ── Quick Fixes ─────────────────────────────────────────────────── -->
        <div class="cs-section-header" style="background:linear-gradient(90deg,#78350f 0%,#b45309 100%);border-left:3px solid #fcd34d;margin-bottom:0;">
            <span>&#x26A1; <?php esc_html_e( 'Quick Fixes', 'cloudscale-devtools' ); ?></span>
            <span class="cs-header-hint"><?php esc_html_e( 'One-click hardening actions for common WordPress security settings', 'cloudscale-devtools' ); ?></span>
            <?php self::render_explain_btn( 'quick-fixes', 'Quick Fixes', [
                [ 'name' => 'How it works',       'rec' => 'Overview',    'html' => 'Each row shows a security hardening item and its current status (&#x2705; fixed / &#x26A0; needs attention). Click the action button to apply the fix in one click — no manual file editing or WP-CLI required. The panel refreshes automatically after each fix.' ],
                [ 'name' => 'WP-Cron Health',     'rec' => 'Important',   'html' => 'Checks that WordPress scheduled cleanup events are scheduled and firing on time. If cron is disabled or events are overdue, click <strong>Reschedule &amp; Run Now</strong> to fix immediately.' ],
                [ 'name' => 'Expired Transients', 'rec' => 'Maintenance', 'html' => 'Counts expired cache entries left in wp_options. WordPress auto-purges these daily via cron, but they can accumulate if cron has been unreliable. Click <strong>Delete Expired Transients</strong> to clear the backlog immediately.' ],
                [ 'name' => 'DB Prefix',          'rec' => 'Critical',    'html' => 'Renames all <code>wp_</code> tables to a unique prefix and updates wp-config.php automatically. Always create a database backup before running this fix.' ],
                [ 'name' => 'wp-config.php',      'rec' => 'Critical',    'html' => 'Sets <code>wp-config.php</code> permissions to <code>0400</code> (read-only). This prevents any PHP process — including a compromised plugin — from overwriting your database credentials or secret keys.' ],
            ] ); ?>
        </div>
        <div id="cs-quick-fixes-panel" style="padding:12px 0 4px;">
        <?php foreach ( CSDT_Site_Audit::get_quick_fixes() as $fix ) :
            $is_fixed = (bool) $fix['fixed'];
        ?>
            <div class="cs-quick-fix-row" data-fix-id="<?php echo esc_attr( $fix['id'] ); ?>" style="display:flex;align-items:flex-start;gap:12px;padding:10px 14px;margin-bottom:6px;background:<?php echo $is_fixed ? 'rgba(0,0,0,0.02)' : '#fff'; ?>;border-radius:6px;border:1px solid <?php echo $is_fixed ? 'rgba(0,0,0,0.07)' : 'rgba(0,0,0,0.12)'; ?>;">
                <div style="flex-shrink:0;font-size:16px;line-height:1.5;padding-top:1px;"><?php echo $is_fixed ? '<span style="color:#16a34a;">✓</span>' : '<span style="color:#d97706;">⚠</span>'; ?></div>
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                        <div style="font-size:13px;font-weight:600;color:<?php echo $is_fixed ? '#6b7280' : '#1d2327'; ?>;"><?php echo esc_html( $fix['title'] ); ?></div>
                        <?php if ( $is_fixed ) : ?>
                        <span style="flex-shrink:0;font-size:12px;color:#16a34a;font-weight:600;white-space:nowrap;">Fixed &#x2713;</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:12px;color:#50575e;margin-top:2px;"><?php echo esc_html( $fix['detail'] ); ?></div>
                    <?php if ( ! $is_fixed ) :
                        $risk        = $fix['risk']        ?? 'safe';
                        $confirm_msg = $fix['confirm_msg'] ?? '';
                        $btn_extra_style = $risk === 'moderate'    ? 'background:#d97706;border-color:#b45309;'
                                         : ( $risk === 'destructive' ? 'background:#dc2626;border-color:#b91c1c;' : '' );
                    ?>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;">
                    <?php if ( ! empty( $fix['fix_modal'] ) ) : ?>
                        <button type="button" class="cs-btn-primary cs-btn-sm"
                                style="<?php echo esc_attr( $btn_extra_style ); ?>"
                                data-cs-modal-open="<?php echo esc_attr( $fix['fix_modal'] ); ?>">
                            <?php echo esc_html( $fix['fix_label'] ); ?>
                        </button>
                    <?php else : ?>
                        <button type="button" class="cs-btn-primary cs-btn-sm cs-quick-fix-btn"
                                style="<?php echo esc_attr( $btn_extra_style ); ?>"
                                data-fix-id="<?php echo esc_attr( $fix['id'] ); ?>"
                                data-risk="<?php echo esc_attr( $risk ); ?>"
                                <?php if ( $confirm_msg ) : ?>data-confirm-msg="<?php echo esc_attr( $confirm_msg ); ?>"<?php endif; ?>>
                            <?php echo esc_html( $fix['fix_label'] ); ?>
                        </button>
                        <?php if ( ! empty( $fix['dismiss_label'] ) && ! empty( $fix['dismiss_id'] ) ) : ?>
                        <button type="button" class="cs-btn-secondary cs-btn-sm cs-quick-fix-btn"
                                data-fix-id="<?php echo esc_attr( $fix['dismiss_id'] ); ?>"
                                style="font-size:11px;">
                            <?php echo esc_html( $fix['dismiss_label'] ); ?>
                        </button>
                        <?php endif; ?>
                    <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>

        </div><!-- /cs-panel -->
        <?php
    }

    /* ==================================================================
       Optimizer tab — Plugin Stack Scanner + Update Risk Scorer
       ================================================================== */

    private static function render_optimizer_panel(): void {
        $has_key = CSDT_AI_Dispatcher::has_key();
        $security_url = admin_url( 'tools.php?page=' . self::TOOLS_SLUG . '&tab=security' );
        ?>
        <!-- ── Plugin Stack Scanner ──────────────────────────────────────── -->
        <div class="cs-panel" id="cs-panel-plugin-stack">
            <div class="cs-section-header" style="background:linear-gradient(90deg,#1a3a8f 0%,#1e6fd9 100%);border-left:3px solid #60a5fa;">
                <span>🔍 <?php esc_html_e( 'Plugin Stack Scanner', 'cloudscale-devtools' ); ?></span>
                <span class="cs-header-hint"><?php esc_html_e( 'Find plugins CloudScale already replaces — reduce bloat and attack surface', 'cloudscale-devtools' ); ?></span>
                <?php self::render_explain_btn( 'plugin-stack', 'Plugin Stack Scanner', [
                    [ 'name' => 'What it scans',      'rec' => 'Overview',   'html' => 'Compares your active plugins against a curated list of functionality that CloudScale already provides. Plugins flagged as redundant can usually be deactivated — reducing page load time, update surface area, and conflict risk.' ],
                    [ 'name' => 'Inactive plugins',   'rec' => 'Important',  'html' => 'Inactive plugins still execute their autoloaded code on every page load and are still scanned for vulnerabilities. Deactivate <em>and delete</em> plugins you are not actively using — do not just deactivate them.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                    <p style="color:#4b5563;margin:0 0 6px;line-height:1.65;font-size:.95em;">
                        <?php esc_html_e( 'CloudScale replaces entire categories of WordPress plugins — security scanners, 2FA plugins, SMTP mailers, code block plugins, SQL tools, and log viewers. Scan to find out which of your installed plugins you can safely remove.', 'cloudscale-devtools' ); ?>
                    </p>
                    <p style="color:#9ca3af;margin:0 0 18px;font-size:.88em;">
                        <?php esc_html_e( 'Fewer plugins = smaller attack surface, faster page loads, fewer update conflicts.', 'cloudscale-devtools' ); ?>
                    </p>
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                        <button id="csdt-optimizer-scan-btn" class="cs-btn-primary">
                            🔍 <?php esc_html_e( 'Scan My Plugin Stack', 'cloudscale-devtools' ); ?>
                        </button>
                        <span id="csdt-optimizer-scanning" style="display:none;color:#6b7280;font-size:13px;">
                            ⏳ <?php esc_html_e( 'Scanning installed plugins...', 'cloudscale-devtools' ); ?>
                        </span>
                    </div>
                    <div id="csdt-optimizer-results" style="display:none;margin-top:20px;"></div>
            </div>
        </div>

        <!-- ── Update Risk Scorer ────────────────────────────────────────── -->
        <div class="cs-panel" id="cs-panel-update-risk">
            <div class="cs-section-header cs-section-header-teal">
                <span>🔄 <?php esc_html_e( 'Update Risk Scorer', 'cloudscale-devtools' ); ?></span>
                <span class="cs-header-hint"><?php esc_html_e( 'AI-rates pending plugin updates: Patch / Minor / Breaking before you apply them', 'cloudscale-devtools' ); ?></span>
                <?php self::render_explain_btn( 'update-risk', 'Update Risk Scorer', [
                    [ 'name' => 'Risk ratings',   'rec' => 'Recommended', 'html' => '🟢 <strong>Patch</strong> — safe to apply immediately (security fix or bug fix with no API changes). 🟡 <strong>Minor</strong> — new features, low risk but review changelog. 🔴 <strong>Breaking</strong> — major version or significant API changes, test on staging first.' ],
                    [ 'name' => 'How it works',   'rec' => 'Overview',    'html' => 'Reads the plugin changelog from WordPress.org and sends it to the configured AI provider to assess change type. Requires an AI API key (configure on the Home tab).' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <div>
                    <p style="color:#4b5563;margin:0 0 6px;line-height:1.65;font-size:.95em;">
                        <?php esc_html_e( 'Before applying plugin updates, get an AI risk rating for each one: Patch (safe now), Minor (new features), or Breaking (review first). Prevents update-caused site breakage.', 'cloudscale-devtools' ); ?>
                    </p>
                    <p style="color:#9ca3af;margin:0 0 16px;font-size:.88em;">
                        <?php esc_html_e( 'Reads the plugin changelog from WordPress.org and asks the AI to assess whether this is a security patch, a feature release, or a potentially breaking change.', 'cloudscale-devtools' ); ?>
                    </p>
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                        <button id="csdt-update-risk-scan-btn" class="cs-btn-primary">
                            🔍 <?php esc_html_e( 'Scan for Available Updates', 'cloudscale-devtools' ); ?>
                        </button>
                        <span id="csdt-update-risk-scanning" style="display:none;color:#6b7280;font-size:13px;">
                            ⏳ <?php esc_html_e( 'Loading...', 'cloudscale-devtools' ); ?>
                        </span>
                    </div>
                    <div id="csdt-update-risk-results" style="display:none;margin-top:20px;"></div>
                </div>
            </div>
        </div>

        <!-- ── Database Intelligence Engine ─────────────────────────────── -->
        <div class="cs-panel">
            <div class="cs-section-header cs-section-header-orange">
                <span>🗄️ <?php esc_html_e( 'Database Intelligence Engine', 'cloudscale-devtools' ); ?></span>
                <span class="cs-header-hint"><?php esc_html_e( 'Scan for DB bloat — transients, revisions, orphaned meta — then one-click fix', 'cloudscale-devtools' ); ?></span>
                <?php self::render_explain_btn( 'db-intelligence', 'Database Intelligence Engine', [
                    [ 'name' => 'What it finds',  'rec' => 'Recommended', 'html' => 'Oversized autoload cache (slows every page load), expired transients (uncleaned remnants from plugins), post revisions (can accumulate thousands of rows), and orphaned post/user metadata left behind by deleted plugins.' ],
                    [ 'name' => 'One-click fixes','rec' => 'Recommended', 'html' => 'Each issue found includes a Fix It button that runs the cleanup directly in the database. The operation is logged. Take a backup first if you want a safety net — CloudScale Backup &amp; Restore can do this in one click.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <div>
                    <p style="color:#4b5563;margin:0 0 6px;line-height:1.65;font-size:.95em;">
                        <?php esc_html_e( 'Scans your WordPress database for hidden bloat — oversized autoload cache, expired transients, post revisions, and orphaned metadata — then gives you one-click cleanup actions for each issue found.', 'cloudscale-devtools' ); ?>
                    </p>
                    <p style="color:#9ca3af;margin:0 0 16px;font-size:.88em;">
                        <?php esc_html_e( 'All fixes run directly in the database. Take a backup first if you want a safety net.', 'cloudscale-devtools' ); ?>
                    </p>
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                        <button id="csdt-db-intelligence-scan-btn" class="cs-btn-primary">
                            🔍 <?php esc_html_e( 'Analyse Database', 'cloudscale-devtools' ); ?>
                        </button>
                        <span id="csdt-db-intelligence-scanning" style="display:none;color:#6b7280;font-size:13px;">
                            ⏳ <?php esc_html_e( 'Scanning…', 'cloudscale-devtools' ); ?>
                        </span>
                    </div>
                    <div id="csdt-db-intelligence-results" style="display:none;margin-top:20px;"></div>
                </div>
            </div>
        </div>

        <!-- ── Orphaned Table Cleanup ────────────────────────────────────── -->
        <div class="cs-panel">
            <div class="cs-section-header cs-section-header-red">
                <span>🗑️ <?php esc_html_e( 'Orphaned Table Cleanup', 'cloudscale-devtools' ); ?></span>
                <span class="cs-header-hint"><?php esc_html_e( 'Find and safely remove database tables left behind by deleted plugins', 'cloudscale-devtools' ); ?></span>
                <?php self::render_explain_btn( 'orphaned-tables', 'Orphaned Table Cleanup', [
                    [ 'name' => 'What gets flagged', 'rec' => 'Overview',   'html' => 'Non-core database tables that have no active plugin claiming them. WordPress core tables are always protected. Tables are identified by comparing your database against a list of known core tables.' ],
                    [ 'name' => 'Recycle Bin',       'rec' => 'Important',  'html' => 'Tables are never deleted immediately. They are renamed with a <code>_trash_</code> prefix (moved to the Recycle Bin) first. You can restore them to their original name or permanently delete from the Recycle Bin.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <div>
                    <p style="color:#4b5563;margin:0 0 6px;line-height:1.65;font-size:.95em;">
                        <?php esc_html_e( 'Scans for database tables left behind by removed plugins. WordPress core tables are always protected — only non-core tables appear here.', 'cloudscale-devtools' ); ?>
                    </p>
                    <p style="color:#9ca3af;margin:0 0 16px;font-size:.88em;">
                        <?php esc_html_e( 'Tables are moved to the Recycle Bin first (renamed with a _trash_ prefix). You can then restore or permanently delete them.', 'cloudscale-devtools' ); ?>
                    </p>
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
                        <button id="csdt-orphan-scan-btn" type="button" class="cs-btn-primary">
                            🔍 <?php esc_html_e( 'Scan for Orphaned Tables', 'cloudscale-devtools' ); ?>
                        </button>
                    </div>
                    <div id="csdt-orphan-results" style="margin-top:8px;"></div>

                    <!-- Recycle Bin -->
                    <div style="margin-top:28px;border-top:1px solid #fde68a;padding-top:20px;">
                        <div id="csdt-trash-toggle" style="display:flex;align-items:center;gap:10px;margin-bottom:8px;cursor:pointer;user-select:none;">
                            <span id="csdt-trash-chevron" style="font-size:11px;color:#6b7280;min-width:10px;">▶</span>
                            <h3 style="margin:0;font-size:1rem;font-weight:600;color:#374151;">♻️ <?php esc_html_e( 'Recycle Bin', 'cloudscale-devtools' ); ?></h3>
                            <button id="csdt-trash-refresh-btn" type="button" style="font-size:11px;background:none;border:1px solid #d1d5db;border-radius:4px;padding:2px 8px;cursor:pointer;color:#6b7280;">🔄 <?php esc_html_e( 'Refresh', 'cloudscale-devtools' ); ?></button>
                        </div>
                        <div id="csdt-trash-body" style="display:none;">
                            <p style="color:#9ca3af;font-size:.88em;margin:0 0 12px;"><?php esc_html_e( 'Archived tables can be restored to their original names or permanently deleted.', 'cloudscale-devtools' ); ?></p>
                            <div id="csdt-trash-results"></div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php
    }

        private static function render_security_panel(): void {
        $ai_cfg         = CSDT_AI_Dispatcher::get_config();
        $ai_provider    = $ai_cfg['provider'];
        $sched_enabled  = get_option( 'csdt_scan_schedule_enabled', '0' ) === '1';
        $sched_freq     = get_option( 'csdt_scan_schedule_freq',    'weekly' );
        $sched_type     = get_option( 'csdt_scan_schedule_type',    'deep' );
        $notify_enabled  = get_option( 'csdt_scan_schedule_email',   '1' ) === '1';
        $notify_email    = get_option( 'csdt_notify_email', '' );
        $notify_ntfy_url = get_option( 'csdt_scan_schedule_ntfy_url', '' );
        $notify_ntfy_tok = get_option( 'csdt_scan_schedule_ntfy_token', '' );
        $next_run       = wp_next_scheduled( 'csdt_scheduled_scan' );
        ?>
        <?php $site_audit_url = admin_url( 'tools.php?page=' . self::TOOLS_SLUG . '&tab=site-audit' ); ?>
        <div class="cs-tab-intro" style="margin-bottom:20px;">
                    <p><?php echo wp_kses( __( 'The <strong>AI Cyber Audit</strong> uses frontier AI &#8212; Anthropic Claude or Google Gemini &#8212; to analyse your WordPress installation and produce a prioritised, scored security report in under 60 seconds. Configure your provider and API key in the <strong>AI Settings</strong> panel below, then run a scan from the <strong>AI Cyber Audit</strong> panel. A free Gemini tier is available with no credit card required.', 'cloudscale-devtools' ), [ 'strong' => [] ] ); ?></p>

                <!-- AI Security Scan vs Site Audit comparison -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px;font-size:12px;">
                    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:10px 14px;">
                        <div style="font-weight:700;color:#15803d;margin-bottom:4px;">🛡️ AI Security Scan — this tab</div>
                        <div style="color:#374151;line-height:1.5;"><?php esc_html_e( 'Security misconfigurations, exposed endpoints, headers, brute-force exposure. Finds issues attackers exploit.', 'cloudscale-devtools' ); ?></div>
                    </div>
                    <div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:6px;padding:10px 14px;">
                        <div style="font-weight:700;color:#1d4ed8;margin-bottom:4px;">🔍 Site Audit</div>
                        <div style="color:#374151;line-height:1.5;"><?php esc_html_e( 'Content quality, SEO, database health, plugin status. Finds issues affecting visitors and search rankings.', 'cloudscale-devtools' ); ?></div>
                        <a href="<?php echo esc_url( $site_audit_url ); ?>" style="display:inline-block;margin-top:6px;font-size:11px;color:#6366f1;font-weight:600;"><?php esc_html_e( 'Go to Site Audit →', 'cloudscale-devtools' ); ?></a>
                    </div>
                </div>

                <!-- ── Threat Monitor ──────────────────────────── -->
                <?php
                $tm_enabled       = get_option( 'csdt_threat_monitor_enabled',        '1' ) === '1';
                $tm_file_enabled  = get_option( 'csdt_threat_file_integrity_enabled', '1' ) === '1';
                $tm_admin_enabled = get_option( 'csdt_threat_new_admin_enabled',      '1' ) === '1';
                $tm_probe_enabled = get_option( 'csdt_threat_probe_enabled',          '1' ) === '1';
                $tm_threshold     = get_option( 'csdt_threat_probe_threshold',        '25' );
                $tm_last_file     = get_option( 'csdt_threat_last_file_alert',        null );
                $tm_last_admin    = get_option( 'csdt_threat_last_admin_alert',       null );
                $tm_last_probe    = get_option( 'csdt_threat_last_probe_alert',       null );
                $tm_baseline_ver  = get_option( 'csdt_file_integrity_wp_ver',         '' );
                $tm_baseline      = get_option( 'csdt_file_integrity_baseline',       [] );
                ?>
                <div class="cs-panel" id="cs-panel-threat-monitor">
                    <div class="cs-section-header" style="background:linear-gradient(90deg,#7f1d1d 0%,#b91c1c 100%);border-left:3px solid #f87171;">
                        <span>🔎 <?php esc_html_e( 'Threat Monitor', 'cloudscale-devtools' ); ?></span>
                        <span class="cs-header-hint"><?php esc_html_e( 'File integrity · New admin alert · Probe detection — alerts once per incident, not per event', 'cloudscale-devtools' ); ?></span>
                        <?php self::render_explain_btn( 'threat-monitor', 'Threat Monitor', [
                            [ 'name' => 'File Integrity',   'rec' => 'Critical', 'html' => 'Every 5 minutes, scans <code>wp-includes/*.php</code> and <code>wp-admin/*.php</code> and compares modification times against a stored baseline. Any unexpected change triggers an immediate email and push alert.<br><br><strong>Smart baseline:</strong> When WordPress is updated, the baseline is rebuilt silently — core files legitimately change during updates. The same mtime is never alerted twice. Click <strong>Reset File Baseline</strong> after a manual core edit to clear outstanding alerts.' ],
                            [ 'name' => 'New Admin Alert', 'rec' => 'Critical', 'html' => 'Hooks into <code>user_register</code> and <code>set_user_role</code>. The instant any account is created as — or promoted to — administrator, an alert fires with the username, email, and creation method (Admin UI / REST API / WP-CLI).<br><br>Accounts matching test/automation patterns (playwright, helpdocs, e2e_) are flagged as <code>[TEST]</code> and do not trigger false positives during CI runs.' ],
                            [ 'name' => 'Probe Detection', 'rec' => 'High',     'html' => 'Reads only new bytes appended to the access log since the last check. Counts hits to sensitive paths: <code>wp-login.php</code>, <code>xmlrpc.php</code>, <code>wp-config.php</code>, <code>.env</code>, <code>.git/</code>, <code>.sql</code>, <code>.bak</code>, and shell-injection patterns (<code>eval(</code>, <code>base64_</code>, <code>cmd=</code>).<br><br>Alerts only when count exceeds threshold (default: 25/5 min) AND at most once per hour — no alert floods.' ],
                            [ 'name' => 'Alert channels',  'rec' => 'Setup',    'html' => 'Alerts fire via <strong>email</strong> (WordPress admin address) and <strong>ntfy.sh</strong> if a topic URL is set under Security Scan → Scheduled Scans. The Threat Monitor shares the same notification infrastructure as the SSH Monitor and PHP Error Alerting — one place to configure, all monitors benefit.' ],
                        ] ); ?>
                    </div>
                    <div class="cs-panel-body">

                        <div class="cs-field-row">
                            <div class="cs-field">
                                <label class="cs-label">
                                    <input type="checkbox" id="csdt-tm-enabled" <?php checked( $tm_enabled ); ?>>
                                    <?php esc_html_e( 'Enable Threat Monitor', 'cloudscale-devtools' ); ?>
                                </label>
                                <span class="cs-hint"><?php esc_html_e( 'Master switch. Runs checks every 5 minutes. Alerts via email and ntfy.sh.', 'cloudscale-devtools' ); ?></span>
                            </div>
                        </div>

                        <div id="csdt-tm-options" style="<?php echo $tm_enabled ? '' : 'opacity:.5;pointer-events:none;'; ?>">
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px;">

                                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;">
                                    <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:13px;font-weight:600;color:#1e293b;">
                                        <input type="checkbox" id="csdt-tm-file" <?php checked( $tm_file_enabled ); ?> style="margin-top:2px;flex-shrink:0;">
                                        🗂️ <?php esc_html_e( 'File Integrity', 'cloudscale-devtools' ); ?>
                                    </label>
                                    <div style="font-size:12px;color:#64748b;margin-top:6px;line-height:1.5;"><?php esc_html_e( 'Alerts if wp-includes/ or wp-admin/ core files are modified. Ignores WP core updates automatically. Alerts once per unique change.', 'cloudscale-devtools' ); ?></div>
                                    <?php if ( $tm_last_file ) : ?>
                                    <div style="margin-top:8px;font-size:11px;color:#dc2626;font-weight:600;">
                                        🚨 <?php echo esc_html( human_time_diff( $tm_last_file['ts'] ) . ' ago' ); ?> —
                                        <?php echo (int) $tm_last_file['count']; ?> file<?php echo $tm_last_file['count'] === 1 ? '' : 's'; ?>
                                    </div>
                                    <?php elseif ( $tm_baseline ) : ?>
                                    <div style="margin-top:8px;font-size:11px;color:#16a34a;">✓ <?php /* translators: 1: WordPress version string, 2: number of files in baseline */ printf( esc_html__( 'Baseline: WP %s (%d files)', 'cloudscale-devtools' ), esc_html( $tm_baseline_ver ), count( $tm_baseline ) ); ?></div>
                                    <?php else : ?>
                                    <div style="margin-top:8px;font-size:11px;color:#64748b;"><?php esc_html_e( 'Baseline will be created on first run.', 'cloudscale-devtools' ); ?></div>
                                    <?php endif; ?>
                                </div>

                                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;">
                                    <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:13px;font-weight:600;color:#1e293b;">
                                        <input type="checkbox" id="csdt-tm-admin" <?php checked( $tm_admin_enabled ); ?> style="margin-top:2px;flex-shrink:0;">
                                        👤 <?php esc_html_e( 'New Admin Alert', 'cloudscale-devtools' ); ?>
                                    </label>
                                    <div style="font-size:12px;color:#64748b;margin-top:6px;line-height:1.5;"><?php esc_html_e( 'Instant alert when a new administrator account is created or a user is promoted to admin. Fires once per user.', 'cloudscale-devtools' ); ?></div>
                                    <?php if ( $tm_last_admin ) : ?>
                                    <div style="margin-top:8px;font-size:11px;color:#dc2626;font-weight:600;">
                                        🚨 <?php echo esc_html( human_time_diff( $tm_last_admin['ts'] ) . ' ago — ' . $tm_last_admin['login'] ); ?>
                                    </div>
                                    <?php else : ?>
                                    <div style="margin-top:8px;font-size:11px;color:#16a34a;">✓ <?php esc_html_e( 'No new admin accounts detected.', 'cloudscale-devtools' ); ?></div>
                                    <?php endif; ?>
                                </div>

                                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;">
                                    <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:13px;font-weight:600;color:#1e293b;">
                                        <input type="checkbox" id="csdt-tm-probe" <?php checked( $tm_probe_enabled ); ?> style="margin-top:2px;flex-shrink:0;">
                                        🔍 <?php esc_html_e( 'Probe Detection', 'cloudscale-devtools' ); ?>
                                    </label>
                                    <div style="font-size:12px;color:#64748b;margin-top:6px;line-height:1.5;"><?php esc_html_e( 'Counts requests to sensitive endpoints (wp-login, xmlrpc, .env, .git) in the access log. Throttled to one alert per hour.', 'cloudscale-devtools' ); ?></div>
                                    <div style="margin-top:8px;display:flex;align-items:center;gap:6px;">
                                        <label style="font-size:11px;color:#64748b;"><?php esc_html_e( 'Threshold:', 'cloudscale-devtools' ); ?></label>
                                        <input type="number" id="csdt-tm-probe-threshold" min="5" max="500"
                                               value="<?php echo esc_attr( $tm_threshold ); ?>"
                                               style="width:60px;padding:2px 6px;font-size:12px;border:1px solid #d1d5db;border-radius:4px;">
                                        <span style="font-size:11px;color:#64748b;"><?php esc_html_e( 'requests / 5 min', 'cloudscale-devtools' ); ?></span>
                                    </div>
                                    <?php if ( $tm_last_probe ) : ?>
                                    <div style="margin-top:6px;font-size:11px;color:#d97706;font-weight:600;">
                                        ⚠ <?php echo esc_html( human_time_diff( $tm_last_probe['ts'] ) . ' ago — ' . $tm_last_probe['count'] . ' probes' ); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>

                            </div>
                        </div>

                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <button type="button" class="cs-btn-primary" id="csdt-tm-save">💾 <?php esc_html_e( 'Save Settings', 'cloudscale-devtools' ); ?></button>
                            <span class="cs-settings-saved" id="csdt-tm-saved">✓ <?php esc_html_e( 'Saved', 'cloudscale-devtools' ); ?></span>
                            <?php if ( $tm_baseline ) : ?>
                            <button type="button" class="cs-btn-secondary" id="csdt-tm-reset" style="font-size:12px;margin-left:auto;">↺ <?php esc_html_e( 'Reset File Baseline', 'cloudscale-devtools' ); ?></button>
                            <span id="csdt-tm-reset-msg" style="font-size:12px;color:#16a34a;display:none;"></span>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>

                <!-- ── AI Settings ────────────────────────────────────────────── -->
                <div class="cs-panel" id="cs-panel-ai-settings">
                <div class="cs-section-header cs-section-header-red">
                    <span>&#x1F916; <?php esc_html_e( 'AI Settings', 'cloudscale-devtools' ); ?></span>
                    <span class="cs-header-hint"><?php esc_html_e( 'Select a provider and paste your API key to enable AI-powered security scans', 'cloudscale-devtools' ); ?></span>
                    <?php self::render_explain_btn( 'cyber-audit', 'AI Cyber Audit', [
                        [ 'name' => 'AI Providers',    'rec' => 'Info',        'html' => '<p>Two providers supported. Your API key is stored only in <code>wp_options</code> and sent exclusively to that provider\'s API endpoint — never to any third party.</p><p><strong>Anthropic Claude</strong> — best results for security analysis.<br>Get a key: <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com/settings/keys</a><br>Models: <code>claude-sonnet-4-6</code> (fast, recommended) · <code>claude-opus-4-7</code> (most capable)</p><p><strong>Google Gemini</strong> — free tier available.<br>Get a key: <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">aistudio.google.com/app/apikey</a><br>Models: <code>gemini-2.0-flash</code> (fast, free tier) · <code>gemini-2.5-pro</code> (most capable)</p>' ],
                        [ 'name' => 'Deep Dive Scan',  'rec' => 'Recommended', 'html' => 'Audits WordPress core settings, plugins, themes, user accounts, file permissions, and wp-config.php. Adds live HTTP probes (SSL/TLS strength, login page exposure, XML-RPC, REST user enumeration, directory listing, server headers), DNS email security checks (SPF, DMARC, DKIM), PHP end-of-life detection, and AI-powered static triage of plugin PHP files for suspicious code patterns.' ],
                        [ 'name' => 'Scheduled Scans', 'rec' => 'Recommended', 'html' => 'Run automatically on a daily or weekly schedule. Results are stored in Scan History. Configure email and ntfy.sh alerts under Scheduled Scans → Notifications to receive the AI summary the moment each scan completes.' ],
                    ] ); ?>
                </div>
                <div class="cs-panel-body">
                <div class="cs-sec-settings">

                    <div class="cs-sec-row">
                        <span class="cs-sec-label"><?php esc_html_e( 'AI Provider:', 'cloudscale-devtools' ); ?></span>
                        <div class="cs-sec-control">
                            <select id="cs-sec-provider" class="cs-sec-select">
                                <option value="anthropic"><?php esc_html_e( 'Anthropic Claude', 'cloudscale-devtools' ); ?></option>
                                <option value="gemini"><?php esc_html_e( 'Google Gemini', 'cloudscale-devtools' ); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="cs-sec-row" id="cs-row-anthropic-key">
                        <span class="cs-sec-label"><?php esc_html_e( 'API Key:', 'cloudscale-devtools' ); ?></span>
                        <div class="cs-sec-control">
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                <input type="password" id="cs-sec-api-key" class="cs-text-input cs-sec-key-input"
                                       autocomplete="off" placeholder="sk-ant-api03-…">
                                <button type="button" class="cs-btn-secondary" id="cs-sec-test-key">
                                    <?php esc_html_e( 'Test Key', 'cloudscale-devtools' ); ?>
                                </button>
                                <span id="cs-sec-key-status" class="cs-sec-key-status"></span>
                            </div>
                            <span class="cs-hint"><?php echo wp_kses(
                                __( 'Get your key at <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a>. Stored in wp_options.', 'cloudscale-devtools' ),
                                [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
                            ); ?></span>
                        </div>
                    </div>

                    <div class="cs-sec-row" id="cs-row-gemini-key" style="display:none">
                        <span class="cs-sec-label"><?php esc_html_e( 'API Key:', 'cloudscale-devtools' ); ?></span>
                        <div class="cs-sec-control">
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                <input type="password" id="cs-sec-gemini-key" class="cs-text-input cs-sec-key-input"
                                       autocomplete="off" placeholder="AIza…">
                                <button type="button" class="cs-btn-secondary" id="cs-sec-test-gemini-key">
                                    <?php esc_html_e( 'Test Key', 'cloudscale-devtools' ); ?>
                                </button>
                                <span id="cs-sec-gemini-key-status" class="cs-sec-key-status"></span>
                            </div>
                            <span class="cs-hint"><?php echo wp_kses(
                                __( 'Get your key at <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">aistudio.google.com</a>. Stored in wp_options.', 'cloudscale-devtools' ),
                                [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
                            ); ?></span>
                        </div>
                    </div>

                    <div class="cs-sec-row">
                        <span class="cs-sec-label"><?php esc_html_e( 'AI model:', 'cloudscale-devtools' ); ?></span>
                        <div class="cs-sec-control">
                            <select id="cs-sec-deep-model" class="cs-sec-select">
                                <option value="_auto_deep">&#x2728; Auto</option>
                            </select>
                        </div>
                    </div>

                    <div class="cs-sec-row cs-sec-row-prompt">
                        <span class="cs-sec-label"><?php esc_html_e( 'System prompt:', 'cloudscale-devtools' ); ?></span>
                        <div class="cs-sec-control">
                            <textarea id="cs-sec-prompt" class="cs-sec-prompt-area" rows="10"></textarea>
                            <div style="display:flex;align-items:center;gap:6px;margin-top:8px;flex-wrap:wrap">
                                <button type="button" class="cs-btn-secondary cs-btn-sm" id="cs-sec-copy-prompt">&#x2398; <?php esc_html_e( 'Copy', 'cloudscale-devtools' ); ?></button>
                                <button type="button" class="cs-btn-secondary cs-btn-sm" id="cs-sec-reset-prompt"><?php esc_html_e( 'Reset to default', 'cloudscale-devtools' ); ?></button>
                                <div style="flex:1"></div>
                                <button type="button" class="cs-btn-primary" id="cs-sec-save">&#x1F4BE; <?php esc_html_e( 'Save Settings', 'cloudscale-devtools' ); ?></button>
                                <span class="cs-settings-saved" id="cs-sec-saved">&#x2713; <?php esc_html_e( 'Saved', 'cloudscale-devtools' ); ?></span>
                            </div>
                        </div>
                    </div>

                </div>

                <hr class="cs-sec-divider">

                <!-- Notifications -->
                <div class="cs-sec-settings" style="margin-top:0;padding-top:0;">
                    <div class="cs-sec-row">
                        <span class="cs-sec-label" style="font-weight:600;"><?php esc_html_e( 'Notifications:', 'cloudscale-devtools' ); ?></span>
                        <div class="cs-sec-control">
                            <span class="cs-hint"><?php esc_html_e( 'Global alert settings for scheduled scans, threat monitor, and uptime monitor.', 'cloudscale-devtools' ); ?></span>
                        </div>
                    </div>
                    <div class="cs-sec-row">
                        <span class="cs-sec-label"><?php esc_html_e( 'Send email alerts:', 'cloudscale-devtools' ); ?></span>
                        <div class="cs-sec-control">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="checkbox" id="cs-notify-email-enabled" <?php checked( $notify_enabled ); ?>>
                                <span><?php /* translators: %s is the admin email address */ printf( esc_html__( 'Send to %s', 'cloudscale-devtools' ), '<strong>' . esc_html( get_option( 'admin_email' ) ) . '</strong>' ); ?></span>
                            </label>
                        </div>
                    </div>
                    <div class="cs-sec-row">
                        <span class="cs-sec-label"><?php esc_html_e( 'Email override:', 'cloudscale-devtools' ); ?></span>
                        <div class="cs-sec-control">
                            <input type="email" id="cs-notify-email" class="cs-text-input"
                                   placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"
                                   value="<?php echo esc_attr( $notify_email ); ?>"
                                   style="max-width:280px;">
                            <span class="cs-hint"><?php esc_html_e( 'Leave blank to use the admin email above.', 'cloudscale-devtools' ); ?></span>
                        </div>
                    </div>
                    <div class="cs-sec-row">
                        <span class="cs-sec-label"><?php esc_html_e( 'ntfy.sh topic:', 'cloudscale-devtools' ); ?></span>
                        <div class="cs-sec-control">
                            <input type="text" id="cs-notify-ntfy-url" class="cs-text-input"
                                   placeholder="https://ntfy.sh/your-topic"
                                   value="<?php echo esc_attr( $notify_ntfy_url ); ?>"
                                   style="max-width:320px;">
                            <span class="cs-hint"><?php echo wp_kses( __( 'Optional push notification via <a href="https://ntfy.sh" target="_blank" rel="noopener">ntfy.sh</a>.', 'cloudscale-devtools' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ); ?></span>
                        </div>
                    </div>
                    <div class="cs-sec-row">
                        <span class="cs-sec-label"><?php esc_html_e( 'ntfy auth token:', 'cloudscale-devtools' ); ?></span>
                        <div class="cs-sec-control">
                            <input type="password" id="cs-notify-ntfy-token" class="cs-text-input"
                                   autocomplete="off" placeholder="<?php echo $notify_ntfy_tok ? '&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;' : esc_attr__( 'Optional — for protected topics', 'cloudscale-devtools' ); ?>"
                                   style="max-width:320px;">
                        </div>
                    </div>
                    <div class="cs-sec-row">
                        <span class="cs-sec-label"></span>
                        <div class="cs-sec-control">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <button type="button" class="cs-btn-primary" id="cs-notify-save">&#x1F4BE; <?php esc_html_e( 'Save Notification Settings', 'cloudscale-devtools' ); ?></button>
                                <span class="cs-settings-saved" id="cs-notify-saved">&#x2713; <?php esc_html_e( 'Saved', 'cloudscale-devtools' ); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="cs-sec-divider">

                <!-- Scheduled scan -->
                <div class="cs-sec-settings" style="margin-top:0;padding-top:0;">
                    <div class="cs-sec-row">
                        <span class="cs-sec-label"><?php esc_html_e( 'Scheduled Scan:', 'cloudscale-devtools' ); ?></span>
                        <div class="cs-sec-control">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="checkbox" id="cs-sched-enabled" <?php checked( $sched_enabled ); ?>>
                                <span><?php esc_html_e( 'Run automatically on a schedule', 'cloudscale-devtools' ); ?></span>
                            </label>
                            <?php if ( $next_run ) : ?>
                            <span class="cs-hint"><?php /* translators: %s is a formatted date/time string e.g. "Mon 5 May 2025, 3:00pm" */ printf( esc_html__( 'Next run: %s', 'cloudscale-devtools' ), esc_html( wp_date( 'D j M Y, g:ia', $next_run ) ) ); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div id="cs-sched-options" <?php echo $sched_enabled ? 'style="display:flex;flex-direction:column;gap:16px;"' : 'style="display:none"'; ?>>
                        <div class="cs-sec-row">
                            <span class="cs-sec-label"><?php esc_html_e( 'Frequency:', 'cloudscale-devtools' ); ?></span>
                            <div class="cs-sec-control">
                                <select id="cs-sched-freq" class="cs-sec-select" style="width:auto;max-width:180px;">
                                    <option value="weekly"  <?php selected( $sched_freq, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'cloudscale-devtools' ); ?></option>
                                    <option value="monthly" <?php selected( $sched_freq, 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'cloudscale-devtools' ); ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="cs-sec-row">
                            <span class="cs-sec-label"></span>
                            <div class="cs-sec-control">
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <button type="button" class="cs-btn-primary" id="cs-sched-save">&#x1F4BE; <?php esc_html_e( 'Save Schedule', 'cloudscale-devtools' ); ?></button>
                                    <span class="cs-settings-saved" id="cs-sched-saved">&#x2713; <?php esc_html_e( 'Saved', 'cloudscale-devtools' ); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                </div><!-- /AI settings -->
                </div>

                <div class="cs-panel" id="cs-panel-ai-cyber-audit">
                <div class="cs-section-header" style="background:linear-gradient(90deg,#022c22 0%,#065f46 100%);border-left:3px solid #34d399;">
                    <span>🕵️ <?php esc_html_e( 'AI Cyber Audit', 'cloudscale-devtools' ); ?></span>
                    <span class="cs-header-hint"><?php esc_html_e( 'AI-powered deep dive security scanning — internal config, plugin code analysis, and external exposure checks', 'cloudscale-devtools' ); ?></span>
                </div>
                <div class="cs-panel-body">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;padding:10px 14px;background:#f0fdf4;border-radius:8px;border:1px solid #bbf7d0;">
                    <label for="cs-audit-target-url" style="font-size:12px;font-weight:600;color:#14532d;white-space:nowrap;">
                        🎯 <?php esc_html_e( 'Target site:', 'cloudscale-devtools' ); ?>
                    </label>
                    <input type="url" id="cs-audit-target-url"
                           value="<?php echo esc_attr( home_url( '/' ) ); ?>"
                           placeholder="https://example.com/"
                           style="flex:1;min-width:200px;max-width:420px;font-size:13px;padding:5px 10px;border:1px solid #86efac;border-radius:6px;color:#0f172a;background:#fff;" />
                    <span id="cs-audit-url-status" style="font-size:12px;font-weight:600;color:#15803d;">&#x2714; <?php esc_html_e( 'This site', 'cloudscale-devtools' ); ?></span>
                </div>
                <div class="cs-scan-col-header">
                    <?php self::render_explain_btn( 'deep-scan', 'AI Deep Dive Cyber Audit', [
                        [ 'name' => 'What it checks',     'rec' => 'Overview',       'html' => 'Checks your WordPress core settings, active plugins and themes, user accounts, file permissions, and wp-config.php, then adds <strong>live HTTP probes</strong> of your site: SSL/TLS validity and strength, login page exposure, XML-RPC state, REST API user enumeration, author enumeration, directory listing, and server version headers.' ],
                        [ 'name' => 'Plugin code triage', 'rec' => 'AI static scan',  'html' => 'The AI pre-screens plugin PHP files for suspicious patterns (eval, base64_decode, remote code execution sinks) and classifies each finding as <strong>Confirmed / False Positive / Needs Context</strong> before the main analysis. This reduces noise and focuses the report on real risks.' ],
                        [ 'name' => 'DNS checks',         'rec' => 'Email security',  'html' => 'SPF, DMARC, and DKIM records are checked only when your domain has an MX record. If you have no email configured, these checks are skipped — no false positives.' ],
                        [ 'name' => 'Speed',              'rec' => '30–90s',          'html' => 'The deep dive makes outbound HTTP and DNS requests so duration depends on your network. Typical completion is 30–90 seconds. The browser connection is closed immediately via <code>fastcgi_finish_request()</code>; a progress bar polls every 3 seconds.' ],
                    ] ); ?>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                    <button id="cs-deep-scan-btn" class="cs-btn-primary cs-btn-deep" disabled>
                        🕵️ <?php esc_html_e( 'Run AI Deep Dive Cyber Audit', 'cloudscale-devtools' ); ?>
                    </button>
                    <button id="cs-deep-cancel-btn" class="cs-btn-secondary" style="display:none">
                        ✕ <?php esc_html_e( 'Cancel', 'cloudscale-devtools' ); ?>
                    </button>
                    <span id="cs-deep-model-badge" class="cs-scan-model-badge"></span>
                </div>
                <span id="cs-deep-scan-status" class="cs-vuln-inline-msg"></span>
                <div id="cs-deep-progress" class="cs-scan-progress">
                    <div class="cs-scan-progress-fill deep"></div>
                </div>
                <div id="cs-deep-results" class="cs-vuln-results" style="display:none;margin-top:6px"></div>
                </div>
                </div><!-- /cs-panel-body -->
                </div><!-- /cs-panel-ai-cyber-audit -->

                <!-- Scan History -->
                <div class="cs-panel" id="cs-panel-scan-history">
                <div class="cs-section-header" style="background:linear-gradient(90deg,#1e1b4b 0%,#4338ca 100%);border-left:3px solid #818cf8;">
                    <span>📈 <?php esc_html_e( 'Scan History', 'cloudscale-devtools' ); ?></span>
                    <span class="cs-header-hint"><?php esc_html_e( 'Last 50 scans — track your security score over time', 'cloudscale-devtools' ); ?></span>
                    <?php self::render_explain_btn( 'scan-history', 'Scan History', [
                        [ 'name' => 'What is tracked',   'rec' => 'Overview',    'html' => 'Every AI Deep Dive Cyber Audit saves a summary entry: scan date, model used, severity counts (critical / high / medium / low), and the full findings list. The last 50 scans are retained.' ],
                        [ 'name' => 'Score trend chart', 'rec' => 'Info',        'html' => 'The chart plots your critical + high finding count over time. A downward trend means your security posture is improving. Spikes after a plugin update or site change are worth investigating.' ],
                        [ 'name' => 'Reload a scan',     'rec' => 'Info',        'html' => 'Click any row in the history table to reload that scan\'s full findings report. Useful for comparing before-and-after states when remediating issues, without needing to re-run the scan.' ],
                    ] ); ?>
                </div>
                <div class="cs-panel-body" id="cs-scan-history-wrap">
                <?php
                $history = get_option( 'csdt_scan_history', [] );
                if ( ! empty( $history ) ) : ?>
                <canvas id="cs-scan-history-chart" height="180"
                    style="width:100%;max-width:100%;display:block;margin-bottom:20px;border-radius:6px;background:#fff;border:1px solid #e2e8f0;"></canvas>
                <?php endif; if ( empty( $history ) ) :
                ?>
                    <div style="text-align:center;padding:32px 20px;background:#f8fafc;border:2px dashed #e2e8f0;border-radius:8px;">
                        <div style="font-size:2rem;margin-bottom:10px;">🛡️</div>
                        <div style="font-weight:700;font-size:15px;color:#0f172a;margin-bottom:6px;"><?php esc_html_e( 'No scans yet', 'cloudscale-devtools' ); ?></div>
                        <div style="font-size:13px;color:#6b7280;margin:0 auto 18px;max-width:380px;"><?php esc_html_e( 'Run your first AI Cyber Audit above. Each scan is saved here so you can track your security posture over time.', 'cloudscale-devtools' ); ?></div>
                        <a href="#cs-panel-ai-cyber-audit" onclick="document.getElementById('cs-panel-ai-cyber-audit').scrollIntoView({behavior:'smooth'});return false;" style="display:inline-block;background:#6366f1;color:#fff;font-weight:600;font-size:13px;padding:7px 18px;border-radius:6px;text-decoration:none;">Run First Scan ↑</a>
                    </div>
                <?php else : ?>
                    <details id="cs-scan-history-list">
                    <summary style="cursor:pointer;list-style:none;display:flex;align-items:center;gap:8px;padding:4px 2px;margin-bottom:8px;user-select:none;">
                        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#64748b;"><?php echo esc_html( sprintf( _n( '%d scan', '%d scans', count( $history ), 'cloudscale-devtools' ), count( $history ) ) ); ?></span>
                        <span style="font-size:10px;color:#94a3b8;margin-left:auto;">&#x25BC;</span>
                    </summary>
                    <div style="display:flex;flex-direction:column;gap:6px;">
                    <?php foreach ( $history as $idx => $entry ) :
                        $score       = (int) ( $entry['score'] ?? 0 );
                        $label       = esc_html( $entry['score_label'] ?? '' );
                        $type_label  = $entry['type'] === 'deep' ? 'Deep Dive' : 'AI Cyber Audit';
                        $date        = $entry['scanned_at'] ? wp_date( 'D j M Y, g:ia', $entry['scanned_at'] ) : '';
                        $score_color = $score >= 90 ? '#22c55e' : ( $score >= 75 ? '#4ade80' : ( $score >= 55 ? '#fbbf24' : ( $score >= 35 ? '#f97316' : '#ef4444' ) ) );
                        $has_findings = ! empty( $entry['findings'] );
                    ?>
                        <div style="display:flex;align-items:flex-start;gap:14px;padding:10px 12px;background:#f8fafc;border-radius:6px;border:1px solid #e2e8f0;">
                            <div style="flex-shrink:0;text-align:center;min-width:48px;">
                                <div style="font-size:1.4rem;font-weight:700;color:<?php echo esc_attr( $score_color ); ?>;line-height:1;"><?php echo esc_html( $score ); ?></div>
                                <div style="font-size:10px;color:<?php echo esc_attr( $score_color ); ?>;opacity:.8;"><?php echo esc_html( $label ); ?></div>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px;flex-wrap:wrap;">
                                    <span style="font-size:12px;font-weight:600;color:#0f172a;"><?php echo esc_html( $type_label ); ?></span>
                                    <span style="font-size:12px;font-weight:400;color:#64748b;"><?php echo esc_html( $date ); ?></span>
                                    <?php if ( $has_findings ) : ?>
                                    <button type="button"
                                        class="csdt-view-report-btn"
                                        data-idx="<?php echo esc_attr( $idx ); ?>"
                                        data-type="<?php echo esc_attr( $type_label ); ?>"
                                        data-date="<?php echo esc_attr( $date ); ?>"
                                        data-score="<?php echo esc_attr( $score ); ?>"
                                        data-label="<?php echo esc_attr( $label ); ?>"
                                        data-summary="<?php echo esc_attr( $entry['summary'] ?? '' ); ?>"
                                        style="font-size:11px;font-weight:600;color:#60a5fa;background:none;border:1px solid #60a5fa;border-radius:4px;padding:1px 8px;cursor:pointer;line-height:1.5;flex-shrink:0;">
                                        View Report
                                    </button>
                                    <button type="button"
                                        class="csdt-history-pdf-btn"
                                        data-idx="<?php echo esc_attr( $idx ); ?>"
                                        data-scan-type="<?php echo esc_attr( $entry['type'] ?? 'standard' ); ?>"
                                        style="font-size:11px;font-weight:600;color:#6b7280;background:none;border:1px solid #d1d5db;border-radius:4px;padding:1px 8px;cursor:pointer;line-height:1.5;flex-shrink:0;">
                                        ↓ PDF
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:12px;color:#374151;line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
                                    <?php echo esc_html( $entry['summary'] ?? '' ); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    </details>
                <?php endif; ?>
                </div><!-- /cs-panel-body scan-history -->
                </div><!-- /cs-panel-scan-history -->

                <div class="cs-panel" id="cs-panel-adhoc-audits">
                <div class="cs-section-header" style="background:linear-gradient(90deg,#0c1a2e 0%,#1e3a5f 100%);border-left:3px solid #60a5fa;">
                    <span>🌐 <?php esc_html_e( 'Adhoc Cyber Audits', 'cloudscale-devtools' ); ?></span>
                    <span class="cs-header-hint"><?php esc_html_e( 'External site scans — results saved here', 'cloudscale-devtools' ); ?></span>
                </div>
                <div class="cs-panel-body">
                    <div id="cs-adhoc-scan-area" style="display:none;margin-bottom:16px;">
                        <span id="cs-adhoc-scan-target" style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:6px;"></span>
                        <span id="cs-adhoc-scan-status" class="cs-vuln-inline-msg"></span>
                        <div id="cs-adhoc-progress" class="cs-scan-progress" style="margin-top:6px;">
                            <div class="cs-scan-progress-fill"></div>
                        </div>
                    </div>
                    <div id="cs-adhoc-list">
                        <div id="cs-adhoc-empty" style="text-align:center;padding:28px 20px;background:#f8fafc;border:2px dashed #e2e8f0;border-radius:8px;">
                            <div style="font-size:2rem;margin-bottom:8px;">🌐</div>
                            <div style="font-weight:700;font-size:14px;color:#0f172a;margin-bottom:5px;"><?php esc_html_e( 'No adhoc scans yet', 'cloudscale-devtools' ); ?></div>
                            <div style="font-size:13px;color:#6b7280;max-width:380px;margin:0 auto;"><?php esc_html_e( 'Enter a different site URL above, then run an AI Deep Dive Cyber Audit.', 'cloudscale-devtools' ); ?></div>
                        </div>
                    </div>
                </div>
                </div><!-- /cs-panel-adhoc-audits -->
        <?php
    }

    /**
     * Hunt for a domain across cron events, options values, and active plugin headers.
     * Called from the CSP violation log "Where is this from?" button.
     */
    public static function ajax_csp_domain_hunt(): void {
        check_ajax_referer( self::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
        if ( ! $domain ) {
            wp_send_json_error( 'No domain provided.' );
        }

        global $wpdb;
        $results = [];

        // ── 1. Cron events ──────────────────────────────────────────────────
        $cron = _get_cron_array();
        $cron_hits = [];
        if ( is_array( $cron ) ) {
            foreach ( $cron as $timestamp => $hooks ) {
                foreach ( $hooks as $hook => $events ) {
                    if ( stripos( $hook, $domain ) !== false ) {
                        $cron_hits[] = $hook . ' (next: ' . gmdate( 'Y-m-d H:i', (int) $timestamp ) . ' UTC)';
                    }
                    foreach ( $events as $event ) {
                        $serialized = maybe_serialize( $event['args'] ?? [] );
                        if ( stripos( (string) $serialized, $domain ) !== false ) {
                            $cron_hits[] = $hook . ' args contain domain (next: ' . gmdate( 'Y-m-d H:i', (int) $timestamp ) . ' UTC)';
                        }
                    }
                }
            }
        }
        if ( $cron_hits ) {
            $results['cron'] = array_unique( $cron_hits );
        }

        // ── 2. wp_options values ─────────────────────────────────────────────
        $like        = '%' . $wpdb->esc_like( $domain ) . '%';
        $option_hits = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s AND autoload = 'yes' LIMIT 20",
                $like
            ),
            ARRAY_A
        );
        if ( $option_hits ) {
            $results['options'] = array_column( $option_hits, 'option_name' );
        }

        // ── 3. Non-autoloaded options (slower — limit tightly) ────────────────
        $slow_hits = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s AND autoload != 'yes' LIMIT 10",
                $like
            ),
            ARRAY_A
        );
        if ( $slow_hits ) {
            $results['options_noautoload'] = array_column( $slow_hits, 'option_name' );
        }

        // ── 4. Active plugin file headers ────────────────────────────────────
        $active  = get_option( 'active_plugins', [] );
        $plugin_hits = [];
        foreach ( $active as $plugin_file ) {
            $path = WP_PLUGIN_DIR . '/' . $plugin_file;
            if ( ! file_exists( $path ) ) { continue; }
            $header = file_get_contents( $path, false, null, 0, 4000 );
            if ( $header && stripos( $header, $domain ) !== false ) {
                $plugin_hits[] = $plugin_file;
            }
        }
        if ( $plugin_hits ) {
            $results['active_plugins'] = $plugin_hits;
        }

        // ── 5. Inactive plugin directories (name match only) ─────────────────
        $all_plugins = get_plugins();
        $inactive_hits = [];
        foreach ( $all_plugins as $file => $data ) {
            if ( in_array( $file, $active, true ) ) { continue; }
            $slug = dirname( $file );
            if ( stripos( $slug, $domain ) !== false || stripos( $data['Name'] ?? '', $domain ) !== false ) {
                $inactive_hits[] = ( $data['Name'] ?? $file ) . ' (inactive)';
            }
        }
        if ( $inactive_hits ) {
            $results['inactive_plugins'] = $inactive_hits;
        }

        wp_send_json_success( [
            'domain'  => $domain,
            'results' => $results,
            'found'   => ! empty( $results ),
        ] );
    }


}

CloudScale_DevTools::init();
