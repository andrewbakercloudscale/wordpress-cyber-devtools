<?php
/**
 * SSH failure monitor, PHP error monitor, and PHP-FPM worker monitor.
 *
 * @package CloudScale_DevTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CSDT_Monitor {

    public static function ajax_ssh_fix_permissions(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }

        if ( ! function_exists( 'exec' ) ) {
            wp_send_json_error( 'exec() is disabled on this server — run the command manually.' );
        }

        // Add www-data to adm group (try with sudo first, then direct for root-in-container).
        $out = []; $rc = 1;
        exec( 'sudo usermod -a -G adm www-data 2>&1', $out, $rc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- hardcoded OS group management; no WP Filesystem equivalent
        if ( $rc !== 0 ) {
            $out = []; $rc = 1;
            exec( 'usermod -a -G adm www-data 2>&1', $out, $rc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- hardcoded OS group management; no WP Filesystem equivalent
        }
        if ( $rc !== 0 ) {
            wp_send_json_error( 'usermod failed: ' . implode( ' ', $out ) );
        }

        // Reload php-fpm so the new group membership takes effect.
        // Try several service names used across distros / Docker setups.
        foreach ( [ 'php-fpm', 'php8.2-fpm', 'php8.1-fpm', 'php8.3-fpm' ] as $svc ) {
            $tmp = []; $src = 0;
            exec( 'sudo systemctl restart ' . $svc . ' 2>&1', $tmp, $src ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- hardcoded service list; no WP Filesystem equivalent for systemctl
            if ( $src === 0 ) { break; }
        }
        if ( $src !== 0 ) {
            // Inside Docker, systemctl won't work — send SIGUSR2 to php-fpm master.
            $pid_files = glob( '/var/run/php/php*-fpm.pid' );
            if ( $pid_files ) {
                $pid = (int) file_get_contents( $pid_files[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local OS PID file
                if ( $pid > 0 ) { posix_kill( $pid, SIGUSR2 ); }
            }
        }

        // Re-check readability after attempting restart.
        clearstatcache( true );
        $readable = false;
        foreach ( [ '/var/log/auth.log', '/var/log/secure', '/var/log/messages' ] as $p ) {
            if ( is_readable( $p ) ) { $readable = true; break; }
        }

        wp_send_json_success( [
            'readable' => $readable,
            'note'     => $readable
                ? 'Auth log is now readable — refresh the page to activate monitoring.'
                : 'Group updated. If the log is still unreadable, PHP-FPM may need a full restart to pick up the new group membership.',
        ] );
    }

    public static function ajax_ssh_log_clear(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        delete_option( 'csdt_ssh_monitor_alert_log' );
        wp_send_json_success();
    }

    public static function ajax_ssh_monitor_save(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $enabled   = ( $_POST['enabled']   ?? '0' ) === '1' ? '1' : '0';
        $threshold = max( 1, min( 1000, (int) ( $_POST['threshold'] ?? 10 ) ) );
        update_option( 'csdt_ssh_monitor_enabled',   $enabled,   false );
        update_option( 'csdt_ssh_monitor_threshold', $threshold, false );

        if ( $enabled === '1' ) {
            if ( ! wp_next_scheduled( 'csdt_ssh_monitor' ) ) {
                wp_schedule_event( time() + 60, 'csdt_every_1min', 'csdt_ssh_monitor' );
            }
        } else {
            wp_clear_scheduled_hook( 'csdt_ssh_monitor' );
        }
        wp_send_json_success();
    }

    public static function monitor_ssh_failures(): void {
        if ( get_option( 'csdt_ssh_monitor_enabled', '1' ) !== '1' ) {
            return;
        }

        // Find a readable auth log
        $auth_log = '';
        foreach ( [ '/var/log/auth.log', '/var/log/secure', '/var/log/messages' ] as $p ) {
            if ( is_readable( $p ) ) { $auth_log = $p; break; }
        }
        if ( ! $auth_log ) {
            return; // log not accessible — silently skip
        }

        // Read the last 256 KB (enough for several minutes of auth activity)
        $handle = is_readable( $auth_log ) ? fopen( $auth_log, 'r' ) : false;
        if ( ! $handle ) {
            return;
        }
        fseek( $handle, 0, SEEK_END );
        $size = ftell( $handle );
        if ( $size === 0 ) {
            fclose( $handle );
            return;
        }
        $read  = min( 262144, $size );
        fseek( $handle, $size - $read );
        $chunk = fread( $handle, $read );
        fclose( $handle );

        if ( ! $chunk ) {
            return;
        }

        $window    = 60; // seconds — check the last 60 seconds on each 1-minute cron tick
        $threshold = (int) get_option( 'csdt_ssh_monitor_threshold', '10' );
        $now       = time();
        $failures  = [];

        // Match: "Failed password", "Invalid user", "Connection closed by invalid user", "authentication failure"
        $pattern = '/^(\w{3}\s+\d+\s[\d:]+)\s+\S+\s+sshd\[\d+\]:\s+(?:Failed password|Invalid user|Connection closed by invalid user|authentication failure).*/m';

        foreach ( explode( "\n", $chunk ) as $line ) {
            if ( ! preg_match( $pattern, $line, $m ) ) {
                continue;
            }
            // Parse syslog timestamp (no year — assume current year, roll back if in future)
            $ts = strtotime( $m[1] );
            if ( $ts === false ) {
                continue;
            }
            // Syslog has no year — if timestamp is in the future, it's last year
            if ( $ts > $now + 60 ) {
                $ts = strtotime( $m[1] . ' ' . ( (int) gmdate( 'Y' ) - 1 ) );
            }
            if ( $ts !== false && ( $now - $ts ) <= $window ) {
                $failures[] = trim( $line );
            }
        }

        $count = count( $failures );

        // Extract targeted usernames from failure lines
        $user_counts = [];
        foreach ( $failures as $line ) {
            $username = '';
            if ( preg_match( '/Failed password for (?:invalid user )?(\S+)\s+from/i', $line, $um ) ) {
                $username = $um[1];
            } elseif ( preg_match( '/Invalid user (\S+)\s+from/i', $line, $um ) ) {
                $username = $um[1];
            } elseif ( preg_match( '/Connection closed by invalid user (\S+)\s/i', $line, $um ) ) {
                $username = $um[1];
            } elseif ( preg_match( '/\buser=(\S+)/', $line, $um ) ) {
                $username = $um[1];
            }
            if ( $username && $username !== 'for' && $username !== 'by' ) {
                $user_counts[ $username ] = ( $user_counts[ $username ] ?? 0 ) + 1;
            }
        }

        // Store recent failure data for the Quick Fixes panel display
        update_option( 'csdt_ssh_monitor_last_check', [
            'ts'    => $now,
            'count' => $count,
            'lines' => array_slice( $failures, -20 ), // keep last 20 for display
        ], false );

        if ( $count < $threshold ) {
            return;
        }

        // Throttle: don't alert more than once per 5 minutes (attacks move fast)
        $last_alert = (int) get_option( 'csdt_ssh_monitor_last_alert', 0 );
        if ( ( $now - $last_alert ) < 300 ) {
            return;
        }
        update_option( 'csdt_ssh_monitor_last_alert', $now, false );

        // Append to alert log (keep last 50 events)
        arsort( $user_counts );
        $alert_log   = get_option( 'csdt_ssh_monitor_alert_log', [] );
        $alert_log[] = [ 'ts' => $now, 'count' => $count, 'users' => $user_counts ];
        if ( count( $alert_log ) > 50 ) {
            $alert_log = array_slice( $alert_log, -50 );
        }
        update_option( 'csdt_ssh_monitor_alert_log', $alert_log, false );

        // Build alert message
        $site      = get_bloginfo( 'name' ) ?: home_url();
        $admin_url = admin_url( 'tools.php?page=' . CloudScale_DevTools::TOOLS_SLUG . '&tab=security' );
        $subject   = sprintf( 'CSDT: 🚨 SSH Brute-Force — %d failures (%s)', $count, $site );
        $body      = sprintf(
            "SSH brute-force attack detected on %s\n\n%d failed SSH login attempts in the last 60 seconds.\n\nRecent failures:\n%s\n\nInstall fail2ban immediately to block attacking IPs automatically.\nQuick Fixes: %s",
            $site,
            $count,
            implode( "\n", array_slice( $failures, -5 ) ),
            $admin_url
        );

        // Email alert
        if ( get_option( 'csdt_scan_schedule_email', '1' ) === '1' ) {
            $to = get_option( 'csdt_notify_email', '' ) ?: get_option( 'admin_email' );
            wp_mail( $to, $subject, $body );
        }

        // ntfy.sh push notification
        $ntfy_url = get_option( 'csdt_scan_schedule_ntfy_url', '' );
        if ( $ntfy_url ) {
            $headers = [
                'Title'    => $subject,
                'Priority' => 'urgent',
                'Tags'     => 'rotating_light,computer',
                'Click'    => $admin_url,
            ];
            $ntfy_tok = get_option( 'csdt_scan_schedule_ntfy_token', '' );
            if ( $ntfy_tok ) {
                $headers['Authorization'] = 'Bearer ' . $ntfy_tok;
            }
            wp_remote_post( $ntfy_url, [
                'timeout' => 10,
                'headers' => $headers,
                'body'    => $body,
            ] );
        }
    }

    public static function monitor_php_errors(): void {
        if ( get_option( 'csdt_php_error_monitor_enabled', '1' ) !== '1' ) {
            return;
        }

        $sources     = CloudScale_DevTools::get_log_sources();
        $watch_keys  = [ 'php_error', 'wp_debug' ];
        $last_pos    = get_option( 'csdt_php_error_last_pos', [] );
        $new_pos     = $last_pos;
        $new_lines   = [];
        $fatal_lines = [];
        $now         = time();
        $is_first_run = empty( $last_pos );

        foreach ( $watch_keys as $key ) {
            if ( empty( $sources[ $key ]['path'] ) ) {
                continue;
            }
            $path = $sources[ $key ]['path'];
            if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
                continue;
            }

            $size     = filesize( $path );
            $saved    = isset( $last_pos[ $key ] ) ? (int) $last_pos[ $key ] : null;
            $new_pos[ $key ] = $size;

            // First run or file truncated — just record position, don't alert
            if ( $saved === null || $saved > $size ) {
                continue;
            }

            $unread = $size - $saved;
            if ( $unread <= 0 ) {
                continue;
            }

            // Cap at 128 KB of new content per source to avoid memory issues
            $read_bytes = min( $unread, 131072 );
            $handle = is_readable( $path ) ? fopen( $path, 'rb' ) : false;
            if ( ! $handle ) {
                continue;
            }
            fseek( $handle, $size - $read_bytes );
            $chunk = fread( $handle, $read_bytes );
            fclose( $handle );

            if ( ! $chunk ) {
                continue;
            }

            foreach ( explode( "\n", $chunk ) as $line ) {
                $line = trim( $line );
                if ( ! $line ) {
                    continue;
                }
                $lower = strtolower( $line );
                if ( strpos( $lower, 'fatal' ) !== false || strpos( $lower, 'critical' ) !== false ) {
                    $fatal_lines[] = $line;
                } elseif ( strpos( $lower, 'php error' ) !== false || preg_match( '/\bPHP (?:Warning|Parse error|Error)\b/i', $line ) ) {
                    $new_lines[] = $line;
                }
            }
        }

        update_option( 'csdt_php_error_last_pos', $new_pos, false );

        if ( $is_first_run || ( empty( $fatal_lines ) && empty( $new_lines ) ) ) {
            return;
        }

        $threshold = (int) get_option( 'csdt_php_error_monitor_threshold', '1' );
        $has_alert = ! empty( $fatal_lines ) || count( $new_lines ) >= $threshold;

        if ( ! $has_alert ) {
            return;
        }

        // Throttle: max one alert per 15 minutes
        $last_alert = (int) get_option( 'csdt_php_error_monitor_last_alert', 0 );
        if ( ( $now - $last_alert ) < 900 ) {
            return;
        }
        update_option( 'csdt_php_error_monitor_last_alert', $now, false );

        $site      = get_bloginfo( 'name' ) ?: home_url();
        $debug_url = admin_url( 'tools.php?page=' . CloudScale_DevTools::TOOLS_SLUG . '&tab=debug' );
        $is_fatal  = ! empty( $fatal_lines );
        $all_new   = array_merge( $fatal_lines, $new_lines );
        $excerpt   = implode( "\n", array_slice( $all_new, 0, 5 ) );

        if ( $is_fatal ) {
            $subject = sprintf( 'CSDT: PHP Fatal — %s', $site );
            $priority = 'urgent';
            $tags     = 'rotating_light,computer';
        } else {
            $subject = sprintf( 'CSDT: %d PHP error%s — %s', count( $new_lines ), count( $new_lines ) === 1 ? '' : 's', $site );
            $priority = 'high';
            $tags     = 'warning,computer';
        }

        $body = sprintf(
            "%s on %s\n\nRecent entries:\n%s\n\nOpen Diagnostics to analyze: %s",
            $subject,
            home_url(),
            $excerpt,
            $debug_url
        );

        // Email
        add_filter( 'wp_mail_content_type', [ 'CSDT_Login', 'email_content_type_html' ] );
        wp_mail( get_option( 'admin_email' ), $subject, nl2br( esc_html( $body ) ) );
        remove_filter( 'wp_mail_content_type', [ 'CSDT_Login', 'email_content_type_html' ] );

        // ntfy.sh
        $ntfy_url = get_option( 'csdt_scan_schedule_ntfy_url', '' );
        if ( $ntfy_url ) {
            $headers = [
                'Title'    => $subject,
                'Priority' => $priority,
                'Tags'     => $tags,
                'Click'    => $debug_url,
            ];
            $ntfy_tok = get_option( 'csdt_scan_schedule_ntfy_token', '' );
            if ( $ntfy_tok ) {
                $headers['Authorization'] = 'Bearer ' . $ntfy_tok;
            }
            wp_remote_post( $ntfy_url, [
                'timeout' => 10,
                'headers' => $headers,
                'body'    => $excerpt,
            ] );
        }

        update_option( 'csdt_php_error_monitor_last_trigger', [
            'ts'      => $now,
            'fatal'   => count( $fatal_lines ),
            'errors'  => count( $new_lines ),
            'excerpt' => $excerpt,
        ], false );
    }

    public static function ajax_php_error_monitor_save(): void {
        check_ajax_referer( CloudScale_DevTools::DEBUG_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $enabled   = isset( $_POST['enabled'] ) && $_POST['enabled'] === '1' ? '1' : '0';
        $threshold = max( 1, min( 50, (int) ( $_POST['threshold'] ?? 1 ) ) );
        update_option( 'csdt_php_error_monitor_enabled',   $enabled,           false );
        update_option( 'csdt_php_error_monitor_threshold', (string) $threshold, false );
        if ( $enabled === '1' ) {
            if ( ! wp_next_scheduled( 'csdt_php_error_monitor' ) ) {
                wp_schedule_event( time() + 300, 'csdt_every_5min', 'csdt_php_error_monitor' );
            }
            // Reset position so first run doesn't re-scan old log
            delete_option( 'csdt_php_error_last_pos' );
        } else {
            wp_clear_scheduled_hook( 'csdt_php_error_monitor' );
        }
        wp_send_json_success( [ 'enabled' => $enabled, 'threshold' => $threshold ] );
    }

    public static function ajax_fpm_monitor_save(): void {
        check_ajax_referer( CloudScale_DevTools::FPM_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $enabled          = isset( $_POST['enabled'] ) && $_POST['enabled'] === '1' ? '1' : '0';
        $threshold        = max( 1, min( 30,    (int) ( $_POST['threshold']       ?? 3    ) ) );
        $cooldown         = max( 60, min( 86400, (int) ( $_POST['cooldown']       ?? 1800 ) ) );
        $timeout          = max( 1, min( 30,    (int) ( $_POST['probe_timeout']   ?? 5    ) ) );
        $auto_restart     = isset( $_POST['auto_restart'] ) && $_POST['auto_restart'] === '1' ? '1' : '0';
        $restart_cooldown = max( 60, min( 86400, (int) ( $_POST['restart_cooldown'] ?? 1200 ) ) );
        update_option( 'csdt_fpm_enabled',          $enabled,                                                                         false );
        update_option( 'csdt_fpm_threshold',         (string) $threshold,                                                             false );
        update_option( 'csdt_fpm_cooldown',          (string) $cooldown,                                                              false );
        update_option( 'csdt_fpm_probe_url',         esc_url_raw( (string) ( $_POST['probe_url']    ?? 'http://localhost:8082/' ) ),   false );
        update_option( 'csdt_fpm_probe_timeout',     (string) $timeout,                                                               false );
        update_option( 'csdt_fpm_wp_container',      sanitize_text_field( (string) ( $_POST['wp_container'] ?? 'pi_wordpress' ) ),    false );
        update_option( 'csdt_fpm_db_container',      sanitize_text_field( (string) ( $_POST['db_container'] ?? 'pi_mariadb'  ) ),    false );
        update_option( 'csdt_fpm_auto_restart',      $auto_restart,                                                                   false );
        update_option( 'csdt_fpm_restart_cooldown',  (string) $restart_cooldown,                                                      false );
        wp_send_json_success();
    }

    public static function ajax_fpm_worker_status(): void {
        check_ajax_referer( CloudScale_DevTools::FPM_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $probe_url  = rtrim( get_option( 'csdt_fpm_probe_url', 'http://localhost:8082/' ), '/' );
        $status_url = $probe_url . '/fpm-status';
        $response   = wp_remote_get( $status_url, [ 'timeout' => 5, 'sslverify' => false ] );
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => 'Could not reach ' . $status_url . ': ' . $response->get_error_message() . '. Ensure pm.status_path = /fpm-status in www.conf and a matching nginx location.' ] );
        }
        $body = wp_remote_retrieve_body( $response );
        $code = wp_remote_retrieve_response_code( $response );
        if ( (int) $code !== 200 || empty( $body ) ) {
            wp_send_json_error( [ 'message' => 'FPM status returned HTTP ' . $code . '. Enable pm.status_path = /fpm-status in www.conf and add a nginx location block for /fpm-status.' ] );
        }
        $parse = static function ( string $key ) use ( $body ): ?int {
            if ( preg_match( '/^' . preg_quote( $key, '/' ) . ':\s*(\d+)/m', $body, $m ) ) {
                return (int) $m[1];
            }
            return null;
        };
        wp_send_json_success( [
            'active' => $parse( 'active processes' ),
            'idle'   => $parse( 'idle processes' ),
            'total'  => $parse( 'total processes' ),
        ] );
    }

    public static function ajax_fpm_worker_detail(): void {
        check_ajax_referer( CloudScale_DevTools::FPM_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $probe_url  = rtrim( get_option( 'csdt_fpm_probe_url', 'http://localhost:8082/' ), '/' );
        $status_url = $probe_url . '/fpm-status?full&json';
        $response   = wp_remote_get( $status_url, [ 'timeout' => 5, 'sslverify' => false ] );
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        if ( $code !== 200 || empty( $body ) ) {
            wp_send_json_error( [ 'message' => 'HTTP ' . $code ] );
        }

        // Try JSON first (supported since PHP-FPM 7.x with ?json)
        $json = json_decode( $body, true );
        if ( $json && isset( $json['processes'] ) ) {
            wp_send_json_success( [
                'pool'     => $json['pool'] ?? '',
                'pm'       => $json['process manager'] ?? '',
                'accepted' => $json['accepted conn'] ?? 0,
                'workers'  => array_map( static function ( array $p ): array {
                    return [
                        'pid'      => $p['pid'] ?? 0,
                        'state'    => $p['state'] ?? '',
                        'reqs'     => $p['requests'] ?? 0,
                        'since'    => $p['start since'] ?? 0,
                        'duration' => $p['request duration'] ?? 0,
                        'method'   => $p['request method'] ?? '',
                        'uri'      => ( $p['request uri'] ?? '' ) . ( ! empty( $p['query string'] ) ? '?' . $p['query string'] : '' ),
                        'script'   => basename( $p['script'] ?? '' ),
                        'cpu'      => $p['last request cpu'] ?? 0,
                        'mem'      => $p['last request memory'] ?? 0,
                        'user'     => $p['user'] ?? '-',
                    ];
                }, $json['processes'] ),
            ] );
        }

        // Fall back to text parsing
        $sections = preg_split( '/\*{8,}/', $body );
        $pool_info = [];
        $workers   = [];
        if ( ! empty( $sections[0] ) ) {
            foreach ( explode( "\n", $sections[0] ) as $line ) {
                if ( preg_match( '/^([^:]+):\s*(.+)$/', trim( $line ), $m ) ) {
                    $pool_info[ trim( $m[1] ) ] = trim( $m[2] );
                }
            }
        }
        foreach ( array_slice( $sections, 1 ) as $section ) {
            $w = [];
            foreach ( explode( "\n", $section ) as $line ) {
                if ( preg_match( '/^([^:]+):\s*(.*)$/', trim( $line ), $m ) ) {
                    $w[ trim( $m[1] ) ] = trim( $m[2] );
                }
            }
            if ( ! empty( $w['pid'] ) ) {
                $uri = $w['request URI'] ?? '';
                $qs  = $w['query string'] ?? '';
                $workers[] = [
                    'pid'      => (int) $w['pid'],
                    'state'    => $w['state'] ?? '',
                    'reqs'     => (int) ( $w['requests'] ?? 0 ),
                    'since'    => (int) ( $w['start since'] ?? 0 ),
                    'duration' => (int) ( $w['request duration'] ?? 0 ),
                    'method'   => $w['request method'] ?? '',
                    'uri'      => $uri . ( $qs ? '?' . $qs : '' ),
                    'script'   => basename( $w['script'] ?? '' ),
                    'cpu'      => (float) ( $w['last request cpu'] ?? 0 ),
                    'mem'      => (int) ( $w['last request memory'] ?? 0 ),
                    'user'     => $w['user'] ?? '-',
                ];
            }
        }
        wp_send_json_success( [
            'pool'     => $pool_info['pool'] ?? '',
            'pm'       => $pool_info['process manager'] ?? '',
            'accepted' => (int) ( $pool_info['accepted conn'] ?? 0 ),
            'workers'  => $workers,
        ] );
    }

    public static function ajax_fpm_setup_detect(): void {
        check_ajax_referer( CloudScale_DevTools::FPM_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        // Locate www.conf
        $conf_candidates = [
            '/usr/local/etc/php-fpm.d/www.conf',
            '/etc/php-fpm.d/www.conf',
            '/etc/php/8.4/fpm/pool.d/www.conf',
            '/etc/php/8.3/fpm/pool.d/www.conf',
            '/etc/php/8.2/fpm/pool.d/www.conf',
            '/etc/php/8.1/fpm/pool.d/www.conf',
            '/etc/php/8.0/fpm/pool.d/www.conf',
            '/etc/php/7.4/fpm/pool.d/www.conf',
        ];
        $www_conf          = null;
        $www_conf_writable = false;
        $status_path_set   = false;
        foreach ( $conf_candidates as $p ) {
            if ( file_exists( $p ) && is_readable( $p ) ) {
                $www_conf          = $p;
                $www_conf_writable = is_writable( $p );
                $content           = (string) file_get_contents( $p ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local PHP-FPM config file
                $status_path_set   = (bool) preg_match( '/^\s*pm\.status_path\s*=/m', $content );
                break;
            }
        }

        // Probe nginx candidates
        $stored   = rtrim( get_option( 'csdt_fpm_probe_url', 'http://localhost:8082/' ), '/' );
        $probes   = array_unique( [
            $stored, 'http://localhost', 'http://localhost:80',
            'http://localhost:8080', 'http://localhost:8082', 'http://127.0.0.1',
        ] );
        $nginx_url        = null;
        $fpm_status_works = false;
        foreach ( $probes as $base ) {
            $base = rtrim( $base, '/' );
            $r    = wp_remote_get( $base . '/fpm-status', [ 'timeout' => 2, 'sslverify' => false ] );
            if ( ! is_wp_error( $r ) && (int) wp_remote_retrieve_response_code( $r ) === 200 ) {
                $body = wp_remote_retrieve_body( $r );
                if ( str_contains( $body, 'active processes' ) || str_contains( $body, 'pool:' ) ) {
                    $nginx_url        = $base . '/';
                    $fpm_status_works = true;
                    break;
                }
            }
            if ( $nginx_url === null ) {
                $r2 = wp_remote_get( $base . '/', [ 'timeout' => 2, 'sslverify' => false ] );
                if ( ! is_wp_error( $r2 ) && (int) wp_remote_retrieve_response_code( $r2 ) > 0 ) {
                    $nginx_url = $base . '/';
                }
            }
        }

        // Try to read the fastcgi_pass upstream from nginx config (same container only)
        $fastcgi_pass = 'php:9000'; // default guess
        foreach ( [ '/etc/nginx/sites-enabled', '/etc/nginx/conf.d', '/etc/nginx' ] as $dir ) {
            if ( ! is_dir( $dir ) ) continue;
            foreach ( (array) glob( $dir . '/*.conf' ) as $cf ) {
                $nc = is_readable( $cf ) ? (string) file_get_contents( $cf ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local nginx config file
                if ( preg_match( '/fastcgi_pass\s+([^\s;]+)/i', $nc, $m ) ) {
                    $fastcgi_pass = $m[1];
                    break 2;
                }
            }
        }

        wp_send_json_success( [
            'www_conf'         => $www_conf,
            'www_conf_writable'=> $www_conf_writable,
            'status_path_set'  => $status_path_set,
            'nginx_url'        => $nginx_url,
            'fpm_status_works' => $fpm_status_works,
            'fastcgi_pass'     => $fastcgi_pass,
        ] );
    }

    public static function ajax_fpm_setup_patch(): void {
        check_ajax_referer( CloudScale_DevTools::FPM_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $www_conf  = sanitize_text_field( (string) ( $_POST['www_conf'] ?? '' ) );
        $nginx_url = esc_url_raw( (string) ( $_POST['nginx_url'] ?? '' ) );

        $safe_paths = [
            '/usr/local/etc/php-fpm.d/www.conf',
            '/etc/php-fpm.d/www.conf',
            '/etc/php/8.4/fpm/pool.d/www.conf',
            '/etc/php/8.3/fpm/pool.d/www.conf',
            '/etc/php/8.2/fpm/pool.d/www.conf',
            '/etc/php/8.1/fpm/pool.d/www.conf',
            '/etc/php/8.0/fpm/pool.d/www.conf',
            '/etc/php/7.4/fpm/pool.d/www.conf',
        ];
        if ( ! in_array( $www_conf, $safe_paths, true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid config path.' ] );
        }
        if ( ! file_exists( $www_conf ) || ! is_writable( $www_conf ) ) {
            wp_send_json_error( [ 'message' => 'www.conf not writable: ' . $www_conf ] );
        }

        $content = (string) file_get_contents( $www_conf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local PHP-FPM www.conf (path validated against allowlist)
        $patched = false;
        if ( ! preg_match( '/^\s*pm\.status_path\s*=/m', $content ) ) {
            // Insert after pm.max_spare_servers if present, else append
            if ( preg_match( '/^(pm\.max_spare_servers\s*=.*)/m', $content ) ) {
                $content = preg_replace(
                    '/^(pm\.max_spare_servers\s*=.*)/m',
                    "$1\npm.status_path = /fpm-status",
                    $content,
                    1
                );
            } else {
                $content .= "\npm.status_path = /fpm-status\n";
            }
            if ( file_put_contents( $www_conf, $content ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing PHP-FPM www.conf (path validated against allowlist)
                wp_send_json_error( [ 'message' => 'Could not write to ' . $www_conf ] );
            }
            $patched = true;
        }

        if ( $nginx_url ) {
            update_option( 'csdt_fpm_probe_url', rtrim( $nginx_url, '/' ) . '/', false );
        }

        // Reload php-fpm master — try PID file, then /proc scan
        $reloaded     = false;
        $reload_msg   = '';
        $reload_error = '';

        foreach ( [ '/var/run/php-fpm.pid', '/run/php-fpm.pid', '/run/php/php-fpm.pid' ] as $pid_file ) {
            if ( file_exists( $pid_file ) ) {
                $pid = (int) trim( (string) file_get_contents( $pid_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local OS PID file
                if ( $pid > 1 && function_exists( 'posix_kill' ) && posix_kill( $pid, SIGUSR2 ) ) {
                    $reloaded   = true;
                    $reload_msg = 'Sent SIGUSR2 to php-fpm master (PID ' . $pid . ')';
                    break;
                }
            }
        }

        if ( ! $reloaded && is_dir( '/proc' ) && function_exists( 'posix_kill' ) ) {
            $fpm_pids = [];
            foreach ( (array) glob( '/proc/[0-9]*', GLOB_ONLYDIR ) as $d ) {
                $comm = is_readable( $d . '/comm' ) ? (string) file_get_contents( $d . '/comm' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading /proc process name
                if ( str_contains( trim( $comm ), 'php-fpm' ) ) {
                    $fpm_pids[] = (int) basename( $d );
                }
            }
            if ( $fpm_pids ) {
                sort( $fpm_pids );
                if ( posix_kill( $fpm_pids[0], SIGUSR2 ) ) {
                    $reloaded   = true;
                    $reload_msg = 'Sent SIGUSR2 to php-fpm master (PID ' . $fpm_pids[0] . ')';
                }
            }
        }

        if ( ! $reloaded ) {
            $reload_error = 'Auto-reload failed — run manually: kill -USR2 $(pgrep -o php-fpm)';
        }

        wp_send_json_success( [
            'patched'      => $patched,
            'reloaded'     => $reloaded,
            'reload_msg'   => $reload_msg,
            'reload_error' => $reload_error,
        ] );
    }

    // ── OPcache ──────────────────────────────────────────────────────────────

    public static function ajax_opcache_stats(): void {
        check_ajax_referer( CloudScale_DevTools::FPM_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }
        wp_send_json_success( self::get_opcache_stats() );
    }

    public static function ajax_opcache_flush(): void {
        // Accepts either a logged-in admin with nonce OR the stored deploy token (for deploy script).
        $token  = sanitize_text_field( (string) ( $_POST['token'] ?? '' ) );
        $stored = get_option( 'csdt_opcache_token', '' );

        $by_token = $stored && $token && hash_equals( $stored, $token );
        if ( ! $by_token ) {
            check_ajax_referer( CloudScale_DevTools::FPM_NONCE, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }
        }

        // Use per-file invalidation instead of opcache_reset() to avoid acquiring the
        // global OPcache write-lock, which blocks all workers simultaneously and causes
        // a brief upstream timeout visible to users as ERR_CONNECTION_FAILED.
        $ok = false;
        if ( function_exists( 'opcache_invalidate' ) && function_exists( 'opcache_get_status' ) ) {
            $status = opcache_get_status( false );
            if ( ! empty( $status['scripts'] ) ) {
                foreach ( array_keys( $status['scripts'] ) as $cached_file ) {
                    opcache_invalidate( $cached_file, true );
                }
                $ok = true;
            }
        }
        if ( ! $ok ) {
            $ok = function_exists( 'opcache_reset' ) && opcache_reset();
        }
        update_option( 'csdt_opcache_last_flush', time(), false );

        wp_send_json_success( [
            'flushed'    => $ok,
            'stats'      => self::get_opcache_stats(),
            'flushed_at' => human_time_diff( time() ) . ' ago',
        ] );
    }

    public static function ajax_lscache_purge(): void {
        $token  = sanitize_text_field( (string) ( $_POST['token'] ?? '' ) );
        $stored = get_option( 'csdt_opcache_token', '' );
        $by_token = $stored && $token && hash_equals( $stored, $token );
        if ( ! $by_token ) {
            check_ajax_referer( CloudScale_DevTools::FPM_NONCE, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }
        }
        do_action( 'litespeed_purge_all' );
        wp_send_json_success( [ 'purged' => true ] );
    }

    private static function get_opcache_stats(): array {
        if ( ! function_exists( 'opcache_get_status' ) ) {
            return [ 'available' => false ];
        }
        $s = opcache_get_status( false );
        if ( ! $s ) {
            return [ 'available' => false ];
        }
        $mem   = $s['memory_usage'];
        $total = $mem['used_memory'] + $mem['free_memory'] + $mem['wasted_memory'];
        $stats = $s['opcache_statistics'];
        $last  = (int) get_option( 'csdt_opcache_last_flush', 0 );
        return [
            'available'      => true,
            'enabled'        => $s['opcache_enabled'],
            'mem_used'       => $mem['used_memory'],
            'mem_free'       => $mem['free_memory'],
            'mem_wasted'     => $mem['wasted_memory'],
            'mem_total'      => $total,
            'cached_scripts' => $stats['num_cached_scripts'],
            'hits'           => $stats['hits'],
            'misses'         => $stats['misses'],
            'hit_rate'       => $total > 0 ? round( $stats['hits'] / max( 1, $stats['hits'] + $stats['misses'] ) * 100, 1 ) : 0,
            'last_flush'     => $last ? human_time_diff( $last ) . ' ago' : 'never',
            'last_flush_ts'  => $last,
        ];
    }

    public static function register_fpm_report_route(): void {
        register_rest_route( 'csdt/v1', '/fpm-report', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_fpm_report' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public static function rest_fpm_report( \WP_REST_Request $request ): \WP_REST_Response {
        $token  = sanitize_text_field( (string) $request->get_param( 'token' ) );
        $stored = get_option( 'csdt_fpm_token', '' );
        if ( empty( $stored ) || ! hash_equals( $stored, $token ) ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid token' ], 403 );
        }
        $type = sanitize_text_field( (string) $request->get_param( 'type' ) );
        if ( ! in_array( $type, [ 'saturated', 'recovered', 'restarted' ], true ) ) {
            $type = 'saturated';
        }
        $msg   = sanitize_text_field( (string) $request->get_param( 'msg' ) );
        $event = [
            'ts'   => time(),
            'type' => $type,
            'msg'  => substr( $msg, 0, 200 ),
        ];
        update_option( 'csdt_fpm_last_event', $event, false );
        $log   = get_option( 'csdt_fpm_event_log', [] );
        if ( ! is_array( $log ) ) {
            $log = [];
        }
        array_unshift( $log, $event );
        $log = array_slice( $log, 0, 50 );
        update_option( 'csdt_fpm_event_log', $log, false );
        return new \WP_REST_Response( [ 'ok' => true ] );
    }

}
