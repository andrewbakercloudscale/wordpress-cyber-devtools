<?php
/**
 * Performance Monitor — HTTP, query, hook, asset, error, and transient tracking.
 *
 * @package CloudScale_DevTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CSDT_Perf_Monitor {

    // Performance monitor — static storage.
    /** @var array HTTP calls captured during this request. */
    public static $perf_http_calls = [];
    /** @var float|null Microtime when last HTTP request started. */
    public static $perf_http_timer = null;
    /** @var array PHP errors captured during this request. */
    public static $perf_php_errors = [];
    /** @var array|null Active plugin prefix → slug map cache. */
    public static $perf_plugin_map = null;
    /** @var callable|null Previous PHP error handler to chain into. */
    public static $perf_prev_error_handler = null;
    /** @var string Template filename captured via template_include filter. */
    public static $perf_template = '';
    /** @var array Hook fire stats: [ hook => ['count'=>int,'total_ms'=>float,'max_ms'=>float] ] */
    public static $perf_hooks = [];
    /** @var float|null Timestamp of last hook fire (ms). */
    public static $perf_hook_last_ms = null;
    /** @var string|null Name of last hook fired. */
    public static $perf_hook_last_name = null;
    /** @var array Transient stats: [ key => [ gets, hits, sets, deletes ] ] */
    public static $perf_transients = [];
    /** @var array Template hierarchy candidates captured via *_template_hierarchy filters. */
    public static $perf_template_hierarchy = [];
    /** @var array Request lifecycle milestones: [ ['label'=>string, 'ms'=>float] ] */
    public static $perf_milestones = [];

    /* ==================================================================
       PERFORMANCE MONITOR — HTTP capture
       ================================================================== */

    /**
     * Records microtime before each outbound HTTP request starts.
     *
     * Returns the $pre value unchanged so it never short-circuits the request.
     *
     * @param  false|array|\WP_Error $pre  Pre-emptive response or false.
     * @param  array                 $args Request arguments.
     * @param  string                $url  Request URL.
     * @return false|array|\WP_Error
     */
    public static function perf_http_before( $pre, $args, $url ) {
        self::$perf_http_timer = microtime( true );
        return $pre;
    }

    /**
     * Captures a completed HTTP request into the performance monitor data store.
     *
     * @param  array|\WP_Error $response    HTTP response or WP_Error.
     * @param  string          $context     Transport context string.
     * @param  string          $class       WP_HTTP transport class name.
     * @param  array           $parsed_args Parsed request arguments.
     * @param  string          $url         Request URL.
     * @return void
     */
    public static function perf_http_after( $response, $context, $class, $parsed_args, $url ) {
        $elapsed_ms            = self::$perf_http_timer
            ? round( ( microtime( true ) - self::$perf_http_timer ) * 1000, 2 )
            : 0;
        self::$perf_http_timer = null;

        $status = 0;
        $cached = false;
        $error  = null;

        if ( is_wp_error( $response ) ) {
            $error = $response->get_error_message();
        } else {
            $status  = (int) wp_remote_retrieve_response_code( $response );
            $headers = wp_remote_retrieve_headers( $response );
            // Detect CDN / proxy cache hits.
            $hdr_xcache    = is_array( $headers['x-cache'] ?? null )    ? implode( ', ', $headers['x-cache'] )    : (string) ( $headers['x-cache'] ?? '' );
            $hdr_cfcache   = is_array( $headers['cf-cache-status'] ?? null ) ? implode( ', ', $headers['cf-cache-status'] ) : (string) ( $headers['cf-cache-status'] ?? '' );
            $hdr_wpcache   = is_array( $headers['x-wp-cache'] ?? null ) ? implode( ', ', $headers['x-wp-cache'] ) : (string) ( $headers['x-wp-cache'] ?? '' );
            if ( $hdr_xcache  && false !== stripos( $hdr_xcache,  'HIT' ) ) { $cached = true; }
            elseif ( $hdr_cfcache && 'HIT' === strtoupper( $hdr_cfcache ) ) { $cached = true; }
            elseif ( $hdr_wpcache && 'HIT' === strtoupper( $hdr_wpcache ) ) { $cached = true; }
        }

        // Use a real file-path backtrace for accurate plugin attribution.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
        $bt     = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 25 );
        $plugin = self::perf_plugin_from_frames( $bt );

        $parsed_url = wp_parse_url( $url );
        $home_host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );

        self::$perf_http_calls[] = [
            'url'      => $url,
            'method'   => strtoupper( $parsed_args['method'] ?? 'GET' ),
            'status'   => $status,
            'time_ms'  => $elapsed_ms,
            'cached'   => $cached,
            'plugin'   => $plugin,
            'error'    => $error,
            // Security flags.
            'insecure' => isset( $parsed_url['scheme'] ) && 'http' === strtolower( $parsed_url['scheme'] ),
            'external' => isset( $parsed_url['host'] ) && strtolower( $parsed_url['host'] ) !== strtolower( $home_host ),
        ];
    }

    /**
     * Captures PHP warnings, notices, and deprecations into the performance monitor store.
     *
     * Chains to any previously registered error handler so existing error reporting
     * (WP_DEBUG display, logging) continues to work unaffected.
     *
     * @param  int    $errno   Error number / level bitmask.
     * @param  string $errstr  Error message.
     * @param  string $errfile File where the error occurred.
     * @param  int    $errline Line number where the error occurred.
     * @return bool   false to allow PHP's default handler to also run.
     */
    public static function perf_error_handler( int $errno, string $errstr, string $errfile = '', int $errline = 0 ): bool {
        static $count = 0;
        if ( $count < 75 ) {
            $count++;
            $levels = [
                E_WARNING         => 'Warning',
                E_NOTICE          => 'Notice',
                E_DEPRECATED      => 'Deprecated',
                E_USER_WARNING    => 'Warning',
                E_USER_NOTICE     => 'Notice',
                E_USER_DEPRECATED => 'Deprecated',
            ];
            self::$perf_php_errors[] = [
                'level'   => $levels[ $errno ] ?? 'Notice',
                'message' => $errstr,
                'file'    => defined( 'ABSPATH' ) ? str_replace( ABSPATH, '', $errfile ) : $errfile,
                'line'    => $errline,
            ];
        }

        // Chain to the previous handler (e.g. WordPress debug display/logging).
        if ( is_callable( self::$perf_prev_error_handler ) ) {
            return (bool) call_user_func( self::$perf_prev_error_handler, $errno, $errstr, $errfile, $errline );
        }

        return false; // let PHP's built-in handler continue.
    }

    /* ==================================================================
       PERFORMANCE MONITOR — Admin assets + panel output
       ================================================================== */

    /**
     * Enqueues the performance monitor CSS and JS on all admin pages for admins.
     *
     * @param  string $hook Current admin page hook suffix.
     * @return void
     */
    public static function perf_enqueue( string $hook ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $root_url = plugin_dir_url( __DIR__ );
        wp_enqueue_style(
            'csdt-perf-monitor',
            $root_url . 'assets/cs-perf-monitor.css',
            [],
            CloudScale_DevTools::VERSION
        );
        wp_enqueue_script(
            'csdt-perf-monitor',
            $root_url . 'assets/cs-perf-monitor.js',
            [],
            CloudScale_DevTools::VERSION,
            true
        );
    }

    /**
     * Stores the template filename so it can be included in panel meta context.
     *
     * @param  string $template Full path to the active template file.
     * @return string           Unchanged template path.
     */
    public static function perf_capture_template( string $template ): string {
        self::$perf_template = basename( $template );
        return $template;
    }

    /**
     * Tracks every action/filter fire for hook timing.
     * Called via add_action('all', ...) so receives the current hook name automatically.
     *
     * @return void
     */
    public static function perf_hook_tracker(): void {
        $hook = current_filter();
        $now  = microtime( true ) * 1000;

        // Close out the previous hook's timing.
        if ( null !== self::$perf_hook_last_ms && null !== self::$perf_hook_last_name ) {
            $elapsed = $now - self::$perf_hook_last_ms;
            $prev    = self::$perf_hook_last_name;
            if ( ! isset( self::$perf_hooks[ $prev ] ) ) {
                self::$perf_hooks[ $prev ] = [ 'count' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0 ];
            }
            self::$perf_hooks[ $prev ]['count']++;
            self::$perf_hooks[ $prev ]['total_ms'] += $elapsed;
            if ( $elapsed > self::$perf_hooks[ $prev ]['max_ms'] ) {
                self::$perf_hooks[ $prev ]['max_ms'] = $elapsed;
            }
        }

        self::$perf_hook_last_ms   = $now;
        self::$perf_hook_last_name = $hook;
    }

    /**
     * Captures all enqueued scripts and styles at footer time (priority 1).
     *
     * @return void
     */
    public static function perf_capture_assets(): void {
        // Only needs to run once; admin_footer and wp_footer both call this.
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;
    }

    /**
     * Builds the scripts & styles payload for the panel.
     *
     * @return array{ scripts: array, styles: array }
     */
    private static function perf_build_assets_data(): array {
        $scripts_obj = wp_scripts();
        $styles_obj  = wp_styles();

        // WP registers scripts/styles with src=false (inline-only); cast to string
        // so the JS side always receives a string, never a boolean false.
        $in_footer = isset( $scripts_obj->in_footer ) && is_array( $scripts_obj->in_footer )
            ? $scripts_obj->in_footer : [];

        $scripts = [];
        foreach ( $scripts_obj->done as $handle ) {
            if ( ! isset( $scripts_obj->registered[ $handle ] ) ) {
                continue;
            }
            $dep      = $scripts_obj->registered[ $handle ];
            $src      = is_string( $dep->src ) ? $dep->src : '';
            $strategy = isset( $dep->extra['strategy'] ) ? (string) $dep->extra['strategy'] : '';
            $scripts[] = [
                'handle'    => (string) $handle,
                'src'       => $src,
                'plugin'    => self::perf_attr_asset( $src ),
                'ver'       => is_string( $dep->ver ) ? $dep->ver : ( $dep->ver ? (string) $dep->ver : '' ),
                'in_footer' => in_array( $handle, $in_footer, true ),
                'strategy'  => $strategy, // 'defer', 'async', or ''
            ];
        }

        $styles = [];
        foreach ( $styles_obj->done as $handle ) {
            if ( ! isset( $styles_obj->registered[ $handle ] ) ) {
                continue;
            }
            $dep = $styles_obj->registered[ $handle ];
            $src = is_string( $dep->src ) ? $dep->src : '';
            $styles[] = [
                'handle' => (string) $handle,
                'src'    => $src,
                'plugin' => self::perf_attr_asset( $src ),
                'ver'    => is_string( $dep->ver ) ? $dep->ver : ( $dep->ver ? (string) $dep->ver : '' ),
            ];
        }

        return [ 'scripts' => $scripts, 'styles' => $styles ];
    }

    /**
     * Attributes an asset URL to a plugin or theme slug.
     *
     * @param  string $src Asset URL or path.
     * @return string      Plugin slug, 'theme', 'wp-core', or 'unknown'.
     */
    private static function perf_attr_asset( string $src ): string {
        if ( empty( $src ) ) {
            return 'unknown';
        }
        $content_url = content_url();
        // Strip the site URL to get a relative path for easier matching.
        $rel = str_replace( site_url(), '', $src );

        if ( false !== strpos( $rel, '/plugins/' ) ) {
            if ( preg_match( '#/plugins/([^/]+)/#', $rel, $m ) ) {
                return $m[1];
            }
        }
        if ( false !== strpos( $rel, '/themes/' ) ) {
            return 'theme';
        }
        if ( false !== strpos( $rel, '/wp-includes/' ) || false !== strpos( $rel, '/wp-admin/' ) ) {
            return 'wp-core';
        }
        return 'unknown';
    }

    /**
     * Builds the object-cache stats payload.
     *
     * @return array
     */
    private static function perf_build_cache_data(): array {
        global $wp_object_cache;

        if ( ! is_object( $wp_object_cache ) ) {
            return [ 'available' => false ];
        }

        // Standard WP internal cache (non-persistent).
        $hits   = method_exists( $wp_object_cache, 'cache_hits' )
            ? $wp_object_cache->cache_hits
            : ( $wp_object_cache->hits ?? null );
        $misses = method_exists( $wp_object_cache, 'cache_misses' )
            ? $wp_object_cache->cache_misses
            : ( $wp_object_cache->misses ?? null );

        // Fallback: try public properties directly (most object cache plugins expose these).
        if ( null === $hits )   { $hits   = $wp_object_cache->cache_hits   ?? $wp_object_cache->hits   ?? null; }
        if ( null === $misses ) { $misses = $wp_object_cache->cache_misses ?? $wp_object_cache->misses ?? null; }

        $total    = (int) $hits + (int) $misses;
        $hit_rate = $total > 0 ? round( ( (int) $hits / $total ) * 100, 1 ) : null;

        // Redis / Memcache info string if available.
        $info = null;
        if ( method_exists( $wp_object_cache, 'info' ) ) {
            $raw = $wp_object_cache->info();
            $info = is_string( $raw ) ? $raw : wp_json_encode( $raw );
        }

        // Group stats — available on persistent caches (e.g. Redis Object Cache plugin).
        $groups = [];
        if ( isset( $wp_object_cache->stats ) && is_array( $wp_object_cache->stats ) ) {
            foreach ( $wp_object_cache->stats as $group => $stat ) {
                if ( is_array( $stat ) ) {
                    $groups[] = [
                        'group'   => $group,
                        'hits'    => $stat['hits']   ?? 0,
                        'misses'  => $stat['misses'] ?? 0,
                        'bytes'   => $stat['bytes']  ?? 0,
                    ];
                }
            }
        }

        return [
            'available'  => true,
            'persistent' => ( defined( 'WP_REDIS_VERSION' ) || defined( 'MEMCACHE_VERSION' ) ),
            'hits'       => (int) $hits,
            'misses'     => (int) $misses,
            'hit_rate'   => $hit_rate,
            'info'       => $info,
            'groups'     => $groups,
        ];
    }

    /**
     * Builds the top-N hooks timing payload.
     *
     * @return array
     */
    /**
     * Records a named request-lifecycle milestone with ms-since-request-start.
     *
     * @param  string $label Human-readable phase label.
     * @return void
     */
    public static function perf_record_milestone( string $label ): void {
        if ( ! isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
            return;
        }
        self::$perf_milestones[] = [
            'label' => $label,
            'ms'    => round( ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000, 1 ),
        ];
    }

    private static function perf_build_hooks_data(): array {
        global $wp_filter;

        $hooks = self::$perf_hooks;
        // Sort by total_ms descending.
        uasort( $hooks, static function ( $a, $b ) {
            return $b['total_ms'] <=> $a['total_ms'];
        } );

        $result = [];
        foreach ( array_slice( $hooks, 0, 50, true ) as $name => $stat ) {
            // Collect registered callbacks from $wp_filter for attribution.
            $callbacks = [];
            if ( isset( $wp_filter[ $name ] ) && $wp_filter[ $name ] instanceof WP_Hook ) {
                $cb_count = 0;
                foreach ( $wp_filter[ $name ]->callbacks as $priority => $cbs ) {
                    foreach ( $cbs as $cb_info ) {
                        if ( $cb_count >= 20 ) {
                            break 2;
                        }
                        $info        = self::perf_callback_info( $cb_info['function'] );
                        $callbacks[] = [
                            'priority' => (int) $priority,
                            'label'    => $info['label'],
                            'plugin'   => $info['plugin'],
                        ];
                        $cb_count++;
                    }
                }
            }

            $result[] = [
                'hook'      => $name,
                'count'     => $stat['count'],
                'total_ms'  => round( $stat['total_ms'], 2 ),
                'max_ms'    => round( $stat['max_ms'], 2 ),
                'avg_ms'    => $stat['count'] > 0 ? round( $stat['total_ms'] / $stat['count'], 2 ) : 0,
                'callbacks' => $callbacks,
            ];
        }
        return $result;
    }

    /**
     * Enqueues performance monitor CSS and JS on frontend pages for admin users.
     *
     * @return void
     */
    public static function perf_frontend_enqueue() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $root_url = plugin_dir_url( __DIR__ );
        wp_enqueue_style(
            'csdt-perf-monitor',
            $root_url . 'assets/cs-perf-monitor.css',
            [],
            CloudScale_DevTools::VERSION
        );
        wp_enqueue_script(
            'csdt-perf-monitor',
            $root_url . 'assets/cs-perf-monitor.js',
            [],
            CloudScale_DevTools::VERSION,
            true
        );
    }

    /**
     * Injects performance data as a JS global before footer scripts are printed.
     *
     * Hooked to admin_footer / wp_footer at priority 15, before WordPress
     * calls wp_print_footer_scripts() at priority 20. This ensures
     * window.csdtDevtoolsPerfData is set before cs-perf-monitor.js IIFE runs.
     *
     * @since  1.8.113
     * @return void
     */
    public static function perf_inject_data(): void {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( get_option( 'csdt_devtools_perf_monitor_enabled', '1' ) === '0' ) {
            return;
        }

        $queries = self::perf_build_query_data();
        $http    = self::$perf_http_calls;
        $errors  = self::$perf_php_errors;
        $logs    = self::perf_build_log_data();
        $assets  = self::perf_build_assets_data();
        $cache   = self::perf_build_cache_data();
        $hooks   = self::perf_build_hooks_data();

        $q_total = 0.0;
        foreach ( $queries as $q ) {
            $q_total += $q['time_ms'];
        }
        $h_total = 0.0;
        foreach ( $http as $h ) {
            $h_total += $h['time_ms'];
        }

        // Request time snapshot at data-injection time (priority 15).
        $page_ms = isset( $_SERVER['REQUEST_TIME_FLOAT'] )
            ? round( ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000, 2 )
            : 0;

        $data = [
            'queries' => $queries,
            'http'    => $http,
            'errors'  => $errors,
            'logs'    => $logs,
            'assets'  => $assets,
            'cache'   => $cache,
            'hooks'   => $hooks,
            'meta'    => [
                'query_count'    => count( $queries ),
                'query_total_ms' => round( $q_total, 2 ),
                'http_count'     => count( $http ),
                'http_total_ms'  => round( $h_total, 2 ),
                'error_count'    => count( $errors ),
                'log_count'      => count( $logs ),
                'script_count'   => count( $assets['scripts'] ),
                'style_count'    => count( $assets['styles'] ),
                'hook_count'     => count( $hooks ),
                'page_load_ms'       => $page_ms,
                'is_admin'           => is_admin(),
                'url'                => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
                'ajax_url'           => admin_url( 'admin-ajax.php' ),
                'explain_nonce'      => wp_create_nonce( 'csdt_devtools_perf_explain' ),
                'debug_nonce'        => wp_create_nonce( 'csdt_devtools_perf_debug' ),
                'wp_debug'           => (bool) get_option( 'csdt_devtools_perf_debug_logging', false ),
                'wp_debug_log'       => (bool) get_option( 'csdt_devtools_perf_debug_logging', false ),
                'savequeries_active' => defined( 'SAVEQUERIES' ) && SAVEQUERIES,
                // Page-context strip: what screen / template is this?
                'wp_screen'          => ( is_admin() && function_exists( 'get_current_screen' ) && get_current_screen() )
                                            ? get_current_screen()->id
                                            : '',
                'page_type'          => is_admin() ? 'admin'
                                        : ( is_singular() ? get_post_type() . ' (single)'
                                        : ( is_archive() ? 'archive'
                                        : ( is_home()    ? 'blog home'
                                        : ( is_front_page() ? 'front page' : 'other' ) ) ) ),
                'template'           => self::$perf_template,
                // Environment
                'php_version'        => PHP_VERSION,
                'wp_version'         => get_bloginfo( 'version' ),
                'mysql_version'      => $wpdb->db_version(),
                'memory_limit'       => ini_get( 'memory_limit' ),
                'memory_peak_mb'     => round( memory_get_peak_usage( true ) / 1048576, 1 ),
                'active_theme'       => wp_get_theme()->get( 'Name' ),
                'is_multisite'       => is_multisite(),
                'login_slug'         => get_option( 'csdt_devtools_login_slug', '' ),
            ],
            'request'    => self::perf_build_request_data(),
            'transients' => self::perf_build_transient_data(),
            'template'   => self::perf_build_template_data(),
            'health'     => self::perf_build_health_data(),
            'milestones' => array_merge(
                [ [ 'label' => 'Request start', 'ms' => 0.0 ] ],
                self::$perf_milestones
            ),
        ];

        wp_add_inline_script( 'csdt-perf-monitor', 'window.csdtDevtoolsPerfData=' . wp_json_encode( $data ) . ';', 'before' );
    }

    /**
     * Outputs the performance monitor panel HTML skeleton at footer.
     *
     * Fires at priority 9999 so it appears at the very end of the page body.
     * Data is injected earlier via perf_inject_data() at priority 15.
     *
     * @since  1.8.0
     * @return void
     */
    public static function perf_output_panel(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( get_option( 'csdt_devtools_perf_monitor_enabled', '1' ) === '0' ) {
            return;
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static HTML, no user data
        echo self::perf_panel_html();
    }

    // ─── Thumbnails admin CSS ─────────────────────────────────────────────────

    /**
     * Returns the CSS for the Thumbnails admin tab.
     *
     * Injected via wp_add_inline_style() on the cs-admin-tabs handle when the
     * thumbnails tab is active, keeping the render method free of <style> tags.
     *
     * @since  1.8.113
     * @return string
     */
    public static function get_thumbnails_admin_css(): string {
        return '
.cs-thumb-cf-steps{display:flex;flex-direction:column;gap:12px;margin-top:10px}
.cs-thumb-cf-step{display:flex;gap:12px;align-items:flex-start}
.cs-thumb-cf-step-num{min-width:26px;height:26px;background:#e65100;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;margin-top:2px}
.cs-thumb-cf-code{background:#1e1e1e;color:#e8e8e8;padding:10px 14px;border-radius:4px;font-size:12px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;margin:6px 0}
.cs-thumb-report-hdr{display:flex;justify-content:space-between;align-items:center;padding:9px 14px;border-radius:4px;margin-bottom:10px;font-size:13px;font-weight:600}
.cs-thumb-pass-hdr{background:#edfaed;color:#276227}
.cs-thumb-warn-hdr{background:#fff8e5;color:#7a5a00}
.cs-thumb-fail-hdr{background:#fdf0f0;color:#8c2020}
.cs-thumb-tally{display:flex;gap:12px;font-size:13px}
.cs-thumb-section{border:1px solid #e0e0e0;border-radius:4px;margin-bottom:10px;overflow:hidden}
.cs-thumb-section-title{background:#f6f7f7;padding:6px 12px;font-size:12px;font-weight:700;color:#333;border-bottom:1px solid #e0e0e0;text-transform:uppercase;letter-spacing:.4px}
.cs-thumb-results-list{margin:0;padding:6px 10px;list-style:none}
.cs-thumb-result{display:flex;gap:8px;padding:3px 0;font-size:12px;align-items:flex-start}
.cs-thumb-pass{color:#276227}
.cs-thumb-warn{color:#7a5a00}
.cs-thumb-fail{color:#8c2020}
.cs-thumb-fix{margin-top:3px;font-size:11px;color:#1a4a7a;background:#f0f6fc;border-left:3px solid #2271b1;padding:3px 7px;border-radius:0 3px 3px 0}
.cs-thumb-info{color:#555}
.cs-thumb-ua-grid{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
.cs-thumb-ua-chip{padding:5px 12px;border-radius:20px;font-size:12px;font-weight:600}
.cs-thumb-ua-ok{background:#edfaed;color:#276227}
.cs-thumb-ua-fail{background:#fdf0f0;color:#8c2020}
.cs-thumb-ua-warn{background:#fff8e5;color:#7a5a00}
.cs-input-light-placeholder::placeholder{color:#bbb;font-weight:400}
.cs-thumb-posts-table{width:100%;border-collapse:collapse;font-size:13px}
.cs-thumb-posts-table th{background:#f6f7f7;padding:7px 10px;text-align:left;border-bottom:2px solid #ddd}
.cs-thumb-posts-table td{padding:7px 10px;border-bottom:1px solid #eee;vertical-align:top}
.cs-thumb-badge-ok{display:inline-block;background:#edfaed;color:#276227;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.cs-thumb-badge-warn{display:inline-block;background:#fff8e5;color:#7a5a00;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.cs-thumb-badge-fail{display:inline-block;background:#fdf0f0;color:#8c2020;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.cs-thumb-audit-table{font-size:12px;width:100%;border-collapse:collapse}
.cs-thumb-audit-table th{background:#f6f7f7;padding:6px 10px;text-align:left;border-bottom:2px solid #ddd}
.cs-thumb-audit-table td{padding:6px 10px;border-bottom:1px solid #eee;vertical-align:top}
.cs-platform-grid{display:flex;flex-wrap:wrap;gap:10px}
.cs-platform-card{display:flex;align-items:flex-start;gap:8px;padding:10px 12px;border:2px solid #ddd;border-radius:6px;cursor:pointer;transition:border-color .15s,background .15s;min-width:160px;flex:1 1 auto;max-width:200px}
.cs-platform-card:hover{border-color:#2271b1}
.cs-platform-checked{border-color:#2271b1;background:#f0f6ff}
.cs-platform-card input{margin-top:2px;flex-shrink:0}
.cs-platform-card-body{display:flex;flex-direction:column;gap:2px}
.cs-platform-name{font-size:13px;font-weight:600;color:#333}
.cs-platform-dims{font-size:11px;color:#555}
.cs-platform-limit{font-size:11px;color:#888}
.cs-fix-modal-wrap{margin-top:8px;padding:10px 12px;background:#f6f7f7;border:1px solid #e0e0e0;border-radius:5px;font-size:12px}
.cs-fix-platform-row{display:flex;align-items:center;gap:8px;padding:4px 0;border-bottom:1px solid #ebebeb}
.cs-fix-platform-row:last-child{border-bottom:none}
.cs-fix-platform-label{min-width:90px;font-weight:600;color:#333;font-size:12px}
.cs-fix-platform-dims{color:#888;font-size:11px;min-width:80px}
.cs-fix-platform-status{font-size:11px;flex:1}
.cs-fix-preview-thumb{width:48px;height:28px;object-fit:cover;border-radius:2px;border:1px solid #ddd;flex-shrink:0}
';
    }

    /**
     * Returns CSS for the Explain modal description content.
     *
     * Injected via wp_add_inline_style() on the csdt-admin-tabs handle on
     * every admin page load, scoped to .cs-explain-desc so it cannot leak.
     *
     * @since  1.8.118
     * @return string
     */
    public static function get_explain_modal_css(): string {
        return '
.cs-explain-desc{color:#50575e;font-size:12px;line-height:1.7}
.cs-explain-desc p{margin:0 0 8px 0}
.cs-explain-desc p:last-child{margin-bottom:0}
.cs-explain-desc code{display:inline;background:#1e2430;color:#e8b86d;padding:1px 6px;border-radius:3px;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:11px;white-space:nowrap;word-break:break-all}
.cs-explain-desc strong{color:#111827;font-weight:700}
.cs-explain-desc em{font-style:italic}
.cs-explain-desc a{color:#2271b1;text-decoration:underline}
.cs-explain-desc a:hover{color:#135e96}
.cs-explain-desc ul,.cs-explain-desc ol{margin:6px 0 0 0;padding-left:20px}
.cs-explain-desc li{margin-bottom:4px}
.cs-explain-desc h4{margin:10px 0 4px;font-size:12px;font-weight:700;color:#111827;text-transform:uppercase;letter-spacing:.04em}
';
    }

    /* ==================================================================
       PERFORMANCE MONITOR — Request data
       ================================================================== */

    /**
     * Collects request context: GET params, POST keys, WP query vars,
     * matched rewrite rule, and current user roles.
     *
     * @return array
     */
    private static function perf_build_request_data(): array {
        global $wp;

        // $_GET — values are in the URL so safe to show; sanitise for output.
        $get_params = [];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        foreach ( $_GET as $k => $v ) {
            $get_params[ sanitize_key( $k ) ] = is_array( $v )
                ? '(array)'
                : sanitize_text_field( wp_unslash( (string) $v ) );
        }

        // $_POST — show keys only; values could contain passwords / nonces.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $post_keys = array_map( 'sanitize_key', array_keys( $_POST ) );

        // WP query vars — only available on frontend after parse_request.
        $query_vars = [];
        if ( isset( $wp ) && is_object( $wp ) && isset( $wp->query_vars ) && is_array( $wp->query_vars ) ) {
            foreach ( $wp->query_vars as $k => $v ) {
                if ( '' === $v || false === $v || null === $v ) {
                    continue;
                }
                $query_vars[ sanitize_key( $k ) ] = is_array( $v )
                    ? '(array)'
                    : sanitize_text_field( wp_unslash( (string) $v ) );
            }
        }

        return [
            'method'       => isset( $_SERVER['REQUEST_METHOD'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
                : 'GET',
            'get'          => $get_params,
            'post_keys'    => $post_keys,
            'matched_rule' => ( isset( $wp ) && is_object( $wp ) && isset( $wp->matched_rule ) )
                ? (string) $wp->matched_rule
                : '',
            'query_vars'   => $query_vars,
            'user_roles'   => wp_get_current_user()->roles,
        ];
    }

    /* ==================================================================
       PERFORMANCE MONITOR — Transient + template hierarchy observers
       ================================================================== */

    /**
     * Single all-hook observer for transients and template hierarchy.
     * Kept in one callback to minimise per-hook overhead (this fires on every hook).
     *
     * @param mixed $value First arg of the current filter/action (may not exist).
     * @return void
     */
    public static function perf_misc_tracker( $value = null ): void {
        $hook = current_filter();
        $ch   = isset( $hook[0] ) ? $hook[0] : '';

        // ── Transient GET tracking ────────────────────────────────────────────
        if ( 'p' === $ch && strpos( $hook, 'pre_transient_' ) === 0 ) {
            $key = substr( $hook, 14 ); // strlen('pre_transient_') = 14
            if ( ! isset( self::$perf_transients[ $key ] ) ) {
                self::$perf_transients[ $key ] = [ 'gets' => 0, 'hits' => 0, 'sets' => 0, 'deletes' => 0 ];
            }
            self::$perf_transients[ $key ]['gets']++;
            return;
        }

        // ── Transient GET result (hits only; misses = gets - hits) ────────────
        if ( 't' === $ch && strpos( $hook, 'transient_' ) === 0 ) {
            $key = substr( $hook, 10 ); // strlen('transient_') = 10
            if ( isset( self::$perf_transients[ $key ] ) && false !== $value ) {
                self::$perf_transients[ $key ]['hits']++;
            }
            return;
        }

        // ── Template hierarchy capture ────────────────────────────────────────
        // Hooks like single_template_hierarchy, page_template_hierarchy etc.
        static $suffix     = '_template_hierarchy';
        static $suffix_len = 19;
        if ( strlen( $hook ) > $suffix_len
            && substr( $hook, -$suffix_len ) === $suffix
            && is_array( $value )
        ) {
            self::$perf_template_hierarchy[] = [
                'type'      => substr( $hook, 0, -$suffix_len ),
                'templates' => array_values( $value ),
            ];
        }
    }

    /**
     * Records a transient SET. Fires on setted_transient / setted_site_transient.
     *
     * @param string $transient Transient key.
     * @return void
     */
    public static function perf_transient_set( string $transient ): void {
        if ( ! isset( self::$perf_transients[ $transient ] ) ) {
            self::$perf_transients[ $transient ] = [ 'gets' => 0, 'hits' => 0, 'sets' => 0, 'deletes' => 0 ];
        }
        self::$perf_transients[ $transient ]['sets']++;
    }

    /**
     * Records a transient DELETE. Fires on deleted_transient / deleted_site_transient.
     *
     * @param string $transient Transient key.
     * @return void
     */
    public static function perf_transient_delete( string $transient ): void {
        if ( ! isset( self::$perf_transients[ $transient ] ) ) {
            self::$perf_transients[ $transient ] = [ 'gets' => 0, 'hits' => 0, 'sets' => 0, 'deletes' => 0 ];
        }
        self::$perf_transients[ $transient ]['deletes']++;
    }

    /**
     * Builds the transient stats array for the panel.
     *
     * @return array
     */
    private static function perf_build_transient_data(): array {
        $result = [];
        foreach ( self::$perf_transients as $key => $stats ) {
            // hits only counts DB hits; persistent-cache GETs intercepted via
            // pre_transient_* may not produce a matching transient_* call.
            $misses   = max( 0, $stats['gets'] - $stats['hits'] );
            $hit_rate = $stats['gets'] > 0
                ? round( ( $stats['hits'] / $stats['gets'] ) * 100 )
                : null;
            $result[] = [
                'key'      => $key,
                'gets'     => $stats['gets'],
                'hits'     => $stats['hits'],
                'misses'   => $misses,
                'sets'     => $stats['sets'],
                'deletes'  => $stats['deletes'],
                'hit_rate' => $hit_rate,
            ];
        }
        usort( $result, function ( $a, $b ) {
            return ( $b['gets'] + $b['sets'] ) - ( $a['gets'] + $a['sets'] );
        } );
        return $result;
    }

    /**
     * Builds template hierarchy data: type, ordered candidates, and which was used.
     *
     * @return array
     */
    private static function perf_build_template_data(): array {
        if ( empty( self::$perf_template_hierarchy ) ) {
            return [ 'final' => self::$perf_template, 'hierarchy' => [] ];
        }

        $child_dir  = trailingslashit( get_stylesheet_directory() );
        $parent_dir = trailingslashit( get_template_directory() );
        $is_child   = ( $child_dir !== $parent_dir );

        $hierarchy = [];
        foreach ( self::$perf_template_hierarchy as $entry ) {
            $candidates = [];
            foreach ( $entry['templates'] as $tpl ) {
                if ( file_exists( $child_dir . $tpl ) ) {
                    $found    = true;
                    $location = 'child';
                } elseif ( $is_child && file_exists( $parent_dir . $tpl ) ) {
                    $found    = true;
                    $location = 'parent';
                } else {
                    $found    = false;
                    $location = '';
                }
                $candidates[] = [
                    'file'     => $tpl,
                    'found'    => $found,
                    'location' => $location,
                    'active'   => ( $tpl === self::$perf_template ),
                ];
            }
            $hierarchy[] = [
                'type'       => $entry['type'],
                'candidates' => $candidates,
            ];
        }

        return [
            'final'     => self::$perf_template,
            'hierarchy' => $hierarchy,
        ];
    }

    /* ==================================================================
       PERFORMANCE MONITOR — Query processing
       ================================================================== */

    /**
     * Site health snapshot: autoloaded options bloat, WP-Cron backlog, and
     * security configuration flags. Cheap to compute — one aggregate DB query
     * for autoload size plus in-memory checks for everything else.
     *
     * @return array
     */
    private static function perf_build_health_data(): array {
        global $wpdb;

        // ── Autoloaded options ────────────────────────────────────────────────
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT SUM(LENGTH(option_value)) AS total_bytes, COUNT(*) AS total_count
             FROM {$wpdb->options}
             WHERE autoload = 'yes'",
            ARRAY_A
        );
        $autoload_kb    = $row ? round( (float) $row['total_bytes'] / 1024, 1 ) : 0.0;
        $autoload_count = $row ? (int) $row['total_count'] : 0;

        // Top 5 largest autoloaded options (skip transients — they are ephemeral).
        $top_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT option_name, LENGTH(option_value) AS size_bytes
             FROM {$wpdb->options}
             WHERE autoload = 'yes'
               AND option_name NOT LIKE '\_transient\_%'
               AND option_name NOT LIKE '\_site\_transient\_%'
             ORDER BY size_bytes DESC
             LIMIT 5",
            ARRAY_A
        );
        $large_autoloads = [];
        foreach ( ( $top_rows ?: [] ) as $r ) {
            $large_autoloads[] = [
                'name'    => $r['option_name'],
                'size_kb' => round( (float) $r['size_bytes'] / 1024, 1 ),
            ];
        }

        // ── WP-Cron backlog ───────────────────────────────────────────────────
        $cron_array   = _get_cron_array() ?: [];
        $now          = time();
        $cron_total   = 0;
        $cron_overdue = 0;
        $overdue_list = [];
        foreach ( $cron_array as $timestamp => $hooks ) {
            $cron_total += count( $hooks );
            if ( (int) $timestamp < $now ) {
                foreach ( array_keys( $hooks ) as $hook_name ) {
                    ++$cron_overdue;
                    if ( count( $overdue_list ) < 5 ) {
                        $overdue_list[] = [
                            'hook'            => $hook_name,
                            'overdue_seconds' => $now - (int) $timestamp,
                        ];
                    }
                }
            }
        }

        // ── Security configuration ────────────────────────────────────────────
        $wp_debug_display = defined( 'WP_DEBUG' ) && WP_DEBUG
            && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY );

        // ── Credential / account hygiene ─────────────────────────────────────
        $admin_user_exists = (bool) username_exists( 'admin' );

        // ── Database ──────────────────────────────────────────────────────────
        $db_prefix_default = ( $wpdb->prefix === 'wp_' );

        // ── XML-RPC ───────────────────────────────────────────────────────────
        $xmlrpc_enabled = file_exists( ABSPATH . 'xmlrpc.php' )
            && (bool) apply_filters( 'xmlrpc_enabled', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

        // ── File exposure ─────────────────────────────────────────────────────
        $readme_exposed  = file_exists( ABSPATH . 'readme.html' );
        $license_exposed = file_exists( ABSPATH . 'license.txt' );

        // ── PHP version ───────────────────────────────────────────────────────
        // April 2026: 8.0 EOL Nov 2023, 8.1 EOL Dec 2025, 8.2 EOL Dec 2026, 8.3+ current.
        $php_eol = version_compare( PHP_VERSION, '8.2', '<' ); // EOL — no security patches
        $php_old = ! $php_eol && version_compare( PHP_VERSION, '8.2', '==' ); // 8.2 EOL Dec 2026

        // ── Failed logins (brute-force signal) ────────────────────────────────
        $failed_logins_1h  = (int) get_transient( 'csdt_devtools_failed_logins_1h' );
        $failed_logins_24h = (int) get_transient( 'csdt_devtools_failed_logins_24h' );

        // ── Author enumeration ────────────────────────────────────────────────
        // With pretty permalinks on, /?author=1 redirects to /author/username/.
        // Flag if pretty permalinks are active and no known filter blocks it.
        $author_enum_risk = ! empty( get_option( 'permalink_structure' ) )
            && ! has_filter( 'redirect_canonical', '__return_false' )
            && ! has_action( 'template_redirect', '__return_false' );

        // ── Plugins with pending updates ──────────────────────────────────────
        $plugins_with_updates = self::perf_get_plugin_update_info();

        // ── Disk space ────────────────────────────────────────────────────────
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $disk_free  = function_exists( 'disk_free_space' )  ? @disk_free_space( ABSPATH )  : false;
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $disk_total = function_exists( 'disk_total_space' ) ? @disk_total_space( ABSPATH ) : false;
        $disk_pct_used = ( false !== $disk_free && false !== $disk_total && $disk_total > 0 )
            ? (int) round( ( 1 - $disk_free / $disk_total ) * 100 )
            : null;
        $disk_free_gb = ( false !== $disk_free ) ? round( (float) $disk_free / 1073741824, 1 ) : null;

        // ── OPcache ───────────────────────────────────────────────────────────
        $opcache = null;
        if ( function_exists( 'opcache_get_status' ) ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $oc = @opcache_get_status( false );
            if ( false === $oc ) {
                $opcache = [ 'enabled' => false ];
            } elseif ( is_array( $oc ) ) {
                $oc_used_mb    = round( ( (float) ( $oc['memory_usage']['used_memory']   ?? 0 ) ) / 1048576, 1 );
                $oc_free_mb    = round( ( (float) ( $oc['memory_usage']['free_memory']   ?? 0 ) ) / 1048576, 1 );
                $oc_wasted_mb  = round( ( (float) ( $oc['memory_usage']['wasted_memory'] ?? 0 ) ) / 1048576, 1 );
                $oc_total_mb   = $oc_used_mb + $oc_free_mb + $oc_wasted_mb;
                $oc_hits   = (int) ( $oc['opcache_statistics']['hits']   ?? 0 );
                $oc_misses = (int) ( $oc['opcache_statistics']['misses'] ?? 0 );
                $opcache = [
                    'enabled'         => true,
                    'hit_rate'        => round( (float) ( $oc['opcache_statistics']['opcache_hit_rate']   ?? 0 ), 1 ),
                    'used_mb'         => $oc_used_mb,
                    'free_mb'         => $oc_free_mb,
                    'wasted_mb'       => $oc_wasted_mb,
                    'mem_pct'         => $oc_total_mb > 0 ? (int) round( $oc_used_mb / $oc_total_mb * 100 ) : 0,
                    'oom_restarts'    => (int) ( $oc['opcache_statistics']['oom_restarts']       ?? 0 ),
                    'cached_scripts'  => (int) ( $oc['opcache_statistics']['num_cached_scripts'] ?? 0 ),
                    'total_requests'  => $oc_hits + $oc_misses,
                ];
            }
        }

        // ── PHP limits ────────────────────────────────────────────────────────
        $php_upload_max  = ini_get( 'upload_max_filesize' ) ?: '2M';
        $php_post_max    = ini_get( 'post_max_size' )       ?: '8M';
        $php_max_exec    = (int) ini_get( 'max_execution_time' );

        // ── Uploads directory writable ────────────────────────────────────────
        $upload_info      = wp_upload_dir();
        $uploads_writable = is_writable( $upload_info['basedir'] );

        // ── WordPress core update available ───────────────────────────────────
        $wp_update_available = false;
        $wp_latest_version   = '';
        $update_core = get_site_transient( 'update_core' );
        if ( $update_core && isset( $update_core->updates ) && is_array( $update_core->updates ) ) {
            foreach ( $update_core->updates as $update ) {
                if ( isset( $update->response ) && 'upgrade' === $update->response ) {
                    $wp_update_available = true;
                    $wp_latest_version   = isset( $update->version ) ? (string) $update->version : '';
                    break;
                }
            }
        }

        // ── MySQL / MariaDB full version (db_version() strips MariaDB suffix) ─
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $db_full_version = (string) $wpdb->get_var( 'SELECT VERSION()' );
        $is_mariadb      = false !== stripos( $db_full_version, 'mariadb' );

        // ── Maintenance mode stuck ────────────────────────────────────────────
        $maintenance_file = ABSPATH . '.maintenance';
        $maintenance_stale = false;
        if ( file_exists( $maintenance_file ) ) {
            $mtime = filemtime( $maintenance_file );
            $maintenance_stale = ( false !== $mtime ) && ( time() - $mtime > 600 ); // >10 min
        }

        // ── siteurl / home URL mismatch vs current request host ───────────────
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $current_host    = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
        $siteurl_host    = strtolower( (string) parse_url( get_option( 'siteurl' ), PHP_URL_HOST ) );
        $home_host       = strtolower( (string) parse_url( get_option( 'home' ), PHP_URL_HOST ) );
        $url_host_mismatch = ( '' !== $current_host )
            && ( ( '' !== $siteurl_host && $siteurl_host !== $current_host )
              || ( '' !== $home_host    && $home_host    !== $current_host ) );
        // Also flag if WP_SITEURL / WP_HOME constants conflict with DB values.
        $url_const_override = ( defined( 'WP_SITEURL' ) && WP_SITEURL !== get_option( 'siteurl' ) )
                           || ( defined( 'WP_HOME' )    && WP_HOME    !== get_option( 'home' ) );

        // ── Rewrite rules need flushing ───────────────────────────────────────
        $has_pretty_permalinks = ! empty( get_option( 'permalink_structure' ) );
        $rewrite_rules_missing = $has_pretty_permalinks && empty( get_option( 'rewrite_rules' ) );

        // ── wp-config.php world-readable ─────────────────────────────────────
        $wpconfig_path           = ABSPATH . 'wp-config.php';
        $wpconfig_world_readable = file_exists( $wpconfig_path )
            && ( fileperms( $wpconfig_path ) & 0004 );   // world-readable bit

        // ── debug.log size ────────────────────────────────────────────────────
        $debug_log_mb = null;
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            $log_path = get_option( 'csdt_debug_log_path', '' ) ?: ( is_string( WP_DEBUG_LOG ) ? WP_DEBUG_LOG : ( WP_CONTENT_DIR . '/debug.log' ) );
            if ( file_exists( $log_path ) ) {
                $debug_log_mb = round( (float) filesize( $log_path ) / 1048576, 1 );
            }
        }

        // ── Web server version (from Server header) ───────────────────────────
        $server_software = isset( $_SERVER['SERVER_SOFTWARE'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
            : '';
        $nginx_version  = '';
        $apache_version = '';
        if ( preg_match( '/nginx\/([0-9][0-9.]*)/i', $server_software, $sv_m ) ) {
            $nginx_version = $sv_m[1];
        } elseif ( preg_match( '/Apache\/([0-9][0-9.]*)/i', $server_software, $sv_m ) ) {
            $apache_version = $sv_m[1];
        }

        // ── System load average (Unix only) ───────────────────────────────────
        $load_avg  = function_exists( 'sys_getloadavg' )
            ? array_map( fn( float $v ): float => round( $v, 2 ), sys_getloadavg() )
            : [];
        $cpu_count = 1;
        if ( is_readable( '/proc/cpuinfo' ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $cpuinfo = file_get_contents( '/proc/cpuinfo' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            if ( false !== $cpuinfo ) {
                preg_match_all( '/^processor\s*:/m', $cpuinfo, $cpu_matches );
                $cpu_count = max( 1, count( $cpu_matches[0] ) );
            }
        }

        return [
            'autoload_kb'          => $autoload_kb,
            'autoload_count'       => $autoload_count,
            'large_autoloads'      => $large_autoloads,
            'cron_total'           => $cron_total,
            'cron_overdue'         => $cron_overdue,
            'cron_overdue_list'    => $overdue_list,
            'wp_debug_display'     => $wp_debug_display,
            'disallow_file_edit'   => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
            'disallow_file_mods'   => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
            'site_https'           => strpos( home_url(), 'https://' ) === 0,
            'admin_user_exists'    => $admin_user_exists,
            'db_prefix_default'    => $db_prefix_default,
            'xmlrpc_enabled'       => $xmlrpc_enabled,
            'readme_exposed'       => $readme_exposed,
            'license_exposed'      => $license_exposed,
            'php_eol'              => $php_eol,
            'php_old'              => $php_old,
            'failed_logins_1h'     => $failed_logins_1h,
            'failed_logins_24h'    => $failed_logins_24h,
            'author_enum_risk'     => $author_enum_risk,
            'plugins_with_updates' => $plugins_with_updates,
            'load_avg'             => $load_avg,
            'cpu_count'            => $cpu_count,
            'disk_pct_used'        => $disk_pct_used,
            'disk_free_gb'         => $disk_free_gb,
            'opcache'              => $opcache,
            'php_upload_max'       => $php_upload_max,
            'php_post_max'         => $php_post_max,
            'php_max_exec'         => $php_max_exec,
            'uploads_writable'       => $uploads_writable,
            'maintenance_stale'      => $maintenance_stale,
            'url_host_mismatch'      => $url_host_mismatch,
            'url_const_override'     => $url_const_override,
            'rewrite_rules_missing'  => $rewrite_rules_missing,
            'wpconfig_world_readable'=> (bool) $wpconfig_world_readable,
            'debug_log_mb'           => $debug_log_mb,
            'wp_update_available'    => $wp_update_available,
            'wp_latest_version'      => $wp_latest_version,
            'is_mariadb'             => $is_mariadb,
            'db_full_version'        => $db_full_version,
            'nginx_version'          => $nginx_version,
            'apache_version'         => $apache_version,
            'brute_force_enabled'    => get_option( 'csdt_devtools_brute_force_enabled', '1' ) === '1',
        ];
    }

    /**
     * Fired on wp_login_failed. Increments rolling failed-login counters stored
     * as transients so the CS Monitor can surface brute-force signals.
     *
     * @param string $username The username that failed authentication.
     * @return void
     */
    private static function get_client_ip(): string {
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

    public static function perf_track_failed_login( string $username ): void {
        // 1-hour rolling window.
        $c1h = (int) get_transient( 'csdt_devtools_failed_logins_1h' );
        set_transient( 'csdt_devtools_failed_logins_1h', $c1h + 1, HOUR_IN_SECONDS );
        // 24-hour rolling window.
        $c24h = (int) get_transient( 'csdt_devtools_failed_logins_24h' );
        set_transient( 'csdt_devtools_failed_logins_24h', $c24h + 1, DAY_IN_SECONDS );

        // Persistent 14-day rolling log [timestamp, username, ip, country] — capped at 500 entries.
        $log    = get_option( 'csdt_devtools_bf_log', [] );
        if ( ! is_array( $log ) ) {
            $log = [];
        }
        $cutoff = time() - 14 * DAY_IN_SECONDS;
        $log    = array_values( array_filter( $log, fn( $e ) => isset( $e[0] ) && $e[0] >= $cutoff ) );
        if ( count( $log ) >= 500 ) {
            array_shift( $log );
        }
        $ip      = self::get_client_ip();
        $country = CSDT_Geo::get_country( $ip );

        // If WP passed an empty username (REST API / application-password path),
        // try PHP_AUTH_USER which holds the Basic Auth username they sent.
        $logged_user = $username;
        if ( '' === $logged_user && ! empty( $_SERVER['PHP_AUTH_USER'] ) ) {
            $logged_user = sanitize_user( wp_unslash( $_SERVER['PHP_AUTH_USER'] ), true );
        }
        if ( '' === $logged_user ) {
            $logged_user = '[REST API]';
        }

        $log[]   = [ time(), $logged_user, $ip, $country ];
        update_option( 'csdt_devtools_bf_log', $log, false );

        // Per-country 14-day rolling count for the map.
        if ( $country ) {
            $cc_stats = get_option( 'csdt_bf_country_stats', [] );
            if ( ! is_array( $cc_stats ) ) {
                $cc_stats = [];
            }
            $today = gmdate( 'Y-m-d' );
            if ( ! isset( $cc_stats[ $country ] ) || ! is_array( $cc_stats[ $country ] ) ) {
                $cc_stats[ $country ] = [];
            }
            $cc_stats[ $country ][ $today ] = ( $cc_stats[ $country ][ $today ] ?? 0 ) + 1;
            $cutoff_day = gmdate( 'Y-m-d', strtotime( '-14 days' ) );
            foreach ( $cc_stats as $cc => $days ) {
                foreach ( array_keys( $days ) as $day_key ) {
                    if ( $day_key < $cutoff_day ) unset( $cc_stats[ $cc ][ $day_key ] );
                }
                if ( empty( $cc_stats[ $cc ] ) ) unset( $cc_stats[ $cc ] );
            }
            update_option( 'csdt_bf_country_stats', $cc_stats, false );
        }

        // Per-IP failed login index — keyed by IP for future dashboard use.
        if ( $ip ) {
            $ip_index = get_option( 'csdt_devtools_failed_login_ips', [] );
            if ( ! is_array( $ip_index ) ) {
                $ip_index = [];
            }
            $clean_user = sanitize_user( $username, true );
            $now = time();
            if ( isset( $ip_index[ $ip ] ) ) {
                $ip_index[ $ip ]['count']++;
                $ip_index[ $ip ]['last_seen'] = $now;
                $ip_index[ $ip ]['times'][]   = $now;
                if ( count( $ip_index[ $ip ]['times'] ) > 50 ) {
                    array_shift( $ip_index[ $ip ]['times'] );
                }
                if ( ! in_array( $clean_user, $ip_index[ $ip ]['usernames'], true ) ) {
                    $ip_index[ $ip ]['usernames'][] = $clean_user;
                    if ( count( $ip_index[ $ip ]['usernames'] ) > 20 ) {
                        array_shift( $ip_index[ $ip ]['usernames'] );
                    }
                }
            } else {
                $ip_index[ $ip ] = [
                    'count'      => 1,
                    'first_seen' => $now,
                    'last_seen'  => $now,
                    'times'      => [ $now ],
                    'usernames'  => [ $clean_user ],
                ];
            }
            // Purge IPs not seen in the last 90 days.
            $cutoff_ip = time() - 90 * DAY_IN_SECONDS;
            $ip_index  = array_filter( $ip_index, fn( $e ) => $e['last_seen'] >= $cutoff_ip );
            // Cap at 1000 unique IPs — drop the oldest by last_seen.
            if ( count( $ip_index ) > 1000 ) {
                uasort( $ip_index, fn( $a, $b ) => $a['last_seen'] <=> $b['last_seen'] );
                $ip_index = array_slice( $ip_index, -1000, null, true );
            }
            update_option( 'csdt_devtools_failed_login_ips', $ip_index, false );
        }

        // Track invalid (nonexistent) username attempts separately
        if ( ! username_exists( $username ) ) {
            $inv = get_option( 'csdt_invalid_user_log', [] );
            if ( ! is_array( $inv ) ) $inv = [];
            $inv[] = [ time(), sanitize_user( $username, true ), $ip ];
            if ( count( $inv ) > 200 ) {
                $inv = array_slice( $inv, -200 );
            }
            update_option( 'csdt_invalid_user_log', $inv, false );
        }

        // ── Brute-force per-account lockout ──────────────────────────────────
        if ( get_option( 'csdt_devtools_brute_force_enabled', '1' ) !== '1' || empty( $username ) ) {
            return;
        }
        $max_attempts = max( 1, (int) get_option( 'csdt_devtools_brute_force_attempts', '5' ) );
        $lockout_secs = max( 60, (int) get_option( 'csdt_devtools_brute_force_lockout', '10' ) * MINUTE_IN_SECONDS );
        $slug         = md5( strtolower( $username ) );
        $count_key    = 'csdt_devtools_bf_count_' . $slug;
        $lock_key     = 'csdt_devtools_bf_lock_' . $slug;
        $attempts     = (int) get_transient( $count_key ) + 1;
        if ( $attempts >= $max_attempts ) {
            // Threshold reached — lock the account and clear the counter.
            set_transient( $lock_key, time() + $lockout_secs, $lockout_secs );
            delete_transient( $count_key );

            // Send throttled alert — only for valid accounts (real attack vector), at most once per 2 hrs.
            if ( username_exists( $username ) ) {
                $notif_key = 'csdt_devtools_bf_notif_' . $slug;
                if ( ! get_transient( $notif_key ) ) {
                    set_transient( $notif_key, 1, 2 * HOUR_IN_SECONDS );
                    $site      = wp_specialchars_decode( get_bloginfo( 'name' ) ?: home_url(), ENT_QUOTES );
                    $admin_url = admin_url( 'tools.php?page=' . CloudScale_DevTools::TOOLS_SLUG . '&tab=login' );
                    $subject   = sprintf( '[%s] REAL ACCOUNT under brute-force attack: %s', $site, $username );
                    $body      = sprintf(
                        "Account '%s' is a valid WordPress account and has been locked after %d consecutive failed login attempts.\n\nThis is an active credential-stuffing or brute-force attack targeting a real account.\n\nAccount will be locked for %d minutes. If this recurs, enable 2FA immediately.\n\nView login attempts: %s",
                        $username,
                        $max_attempts,
                        $lockout_secs / MINUTE_IN_SECONDS,
                        $admin_url
                    );
                    CloudScale_DevTools::send_threat_alert( $subject, $body, 'urgent', 'skull,warning', $admin_url );
                }
            }
        } else {
            // Still within the window — keep counting.
            set_transient( $count_key, $attempts, $lockout_secs * 2 );
        }

        // ── Auto IP block after N failures in 1 hour ─────────────────────────
        // Default threshold: 10 failures/hour. Configurable via option.
        // Skips already-blocked IPs and local/private ranges.
        if ( ! $ip ) { return; }
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) { return; }

        $auto_block_threshold = (int) get_option( 'csdt_devtools_bf_auto_block_threshold', '10' );
        if ( $auto_block_threshold < 1 ) { return; }

        // Check if already manually or auto-blocked.
        $blocklist = get_option( 'csdt_ip_blocklist', [] );
        if ( ! is_array( $blocklist ) ) { $blocklist = []; }
        if ( isset( $blocklist[ $ip ] ) ) { return; }

        // Count how many failures this IP had in the last hour using the ip_index times array.
        $ip_index   = get_option( 'csdt_devtools_failed_login_ips', [] );
        $hour_ago   = time() - HOUR_IN_SECONDS;
        $recent_hits = 0;
        if ( isset( $ip_index[ $ip ]['times'] ) ) {
            $recent_hits = count( array_filter( $ip_index[ $ip ]['times'], fn( $t ) => $t >= $hour_ago ) );
        }

        if ( $recent_hits >= $auto_block_threshold ) {
            $blocklist[ $ip ] = [
                'reason'     => sprintf( 'Auto-blocked: %d failed logins in 1 hour', $recent_hits ),
                'blocked_at' => time(),
                'auto'       => true,
            ];
            update_option( 'csdt_ip_blocklist', $blocklist, false );

            // Record security event + ntfy.
            CSDT_Login::record_security_event(
                'attack',
                "IP auto-blocked: {$ip}",
                "Reason: {$recent_hits} failed logins in 1 hour"
            );
            CSDT_Login::send_ntfy(
                "IP auto-blocked: {$ip}",
                "Blocked after {$recent_hits} failed login attempts in 1 hour.\nIP: {$ip}",
                'high',
                'rotating_light'
            );
        }
    }

    /**
     * Returns plugins that have a pending update available, using the cached
     * update_plugins site transient (populated by WP's own update check cron).
     * Never makes a live HTTP call — reads from DB only.
     *
     * @return array  [ { slug, name, current, new_version } ]
     */
    private static function perf_get_plugin_update_info(): array {
        $update_data = get_site_transient( 'update_plugins' );
        if ( ! $update_data || empty( $update_data->response ) ) {
            return [];
        }
        $results = [];
        foreach ( $update_data->response as $plugin_file => $plugin_data ) {
            $current_ver = $update_data->checked[ $plugin_file ] ?? '';
            $slug        = $plugin_data->slug ?? basename( dirname( $plugin_file ) );
            $results[]   = [
                'plugin'      => $plugin_file,
                'slug'        => $slug,
                'current'     => $current_ver,
                'new_version' => $plugin_data->new_version ?? '',
            ];
        }
        // Sort by slug name.
        usort( $results, fn( $a, $b ) => strcmp( $a['slug'], $b['slug'] ) );
        return $results;
    }

    /**
     * Processes $wpdb->queries into a structured array for the panel.
     *
     * @return array
     */
    private static function perf_build_query_data(): array {
        global $wpdb;

        if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES || empty( $wpdb->queries ) ) {
            return [];
        }

        $seen   = [];
        $result = [];

        foreach ( $wpdb->queries as $q ) {
            $sql     = isset( $q[0] ) ? trim( (string) $q[0] ) : '';
            $time_ms = isset( $q[1] ) ? round( (float) $q[1] * 1000, 1 ) : 0.0;
            $bt_str  = isset( $q[2] ) ? (string) $q[2] : '';
            // Index 4 = row count for SELECT queries, rows_affected for write queries.
            $rows = isset( $q[4] ) && is_numeric( $q[4] ) ? (int) $q[4] : -1;

            if ( '' === $sql ) {
                continue;
            }

            // Duplicate detection: normalise whitespace before hashing.
            $hash    = md5( preg_replace( '/\s+/', ' ', strtolower( $sql ) ) );
            $is_dupe = array_key_exists( $hash, $seen );
            if ( ! $is_dupe ) {
                $seen[ $hash ] = true;
            }

            // Extract leading keyword.
            preg_match( '/^\s*(\w+)/i', $sql, $kw );
            $keyword = strtoupper( $kw[1] ?? 'QUERY' );

            $result[] = [
                'sql'     => $sql,
                'keyword' => $keyword,
                'time_ms' => $time_ms,
                'rows'    => $rows,
                'plugin'  => self::perf_plugin_from_query_bt( $bt_str ),
                'caller'  => self::perf_caller_from_query_bt( $bt_str ),
                'stack'   => self::perf_parse_stack( $bt_str ),
                'is_dupe' => $is_dupe,
            ];
        }

        return $result;
    }

    /**
     * Extracts the responsible plugin slug from a SAVEQUERIES backtrace string.
     *
     * wp_debug_backtrace_summary() includes require/include calls with file paths
     * like require('wp-content/plugins/SLUG/...'), so we can extract the slug.
     *
     * @param  string $bt SAVEQUERIES backtrace string.
     * @return string     Plugin directory slug or 'WordPress Core'.
     */
    private static function perf_plugin_from_query_bt( string $bt ): string {
        // Primary: require/include with plugin path (most accurate).
        if ( preg_match( "/(?:require|include)(?:_once)?\(['\"]wp-content\/plugins\/([^\/'\",\)]+)/i", $bt, $m ) ) {
            return $m[1];
        }

        // Fallback: match class/function prefixes against installed plugin slugs.
        $map = self::perf_get_plugin_prefix_map();
        foreach ( $map as $prefix => $slug ) {
            if ( 1 === preg_match( '/\b' . preg_quote( $prefix, '/' ) . '/i', $bt ) ) {
                return $slug;
            }
        }

        return 'WordPress Core';
    }

    /**
     * Returns the most relevant calling function from a SAVEQUERIES backtrace string.
     *
     * Skips internal WordPress and wpdb frames to surface the application-level caller.
     *
     * @param  string $bt Backtrace string.
     * @return string     Caller name (truncated to 70 chars) or empty string.
     */
    private static function perf_caller_from_query_bt( string $bt ): string {
        static $skip = [ 'wpdb', 'WP_Hook', 'do_action', 'apply_filters',
                          'require', 'include', '{main}', 'wp-settings', 'wp-blog-header' ];

        $frames = array_map( 'trim', explode( ',', $bt ) );
        foreach ( $frames as $frame ) {
            if ( '' === $frame ) {
                continue;
            }
            $skip_it = false;
            foreach ( $skip as $s ) {
                if ( false !== stripos( $frame, $s ) ) {
                    $skip_it = true;
                    break;
                }
            }
            if ( ! $skip_it ) {
                return strlen( $frame ) > 70 ? substr( $frame, 0, 67 ) . '...' : $frame;
            }
        }
        return '';
    }

    /**
     * Parses a SAVEQUERIES backtrace string into a typed array of call-chain frames.
     *
     * Each frame is annotated with a type so the JS can colour-code the trace:
     *   hook     — do_action / apply_filters (the WP entry point for this code path)
     *   plugin   — require/include from wp-content/plugins/
     *   theme    — require/include from wp-content/themes/
     *   file     — require/include from WP core
     *   wp       — WP_Hook, call_user_func, {main}
     *   db       — wpdb methods
     *   code     — application-level function or class method (the "real" work)
     *
     * @param  string $bt SAVEQUERIES backtrace string.
     * @return array<int, array{frame: string, type: string}>
     */
    private static function perf_parse_stack( string $bt ): array {
        if ( '' === $bt ) {
            return [];
        }

        $frames = array_values( array_filter( array_map( 'trim', explode( ',', $bt ) ) ) );
        $result = [];

        foreach ( $frames as $frame ) {
            if ( '' === $frame ) {
                continue;
            }

            if ( preg_match( '/^(do_action|apply_filters)\b/i', $frame ) ) {
                $type = 'hook';
            } elseif ( preg_match( '/^WP_Hook\b/i', $frame ) ) {
                $type = 'wp';
            } elseif ( preg_match( '/^(call_user_func|{main})/i', $frame ) ) {
                $type = 'wp';
            } elseif ( preg_match( '/^wpdb\b/i', $frame ) ) {
                $type = 'db';
            } elseif ( preg_match( '/^(require|include)(?:_once)?\(/i', $frame ) ) {
                if ( false !== stripos( $frame, '/plugins/' ) ) {
                    $type = 'plugin';
                } elseif ( false !== stripos( $frame, '/themes/' ) ) {
                    $type = 'theme';
                } else {
                    $type = 'file';
                }
            } else {
                $type = 'code';
            }

            $result[] = [ 'frame' => $frame, 'type' => $type ];
        }

        return $result;
    }

    /**
     * Attributes an HTTP call to a plugin using real debug_backtrace frames with file paths.
     *
     * @param  array $frames debug_backtrace() frames.
     * @return string        Plugin directory slug or 'WordPress Core'.
     */
    private static function perf_plugin_from_frames( array $frames ): string {
        $plugins_dir = wp_normalize_path( WP_PLUGIN_DIR );
        foreach ( $frames as $frame ) {
            if ( empty( $frame['file'] ) ) {
                continue;
            }
            $file = wp_normalize_path( $frame['file'] );
            if ( 0 === strpos( $file, $plugins_dir . '/' ) ) {
                $relative = substr( $file, strlen( $plugins_dir ) + 1 );
                $parts    = explode( '/', $relative );
                if ( ! empty( $parts[0] ) ) {
                    return $parts[0];
                }
            }
        }
        return 'WordPress Core';
    }

    /**
     * Maps an absolute (normalised) file path to a plugin slug, theme slug,
     * or 'WordPress Core'.  Used by perf_callback_info() for hook attribution.
     *
     * @param  string $file wp_normalize_path() output.
     * @return string
     */
    private static function perf_plugin_from_file( string $file ): string {
        if ( '' === $file ) {
            return 'WordPress Core';
        }
        $plugins_dir = wp_normalize_path( WP_PLUGIN_DIR );
        if ( str_starts_with( $file, $plugins_dir . '/' ) ) {
            $relative = substr( $file, strlen( $plugins_dir ) + 1 );
            return explode( '/', $relative )[0] ?? 'WordPress Core';
        }
        $themes_dir = wp_normalize_path( get_theme_root() );
        if ( str_starts_with( $file, $themes_dir . '/' ) ) {
            $relative = substr( $file, strlen( $themes_dir ) + 1 );
            return 'theme: ' . ( explode( '/', $relative )[0] ?? '?' );
        }
        return 'WordPress Core';
    }

    /**
     * Returns a human-readable label and plugin attribution for a hook callback.
     * Uses Reflection to locate the file that defines the callable.
     *
     * @param  mixed $cb Callback (string, array, Closure, invokable object).
     * @return array{label: string, plugin: string}
     */
    private static function perf_callback_info( $cb ): array {
        $label = '';
        $file  = '';
        try {
            if ( $cb instanceof Closure ) {
                $rf    = new ReflectionFunction( $cb );
                $file  = wp_normalize_path( (string) $rf->getFileName() );
                $label = '{closure}:' . $rf->getStartLine();
            } elseif ( is_string( $cb ) && function_exists( $cb ) ) {
                $rf    = new ReflectionFunction( $cb );
                $file  = wp_normalize_path( (string) $rf->getFileName() );
                $label = $cb . '()';
            } elseif ( is_array( $cb ) && 2 === count( $cb ) ) {
                $class  = is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0];
                $method = (string) ( $cb[1] ?? '' );
                $label  = $class . '::' . $method . '()';
                if ( $method && method_exists( $class, $method ) ) {
                    $rm   = new ReflectionMethod( $class, $method );
                    $file = wp_normalize_path( (string) $rm->getFileName() );
                }
            } elseif ( is_object( $cb ) && method_exists( $cb, '__invoke' ) ) {
                $rm    = new ReflectionMethod( $cb, '__invoke' );
                $file  = wp_normalize_path( (string) $rm->getFileName() );
                $label = get_class( $cb ) . '::__invoke()';
            } else {
                $label = is_string( $cb ) ? $cb : '(unknown)';
            }
        } catch ( ReflectionException $e ) {
            if ( is_string( $cb ) ) {
                $label = $cb;
            } elseif ( is_array( $cb ) ) {
                $class = is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0];
                $label = $class . '::' . ( $cb[1] ?? '?' ) . '()';
            }
        }
        return [
            'label'  => $label ?: '(unknown)',
            'plugin' => $file ? self::perf_plugin_from_file( $file ) : 'WordPress Core',
        ];
    }

    /**
     * Builds a map of function/class prefixes to plugin slugs from active plugins.
     *
     * Used as a fallback when file-path attribution is not available.
     *
     * @return array<string, string>
     */
    private static function perf_get_plugin_prefix_map(): array {
        if ( null !== self::$perf_plugin_map ) {
            return self::$perf_plugin_map;
        }
        self::$perf_plugin_map = [];
        foreach ( get_option( 'active_plugins', [] ) as $plugin_file ) {
            $slug = dirname( $plugin_file );
            if ( '.' === $slug ) {
                $slug = basename( $plugin_file, '.php' );
            }
            $snake = str_replace( '-', '_', strtolower( $slug ) );
            foreach ( [ $slug, $snake, strtoupper( $snake ) ] as $prefix ) {
                if ( strlen( $prefix ) > 2 ) {
                    self::$perf_plugin_map[ $prefix ] = $slug;
                }
            }
        }
        return self::$perf_plugin_map;
    }

    /* ==================================================================
       PERFORMANCE MONITOR — EXPLAIN AJAX endpoint
       ================================================================== */

    /**
     * AJAX handler: runs EXPLAIN on a captured SELECT query and returns the plan.
     *
     * Only SELECT, SHOW, and DESCRIBE queries are accepted.
     * Access is restricted to manage_options users via nonce + capability check.
     *
     * @return void  Sends JSON and exits.
     */
    public static function ajax_perf_explain() {
        check_ajax_referer( 'csdt_devtools_perf_explain', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $sql = isset( $_POST['sql'] ) ? trim( wp_unslash( $_POST['sql'] ) ) : '';

        if ( '' === $sql ) {
            wp_send_json_error( 'No SQL provided.' );
        }

        if ( ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE)\s/i', $sql ) ) {
            wp_send_json_error( 'Only SELECT, SHOW, and DESCRIBE queries can be explained.' );
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A );

        if ( $wpdb->last_error ) {
            wp_send_json_error( $wpdb->last_error );
        }

        wp_send_json_success( [ 'rows' => $rows ?: [] ] );
    }

    /**
     * AJAX: toggle WP_DEBUG + WP_DEBUG_LOG + WP_DEBUG_DISPLAY in wp-config.php.
     *
     * Reads the current state, flips it, rewrites the relevant defines in the file.
     * Restricted to manage_options admins.
     *
     * @return void  Sends JSON and exits.
     */
    public static function ajax_perf_debug_toggle() {
        check_ajax_referer( 'csdt_devtools_perf_debug', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $enable = isset( $_POST['enable'] ) ? ( '1' === $_POST['enable'] || 'true' === $_POST['enable'] ) : null;
        if ( null === $enable ) {
            $enable = ! (bool) get_option( 'csdt_devtools_perf_debug_logging', false );
        }

        if ( $enable ) {
            update_option( 'csdt_devtools_perf_debug_logging', 1, false );
        } else {
            delete_option( 'csdt_devtools_perf_debug_logging' );
        }

        wp_send_json_success( [
            'enabled' => $enable,
            'message' => $enable
                ? 'Debug logging enabled. Reload the page to start capturing logs.'
                : 'Debug logging disabled.',
        ] );
    }

    /* ==================================================================
       PERFORMANCE MONITOR — Log data
       ================================================================== */

    /**
     * Reads the WordPress debug.log file (last 500 lines) and merges with
     * in-memory PHP errors captured by perf_error_handler().
     *
     * Each entry: { ts, level, message, source }
     *   source: 'debug_log' | 'php_handler'
     *
     * @return array
     */
    private static function perf_build_log_data(): array {
        $entries = [];

        // ── 1. Read debug.log ──────────────────────────────────────────────────
        $log_file = get_option( 'csdt_debug_log_path', '' ) ?: (
            defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG )
                ? WP_DEBUG_LOG
                : WP_CONTENT_DIR . '/debug.log'
        );

        if ( is_readable( $log_file ) ) {
            $lines = [];
            $fp    = fopen( $log_file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            if ( $fp ) {
                // Read last 600 lines efficiently.
                $all = [];
                while ( ! feof( $fp ) ) {
                    $all[] = fgets( $fp );
                }
                fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                $lines = array_slice( $all, -600 );
            }

            // Merge continuation lines (stack traces) into their parent entry.
            $buffer = '';
            $flush  = function ( string $buf ) use ( &$entries ): void {
                if ( '' === $buf ) return;
                // WordPress debug.log format: [DD-Mon-YYYY HH:MM:SS UTC] PHP Level: message
                if ( preg_match( '/^\[([^\]]+)\]\s+PHP\s+(\w[\w\s]*?):\s+(.*)/s', $buf, $m ) ) {
                    $entries[] = [
                        'ts'      => $m[1],
                        'level'   => strtolower( trim( $m[2] ) ),
                        'message' => trim( $m[3] ),
                        'source'  => 'debug_log',
                    ];
                } elseif ( preg_match( '/^\[([^\]]+)\]\s+(.*)/s', $buf, $m ) ) {
                    $entries[] = [
                        'ts'      => $m[1],
                        'level'   => 'info',
                        'message' => trim( $m[2] ),
                        'source'  => 'debug_log',
                    ];
                }
            };

            foreach ( $lines as $line ) {
                $line = rtrim( (string) $line );
                if ( preg_match( '/^\[/', $line ) && '' !== $buffer ) {
                    $flush( $buffer );
                    $buffer = $line;
                } else {
                    $buffer .= ( '' === $buffer ? '' : "\n" ) . $line;
                }
            }
            $flush( $buffer );
        }

        // ── 2. Merge in-memory PHP errors (captured this request) ──────────────
        foreach ( self::$perf_php_errors as $e ) {
            $entries[] = [
                'ts'      => gmdate( 'd-M-Y H:i:s' ) . ' UTC',
                'level'   => isset( $e['type'] ) ? strtolower( $e['type'] ) : 'notice',
                'message' => ( isset( $e['message'] ) ? $e['message'] : '' )
                             . ( ! empty( $e['file'] ) ? ' in ' . $e['file'] . ':' . ( $e['line'] ?? '' ) : '' ),
                'source'  => 'php_handler',
            ];
        }

        // Sort by timestamp desc — keep last 500.
        $entries = array_slice( $entries, -500 );
        return array_reverse( $entries );
    }

    /* ==================================================================
       PERFORMANCE MONITOR — Panel HTML scaffold
       ================================================================== */

    /**
     * Returns the performance monitor panel HTML.
     *
     * All data rendering is handled client-side by cs-perf-monitor.js
     * which reads window.csdtDevtoolsPerfData.
     *
     * @return string HTML markup.
     */
    private static function perf_panel_html(): string {
        return '<div id="cs-perf" class="cs-perf-collapsed" role="complementary" aria-label="' . esc_attr__( 'CloudScale Performance Monitor', 'cloudscale-devtools' ) . '">'
            . '<div id="cs-perf-resize" title="Drag to resize"></div>'
            . '<div id="cs-perf-header">'
                . '<div class="cs-perf-hl">'
                . '<button id="cs-perf-toggle" class="cs-perf-monitor-btn" title="Toggle panel (Ctrl+Shift+M)" aria-expanded="false">'
                        . '<span class="cs-perf-logo">&#9889;</span>'
                        . '<span class="cs-perf-name">CS&nbsp;Monitor</span>'
                        . '<span class="cs-perf-name-short">CS</span>'
                        . '<span id="cs-perf-toggle-arrow" class="cs-perf-toggle-arrow">&#9650;</span>'
                    . '</button>'
                    . '<span id="cs-pb-db"  class="cs-perf-badge cs-pb-db"  title="Database queries">DB&nbsp;<em>0</em></span>'
                    . '<span id="cs-pb-http" class="cs-perf-badge cs-pb-http" title="HTTP / REST calls">HTTP&nbsp;<em>0</em></span>'
                    . '<span id="cs-pb-log"  class="cs-perf-badge cs-pb-log"  title="Log entries" style="display:none">LOG&nbsp;<em>0</em></span>'
                    . '<span id="cs-pb-issues" class="cs-perf-badge cs-pb-issues-critical" title="Critical / warning issues detected" style="display:none">&#9888;&nbsp;<em>0</em></span>'
                . '</div>'
                . '<div class="cs-perf-hr">'
                    . '<span id="cs-perf-ttl" class="cs-perf-total"></span>'
                    . '<button id="cs-perf-clear" class="cs-perf-btn" title="Clear browser-side errors and issues (page refresh clears DB/HTTP/hook data)">&#10005;&nbsp;Clear</button>'
                    . '<button id="cs-perf-export" class="cs-perf-btn" title="Export data as JSON (download)">&#8595;&nbsp;JSON</button>'
                . '</div>'
            . '</div>'
            . '<div id="cs-perf-body">'
                . '<div id="cs-perf-ctx" aria-label="Page context"></div>'
                . '<div id="cs-perf-tabs" role="tablist">'
                    . '<div id="cs-ptab-scroll">'
                        . '<button class="cs-ptab"         data-tab="issues"  role="tab" aria-selected="false">Issues <span id="cs-ptc-issues">0</span></button>'
                        . '<button class="cs-ptab active"  data-tab="db"      role="tab" aria-selected="true">DB Queries <span id="cs-ptc-db">0</span></button>'
                        . '<button class="cs-ptab"         data-tab="http"    role="tab" aria-selected="false">HTTP / REST <span id="cs-ptc-http">0</span></button>'
                        . '<button class="cs-ptab"         data-tab="logs"    role="tab" aria-selected="false">Logs <span id="cs-ptc-log">0</span></button>'
                        . '<button class="cs-ptab"         data-tab="assets"  role="tab" aria-selected="false">Assets <span id="cs-ptc-assets">0</span></button>'
                        . '<button class="cs-ptab"         data-tab="hooks"   role="tab" aria-selected="false">Hooks <span id="cs-ptc-hooks">0</span></button>'
                        . '<button class="cs-ptab"         data-tab="request"   role="tab" aria-selected="false">Request</button>'
                        . '<button class="cs-ptab"         data-tab="template"  role="tab" aria-selected="false">Template</button>'
                        . '<button class="cs-ptab"         data-tab="transients" role="tab" aria-selected="false">Transients <span id="cs-ptc-trans">0</span></button>'
                        . '<button class="cs-ptab"         data-tab="editor"    role="tab" aria-selected="false">Browser <span id="cs-ptc-editor">0</span></button>'
                        . '<button class="cs-ptab"         data-tab="summary"   role="tab" aria-selected="false">Summary</button>'
                    . '</div>'
                    . '<button id="cs-perf-copy" class="cs-ptab-copy" title="Copy current tab to clipboard">&#128203; Copy</button>'
                . '</div>'
                . '<div id="cs-perf-filters">'
                    . '<input type="search" id="cs-pf-search" placeholder="Filter&#8230;" aria-label="Filter rows">'
                    . '<select id="cs-pf-plugin" aria-label="Filter by plugin"><option value="">All plugins</option></select>'
                    . '<select id="cs-pf-speed" aria-label="Filter by speed">'
                        . '<option value="0">Any speed</option>'
                        . '<option value="10">Slow &gt;10ms</option>'
                        . '<option value="50">Slow &gt;50ms</option>'
                        . '<option value="100">Critical &gt;100ms</option>'
                    . '</select>'
                    . '<label class="cs-pf-dupe-lbl"><input type="checkbox" id="cs-pf-dupe"> Dupes only</label>'
                . '</div>'
                . '<div id="cs-pp-issues" class="cs-ppane" role="tabpanel">'
                    . '<div id="cs-issues-wrap" class="cs-tbl-wrap cs-issues-wrap"></div>'
                . '</div>'
                . '<div id="cs-pp-db" class="cs-ppane active" role="tabpanel">'
                    . '<div class="cs-tbl-wrap">'
                        . '<table class="cs-ptable"><thead><tr>'
                            . '<th class="c-n">#</th>'
                            . '<th class="c-q">Query</th>'
                            . '<th class="c-p cs-sortable" data-sort="plugin">Plugin&nbsp;&#8597;</th>'
                            . '<th class="c-r cs-sortable" data-sort="rows">Rows&nbsp;&#8597;</th>'
                            . '<th class="c-t cs-sortable cs-sort-active" data-sort="time">Time&nbsp;&#8595;</th>'
                        . '</tr></thead><tbody id="cs-db-rows"></tbody></table>'
                    . '</div>'
                . '</div>'
                . '<div id="cs-pp-http" class="cs-ppane" role="tabpanel">'
                    . '<div class="cs-tbl-wrap">'
                        . '<table class="cs-ptable"><thead><tr>'
                            . '<th class="c-n">#</th>'
                            . '<th class="c-m">Method</th>'
                            . '<th class="c-u">URL</th>'
                            . '<th class="c-p">Plugin</th>'
                            . '<th class="c-s">Status</th>'
                            . '<th class="c-t cs-sortable" data-sort="time">Time&nbsp;&#8597;</th>'
                        . '</tr></thead><tbody id="cs-http-rows"></tbody></table>'
                    . '</div>'
                . '</div>'
                . '<div id="cs-pp-logs" class="cs-ppane" role="tabpanel">'
                    . '<div id="cs-debug-bar" class="cs-debug-bar">'
                        . '<span id="cs-debug-status" class="cs-debug-status"></span>'
                        . '<button id="cs-debug-toggle" class="cs-debug-toggle-btn">Enable debug logging</button>'
                        . '<span id="cs-debug-msg" class="cs-debug-msg"></span>'
                    . '</div>'
                    . '<div class="cs-log-filters">'
                        . '<input type="search" id="cs-lf-search" placeholder="Filter logs&#8230;" aria-label="Filter log entries">'
                        . '<select id="cs-lf-level" aria-label="Filter by level">'
                            . '<option value="">All levels</option>'
                            . '<option value="fatal error">Fatal</option>'
                            . '<option value="error">Error</option>'
                            . '<option value="warning">Warning</option>'
                            . '<option value="notice">Notice</option>'
                            . '<option value="deprecated">Deprecated</option>'
                            . '<option value="info">Info</option>'
                        . '</select>'
                        . '<select id="cs-lf-source" aria-label="Filter by source">'
                            . '<option value="">All sources</option>'
                            . '<option value="debug_log">debug.log file</option>'
                            . '<option value="php_handler">This request</option>'
                        . '</select>'
                    . '</div>'
                    . '<div id="cs-log-list" class="cs-log-list"></div>'
                . '</div>'
                . '<div id="cs-pp-assets" class="cs-ppane" role="tabpanel">'
                    . '<div class="cs-assets-filters">'
                        . '<input type="search" id="cs-af-search" placeholder="Filter assets&#8230;" aria-label="Filter assets">'
                        . '<select id="cs-af-type" aria-label="Filter by type">'
                            . '<option value="">JS &amp; CSS</option>'
                            . '<option value="scripts">JS only</option>'
                            . '<option value="styles">CSS only</option>'
                        . '</select>'
                        . '<select id="cs-af-plugin" aria-label="Filter by plugin"><option value="">All plugins</option></select>'
                    . '</div>'
                    . '<div class="cs-tbl-wrap">'
                        . '<table class="cs-ptable"><thead><tr>'
                            . '<th class="c-at">Type</th>'
                            . '<th class="c-ah">Handle</th>'
                            . '<th class="c-ap">Plugin</th>'
                            . '<th class="c-au">Source</th>'
                        . '</tr></thead><tbody id="cs-assets-rows"></tbody></table>'
                    . '</div>'
                . '</div>'
                . '<div id="cs-pp-hooks" class="cs-ppane" role="tabpanel">'
                    . '<div class="cs-hooks-filters">'
                        . '<input type="search" id="cs-hkf-search" placeholder="Filter hooks&#8230;" aria-label="Filter hooks">'
                    . '</div>'
                    . '<div class="cs-tbl-wrap">'
                        . '<table class="cs-ptable"><thead><tr>'
                            . '<th class="c-hk">Hook</th>'
                            . '<th class="c-hc cs-sortable" data-sort="count">Count&nbsp;&#8597;</th>'
                            . '<th class="c-ht cs-sortable cs-sort-hk-time" data-sort="total_ms">Total&nbsp;&#8597;</th>'
                            . '<th class="c-hm cs-sortable" data-sort="max_ms">Max&nbsp;&#8597;</th>'
                        . '</tr></thead><tbody id="cs-hooks-rows"></tbody></table>'
                    . '</div>'
                . '</div>'
                . '<div id="cs-pp-request" class="cs-ppane" role="tabpanel">'
                    . '<div id="cs-request-wrap" class="cs-tbl-wrap cs-request-wrap"></div>'
                . '</div>'
                . '<div id="cs-pp-template" class="cs-ppane" role="tabpanel">'
                    . '<div id="cs-template-wrap" class="cs-tbl-wrap cs-template-wrap"></div>'
                . '</div>'
                . '<div id="cs-pp-transients" class="cs-ppane" role="tabpanel">'
                    . '<div class="cs-tbl-wrap">'
                        . '<table class="cs-ptable"><thead><tr>'
                            . '<th class="c-tk">Transient key</th>'
                            . '<th class="c-tg">Gets</th>'
                            . '<th class="c-th">Hits</th>'
                            . '<th class="c-tm">Misses</th>'
                            . '<th class="c-ts">Sets</th>'
                            . '<th class="c-td">Del</th>'
                            . '<th class="c-tr">Hit&nbsp;%</th>'
                        . '</tr></thead><tbody id="cs-trans-rows"></tbody></table>'
                    . '</div>'
                . '</div>'
                . '<div id="cs-pp-summary" class="cs-ppane" role="tabpanel">'
                    . '<div id="cs-summary-wrap" class="cs-tbl-wrap"></div>'
                . '</div>'
                . '<div id="cs-pp-editor" class="cs-ppane" role="tabpanel">'
                    . '<div id="cs-pp-editor-body" class="cs-tbl-wrap"></div>'
                . '</div>'
                . '<div id="cs-perf-foot"><span id="cs-perf-foot-txt"></span></div>'
            . '</div>'
        . '</div>' . "\n";
    }

}
