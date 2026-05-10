<?php
/**
 * Threat monitor — file integrity, probe detection, admin alerts, brute-force self-test.
 *
 * @package CloudScale_DevTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CSDT_Threat_Monitor {

    public static function monitor_threats(): void {
        if ( get_option( 'csdt_threat_monitor_enabled', '1' ) !== '1' ) {
            return;
        }
        if ( get_option( 'csdt_threat_file_integrity_enabled', '1' ) === '1' ) {
            self::check_file_integrity();
        }
        if ( get_option( 'csdt_threat_probe_enabled', '1' ) === '1' ) {
            self::check_probe_patterns();
        }
        update_option( 'csdt_threat_last_run', time(), false );
    }

    private static function check_file_integrity(): void {
        $abspath    = rtrim( ABSPATH, DIRECTORY_SEPARATOR );
        $wp_version = get_bloginfo( 'version' );
        $baseline   = get_option( 'csdt_file_integrity_baseline', [] );
        $alerted    = get_option( 'csdt_file_integrity_alerted',  [] );
        $saved_ver  = get_option( 'csdt_file_integrity_wp_ver',   '' );

        $scan_files = array_merge(
            [ $abspath . '/wp-config.php', $abspath . '/wp-login.php' ],
            glob( $abspath . '/wp-includes/*.php' ) ?: [],
            glob( $abspath . '/wp-admin/*.php' )    ?: []
        );

        // Build or rebuild baseline (first run, or after a WP core update)
        if ( empty( $baseline ) || $saved_ver !== $wp_version ) {
            $new_baseline = [];
            foreach ( $scan_files as $f ) {
                if ( file_exists( $f ) ) {
                    $new_baseline[ $f ] = (int) filemtime( $f );
                }
            }
            update_option( 'csdt_file_integrity_baseline', $new_baseline, false );
            update_option( 'csdt_file_integrity_wp_ver',   $wp_version,   false );
            update_option( 'csdt_file_integrity_alerted',  [],            false );
            return; // No alert on baseline creation
        }

        $modified = [];
        foreach ( $scan_files as $f ) {
            if ( ! file_exists( $f ) ) {
                continue;
            }
            $current = (int) filemtime( $f );
            $base    = isset( $baseline[ $f ] ) ? (int) $baseline[ $f ] : null;
            $prev    = isset( $alerted[ $f ] )  ? (int) $alerted[ $f ]  : null;

            // New file not in baseline, or mtime changed and we haven't alerted on this mtime yet
            if ( $base === null || ( $current !== $base && $current !== $prev ) ) {
                $modified[ $f ] = $current;
            }
        }

        if ( empty( $modified ) ) {
            return;
        }

        // Record that we've alerted for these mtimes — prevents repeat alerts for the same change
        update_option( 'csdt_file_integrity_alerted', array_merge( $alerted, $modified ), false );

        $site      = get_bloginfo( 'name' ) ?: home_url();
        $admin_url = admin_url( 'tools.php?page=' . CloudScale_DevTools::TOOLS_SLUG . '&tab=security' );
        $count     = count( $modified );
        $file_list = implode( "\n", array_map(
            fn( $f ) => '  ' . str_replace( ABSPATH, '', $f ),
            array_keys( $modified )
        ) );

        $subject = sprintf( 'CSDT: ⚠️ Core file changed (%d) — %s', $count, $site );
        $body    = sprintf(
            "WordPress core file modification detected on %s.\n\n%d file%s changed:\n%s\n\nIf you did not update WordPress or install a plugin, investigate immediately — this may indicate a compromise.\n\nSecurity dashboard: %s",
            home_url(), $count, $count === 1 ? '' : 's', $file_list, $admin_url
        );

        self::send_threat_alert( $subject, $body, 'urgent', 'rotating_light,lock', $admin_url );
        update_option( 'csdt_threat_last_file_alert', [ 'ts' => time(), 'count' => $count, 'files' => array_keys( $modified ) ], false );
    }

    private static function check_probe_patterns(): void {
        $log_candidates = [
            '/var/log/nginx/access.log',
            '/var/log/apache2/access.log',
            '/var/log/httpd/access_log',
            '/var/log/apache2/other_vhosts_access.log',
        ];
        foreach ( CloudScale_DevTools::get_log_sources() as $s ) {
            if ( ! empty( $s['path'] ) && strpos( $s['path'], 'access' ) !== false ) {
                $log_candidates[] = $s['path'];
            }
        }
        $log_path = '';
        foreach ( $log_candidates as $p ) {
            if ( is_readable( $p ) ) { $log_path = $p; break; }
        }
        if ( ! $log_path ) {
            return;
        }

        $threshold = max( 5, (int) get_option( 'csdt_threat_probe_threshold', '25' ) );
        $last_pos  = get_option( 'csdt_threat_probe_last_pos', [] );
        $now       = time();
        $size      = filesize( $log_path );
        $saved     = isset( $last_pos[ $log_path ] ) ? (int) $last_pos[ $log_path ] : null;

        update_option( 'csdt_threat_probe_last_pos', array_merge( $last_pos, [ $log_path => $size ] ), false );

        if ( $saved === null || $saved > $size ) {
            return; // First run or log rotated — just record position
        }
        $unread = $size - $saved;
        if ( $unread <= 0 ) {
            return;
        }

        $handle = is_readable( $log_path ) ? fopen( $log_path, 'rb' ) : false;
        if ( ! $handle ) {
            return;
        }
        fseek( $handle, $size - min( $unread, 524288 ) );
        $chunk = fread( $handle, min( $unread, 524288 ) );
        fclose( $handle );

        if ( ! $chunk ) {
            return;
        }

        $sensitive = [ 'wp-login.php', 'xmlrpc.php', 'wp-config', '.env', '/.git/', '/.svn/', '.sql', '.bak', '.dump', 'eval(', 'base64_', 'cmd=', 'exec=' ];
        $count     = 0;
        foreach ( explode( "\n", $chunk ) as $line ) {
            foreach ( $sensitive as $pat ) {
                if ( strpos( $line, $pat ) !== false ) {
                    $count++;
                    break;
                }
            }
        }

        if ( $count < $threshold ) {
            return;
        }

        // Throttle: one alert per hour
        $last_alert = (int) get_option( 'csdt_threat_probe_last_alert', 0 );
        if ( ( $now - $last_alert ) < 3600 ) {
            return;
        }
        update_option( 'csdt_threat_probe_last_alert', $now, false );

        $site      = get_bloginfo( 'name' ) ?: home_url();
        $admin_url = admin_url( 'tools.php?page=' . CloudScale_DevTools::TOOLS_SLUG . '&tab=security' );
        $subject   = sprintf( 'CSDT: 🔍 Probe — %d sensitive reqs (%s)', $count, $site );
        $body      = sprintf(
            "%d requests to sensitive paths (wp-login, xmlrpc, .env, .git, etc.) detected in the last 5 minutes on %s.\n\nThis indicates active scanning or an attack. Consider blocking the source IP via fail2ban or Cloudflare.\n\nSecurity dashboard: %s",
            $count, home_url(), $admin_url
        );
        self::send_threat_alert( $subject, $body, 'high', 'warning,shield', $admin_url );
        update_option( 'csdt_threat_last_probe_alert', [ 'ts' => $now, 'count' => $count ], false );
    }

    public static function on_user_registered( int $user_id ): void {
        if ( get_option( 'csdt_threat_monitor_enabled', '1' ) !== '1' ) return;
        if ( get_option( 'csdt_threat_new_admin_enabled', '1' ) !== '1' ) return;
        $user = get_userdata( $user_id );
        if ( ! $user || ! in_array( 'administrator', (array) $user->roles, true ) ) return;
        self::alert_new_admin( $user );
    }

    public static function on_set_user_role( int $user_id, string $new_role, array $old_roles ): void {
        if ( get_option( 'csdt_threat_monitor_enabled', '1' ) !== '1' ) return;
        if ( get_option( 'csdt_threat_new_admin_enabled', '1' ) !== '1' ) return;
        if ( $new_role !== 'administrator' ) return;
        if ( in_array( 'administrator', $old_roles, true ) ) return;
        $user = get_userdata( $user_id );
        if ( ! $user ) return;
        self::alert_new_admin( $user );
    }

    private static function alert_new_admin( \WP_User $user ): void {
        $alerted = get_option( 'csdt_threat_alerted_admins', [] );
        if ( in_array( $user->ID, $alerted, true ) ) return;
        $alerted[] = $user->ID;
        if ( count( $alerted ) > 100 ) {
            $alerted = array_slice( $alerted, -100 );
        }
        update_option( 'csdt_threat_alerted_admins', $alerted, false );

        // Detect creation method
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            $method = 'WP-CLI / SSH';
        } elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            $method = 'REST API';
        } elseif ( ! empty( $_SERVER['REQUEST_URI'] ) && strpos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '/wp-admin/' ) !== false ) {
            $method = 'Admin UI';
        } else {
            $method = 'Programmatic';
        }

        // Detect test/automation accounts
        $test_patterns = [ 'playwright', 'helpdocs', 'test_', 'e2e_', 'cypress', 'selenium' ];
        $is_test       = false;
        foreach ( $test_patterns as $p ) {
            if ( stripos( $user->user_login, $p ) !== false || stripos( $user->user_email, $p ) !== false ) {
                $is_test = true;
                break;
            }
        }
        $test_flag = $is_test ? ' [TEST]' : '';

        $site      = wp_specialchars_decode( get_bloginfo( 'name' ) ?: home_url(), ENT_QUOTES );
        $admin_url = admin_url( 'users.php' );
        $subject   = sprintf( 'CSDT: 🚨 New admin %s%s (%s)', $user->user_login, $test_flag, $site );
        $body      = sprintf(
            "A new administrator account was created on %s.\n\nUsername: %s\nEmail: %s\nCreated via: %s\nRegistered: %s\n\n%sIf you did not create this account, revoke it immediately.\n\nManage users: %s",
            home_url(), $user->user_login, $user->user_email, $method, $user->user_registered,
            $is_test ? "This appears to be a TEST/automation account.\n\n" : '',
            $admin_url
        );
        self::send_threat_alert( $subject, $body, 'urgent', 'rotating_light,bust_in_silhouette', $admin_url );
        update_option( 'csdt_threat_last_admin_alert', [ 'ts' => time(), 'login' => $user->user_login, 'email' => $user->user_email ], false );
    }

    private static function send_threat_alert( string $subject, string $body, string $priority, string $tags, string $click_url ): void {
        if ( get_option( 'csdt_scan_schedule_email', '1' ) === '1' ) {
            add_filter( 'wp_mail_content_type', [ 'CSDT_Login', 'email_content_type_html' ] );
            wp_mail( get_option( 'admin_email' ), $subject, nl2br( esc_html( $body ) ) );
            remove_filter( 'wp_mail_content_type', [ 'CSDT_Login', 'email_content_type_html' ] );
        }
        $ntfy_url = get_option( 'csdt_scan_schedule_ntfy_url', '' );
        if ( $ntfy_url ) {
            $headers = [ 'Title' => $subject, 'Priority' => $priority, 'Tags' => $tags, 'Click' => $click_url ];
            $ntfy_tok = get_option( 'csdt_scan_schedule_ntfy_token', '' );
            if ( $ntfy_tok ) {
                $headers['Authorization'] = 'Bearer ' . $ntfy_tok;
            }
            wp_remote_post( $ntfy_url, [ 'timeout' => 10, 'headers' => $headers, 'body' => $body ] );
        }
    }

    public static function ajax_threat_monitor_save(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $enabled   = ( $_POST['enabled']         ?? '0' ) === '1' ? '1' : '0';
        $file_int  = ( $_POST['file_integrity']  ?? '0' ) === '1' ? '1' : '0';
        $new_admin = ( $_POST['new_admin']        ?? '0' ) === '1' ? '1' : '0';
        $probe     = ( $_POST['probe']            ?? '0' ) === '1' ? '1' : '0';
        $threshold = max( 5, min( 500, (int) ( $_POST['probe_threshold'] ?? 25 ) ) );

        update_option( 'csdt_threat_monitor_enabled',          $enabled,            true );
        update_option( 'csdt_threat_file_integrity_enabled',   $file_int,           true );
        update_option( 'csdt_threat_new_admin_enabled',        $new_admin,          true );
        update_option( 'csdt_threat_probe_enabled',            $probe,              true );
        update_option( 'csdt_threat_probe_threshold',          (string) $threshold, true );

        if ( $enabled === '1' ) {
            if ( ! wp_next_scheduled( 'csdt_threat_monitor' ) ) {
                wp_schedule_event( time() + 300, 'csdt_every_5min', 'csdt_threat_monitor' );
            }
        } else {
            wp_clear_scheduled_hook( 'csdt_threat_monitor' );
        }
        wp_send_json_success();
    }

    public static function ajax_threat_integrity_reset(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        delete_option( 'csdt_file_integrity_baseline' );
        delete_option( 'csdt_file_integrity_wp_ver' );
        delete_option( 'csdt_file_integrity_alerted' );
        wp_send_json_success( [ 'message' => 'Baseline cleared. A new baseline will be built on the next cron run (within 5 minutes).' ] );
    }

    // ── SSH Brute-Force Monitor ───────────────────────────────────────────────

    public static function ajax_bf_self_test(): void {
        check_ajax_referer( CloudScale_DevTools::LOGIN_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $max_attempts = max( 1, (int) get_option( 'csdt_devtools_brute_force_attempts', '5' ) );
        $lockout_mins = max( 1, (int) get_option( 'csdt_devtools_brute_force_lockout', '10' ) );
        $lockout_secs = $lockout_mins * MINUTE_IN_SECONDS;
        $bf_enabled   = get_option( 'csdt_devtools_brute_force_enabled', '1' ) === '1';

        if ( ! $bf_enabled ) {
            wp_send_json_error( 'Brute-force protection is disabled — enable it first.' );
        }

        // Use a synthetic username — no real user created.
        $test_user = 'csdt_bf_selftest_' . time();
        $slug      = md5( strtolower( $test_user ) );
        $count_key = 'csdt_devtools_bf_count_' . $slug;
        $lock_key  = 'csdt_devtools_bf_lock_' . $slug;

        // Simulate max_attempts consecutive failed logins (mirrors perf_track_failed_login logic).
        for ( $i = 1; $i <= $max_attempts; $i++ ) {
            $attempts = (int) get_transient( $count_key ) + 1;
            if ( $attempts >= $max_attempts ) {
                set_transient( $lock_key, time() + $lockout_secs, $lockout_secs );
                delete_transient( $count_key );
            } else {
                set_transient( $count_key, $attempts, $lockout_secs * 2 );
            }
        }

        $locked_until = (int) get_transient( $lock_key );
        $is_locked    = $locked_until > time();

        // Send a clearly-labelled test notification if ntfy is configured.
        $ntfy_url     = get_option( 'csdt_scan_schedule_ntfy_url', '' );
        $notif_sent   = false;
        if ( $is_locked && $ntfy_url ) {
            $site    = wp_specialchars_decode( get_bloginfo( 'name' ) ?: home_url(), ENT_QUOTES );
            $subject = sprintf( 'CSDT: ✅ BF test passed (%s)', $site );
            $body    = sprintf(
                "Self-test result: PASS\n\nBrute-force lockout fired correctly after %d failed attempts.\nTest account: %s\nLockout duration: %d minutes\n\nThis is a self-test message — no action needed.",
                $max_attempts, $test_user, $lockout_mins
            );
            self::send_threat_alert( $subject, $body, 'default', 'white_check_mark,lock', admin_url( 'tools.php?page=' . CloudScale_DevTools::TOOLS_SLUG . '&tab=login' ) );
            $notif_sent = true;
        }

        // Clean up all test transients.
        delete_transient( $count_key );
        delete_transient( $lock_key );
        delete_transient( 'csdt_devtools_bf_notif_' . $slug );

        wp_send_json_success( [
            'passed'        => $is_locked,
            'attempts'      => $max_attempts,
            'lockout_mins'  => $lockout_mins,
            'notif_sent'    => $notif_sent,
            'ntfy_url'      => ! empty( $ntfy_url ),
            'clear_cmd'     => "wp transient delete csdt_devtools_bf_lock_\$(php -r \"echo md5(strtolower('USERNAME'));\") --path=/var/www/html",
        ] );
    }

}
