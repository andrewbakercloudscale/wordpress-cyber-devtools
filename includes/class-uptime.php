<?php
/**
 * Uptime monitor — Cloudflare Worker ping with alert/recovery notifications.
 *
 * @package CloudScale_DevTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CSDT_Uptime {

    // ── REST API — Readiness Probe ───────────────────────────────────────────

    public static function get_readiness_url(): string { return self::readiness_url(); }

    private static function readiness_url(): string {
        $slug = sanitize_key( (string) get_option( 'csdt_readiness_slug', '' ) );
        // Primary path: csdt/cf-callback/{slug} — allows WAF wildcard exclusion on /wp-json/csdt/cf-callback/*
        return rest_url( 'csdt/cf-callback/' . ( $slug ?: 'ready' ) );
    }

    public static function register_rest_routes(): void {
        $perm = static function ( \WP_REST_Request $request ): bool {
            // Slug guard — URL slug must match stored slug (or 'ready' when no slug is configured).
            $stored_slug = sanitize_key( (string) get_option( 'csdt_readiness_slug', '' ) );
            $url_slug    = sanitize_key( (string) ( $request->get_param( 'slug' ) ?? '' ) );
            if ( ( $stored_slug ?: 'ready' ) !== $url_slug ) { return false; }
            // Bearer-token or ?token= auth.
            $stored = (string) get_option( 'csdt_uptime_token', '' );
            if ( $stored === '' ) { return false; }
            $auth  = $request->get_header( 'Authorization' ) ?? '';
            $token = str_starts_with( $auth, 'Bearer ' ) ? substr( $auth, 7 ) : (string) ( $request->get_param( 'token' ) ?? '' );
            if ( ! hash_equals( $stored, $token ) ) {
                update_option( 'csdt_readiness_last_bad_auth', time(), false );
                return false;
            }
            return true;
        };
        $args = [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'rest_readiness_probe' ],
            'permission_callback' => $perm,
        ];
        // Primary route — WAF-excluded namespace
        register_rest_route( 'csdt/cf-callback', '/(?P<slug>[a-zA-Z0-9_-]+)', $args );
        // Legacy routes kept for backward compat with already-deployed workers
        register_rest_route( 'csdt/v1', '/ready', $args );
        register_rest_route( 'csdt/v1', '/ready/(?P<slug>[a-zA-Z0-9_-]+)', $args );
    }

    public static function rest_readiness_probe( \WP_REST_Request $request ): \WP_REST_Response {
        $checks = self::run_readiness_checks();
        $all_ok = ! in_array( false, array_column( $checks, 'ok' ), true );
        $now    = time();

        update_option( 'csdt_readiness_last_queried',       $now,   false );
        update_option( 'csdt_readiness_last_queried_checks', $checks, false );

        return new \WP_REST_Response( [
            'ok'         => $all_ok,
            'checks'     => $checks,
            'site'       => get_site_url(),
            'checked_at' => $now,
        ], $all_ok ? 200 : 503 );
    }

    private static function run_readiness_checks(): array {
        global $wpdb;
        $checks = [];

        // DB check
        $db_ok = false;
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $result = $wpdb->get_var( 'SELECT 1' );
            $db_ok  = ( '1' === (string) $result );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
        }
        $checks['db'] = [ 'ok' => $db_ok, 'message' => $db_ok ? 'Connected' : 'Query failed' ];

        // PHP-FPM saturation check
        $fpm_ok   = true;
        $fpm_info = [];
        $probe    = rtrim( get_option( 'csdt_fpm_probe_url', '' ), '/' );
        if ( $probe ) {
            $resp = wp_remote_get( $probe . '/fpm-status?json', [ 'timeout' => 3, 'sslverify' => false ] );
            if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) ) {
                $body = json_decode( wp_remote_retrieve_body( $resp ), true );
                if ( is_array( $body ) ) {
                    $active  = (int) ( $body['active processes'] ?? 0 );
                    $total   = (int) ( $body['total processes']  ?? 0 );
                    $sat     = $total > 0 ? (int) round( $active / $total * 100 ) : 0;
                    $fpm_ok  = $sat < 90;
                    $fpm_info = [ 'active' => $active, 'total' => $total, 'saturation_pct' => $sat ];
                }
            }
        }
        $checks['fpm'] = array_merge( [ 'ok' => $fpm_ok ], $fpm_info );

        // WordPress (implicit — reaching this code means WP booted)
        $checks['wp'] = [ 'ok' => true, 'version' => get_bloginfo( 'version' ) ];

        return $checks;
    }

    public static function admin_bar_badge_styles(): void {
        if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) return;
        $css = '#wp-admin-bar-csdt-health>.ab-item{font-weight:700!important}'
             . '#wp-admin-bar-csdt-health.csdt-bar-critical>.ab-item{color:#fca5a5!important}'
             . '#wp-admin-bar-csdt-health.csdt-bar-high>.ab-item{color:#fdba74!important}'
             . '#wp-admin-bar-csdt-health.csdt-bar-medium>.ab-item{color:#fde68a!important}'
             . '#wp-admin-bar-csdt-health.csdt-bar-ok>.ab-item{color:#86efac!important}';
        wp_add_inline_style( 'admin-bar', $css );
    }

    public static function render_admin_bar_badge( \WP_Admin_Bar $bar ): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $audit_url  = admin_url( 'tools.php?page=' . CloudScale_DevTools::TOOLS_SLUG . '&tab=site-audit' );
        $uptime_url = admin_url( 'tools.php?page=' . CloudScale_DevTools::TOOLS_SLUG . '&tab=optimizer' );

        $cache    = get_option( 'csdt_site_audit_cache', null );
        $css_cls  = 'csdt-bar-unknown';
        $label    = 'CS Health';

        if ( $cache && ! empty( $cache['data']['counts'] ) ) {
            $counts   = $cache['data']['counts'];
            $critical = (int) ( $counts['critical'] ?? 0 );
            $high     = (int) ( $counts['high']     ?? 0 );
            $medium   = (int) ( $counts['medium']   ?? 0 );

            if ( $critical > 0 ) {
                $label   = 'CS ↯ ' . $critical . ' Critical';
                $css_cls = 'csdt-bar-critical';
            } elseif ( $high > 0 ) {
                $label   = 'CS ↯ ' . $high . ' High';
                $css_cls = 'csdt-bar-high';
            } elseif ( $medium > 0 ) {
                $label   = 'CS ' . $medium . ' Medium';
                $css_cls = 'csdt-bar-medium';
            } else {
                $label   = 'CS ✓ OK';
                $css_cls = 'csdt-bar-ok';
            }
        }

        $bar->add_node( [
            'id'    => 'csdt-health',
            'title' => esc_html( $label ),
            'href'  => $audit_url,
            'meta'  => [ 'class' => $css_cls, 'title' => 'CloudScale Site Health' ],
        ] );

        if ( $cache && ! empty( $cache['data']['counts'] ) ) {
            $counts   = $cache['data']['counts'];
            $critical = (int) ( $counts['critical'] ?? 0 );
            $high     = (int) ( $counts['high']     ?? 0 );
            $medium   = (int) ( $counts['medium']   ?? 0 );
            $low      = (int) ( $counts['low']      ?? 0 );
            $run_at   = $cache['run_at'] ?? 0;
            $age_min  = $run_at ? round( ( time() - $run_at ) / 60 ) : null;

            if ( $critical > 0 ) {
                $bar->add_node( [ 'parent' => 'csdt-health', 'id' => 'csdt-health-crit',   'title' => '🔴 ' . $critical . ' Critical', 'href' => $audit_url ] );
            }
            if ( $high > 0 ) {
                $bar->add_node( [ 'parent' => 'csdt-health', 'id' => 'csdt-health-high',   'title' => '🟠 ' . $high . ' High',        'href' => $audit_url ] );
            }
            if ( $medium > 0 ) {
                $bar->add_node( [ 'parent' => 'csdt-health', 'id' => 'csdt-health-med',    'title' => '🟡 ' . $medium . ' Medium',    'href' => $audit_url ] );
            }
            if ( $low > 0 ) {
                $bar->add_node( [ 'parent' => 'csdt-health', 'id' => 'csdt-health-low',    'title' => '🟢 ' . $low . ' Low',          'href' => $audit_url ] );
            }
            if ( $age_min !== null ) {
                $age_label = $age_min < 60 ? $age_min . 'm ago' : round( $age_min / 60 ) . 'h ago';
                $bar->add_node( [ 'parent' => 'csdt-health', 'id' => 'csdt-health-age', 'title' => 'Last audit: ' . $age_label, 'href' => $audit_url ] );
            }
        } else {
            $bar->add_node( [ 'parent' => 'csdt-health', 'id' => 'csdt-health-run', 'title' => 'Run Site Audit →', 'href' => $audit_url ] );
        }

        // Uptime node
        $last_ping = get_option( 'csdt_uptime_last_ping', null );
        if ( $last_ping && isset( $last_ping['time'] ) && ( time() - $last_ping['time'] ) < 300 ) {
            $up_label = $last_ping['up']
                ? '⏱ UP ' . $last_ping['ms'] . 'ms'
                : '🔴 SITE DOWN';
            $bar->add_node( [ 'parent' => 'csdt-health', 'id' => 'csdt-health-uptime', 'title' => $up_label, 'href' => $uptime_url ] );
        }
    }

    // ── Uptime Monitor ───────────────────────────────────────────────────────

    private static function record_ping( int $status_code, int $response_ms ): void {
        $is_up = $status_code >= 200 && $status_code < 500;
        $now   = time();
        update_option( 'csdt_uptime_last_ping', [
            'time'   => $now,
            'up'     => $is_up,
            'ms'     => $response_ms,
            'status' => $status_code,
        ], false );
        $raw   = get_option( 'csdt_uptime_raw', [] );
        $raw[] = [ 't' => $now, 'up' => $is_up ? 1 : 0, 'ms' => $response_ms, 's' => $status_code ];
        if ( count( $raw ) > 180 ) { $raw = array_slice( $raw, -180 ); }
        update_option( 'csdt_uptime_raw', $raw, false );
        self::uptime_aggregate_hourly( $now, $is_up, $response_ms );
    }

    // ── WP-Cron heartbeat push ───────────────────────────────────────────────

    public static function add_cron_schedules( array $schedules ): array {
        if ( ! isset( $schedules['csdt_minutely'] ) ) {
            $schedules['csdt_minutely'] = [ 'interval' => 600, 'display' => 'Every 10 Minutes (CloudScale)' ];
        }
        return $schedules;
    }

    public static function push_heartbeat(): void {
        // Paused for Test Alert — skip heartbeat so CF Worker will detect site as down.
        if ( time() < (int) get_option( 'csdt_uptime_pause_until', 0 ) ) { return; }

        $worker_url = (string) get_option( 'csdt_uptime_worker_url', '' );
        $token      = (string) get_option( 'csdt_uptime_token', '' );
        if ( $worker_url === '' || $token === '' ) { return; }

        $start = microtime( true );
        $resp  = wp_remote_post( rtrim( $worker_url, '/' ), [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'    => 'action=csdt_heartbeat',
            'timeout' => 4,
        ] );
        $ms   = (int) round( ( microtime( true ) - $start ) * 1000 );
        $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );

        // Record a ping: site is up (WP-Cron is running), log Worker reachability as response
        self::record_ping( $code === 200 ? 200 : ( $code ?: 0 ), $ms );
    }

    // ── Legacy ping receiver (kept for backward compat with old workers) ─────

    public static function ajax_uptime_ping(): void {
        $token        = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
        $stored_token = (string) get_option( 'csdt_uptime_token', '' );

        if ( $stored_token === '' || ! hash_equals( $stored_token, $token ) ) {
            wp_send_json_error( 'Invalid token', 403 );
            return;
        }

        $status_code = absint( $_POST['status_code'] ?? 0 );
        $response_ms = absint( $_POST['response_ms'] ?? 0 );

        self::record_ping( $status_code, $response_ms );
        wp_send_json_success( [ 'ok' => true ] );
    }

    public static function ajax_uptime_setup(): void {
        check_ajax_referer( CloudScale_DevTools::OPTIMIZER_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $token = (string) get_option( 'csdt_uptime_token', '' );
        if ( $token === '' ) {
            $token = bin2hex( random_bytes( 24 ) );
            update_option( 'csdt_uptime_token', $token, false );
        }

        $site_url = get_site_url();
        $ntfy_url = (string) get_option( 'csdt_uptime_ntfy_url', get_option( 'csdt_scan_schedule_ntfy_url', '' ) );

        wp_send_json_success( [
            'token'         => $token,
            'worker_js'     => self::uptime_worker_js(),
            'wrangler_toml' => self::uptime_wrangler_toml( $site_url, $token, $ntfy_url ),
        ] );
    }

    public static function ajax_uptime_history(): void {
        check_ajax_referer( CloudScale_DevTools::OPTIMIZER_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        // If the caller wants a live push (e.g. Refresh button), send a heartbeat now.
        $pushed = false;
        if ( ! empty( $_POST['push'] ) ) {
            self::push_heartbeat();
            $pushed = true;
        }

        $last_ping = get_option( 'csdt_uptime_last_ping', null );
        $raw       = get_option( 'csdt_uptime_raw', [] );
        $hourly    = get_option( 'csdt_uptime_hourly', [] );

        // Uptime % calculations
        $uptime_24h = null;
        $uptime_7d  = null;
        $avg_ms_24h = null;
        $cutoff_24h = time() - DAY_IN_SECONDS;
        $cutoff_7d  = time() - ( 7 * DAY_IN_SECONDS );

        if ( ! empty( $hourly ) ) {
            $h24_ok = 0; $h24_total = 0; $h24_ms = 0;
            $h7d_ok = 0; $h7d_total = 0;
            foreach ( $hourly as $h ) {
                if ( $h['h'] >= $cutoff_24h ) {
                    $h24_ok    += $h['ok'];
                    $h24_total += $h['total'];
                    $h24_ms    += $h['avg_ms'] * $h['total'];
                }
                if ( $h['h'] >= $cutoff_7d ) {
                    $h7d_ok    += $h['ok'];
                    $h7d_total += $h['total'];
                }
            }
            if ( $h24_total > 0 ) {
                $uptime_24h = round( $h24_ok / $h24_total * 100, 2 );
                $avg_ms_24h = round( $h24_ms / $h24_total );
            }
            if ( $h7d_total > 0 ) {
                $uptime_7d = round( $h7d_ok / $h7d_total * 100, 2 );
            }
        }

        if ( $last_ping ) {
            $last_ping['age_seconds'] = time() - $last_ping['time'];
        }

        wp_send_json_success( [
            'last_ping'   => $last_ping,
            'raw'         => $raw,
            'hourly'      => array_values( array_slice( $hourly, -48 ) ),
            'uptime_24h'  => $uptime_24h,
            'uptime_7d'   => $uptime_7d,
            'avg_ms_24h'  => $avg_ms_24h,
            'enabled'     => get_option( 'csdt_uptime_enabled', '0' ) === '1',
            'worker_url'  => get_option( 'csdt_uptime_worker_url', '' ),
            'pushed'      => $pushed,
            'pause_until' => (int) get_option( 'csdt_uptime_pause_until', 0 ),
        ] );
    }

    public static function ajax_uptime_pause_heartbeat(): void {
        check_ajax_referer( CloudScale_DevTools::OPTIMIZER_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $cancel = ! empty( $_POST['cancel'] );
        if ( $cancel ) {
            delete_option( 'csdt_uptime_pause_until' );
            wp_send_json_success( [ 'paused' => false, 'pause_until' => 0 ] );
            return;
        }

        $until = time() + 5 * MINUTE_IN_SECONDS;
        update_option( 'csdt_uptime_pause_until', $until, false );
        wp_send_json_success( [ 'paused' => true, 'pause_until' => $until ] );
    }

    public static function ajax_uptime_save_settings(): void {
        check_ajax_referer( CloudScale_DevTools::OPTIMIZER_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $ntfy_url = esc_url_raw( wp_unslash( $_POST['ntfy_url'] ?? '' ) );
        update_option( 'csdt_uptime_ntfy_url', $ntfy_url, false );

        $zone_id = sanitize_text_field( wp_unslash( $_POST['cf_zone_id'] ?? '' ) );
        // Only update if a real value was submitted (not the masked placeholder)
        if ( $zone_id !== '' && strpos( $zone_id, '•' ) === false ) {
            update_option( 'csdt_devtools_cf_zone_id', $zone_id, false );
        }
        $api_token = sanitize_text_field( wp_unslash( $_POST['cf_api_token'] ?? '' ) );
        // Only update if a real value was submitted (not the masked placeholder)
        if ( $api_token !== '' && strpos( $api_token, '•' ) === false ) {
            update_option( 'csdt_devtools_cf_api_token', $api_token, false );
        }

        $slug = sanitize_key( wp_unslash( $_POST['ready_slug'] ?? '' ) );
        update_option( 'csdt_readiness_slug', $slug, false );
        wp_send_json_success( [ 'saved' => true, 'ready_url' => self::readiness_url() ] );
    }

    public static function ajax_uptime_test_endpoint(): void {
        check_ajax_referer( CloudScale_DevTools::OPTIMIZER_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $token      = (string) get_option( 'csdt_uptime_token', '' );
        $worker_url = (string) get_option( 'csdt_uptime_worker_url', '' );

        if ( $token === '' ) {
            wp_send_json_error( [ 'message' => 'No token set — deploy the Worker first.' ] );
            return;
        }
        if ( $worker_url === '' ) {
            $worker_url = self::resolve_worker_url();
            if ( $worker_url !== '' ) {
                update_option( 'csdt_uptime_worker_url', $worker_url, true );
            }
        }
        if ( $worker_url === '' ) {
            wp_send_json_error( [ 'message' => 'Worker URL not found — deploy the Worker first.' ] );
            return;
        }

        // Send a heartbeat to the Worker, verify it's accepted
        $start = microtime( true );
        $resp  = wp_remote_post( rtrim( $worker_url, '/' ), [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'    => 'action=csdt_heartbeat',
            'timeout' => 6,
        ] );
        $ms   = (int) round( ( microtime( true ) - $start ) * 1000 );
        $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'message' => 'Could not reach Worker: ' . $resp->get_error_message() ] );
            return;
        }
        if ( $code === 401 ) {
            wp_send_json_error( [ 'message' => 'Worker rejected the token — redeploy to re-sync.' ] );
            return;
        }

        self::record_ping( $code === 200 ? 200 : $code, $ms );

        // Also read back the watchdog state
        $state_resp = wp_remote_post( rtrim( $worker_url, '/' ), [
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
            'body'    => '',
            'timeout' => 10,
        ] );
        $state = [];
        if ( ! is_wp_error( $state_resp ) ) {
            $state = json_decode( wp_remote_retrieve_body( $state_resp ), true ) ?: [];
        }

        wp_send_json_success( [
            'via'         => 'direct',
            'ok'          => $code === 200,
            'status_code' => $code,
            'ms'          => $ms,
            'worker_url'  => $worker_url,
            'stale'       => $state['stale'] ?? null,
        ] );
    }

    public static function ajax_uptime_deploy_worker(): void {
        check_ajax_referer( CloudScale_DevTools::OPTIMIZER_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $zone_id  = (string) get_option( 'csdt_devtools_cf_zone_id', '' );
        $cf_token = (string) get_option( 'csdt_devtools_cf_api_token', '' );
        $ntfy_url = esc_url_raw( wp_unslash( $_POST['ntfy_url'] ?? '' ) );

        if ( $ntfy_url ) { update_option( 'csdt_uptime_ntfy_url', $ntfy_url, false ); }

        if ( $zone_id === '' || $cf_token === '' ) {
            wp_send_json_error( [ 'message' => 'No Cloudflare Zone ID or API Token found. Enter them in the Thumbnails tab first, then return here to deploy.' ] );
            return;
        }

        // Ensure token exists
        $token = (string) get_option( 'csdt_uptime_token', '' );
        if ( $token === '' ) {
            $token = bin2hex( random_bytes( 24 ) );
            update_option( 'csdt_uptime_token', $token, false );
        }

        $site_url = get_site_url();

        // Step 1: Resolve Account ID from zone
        $zone_resp = wp_remote_get(
            'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone_id ),
            [ 'headers' => [ 'Authorization' => 'Bearer ' . $cf_token ], 'timeout' => 15 ]
        );
        if ( is_wp_error( $zone_resp ) ) {
            wp_send_json_error( [ 'message' => 'CF API error: ' . $zone_resp->get_error_message() ] );
            return;
        }
        $zone_data  = json_decode( wp_remote_retrieve_body( $zone_resp ), true );
        $account_id = $zone_data['result']['account']['id'] ?? '';
        if ( ! $account_id ) {
            wp_send_json_error( [ 'message' => 'Could not fetch zone details. Check your CF API token has Zone:Read permission.' ] );
            return;
        }

        // Step 2a: Create or reuse KV namespace for watchdog state
        $kv_id = (string) get_option( 'csdt_uptime_kv_id', '' );
        if ( $kv_id === '' ) {
            $kv_resp = wp_remote_post(
                "https://api.cloudflare.com/client/v4/accounts/{$account_id}/storage/kv/namespaces",
                [
                    'headers' => [ 'Authorization' => 'Bearer ' . $cf_token, 'Content-Type' => 'application/json' ],
                    'body'    => wp_json_encode( [ 'title' => 'csdt-uptime-state' ] ),
                    'timeout' => 15,
                ]
            );
            if ( ! is_wp_error( $kv_resp ) ) {
                $kv_data = json_decode( wp_remote_retrieve_body( $kv_resp ), true );
                $kv_id   = $kv_data['result']['id'] ?? '';
            }
            // If title already taken, find it in the list
            if ( $kv_id === '' ) {
                $list_resp = wp_remote_get(
                    "https://api.cloudflare.com/client/v4/accounts/{$account_id}/storage/kv/namespaces?per_page=100",
                    [ 'headers' => [ 'Authorization' => 'Bearer ' . $cf_token ], 'timeout' => 10 ]
                );
                if ( ! is_wp_error( $list_resp ) ) {
                    $namespaces = json_decode( wp_remote_retrieve_body( $list_resp ), true )['result'] ?? [];
                    foreach ( $namespaces as $ns ) {
                        if ( ( $ns['title'] ?? '' ) === 'csdt-uptime-state' ) {
                            $kv_id = $ns['id'];
                            break;
                        }
                    }
                }
            }
            if ( $kv_id === '' ) {
                wp_send_json_error( [ 'message' => 'Could not create KV namespace. Ensure your API token has Workers KV Storage:Edit permission.' ] );
                return;
            }
            update_option( 'csdt_uptime_kv_id', $kv_id, false );
        }

        // Step 2b: Upload Worker (module syntax + env bindings)
        $boundary = '---CSDTWorkerBnd' . bin2hex( random_bytes( 8 ) );
        $metadata = wp_json_encode( [
            'main_module'        => 'worker.js',
            'compatibility_date' => '2024-11-01',
            'bindings'           => [
                [ 'type' => 'plain_text',   'name' => 'SITE_URL',   'text'         => $site_url ],
                [ 'type' => 'plain_text',   'name' => 'PING_TOKEN', 'text'         => $token    ],
                [ 'type' => 'plain_text',   'name' => 'NTFY_URL',   'text'         => $ntfy_url ],
                [ 'type' => 'kv_namespace', 'name' => 'STATE',      'namespace_id' => $kv_id   ],
            ],
        ] );
        $script_js = self::uptime_worker_js();
        $body  = "--{$boundary}\r\nContent-Disposition: form-data; name=\"metadata\"\r\nContent-Type: application/json\r\n\r\n{$metadata}\r\n";
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"worker.js\"; filename=\"worker.js\"\r\nContent-Type: application/javascript+module\r\n\r\n{$script_js}\r\n";
        $body .= "--{$boundary}--\r\n";

        $upload_resp = wp_remote_request(
            "https://api.cloudflare.com/client/v4/accounts/{$account_id}/workers/scripts/cloudscale-uptime",
            [
                'method'  => 'PUT',
                'headers' => [
                    'Authorization' => 'Bearer ' . $cf_token,
                    'Content-Type'  => "multipart/form-data; boundary={$boundary}",
                ],
                'body'    => $body,
                'timeout' => 30,
            ]
        );

        if ( is_wp_error( $upload_resp ) ) {
            wp_send_json_error( [ 'message' => 'Worker upload failed: ' . $upload_resp->get_error_message() ] );
            return;
        }
        $upload_data = json_decode( wp_remote_retrieve_body( $upload_resp ), true );
        if ( empty( $upload_data['success'] ) ) {
            $err = $upload_data['errors'][0]['message'] ?? 'Upload failed';
            wp_send_json_error( [ 'message' => $err . ' — ensure your CF API token has Workers:Edit permission. You can create one at dash.cloudflare.com → My Profile → API Tokens → Create Token → Edit Cloudflare Workers template.' ] );
            return;
        }

        // Step 3: Set cron trigger (every minute)
        $cron_resp = wp_remote_request(
            "https://api.cloudflare.com/client/v4/accounts/{$account_id}/workers/scripts/cloudscale-uptime/schedules",
            [
                'method'  => 'PUT',
                'headers' => [ 'Authorization' => 'Bearer ' . $cf_token, 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [ [ 'cron' => '* * * * *' ] ] ),
                'timeout' => 15,
            ]
        );
        $cron_ok = ! is_wp_error( $cron_resp ) && ! empty( json_decode( wp_remote_retrieve_body( $cron_resp ), true )['success'] );

        update_option( 'csdt_uptime_enabled', '1', true );

        // Step 4: Get workers.dev subdomain for the Test button and WP-Cron heartbeat target
        $subdomain_resp = wp_remote_get(
            "https://api.cloudflare.com/client/v4/accounts/{$account_id}/workers/subdomain",
            [ 'headers' => [ 'Authorization' => 'Bearer ' . $cf_token ], 'timeout' => 10 ]
        );
        $worker_trigger_url = '';
        if ( ! is_wp_error( $subdomain_resp ) ) {
            $sub_data  = json_decode( wp_remote_retrieve_body( $subdomain_resp ), true );
            $subdomain = $sub_data['result']['subdomain'] ?? '';
            if ( $subdomain ) {
                $worker_trigger_url = "https://cloudscale-uptime.{$subdomain}.workers.dev";
                update_option( 'csdt_uptime_worker_url', $worker_trigger_url, true );
            }
        }

        // Schedule WP-Cron heartbeat (reschedule to pick up new worker URL)
        wp_clear_scheduled_hook( 'csdt_uptime_heartbeat' );
        wp_schedule_event( time() + 10, 'csdt_minutely', 'csdt_uptime_heartbeat' );

        wp_send_json_success( [
            'message'        => 'Worker deployed! WordPress will push heartbeats every 60 seconds.',
            'cf_worker_url'  => "https://dash.cloudflare.com/{$account_id}/workers/view/cloudscale-uptime",
            'worker_url'     => $worker_trigger_url,
            'cron_ok'        => $cron_ok,
            'token'          => $token,
        ] );
    }

    private static function uptime_aggregate_hourly( int $now, bool $is_up, int $ms ): void {
        $hour    = $now - ( $now % 3600 );
        $hourly  = get_option( 'csdt_uptime_hourly', [] );
        $updated = false;
        foreach ( $hourly as &$h ) {
            if ( $h['h'] === $hour ) {
                $h['total']++;
                if ( $is_up ) $h['ok']++;
                $h['avg_ms'] = (int) round( ( $h['avg_ms'] * ( $h['total'] - 1 ) + $ms ) / $h['total'] );
                $updated = true;
                break;
            }
        }
        unset( $h );
        if ( ! $updated ) {
            $hourly[] = [ 'h' => $hour, 'total' => 1, 'ok' => $is_up ? 1 : 0, 'avg_ms' => $ms ];
        }
        if ( count( $hourly ) > 168 ) { $hourly = array_slice( $hourly, -168 ); }
        update_option( 'csdt_uptime_hourly', $hourly, false );
    }

    private static function resolve_worker_url(): string {
        $zone_id  = (string) get_option( 'csdt_devtools_cf_zone_id', '' );
        $cf_token = (string) get_option( 'csdt_devtools_cf_api_token', '' );
        if ( $zone_id === '' || $cf_token === '' ) { return ''; }

        $zone_resp  = wp_remote_get( 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone_id ),
            [ 'headers' => [ 'Authorization' => 'Bearer ' . $cf_token ], 'timeout' => 10 ] );
        if ( is_wp_error( $zone_resp ) ) { return ''; }
        $account_id = json_decode( wp_remote_retrieve_body( $zone_resp ), true )['result']['account']['id'] ?? '';
        if ( ! $account_id ) { return ''; }

        $sub_resp = wp_remote_get( "https://api.cloudflare.com/client/v4/accounts/{$account_id}/workers/subdomain",
            [ 'headers' => [ 'Authorization' => 'Bearer ' . $cf_token ], 'timeout' => 10 ] );
        if ( is_wp_error( $sub_resp ) ) { return ''; }
        $subdomain = json_decode( wp_remote_retrieve_body( $sub_resp ), true )['result']['subdomain'] ?? '';
        return $subdomain ? "https://cloudscale-uptime.{$subdomain}.workers.dev" : '';
    }

    private static function uptime_worker_js(): string {
        return <<<'JS'
// CloudScale Uptime Monitor — heartbeat watchdog
// WordPress pushes a POST heartbeat every 3 minutes via WP-Cron.
// If no heartbeat arrives for >8 minutes, the site is treated as down.
const STALE_MS=8*60*1000,ALERT_COOL=30*60*1000;
async function watchdog(env,ctx){
  const now=Date.now();
  const[hbStr,dsStr,laStr]=await Promise.all([env.STATE.get('hb'),env.STATE.get('ds'),env.STATE.get('la')]);
  const lastHb=hbStr?parseInt(hbStr,10):0,downSince=dsStr?parseInt(dsStr,10):0,lastAlert=laStr?parseInt(laStr,10):0;
  const stale=!lastHb||(now-lastHb)>STALE_MS;
  if(stale){
    const since=downSince||now,ops=[];
    if(!downSince)ops.push(env.STATE.put('ds',String(now)));
    if(now-lastAlert>ALERT_COOL){ops.push(notify(env,false,Math.round((now-since)/1000)));ops.push(env.STATE.put('la',String(now)));}
    ctx.waitUntil(Promise.all(ops));
  } else if(downSince){
    ctx.waitUntil(Promise.all([notify(env,true,Math.round((now-downSince)/1000)),env.STATE.delete('ds'),env.STATE.put('la','0')]));
  }
  return{stale,lastHb,downSince};
}
async function notify(env,recovered,downSecs){
  if(!env.NTFY_URL)return;
  const dur=downSecs>0?fmtSecs(downSecs):null;
  return fetch(env.NTFY_URL,{method:'POST',headers:{Title:(recovered?'Site Recovered: ':'Site Down: ')+env.SITE_URL,Priority:recovered?'default':'urgent',Tags:recovered?'white_check_mark':'rotating_light'},body:recovered?'Back online'+(dur?' — was down '+dur:''):'No heartbeat received for '+(dur||'8m+')}).catch(()=>{});
}
function fmtSecs(s){const m=Math.floor(s/60);return m>0?m+'m '+(s%60)+'s':s+'s';}
export default{
  async scheduled(event,env,ctx){ctx.waitUntil(watchdog(env,ctx));},
  async fetch(request,env,ctx){
    if(request.method!=='POST')return new Response('Method Not Allowed',{status:405});
    if((request.headers.get('Authorization')||'')!=='Bearer '+env.PING_TOKEN)return new Response('Unauthorized',{status:401});
    const params=new URLSearchParams(await request.text());
    if(params.get('action')==='csdt_heartbeat'){await env.STATE.put('hb',String(Date.now()));return new Response(JSON.stringify({ok:true}),{headers:{'Content-Type':'application/json'}});}
    const state=await watchdog(env,ctx);
    return new Response(JSON.stringify({ok:!state.stale,...state,triggered:true}),{headers:{'Content-Type':'application/json'}});
  },
};
JS;
    }

    private static function uptime_wrangler_toml( string $site_url, string $token, string $ntfy_url ): string {
        return "name = \"cloudscale-uptime\"\nmain = \"worker.js\"\ncompatibility_date = \"2024-11-01\"\n\n[vars]\nSITE_URL = \"{$site_url}\"\nPING_TOKEN = \"{$token}\"\nNTFY_URL = \"{$ntfy_url}\"\n\n# STATE KV namespace must be created first:\n# wrangler kv:namespace create csdt-uptime-state\n# Then add to wrangler.toml:\n# [[kv_namespaces]]\n# binding = \"STATE\"\n# id = \"<namespace_id>\"\n\n[[triggers.crons]]\ncrons = [\"* * * * *\"]\n";
    }

}
