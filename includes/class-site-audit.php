<?php
/**
 * Site Auditor — quick fixes, scheduled scans, AI site audit, deep scan,
 * rule-based findings, external checks, plugin/core integrity, scan history.
 *
 * @package CloudScale_DevTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CSDT_Site_Audit {

    public static function default_security_prompt(): string {
        return 'You are an expert WordPress security auditor with deep knowledge of WordPress internals, common attack vectors, and security hardening best practices.'
            . "\n\nAnalyse the provided site configuration and return a comprehensive, prioritised security assessment."
            . "\n\nReturn ONLY valid JSON — no markdown code fences, no explanation outside the JSON. Use this exact schema:"
            . "\n{\"score\":<integer 0-100>,\"score_label\":\"<Excellent|Good|Fair|Poor|Critical>\",\"summary\":\"<2-3 sentence executive summary>\","
            . "\"critical\":[{\"title\":\"...\",\"detail\":\"...\",\"fix\":\"...\"}],"
            . "\"high\":[{\"title\":\"...\",\"detail\":\"...\",\"fix\":\"...\"}],"
            . "\"medium\":[{\"title\":\"...\",\"detail\":\"...\",\"fix\":\"...\"}],"
            . "\"low\":[{\"title\":\"...\",\"detail\":\"...\",\"fix\":\"...\"}],"
            . "\"good\":[{\"title\":\"...\",\"detail\":\"...\"}]}"
            . "\n\nScoring: 90-100 Excellent, 75-89 Good, 55-74 Fair, 35-54 Poor, 0-34 Critical."
            . "\n\nFor each issue — title: concise problem name; detail: specific risk with exploit path; fix: exact actionable steps (include WP-CLI commands, file paths, or wp-config.php constants where relevant)."
            . "\n\nAnalyse: WordPress/PHP version currency, plugin/theme security posture, authentication hardening (2FA, brute-force, admin username), configuration security (debug mode, file editing, DB prefix), exposed sensitive files, HTTP security headers, HTTPS enforcement, and any notable risk combinations.";
    }

    public static function gather_security_data(): array {
        global $wpdb;

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = (array) get_option( 'active_plugins', [] );
        $plugin_updates = get_site_transient( 'update_plugins' );
        $plugins_list   = [];
        foreach ( $all_plugins as $file => $p ) {
            $has_upd        = is_object( $plugin_updates ) && isset( $plugin_updates->response[ $file ] );
            $plugins_list[] = [
                'name'    => $p['Name'],
                'version' => $p['Version'],
                'active'  => in_array( $file, $active_plugins, true ),
                'update'  => $has_upd,
                'new_ver' => $has_upd ? ( $plugin_updates->response[ $file ]->new_version ?? null ) : null,
            ];
        }
        usort( $plugins_list, fn( $a, $b ) => (int) $b['active'] - (int) $a['active'] );

        $wp_updates = get_site_transient( 'update_core' );
        $wp_current = get_bloginfo( 'version' );
        $wp_latest  = $wp_updates->updates[0]->version ?? $wp_current;

        $user_counts       = count_users();
        $admin_user_exists = (bool) get_user_by( 'login', 'admin' );

        $sec_headers = [];
        $home_resp   = wp_remote_get( home_url( '/' ), [
            'timeout'    => 5,
            'sslverify'  => false,
            'user-agent' => 'Mozilla/5.0 (compatible; CSDT-SecurityScan/1.0)',
        ] );
        if ( ! is_wp_error( $home_resp ) ) {
            $h = wp_remote_retrieve_headers( $home_resp );
            foreach ( [ 'x-frame-options', 'x-content-type-options', 'strict-transport-security',
                        'content-security-policy', 'referrer-policy', 'permissions-policy' ] as $hname ) {
                $sec_headers[ $hname ] = $h[ $hname ] ?? null;
            }
        }

        $exposed = [];
        foreach ( [ 'readme.html', 'license.txt', 'wp-config.php.bak', '.env' ] as $f ) {
            if ( file_exists( ABSPATH . $f ) ) {
                $check = wp_remote_head( home_url( '/' . $f ), [ 'timeout' => 3, 'sslverify' => false ] );
                if ( ! is_wp_error( $check ) && (int) wp_remote_retrieve_response_code( $check ) === 200 ) {
                    $exposed[] = $f;
                }
            }
        }

        $config_perms = file_exists( ABSPATH . 'wp-config.php' )
            ? substr( sprintf( '%o', fileperms( ABSPATH . 'wp-config.php' ) ), -4 )
            : 'unknown';

        return [
            'wordpress' => [
                'version'    => $wp_current,
                'latest'     => $wp_latest,
                'up_to_date' => version_compare( $wp_current, $wp_latest, '>=' ),
            ],
            'php_version'    => PHP_VERSION,
            'plugins'        => $plugins_list,
            'plugin_summary' => [
                'total'    => count( $plugins_list ),
                'active'   => count( array_filter( $plugins_list, fn( $p ) => $p['active'] ) ),
                'inactive' => count( array_filter( $plugins_list, fn( $p ) => ! $p['active'] ) ),
                'outdated' => count( array_filter( $plugins_list, fn( $p ) => $p['update'] ) ),
            ],
            'users' => [
                'admin_login_exists' => $admin_user_exists,
                'admin_count'        => $user_counts['avail_roles']['administrator'] ?? 0,
                'total_users'        => $user_counts['total_users'],
            ],
            'configuration' => [
                'wp_debug'           => defined( 'WP_DEBUG' ) && WP_DEBUG,
                'wp_debug_display'   => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
                'wp_debug_log'       => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
                'disallow_file_edit' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
                'disallow_file_mods' => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
                'force_ssl_admin'    => defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN,
                'db_prefix'          => $wpdb->prefix,
                'db_prefix_default'  => $wpdb->prefix === 'wp_',
                'wp_config_perms'         => $config_perms,
                'wp_config_world_readable'=> file_exists( ABSPATH . 'wp-config.php' ) && (bool) ( fileperms( ABSPATH . 'wp-config.php' ) & 0004 ),
            ],
            'site' => [
                'url'                    => home_url( '/' ),
                'is_https'               => is_ssl(),
                'login_url_hidden'       => get_option( 'csdt_devtools_login_hide_enabled', '0' ) === '1',
                'xmlrpc_exists'          => file_exists( ABSPATH . 'xmlrpc.php' ) && (bool) apply_filters( 'xmlrpc_enabled', true ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
                'open_registration'      => (bool) get_option( 'users_can_register', 0 ),
                'pingbacks_enabled'      => get_option( 'default_ping_status' ) === 'open',
                'wp_version_in_meta'     => ! has_filter( 'the_generator', '__return_empty_string' ) && ! has_filter( 'wp_head', 'wp_generator' ),
                'default_comment_status' => get_option( 'default_comment_status' ),
            ],
            'security_features' => [
                'brute_force_enabled'  => get_option( 'csdt_devtools_brute_force_enabled', '1' ) === '1',
                'two_fa_site_method'   => get_option( 'csdt_devtools_2fa_method', 'off' ),
                'two_fa_totp_admins'   => ( function () {
                    $admins = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
                    $count  = 0;
                    foreach ( $admins as $id ) {
                        if ( get_user_meta( (int) $id, 'csdt_devtools_totp_enabled', true ) === '1' ) {
                            $count++;
                        }
                    }
                    return $count;
                } )(),
                'passkeys_admin_count' => ( function () {
                    $admins = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
                    $count  = 0;
                    foreach ( $admins as $id ) {
                        // Passkeys stored as JSON string — use the class method which decodes it
                        $keys = CSDT_DevTools_Passkey::get_passkeys( (int) $id );
                        if ( ! empty( $keys ) ) {
                            $count++;
                        }
                    }
                    return $count;
                } )(),
                'admin_count'          => count( get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] ) ),
                'failed_logins_1h'     => (int) get_transient( 'csdt_devtools_failed_logins_1h' ),
                'failed_logins_24h'    => (int) get_transient( 'csdt_devtools_failed_logins_24h' ),
                'app_passwords'        => ( function () {
                    $enabled       = function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();
                    $admins_with   = 0;
                    $total_app_pw  = 0;
                    if ( $enabled ) {
                        foreach ( get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] ) as $id ) {
                            $pws = WP_Application_Passwords::get_user_application_passwords( (int) $id );
                            if ( ! empty( $pws ) ) {
                                $admins_with++;
                                $total_app_pw += count( $pws );
                            }
                        }
                    }
                    return [
                        'enabled'          => $enabled,
                        'admins_with_app_pw'=> $admins_with,
                        'total_app_passwords'=> $total_app_pw,
                    ];
                } )(),
            ],
            'exposed_files'    => $exposed,
            'security_headers' => $sec_headers,
            'ssh_status'       => self::gather_ssh_status(),
        ];
    }


    public static function get_quick_fixes(): array {
        $app_pw_available = function_exists( 'wp_is_application_passwords_available' )
                           && wp_is_application_passwords_available();
        return [
            [
                'id'        => 'disable_pingbacks',
                'title'     => 'Pingbacks & trackbacks enabled',
                'detail'    => 'WordPress sends/receives trackback notifications — commonly abused for DDoS amplification and spam.',
                'fixed'     => get_option( 'default_ping_status' ) !== 'open',
                'fix_label' => 'Disable Pingbacks',
            ],
            [
                'id'        => 'close_registration',
                'title'     => 'Open user registration',
                'detail'    => 'Anyone can register an account on this site. Widens attack surface for spam and privilege escalation.',
                'fixed'     => ! (bool) get_option( 'users_can_register', 0 ),
                'fix_label' => 'Disable Registration',
            ],
            [
                'id'           => 'disable_app_passwords',
                'title'        => 'Application passwords enabled',
                'detail'       => get_option( 'csdt_devtools_test_accounts_enabled', '0' ) === '1'
                    ? 'App passwords are required for the Test Account Manager feature and are intentionally enabled.'
                    : ( get_option( 'csdt_devtools_app_pw_2fa_ack', '0' ) === '1'
                        ? 'App passwords are intentionally enabled — 2FA is active and REST API use is authorised.'
                        : 'App passwords allow REST API authentication and can bypass two-factor authentication. Disable unless needed.' ),
                'fixed'        => get_option( 'csdt_devtools_disable_app_passwords', '0' ) === '1'
                              || ! $app_pw_available
                              || get_option( 'csdt_devtools_test_accounts_enabled', '0' ) === '1'
                              || get_option( 'csdt_devtools_app_pw_2fa_ack', '0' ) === '1',
                'fix_label'    => 'Disable App Passwords',
                'dismiss_label'=> 'Using with 2FA',
                'dismiss_id'   => 'app_pw_2fa_ack',
            ],
            [
                'id'        => 'enforce_2fa_admins',
                'title'     => get_option( 'csdt_devtools_2fa_force_admins', '0' ) === '1'
                    ? '2FA enforcement is active — admins must complete a second factor'
                    : 'Admin accounts not required to use two-factor authentication',
                'detail'    => get_option( 'csdt_devtools_2fa_force_admins', '0' ) === '1'
                    ? 'All administrator accounts are required to set up and use 2FA on every login. Accounts without 2FA configured will be redirected to the enrollment page.'
                    : 'Administrator accounts can log in with a password alone. A single leaked or guessed password gives an attacker full site control. Enabling enforcement redirects admins without 2FA to the enrollment page on their next login.',
                'fixed'     => get_option( 'csdt_devtools_2fa_force_admins', '0' ) === '1',
                'fix_label' => 'Enforce 2FA for Admins',
            ],
            [
                'id'        => 'hide_wp_version',
                'title'     => 'WordPress version exposed in HTML',
                'detail'    => 'The <generator> meta tag and asset ?ver= query strings reveal your WP version, helping attackers target known vulnerabilities.',
                'fixed'     => get_option( 'csdt_devtools_hide_wp_version', '0' ) === '1'
                              || has_filter( 'the_generator', '__return_empty_string' ),
                'fix_label' => 'Hide WP Version',
            ],
            [
                'id'        => 'close_comments',
                'title'     => 'Comments open by default on new posts',
                'detail'    => 'Open comments invite spam, XSS payloads, and link injection attacks.',
                'fixed'     => get_option( 'default_comment_status' ) !== 'open',
                'fix_label' => 'Close Comments',
            ],
            [
                'id'          => 'wpconfig_perms',
                'title'       => 'wp-config.php is writable by the web server process',
                'detail'      => 'wp-config.php should be read-only (0400 or 0440) so no PHP process running as the web server user can overwrite database credentials or secret keys.',
                'fixed'       => ( function () {
                    $f = ABSPATH . 'wp-config.php';
                    if ( ! file_exists( $f ) ) { return true; }
                    $perms = substr( sprintf( '%o', fileperms( $f ) ), -4 );
                    return in_array( $perms, [ '0400', '0440', '0600', '0640' ], true );
                } )(),
                'fix_label'   => 'Set to 0400',
                'risk'        => 'moderate',
                'confirm_msg' => 'This will chmod wp-config.php to 0400 (read-only). Make sure you have SSH access in case you need to restore write permissions.',
            ],
            [
                'id'          => 'block_debug_log',
                'title'       => 'debug.log exposed publicly',
                'detail'      => 'debug.log is HTTP-accessible. On nginx, .htaccess rules are ignored — the only PHP-level fix is to move the file one directory above the web root. It stays readable via the Server Logs tab.',
                'fixed'       => ! file_exists( WP_CONTENT_DIR . '/debug.log' ),
                'fix_label'   => 'Move Outside Web Root',
                'risk'        => 'moderate',
                'confirm_msg' => 'This will move debug.log one directory above the web root. It remains readable from the Server Logs tab.',
            ],
            [
                'id'        => 'db_prefix_default',
                'title'     => 'Default database table prefix (wp_)',
                'detail'    => 'The default wp_ prefix is a well-known attack target. Renaming tables to a unique prefix reduces automated SQL injection and enumeration risk.',
                'fixed'     => ( function () {
                    global $wpdb;
                    return $wpdb->prefix !== 'wp_';
                } )(),
                'fix_label' => 'Fix Prefix…',
                'fix_modal'  => 'csdt-db-prefix-modal',
            ],
            [
                'id'           => 'csp_unsafe_inline',
                'title'        => "CSP allows 'unsafe-inline' for scripts — weakens XSS protection",
                'detail'       => get_option( 'csdt_csp_inline_ack', '0' ) === '1'
                    ? "Acknowledged — 'unsafe-inline' in script-src is managed externally (nginx, Cloudflare, or CDN)."
                    : ( get_option( 'csdt_csp_nonces_enabled', '0' ) === '1'
                        ? "Nonce-based CSP is active. Scripts are protected with per-request nonces; 'unsafe-inline' has been removed from script-src."
                        : "Clicking 'Enable Nonce CSP' will start in Report-Only mode — nothing is blocked yet. Browse your site and check the CSP Violation Log before switching to Enforce. ⚠️ Never go straight to Enforce — some plugins use eval() (underscore.min.js templates) and will break." ),
                'fixed'        => get_option( 'csdt_csp_inline_ack', '0' ) === '1'
                    || ( get_option( 'csdt_csp_nonces_enabled', '0' ) === '1'
                         && get_option( 'csdt_devtools_csp_enabled', '0' ) === '1' ),
                'fix_label'    => 'Enable Nonce CSP',
                'dismiss_label'=> 'Managed Externally',
                'dismiss_id'   => 'csp_inline_ack',
            ],
            ( function () {
                $cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
                $next_delete   = wp_next_scheduled( 'wp_scheduled_delete' );
                $next_transient= wp_next_scheduled( 'delete_expired_transients' );
                $now           = time();
                $overdue_secs  = 6 * HOUR_IN_SECONDS;
                $missing       = ! $next_delete || ! $next_transient;
                $overdue       = ( $next_delete   && $next_delete    < $now - $overdue_secs )
                              || ( $next_transient && $next_transient < $now - $overdue_secs );
                // Healthy = events scheduled and not overdue.
                // DISABLE_WP_CRON is handled by its own separate quick-fix item;
                // don't double-report it here. If events are scheduled, they'll fire
                // when a system cron triggers wp-cron.php — that's the correct setup.
                $healthy = ! $missing && ! $overdue;

                if ( $healthy && $cron_disabled ) {
                    $detail = 'Cleanup events are scheduled. DISABLE_WP_CRON is set — ensure a system cron triggers wp-cron.php (e.g. */10 * * * * curl -s ' . home_url( '/wp-cron.php?doing_wp_cron' ) . ').';
                } elseif ( $healthy ) {
                    $next_label = 'next in ' . human_time_diff( $now, min(
                        $next_delete    ?: PHP_INT_MAX,
                        $next_transient ?: PHP_INT_MAX
                    ) );
                    $detail = "WP-Cron cleanup events are scheduled and running on time ({$next_label}).";
                } elseif ( $missing ) {
                    $detail = 'One or more core cleanup cron events (wp_scheduled_delete, delete_expired_transients) are missing from the schedule. They will be rescheduled on fix.';
                } else {
                    $hours = round( ( $now - min( $next_delete ?: $now, $next_transient ?: $now ) ) / HOUR_IN_SECONDS );
                    $detail = "Core cleanup cron events are {$hours}h overdue — WP-Cron may not be firing. Click fix to reschedule and run them now.";
                }

                return [
                    'id'        => 'cron_health',
                    'title'     => $healthy
                        ? 'WP-Cron cleanup events are scheduled'
                        : 'WP-Cron cleanup events are missing or overdue',
                    'detail'    => $detail,
                    'fixed'     => $healthy,
                    'fix_label' => 'Reschedule & Run Now',
                ];
            } )(),
            ( function () {
                global $wpdb;
                $count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    "SELECT COUNT(*) FROM {$wpdb->options}
                     WHERE option_name LIKE '_transient_timeout_%'
                       AND CAST( option_value AS UNSIGNED ) < UNIX_TIMESTAMP()"
                );
                return [
                    'id'        => 'expired_transients',
                    'title'     => $count > 0
                        ? sprintf( '%d expired transient%s bloating wp_options', $count, $count === 1 ? '' : 's' )
                        : 'No expired transients — wp_options is clean',
                    'detail'    => $count > 0
                        ? sprintf( '%d expired transient record%s remain in wp_options. WordPress normally auto-purges these via cron, but missed cron runs let them accumulate and slow down any query that scans the options table.', $count, $count === 1 ? '' : 's' )
                        : 'All transients have been cleaned up.',
                    'fixed'     => $count === 0,
                    'fix_label' => 'Delete Expired Transients',
                ];
            } )(),
            ( function () {
                // ── Detection strategy ─────────────────────────────────────────
                // Three possible states: RUNNING (proved), NOT_INSTALLED (proved), UNDETECTABLE.
                // "Undetectable" happens when PHP runs inside a container with no access to host
                // binaries, PID files, or log files — we must not lie and say "not installed".
                //
                // Detection order (most reliable first):
                //   1. /var/log/fail2ban.log readable → definitive (only exists if f2b installed)
                //   2. Binary present in known paths → installed; PID/socket → running
                //   3. exec('which fail2ban-client') → installed; exec('systemctl is-active') → running
                //   4. All methods exhausted + /.dockerenv present → UNDETECTABLE

                $state = 'unknown'; // 'running' | 'installed_stopped' | 'not_installed' | 'undetectable'

                // Method 1: log file (most reliable in containerised environments if mounted)
                $f2b_log = '/var/log/fail2ban.log';
                if ( is_readable( $f2b_log ) ) {
                    $state = 'installed_stopped'; // file exists = installed; assume stopped until proved
                    $mtime = @filemtime( $f2b_log );
                    if ( $mtime && ( time() - $mtime ) < 7200 ) {
                        // File touched in last 2 h — read tail to find last lifecycle event
                        $tail = '';
                        if ( function_exists( 'exec' ) ) {
                            $out = [];
                            @exec( 'tail -20 ' . escapeshellarg( $f2b_log ) . ' 2>/dev/null', $out ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
                            $tail = implode( "\n", $out );
                        } elseif ( is_readable( $f2b_log ) && ( $fh = fopen( $f2b_log, 'r' ) ) ) {
                            $sz = (int) filesize( $f2b_log );
                            if ( $sz > 2048 ) { fseek( $fh, -2048, SEEK_END ); }
                            $tail = (string) fread( $fh, 2048 );
                            fclose( $fh );
                        }
                        preg_match_all( '/(?:started successfully|Stopping all jails)/i', $tail, $m );
                        $last = ! empty( $m[0] ) ? strtolower( end( $m[0] ) ) : '';
                        if ( $last !== 'stopping all jails' ) {
                            $state = 'running';
                        }
                    } else {
                        // Log not touched in 2 h — fail2ban may have been stopped; treat as installed_stopped
                        $state = 'installed_stopped';
                    }
                }

                // Method 2 + 3: binary / PID / exec (works on bare metal or with docker-in-docker)
                if ( $state === 'unknown' ) {
                    $installed = false;
                    foreach ( [ '/usr/bin/fail2ban-client', '/usr/sbin/fail2ban-client',
                                 '/usr/local/bin/fail2ban-client', '/usr/local/sbin/fail2ban-client',
                                 '/opt/fail2ban/bin/fail2ban-client' ] as $p ) {
                        if ( file_exists( $p ) ) { $installed = true; break; }
                    }
                    if ( ! $installed && function_exists( 'exec' ) ) {
                        $out = [];
                        @exec( 'which fail2ban-client 2>/dev/null', $out ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
                        $installed = ! empty( trim( $out[0] ?? '' ) );
                    }

                    if ( $installed ) {
                        $running = false;
                        foreach ( [ '/var/run/fail2ban/fail2ban.pid', '/run/fail2ban/fail2ban.pid',
                                     '/var/run/fail2ban/fail2ban.sock', '/run/fail2ban/fail2ban.sock' ] as $p ) {
                            if ( file_exists( $p ) ) { $running = true; break; }
                        }
                        if ( ! $running && function_exists( 'exec' ) ) {
                            $out = [];
                            @exec( 'systemctl is-active fail2ban 2>/dev/null', $out ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
                            $running = ( trim( $out[0] ?? '' ) === 'active' );
                        }
                        $state = $running ? 'running' : 'installed_stopped';
                    } else {
                        // Nothing found — check if we're in a container before declaring "not installed"
                        $in_container = file_exists( '/.dockerenv' )
                            || ( is_readable( '/proc/1/cgroup' ) && str_contains( (string) file_get_contents( '/proc/1/cgroup' ), 'docker' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                        $state = $in_container ? 'undetectable' : 'not_installed';
                    }
                }

                $last_check = get_option( 'csdt_ssh_monitor_last_check', null );
                $recent     = ( is_array( $last_check ) && isset( $last_check['count'] ) ) ? (int) $last_check['count'] : null;
                $age        = ( is_array( $last_check ) && isset( $last_check['ts'] ) ) ? (int) ( time() - $last_check['ts'] ) : null;
                $count_note = ( $recent !== null && $age !== null && $age < 180 )
                    ? sprintf( ' — %d failed attempt%s in the last 60 s', $recent, $recent === 1 ? '' : 's' )
                    : '';

                $detail_map = [
                    'running'           => 'fail2ban is installed and the daemon is running — SSH brute-force attempts are being blocked automatically.',
                    'installed_stopped' => 'fail2ban is installed but the daemon is not running. Start it: sudo systemctl start fail2ban && sudo systemctl enable fail2ban',
                    'not_installed'     => 'CRITICAL: fail2ban is not installed. Unprotected SSH is scanned 24/7 — attackers attempt thousands of passwords per minute and compromised servers are immediately enlisted into DDoS botnets.',
                    'undetectable'      => 'Cannot verify from inside this container — host services are not visible to PHP. Click "Enable Detection" for the one-line docker-compose fix.',
                ];

                return [
                    'id'        => 'ssh_brute_force',
                    'title'     => 'SSH brute-force protection' . $count_note,
                    'detail'    => $detail_map[ $state ] ?? $detail_map['not_installed'],
                    'fixed'     => ( $state === 'running' ),
                    'fix_label' => ( $state === 'undetectable' ) ? 'Enable Detection' : 'Copy fail2ban config',
                    'fix_modal' => ( $state === 'undetectable' ) ? 'csdt-fail2ban-docker-modal' : 'csdt-fail2ban-modal',
                ];
            } )(),
            ( function () {
                $cfg_file = ABSPATH . 'wp-config.php';
                // Check runtime constant first — covers mu-plugin or non-standard define formats
                $fixed = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
                if ( ! $fixed && is_readable( $cfg_file ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                    $cfg   = file_get_contents( $cfg_file );
                    $fixed = (bool) preg_match( "/define\s*\(\s*['\"]DISABLE_WP_CRON['\"]\s*,\s*(?:true|1)\s*\)\s*;/i", $cfg );
                }
                return [
                    'id'        => 'disable_wp_cron',
                    'title'     => $fixed
                        ? 'DISABLE_WP_CRON is set — wp-cron.php cannot be abused externally'
                        : 'wp-cron.php is publicly accessible — denial-of-service risk',
                    'detail'    => $fixed
                        ? "define('DISABLE_WP_CRON', true) is present in wp-config.php. Ensure a system cron or WP-CLI fires scheduled events every 5–15 minutes."
                        : "Any HTTP request to wp-cron.php triggers all due scheduled tasks. Attackers can spam it to cause repeated CPU spikes (denial-of-service). Fix writes define('DISABLE_WP_CRON', true) to wp-config.php — you must then configure a system cron to keep events running.",
                    'fixed'     => $fixed,
                    'fix_label' => 'Disable Public WP-Cron',
                ];
            } )(),
            ( function () {
                $file_mods_disabled = defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS;
                // Cross-check the file directly — OPcache may still have the old version
                // even after the constant was removed from wp-config.php.
                if ( $file_mods_disabled ) {
                    $cfg_raw = @file_get_contents( ABSPATH . 'wp-config.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                    if ( $cfg_raw !== false && ! preg_match( "/define\s*\(\s*['\"]DISALLOW_FILE_MODS['\"]\s*,\s*true\s*\)/i", $cfg_raw ) ) {
                        $file_mods_disabled = false; // removed from file; constant only persists in this process
                    }
                }
                $updater_disabled   = ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED )
                                   || ( defined( 'WP_AUTO_UPDATE_CORE' ) && WP_AUTO_UPDATE_CORE === false );
                $disabled = $file_mods_disabled || $updater_disabled;

                if ( $file_mods_disabled ) {
                    $detail = 'DISALLOW_FILE_MODS is set to true in wp-config.php. This blocks WordPress auto-updates, prevents security patches from installing, and removes the Delete button from plugins. Fix removes only this constant; DISALLOW_FILE_EDIT (blocks the in-admin code editor) is left intact.';
                } elseif ( $updater_disabled ) {
                    $detail = 'AUTOMATIC_UPDATER_DISABLED or WP_AUTO_UPDATE_CORE=false is set in wp-config.php. WordPress will not auto-install security patches.';
                } else {
                    $detail = 'WordPress automatic updates are enabled and will install minor security releases automatically.';
                }

                $cfg_writable = is_writable( ABSPATH . 'wp-config.php' );
                return [
                    'id'          => 'enable_auto_updates',
                    'title'       => $disabled
                        ? 'Automatic updates are disabled — security patches will not auto-install'
                        : 'Automatic updates are enabled',
                    'detail'      => $detail,
                    'fixed'       => ! $disabled,
                    'fix_label'   => 'Enable Auto Updates',
                    'risk'        => 'moderate',
                    'confirm_msg' => $file_mods_disabled && $cfg_writable
                        ? "This will remove define('DISALLOW_FILE_MODS', true) from wp-config.php. WordPress, plugins, and themes will be able to auto-update and the plugin Delete button will be restored."
                        : null,
                ];
            } )(),
            ( function () {
                if ( ! function_exists( 'get_plugins' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $all_plugins    = get_plugins();
                $active_plugins = (array) get_option( 'active_plugins', [] );
                $inactive = array_filter( $all_plugins, fn( $data, $k ) => ! in_array( $k, $active_plugins, true ), ARRAY_FILTER_USE_BOTH );
                $count    = count( $inactive );
                $names    = implode( ', ', array_map( fn( $d ) => $d['Name'], array_slice( $inactive, 0, 3 ) ) );
                if ( $count > 3 ) { $names .= ' and ' . ( $count - 3 ) . ' more'; }
                return [
                    'id'          => 'delete_inactive_plugins',
                    'title'       => $count > 0
                        ? sprintf( '%d inactive plugin%s sitting on disk unpatched', $count, $count === 1 ? '' : 's' )
                        : 'No inactive plugins — disk is clean',
                    'detail'      => $count > 0
                        ? sprintf( '%d deactivated plugin%s (%s) remain on disk. Inactive plugins receive no security attention and can be exploited via path traversal or known CVEs even when not running. Delete them if you no longer need them.', $count, $count === 1 ? '' : 's', $names )
                        : 'All installed plugins are active. No unpatched plugin files sitting on disk.',
                    'fixed'       => $count === 0,
                    'fix_label'   => $count > 0 ? "Delete All {$count} Inactive" : 'Nothing to delete',
                    'risk'        => 'moderate',
                    'confirm_msg' => $count > 0
                        ? "This will permanently delete all {$count} inactive plugin(s) from disk: {$names}. This cannot be undone. Make sure you do not need any of them before continuing."
                        : null,
                ];
            } )(),
            ( function () {
                // Detect orphaned cron hooks — events registered in the schedule but with no
                // PHP handler. These are left behind by deleted/deactivated plugins and fire
                // periodically doing nothing, wasting a cron slot and slowing WP-Cron runs.
                $core_hooks = [
                    'wp_scheduled_delete', 'delete_expired_transients', 'wp_version_check',
                    'wp_update_plugins', 'wp_update_themes', 'wp_scheduled_auto_draft_delete',
                    'recovery_mode_clean_expired_keys', 'wp_privacy_delete_old_export_files',
                    'wp_site_health_scheduled_check', 'wp_update_user_counts',
                    'wp_https_detection', 'wp_privacy_delete_old_export_files',
                ];
                $orphaned = [];
                $crons    = _get_cron_array();
                if ( is_array( $crons ) ) {
                    foreach ( $crons as $hooks ) {
                        foreach ( array_keys( $hooks ) as $hook ) {
                            if ( in_array( $hook, $core_hooks, true ) ) { continue; }
                            if ( ! has_action( $hook ) ) {
                                $orphaned[] = $hook;
                            }
                        }
                    }
                }
                $orphaned = array_values( array_unique( $orphaned ) );
                $count    = count( $orphaned );
                $preview  = implode( ', ', array_slice( $orphaned, 0, 4 ) );
                if ( $count > 4 ) { $preview .= ' and ' . ( $count - 4 ) . ' more'; }
                return [
                    'id'        => 'orphaned_cron_hooks',
                    'title'     => $count > 0
                        ? sprintf( '%d orphaned cron hook%s from deleted plugins', $count, $count === 1 ? '' : 's' )
                        : 'No orphaned cron hooks — schedule is clean',
                    'detail'    => $count > 0
                        ? sprintf(
                            '%d scheduled cron hook%s %s a registered PHP handler. These were added by plugins that have since been deleted or deactivated but whose scheduled events were never cleaned up. They fire on schedule, do nothing, and accumulate latency on every WP-Cron run. Hooks: %s.',
                            $count, $count === 1 ? '' : 's',
                            $count === 1 ? 'has no' : 'have no',
                            $preview
                          )
                        : 'All scheduled cron events have registered PHP handlers. No leftover events from deleted plugins.',
                    'fixed'     => $count === 0,
                    'fix_label' => $count > 0 ? 'Remove Orphaned Hooks' : 'Nothing to remove',
                    'risk'      => $count > 0 ? 'low' : null,
                ];
            } )(),
            ( function () {
                $already_fixed = get_option( 'csdt_ads_dedup', '0' ) === '1';
                if ( $already_fixed ) {
                    return [
                        'id'        => 'ads_dedup',
                        'title'     => 'AdSense duplicate push guard active',
                        'detail'    => 'A deduplication patch is injected before AdSense loads, preventing the "Only one enable_page_level_ads allowed per page" TagError.',
                        'fixed'     => true,
                        'fix_label' => 'Already fixed',
                    ];
                }
                // Detect duplicate enable_page_level_ads in the home page HTML.
                $cache_key = 'csdt_home_html_ads_check';
                $count = get_transient( $cache_key );
                if ( false === $count ) {
                    $resp  = wp_remote_get( home_url( '/' ), [ 'timeout' => 6, 'sslverify' => false ] );
                    $body  = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_body( $resp );
                    $count = (int) substr_count( $body, 'enable_page_level_ads' );
                    set_transient( $cache_key, $count, 5 * MINUTE_IN_SECONDS );
                }
                $detected = (int) $count > 1;
                return [
                    'id'        => 'ads_dedup',
                    'title'     => $detected
                        ? 'Duplicate AdSense push detected — "enable_page_level_ads" called ' . $count . ' times'
                        : 'No duplicate AdSense push detected',
                    'detail'    => $detected
                        ? 'Multiple calls to adsbygoogle.push({ enable_page_level_ads: true }) cause a TagError in the browser console and may prevent ads from loading. Usually caused by two plugins or widgets both initialising Auto Ads.'
                        : 'Only one enable_page_level_ads call found on the home page.',
                    'fixed'     => ! $detected,
                    'fix_label' => 'Fix Duplicate Push',
                    'risk'      => $detected ? 'medium' : null,
                ];
            } )(),
            ( function () {
                $cache_key = 'csdt_crossorigin_check';
                $missing   = get_transient( $cache_key );
                if ( false === $missing ) {
                    $resp = wp_remote_get( home_url( '/' ), [ 'timeout' => 6, 'sslverify' => false ] );
                    $body = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_body( $resp );
                    $missing = [];
                    if ( $body ) {
                        preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $body, $m );
                        $home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
                        foreach ( $m[0] as $i => $tag ) {
                            $src  = $m[1][ $i ];
                            $host = (string) wp_parse_url( $src, PHP_URL_HOST );
                            if ( $host && $host !== $home_host && stripos( $tag, 'crossorigin' ) === false ) {
                                $missing[] = $src;
                            }
                        }
                        $missing = array_values( array_unique( $missing ) );
                    }
                    set_transient( $cache_key, $missing, 5 * MINUTE_IN_SECONDS );
                }
                $count = count( (array) $missing );
                return [
                    'id'        => 'crossorigin_scripts',
                    'title'     => $count > 0
                        ? $count . ' third-party script' . ( $count === 1 ? '' : 's' ) . ' missing crossorigin="anonymous"'
                        : 'All third-party scripts have crossorigin attribute',
                    'detail'    => $count > 0
                        ? 'Without crossorigin="anonymous", JS errors from these scripts show as opaque "Script error." with no stack trace. The CDN must also send Access-Control-Allow-Origin: * for this to work. Scripts: ' . implode( ', ', array_slice( (array) $missing, 0, 3 ) ) . ( $count > 3 ? ' …' : '' )
                        : 'No third-party scripts are missing crossorigin attributes on the home page.',
                    'fixed'     => $count === 0,
                    'fix_label' => 'Add crossorigin via wp_script_attributes',
                    'risk'      => $count > 0 ? 'low' : null,
                ];
            } )(),
        ];
    }
    // ── Editor Debug Panel ────────────────────────────────────────────────────


    public static function render_site_audit_panel(): void {
        $has_key = CSDT_AI_Dispatcher::has_key();
        $security_url = admin_url( 'tools.php?page=' . CloudScale_DevTools::TOOLS_SLUG . '&tab=security' );
        ?>
        <div class="cs-panel" id="cs-panel-site-audit">
            <div class="cs-section-header" style="background:linear-gradient(90deg,#064e3b 0%,#047857 100%);border-left:3px solid #34d399;">
                <span>🔍 <?php esc_html_e( 'AI Site Auditor', 'cloudscale-devtools' ); ?></span>
                <span class="cs-header-hint"><?php esc_html_e( 'One-click scan — SEO, performance, content, and database health, all in under 60 seconds', 'cloudscale-devtools' ); ?></span>
                <?php CloudScale_DevTools::render_explain_btn( 'site-auditor', 'AI Site Auditor', [
                    [ 'name' => 'What it scans',    'rec' => 'Overview',     'html' => 'Analyses up to 100 published posts and pages for SEO gaps (missing titles, meta descriptions, thin content, duplicate titles, missing images), database health (expired transients, autoload bloat, post revisions, orphaned postmeta), plugin health, and WordPress configuration. All data is collected server-side — no external crawlers.' ],
                    [ 'name' => 'AI triage',        'rec' => 'Recommended',  'html' => 'When an Anthropic or Gemini API key is configured, findings are sent to the AI for prioritisation and tailored fix advice. Without a key, the audit still runs using built-in rule-based checks — you get all structural findings without the AI commentary.' ],
                    [ 'name' => 'Result caching',   'rec' => 'Info',         'html' => 'Audit results are cached after each run. Opening the Site Audit tab shows the last result immediately without re-running. Click <strong>Re-run Audit</strong> to refresh. The last-run timestamp is shown in the header.' ],
                    [ 'name' => 'Severity levels',  'rec' => 'Info',         'html' => '<strong>Critical</strong> — fix immediately (active security risk or data loss).<br><strong>High</strong> — fix this week (significant SEO or performance impact).<br><strong>Medium</strong> — plan to fix (noticeable but not urgent).<br><strong>Low / Info</strong> — best practice or informational.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">

                <p style="color:#4b5563;margin:0 0 6px;line-height:1.65;font-size:.95em;">
                    <?php esc_html_e( 'Scans your published content and database, then uses AI to produce a prioritised issue list scored by impact. Covers SEO gaps, thin content, missing images, duplicate titles, database bloat, and plugin health — no external crawlers, no data leaving your server.', 'cloudscale-devtools' ); ?>
                </p>
                <p style="color:#9ca3af;margin:0 0 14px;font-size:.88em;">
                    <?php esc_html_e( 'Without an AI key the audit still runs and returns rule-based findings. With an AI key you also get narrative summaries and prioritised recommendations.', 'cloudscale-devtools' ); ?>
                </p>

                <!-- Site Audit vs AI Security Scan comparison -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px;font-size:12px;">
                    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:10px 14px;">
                        <div style="font-weight:700;color:#15803d;margin-bottom:4px;">🔍 Site Audit — this tab</div>
                        <div style="color:#374151;line-height:1.5;"><?php esc_html_e( 'Content quality, SEO, database health, plugin status. Finds issues affecting visitors and search rankings.', 'cloudscale-devtools' ); ?></div>
                    </div>
                    <div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:6px;padding:10px 14px;">
                        <div style="font-weight:700;color:#1d4ed8;margin-bottom:4px;">🛡️ AI Security Scan</div>
                        <div style="color:#374151;line-height:1.5;"><?php esc_html_e( 'Security misconfigurations, exposed endpoints, headers, brute-force exposure. Finds issues attackers exploit.', 'cloudscale-devtools' ); ?></div>
                        <a href="<?php echo esc_url( $security_url ); ?>" style="display:inline-block;margin-top:6px;font-size:11px;color:#6366f1;font-weight:600;"><?php esc_html_e( 'Go to Security Scan →', 'cloudscale-devtools' ); ?></a>
                    </div>
                </div>

                <?php if ( ! $has_key ) : ?>
                <div style="background:#fff7ed;border-left:3px solid #f59e0b;padding:11px 16px;border-radius:0 6px 6px 0;margin-bottom:16px;font-size:13px;color:#92400e;">
                    <?php printf(
                        /* translators: %s: link to security tab */
                        esc_html__( 'Add an AI key for narrative summaries and deeper analysis. %s', 'cloudscale-devtools' ),
                        '<a href="' . esc_url( $security_url ) . '" style="color:#b45309;font-weight:600;">' . esc_html__( 'Add your key on the Security tab →', 'cloudscale-devtools' ) . '</a>'
                    ); ?>
                </div>
                <?php endif; ?>

                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:24px;">
                    <button id="csdt-site-audit-btn" class="cs-btn-primary" style="background:linear-gradient(135deg,#10b981,#059669);border-color:#059669;">
                        🚀 <?php esc_html_e( 'Run Site Audit', 'cloudscale-devtools' ); ?>
                    </button>
                    <span id="csdt-site-audit-progress" style="display:none;color:#6b7280;font-size:13px;">
                        ⏳ <span id="csdt-site-audit-progress-text"><?php esc_html_e( 'Gathering site data...', 'cloudscale-devtools' ); ?></span>
                    </span>
                </div>

                <div id="csdt-site-audit-results" style="display:none;"></div>

                <?php $audit_cache_check = get_option( 'csdt_site_audit_cache', null ); if ( ! $audit_cache_check ) : ?>
                <div id="csdt-site-audit-empty" style="text-align:center;padding:32px 20px;background:#f8fafc;border:2px dashed #d1fae5;border-radius:8px;margin-top:16px;">
                    <div style="font-size:2rem;margin-bottom:10px;">🔍</div>
                    <div style="font-weight:700;font-size:15px;color:#0f172a;margin-bottom:6px;"><?php esc_html_e( 'No audit results yet', 'cloudscale-devtools' ); ?></div>
                    <div style="font-size:13px;color:#6b7280;max-width:380px;margin:0 auto;"><?php esc_html_e( 'Click Run Site Audit above to scan your content, SEO, database, and plugin health in under 60 seconds.', 'cloudscale-devtools' ); ?></div>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <?php
    }

    public static function render_quick_fix_modals(): void {
        $sec_nonce = wp_json_encode( wp_create_nonce( CloudScale_DevTools::SECURITY_NONCE ) );
        $ajax_url  = wp_json_encode( admin_url( 'admin-ajax.php' ) );
        ?>
        <!-- DB Prefix Migration Modal -->
        <div id="csdt-db-prefix-modal" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.6);align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:8px;max-width:560px;width:92%;padding:24px 24px 20px;position:relative;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.4);">
                <button id="csdt-dbp-close" style="position:absolute;top:12px;right:14px;background:none;border:none;font-size:20px;cursor:pointer;color:#50575e;line-height:1;" title="Close">&#x2715;</button>
                <h3 style="margin:0 0 6px;font-size:16px;font-weight:700;">Fix Database Table Prefix</h3>
                <p style="font-size:13px;color:#50575e;margin:0 0 18px;">Renames all <code style="background:#f0f0f1;padding:1px 5px;border-radius:3px;">wp_</code> tables to a unique prefix and updates <code style="background:#f0f0f1;padding:1px 5px;border-radius:3px;">wp-config.php</code> automatically.</p>
                <!-- Step 1: Backup warning -->
                <div id="csdt-dbp-step1">
                    <div style="background:#fffbeb;border:1px solid #f59e0b;border-radius:6px;padding:14px 16px;margin-bottom:16px;">
                        <p style="margin:0 0 6px;font-weight:600;font-size:13px;color:#92400e;">&#x26A0; Back up your database before continuing</p>
                        <p style="margin:0 0 10px;font-size:13px;color:#78350f;">This operation renames tables directly in MySQL. If anything goes wrong mid-migration you will need a backup to recover.</p>
                        <a href="https://andrewbaker.ninja/wordpress-plugin-help/backup-restore-help/" target="_blank" style="color:#b45309;font-weight:600;font-size:13px;text-decoration:underline;">&#x2192; CloudScale Backup &amp; Restore &#x2014; install &amp; create a backup first</a>
                    </div>
                    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#166534;">
                        &#x21A9; <strong>Rollback is saved automatically</strong> &#x2014; after the rename a rollback snapshot is stored. You can undo from the <strong>Home tab</strong> at any time.
                    </div>
                    <label style="display:flex;align-items:flex-start;gap:10px;font-size:13px;cursor:pointer;line-height:1.6;">
                        <input type="checkbox" id="csdt-dbp-backup-ok" style="margin-top:3px;flex-shrink:0;">
                        I have a recent database backup and understand this will rename my live database tables
                    </label>
                    <div style="margin-top:16px;">
                        <button id="csdt-dbp-preflight-btn" class="cs-btn-primary" disabled style="opacity:.5;">Next: Pre-flight check &#x2192;</button>
                    </div>
                </div>
                <!-- Step 2: Preflight results -->
                <div id="csdt-dbp-step2" style="display:none;">
                    <div id="csdt-dbp-preflight-out" style="background:#f6f7f7;border-radius:6px;padding:14px 16px;font-size:13px;line-height:1.6;margin-bottom:16px;"></div>
                    <div style="display:flex;gap:8px;">
                        <button id="csdt-dbp-back-btn" class="cs-btn-secondary">&#x2190; Back</button>
                        <button id="csdt-dbp-migrate-btn" class="cs-btn-primary">&#x26A1; Rename Tables Now</button>
                    </div>
                </div>
                <!-- Step 3: Result -->
                <div id="csdt-dbp-step3" style="display:none;">
                    <div id="csdt-dbp-result-out" style="font-size:13px;line-height:1.6;"></div>
                </div>
            </div>
        </div>

        <!-- fail2ban config modal -->
        <div id="csdt-fail2ban-modal" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.6);align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:10px;padding:28px 30px;max-width:600px;width:92%;max-height:90vh;overflow-y:auto;position:relative;box-shadow:0 8px 40px rgba(0,0,0,.3);">
                <button id="csdt-f2b-close" data-cs-modal-close="csdt-fail2ban-modal" style="position:absolute;top:12px;right:14px;background:none;border:none;font-size:20px;cursor:pointer;color:#50575e;line-height:1;" title="Close">&#x2715;</button>
                <h3 style="margin:0 0 6px;font-size:16px;">SSH Brute-Force Protection &#x2014; fail2ban</h3>
                <p style="margin:0 0 16px;font-size:13px;color:#50575e;">fail2ban monitors SSH login failures and automatically blocks offending IPs at the firewall level. Install it on your server, then use the config below.</p>
                <p style="margin:0 0 8px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#64748b;">1. Install &amp; enable</p>
                <pre style="background:#0f172a;color:#e2e8f0;padding:12px 14px;border-radius:6px;font-size:12px;overflow-x:auto;margin:0 0 16px;white-space:pre;">sudo apt install fail2ban -y
sudo systemctl enable fail2ban
sudo systemctl start fail2ban</pre>
                <p style="margin:0 0 8px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#64748b;">2. Create jail config &#x2014; <code style="font-size:11px;">/etc/fail2ban/jail.local</code></p>
                <pre id="csdt-f2b-config" style="background:#0f172a;color:#e2e8f0;padding:12px 14px;border-radius:6px;font-size:12px;overflow-x:auto;margin:0 0 12px;white-space:pre;">[DEFAULT]
bantime  = 3600
findtime = 600
maxretry = 5

[sshd]
enabled  = true
port     = ssh
logpath  = %(sshd_log)s
backend  = %(sshd_backend)s
maxretry = 3
bantime  = 86400</pre>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button id="csdt-f2b-copy" class="cs-btn-primary cs-btn-sm" data-cs-copy-from="csdt-f2b-config">Copy Config</button>
                    <button class="cs-btn-secondary cs-btn-sm" data-cs-modal-close="csdt-fail2ban-modal">Close</button>
                </div>
                <p style="margin:16px 0 0;font-size:12px;color:#64748b;">After saving <code>/etc/fail2ban/jail.local</code> run: <code>sudo systemctl restart fail2ban</code> &#x2014; verify with: <code>sudo fail2ban-client status sshd</code></p>
            </div>
        </div>

        <!-- fail2ban Docker detection modal -->
        <div id="csdt-fail2ban-docker-modal" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.6);align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:10px;padding:28px 30px;max-width:620px;width:92%;max-height:90vh;overflow-y:auto;position:relative;box-shadow:0 8px 40px rgba(0,0,0,.3);">
                <button data-cs-modal-close="csdt-fail2ban-docker-modal" style="position:absolute;top:12px;right:14px;background:none;border:none;font-size:20px;cursor:pointer;color:#50575e;line-height:1;" title="Close">&#x2715;</button>
                <h3 style="margin:0 0 6px;font-size:16px;">Enable fail2ban Detection</h3>
                <p style="margin:0 0 16px;font-size:13px;color:#50575e;">WordPress is running inside a Docker container that cannot see host services. To prove whether fail2ban is installed and running, expose its log file into the container &#x2014; a read-only mount, one line in your docker-compose.yml.</p>
                <p style="margin:0 0 8px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#64748b;">Step 1 &#x2014; Add this to your docker-compose.yml (under WordPress <code>volumes:</code>)</p>
                <pre id="csdt-f2b-docker-vol" style="background:#0f172a;color:#e2e8f0;padding:12px 14px;border-radius:6px;font-size:12px;overflow-x:auto;margin:0 0 8px;white-space:pre;">      - /var/log/fail2ban.log:/var/log/fail2ban.log:ro</pre>
                <button class="cs-btn-secondary cs-btn-sm" style="margin-bottom:16px;" data-cs-copy-from="csdt-f2b-docker-vol">Copy line</button>
                <p style="margin:0 0 8px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#64748b;">Step 2 &#x2014; Restart the WordPress container</p>
                <pre id="csdt-f2b-docker-restart" style="background:#0f172a;color:#e2e8f0;padding:12px 14px;border-radius:6px;font-size:12px;overflow-x:auto;margin:0 0 8px;white-space:pre;">docker compose up -d wordpress</pre>
                <button class="cs-btn-secondary cs-btn-sm" style="margin-bottom:16px;" data-cs-copy-from="csdt-f2b-docker-restart">Copy command</button>
                <p style="margin:0 0 8px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#64748b;">Step 3 &#x2014; Refresh this page</p>
                <p style="margin:0 0 16px;font-size:13px;color:#50575e;">The plugin will now read <code>/var/log/fail2ban.log</code> directly and report whether fail2ban is installed and running.</p>
                <div style="background:#fefce8;border:1px solid #fde047;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:12px;color:#713f12;">
                    <strong>fail2ban not installed?</strong> Click Close and use the "Copy fail2ban config" button to install it on your host first.
                </div>
                <div style="display:flex;justify-content:flex-end;">
                    <button class="cs-btn-secondary cs-btn-sm" data-cs-modal-close="csdt-fail2ban-docker-modal">Close</button>
                </div>
            </div>
        </div>
        <?php
    }


    public static function add_cron_schedules( array $schedules ): array {
        $schedules['csdt_monthly'] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display'  => __( 'Once Monthly', 'cloudscale-devtools' ),
        ];
        $schedules['csdt_every_1min'] = [
            'interval' => MINUTE_IN_SECONDS,
            'display'  => __( 'Every Minute', 'cloudscale-devtools' ),
        ];
        $schedules['csdt_every_2min'] = [
            'interval' => 2 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 2 Minutes', 'cloudscale-devtools' ),
        ];
        $schedules['csdt_every_5min'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 5 Minutes', 'cloudscale-devtools' ),
        ];
        return $schedules;
    }

    public static function ajax_save_schedule(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $enabled   = ! empty( $_POST['enabled'] ) && $_POST['enabled'] === '1';
        $freq      = in_array( $_POST['freq']  ?? '', [ 'weekly', 'monthly' ], true ) ? sanitize_key( $_POST['freq'] ) : 'weekly';
        $type      = in_array( $_POST['type']  ?? '', [ 'standard', 'deep' ], true )  ? sanitize_key( $_POST['type'] ) : 'deep';

        update_option( 'csdt_scan_schedule_enabled',    $enabled ? '1' : '0', false );
        update_option( 'csdt_scan_schedule_freq',       $freq,                false );
        update_option( 'csdt_scan_schedule_type',       $type,                false );

        // Re-register cron event
        wp_clear_scheduled_hook( 'csdt_scheduled_scan' );
        $next_run = null;
        if ( $enabled ) {
            $recurrence = $freq === 'monthly' ? 'csdt_monthly' : 'weekly';
            wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, 'csdt_scheduled_scan' );
            $next_run = wp_next_scheduled( 'csdt_scheduled_scan' );
        }

        wp_send_json_success( [
            'saved'    => true,
            'next_run' => $next_run ? wp_date( 'D j M Y, g:ia', $next_run ) : null,
        ] );
    }

    public static function ajax_save_notify(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $email_enabled = ! empty( $_POST['email_enabled'] ) && $_POST['email_enabled'] === '1';
        $email_to      = sanitize_email( wp_unslash( $_POST['email_to'] ?? '' ) );
        $ntfy_url      = esc_url_raw( wp_unslash( $_POST['ntfy_url']   ?? '' ) );
        $ntfy_tok      = sanitize_text_field( wp_unslash( $_POST['ntfy_token'] ?? '' ) );
        $ntfy_tok      = trim( str_replace( "\u{2022}", '', $ntfy_tok ) );

        update_option( 'csdt_scan_schedule_email',  $email_enabled ? '1' : '0', false );
        update_option( 'csdt_notify_email',         $email_to,                  false );
        update_option( 'csdt_scan_schedule_ntfy_url', $ntfy_url,                false );
        if ( $ntfy_tok !== '' ) {
            update_option( 'csdt_scan_schedule_ntfy_token', $ntfy_tok, false );
        }

        wp_send_json_success( [ 'saved' => true ] );
    }

    public static function run_scheduled_scan(): void {
        $type = get_option( 'csdt_scan_schedule_type', 'deep' );
        if ( $type === 'deep' ) {
            self::cron_deep_scan();
        } else {
            CSDT_Vuln_Scan::cron_vuln_scan();
        }

        // Fetch the freshly stored result to notify
        $result = $type === 'deep'
            ? get_option( 'csdt_deep_scan_v1' )
            : get_option( 'csdt_security_scan_v2' );

        if ( $result && isset( $result['report'] ) ) {
            self::send_scan_notifications( $result['report'], $type );
        }
    }

    private static function send_scan_notifications( array $report, string $type ): void {
        $score       = $report['score']       ?? '?';
        $label       = $report['score_label'] ?? '';
        $summary     = $report['summary']     ?? '';
        $critical    = count( $report['critical'] ?? [] );
        $high        = count( $report['high']     ?? [] );
        $type_label  = $type === 'deep' ? 'AI Deep Dive Cyber Audit' : 'AI Cyber Audit';
        $site        = get_bloginfo( 'name' ) ?: home_url();
        $admin_url   = admin_url( 'tools.php?page=' . CloudScale_DevTools::TOOLS_SLUG . '&tab=security' );

        $subject = sprintf( 'CSDT: Scan %s/100 %s — %s', $score, $label, $site );
        $body    = sprintf(
            "%s completed for %s\n\nScore: %s/100 (%s)\nCritical: %d | High: %d\n\n%s\n\nView full report: %s",
            $type_label, $site, $score, $label, $critical, $high, $summary, $admin_url
        );

        // Email notification
        if ( get_option( 'csdt_scan_schedule_email', '1' ) === '1' ) {
            $to = get_option( 'csdt_notify_email', '' ) ?: get_option( 'admin_email' );
            wp_mail( $to, $subject, $body );
        }

        // ntfy.sh push notification
        if ( get_option( 'csdt_ntfy_scan_result', '1' ) === '1' ) {
        $ntfy_url = get_option( 'csdt_scan_schedule_ntfy_url', '' );
        if ( $ntfy_url ) {
            $priority = $critical > 0 ? 'urgent' : ( $high > 0 ? 'high' : 'default' );
            $headers  = [
                'Title'    => 'CS > Cyber: ' . ( parse_url( get_site_url(), PHP_URL_HOST ) ?: '' ) . ': ' . $subject,
                'Priority' => $priority,
                'Tags'     => $score >= 75 ? 'white_check_mark' : ( $score >= 55 ? 'warning' : 'rotating_light' ),
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
        } // end csdt_ntfy_scan_result check
    }


    public static function ajax_apply_quick_fix(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $action = isset( $_POST['fix_action'] ) ? sanitize_key( wp_unslash( $_POST['fix_action'] ) ) : '';
        $fix_id = isset( $_POST['fix_id'] )     ? sanitize_key( wp_unslash( $_POST['fix_id'] ) )     : '';

        if ( $action === 'list' ) {
            wp_send_json_success( [ 'fixes' => self::get_quick_fixes() ] );
            return;
        }

        if ( $action === 'dismiss' ) {
            $allowed_acks = [ 'cron_disabled_ack' ];
            if ( ! in_array( $fix_id, $allowed_acks, true ) ) {
                wp_send_json_error( 'Invalid dismiss id' );
                return;
            }
            update_option( 'csdt_' . $fix_id, '1' );
            wp_send_json_success( [ 'message' => 'Acknowledged' ] );
            return;
        }

        if ( $action !== 'apply' ) {
            wp_send_json_error( 'Invalid action' );
            return;
        }

        switch ( $fix_id ) {
            case 'security_headers':
                update_option( 'csdt_devtools_safe_headers_enabled', '1' );
                delete_transient( 'csdt_sec_headers_check' );
                break;
            case 'security_headers_ack':
                update_option( 'csdt_devtools_sec_headers_ack', '1' );
                delete_transient( 'csdt_sec_headers_check' );
                break;
            case 'defimg_no_fallback_ack':
                update_option( 'csdt_defimg_no_fallback_ack', '1' );
                break;
            case 'app_pw_2fa_ack':
                update_option( 'csdt_devtools_app_pw_2fa_ack', '1' );
                break;
            case 'disable_pingbacks':
                update_option( 'default_ping_status',   'closed' );
                update_option( 'default_pingback_flag', 0 );
                break;
            case 'close_registration':
                update_option( 'users_can_register', 0 );
                break;
            case 'disable_app_passwords':
                update_option( 'csdt_devtools_disable_app_passwords', '1' );
                break;
            case 'hide_wp_version':
                update_option( 'csdt_devtools_hide_wp_version', '1' );
                break;
            case 'close_comments':
                update_option( 'default_comment_status', 'closed' );
                break;
            case 'cron_health':
                // Reschedule missing core cleanup events.
                if ( ! wp_next_scheduled( 'wp_scheduled_delete' ) ) {
                    wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wp_scheduled_delete' );
                }
                if ( ! wp_next_scheduled( 'delete_expired_transients' ) ) {
                    wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'delete_expired_transients' );
                }
                if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
                    // Can't run events — WP-Cron is disabled. Acknowledge the finding so it
                    // stops reappearing: the admin has confirmed a system cron handles this.
                    update_option( 'csdt_cron_disabled_ack', '1', false );
                    wp_send_json_success( [ 'message' => 'Acknowledged — ensure your system cron calls wp-cli cron event run --due-now at least every 15 minutes.' ] );
                    return;
                }
                do_action( 'delete_expired_transients' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
                do_action( 'wp_scheduled_delete' );       // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
                break;
            case 'expired_transients':
                global $wpdb;
                // Delete expired timeout markers and their orphaned data keys in one JOIN.
                $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    "DELETE a, b
                     FROM {$wpdb->options} a
                     LEFT JOIN {$wpdb->options} b
                         ON b.option_name = REPLACE( a.option_name, '_transient_timeout_', '_transient_' )
                     WHERE a.option_name LIKE '_transient_timeout_%'
                       AND CAST( a.option_value AS UNSIGNED ) < UNIX_TIMESTAMP()"
                );
                break;
            case 'wpconfig_perms':
                $cfg_file = ABSPATH . 'wp-config.php';
                if ( ! file_exists( $cfg_file ) || ! is_writable( dirname( $cfg_file ) ) ) {
                    wp_send_json_error( 'wp-config.php not found or directory not writable.' );
                    return;
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
                if ( ! chmod( $cfg_file, 0400 ) ) {
                    wp_send_json_error( 'chmod failed — server may restrict permission changes.' );
                    return;
                }
                wp_send_json_success( [ 'fixes' => self::get_quick_fixes(), 'message' => 'Permissions set to 0400 — wp-config.php is now read-only.' ] );
                return;
            case 'block_debug_log':
                $old_log = WP_CONTENT_DIR . '/debug.log';
                $new_log = rtrim( dirname( rtrim( ABSPATH, '/\\' ) ), '/\\' ) . '/wordpress-debug.log';

                // 1. Migrate existing content and delete from web root.
                if ( file_exists( $old_log ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                    $existing = file_get_contents( $old_log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                    if ( $existing !== false && $existing !== '' ) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                        file_put_contents( $new_log, $existing, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
                    }
                    wp_delete_file( $old_log );
                }

                // 2. Rewrite WP_DEBUG_LOG in wp-config.php so WordPress writes to the safe
                //    path from the very first line of execution — before any mu-plugin runs.
                $cfg_file     = ABSPATH . 'wp-config.php';
                $cfg_updated  = false;
                if ( is_readable( $cfg_file ) && is_writable( $cfg_file ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                    $cfg = file_get_contents( $cfg_file );
                    $safe_path  = str_replace( "'", "\\'", $new_log );
                    $new_define = "define( 'WP_DEBUG_LOG', '" . $safe_path . "' );";
                    $pattern    = "/define\s*\(\s*['\"]WP_DEBUG_LOG['\"]\s*,\s*(?:true|false|'[^']*'|\"[^\"]*\")\s*\)\s*;/i";
                    if ( preg_match( $pattern, $cfg ) ) {
                        $cfg = preg_replace( $pattern, $new_define, $cfg );
                    } else {
                        // No existing define — insert before the "stop editing" marker.
                        $cfg = preg_replace(
                            '/\/\*\s*That\'s all[^*]*\*\//is',
                            $new_define . "\n\n/* That's all, stop editing! Happy publishing. */",
                            $cfg,
                            1
                        );
                    }
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                    if ( $cfg && file_put_contents( $cfg_file, $cfg ) !== false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
                        $cfg_updated = true;
                    }
                }

                // 3. Store new path and write mu-plugin as belt-and-suspenders fallback.
                update_option( 'csdt_debug_log_path', $new_log, false );
                $mu_dir = WP_CONTENT_DIR . '/mu-plugins';
                if ( ! is_dir( $mu_dir ) ) { wp_mkdir_p( $mu_dir ); }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                file_put_contents(
                    $mu_dir . '/csdt-secure-logs.php',
                    '<?php' . "\n" .
                    '// Belt-and-suspenders: redirect error_log to safe path — by CloudScale DevTools.' . "\n" .
                    '@ini_set( \'error_log\', ' . var_export( $new_log, true ) . ' );' . "\n"
                );

                if ( ! $cfg_updated ) {
                    // wp-config.php not writable — mu-plugin is the only protection.
                    // Return success with a warning so the caller can surface it.
                    wp_send_json_success( [
                        'fixes'   => self::get_quick_fixes(),
                        'warning' => 'debug.log deleted and mu-plugin installed, but wp-config.php is not writable — WP_DEBUG_LOG still points to the old path. The file may reappear on the next PHP error. To make this permanent, set WP_DEBUG_LOG to \'' . $new_log . '\' in wp-config.php manually.',
                    ] );
                    return;
                }
                break;
            case 'csp_unsafe_inline':
                update_option( 'csdt_csp_nonces_enabled', '1' );
                // Auto-enable the plugin's own CSP in Report-Only mode first — never jump straight to Enforce
                if ( get_option( 'csdt_devtools_csp_enabled', '0' ) !== '1' ) {
                    update_option( 'csdt_devtools_csp_enabled', '1' );
                    update_option( 'csdt_devtools_csp_mode', 'report_only' );
                }
                delete_transient( 'csdt_csp_unsafe_check' );
                break;
            case 'csp_inline_ack':
                update_option( 'csdt_csp_inline_ack', '1' );
                delete_transient( 'csdt_csp_unsafe_check' );
                break;
            case 'enforce_2fa_admins':
                update_option( 'csdt_devtools_2fa_force_admins', '1' );
                break;
            case 'disable_wp_cron':
                $cfg_file = ABSPATH . 'wp-config.php';
                if ( ! is_readable( $cfg_file ) || ! is_writable( $cfg_file ) ) {
                    wp_send_json_success( [
                        'fixes'   => self::get_quick_fixes(),
                        'warning' => "wp-config.php is not writable. Add this line manually before the stop-editing marker:\n\ndefine( 'DISABLE_WP_CRON', true );\n\nThen add a system cron: */10 * * * * wp cron event run --due-now --path=/var/www/html",
                    ] );
                    return;
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                $cfg        = file_get_contents( $cfg_file );
                $new_define = "define( 'DISABLE_WP_CRON', true );";
                $pattern    = "/define\s*\(\s*['\"]DISABLE_WP_CRON['\"]\s*,\s*(?:true|false)\s*\)\s*;/i";
                if ( preg_match( $pattern, $cfg ) ) {
                    $cfg = preg_replace( $pattern, $new_define, $cfg );
                } else {
                    $cfg = preg_replace(
                        '/\/\*\s*That\'s all[^*]*\*\//is',
                        $new_define . "\n\n/* That's all, stop editing! Happy publishing. */",
                        $cfg,
                        1
                    );
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                if ( ! $cfg || file_put_contents( $cfg_file, $cfg ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
                    wp_send_json_error( 'Failed to write wp-config.php' );
                    return;
                }
                wp_send_json_success( [
                    'fixes'   => self::get_quick_fixes(),
                    'warning' => "DISABLE_WP_CRON set in wp-config.php. Scheduled events will no longer run automatically — add a system cron to keep them firing:\n*/10 * * * * wp cron event run --due-now --path=/var/www/html",
                ] );
                return;
            case 'enable_auto_updates':
                $cfg_file = ABSPATH . 'wp-config.php';
                if ( ! is_readable( $cfg_file ) || ! is_writable( $cfg_file ) ) {
                    wp_send_json_success( [
                        'fixes'   => self::get_quick_fixes(),
                        'warning' => "wp-config.php is not writable (permissions may be set to 0400). Remove the following constants manually via SSH:\n\ndefine( 'DISALLOW_FILE_MODS', true );\ndefine( 'AUTOMATIC_UPDATER_DISABLED', true );\ndefine( 'WP_AUTO_UPDATE_CORE', false );",
                    ] );
                    return;
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                $cfg = file_get_contents( $cfg_file );
                // Remove DISALLOW_FILE_MODS=true — this is the primary blocker for both updates and plugin deletion
                $cfg = preg_replace(
                    "/define\s*\(\s*['\"]DISALLOW_FILE_MODS['\"]\s*,\s*true\s*\)\s*;[^\n]*\n?/i",
                    '',
                    $cfg
                );
                // Remove AUTOMATIC_UPDATER_DISABLED=true
                $cfg = preg_replace(
                    "/define\s*\(\s*['\"]AUTOMATIC_UPDATER_DISABLED['\"]\s*,\s*true\s*\)\s*;[^\n]*\n?/i",
                    '',
                    $cfg
                );
                // Remove WP_AUTO_UPDATE_CORE=false so WP falls back to default (minor updates enabled)
                $cfg = preg_replace(
                    "/define\s*\(\s*['\"]WP_AUTO_UPDATE_CORE['\"]\s*,\s*false\s*\)\s*;[^\n]*\n?/i",
                    '',
                    $cfg
                );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                if ( ! $cfg || file_put_contents( $cfg_file, $cfg ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
                    wp_send_json_error( 'Failed to write wp-config.php' );
                    return;
                }
                wp_send_json_success( [ 'fixes' => self::get_quick_fixes() ] );
                return;
            case 'delete_inactive_plugins':
                if ( ! function_exists( 'get_plugins' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                if ( ! function_exists( 'delete_plugins' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                $all_plugins    = get_plugins();
                $active_plugins = (array) get_option( 'active_plugins', [] );
                $inactive = array_keys( array_filter( $all_plugins, fn( $data, $k ) => ! in_array( $k, $active_plugins, true ), ARRAY_FILTER_USE_BOTH ) );
                if ( empty( $inactive ) ) {
                    wp_send_json_success( [ 'fixes' => self::get_quick_fixes(), 'message' => 'No inactive plugins found.' ] );
                    return;
                }
                $result = delete_plugins( $inactive );
                if ( is_wp_error( $result ) ) {
                    wp_send_json_error( $result->get_error_message() );
                    return;
                }
                $count = count( $inactive );
                wp_send_json_success( [
                    'fixes'   => self::get_quick_fixes(),
                    'message' => sprintf( 'Deleted %d inactive plugin%s from disk.', $count, $count === 1 ? '' : 's' ),
                ] );
                return;
            case 'orphaned_cron_hooks':
                $core_hooks = [
                    'wp_scheduled_delete', 'delete_expired_transients', 'wp_version_check',
                    'wp_update_plugins', 'wp_update_themes', 'wp_scheduled_auto_draft_delete',
                    'recovery_mode_clean_expired_keys', 'wp_privacy_delete_old_export_files',
                    'wp_site_health_scheduled_check', 'wp_update_user_counts',
                    'wp_https_detection', 'wp_privacy_delete_old_export_files',
                ];
                $removed = 0;
                $crons   = _get_cron_array();
                if ( is_array( $crons ) ) {
                    foreach ( $crons as $hooks ) {
                        foreach ( array_keys( $hooks ) as $hook ) {
                            if ( in_array( $hook, $core_hooks, true ) ) { continue; }
                            if ( ! has_action( $hook ) ) {
                                wp_clear_scheduled_hook( $hook );
                                $removed++;
                            }
                        }
                    }
                }
                wp_send_json_success( [
                    'fixes'   => self::get_quick_fixes(),
                    'message' => $removed > 0
                        ? sprintf( 'Removed %d orphaned cron hook%s.', $removed, $removed === 1 ? '' : 's' )
                        : 'No orphaned hooks found.',
                ] );
                return;
            case 'crossorigin_scripts':
                update_option( 'csdt_crossorigin_scripts', '1' );
                delete_transient( 'csdt_crossorigin_check' );
                wp_send_json_success( [
                    'fixes'   => self::get_quick_fixes(),
                    'message' => 'crossorigin="anonymous" will now be added to all enqueued third-party scripts via the script_loader_tag filter.',
                ] );
                return;
            case 'ads_dedup':
                update_option( 'csdt_ads_dedup', '1' );
                delete_transient( 'csdt_home_html_ads_check' );
                wp_send_json_success( [
                    'fixes'   => self::get_quick_fixes(),
                    'message' => 'AdSense deduplication patch enabled — duplicate push calls will be silently dropped on every page load.',
                ] );
                return;
            default:
                wp_send_json_error( 'Unknown fix ID' );
                return;
        }

        wp_send_json_success( [ 'fixes' => self::get_quick_fixes() ] );
    }

    public static function ajax_db_prefix_preflight(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;
        $current_prefix = $wpdb->prefix;

        if ( $current_prefix !== 'wp_' ) {
            wp_send_json_error( 'Database prefix is already "' . esc_html( $current_prefix ) . '" — nothing to migrate.' );
            return;
        }

        $cfg_file     = ABSPATH . 'wp-config.php';
        $cfg_writable = is_readable( $cfg_file ) && is_writable( $cfg_file );
        $cfg_content  = is_readable( $cfg_file ) ? file_get_contents( $cfg_file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

        // Detect wp-config.php format: must have a static $table_prefix = 'wp_'; to be editable
        $cfg_static   = (bool) preg_match( '/\$table_prefix\s*=\s*[\'"]wp_[\'"]\s*;/', $cfg_content );
        $cfg_getenv   = (bool) preg_match( '/\$table_prefix\s*=\s*getenv/', $cfg_content );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $all_tables  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $current_prefix ) . '%' ) );
        $prefix_len  = strlen( $current_prefix );
        $core        = self::core_table_suffixes();
        $tables      = array_values( array_filter( $all_tables, fn( $t ) => in_array( substr( $t, $prefix_len ), $core, true ) ) );
        $skipped     = count( $all_tables ) - count( $tables );

        // Generate a unique prefix and stash it for 5 minutes
        $new_prefix = 'cs' . substr( md5( wp_generate_uuid4() ), 0, 6 ) . '_';
        set_transient( 'csdt_db_prefix_proposed', $new_prefix, 300 );

        wp_send_json_success( [
            'current_prefix' => $current_prefix,
            'new_prefix'     => $new_prefix,
            'table_count'    => count( $tables ),
            'tables'         => $tables,
            'skipped_count'  => $skipped,
            'cfg_writable'   => $cfg_writable,
            'cfg_static'     => $cfg_static,
            'cfg_getenv'     => $cfg_getenv,
        ] );
    }

    public static function ajax_db_prefix_migrate(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;
        $current_prefix = $wpdb->prefix;

        if ( $current_prefix !== 'wp_' ) {
            wp_send_json_error( 'Prefix is not wp_ — aborting.' );
            return;
        }

        $new_prefix = get_transient( 'csdt_db_prefix_proposed' );
        if ( ! $new_prefix || ! preg_match( '/^cs[a-f0-9]{6}_$/', $new_prefix ) ) {
            wp_send_json_error( 'Pre-flight token expired. Please click "← Back" and run the pre-flight check again.' );
            return;
        }

        $cfg_file = ABSPATH . 'wp-config.php';
        if ( ! is_readable( $cfg_file ) ) {
            wp_send_json_error( 'wp-config.php is not readable.' );
            return;
        }
        $original_perms = fileperms( $cfg_file );
        $needs_chmod    = ! is_writable( $cfg_file );
        if ( $needs_chmod && ! @chmod( $cfg_file, 0644 ) ) {
            wp_send_json_error( 'wp-config.php is read-only and could not be unlocked. Run: chmod 644 ' . basename( $cfg_file ) . ' then retry.' );
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $all_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $current_prefix ) . '%' ) );
        $prefix_len = strlen( $current_prefix );
        $core       = self::core_table_suffixes();
        $tables     = array_values( array_filter( $all_tables, fn( $t ) => in_array( substr( $t, $prefix_len ), $core, true ) ) );

        if ( empty( $tables ) ) {
            wp_send_json_error( 'No core tables found with prefix "' . esc_html( $current_prefix ) . '".' );
            return;
        }

        $renamed = [];
        $errors  = [];

        foreach ( $tables as $table ) {
            $suffix    = substr( $table, strlen( $current_prefix ) );
            $new_table = $new_prefix . $suffix;
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result = $wpdb->query( 'RENAME TABLE `' . esc_sql( $table ) . '` TO `' . esc_sql( $new_table ) . '`' );
            if ( $result === false ) {
                $errors[] = $table;
            } else {
                $renamed[] = [ 'from' => $table, 'to' => $new_table ];
            }
        }

        if ( ! empty( $errors ) ) {
            foreach ( $renamed as $pair ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query( 'RENAME TABLE `' . esc_sql( $pair['to'] ) . '` TO `' . esc_sql( $pair['from'] ) . '`' );
            }
            wp_send_json_error( 'Migration failed and was rolled back. Could not rename: ' . implode( ', ', $errors ) );
            return;
        }

        // Helper: undo table renames (used in rollback closures below).
        $undo_tables = static function () use ( $wpdb, $renamed ): void {
            foreach ( $renamed as $pair ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query( 'RENAME TABLE `' . esc_sql( $pair['to'] ) . '` TO `' . esc_sql( $pair['from'] ) . '`' );
            }
        };

        // Update option_name keys that carried the old prefix (e.g. wp_user_roles).
        // If this fails we undo the table renames so nothing is left half-migrated.
        $options_table = $new_prefix . 'options';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $opts_result = $wpdb->query( $wpdb->prepare(
            'UPDATE `' . esc_sql( $options_table ) . '` SET option_name = REPLACE(option_name, %s, %s) WHERE option_name LIKE %s',
            $current_prefix,
            $new_prefix,
            $wpdb->esc_like( $current_prefix ) . '%'
        ) );
        if ( $opts_result === false ) {
            $undo_tables();
            wp_send_json_error( 'Could not update option_name keys — migration rolled back. DB: ' . $wpdb->last_error );
            return;
        }
        $opts_rows = (int) $wpdb->rows_affected;

        // Update meta_key entries that carried the old prefix (e.g. wp_capabilities).
        // On failure: undo the options update AND the table renames.
        $usermeta_table = $new_prefix . 'usermeta';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $meta_result = $wpdb->query( $wpdb->prepare(
            'UPDATE `' . esc_sql( $usermeta_table ) . '` SET meta_key = REPLACE(meta_key, %s, %s) WHERE meta_key LIKE %s',
            $current_prefix,
            $new_prefix,
            $wpdb->esc_like( $current_prefix ) . '%'
        ) );
        if ( $meta_result === false ) {
            // Undo options update then table renames.
            $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'UPDATE `' . esc_sql( $options_table ) . '` SET option_name = REPLACE(option_name, %s, %s) WHERE option_name LIKE %s',
                $new_prefix, $current_prefix, $wpdb->esc_like( $new_prefix ) . '%'
            ) );
            $undo_tables();
            wp_send_json_error( 'Could not update meta_key entries — migration rolled back. DB: ' . $wpdb->last_error );
            return;
        }
        $meta_rows = (int) $wpdb->rows_affected;

        // Rewrite $table_prefix in wp-config.php — handles both static and Docker getenv_docker() forms.
        // On any failure: undo meta, options, and table renames so the DB is fully restored.
        $undo_meta_and_tables = static function () use ( $wpdb, $options_table, $usermeta_table, $current_prefix, $new_prefix, $undo_tables ): void {
            $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'UPDATE `' . esc_sql( $usermeta_table ) . '` SET meta_key = REPLACE(meta_key, %s, %s) WHERE meta_key LIKE %s',
                $new_prefix, $current_prefix, $wpdb->esc_like( $new_prefix ) . '%'
            ) );
            $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'UPDATE `' . esc_sql( $options_table ) . '` SET option_name = REPLACE(option_name, %s, %s) WHERE option_name LIKE %s',
                $new_prefix, $current_prefix, $wpdb->esc_like( $new_prefix ) . '%'
            ) );
            $undo_tables();
        };

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $cfg     = file_get_contents( $cfg_file );
        $new_cfg = preg_replace(
            '/\$table_prefix\s*=\s*getenv_docker\s*\([^)]+\)\s*;/',
            "\$table_prefix = '" . $new_prefix . "';",
            $cfg
        );
        if ( $new_cfg === null || $new_cfg === $cfg ) {
            // Fall back to plain static pattern
            $new_cfg = preg_replace(
                '/\$table_prefix\s*=\s*[\'"]wp_[\'"]\s*;/',
                "\$table_prefix = '" . $new_prefix . "';",
                $cfg
            );
        }

        if ( $new_cfg === null || $new_cfg === $cfg ) {
            $undo_meta_and_tables();
            wp_send_json_error( 'Could not update $table_prefix in wp-config.php. Migration rolled back.' );
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if ( file_put_contents( $cfg_file, $new_cfg ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
            if ( $needs_chmod ) { @chmod( $cfg_file, $original_perms ); }
            $undo_meta_and_tables();
            wp_send_json_error( 'Could not write wp-config.php. Migration rolled back.' );
            return;
        }

        // Restore original permissions (e.g. 0400) after successful write
        if ( $needs_chmod ) { @chmod( $cfg_file, $original_perms ); }

        // Flush object cache — stale Redis/Memcached entries keyed to old prefix cause 404s
        wp_cache_flush();

        // Store rollback info so the user can undo at any time
        update_option( 'csdt_db_prefix_rollback', [
            'old_prefix'   => $current_prefix,
            'new_prefix'   => $new_prefix,
            'tables'       => $renamed,   // [['from'=>..., 'to'=>...], ...]
            'cfg_getenv'   => str_contains( $cfg, 'getenv_docker' ) ? false : false, // static form after write
            'cfg_original' => $cfg,       // full original wp-config.php content
            'time'         => time(),
        ] );

        delete_transient( 'csdt_db_prefix_proposed' );

        wp_send_json_success( [
            'new_prefix'        => $new_prefix,
            'tables_renamed'    => count( $renamed ),
            'options_updated'   => $opts_rows,
            'usermeta_updated'  => $meta_rows,
            'message'           => 'Success! Renamed ' . count( $renamed ) . ' tables, updated ' . $opts_rows . ' option rows and ' . $meta_rows . ' user meta rows to prefix "' . $new_prefix . '".',
        ] );
    }

    public static function ajax_db_prefix_rollback(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $info = get_option( 'csdt_db_prefix_rollback' );
        if ( ! $info || empty( $info['tables'] ) ) {
            wp_send_json_error( 'No rollback data found.' );
            return;
        }

        global $wpdb;
        $errors  = [];
        $reverted = 0;

        foreach ( $info['tables'] as $pair ) {
            $from = sanitize_text_field( $pair['to'] );   // current name (new prefix)
            $to   = sanitize_text_field( $pair['from'] ); // original name (old prefix)
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $r = $wpdb->query( 'RENAME TABLE `' . esc_sql( $from ) . '` TO `' . esc_sql( $to ) . '`' );
            if ( $r === false ) {
                $errors[] = $from;
            } else {
                $reverted++;
            }
        }

        if ( ! empty( $errors ) ) {
            wp_send_json_error( 'Rollback partially failed. Could not rename: ' . implode( ', ', $errors ) );
            return;
        }

        $old_prefix     = $info['old_prefix'];
        $new_prefix     = $info['new_prefix'];
        $options_table  = $old_prefix . 'options';
        $usermeta_table = $old_prefix . 'usermeta';

        // Helper: re-apply the forward migration names so the DB isn't left half-reverted.
        $undo_rollback_tables = static function () use ( $wpdb, $info ): void {
            foreach ( $info['tables'] as $pair ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query( 'RENAME TABLE `' . esc_sql( $pair['from'] ) . '` TO `' . esc_sql( $pair['to'] ) . '`' );
            }
        };

        // Revert option_name keys back to old prefix (e.g. csXXX_user_roles → wp_user_roles).
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $opts_result = $wpdb->query( $wpdb->prepare(
            'UPDATE `' . esc_sql( $options_table ) . '` SET option_name = REPLACE(option_name, %s, %s) WHERE option_name LIKE %s',
            $new_prefix, $old_prefix, $wpdb->esc_like( $new_prefix ) . '%'
        ) );
        if ( $opts_result === false ) {
            $undo_rollback_tables();
            wp_send_json_error( 'Could not revert option_name keys — rollback undone. DB: ' . $wpdb->last_error );
            return;
        }
        $opts_rows = (int) $wpdb->rows_affected;

        // Revert meta_key entries back to old prefix (e.g. csXXX_capabilities → wp_capabilities).
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $meta_result = $wpdb->query( $wpdb->prepare(
            'UPDATE `' . esc_sql( $usermeta_table ) . '` SET meta_key = REPLACE(meta_key, %s, %s) WHERE meta_key LIKE %s',
            $new_prefix, $old_prefix, $wpdb->esc_like( $new_prefix ) . '%'
        ) );
        if ( $meta_result === false ) {
            // Undo options revert then re-apply table renames.
            $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'UPDATE `' . esc_sql( $options_table ) . '` SET option_name = REPLACE(option_name, %s, %s) WHERE option_name LIKE %s',
                $old_prefix, $new_prefix, $wpdb->esc_like( $old_prefix ) . '%'
            ) );
            $undo_rollback_tables();
            wp_send_json_error( 'Could not revert meta_key entries — rollback undone. DB: ' . $wpdb->last_error );
            return;
        }
        $meta_rows = (int) $wpdb->rows_affected;

        // Restore original wp-config.php content.
        $cfg_file = ABSPATH . 'wp-config.php';
        if ( ! empty( $info['cfg_original'] ) ) {
            $original_perms = file_exists( $cfg_file ) ? fileperms( $cfg_file ) : 0644;
            $needs_chmod    = ! is_writable( $cfg_file );
            if ( $needs_chmod ) { @chmod( $cfg_file, 0644 ); }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $cfg_file, $info['cfg_original'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
            if ( $needs_chmod ) { @chmod( $cfg_file, $original_perms ); }
        }

        wp_cache_flush();
        delete_option( 'csdt_db_prefix_rollback' );

        wp_send_json_success( [
            'reverted'         => $reverted,
            'options_reverted' => $opts_rows,
            'usermeta_reverted'=> $meta_rows,
            'message'          => 'Rolled back ' . $reverted . ' tables, reverted ' . $opts_rows . ' option rows and ' . $meta_rows . ' user meta rows to prefix "' . esc_html( $old_prefix ) . '".',
        ] );
    }

    // ── Shared list of WordPress core table suffixes (no prefix) ────────────
    public static function core_table_suffixes(): array {
        return [
            'comments', 'commentmeta', 'links', 'options', 'postmeta', 'posts',
            'terms', 'termmeta', 'term_relationships', 'term_taxonomy', 'usermeta', 'users',
            // Multisite
            'blogs', 'blog_versions', 'registration_log', 'signups', 'site', 'sitemeta', 'sitecategories',
        ];
    }

    // ── Map a table suffix to a likely plugin name ───────────────────────────
    public static function guess_plugin_from_suffix( string $suffix ): string {
        $map = [
            'rank_math'       => 'Rank Math SEO',
            'shortpixel'      => 'ShortPixel',
            'woocommerce'     => 'WooCommerce',
            'wc_order'        => 'WooCommerce',
            'wc_product'      => 'WooCommerce',
            'wc_tax'          => 'WooCommerce',
            'woo_'            => 'WooCommerce',
            'elementor'       => 'Elementor',
            'e_submissions'   => 'Elementor',
            'aioseo'          => 'AIOSEO',
            'yoast'           => 'Yoast SEO',
            'wpseo'           => 'Yoast SEO',
            'jetpack'         => 'Jetpack',
            'ninja_forms'     => 'Ninja Forms',
            'nf_'             => 'Ninja Forms',
            'gf_'             => 'Gravity Forms',
            'litespeed'       => 'LiteSpeed Cache',
            'smush'           => 'Smush',
            'wordfence'       => 'Wordfence',
            'wfblockedip'     => 'Wordfence',
            'wf_'             => 'Wordfence',
            'cf7'             => 'Contact Form 7',
            'wpcf7'           => 'Contact Form 7',
            'redirection'     => 'Redirection',
            'duplicator'      => 'Duplicator',
            'itsec'           => 'iThemes Security',
            'backwpup'        => 'BackWPup',
            'mailpoet'        => 'MailPoet',
            'wysija'          => 'MailPoet (legacy)',
            'wpforms'         => 'WPForms',
            'actionscheduler' => 'Action Scheduler',
            'icl_'            => 'WPML',
            'wpml_'           => 'WPML',
            'learndash'       => 'LearnDash',
            'bookly'          => 'Bookly',
            'affilwp'         => 'AffiliateWP',
            'give_'           => 'GiveWP',
            'tribe_'          => 'The Events Calendar',
            'em_'             => 'Events Manager',
            'wsal_'           => 'WP Activity Log',
            'formidable'      => 'Formidable Forms',
            'wpo_'            => 'WP-Optimize',
            'ewwwio'          => 'EWWW Image Optimizer',
            'mepr_'           => 'MemberPress',
            'pmpro'           => 'Paid Memberships Pro',
            'mlw_'            => 'Quiz And Survey Master',
            'wdr_'            => 'Discount Rules',
            'berocket'        => 'BeRocket',
            'cspv_'           => 'Cloudscale Devtools (legacy)',
            'csdt_'           => 'Cloudscale Devtools',
        ];
        foreach ( $map as $key => $name ) {
            if ( str_contains( $suffix, $key ) ) {
                return $name;
            }
        }
        return 'Unknown plugin';
    }

    public static function guess_plugin_url_from_suffix( string $suffix ): string {
        $map = [
            'rank_math'       => 'https://wordpress.org/plugins/seo-by-rank-math/',
            'shortpixel'      => 'https://wordpress.org/plugins/shortpixel-image-optimiser/',
            'woocommerce'     => 'https://wordpress.org/plugins/woocommerce/',
            'wc_order'        => 'https://wordpress.org/plugins/woocommerce/',
            'wc_product'      => 'https://wordpress.org/plugins/woocommerce/',
            'wc_tax'          => 'https://wordpress.org/plugins/woocommerce/',
            'woo_'            => 'https://wordpress.org/plugins/woocommerce/',
            'elementor'       => 'https://wordpress.org/plugins/elementor/',
            'e_submissions'   => 'https://wordpress.org/plugins/elementor/',
            'aioseo'          => 'https://wordpress.org/plugins/all-in-one-seo-pack/',
            'yoast'           => 'https://wordpress.org/plugins/wordpress-seo/',
            'wpseo'           => 'https://wordpress.org/plugins/wordpress-seo/',
            'jetpack'         => 'https://wordpress.org/plugins/jetpack/',
            'ninja_forms'     => 'https://wordpress.org/plugins/ninja-forms/',
            'nf_'             => 'https://wordpress.org/plugins/ninja-forms/',
            'gf_'             => 'https://www.gravityforms.com/',
            'litespeed'       => 'https://wordpress.org/plugins/litespeed-cache/',
            'smush'           => 'https://wordpress.org/plugins/wp-smushit/',
            'wordfence'       => 'https://wordpress.org/plugins/wordfence/',
            'wfblockedip'     => 'https://wordpress.org/plugins/wordfence/',
            'wf_'             => 'https://wordpress.org/plugins/wordfence/',
            'cf7'             => 'https://wordpress.org/plugins/contact-form-7/',
            'wpcf7'           => 'https://wordpress.org/plugins/contact-form-7/',
            'redirection'     => 'https://wordpress.org/plugins/redirection/',
            'duplicator'      => 'https://wordpress.org/plugins/duplicator/',
            'itsec'           => 'https://wordpress.org/plugins/better-wp-security/',
            'backwpup'        => 'https://wordpress.org/plugins/backwpup/',
            'mailpoet'        => 'https://wordpress.org/plugins/mailpoet/',
            'wysija'          => 'https://wordpress.org/plugins/mailpoet/',
            'wpforms'         => 'https://wordpress.org/plugins/wpforms-lite/',
            'actionscheduler' => 'https://wordpress.org/plugins/action-scheduler/',
            'icl_'            => 'https://wpml.org/',
            'wpml_'           => 'https://wpml.org/',
            'learndash'       => 'https://www.learndash.com/',
            'bookly'          => 'https://wordpress.org/plugins/bookly-responsive-appointment-booking-tool/',
            'affilwp'         => 'https://affiliatewp.com/',
            'give_'           => 'https://wordpress.org/plugins/give/',
            'tribe_'          => 'https://wordpress.org/plugins/the-events-calendar/',
            'em_'             => 'https://wordpress.org/plugins/events-manager/',
            'wsal_'           => 'https://wordpress.org/plugins/wp-security-audit-log/',
            'formidable'      => 'https://wordpress.org/plugins/formidable/',
            'wpo_'            => 'https://wordpress.org/plugins/wp-optimize/',
            'ewwwio'          => 'https://wordpress.org/plugins/ewww-image-optimizer/',
            'mepr_'           => 'https://www.memberpress.com/',
            'pmpro'           => 'https://www.paidmembershipspro.com/',
            'mlw_'            => 'https://wordpress.org/plugins/quiz-master-next/',
            'wdr_'            => 'https://wordpress.org/plugins/woo-discount-rules/',
            'berocket'        => 'https://berocket.com/',
            'cspv_'           => '',
            'csdt_'           => '',
        ];
        foreach ( $map as $key => $url ) {
            if ( str_contains( $suffix, $key ) ) {
                return $url;
            }
        }
        return '';
    }


    private static function default_internal_scan_prompt(): string {
        return <<<'PROMPT'
You are a WordPress security expert. Analyse the provided internal WordPress configuration data only.

Focus on: WordPress/PHP version currency, WP_DEBUG/WP_DEBUG_DISPLAY flags (exposed to public = critical), DISALLOW_FILE_EDIT/MODS, database prefix (wp_ default is a risk), user accounts (admin username exists, counts), active plugin list (outdated plugins), brute force protection, 2FA configuration (email/TOTP/passkey counts per admin), login URL obfuscation, wp-config.php file permissions, open user registration, pingbacks enabled (DDoS amplification), WordPress version in meta generator tag, default comment status.

SSH hardening (ssh_status key): fail2ban_installed/fail2ban_running — whether fail2ban is present and active. ssh_port_open — whether SSH is on port 22. password_auth: yes=brute-forceable/no=key-only. root_login: yes=critical. If ssh_port_open=false omit SSH entirely.
Rules: ssh_port_open=true + fail2ban_running=false = CRITICAL (unprotected SSH is actively recruited into DDoS botnets within hours). ssh_port_open=true + password_auth=yes + fail2ban_running=false = CRITICAL. ssh_port_open=true + root_login=yes = CRITICAL. ssh_port_open=true + fail2ban_running=true = good finding. ssh_port_open=true + password_auth=no = good finding.

Return ONLY a JSON object (no markdown, no code fences) with this exact schema:
{"score":0-100,"score_label":"Excellent|Good|Fair|Poor|Critical","summary":"1-2 sentences on internal config security posture","critical":[{"title":"...","detail":"...","fix":"..."}],"high":[...],"medium":[...],"low":[...],"good":[{"title":"...","detail":"..."}]}

Score the internal configuration on a 0-100 scale. Be strict. Include good practices for hardened settings.
PROMPT;
    }

    private static function default_external_scan_prompt(): string {
        return <<<'PROMPT'
You are a penetration tester. Analyse the provided external exposure checks and plugin code scan data only.

For external checks assess: HTTP security headers (CSP, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy), exposed endpoints (wp-login.php, xmlrpc.php, wp-cron.php, REST API user enumeration, author enumeration /?author=1, directory listing), SSL certificate validity and days to expiry, HTTP→HTTPS redirect enforcement, exposed sensitive files (debug.log, .env, wp-config.php.bak, .git/config, readme.html, phpinfo.php, error_log, composer.json, backup archives), database admin tools accessible (adminer, phpMyAdmin), server-status/server-info pages, and email DNS security (SPF and DMARC records present).

IMPORTANT — headers_scan_blocked: If headers_scan_blocked is true (or headers_response_code >= 400), the HTTP headers request was blocked by the site's bot protection or WAF (e.g. Cloudflare returned a 403 challenge page). The challenge page's own headers do NOT represent the real site's security headers. Do NOT report any security headers (HSTS, CSP, X-Frame-Options, etc.) as missing or weak findings. Instead, include exactly one low-severity note: "Security headers unverifiable — scan blocked by bot protection (HTTP headers_response_code)." Do not penalise the score for missing headers when blocked.

For plugin code scan (plugin_code_scan): list detected patterns as context only — raw static analysis that may include false positives.

For code_triage: AI-verified verdicts on the static findings. Each entry has verdict (confirmed|false_positive|needs_context), severity, type, explanation, and fix. Only raise confirmed findings as real issues — do not report false_positive items as vulnerabilities. Use the severity from code_triage for confirmed items. For needs_context items, mention them at low severity. Name plugin, file, and line number in every code finding.

Return ONLY a JSON object (no markdown, no code fences) with this exact schema:
{"score":0-100,"score_label":"Excellent|Good|Fair|Poor|Critical","summary":"1-2 sentences on external exposure and code scan posture","critical":[{"title":"...","detail":"...","fix":"..."}],"high":[...],"medium":[...],"low":[...],"good":[{"title":"...","detail":"..."}]}

Score external exposure on a 0-100 scale. Prioritise externally reachable issues at critical/high. Include good practices for blocked endpoints and hardened headers.
PROMPT;
    }

    // ── Cancel scan ───────────────────────────────────────────────────

    public static function ajax_cancel_scan(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : 'deep';
        if ( $type === 'deep' ) {
            set_transient( 'csdt_deep_scan_cancelled', '1', 300 );
            delete_transient( 'csdt_deep_scan_status' );
        } else {
            set_transient( 'csdt_vuln_scan_cancelled', '1', 300 );
            delete_transient( 'csdt_vuln_scan_status' );
        }
        wp_send_json_success( [ 'cancelled' => true ] );
    }

    public static function ajax_site_audit(): void {
        check_ajax_referer( CloudScale_DevTools::SITE_AUDIT_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $data     = self::gather_site_audit_data();
        $has_key = CSDT_AI_Dispatcher::has_key();

        if ( $has_key ) {
            $system = 'You are a WordPress site auditor. You receive structured JSON data about a WordPress site and must return a JSON array of findings. Each finding must be a JSON object with these exact keys: category (string: "SEO", "Content", "Performance", "Database", "Security", or "Plugins"), severity ("critical", "high", "medium", "low", or "info"), title (string, max 80 chars), detail (string, 1-3 sentences explaining the issue), fix (string, 1-2 sentences of specific actionable advice), affected (string, e.g. "14 pages", "wp_options table", "All posts"). Return ONLY the raw JSON array, no markdown, no code fences, no explanation. Order findings by severity (critical first). IMPORTANT: (1) Do NOT generate findings about missing backup plugins or missing SEO plugins — those are handled separately. (2) The field "template_rendered_pages" lists pages whose post_content is empty because they use a custom theme template or page builder — their actual rendered content may be substantial. Do NOT flag these as thin or empty content. (3) Do NOT generate findings about post revisions in the database — those are handled separately. (4) Do NOT generate findings about missing meta descriptions or missing SEO title tags — those are handled separately. Never recommend Yoast SEO or Rank Math — use CloudScale SEO AI only. (5) Do NOT generate findings about brute-force protection, SSH monitor, login URL hiding, or two-factor authentication — those are already reported. (6) Do NOT generate findings about disk space, WordPress core updates, or the "admin" username — those are already reported. (7) Do NOT generate findings about passkey or WebAuthn authentication status — those are handled separately. (8) Do NOT generate findings about WP-Cron health, expired transients, or DISABLE_WP_CRON — those are handled separately. (9) Do NOT generate findings about autoloaded options or wp_options autoload size — those are handled separately. (10) Do NOT generate findings about the default featured image, default post thumbnail, or broken/missing default images — those are handled separately. (11) Do NOT generate findings about thin content, stub posts, near-zero word count, or average post word count being below any threshold — those are handled separately. (12) Do NOT generate findings about missing featured images on individual posts/pages (non-default) or overall featured image coverage — those are handled separately. (13) Do NOT generate findings about duplicate page titles or duplicate post titles — those are handled separately. (14) Do NOT generate findings about wp-config.php file permissions, readability, or writability — those are handled separately. (15) Do NOT generate findings about inactive or unused themes — those are handled separately. (16) Do NOT generate findings about automatic background updates being disabled, AUTOMATIC_UPDATER_DISABLED, or WP_AUTO_UPDATE_CORE — those are handled separately.';

            $user_msg = "Audit this WordPress site and return findings as a JSON array:\n\n" . wp_json_encode( $data, JSON_PRETTY_PRINT );

            try {
                $raw      = CSDT_AI_Dispatcher::call( $system, $user_msg, '_auto', 2048 );
                // Strip markdown code fences if present
                $raw      = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw ) );
                $raw      = preg_replace( '/\s*```$/', '', $raw );
                $findings = json_decode( $raw, true );
                if ( ! is_array( $findings ) ) {
                    $findings = self::generate_rule_based_findings( $data );
                } else {
                    // Strip AI findings that duplicate rule-based topics — rule-based findings use
                    // calibrated severities so AI versions (often inflated) must not override them.
                    $findings = array_values( array_filter( $findings, function ( $f ) {
                        $t = strtolower( $f['title'] ?? '' ) . ' ' . strtolower( $f['detail'] ?? '' );
                        if ( strpos( $t, 'thin content' ) !== false )                                       return false;
                        if ( strpos( $t, 'word count' ) !== false || strpos( $t, 'word-count' ) !== false ) return false;
                        if ( strpos( $t, 'duplicate title' ) !== false )                                    return false;
                        if ( strpos( $t, 'featured image' ) !== false )                                     return false;
                        if ( strpos( $t, 'wp-config.php' ) !== false )                                     return false;
                        if ( strpos( $t, 'missing image' ) !== false )                                     return false;
                        if ( strpos( $t, 'brute-force' ) !== false || strpos( $t, 'brute force' ) !== false ) return false;
                        if ( strpos( $t, 'lockout' ) !== false )                                            return false;
                        if ( strpos( $t, 'two-factor' ) !== false || strpos( $t, '2fa' ) !== false )        return false;
                        if ( strpos( $t, 'login url' ) !== false )                                          return false;
                        if ( strpos( $t, 'inactive theme' ) !== false || strpos( $t, 'unused theme' ) !== false ) return false;
                        if ( strpos( $t, 'auto update' ) !== false || strpos( $t, 'auto-update' ) !== false || strpos( $t, 'automatic update' ) !== false || strpos( $t, 'background update' ) !== false ) return false;
                        if ( strpos( $t, 'title tag' ) !== false || strpos( $t, 'title tags' ) !== false )         return false;
                        if ( strpos( $t, 'meta description' ) !== false || strpos( $t, 'meta desc' ) !== false )   return false;
                        return true;
                    } ) );
                    // Always append cross-sell and all rule-based findings even with AI
                    $findings = array_merge( $findings, self::get_cross_sell_findings( $data ) );
                    $findings = array_merge( $findings, self::generate_rule_based_findings( $data ) );
                }
            } catch ( \Throwable $e ) {
                $findings = self::generate_rule_based_findings( $data );
            }
        } else {
            $findings = self::generate_rule_based_findings( $data );
        }

        // Attach summary counts
        $counts = [ 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0 ];
        foreach ( $findings as $f ) {
            $sev = strtolower( $f['severity'] ?? 'info' );
            if ( isset( $counts[ $sev ] ) ) { $counts[ $sev ]++; }
        }

        $response = [
            'findings'   => $findings,
            'counts'     => $counts,
            'ai_used'    => $has_key,
            'post_count' => $data['post_count'] ?? 0,
        ];

        update_option( 'csdt_site_audit_cache', [
            'data'   => $response,
            'run_at' => time(),
        ], false );

        wp_send_json_success( $response );
    }

    private static function gather_site_audit_data(): array {
        global $wpdb;

        // ── Content sample (up to 100 published posts + pages) ──
        $posts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT ID, post_title, post_type, post_status, post_content,
                    post_modified, comment_count
             FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type   IN ('post','page')
             ORDER BY post_modified DESC
             LIMIT 100",
            ARRAY_A
        ) ?: [];

        $post_ids     = array_column( $posts, 'ID' );
        $post_count   = count( $posts );

        // ── SEO meta (CloudScale SEO / Yoast / RankMath / AIO-SEO) ──
        $meta_map = [];
        if ( $post_ids ) {
            $in_clause  = implode( ',', array_map( 'intval', $post_ids ) );
            $meta_rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "SELECT post_id, meta_key, meta_value
                 FROM {$wpdb->postmeta}
                 WHERE post_id IN ({$in_clause})
                   AND meta_key IN ('_cs_seo_desc','_cs_seo_title',
                                    '_yoast_wpseo_metadesc','_yoast_wpseo_title',
                                    'rank_math_description','rank_math_title',
                                    '_aioseop_description','_aioseop_title',
                                    '_thumbnail_id')",
                ARRAY_A
            ) ?: [];
            foreach ( $meta_rows as $row ) {
                $meta_map[ $row['post_id'] ][ $row['meta_key'] ] = $row['meta_value'];
            }
        }

        // ── Analyse posts ──
        $issues            = [ 'no_meta_desc' => [], 'no_title_tag' => [], 'thin_content' => [], 'no_featured_image' => [], 'duplicate_titles' => [] ];
        $title_counts      = [];
        $title_urls        = [];
        $word_count_data   = [];
        $front_page_id     = (int) get_option( 'page_on_front', 0 );
        $template_pages    = [];   // pages whose content is entirely theme/builder rendered

        $meta_desc_keys = [ '_cs_seo_desc', '_yoast_wpseo_metadesc', 'rank_math_description', '_aioseop_description' ];
        $title_keys     = [ '_cs_seo_title', '_yoast_wpseo_title', 'rank_math_title', '_aioseop_title' ];

        foreach ( $posts as $p ) {
            $id    = (int) $p['ID'];
            $title = $p['post_title'];
            $meta  = $meta_map[ $id ] ?? [];

            // Duplicate titles
            $title_counts[ $title ]   = ( $title_counts[ $title ] ?? 0 ) + 1;
            $title_urls[ $title ][]   = get_permalink( $id );

            // Meta description
            $has_meta_desc = false;
            foreach ( $meta_desc_keys as $k ) {
                if ( ! empty( $meta[ $k ] ) ) { $has_meta_desc = true; break; }
            }
            if ( ! $has_meta_desc ) { $issues['no_meta_desc'][] = [ 'title' => $title, 'url' => get_permalink( $id ) ]; }

            // SEO title
            $has_title = false;
            foreach ( $title_keys as $k ) {
                if ( ! empty( $meta[ $k ] ) ) { $has_title = true; break; }
            }
            if ( ! $has_title ) { $issues['no_title_tag'][] = [ 'title' => $title, 'url' => get_permalink( $id ) ]; }

            // Word count — skip pages whose content is entirely theme/builder rendered
            $raw_words = str_word_count( wp_strip_all_tags( $p['post_content'] ) );
            $is_template_rendered = ( $raw_words === 0 && $p['post_type'] === 'page' );
            if ( $is_template_rendered ) {
                $template_pages[] = $title;
            } else {
                if ( $raw_words < 300 ) { $issues['thin_content'][] = [ 'title' => $title, 'words' => $raw_words, 'url' => get_permalink( $id ) ]; }
                $word_count_data[] = $raw_words;
            }

            // Featured image
            if ( empty( $meta['_thumbnail_id'] ) ) { $issues['no_featured_image'][] = [ 'title' => $title, 'url' => get_permalink( $id ) ]; }
        }

        // Duplicate titles (more than once)
        foreach ( $title_counts as $t => $c ) {
            if ( $c > 1 ) {
                $issues['duplicate_titles'][] = [ 'title' => $t, 'urls' => array_slice( $title_urls[ $t ] ?? [], 0, 3 ) ];
            }
        }

        $avg_words = $word_count_data ? (int) ( array_sum( $word_count_data ) / count( $word_count_data ) ) : 0;

        // ── Database health ──
        $autoload_kb = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT ROUND( SUM( LENGTH(option_value) ) / 1024 ) FROM {$wpdb->options} WHERE autoload = 'yes'"
        );

        $expired_transients = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_timeout_%'
               AND CAST( option_value AS UNSIGNED ) < UNIX_TIMESTAMP()"
        );

        $cron_disabled        = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        $cron_next_delete     = wp_next_scheduled( 'wp_scheduled_delete' );
        $cron_next_transient  = wp_next_scheduled( 'delete_expired_transients' );
        $cron_overdue_secs    = 6 * HOUR_IN_SECONDS;
        $cron_missing         = ! $cron_next_delete || ! $cron_next_transient;
        $cron_overdue         = ( $cron_next_delete    && $cron_next_delete    < time() - $cron_overdue_secs )
                             || ( $cron_next_transient && $cron_next_transient < time() - $cron_overdue_secs );
        $cron_healthy         = ! $cron_disabled && ! $cron_missing && ! $cron_overdue;

        $revision_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"
        );

        $orphan_postmeta = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE p.ID IS NULL"
        );

        // ── Plugin health ──
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins    = get_plugins();
        $active_slugs   = (array) get_option( 'active_plugins', [] );
        $active_count   = count( $active_slugs );
        $inactive_count = count( $all_plugins ) - $active_count;

        // ── Inactive themes ──
        $all_themes           = wp_get_themes();
        $active_theme_slug    = get_option( 'stylesheet' );
        $parent_theme_slug    = get_option( 'template' );
        $inactive_theme_count = 0;
        $inactive_theme_names = [];
        foreach ( $all_themes as $slug => $theme ) {
            if ( $slug !== $active_theme_slug && $slug !== $parent_theme_slug ) {
                ++$inactive_theme_count;
                $inactive_theme_names[] = $theme->get( 'Name' );
            }
        }

        // ── Auto-update constants ──
        $auto_updates_disabled = ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED )
                              || ( defined( 'WP_AUTO_UPDATE_CORE' ) && WP_AUTO_UPDATE_CORE === false );

        // ── Cross-sell: backup & SEO plugin detection ──
        $backup_slugs = [ 'cloudscale-backup', 'updraftplus', 'backwpup', 'backup-backup',
                          'duplicator', 'duplicator-pro', 'backupbuddy', 'blogvault-real-time-backup',
                          'wp-all-backup', 'wp-database-backup', 'xcloner-backup-and-restore' ];
        $seo_slugs    = [ 'cloudscale-seo-ai-optimizer', 'wordpress-seo', 'wordpress-seo-premium',
                          'rank-math', 'all-in-one-seo-pack', 'seopress', 'the-seo-framework',
                          'squirrly-seo', 'wp-seopress' ];
        $has_backup = false;
        $has_seo    = false;
        foreach ( $active_slugs as $slug ) {
            $folder = explode( '/', $slug )[0];
            if ( in_array( $folder, $backup_slugs, true ) ) { $has_backup = true; }
            if ( in_array( $folder, $seo_slugs, true ) )    { $has_seo    = true; }
        }

        // ── Config flags ──
        $debug_on      = defined( 'WP_DEBUG' ) && WP_DEBUG;
        $debug_log_on  = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
        $revisions_max = defined( 'WP_POST_REVISIONS' ) ? WP_POST_REVISIONS : -1;

        // ── Login & brute-force settings ──
        $bf_enabled       = get_option( 'csdt_devtools_brute_force_enabled', '1' ) === '1';
        $bf_attempts      = (int) get_option( 'csdt_devtools_brute_force_attempts', '5' );
        $bf_lockout       = (int) get_option( 'csdt_devtools_brute_force_lockout', '10' );
        $login_hide_on    = get_option( 'csdt_devtools_login_hide_enabled', '0' ) === '1';
        $twofa_admins     = get_option( 'csdt_devtools_2fa_force_admins', '0' ) === '1';
        $twofa_method     = get_option( 'csdt_devtools_2fa_method', '' );
        $admin_uids     = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
        $passkeys_on    = false;
        $passkeys_count = 0;
        foreach ( $admin_uids as $uid ) {
            $pks = CSDT_DevTools_Passkey::get_passkeys( (int) $uid );
            if ( ! empty( $pks ) ) {
                $passkeys_on     = true;
                $passkeys_count += count( $pks );
            }
        }

        // ── Active BF attack detection (today's count from bf_log) ──
        $bf_log_raw       = get_option( 'csdt_devtools_bf_log', [] );
        $today_start      = mktime( 0, 0, 0 );
        $bf_today_count   = is_array( $bf_log_raw )
            ? count( array_filter( $bf_log_raw, fn( $e ) => isset( $e[0] ) && $e[0] >= $today_start ) )
            : 0;
        $bf_last_attempt  = is_array( $bf_log_raw ) && ! empty( $bf_log_raw )
            ? max( array_column( $bf_log_raw, 0 ) )
            : 0;
        // Human-readable date label for use in cached finding strings — avoids
        // stale "today" text when the cached result is viewed the following day.
        $bf_scan_date     = wp_date( 'M j', $today_start );

        // ── SSH monitor ──
        $ssh_monitor_on        = get_option( 'csdt_ssh_monitor_enabled', '1' ) === '1';
        $ssh_monitor_threshold = (int) get_option( 'csdt_ssh_monitor_threshold', '10' );

        // ── Default featured image check ──
        $default_img_id      = (int) get_option( 'cloudscale_default_image_id', 0 );
        $default_image_missing = ( $default_img_id === 0 );
        $default_image_broken  = false;
        if ( $default_img_id > 0 ) {
            $img_url = wp_get_attachment_url( $default_img_id );
            if ( $img_url ) {
                $resp = wp_remote_head( $img_url, [ 'timeout' => 5, 'redirection' => 3 ] );
                if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) !== 200 ) {
                    $default_image_broken = true;
                }
            } else {
                $default_image_broken = true;
            }
        }

        // ── Admin username check ──
        $admin_user_exists = (bool) get_user_by( 'login', 'admin' );

        // ── Writable wp-config.php ──
        $wpconfig_path     = ABSPATH . 'wp-config.php';
        if ( file_exists( $wpconfig_path ) ) {
            $wpconfig_oct      = substr( sprintf( '%o', fileperms( $wpconfig_path ) ), -4 );
            $wpconfig_writable = ! in_array( $wpconfig_oct, [ '0400', '0440', '0600', '0640' ], true );
        } else {
            $wpconfig_writable = false;
        }

        // ── WordPress core update ──
        $wp_update_available  = false;
        $wp_latest_version    = '';
        $wp_versions_behind   = 0;
        $core_updates = get_site_transient( 'update_core' );
        if ( $core_updates && ! empty( $core_updates->updates ) ) {
            foreach ( $core_updates->updates as $update ) {
                if ( isset( $update->response ) && $update->response === 'upgrade' ) {
                    $wp_update_available = true;
                    $wp_latest_version   = $update->version ?? '';
                    // Compare major.minor to estimate how far behind
                    $cur_parts    = explode( '.', get_bloginfo( 'version' ) );
                    $new_parts    = explode( '.', $wp_latest_version );
                    $cur_major    = (int) ( $cur_parts[0] ?? 0 );
                    $new_major    = (int) ( $new_parts[0] ?? 0 );
                    $cur_minor    = (int) ( $cur_parts[1] ?? 0 );
                    $new_minor    = (int) ( $new_parts[1] ?? 0 );
                    $wp_versions_behind = ( $new_major - $cur_major ) * 100 + ( $new_minor - $cur_minor );
                    break;
                }
            }
        }

        // ── Disk space ──
        $disk_free_gb  = null;
        $disk_total_gb = null;
        $disk_free_pct = null;
        $disk_root     = function_exists( 'disk_free_space' ) ? @disk_free_space( ABSPATH ) : false;
        $disk_total    = function_exists( 'disk_total_space' ) ? @disk_total_space( ABSPATH ) : false;
        if ( $disk_root !== false && $disk_total && $disk_total > 0 ) {
            $disk_free_gb  = round( $disk_root / 1073741824, 1 );
            $disk_total_gb = round( $disk_total / 1073741824, 1 );
            $disk_free_pct = round( ( $disk_root / $disk_total ) * 100 );
        }

        return [
            'post_count'          => $post_count,
            'avg_word_count'      => $avg_words,
            'no_meta_desc_count'  => count( $issues['no_meta_desc'] ),
            'no_meta_desc_sample' => array_slice( $issues['no_meta_desc'], 0, 6 ),
            'no_title_tag_count'  => count( $issues['no_title_tag'] ),
            'no_title_tag_sample' => array_slice( $issues['no_title_tag'], 0, 6 ),
            'thin_content_count'  => count( $issues['thin_content'] ),
            'thin_content_sample' => array_slice( $issues['thin_content'], 0, 10 ),
            'no_featured_img_count'  => count( $issues['no_featured_image'] ),
            'no_featured_img_sample' => array_slice( $issues['no_featured_image'], 0, 10 ),
            'duplicate_titles'      => $issues['duplicate_titles'],
            'template_rendered_pages' => $template_pages,
            'autoload_kb'         => $autoload_kb,
            'expired_transients'  => $expired_transients,
            'cron_healthy'        => $cron_healthy,
            'cron_disabled'       => $cron_disabled,
            'cron_missing'        => $cron_missing,
            'cron_overdue'        => $cron_overdue,
            'revision_count'      => $revision_count,
            'orphan_postmeta'     => $orphan_postmeta,
            'active_plugins'       => $active_count,
            'inactive_plugins'     => $inactive_count,
            'inactive_themes'      => $inactive_theme_count,
            'inactive_theme_names' => $inactive_theme_names,
            'auto_updates_disabled' => $auto_updates_disabled,
            'has_backup_plugin'   => $has_backup,
            'has_seo_plugin'      => $has_seo,
            'wp_debug'            => $debug_on,
            'wp_debug_log'        => $debug_log_on,
            'revisions_max'       => $revisions_max,
            'wp_version'          => get_bloginfo( 'version' ),
            'php_version'         => PHP_VERSION,
            'bf_enabled'          => $bf_enabled,
            'bf_attempts'         => $bf_attempts,
            'bf_lockout_mins'     => $bf_lockout,
            'bf_today_count'      => $bf_today_count,
            'bf_scan_date'        => $bf_scan_date,
            'bf_last_attempt'     => $bf_last_attempt,
            'login_hide_on'       => $login_hide_on,
            'twofa_admins'        => $twofa_admins,
            'twofa_method'        => $twofa_method,
            'passkeys_on'         => $passkeys_on,
            'passkeys_count'      => $passkeys_count,
            'ssh_monitor_on'      => $ssh_monitor_on,
            'ssh_monitor_threshold' => $ssh_monitor_threshold,
            'default_image_missing' => $default_image_missing,
            'default_image_broken'  => $default_image_broken,
            'admin_user_exists'   => $admin_user_exists,
            'wpconfig_writable'   => $wpconfig_writable,
            'wp_update_available' => $wp_update_available,
            'wp_latest_version'   => $wp_latest_version,
            'wp_versions_behind'  => $wp_versions_behind,
            'disk_free_gb'        => $disk_free_gb,
            'disk_total_gb'       => $disk_total_gb,
            'disk_free_pct'       => $disk_free_pct,
        ];
    }

    private static function get_cross_sell_findings( array $d ): array {
        $findings = [];
        if ( empty( $d['has_backup_plugin'] ) ) {
            $findings[] = [
                'category' => 'Security',
                'severity' => 'high',
                'title'    => 'No backup plugin detected — your site has no recovery point',
                'detail'   => 'A server failure, bad plugin update, or hack with no backup means permanent data loss. Backups are non-negotiable for any production WordPress site.',
                'fix'      => 'Install a backup plugin and schedule daily off-site backups before making any significant changes.',
                'cta'      => [
                    'label' => '🗄 CloudScale Backup & Restore — Free',
                    'url'   => 'https://andrewbaker.ninja/wordpress-plugin-help/cloudscale-backup-restore-help/',
                    'desc'  => 'One-click backup to S3, automated schedules, and point-in-time restore. Free and open-source.',
                ],
            ];
        }
        if ( empty( $d['has_seo_plugin'] ) ) {
            $findings[] = [
                'category' => 'SEO',
                'severity' => 'high',
                'title'    => 'No SEO plugin detected — meta tags and structured data not managed',
                'detail'   => 'Without an SEO plugin, Google receives no guidance on page titles, meta descriptions, canonical URLs, or structured data. This directly limits your organic search visibility.',
                'fix'      => 'Install an SEO plugin to manage meta descriptions, Open Graph tags, sitemaps, and structured data across all your pages.',
                'cta'      => [
                    'label' => '🤖 CloudScale SEO AI — Free',
                    'url'   => 'https://andrewbaker.ninja/wordpress-plugin-help/cloudscale-seo-ai-help/',
                    'desc'  => 'AI-generated meta descriptions, og:image creation, Cloudflare CDN integration, and a social preview checker. Free and open-source.',
                ],
            ];
        }
        return $findings;
    }

    private static function generate_rule_based_findings( array $d ): array {
        $findings = [];

        $csp_dupe_count = $d['csp_duplicate_count'] ?? 0;
        if ( $csp_dupe_count > 1 ) {
            $findings[] = [
                'category' => 'Security',
                'severity' => 'critical',
                'title'    => "Duplicate Content-Security-Policy headers ({$csp_dupe_count} headers detected)",
                'detail'   => 'Multiple Content-Security-Policy headers are being sent simultaneously. Browsers enforce the intersection of all CSP headers, which is almost always far more restrictive than intended — typically blocking JavaScript for all visitors and Googlebot, causing SEO rankings to collapse. Common cause: a plugin CSP and a web server (nginx/Apache) CSP both active at the same time.',
                'fix'      => 'Ensure only one source outputs a Content-Security-Policy header. Check: (1) nginx/Apache add_header directives in server config, (2) WordPress security plugins with CSP settings, (3) CloudScale Devtools CSP panel under Login Security → Content Security Policy. Disable all but one.',
            ];
        }

        if ( $d['no_meta_desc_count'] > 0 ) {
            $sev = $d['no_meta_desc_count'] > 10 ? 'high' : 'medium';
            $findings[] = [
                'category'   => 'SEO',
                'severity'   => $sev,
                'title'      => "Missing meta descriptions on {$d['no_meta_desc_count']} posts/pages",
                'detail'     => 'Meta descriptions control how your pages appear in Google search results. Missing descriptions mean Google auto-generates them, often producing poor click-through rates.',
                'fix'        => 'Use CloudScale SEO AI to auto-generate keyword-rich meta descriptions for every page in one batch operation. Focus on your highest-traffic pages first.',
                'fix_action' => 'seo_ai_desc',
                'affected'   => "{$d['no_meta_desc_count']} posts/pages",
                'links'      => ! empty( $d['no_meta_desc_sample'] )
                    ? array_map(
                        fn( $s ) => [ 'label' => $s['title'], 'url' => $s['url'] ],
                        array_slice( $d['no_meta_desc_sample'], 0, 6 )
                      )
                    : [],
                'cta'        => [
                    'label' => '🤖 CloudScale SEO AI — Free',
                    'url'   => 'https://andrewbaker.ninja/wordpress-plugin-help/cloudscale-seo-ai-help/',
                    'desc'  => 'AI-generated meta descriptions for your entire content library in one click. Free and open-source.',
                ],
            ];
        }

        if ( ! empty( $d['no_title_tag_count'] ) && $d['no_title_tag_count'] > 0 ) {
            $findings[] = [
                'category'   => 'SEO',
                'severity'   => 'medium',
                'title'      => "Missing SEO title tags on {$d['no_title_tag_count']} posts/pages",
                'detail'     => 'SEO title tags are the single strongest on-page ranking signal. Without them Google writes its own titles, often truncating or mis-representing your content in search results.',
                'fix'        => 'Use CloudScale SEO AI to auto-generate optimised title tags across your entire content library.',
                'fix_action' => 'seo_ai_title',
                'affected'   => "{$d['no_title_tag_count']} posts/pages",
                'links'      => ! empty( $d['no_title_tag_sample'] )
                    ? array_map(
                        fn( $s ) => [ 'label' => $s['title'], 'url' => $s['url'] ],
                        array_slice( $d['no_title_tag_sample'], 0, 6 )
                      )
                    : [],
                'cta'        => [
                    'label' => '🤖 CloudScale SEO AI — Free',
                    'url'   => 'https://andrewbaker.ninja/wordpress-plugin-help/cloudscale-seo-ai-help/',
                    'desc'  => 'AI-generated SEO titles and meta descriptions in one batch — free and open-source.',
                ],
            ];
        }

        if ( $d['thin_content_count'] > 0 ) {
            $sev        = 'medium';
            $findings[] = [
                'category' => 'Content',
                'severity' => $sev,
                'title'    => "{$d['thin_content_count']} posts/pages with fewer than 300 words",
                'detail'   => 'Thin content is a known Google ranking penalty trigger. Pages under 300 words rarely rank for competitive terms and may dilute the authority of the whole site.',
                'fix'      => 'Expand these pages with useful content, merge them into a comprehensive page, or set them to noindex if they serve a utility purpose only.',
                'affected' => "{$d['thin_content_count']} posts/pages",
                'links'    => ! empty( $d['thin_content_sample'] )
                    ? array_map(
                        fn( $s ) => [ 'label' => $s['title'], 'url' => $s['url'], 'words' => $s['words'] ],
                        array_slice( $d['thin_content_sample'], 0, 6 )
                      )
                    : [],
            ];
        }

        if ( ! empty( $d['duplicate_titles'] ) ) {
            $count      = count( $d['duplicate_titles'] );
            $examples   = implode( '; ', array_map(
                fn( $g ) => '"' . $g['title'] . '": ' . implode( ', ', $g['urls'] ),
                array_slice( $d['duplicate_titles'], 0, 3 )
            ) );
            $findings[] = [
                'category' => 'SEO',
                'severity' => 'medium',
                'title'    => "Duplicate page titles: {$count} title(s) used on multiple pages",
                'detail'   => 'Google treats duplicate titles as a quality signal. Pages sharing titles compete against each other and confuse crawlers about which to rank. Examples: ' . $examples . '.',
                'fix'      => 'Give every page a unique, descriptive title that includes the target keyword for that page.',
                'affected' => "{$count} duplicate title groups",
            ];
        }

        if ( $d['no_featured_img_count'] > 0 ) {
            $sev        = $d['no_featured_img_count'] > 20 ? 'medium' : 'low';
            $findings[] = [
                'category' => 'SEO',
                'severity' => $sev,
                'title'    => "{$d['no_featured_img_count']} posts/pages missing a featured image",
                'detail'   => 'Featured images are used for og:image, Twitter cards, and on-site article previews. Missing them weakens social sharing and can reduce click-through from search.',
                'fix'      => 'Add a relevant featured image to each post. Use a 1200×630px image for best social media compatibility.',
                'affected' => "{$d['no_featured_img_count']} posts/pages",
                'links'    => ! empty( $d['no_featured_img_sample'] )
                    ? array_map(
                        fn( $s ) => [ 'label' => $s['title'], 'url' => $s['url'] ],
                        array_slice( $d['no_featured_img_sample'], 0, 6 )
                      )
                    : [],
            ];
        }

        $defimg_ack = get_option( 'csdt_defimg_no_fallback_ack', '0' ) === '1';
        $no_img_links = ! empty( $d['no_featured_img_sample'] )
            ? array_map(
                fn( $s ) => [ 'label' => $s['title'], 'url' => $s['url'] ],
                array_slice( $d['no_featured_img_sample'], 0, 6 )
              )
            : [];
        if ( ! empty( $d['default_image_broken'] ) ) {
            $findings[] = [
                'category' => 'SEO',
                'severity' => 'medium',
                'title'    => 'Default featured image is broken — og:image fallback returns 404',
                'detail'   => 'A default image is configured in Thumbnails but the file no longer exists. Posts without their own featured image will produce a broken og:image, causing no preview card on WhatsApp, LinkedIn, and Twitter.',
                'fix'      => 'Go to Thumbnails → Default Featured Image and select a valid replacement, or click Remove to clear the broken reference if you prefer posts without a fallback image.',
                'affected' => 'All posts without a featured image',
                'links'    => $no_img_links,
            ];
        } elseif ( ! empty( $d['default_image_missing'] ) && ! $defimg_ack ) {
            $findings[] = [
                'category'      => 'SEO',
                'severity'      => 'low',
                'title'         => 'No default featured image — posts without a thumbnail have no og:image fallback',
                'detail'        => 'When a post has no featured image, social platforms receive no image. A branded 1200×630 px default ensures every shared post shows a preview card. If you intentionally want no fallback, dismiss this finding.',
                'fix'           => 'Go to Thumbnails → Default Featured Image and select a branded 1200×630 px image, or dismiss this finding if you prefer no fallback.',
                'affected'      => 'All posts without a featured image',
                'links'         => $no_img_links,
                'dismiss_label' => 'No fallback intended',
                'dismiss_id'    => 'defimg_no_fallback_ack',
            ];
        }

        if ( $d['autoload_kb'] > 800 ) {
            $sev = $d['autoload_kb'] > 2000 ? 'critical' : ( $d['autoload_kb'] > 1200 ? 'high' : 'medium' );
            $findings[] = [
                'category' => 'Performance',
                'severity' => $sev,
                'title'    => "Autoloaded data is {$d['autoload_kb']} KB — exceeds healthy threshold",
                'detail'   => 'WordPress loads all autoloaded options on every page request. Values above 800 KB add measurable latency to every PHP execution. Common culprits are abandoned plugins that store large serialised data.',
                'fix'      => 'Run a query to find the top 20 autoloaded options by size: SELECT option_name, length(option_value) as size FROM wp_options WHERE autoload="yes" ORDER BY size DESC LIMIT 20. Review and delete any from deactivated plugins.',
                'affected' => "wp_options ({$d['autoload_kb']} KB autoloaded)",
            ];
        } else {
            $findings[] = [
                'category' => 'Performance',
                'severity' => 'info',
                'title'    => "Autoloaded data is {$d['autoload_kb']} KB — within acceptable range",
                'detail'   => "Total autoloaded data in wp_options is {$d['autoload_kb']} KB, well within the healthy threshold of under 800 KB. No performance impact expected.",
                'fix'      => 'No action required. Periodically re-run the audit after installing new plugins to catch any regressions.',
                'affected' => 'wp_options',
            ];
        }

        $cron_disabled_acked = get_option( 'csdt_cron_disabled_ack', '0' ) === '1';
        if ( ( ! empty( $d['cron_disabled'] ) && ! $cron_disabled_acked ) || ! empty( $d['cron_missing'] ) || ! empty( $d['cron_overdue'] ) ) {
            $sev    = ! empty( $d['cron_disabled'] ) ? 'high' : 'medium';
            $detail = ! empty( $d['cron_disabled'] )
                ? 'DISABLE_WP_CRON is defined in wp-config.php. WordPress will never automatically run scheduled cleanup — expired transients, auto-drafts, and trashed posts will accumulate indefinitely unless a system cron calls wp-cron.php.'
                : ( ! empty( $d['cron_missing'] )
                    ? 'Core cleanup cron events (wp_scheduled_delete, delete_expired_transients) are missing from the schedule. These events handle expired transients, auto-drafts, and trashed content — their absence means database bloat will grow unchecked.'
                    : 'Core cleanup cron events are overdue by more than 6 hours, suggesting WP-Cron is not firing reliably. Expired transients and auto-drafts may be accumulating.' );
            $finding = [
                'category'   => 'Database',
                'severity'   => $sev,
                'title'      => ! empty( $d['cron_disabled'] )
                    ? 'DISABLE_WP_CRON is set — scheduled database cleanup is not running'
                    : 'WP-Cron cleanup events are missing or overdue',
                'detail'     => $detail,
                'fix'        => 'Click Fix It to reschedule and immediately run the missing cron events. If DISABLE_WP_CRON is intentional, ensure a system cron calls wp-cli cron event run --due-now at least every 15 minutes.',
                'fix_action' => 'cron_health',
                'affected'   => 'wp_options, wp_posts tables',
            ];
            if ( ! empty( $d['cron_disabled'] ) ) {
                $finding['dismiss_id']    = 'cron_disabled_ack';
                $finding['dismiss_label'] = 'System Cron Configured';
            }
            $findings[] = $finding;
        }

        if ( $d['expired_transients'] > 50 ) {
            $findings[] = [
                'category'   => 'Database',
                'severity'   => 'medium',
                'title'      => "{$d['expired_transients']} expired transients in the database",
                'detail'     => 'Expired transients are stale cache entries that WordPress has not yet purged. They bloat the wp_options table and slow down option queries.',
                'fix'        => 'Click Fix It to delete all expired transients immediately. For ongoing maintenance use CloudScale Cleanup on a scheduled CRON.',
                'fix_action' => 'expired_transients',
                'affected'   => "wp_options table",
                'cta'        => [
                    'label' => '🧹 CloudScale Cleanup — Free',
                    'url'   => 'https://andrewbaker.ninja/wordpress-plugin-help/cloudscale-cleanup-help/',
                    'desc'  => 'Automatically cleans expired transients, revisions, orphaned postmeta, and auto-drafts on a schedule.',
                ],
            ];
        }

        if ( $d['revision_count'] > 20 ) {
            $sev = $d['revision_count'] > 2000 ? 'medium' : 'low';
            $findings[] = [
                'category' => 'Database',
                'severity' => $sev,
                'title'    => "{$d['revision_count']} post revisions stored in the database",
                'detail'   => 'Post revisions accumulate over time and are rarely used after the first few. Even with a revision limit set, existing legacy revisions remain until explicitly removed — bloating the wp_posts table and slowing backups.',
                'fix'      => "Add define('WP_POST_REVISIONS', 3) to wp-config.php to cap future revisions. Use CloudScale Cleanup to safely delete all existing revisions in one click on a scheduled CRON.",
                'affected' => "wp_posts table ({$d['revision_count']} revisions)",
                'cta'      => [
                    'label' => '🧹 CloudScale Cleanup — Free',
                    'url'   => 'https://andrewbaker.ninja/wordpress-plugin-help/cloudscale-cleanup-help/',
                    'desc'  => 'Scheduled CRON deletes revisions, expired transients, orphaned postmeta, and auto-drafts automatically — no WP-CLI required.',
                ],
            ];
        }

        if ( $d['orphan_postmeta'] > 100 ) {
            $findings[] = [
                'category' => 'Database',
                'severity' => 'low',
                'title'    => "{$d['orphan_postmeta']} orphaned post meta rows",
                'detail'   => 'Orphaned meta rows belong to posts that no longer exist. They accumulate when posts are hard-deleted without cleaning up associated metadata.',
                'fix'      => 'Run: DELETE pm FROM wp_postmeta pm LEFT JOIN wp_posts p ON pm.post_id = p.ID WHERE p.ID IS NULL; — or use a DB cleanup plugin.',
                'affected' => "wp_postmeta table",
            ];
        }

        if ( ( $d['inactive_plugins'] ?? 0 ) >= 1 ) {
            $n   = $d['inactive_plugins'];
            $sev = $n >= 6 ? 'high' : ( $n >= 3 ? 'medium' : 'low' );
            $findings[] = [
                'category' => 'Plugins',
                'severity' => $sev,
                'title'    => "{$n} inactive " . ( $n === 1 ? 'plugin' : 'plugins' ) . ' still installed',
                'detail'   => "Inactive plugins don't run, but their files still exist on disk. If a vulnerability is discovered in an inactive plugin it can still be exploited via directory traversal or file-inclusion attacks.",
                'fix'      => 'Delete every plugin you are not actively using. Go to Plugins, filter by Inactive, and delete each one — deactivating is not enough.',
                'affected' => "{$n} inactive " . ( $n === 1 ? 'plugin' : 'plugins' ),
            ];
        }

        if ( ( $d['inactive_themes'] ?? 0 ) >= 1 ) {
            $n           = $d['inactive_themes'];
            $sev         = $n >= 3 ? 'medium' : 'low';
            $themes_url  = admin_url( 'themes.php' );
            $theme_links = ! empty( $d['inactive_theme_names'] )
                ? array_map(
                    fn( $name ) => [ 'label' => $name, 'url' => $themes_url ],
                    array_slice( $d['inactive_theme_names'], 0, 6 )
                  )
                : [];
            $findings[] = [
                'category' => 'Security',
                'severity' => $sev,
                'title'    => "{$n} inactive " . ( $n === 1 ? 'theme' : 'themes' ) . ' still installed',
                'detail'   => "Unused themes sit on disk with their full PHP code intact. An attacker who gains write access can activate an inactive theme to run arbitrary code. WordPress itself recommends keeping only themes you actually use.",
                'fix'      => 'Go to Appearance → Themes, click each unused theme, and delete it. You only need your active theme and its parent theme (if applicable).',
                'affected' => "{$n} inactive " . ( $n === 1 ? 'theme' : 'themes' ),
                'links'    => $theme_links,
            ];
        }

        if ( ! empty( $d['auto_updates_disabled'] ) ) {
            $findings[] = [
                'category'   => 'Security',
                'severity'   => 'high',
                'title'      => 'WordPress automatic background updates are disabled',
                'detail'     => 'WordPress releases security patches as minor updates (e.g. 6.9.x). With auto-updates disabled your site will not apply these patches automatically, leaving it exposed until you update manually.',
                'fix'        => 'Remove or change the AUTOMATIC_UPDATER_DISABLED / WP_AUTO_UPDATE_CORE constant in wp-config.php. Click Fix It to have this done automatically.',
                'fix_action' => 'enable_auto_updates',
                'affected'   => 'wp-config.php',
            ];
        }

        if ( $d['active_plugins'] > 25 ) {
            $findings[] = [
                'category' => 'Performance',
                'severity' => 'medium',
                'title'    => "{$d['active_plugins']} active plugins — consider reducing",
                'detail'   => 'Each active plugin adds PHP execution time, database queries, and JavaScript/CSS to every page load. Beyond ~20 plugins, the cumulative overhead becomes measurable.',
                'fix'      => 'Audit your plugin stack. Use the Optimizer tab to identify plugins that CloudScale already replaces. Aim for under 20 active plugins.',
                'affected' => "{$d['active_plugins']} active plugins",
            ];
        }

        if ( $d['wp_debug'] ) {
            $findings[] = [
                'category' => 'Security',
                'severity' => 'high',
                'title'    => 'WP_DEBUG is enabled on a production site',
                'detail'   => 'WP_DEBUG outputs PHP errors and notices directly to the browser. This leaks server paths, database table names, plugin file structures, and other information useful to attackers.',
                'fix'      => "Set define('WP_DEBUG', false) in wp-config.php. If you need debug logging, use WP_DEBUG_LOG with WP_DEBUG_DISPLAY set to false.",
                'affected' => 'wp-config.php',
            ];
        }

        // ── WP core update ──
        if ( ! empty( $d['wp_update_available'] ) ) {
            $latest  = $d['wp_latest_version'] ? " (latest: {$d['wp_latest_version']})" : '';
            $behind  = (int) ( $d['wp_versions_behind'] ?? 0 );
            $sev     = $behind >= 2 ? 'critical' : 'high';
            $findings[] = [
                'category' => 'Security',
                'severity' => $sev,
                'title'    => "WordPress core update available{$latest}",
                'detail'   => "You are running WordPress {$d['wp_version']} and a newer version is available. Outdated core versions are the most common vector for mass WordPress compromises.",
                'fix'      => 'Go to Dashboard → Updates and apply the WordPress core update. Back up first.',
                'affected' => "WordPress {$d['wp_version']}",
            ];
        }

        // ── Writable wp-config.php ──
        if ( ! empty( $d['wpconfig_writable'] ) ) {
            $findings[] = [
                'category'   => 'Security',
                'severity'   => 'high',
                'title'      => 'wp-config.php is writable by the web server process',
                'detail'     => 'wp-config.php should be read-only (0400 or 0440) so no PHP process running as the web server user can overwrite database credentials or secret keys.',
                'fix'        => 'Set permissions to 0400 (owner read-only): chmod 0400 wp-config.php. If the web server runs as a different user, use 0440 instead.',
                'fix_action' => 'wpconfig_perms',
                'affected'   => 'wp-config.php',
            ];
        }

        // ── Admin username ──
        if ( ! empty( $d['admin_user_exists'] ) ) {
            $findings[] = [
                'category' => 'Security',
                'severity' => 'high',
                'title'    => 'User account with username "admin" still exists',
                'detail'   => '"admin" is the most targeted username in WordPress brute-force attacks. Leaving it active makes credential-stuffing attacks far more likely to succeed.',
                'fix'      => 'Create a new administrator with a unique username, log in as the new user, then delete the "admin" account and reassign its content.',
                'affected' => 'WordPress users',
            ];
        }

        // ── Disk space ──
        if ( $d['disk_free_pct'] !== null ) {
            if ( $d['disk_free_pct'] < 5 ) {
                $findings[] = [
                    'category' => 'Performance',
                    'severity' => 'critical',
                    'title'    => "Disk critically low — {$d['disk_free_pct']}% free ({$d['disk_free_gb']} GB of {$d['disk_total_gb']} GB)",
                    'detail'   => 'Less than 5% disk space remaining. WordPress will fail to write uploads, logs, or cache files. Database operations may also fail if MySQL runs out of tmp space.',
                    'fix'      => 'Immediately remove unused uploads, clean up logs, and expand disk capacity. Use CloudScale Cleanup to purge database bloat.',
                    'affected' => "Disk ({$d['disk_free_gb']} GB free)",
                ];
            } elseif ( $d['disk_free_pct'] < 15 ) {
                $findings[] = [
                    'category' => 'Performance',
                    'severity' => 'high',
                    'title'    => "Disk space low — {$d['disk_free_pct']}% free ({$d['disk_free_gb']} GB of {$d['disk_total_gb']} GB)",
                    'detail'   => 'Disk space is below 15%. WordPress uploads, caching, and backup operations will start failing as space runs out.',
                    'fix'      => 'Remove unused media, clear old backups, purge log files, and consider upgrading your hosting plan.',
                    'affected' => "Disk ({$d['disk_free_gb']} GB free)",
                ];
            } elseif ( $d['disk_free_pct'] < 25 ) {
                $findings[] = [
                    'category' => 'Performance',
                    'severity' => 'medium',
                    'title'    => "Disk space at {$d['disk_free_pct']}% free ({$d['disk_free_gb']} GB of {$d['disk_total_gb']} GB)",
                    'detail'   => 'Less than 25% disk space remaining. Plan to free up space before it becomes critical.',
                    'fix'      => 'Review and remove large unused media files and old backups. Use the SQL Tool to identify large database tables.',
                    'affected' => "Disk ({$d['disk_free_gb']} GB free)",
                ];
            } else {
                $findings[] = [
                    'category' => 'Performance',
                    'severity' => 'info',
                    'title'    => "Disk space healthy — {$d['disk_free_pct']}% free ({$d['disk_free_gb']} GB of {$d['disk_total_gb']} GB)",
                    'detail'   => 'Disk space is at a comfortable level.',
                    'fix'      => 'No action needed. Run CloudScale Cleanup periodically to keep the database lean and free up space.',
                    'affected' => "Disk ({$d['disk_free_gb']} GB free)",
                    'cta'      => [
                        'label' => '🧹 CloudScale Cleanup — Free',
                        'url'   => 'https://andrewbaker.ninja/wordpress-plugin-help/cloudscale-database-cleanup-help/',
                        'desc'  => 'Remove expired transients, post revisions, spam comments, and orphaned metadata in one click.',
                    ],
                ];
            }
        }

        // ── SSH brute-force monitor ──
        if ( ! empty( $d['ssh_monitor_on'] ) ) {
            $findings[] = [
                'category' => 'Security',
                'severity' => 'info',
                'title'    => "SSH Brute-Force Monitor active — alert threshold: {$d['ssh_monitor_threshold']} failures/min",
                'detail'   => 'The SSH Brute-Force Monitor is reading /var/log/auth.log every 60 seconds and will alert via email and push notification if the threshold is breached.',
                'fix'      => 'No action needed. Adjust the threshold in Security → SSH Monitor Settings if you receive false-positive alerts.',
                'affected' => 'SSH / auth.log',
            ];
        } else {
            $findings[] = [
                'category' => 'Security',
                'severity' => 'medium',
                'title'    => 'SSH Brute-Force Monitor is disabled',
                'detail'   => 'The SSH monitor is not actively watching auth.log. Brute-force SSH attacks targeting this server will go undetected and unalerted.',
                'fix'      => 'Enable the SSH Brute-Force Monitor in Security → SSH Monitor Settings.',
                'affected' => 'SSH / auth.log',
            ];
        }

        // ── Username enumeration protection ──
        $devtools_cta = [
            'label' => '🔒 CloudScale Cyber DevTools — Free',
            'url'   => 'https://andrewbaker.ninja/wordpress-plugin-help/cloudscale-cyber-devtools-help/',
            'desc'  => 'Login security, brute-force protection, CSP, security headers, and more — all in one free plugin.',
        ];
        if ( get_option( 'csdt_devtools_enum_protect', '1' ) === '1' ) {
            $findings[] = [
                'category' => 'Security',
                'severity' => 'info',
                'title'    => 'Username enumeration protection active',
                'detail'   => 'Login errors are genericised to "Invalid username or password." — attackers cannot distinguish a missing username from a wrong password, preventing account enumeration.',
                'fix'      => 'No action needed.',
                'affected' => 'Login page',
                'cta'      => $devtools_cta,
            ];
        } else {
            $findings[] = [
                'category' => 'Security',
                'severity' => 'medium',
                'title'    => 'Username enumeration protection is disabled',
                'detail'   => 'WordPress reveals whether a username exists ("The username X is not registered on this site.") — attackers can automate this to enumerate all valid usernames in minutes.',
                'fix'      => 'Enable "Prevent account enumeration" in Security → Brute-Force Settings.',
                'affected' => 'Login page',
            ];
        }

        // ── Active brute-force attack detected ──
        if ( ! empty( $d['bf_today_count'] ) && $d['bf_today_count'] >= 30 ) {
            $bf_date = $d['bf_scan_date'] ?? wp_date( 'M j' );
            $findings[] = [
                'category'        => 'Security',
                'severity'        => 'critical',
                'title'           => "Active brute-force attack in progress — {$d['bf_today_count']} failed login attempts on {$bf_date}",
                'detail'          => "More than 30 failed WordPress login attempts were recorded on {$bf_date}. This indicates an automated credential-stuffing or brute-force campaign is actively targeting this site. Distributed attacks use rotating IPs to evade per-IP blocks.",
                'fix'             => 'Enable 2FA immediately for all administrator accounts — it makes credential guessing irrelevant even if an attacker has your password. Consider adding a Web Application Firewall (WAF) such as Cloudflare to block attacking IPs at the edge.',
                'affected'        => "{$d['bf_today_count']} attempts on {$bf_date}",
                'bf_last_attempt' => $d['bf_last_attempt'] ?? 0,
            ];
        }

        // ── Login brute-force protection ──
        if ( ! empty( $d['bf_enabled'] ) ) {
            $short_lockout = $d['bf_lockout_mins'] < 15;
            $has_2fa       = ! empty( $d['twofa_admins'] );
            if ( $short_lockout && ! $has_2fa ) {
                // Short lockout with no 2FA — low concern, some protection exists
                $findings[] = [
                    'category' => 'Security',
                    'severity' => 'low',
                    'title'    => "Brute-force lockout is short ({$d['bf_lockout_mins']} min) and 2FA is not enforced",
                    'detail'   => "A {$d['bf_lockout_mins']}-minute lockout after {$d['bf_attempts']} attempts provides limited protection — automated tools simply wait out the window. Enforcing 2FA on admin accounts would eliminate this risk entirely.",
                    'fix'      => 'Increase the lockout to 30–60 minutes in Security → Login Settings, or enforce 2FA for all administrators to make lockout duration irrelevant.',
                    'affected' => 'wp-login.php',
                ];
            } else {
                $twofa_note = $has_2fa ? ' 2FA enforcement means successful credential guessing still cannot lead to account takeover.' : '';
                $findings[] = [
                    'category' => 'Security',
                    'severity' => 'info',
                    'title'    => "Login brute-force protection active — lockout after {$d['bf_attempts']} attempts ({$d['bf_lockout_mins']} min)",
                    'detail'   => 'Per-account lockout is enforced on the WordPress login form.' . $twofa_note,
                    'fix'      => 'No action needed. Adjust thresholds in Security → Login Settings.',
                    'affected' => 'wp-login.php',
                ];
            }
        } else {
            $findings[] = [
                'category' => 'Security',
                'severity' => 'high',
                'title'    => 'WordPress login brute-force protection is disabled',
                'detail'   => 'Without per-account lockout, attackers can make unlimited login attempts against any username without being blocked.',
                'fix'      => 'Enable brute-force protection in Security → Login Settings.',
                'affected' => 'wp-login.php',
            ];
        }

        // ── Login URL hiding ──
        if ( ! empty( $d['login_hide_on'] ) ) {
            $findings[] = [
                'category' => 'Security',
                'severity' => 'info',
                'title'    => 'Login URL is hidden — wp-login.php is not publicly accessible',
                'detail'   => 'The login URL has been moved to a custom slug, significantly reducing automated login scan traffic.',
                'fix'      => 'No action needed.',
                'affected' => 'wp-login.php',
                'cta'      => $devtools_cta,
            ];
        } else {
            $findings[] = [
                'category' => 'Security',
                'severity' => 'medium',
                'title'    => 'Login URL is publicly accessible at /wp-login.php',
                'detail'   => 'The default WordPress login URL is a constant target for automated scanning bots. Hiding it eliminates a large class of credential-stuffing traffic.',
                'fix'      => 'Enable Hide Login URL in Security → Login Settings and set a custom login slug.',
                'affected' => 'wp-login.php',
            ];
        }

        // ── 2FA for admins ──
        if ( ! empty( $d['twofa_admins'] ) ) {
            $method_label = $d['twofa_method'] ? " (method: {$d['twofa_method']})" : '';
            $findings[] = [
                'category' => 'Security',
                'severity' => 'info',
                'title'    => "Two-factor authentication enforced for all admins{$method_label}",
                'detail'   => 'All administrator accounts must complete a second factor on login. This blocks account takeover even if a password is leaked.',
                'fix'      => 'No action needed. Passkeys offer the strongest protection — consider enabling them in Security → Two-Factor settings.',
                'affected' => 'Administrator accounts',
                'cta'      => $devtools_cta,
            ];
        } else {
            $findings[] = [
                'category' => 'Security',
                'severity' => 'high',
                'title'    => 'Two-factor authentication not enforced for admins',
                'detail'   => 'Administrator accounts can log in with a password alone. A single leaked or guessed password gives an attacker full site control.',
                'fix'      => 'Enable and enforce 2FA for all administrators in Security → Two-Factor settings. TOTP (authenticator app) or passkeys are recommended.',
                'affected' => 'Administrator accounts',
            ];
        }

        // ── Passkeys ──
        if ( ! empty( $d['passkeys_on'] ) ) {
            $count      = (int) ( $d['passkeys_count'] ?? 0 );
            $findings[] = [
                'category' => 'Security',
                'severity' => 'info',
                'title'    => "Passkeys (WebAuthn) registered — {$count} passkey(s) across admin accounts",
                'detail'   => 'Administrator accounts have passkeys registered. Passkeys provide phishing-resistant, passwordless login using device biometrics (Face ID, Touch ID, Windows Hello) or hardware security keys.',
                'fix'      => 'No action needed. Register additional passkeys for backup devices via Login Security → Passkeys.',
                'affected' => 'Administrator accounts',
                'cta'      => $devtools_cta,
            ];
        } else {
            $findings[] = [
                'category' => 'Security',
                'severity' => 'medium',
                'title'    => 'No passkeys registered — consider adding a passkey for phishing-resistant login',
                'detail'   => 'Passkeys use Face ID, Touch ID, Windows Hello, or hardware security keys for cryptographically strong authentication. They cannot be phished and require no code entry.',
                'fix'      => 'Go to Login Security → Passkeys (WebAuthn) and click + Add Passkey to register your device.',
                'affected' => 'Administrator accounts',
            ];
        }

        // ── DB table prefix ──
        if ( ! empty( $d['db_prefix_default'] ) ) {
            $findings[] = [
                'category'   => 'Security',
                'severity'   => 'medium',
                'title'      => 'Default database table prefix (wp_) — enumeration risk',
                'detail'     => 'The default wp_ prefix is a well-known attack target. SQL injection probes and automated scanners guess wp_ table names directly. Renaming to a random prefix adds a layer of obscurity.',
                'fix'        => 'Use the DB Prefix Migrator to rename all tables to a unique prefix and update wp-config.php automatically — no downtime required.',
                'fix_action' => 'db_prefix_modal',
                'affected'   => 'All database tables',
            ];
        }

        foreach ( self::get_cross_sell_findings( $d ) as $f ) {
            $findings[] = $f;
        }

        if ( empty( $findings ) ) {
            $findings[] = [
                'category' => 'info',
                'severity' => 'info',
                'title'    => 'No significant issues detected',
                'detail'   => "Your site passed all {$d['post_count']} content checks and database health checks with no major issues found. Add an AI key for deeper analysis and narrative recommendations.",
                'fix'      => 'Continue monitoring regularly. Add an AI API key for richer audit reports.',
                'affected' => "All {$d['post_count']} posts/pages",
            ];
        }

        // Stamp DevTools CTA on every Security/info finding that doesn't already have one
        $devtools_cta_stamp = [
            'label' => '🔒 CloudScale Cyber DevTools — Free',
            'url'   => 'https://andrewbaker.ninja/wordpress-plugin-help/cloudscale-cyber-devtools-help/',
            'desc'  => 'Login security, brute-force protection, CSP, security headers, and more — all in one free plugin.',
        ];
        foreach ( $findings as &$f ) {
            if ( ( $f['category'] ?? '' ) === 'Security' && ( $f['severity'] ?? '' ) === 'info' && empty( $f['cta'] ) ) {
                $f['cta'] = $devtools_cta_stamp;
            }
        }
        unset( $f );

        return $findings;
    }


    private static function check_ssl_certificate( string $host ): array {
        if ( empty( $host ) ) {
            return [ 'available' => false, 'error' => 'No host' ];
        }
        $ctx = stream_context_create( [
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer'       => false,
                'verify_peer_name'  => false,
            ],
        ] );
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $stream = @stream_socket_client( 'ssl://' . $host . ':443', $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx );
        if ( ! $stream ) {
            return [ 'available' => false, 'error' => $errstr ?: "errno $errno" ];
        }
        $params = stream_context_get_params( $stream );
        fclose( $stream );
        $cert_res = $params['options']['ssl']['peer_certificate'] ?? null;
        if ( ! $cert_res ) {
            return [ 'available' => false, 'error' => 'No peer cert captured' ];
        }
        $cert = openssl_x509_parse( $cert_res );
        if ( ! $cert ) {
            return [ 'available' => false, 'error' => 'openssl_x509_parse failed' ];
        }
        $valid_to  = $cert['validTo_time_t']   ?? 0;
        $valid_from= $cert['validFrom_time_t'] ?? 0;
        $now       = time();
        $days_left = $valid_to ? (int) floor( ( $valid_to - $now ) / DAY_IN_SECONDS ) : null;
        return [
            'available'     => true,
            'subject_cn'    => $cert['subject']['CN']  ?? '',
            'issuer'        => $cert['issuer']['CN']   ?? ( $cert['issuer']['O'] ?? '' ),
            'valid_from'    => $valid_from ? gmdate( 'Y-m-d', $valid_from ) : null,
            'valid_to'      => $valid_to   ? gmdate( 'Y-m-d', $valid_to )   : null,
            'days_left'     => $days_left,
            'expired'       => $days_left !== null && $days_left < 0,
            'expiring_soon' => $days_left !== null && $days_left >= 0 && $days_left < 30,
            'san'           => $cert['extensions']['subjectAltName'] ?? null,
        ];
    }

    private static function check_email_dns( string $host ): array {
        // Check MX records first — if none exist, email is not configured for this domain
        // and missing SPF/DMARC/DKIM is not a finding (there's nothing to protect).
        $mx_records = @dns_get_record( $host, DNS_MX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $has_mx     = is_array( $mx_records ) && ! empty( $mx_records );

        if ( ! $has_mx ) {
            return [
                'mx_present'     => false,
                'spf_present'    => false,
                'spf_record'     => null,
                'spf_strictness' => 'not_applicable',
                'dmarc_present'  => false,
                'dmarc_record'   => null,
                'dmarc_policy'   => 'not_applicable',
                'dmarc_pct'      => null,
                'dkim_present'   => false,
                'dkim_selector'  => null,
            ];
        }

        $spf_found   = false;
        $dmarc_found = false;
        $spf_record  = null;
        $dmarc_record= null;

        // SPF — TXT record on the apex domain
        $txt = @dns_get_record( $host, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( is_array( $txt ) ) {
            foreach ( $txt as $r ) {
                $txt_val = isset( $r['txt'] ) ? ( is_array( $r['txt'] ) ? implode( '', $r['txt'] ) : (string) $r['txt'] ) : '';
                if ( stripos( $txt_val, 'v=spf1' ) === 0 ) {
                    $spf_found  = true;
                    $spf_record = $txt_val;
                    break;
                }
            }
        }

        // DMARC — TXT record on _dmarc.domain
        $dmarc_txt = @dns_get_record( '_dmarc.' . $host, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( is_array( $dmarc_txt ) ) {
            foreach ( $dmarc_txt as $r ) {
                $txt_val = isset( $r['txt'] ) ? ( is_array( $r['txt'] ) ? implode( '', $r['txt'] ) : (string) $r['txt'] ) : '';
                if ( stripos( $txt_val, 'v=DMARC1' ) === 0 ) {
                    $dmarc_found  = true;
                    $dmarc_record = $txt_val;
                    break;
                }
            }
        }

        // DKIM — probe common selectors used by major ESPs
        $dkim_found    = false;
        $dkim_selector = null;
        foreach ( [ 'google', 'default', 'mail', 'dkim', 'k1', 'selector1', 'selector2', 'mandrill', 'mailjet', 'sendgrid', 'amazonses', 'smtp' ] as $sel ) {
            $dkim_txt = @dns_get_record( $sel . '._domainkey.' . $host, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if ( is_array( $dkim_txt ) ) {
                foreach ( $dkim_txt as $r ) {
                    $txt_val = isset( $r['txt'] ) ? ( is_array( $r['txt'] ) ? implode( '', $r['txt'] ) : (string) $r['txt'] ) : '';
                    if ( stripos( $txt_val, 'v=DKIM1' ) !== false ) {
                        $dkim_found    = true;
                        $dkim_selector = $sel;
                        break 2;
                    }
                }
            }
        }

        // SPF strictness — ~all (soft fail) still lets spoofed mail through
        $spf_strictness = 'missing';
        if ( $spf_found && $spf_record ) {
            if ( strpos( $spf_record, '+all' ) !== false )     { $spf_strictness = 'pass_all'; }
            elseif ( strpos( $spf_record, '-all' ) !== false ) { $spf_strictness = 'hard_fail'; }
            elseif ( strpos( $spf_record, '~all' ) !== false ) { $spf_strictness = 'soft_fail'; }
            elseif ( strpos( $spf_record, '?all' ) !== false ) { $spf_strictness = 'neutral'; }
            else                                               { $spf_strictness = 'unknown'; }
        }

        // DMARC policy — p=none does nothing (monitoring only)
        $dmarc_policy = 'missing';
        $dmarc_pct    = 100;
        if ( $dmarc_found && $dmarc_record ) {
            if ( preg_match( '/\bp=([^;\s]+)/i', $dmarc_record, $pm ) ) {
                $dmarc_policy = strtolower( trim( $pm[1] ) );
            }
            if ( preg_match( '/\bpct=(\d+)/i', $dmarc_record, $pm ) ) {
                $dmarc_pct = (int) $pm[1];
            }
        }

        return [
            'mx_present'     => true,
            'spf_present'    => $spf_found,
            'spf_record'     => $spf_record,
            'spf_strictness' => $spf_strictness,
            'dmarc_present'  => $dmarc_found,
            'dmarc_record'   => $dmarc_record,
            'dmarc_policy'   => $dmarc_policy,
            'dmarc_pct'      => $dmarc_pct,
            'dkim_present'   => $dkim_found,
            'dkim_selector'  => $dkim_selector,
        ];
    }

    private static function gather_ssh_status(): array {
        // Detect fail2ban installation and running state
        $fail2ban_paths = [
            '/usr/bin/fail2ban-client',
            '/usr/sbin/fail2ban-client',
            '/usr/local/bin/fail2ban-client',
        ];
        $fail2ban_installed = false;
        foreach ( $fail2ban_paths as $p ) {
            if ( file_exists( $p ) ) { $fail2ban_installed = true; break; }
        }
        $fail2ban_running = file_exists( '/var/run/fail2ban/fail2ban.pid' )
                         || file_exists( '/run/fail2ban/fail2ban.pid' );
        $fail2ban_jail    = file_exists( '/etc/fail2ban/jail.conf' )
                         || file_exists( '/etc/fail2ban/jail.local' );

        // Detect SSH daemon on port 22 (1-second timeout; skip if fsockopen unavailable)
        $ssh_port_open = false;
        $ssh_banner    = '';
        if ( function_exists( 'fsockopen' ) ) {
            $fp = @fsockopen( '127.0.0.1', 22, $errno, $errstr, 1 );
            if ( $fp !== false ) {
                $ssh_port_open = true;
                $banner        = @fgets( $fp, 128 );
                $ssh_banner    = $banner !== false ? trim( (string) $banner ) : '';
                fclose( $fp );
            }
        }

        // Parse sshd_config for key hardening settings (read-only; usually readable by www-data)
        $sshd_config       = '';
        $sshd_config_paths = [ '/etc/ssh/sshd_config', '/etc/sshd_config' ];
        foreach ( $sshd_config_paths as $cp ) {
            if ( is_readable( $cp ) ) { $sshd_config = file_get_contents( $cp ); break; } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        }
        $password_auth  = 'unknown'; // yes | no | unknown
        $root_login     = 'unknown'; // yes | no | prohibit-password | unknown
        $pubkey_auth    = 'unknown';
        if ( $sshd_config !== '' ) {
            if ( preg_match( '/^\s*PasswordAuthentication\s+(yes|no)/im', $sshd_config, $m ) ) {
                $password_auth = strtolower( $m[1] );
            }
            if ( preg_match( '/^\s*PermitRootLogin\s+(\S+)/im', $sshd_config, $m ) ) {
                $root_login = strtolower( $m[1] );
            }
            if ( preg_match( '/^\s*PubkeyAuthentication\s+(yes|no)/im', $sshd_config, $m ) ) {
                $pubkey_auth = strtolower( $m[1] );
            }
        }

        return [
            'fail2ban_installed' => $fail2ban_installed,
            'fail2ban_running'   => $fail2ban_running,
            'fail2ban_jail'      => $fail2ban_jail,
            'ssh_port_open'      => $ssh_port_open,
            'ssh_banner'         => $ssh_banner,
            'password_auth'      => $password_auth,
            'root_login'         => $root_login,
            'pubkey_auth'        => $pubkey_auth,
            'sshd_config_readable' => $sshd_config !== '',
        ];
    }

    private static function gather_external_checks( string $base_url = '' ): array {
        $base = $base_url ? trailingslashit( $base_url ) : home_url( '/' );
        $host = (string) wp_parse_url( $base, PHP_URL_HOST );

        $ext = [];

        // SSL certificate
        $ext['ssl'] = self::check_ssl_certificate( $host );

        // Helper: head request, returns [code, error]
        $head = function ( string $url ): array {
            $r = wp_remote_head( $url, [ 'timeout' => 4, 'sslverify' => false, 'redirection' => 0 ] );
            return is_wp_error( $r )
                ? [ 'code' => 'error', 'error' => $r->get_error_message() ]
                : [ 'code' => wp_remote_retrieve_response_code( $r ), 'location' => wp_remote_retrieve_header( $r, 'location' ) ];
        };

        // wp-login.php exposure
        $login_r = $head( $base . 'wp-login.php' );
        $ext['wp_login'] = [
            'code'       => $login_r['code'],
            'accessible' => isset( $login_r['code'] ) && is_int( $login_r['code'] ) && $login_r['code'] < 400,
        ];

        // xmlrpc.php
        $xmlrpc_r = $head( $base . 'xmlrpc.php' );
        $ext['xmlrpc'] = [
            'code'       => $xmlrpc_r['code'],
            'accessible' => isset( $xmlrpc_r['code'] ) && is_int( $xmlrpc_r['code'] ) && $xmlrpc_r['code'] < 400,
        ];

        // REST API user enumeration
        $rest_r = wp_remote_get( $base . 'wp-json/wp/v2/users', [ 'timeout' => 5, 'sslverify' => false ] );
        $ext['rest_users'] = [ 'exposed' => false, 'count' => 0, 'slugs' => [] ];
        if ( ! is_wp_error( $rest_r ) && wp_remote_retrieve_response_code( $rest_r ) === 200 ) {
            $users = json_decode( wp_remote_retrieve_body( $rest_r ), true );
            if ( is_array( $users ) && ! empty( $users ) ) {
                $ext['rest_users']['exposed'] = true;
                $ext['rest_users']['count']   = count( $users );
                $ext['rest_users']['slugs']   = array_values( array_slice( array_column( $users, 'slug' ), 0, 5 ) );
            }
        }

        // Author enumeration /?author=1
        $author_r = wp_remote_head( $base . '?author=1', [ 'timeout' => 4, 'sslverify' => false, 'redirection' => 0 ] );
        $ext['author_enum'] = [ 'exposed' => false ];
        if ( ! is_wp_error( $author_r ) ) {
            $code    = wp_remote_retrieve_response_code( $author_r );
            $loc_raw = wp_remote_retrieve_header( $author_r, 'location' );
            $loc     = is_array( $loc_raw ) ? ( reset( $loc_raw ) ?: '' ) : (string) ( $loc_raw ?? '' );
            if ( $code >= 300 && $code < 400 && $loc && strpos( $loc, '/author/' ) !== false ) {
                $ext['author_enum'] = [ 'exposed' => true, 'redirects_to' => $loc ];
            }
        }

        // Uploads directory listing
        $uploads_r = wp_remote_get( $base . 'wp-content/uploads/', [ 'timeout' => 4, 'sslverify' => false ] );
        $uploads_body = is_wp_error( $uploads_r ) ? '' : wp_remote_retrieve_body( $uploads_r );
        $ext['uploads_listing'] = (
            ! is_wp_error( $uploads_r ) &&
            wp_remote_retrieve_response_code( $uploads_r ) === 200 &&
            ( stripos( $uploads_body, 'Index of' ) !== false || stripos( $uploads_body, 'Parent Directory' ) !== false )
        );

        // Plugins and themes directory listing (reveals installed software to targeted attackers)
        $plugins_r    = wp_remote_get( $base . 'wp-content/plugins/', [ 'timeout' => 4, 'sslverify' => false ] );
        $plugins_body = is_wp_error( $plugins_r ) ? '' : wp_remote_retrieve_body( $plugins_r );
        $ext['plugins_listing'] = (
            ! is_wp_error( $plugins_r ) &&
            wp_remote_retrieve_response_code( $plugins_r ) === 200 &&
            ( stripos( $plugins_body, 'Index of' ) !== false || stripos( $plugins_body, 'Parent Directory' ) !== false )
        );

        $themes_r    = wp_remote_get( $base . 'wp-content/themes/', [ 'timeout' => 4, 'sslverify' => false ] );
        $themes_body = is_wp_error( $themes_r ) ? '' : wp_remote_retrieve_body( $themes_r );
        $ext['themes_listing'] = (
            ! is_wp_error( $themes_r ) &&
            wp_remote_retrieve_response_code( $themes_r ) === 200 &&
            ( stripos( $themes_body, 'Index of' ) !== false || stripos( $themes_body, 'Parent Directory' ) !== false )
        );

        // Exposed sensitive files
        $ext['exposed_files'] = [];
        foreach ( [ 'readme.html', 'license.txt', 'phpinfo.php', 'wp-config.php.bak', '.env', '.htaccess', '.git/config', 'error_log', 'composer.json', 'package.json' ] as $f ) {
            $r = $head( $base . $f );
            if ( isset( $r['code'] ) && is_int( $r['code'] ) && $r['code'] === 200 ) {
                $ext['exposed_files'][] = $f;
            }
        }

        // wp-cron.php publicly accessible (DDoS / resource-abuse vector)
        $cron_r = $head( $base . 'wp-cron.php' );
        $ext['wp_cron_public'] = isset( $cron_r['code'] ) && is_int( $cron_r['code'] ) && $cron_r['code'] < 400;

        // debug.log exposed (leaks credentials, stack traces, internal paths)
        $debug_r = $head( $base . 'wp-content/debug.log' );
        $ext['debug_log_exposed'] = isset( $debug_r['code'] ) && $debug_r['code'] === 200;

        // Adminer / phpMyAdmin reachable (full DB access)
        $db_tools_exposed = [];
        foreach ( [ 'adminer.php', 'adminer/', 'phpmyadmin/', 'pma/', 'phpMyAdmin/', 'db/' ] as $path ) {
            $r = $head( $base . $path );
            if ( isset( $r['code'] ) && is_int( $r['code'] ) && $r['code'] < 400 ) {
                $db_tools_exposed[] = $path;
            }
        }
        $ext['db_tools_exposed'] = $db_tools_exposed;

        // Apache server-status / server-info (leaks live requests and internal IPs)
        $server_status_r = $head( $base . 'server-status' );
        $server_info_r   = $head( $base . 'server-info' );
        $ext['server_status_exposed'] = isset( $server_status_r['code'] ) && $server_status_r['code'] === 200;
        $ext['server_info_exposed']   = isset( $server_info_r['code'] )   && $server_info_r['code'] === 200;

        // Backup archives exposed in webroot (full site or DB dump)
        $backup_files_exposed = [];
        $domain_slug          = str_replace( '.', '', (string) wp_parse_url( $base, PHP_URL_HOST ) );
        $backup_candidates    = [
            'backup.zip', 'backup.tar.gz', 'backup.sql',
            'site.zip', 'site.tar.gz',
            'wordpress.zip', 'wordpress.tar.gz',
            'db.sql', 'database.sql', 'dump.sql',
            $domain_slug . '.zip', $domain_slug . '.sql',
            'wp-backup.zip', 'backup.bak',
        ];
        foreach ( $backup_candidates as $f ) {
            $r = $head( $base . $f );
            if ( isset( $r['code'] ) && $r['code'] === 200 ) {
                $backup_files_exposed[] = $f;
            }
        }
        $ext['backup_files_exposed'] = $backup_files_exposed;

        // HTTP → HTTPS redirect enforcement
        $http_base    = preg_replace( '/^https:/i', 'http:', $base );
        $http_r       = wp_remote_head( $http_base, [ 'timeout' => 5, 'sslverify' => false, 'redirection' => 0 ] );
        $http_code    = is_wp_error( $http_r ) ? null : wp_remote_retrieve_response_code( $http_r );
        $http_loc_raw = is_wp_error( $http_r ) ? null : wp_remote_retrieve_header( $http_r, 'location' );
        $http_loc     = is_array( $http_loc_raw ) ? ( reset( $http_loc_raw ) ?: '' ) : (string) ( $http_loc_raw ?? '' );
        $ext['http_to_https'] = [
            'redirects'   => $http_code !== null && $http_code >= 300 && $http_code < 400 && $http_loc && stripos( $http_loc, 'https://' ) === 0,
            'http_code'   => $http_code,
        ];

        // TLS weak protocol check — test whether TLS 1.0 / 1.1 are still accepted
        $ext['tls_weak_protocols'] = [ 'checked' => false, 'tls10_accepted' => false, 'tls11_accepted' => false ];
        if ( function_exists( 'stream_socket_client' ) ) {
            $tls_tests = [];
            if ( defined( 'STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT' ) ) {
                $tls_tests['tls10_accepted'] = STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;
            }
            if ( defined( 'STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT' ) ) {
                $tls_tests['tls11_accepted'] = STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
            }
            foreach ( $tls_tests as $field => $crypto_method ) {
                $ext['tls_weak_protocols']['checked'] = true;
                $ctx  = stream_context_create( [
                    'ssl' => [
                        'crypto_method'    => $crypto_method,
                        'verify_peer'      => false,
                        'verify_peer_name' => false,
                    ],
                ] );
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                $sock = @stream_socket_client( 'ssl://' . $host . ':443', $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx );
                if ( $sock ) {
                    $ext['tls_weak_protocols'][ $field ] = true;
                    fclose( $sock );
                }
            }
        }

        // Cookie security flags — inspect Set-Cookie headers from wp-login.php
        $cookie_r = wp_remote_get( $base . 'wp-login.php', [ 'timeout' => 5, 'sslverify' => false ] );
        $ext['cookie_security'] = [ 'checked' => false ];
        if ( ! is_wp_error( $cookie_r ) ) {
            $raw_headers = wp_remote_retrieve_headers( $cookie_r );
            $set_cookies = [];
            // WP_HTTP_Requests_Response may return Set-Cookie as array or string
            $sc = $raw_headers['set-cookie'] ?? [];
            if ( is_string( $sc ) ) { $sc = [ $sc ]; }
            foreach ( (array) $sc as $cookie_str ) {
                if ( stripos( $cookie_str, 'wordpress' ) !== false ) {
                    $set_cookies[] = $cookie_str;
                }
            }
            if ( ! empty( $set_cookies ) ) {
                $all_secure   = true;
                $all_httponly = true;
                $all_samesite = true;
                foreach ( $set_cookies as $cs ) {
                    if ( stripos( $cs, '; Secure' ) === false )   { $all_secure   = false; }
                    if ( stripos( $cs, '; HttpOnly' ) === false )  { $all_httponly = false; }
                    if ( stripos( $cs, 'SameSite' ) === false )    { $all_samesite = false; }
                }
                $ext['cookie_security'] = [
                    'checked'    => true,
                    'secure'     => $all_secure,
                    'httponly'   => $all_httponly,
                    'samesite'   => $all_samesite,
                    'cookie_secure_constant' => defined( 'COOKIE_SECURE' ) && COOKIE_SECURE,
                ];
            }
        }

        // WAF / CDN detection
        $waf_detected  = [];
        $waf_headers_r = wp_remote_get( $base, [ 'timeout' => 5, 'sslverify' => false ] );
        if ( ! is_wp_error( $waf_headers_r ) ) {
            $wh = wp_remote_retrieve_headers( $waf_headers_r );
            if ( $wh['cf-ray'] || $wh['cf-cache-status'] || $wh['cf-request-id'] ) {
                $waf_detected[] = 'Cloudflare';
            }
            if ( $wh['x-sucuri-id'] || $wh['x-sucuri-cache'] ) {
                $waf_detected[] = 'Sucuri';
            }
            if ( $wh['x-fw-hash'] || $wh['x-fw-static'] ) {
                $waf_detected[] = 'Wordfence';
            }
            $xcache_val = is_array( $wh['x-cache'] ?? null ) ? implode( ', ', $wh['x-cache'] ) : (string) ( $wh['x-cache'] ?? '' );
            if ( $xcache_val && stripos( $xcache_val, 'cloudfront' ) !== false ) {
                $waf_detected[] = 'CloudFront';
            }
        }
        // Also check if Wordfence plugin is active (server-side indicator)
        $active_plugins = (array) get_option( 'active_plugins', [] );
        foreach ( $active_plugins as $pf ) {
            if ( stripos( $pf, 'wordfence' ) !== false && ! in_array( 'Wordfence', $waf_detected, true ) ) {
                $waf_detected[] = 'Wordfence (plugin active)';
            }
        }
        $ext['waf_cdn'] = [
            'detected' => ! empty( $waf_detected ),
            'providers'=> $waf_detected,
        ];

        // Email security — only include SPF/DMARC/DKIM data if the domain has MX records.
        // Without MX records the domain sends no email and missing records are not a finding.
        $email_dns = self::check_email_dns( $host );
        $ext['email_dns'] = $email_dns['mx_present']
            ? $email_dns
            : [ 'email_configured' => false ];

        // Security headers (from external perspective via public URL)
        $headers_r      = wp_remote_get( $base, [ 'timeout' => 5, 'sslverify' => false ] );
        $headers_status = is_wp_error( $headers_r ) ? 0 : (int) wp_remote_retrieve_response_code( $headers_r );
        $ext['security_headers_external'] = [];
        $ext['csp_duplicate_count']       = 0;
        // Track whether bot protection blocked us — a 4xx/5xx means headers are unverifiable,
        // not necessarily absent (e.g. Cloudflare challenge pages omit HSTS/CSP for their own page).
        $ext['headers_response_code']  = $headers_status;
        $ext['headers_scan_blocked']   = $headers_status >= 400;
        if ( ! is_wp_error( $headers_r ) ) {
            $h = wp_remote_retrieve_headers( $headers_r );
            // Build raw multi-value map to detect duplicate headers.
            // getAll() returns ['header-name' => value] where value may be an array
            // for sites that send the same header more than once.
            $raw_multi_ext = [];
            foreach ( (array) $h->getAll() as $hk => $hv ) {
                $key = strtolower( trim( (string) $hk ) );
                if ( is_array( $hv ) ) {
                    $raw_multi_ext[ $key ] = array_map( 'strval', $hv );
                } else {
                    $raw_multi_ext[ $key ][] = (string) $hv;
                }
            }
            foreach ( [ 'x-frame-options', 'x-content-type-options', 'strict-transport-security',
                        'content-security-policy', 'referrer-policy', 'permissions-policy',
                        'access-control-allow-origin', 'x-powered-by', 'server' ] as $hname ) {
                $all_vals = $raw_multi_ext[ $hname ] ?? null;
                if ( 'content-security-policy' === $hname && ! empty( $all_vals ) ) {
                    $ext['csp_duplicate_count'] = count( $all_vals );
                }
                // Use raw_multi_ext (built from getAll()) for reliable multi-value header access.
                $val = $raw_multi_ext[ $hname ] ?? null;
                $ext['security_headers_external'][ $hname ] = is_array( $val ) ? implode( ' | ', $val ) : $val;
            }
        }

        // CSP quality — presence alone is not enough; weak directives leave XSS open.
        // unsafe-inline and unsafe-eval are WordPress compatibility requirements (plugins
        // like Underscore.js templates need eval; many plugins inject inline scripts).
        // We flag them as acknowledged trade-offs rather than open issues when our plugin
        // owns the CSP, so the AI doesn't report them as actionable findings.
        $csp_val          = $ext['security_headers_external']['content-security-policy'] ?? null;
        $csp_nonces_on    = get_option( 'csdt_csp_nonces_enabled', '0' ) === '1';
        $csp_plugin_owned = get_option( 'csdt_devtools_csp_enabled', '0' ) === '1';
        $csp_quality      = [ 'present' => (bool) $csp_val, 'issues' => [], 'acknowledged' => [] ];
        if ( $ext['headers_scan_blocked'] ) {
            $csp_quality['grade'] = 'unverifiable';
        } elseif ( $csp_val ) {
            if ( stripos( $csp_val, "'unsafe-inline'" ) !== false ) {
                // Only flag unsafe-inline if nonce mode is off AND we're not managing it ourselves
                if ( $csp_nonces_on ) {
                    $csp_quality['acknowledged'][] = 'unsafe-inline-removed-by-nonces';
                } elseif ( $csp_plugin_owned ) {
                    $csp_quality['acknowledged'][] = 'unsafe-inline-wordpress-compat';
                } else {
                    $csp_quality['issues'][] = 'unsafe-inline';
                }
            }
            if ( stripos( $csp_val, "'unsafe-eval'" ) !== false ) {
                // unsafe-eval is required by WordPress's bundled underscore.js (_.template)
                if ( $csp_plugin_owned ) {
                    $csp_quality['acknowledged'][] = 'unsafe-eval-wordpress-compat';
                } else {
                    $csp_quality['issues'][] = 'unsafe-eval';
                }
            }
            if ( preg_match( '/(?:^|[\s;])(\*)[\s;]/', $csp_val ) ) { $csp_quality['issues'][] = 'wildcard-source'; }
            if ( stripos( $csp_val, 'default-src' ) === false )     { $csp_quality['issues'][] = 'no-default-src'; }
            $csp_quality['grade'] = empty( $csp_quality['issues'] ) ? 'good' : 'weak';
        } else {
            $csp_quality['grade'] = 'missing';
        }
        $ext['csp_quality'] = $csp_quality;

        // HSTS quality — max-age must be ≥1 year to be effective
        $hsts_val    = $ext['security_headers_external']['strict-transport-security'] ?? null;
        $hsts_quality = [ 'present' => (bool) $hsts_val, 'issues' => [] ];
        if ( $ext['headers_scan_blocked'] ) {
            $hsts_quality['grade'] = 'unverifiable';
        } elseif ( $hsts_val ) {
            $max_age = 0;
            if ( preg_match( '/max-age=(\d+)/i', $hsts_val, $m ) ) { $max_age = (int) $m[1]; }
            $hsts_quality['max_age']             = $max_age;
            $hsts_quality['includes_subdomains'] = stripos( $hsts_val, 'includeSubDomains' ) !== false;
            $hsts_quality['preload']             = stripos( $hsts_val, 'preload' ) !== false;
            if ( $max_age < 31536000 )              { $hsts_quality['issues'][] = 'max-age-too-short'; }
            if ( ! $hsts_quality['includes_subdomains'] ) { $hsts_quality['issues'][] = 'no-includeSubDomains'; }
            $hsts_quality['grade'] = empty( $hsts_quality['issues'] ) ? 'good' : 'weak';
        } else {
            $hsts_quality['grade'] = 'missing';
        }
        $ext['hsts_quality'] = $hsts_quality;

        // Server header version leak — e.g. "nginx/1.18.0" reveals exact version for CVE targeting
        $server_hdr = $ext['security_headers_external']['server'] ?? null;
        $ext['server_version_leak'] = [
            'header'        => $server_hdr,
            'leaks_version' => $server_hdr !== null && (bool) preg_match( '/\/[\d.]+/', $server_hdr ),
        ];

        return $ext;
    }

    private static function scan_plugin_code(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active_plugins = (array) get_option( 'active_plugins', [] );
        $plugins_dir    = WP_PLUGIN_DIR;

        // Patterns that warrant attention in plugin code
        $patterns = [
            // Remote code execution
            'eval('                          => 'eval()',
            'base64_decode('                 => 'base64_decode()',
            'exec('                          => 'exec()',
            'shell_exec('                    => 'shell_exec()',
            'system('                        => 'system()',
            'passthru('                      => 'passthru()',
            'popen('                         => 'popen()',
            'proc_open('                     => 'proc_open()',
            'assert('                        => 'assert()',
            'preg_replace.*\/e'              => 'preg_replace /e modifier',
            'create_function('               => 'create_function()',
            // File operations with user input
            'file_put_contents.*\$_'         => 'file_put_contents with user input',
            'move_uploaded_file'             => 'move_uploaded_file()',
            // Outbound requests with user input
            'wp_remote_get.*\$_'             => 'outbound request with user input',
            // SQL injection — direct use of user input in DB queries
            '\$wpdb->(query|get_results|get_row|get_var|prepare).*\$_(GET|POST|REQUEST|COOKIE)' => 'SQL query with raw user input (SQLi risk)',
            // XSS — echoing user input without escaping
            'echo\s+\$_(GET|POST|REQUEST|COOKIE|SERVER)\[' => 'echo user input without escaping (XSS risk)',
            'print\s+\$_(GET|POST|REQUEST|COOKIE)\['       => 'print user input without escaping (XSS risk)',
            // Unsafe deserialization
            'unserialize\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)' => 'unserialize() with user input (RCE/object injection)',
            // Remote file inclusion
            'include\s*\(\s*\$_(GET|POST|REQUEST)'         => 'include() with user input (RFI risk)',
            'require\s*\(\s*\$_(GET|POST|REQUEST)'         => 'require() with user input (RFI risk)',
        ];

        $results = [];

        foreach ( $active_plugins as $plugin_file ) {
            $plugin_slug = dirname( $plugin_file );
            if ( $plugin_slug === '.' ) {
                continue; // single-file plugin, skip
            }
            $plugin_path = $plugins_dir . '/' . $plugin_slug;
            if ( ! is_dir( $plugin_path ) ) {
                continue;
            }

            // Skip known safe large libraries
            $skip_dirs = [ 'vendor', 'node_modules', 'assets', 'dist', 'build' ];

            $findings      = [];
            $files_scanned = 0;

            $iter = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator( $plugin_path, FilesystemIterator::SKIP_DOTS ),
                    function ( $file, $key, $iter ) use ( $skip_dirs ) {
                        if ( $iter->hasChildren() ) {
                            return ! in_array( $file->getFilename(), $skip_dirs, true );
                        }
                        return $file->getExtension() === 'php';
                    }
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ( $iter as $file ) {
                if ( $files_scanned >= 200 ) {
                    break; // cap per plugin
                }
                $files_scanned++;
                $content = is_readable( $file->getPathname() ) ? file_get_contents( $file->getPathname() ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                if ( $content === false ) {
                    continue;
                }
                $rel = str_replace( $plugin_path . '/', '', $file->getPathname() );
                foreach ( $patterns as $needle => $label ) {
                    if ( preg_match( '/' . $needle . '/i', $content ) ) {
                        // Get the first matching line for context
                        $lines = explode( "\n", $content );
                        foreach ( $lines as $ln => $line ) {
                            if ( preg_match( '/' . $needle . '/i', $line ) ) {
                                $findings[] = [
                                    'pattern' => $label,
                                    'file'    => $rel,
                                    'line'    => $ln + 1,
                                    'snippet' => trim( substr( $line, 0, 120 ) ),
                                ];
                                break; // one example per pattern per file
                            }
                        }
                        if ( count( $findings ) >= 15 ) {
                            break 2; // cap total findings per plugin
                        }
                    }
                }
            }

            if ( ! empty( $findings ) ) {
                $results[] = [
                    'plugin'        => $plugin_slug,
                    'files_scanned' => $files_scanned,
                    'findings'      => $findings,
                ];
            }
        }

        return $results;
    }

    /**
     * AI-powered triage of static code scan findings.
     * Reads ±10 lines of context around each flagged line, sends up to 10 snippets
     * to the cheapest available model, and returns per-snippet verdicts.
     */
    private static function triage_code_snippets_with_ai( array $scan_results ): array {
        if ( empty( $scan_results ) ) {
            return [ 'skipped' => true, 'reason' => 'no_findings', 'results' => [] ];
        }

        // Flatten findings and sort by risk priority
        $priority_order = [
            'eval()', 'unserialize() with user input (RCE/object injection)',
            'preg_replace /e modifier', 'create_function()',
            'SQL query with raw user input (SQLi risk)',
            'include() with user input (RFI risk)', 'require() with user input (RFI risk)',
            'exec()', 'shell_exec()', 'system()', 'passthru()', 'popen()', 'proc_open()',
            'base64_decode()', 'assert()', 'echo user input without escaping (XSS risk)',
            'print user input without escaping (XSS risk)',
            'file_put_contents with user input', 'move_uploaded_file()',
            'outbound request with user input',
        ];

        $flat = [];
        foreach ( $scan_results as $plugin_result ) {
            foreach ( $plugin_result['findings'] as $finding ) {
                $flat[] = array_merge( $finding, [ 'plugin' => $plugin_result['plugin'] ] );
            }
        }

        usort( $flat, function ( $a, $b ) use ( $priority_order ) {
            $ai = array_search( $a['pattern'], $priority_order, true );
            $bi = array_search( $b['pattern'], $priority_order, true );
            $ai = $ai === false ? 999 : $ai;
            $bi = $bi === false ? 999 : $bi;
            return $ai - $bi;
        } );

        $top = array_slice( $flat, 0, 10 );

        // Build snippet blocks with ±10 lines of context
        $blocks = [];
        foreach ( $top as $idx => $s ) {
            $abs = WP_PLUGIN_DIR . '/' . $s['plugin'] . '/' . $s['file'];
            $ctx = '';
            if ( is_readable( $abs ) ) {
                $lines = @file( $abs, FILE_IGNORE_NEW_LINES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                if ( is_array( $lines ) ) {
                    $start = max( 0, $s['line'] - 11 );
                    $end   = min( count( $lines ) - 1, $s['line'] + 9 );
                    for ( $i = $start; $i <= $end; $i++ ) {
                        $marker = ( $i + 1 === $s['line'] ) ? '  // <<< FLAGGED' : '';
                        $ctx   .= ( $i + 1 ) . ': ' . $lines[ $i ] . $marker . "\n";
                    }
                }
            }
            if ( ! $ctx ) {
                $ctx = $s['line'] . ': ' . $s['snippet'] . "  // <<< FLAGGED\n";
            }
            $blocks[] = '[' . ( $idx + 1 ) . '] Plugin: ' . $s['plugin']
                . ' | File: ' . $s['file']
                . ' | Line: ' . $s['line']
                . ' | Flagged as: ' . $s['pattern'] . "\n"
                . "```php\n" . $ctx . '```';
        }

        $system = 'You are a WordPress PHP security expert. Analyse code snippets flagged by automated static analysis. Determine whether each is a genuine exploitable vulnerability or a false positive. Be precise — many static flags are false positives (e.g. eval() inside a template engine, base64_decode() for legitimate asset loading, shell_exec() behind a capability check). Return ONLY a valid JSON array with no markdown wrapping.';

        $user = 'Analyse these ' . count( $blocks ) . " flagged PHP snippets from active WordPress plugins. The flagged line is marked // <<< FLAGGED.\n\n"
              . implode( "\n\n", $blocks ) . "\n\n"
              . "Return a JSON array — one object per snippet:\n"
              . '{"id":<n>,"verdict":"confirmed|false_positive|needs_context","severity":"critical|high|medium|low|none","type":"<vulnerability type or null>","explanation":"<1-2 concise sentences>","fix":"<specific code-level fix or null if false positive>"}';

        // Use cheapest/fastest model for triage — cost ~$0.01-0.03 per scan
        $provider     = get_option( 'csdt_devtools_ai_provider', 'anthropic' );
        $triage_model = $provider === 'gemini' ? 'gemini-2.0-flash' : 'claude-haiku-4-5-20251001';

        try {
            $raw = CSDT_AI_Dispatcher::call( $system, $user, $triage_model, 2048 );
        } catch ( \Throwable $e ) {
            return [ 'skipped' => true, 'reason' => 'api_error', 'error' => $e->getMessage(), 'results' => [] ];
        }

        // Strip markdown fences if present
        $raw = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw ) );
        $raw = preg_replace( '/\s*```$/i', '', trim( $raw ) );

        $verdicts = json_decode( $raw, true );
        if ( ! is_array( $verdicts ) ) {
            return [ 'skipped' => true, 'reason' => 'parse_error', 'raw_preview' => substr( $raw, 0, 300 ), 'results' => [] ];
        }

        // Index verdicts by id for merge
        $by_id = [];
        foreach ( $verdicts as $v ) {
            if ( isset( $v['id'] ) ) { $by_id[ (int) $v['id'] ] = $v; }
        }

        $output = [];
        foreach ( $top as $idx => $s ) {
            $v        = $by_id[ $idx + 1 ] ?? [];
            $output[] = [
                'plugin'      => $s['plugin'],
                'file'        => $s['file'],
                'line'        => $s['line'],
                'pattern'     => $s['pattern'],
                'verdict'     => $v['verdict']     ?? 'needs_context',
                'severity'    => $v['severity']    ?? 'unknown',
                'type'        => $v['type']        ?? null,
                'explanation' => $v['explanation'] ?? null,
                'fix'         => $v['fix']         ?? null,
            ];
        }

        $confirmed = array_filter( $output, function ( $r ) { return $r['verdict'] === 'confirmed'; } );

        return [
            'skipped'          => false,
            'snippets_triaged' => count( $output ),
            'confirmed_count'  => count( $confirmed ),
            'results'          => $output,
        ];
    }


    private static function audit_users(): array {
        $weak_usernames = [ 'admin', 'administrator', 'webmaster', 'root', 'wp-admin', 'wordpress', 'test', 'user', 'demo' ];
        $admins         = get_users( [ 'role' => 'administrator' ] );
        $weak_admin_logins = [];
        $admins_no_2fa     = [];

        foreach ( $admins as $user ) {
            if ( in_array( strtolower( $user->user_login ), $weak_usernames, true ) ) {
                $weak_admin_logins[] = $user->user_login;
            }
            $has_totp    = get_user_meta( $user->ID, 'csdt_devtools_totp_enabled', true ) === '1';
            $has_passkey = ! empty( get_user_meta( $user->ID, 'csdt_devtools_passkeys', true ) );
            $has_email2fa= get_option( 'csdt_devtools_2fa_method', 'off' ) === 'email';
            if ( ! $has_totp && ! $has_passkey && ! $has_email2fa ) {
                $admins_no_2fa[] = $user->user_login;
            }
        }

        $role_counts = [];
        foreach ( [ 'editor', 'author', 'contributor', 'subscriber' ] as $role ) {
            $count = count( get_users( [ 'role' => $role, 'fields' => 'ID' ] ) );
            if ( $count > 0 ) {
                $role_counts[ $role ] = $count;
            }
        }

        return [
            'admin_count'         => count( $admins ),
            'weak_admin_usernames'=> $weak_admin_logins,
            'admins_without_2fa'  => $admins_no_2fa,
            'admins_without_2fa_count' => count( $admins_no_2fa ),
            'non_admin_role_counts'=> $role_counts,
        ];
    }

    private static function audit_cron_events(): array {
        $crons = _get_cron_array();
        if ( empty( $crons ) || ! is_array( $crons ) ) {
            return [ 'disable_wp_cron' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, 'total_events' => 0, 'hooks' => [], 'suspicious_hooks' => [] ];
        }

        // Collect all scheduled hook names
        $all_hooks = [];
        foreach ( $crons as $hooks ) {
            foreach ( array_keys( $hooks ) as $hook ) {
                $all_hooks[] = $hook;
            }
        }
        $unique_hooks = array_values( array_unique( $all_hooks ) );

        // Known WP core hooks
        $core_hooks = [
            'wp_scheduled_delete', 'wp_update_plugins', 'wp_update_themes', 'wp_version_check',
            'wp_scheduled_auto_draft_delete', 'delete_expired_transients', 'wp_privacy_delete_old_export_files',
            'recovery_mode_clean_expired_keys', 'wp_site_health_scheduled_check',
            'wp_update_user_counts', 'wp_delete_temp_updater_backups',
        ];

        // Build known hooks from active plugins (use option-stored hook prefixes as heuristic)
        $active_plugins = (array) get_option( 'active_plugins', [] );
        $plugin_prefixes = array_map( fn( $f ) => strtolower( str_replace( '-', '_', dirname( $f ) ) ), $active_plugins );

        $suspicious = [];
        foreach ( $unique_hooks as $hook ) {
            if ( in_array( $hook, $core_hooks, true ) ) {
                continue;
            }
            $matched = false;
            foreach ( $plugin_prefixes as $prefix ) {
                if ( $prefix !== '.' && stripos( $hook, $prefix ) !== false ) {
                    $matched = true;
                    break;
                }
            }
            // Also pass through anything with common legit patterns
            if ( ! $matched && ! preg_match( '/^(wp_|wc_|woo|yoast|rank_math|acf_|tribe_|vc_|elementor|jetpack|akismet|wordfence|sucuri|updraft|backup|cache|cron|schedule|clean|purge|sync|check|update|send|mail|report)/i', $hook ) ) {
                $suspicious[] = $hook;
            }
        }

        return [
            'disable_wp_cron' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
            'total_events'    => count( $unique_hooks ),
            'hooks'           => array_slice( $unique_hooks, 0, 30 ),
            'suspicious_hooks'=> $suspicious,
        ];
    }

    private static function enrich_plugins_with_wporg( array $active_plugin_files ): array {
        $results = [];
        $two_years_ago = strtotime( '-2 years' );

        foreach ( $active_plugin_files as $plugin_file ) {
            $slug = dirname( $plugin_file );
            if ( $slug === '.' ) {
                continue; // single-file plugin, skip
            }

            $resp = wp_remote_get(
                'https://api.wordpress.org/plugins/info/1.0/' . rawurlencode( $slug ) . '.json',
                [ 'timeout' => 6, 'sslverify' => true ]
            );
            if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
                continue;
            }
            $data = json_decode( wp_remote_retrieve_body( $resp ), true );
            if ( empty( $data ) || isset( $data['error'] ) ) {
                continue; // not in WP.org repo (premium plugin etc.)
            }

            $last_updated_ts = isset( $data['last_updated'] ) ? strtotime( $data['last_updated'] ) : null;
            $results[ $slug ] = [
                'slug'             => $slug,
                'last_updated'     => $data['last_updated'] ?? null,
                'last_updated_ts'  => $last_updated_ts,
                'abandoned'        => $last_updated_ts && $last_updated_ts < $two_years_ago,
                'years_since_update' => $last_updated_ts ? round( ( time() - $last_updated_ts ) / YEAR_IN_SECONDS, 1 ) : null,
                'active_installs'  => $data['active_installs'] ?? null,
                'rating'           => isset( $data['rating'] ) ? (int) $data['rating'] : null,
                'requires_wp'      => $data['requires'] ?? null,
                'tested_up_to'     => $data['tested'] ?? null,
            ];
        }

        return $results;
    }

    private static function check_plugin_vulnerabilities( array $active_plugin_files, array $all_plugins ): array {
        $vulns = [];

        foreach ( $active_plugin_files as $plugin_file ) {
            $slug    = dirname( $plugin_file );
            $version = $all_plugins[ $plugin_file ]['Version'] ?? null;
            if ( $slug === '.' || ! $version ) {
                continue;
            }

            // Patchstack public vulnerability API — no key required
            $resp = wp_remote_get(
                'https://patchstack.com/database/api/v1/vulnerability?search=' . rawurlencode( $slug ) . '&per_page=5',
                [ 'timeout' => 8, 'sslverify' => true ]
            );
            if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
                continue;
            }
            $data = json_decode( wp_remote_retrieve_body( $resp ), true );
            if ( empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
                continue;
            }

            foreach ( $data['data'] as $vuln ) {
                $fixed_in = $vuln['fixed_in'] ?? null;
                // Only include if the installed version is affected (below fixed_in, or no fix released)
                $affected = ! $fixed_in || version_compare( $version, $fixed_in, '<' );
                if ( ! $affected ) {
                    continue;
                }
                $vulns[] = [
                    'plugin'       => $slug,
                    'version'      => $version,
                    'cve'          => $vuln['cve_id'] ?? null,
                    'title'        => $vuln['title'] ?? $vuln['vuln_type'] ?? 'Unknown vulnerability',
                    'severity'     => $vuln['severity'] ?? null,
                    'cvss'         => $vuln['cvss_score'] ?? null,
                    'fixed_in'     => $fixed_in,
                    'disclosed_at' => $vuln['disclosed_at'] ?? null,
                ];
                if ( count( $vulns ) >= 20 ) {
                    break 2; // cap total
                }
            }
        }

        return $vulns;
    }

    private static function check_core_integrity(): array {
        $version = get_bloginfo( 'version' );
        $resp    = wp_remote_get(
            'https://api.wordpress.org/core/checksums/1.0/?version=' . rawurlencode( $version ) . '&locale=en_US',
            [ 'timeout' => 8, 'sslverify' => true ]
        );
        if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            return [ 'available' => false, 'error' => 'Could not fetch checksums from WordPress.org' ];
        }
        $body      = json_decode( wp_remote_retrieve_body( $resp ), true );
        $checksums = $body['checksums'] ?? null;
        if ( ! is_array( $checksums ) ) {
            return [ 'available' => false, 'error' => 'Invalid checksum response' ];
        }

        // High-value files most commonly backdoored
        $check_files = [
            'index.php',
            'wp-login.php',
            'wp-settings.php',
            'wp-load.php',
            'wp-config-sample.php',
            'wp-includes/functions.php',
            'wp-includes/pluggable.php',
            'wp-includes/class-wp-hook.php',
            'wp-includes/class-wp-query.php',
            'wp-includes/user.php',
            'wp-admin/index.php',
            'wp-admin/includes/file.php',
        ];

        $modified  = [];
        $missing   = [];
        $checked   = 0;

        foreach ( $check_files as $file ) {
            if ( ! isset( $checksums[ $file ] ) ) {
                continue;
            }
            $path = ABSPATH . $file;
            if ( ! file_exists( $path ) ) {
                $missing[] = $file;
                continue;
            }
            $checked++;
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $actual = @md5_file( $path );
            if ( $actual && $actual !== $checksums[ $file ] ) {
                $modified[] = $file;
            }
        }

        return [
            'available'      => true,
            'wp_version'     => $version,
            'files_checked'  => $checked,
            'modified_files' => $modified,
            'missing_files'  => $missing,
            'clean'          => empty( $modified ) && empty( $missing ),
        ];
    }

    private static function scan_malware_indicators(): array {
        $uploads_dir = wp_upload_dir();
        $uploads_base= $uploads_dir['basedir'];

        // 1. PHP files in uploads directory (should be zero)
        $php_in_uploads = [];
        if ( is_dir( $uploads_base ) ) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $uploads_base, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ( $iter as $file ) {
                if ( $file->getExtension() === 'php' ) {
                    $php_in_uploads[] = str_replace( $uploads_base . '/', '', $file->getPathname() );
                    if ( count( $php_in_uploads ) >= 10 ) {
                        break;
                    }
                }
            }
        }

        // 2. PHP files modified in the last 7 days outside plugin/theme dirs
        $recently_modified = [];
        $cutoff            = time() - ( 7 * DAY_IN_SECONDS );
        $skip_paths        = [ WP_PLUGIN_DIR, get_theme_root() ];

        $scan_dirs = [ ABSPATH, ABSPATH . 'wp-includes', ABSPATH . 'wp-admin' ];
        foreach ( $scan_dirs as $dir ) {
            if ( ! is_dir( $dir ) ) {
                continue;
            }
            $iter = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
                    function ( $file, $key, $iter ) use ( $skip_paths ) {
                        if ( $iter->hasChildren() ) {
                            foreach ( $skip_paths as $skip ) {
                                if ( strpos( $file->getPathname(), $skip ) === 0 ) {
                                    return false;
                                }
                            }
                            return true;
                        }
                        return $file->getExtension() === 'php';
                    }
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ( $iter as $file ) {
                if ( $file->getMTime() > $cutoff ) {
                    $recently_modified[] = str_replace( ABSPATH, '', $file->getPathname() );
                    if ( count( $recently_modified ) >= 15 ) {
                        break 2;
                    }
                }
            }
        }

        return [
            'php_files_in_uploads'      => $php_in_uploads,
            'php_files_in_uploads_count'=> count( $php_in_uploads ),
            'recently_modified_php'     => $recently_modified,
            'recently_modified_count'   => count( $recently_modified ),
        ];
    }

    private static function gather_theme_data(): array {
        $theme        = wp_get_theme();
        $parent       = $theme->parent();
        $update_themes = get_site_transient( 'update_themes' );
        $has_update   = isset( $update_themes->response[ $theme->get_stylesheet() ] );
        $parent_update = $parent ? isset( $update_themes->response[ $parent->get_stylesheet() ] ) : false;
        return [
            'active_theme'        => $theme->get( 'Name' ),
            'active_theme_version'=> $theme->get( 'Version' ),
            'active_theme_update' => $has_update,
            'parent_theme'        => $parent ? $parent->get( 'Name' ) : null,
            'parent_theme_update' => $parent_update,
        ];
    }

    private static function check_auth_salts(): array {
        $defaults = [ 'put your unique phrase here', '' ];
        $keys     = [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
                      'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ];
        $weak     = [];
        foreach ( $keys as $k ) {
            if ( ! defined( $k ) || in_array( constant( $k ), $defaults, true ) || strlen( constant( $k ) ) < 32 ) {
                $weak[] = $k;
            }
        }
        return [
            'all_set'  => empty( $weak ),
            'weak_keys'=> $weak,
        ];
    }


    private static function gather_deep_security_data(): array {
        $base           = self::gather_security_data();
        $active_files   = (array) get_option( 'active_plugins', [] );
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins    = get_plugins();
        $external       = self::gather_external_checks();
        $code_scan      = self::scan_plugin_code();
        $theme          = self::gather_theme_data();
        $salts          = self::check_auth_salts();
        $wporg_data     = self::enrich_plugins_with_wporg( $active_files );
        $cve_data       = self::check_plugin_vulnerabilities( $active_files, $all_plugins );
        $core_integrity = self::check_core_integrity();
        $malware        = self::scan_malware_indicators();
        $user_audit     = self::audit_users();
        $cron_audit     = self::audit_cron_events();
        $ssh_status     = self::gather_ssh_status();

        // PHP end-of-life status
        $php_eol_dates = [
            '5.6' => '2018-12-31',
            '7.0' => '2019-01-10',
            '7.1' => '2019-12-01',
            '7.2' => '2019-11-30',
            '7.3' => '2020-12-06',
            '7.4' => '2022-11-28',
            '8.0' => '2023-11-26',
            '8.1' => '2025-12-31',
            '8.2' => '2026-12-31',
            '8.3' => '2027-12-31',
            '8.4' => '2028-12-31',
        ];
        $php_minor    = implode( '.', array_slice( explode( '.', PHP_VERSION ), 0, 2 ) );
        $php_eol_date = $php_eol_dates[ $php_minor ] ?? null;
        $php_is_eol   = $php_eol_date !== null && strtotime( $php_eol_date ) < time();
        $php_eol_info = [
            'version'    => PHP_VERSION,
            'minor'      => $php_minor,
            'eol_date'   => $php_eol_date,
            'is_eol'     => $php_is_eol,
            'days_since' => ( $php_is_eol && $php_eol_date ) ? (int) round( ( time() - strtotime( $php_eol_date ) ) / 86400 ) : null,
            'known'      => $php_eol_date !== null,
        ];

        // WordPress auto-update configuration
        $auto_updates = [
            'updater_globally_disabled' => defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED,
            'core_auto_update_constant' => defined( 'WP_AUTO_UPDATE_CORE' ) ? WP_AUTO_UPDATE_CORE : null,
        ];
        $auto_updates['core_disabled'] = $auto_updates['updater_globally_disabled'] || $auto_updates['core_auto_update_constant'] === false;

        // PHP display_errors — exposes stack traces and file paths to all visitors
        $di_raw         = (string) ini_get( 'display_errors' );
        $display_errors = [
            'display_errors_on' => ! in_array( $di_raw, [ '', '0', 'Off', 'off', 'FALSE', 'false' ], true ),
            'wp_debug_display'  => defined( 'WP_DEBUG_DISPLAY' ) ? WP_DEBUG_DISPLAY : null,
            'ini_value'         => $di_raw,
        ];

        // Inactive (deactivated) plugins — installed on disk, still exploitable via directory traversal
        $inactive_plugins = [];
        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            if ( ! in_array( $plugin_file, $active_files, true ) ) {
                $inactive_plugins[] = [
                    'name'    => $plugin_data['Name'],
                    'version' => $plugin_data['Version'],
                    'file'    => $plugin_file,
                ];
            }
        }

        return array_merge( $base, [
            'theme'              => $theme,
            'auth_salts'         => $salts,
            'user_audit'         => $user_audit,
            'cron_audit'         => $cron_audit,
            'plugin_wporg'       => $wporg_data,
            'plugin_cves'        => $cve_data,
            'core_integrity'     => $core_integrity,
            'malware_indicators' => $malware,
            'external_checks'    => $external,
            'plugin_code_scan'   => $code_scan,
            'php_eol'            => $php_eol_info,
            'auto_updates'       => $auto_updates,
            'display_errors'     => $display_errors,
            'inactive_plugins'   => $inactive_plugins,
            'ssh_status'         => $ssh_status,
        ] );
    }

    private static function default_deep_scan_prompt(): string {
        return <<<'PROMPT'
You are a professional penetration tester and WordPress security expert performing a comprehensive security audit.

You will receive a JSON object with these categories:

1. Internal config — WP/PHP versions (also php_eol key: version, minor, eol_date, is_eol, days_since — EOL PHP receives no security patches, treat as critical if is_eol=true), debug flags, DISALLOW_FILE_EDIT/MODS, FORCE_SSL_ADMIN, database prefix, admin username, user counts, brute force, 2FA (email/TOTP/passkey counts), login URL obfuscation, wp-config.php permissions. Also includes app_passwords: enabled flag, how many admins have application passwords created (app passwords bypass 2FA). display_errors key: display_errors_on=true means PHP stack traces and file paths are exposed to all visitors — high risk on any production site. auto_updates key: updater_globally_disabled and core_disabled flags — if core_disabled=true the site will not auto-patch security releases.
2. Site config — open user registration, pingbacks enabled (DDoS amplification), WP version in meta generator tag, comment defaults.
3. Theme — active theme name/version, pending update for active or parent theme.
4. Auth salts — all 8 WP secret keys/salts set and non-default (weak salts = session forgery).
5. User audit (user_audit) — admin_count, weak_admin_usernames (e.g. "admin", "administrator"), admins_without_2fa (list of admin logins with no TOTP/passkey/email 2FA), non_admin_role_counts.
6. Cron audit (cron_audit) — disable_wp_cron flag, suspicious_hooks (scheduled hook names that don't match any active plugin or WP core hook — potential malware persistence).
7. Plugin WP.org data (plugin_wporg) — for each active plugin: last_updated, abandoned (>2 years since update), years_since_update, active_installs, tested_up_to. Abandoned plugins with low install counts are high risk. Also includes inactive_plugins key: list of installed-but-deactivated plugins (name, version, file) — they sit on disk unpatched and can be exploited via directory traversal or have known CVEs even though not running.
8. Known CVEs (plugin_cves) — each entry has: plugin slug, version installed, CVE ID, title, severity (critical/high/medium/low), CVSS score, fixed_in version. ANY unfixed CVE at critical/high severity is a critical finding.
9. Core file integrity (core_integrity) — MD5 comparison of key WP core files against WordPress.org checksums. modified_files = likely backdoor. This is CRITICAL if any files are listed.
10. Malware indicators (malware_indicators) — php_files_in_uploads (PHP files found in uploads dir — should be zero, any found = likely webshell), recently_modified_php (core PHP files modified in last 7 days outside plugin/theme dirs — warrants investigation).
11. External checks — SSL validity/expiry, HTTP→HTTPS redirect, TLS weak protocols (tls_weak_protocols: checked, tls10_accepted, tls11_accepted — TLS 1.0/1.1 deprecated since 2021, susceptible to POODLE/BEAST attacks), wp-login.php/xmlrpc.php/wp-cron.php access, REST API user enum (rest_users: exposed, count, slugs), author enum, directory listings (uploads_listing, plugins_listing, themes_listing — plugins/themes listing reveals exact software versions to attackers), exposed files (debug.log, .env, backup archives, phpinfo.php, .git/config etc), adminer/phpMyAdmin, server-status/server-info, WAF/CDN detected (waf_cdn.detected, waf_cdn.providers), cookie_security (WP session cookies Secure/HttpOnly/SameSite flags), email DNS (email_dns: if email_configured=false the domain has no MX records — do NOT mention email DNS at all, it is irrelevant; otherwise spf_present, spf_strictness: hard_fail=good/soft_fail=weak/pass_all=dangerous; dmarc_present, dmarc_policy: none=monitoring-only-does-nothing/quarantine=acceptable/reject=best, dmarc_pct; dkim_present, dkim_selector — all three required with strong policies for full spoofing protection), security headers (csp_quality: grade good/weak/missing, issues: unsafe-inline/unsafe-eval/wildcard-source/no-default-src — any issue weakens XSS mitigation; hsts_quality: grade, max_age, includes_subdomains, issues — max-age < 31536000 means HTTPS not enforced for a full year; X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, access-control-allow-origin — wildcard "*" allows credential theft from any origin), server_version_leak: leaks_version=true means Server header discloses exact software version (e.g. nginx/1.18.0) aiding targeted CVE exploitation.
12. Plugin code scan — raw static analysis findings (may include false positives): RCE functions (eval, exec, shell_exec, base64_decode), SQLi (wpdb with raw $_GET/$_POST), XSS (unescaped echo of user input), unserialize with user input, RFI (include/require with user input). Includes plugin, file, line number.
13. Code triage (code_triage) — AI-verified verdicts on the static scan findings. Each entry: plugin, file, line, verdict (confirmed|false_positive|needs_context), severity, type, explanation, fix. ONLY report confirmed findings as real vulnerabilities — ignore false_positives. Use triage severity for confirmed items. For needs_context, mention at low severity with explanation.
14. SSH status (ssh_status) — server-level SSH hardening. fail2ban_installed/fail2ban_running/fail2ban_jail: whether the fail2ban daemon is present and active. ssh_port_open: whether SSH is listening on port 22 (standard port is a brute-force target). password_auth: yes=password login allowed (brute-forceable)/no=key-only (secure)/unknown=could not read config. root_login: yes=root can log in directly (critical)/no or prohibit-password=safer. pubkey_auth: yes=key auth enabled. sshd_config_readable: whether the config file was accessible at scan time. If ssh_port_open=false this is a container/managed environment — omit SSH findings entirely.

Cross-correlate ALL categories for compound risks:
- Known CVE (critical/high) = immediately critical regardless of other factors
- Modified core files = active compromise, treat as critical
- PHP files in uploads = likely webshell, treat as critical
- wp-login.php accessible + brute force disabled = critical combined risk
- Abandoned plugin (>2 years) + known CVE = critical
- No WAF/CDN detected + multiple exposed endpoints = significantly elevated risk
- email_dns.email_configured=false = domain sends no email; do NOT mention SPF/DMARC/DKIM at all, not even as informational
- email_dns present (no email_configured key) + missing SPF + DMARC = email spoofing trivially possible
- email_dns.spf_strictness=soft_fail (~all) = SPF won't block spoofed emails — flag medium; -all required
- email_dns.dmarc_policy=none = DMARC record exists but does nothing (monitoring only) — flag medium; quarantine/reject required to block
- email_dns.spf_strictness=soft_fail + dmarc_policy=none = email spoofing fully unblocked despite records existing — escalate to high
- wp-cron.php public = unauthenticated resource exhaustion
- Default auth salts = any active session can be forged
- debug.log exposed = credentials and stack traces publicly readable
- WP version in meta + outdated WP = targeted exploit possible
- Admins without 2FA = single password compromise = full site takeover
- Application passwords enabled + admins have app passwords = 2FA bypassable via REST API
- Suspicious cron hooks = possible malware persistence mechanism
- WP session cookies missing Secure/HttpOnly = session hijacking risk
- PHP EOL (php_eol.is_eol=true) = no security patches for PHP engine itself — critical if days_since > 365
- TLS 1.0/1.1 accepted = deprecated protocols, POODLE/BEAST exploitable — mark high
- Missing DKIM (dkim_present=false) = email spoofing possible even with SPF+DMARC — all three needed
- plugins_listing or themes_listing exposed = reveals exact plugin/theme versions to targeted attackers
- access-control-allow-origin: "*" = wildcard CORS allows any site to make credentialed requests — critical if combined with sensitive REST endpoints
- REST API user enum exposed (rest_users.exposed=true) = real usernames exposed for credential stuffing — escalates brute force risk significantly
- Abandoned plugin (plugin_wporg: abandoned=true) with no active CVEs = still high risk — unpatched future vulnerabilities likely
- display_errors.display_errors_on=true = PHP stack traces with file paths and variable values visible to all visitors — mark high on production
- auto_updates.core_disabled=true = WP core will not auto-patch security releases; combined with outdated WP version = high risk
- inactive_plugins count > 0 = deactivated plugins on disk are unpatched attack surface; flag names and versions for awareness
- ssh_port_open=true + fail2ban_running=false = CRITICAL: SSH exposed with no brute-force protection — unprotected SSH is actively recruited into botnets and DDoS amplification networks within hours of exposure; recommend fail2ban with sshd jail as immediate remediation
- ssh_port_open=true + password_auth=yes + fail2ban_running=false = CRITICAL: password brute-force fully unblocked on SSH — automated credential-stuffing tools will attempt thousands of passwords per minute; server compromise leads directly to DDoS botnet enlistment
- ssh_port_open=true + root_login=yes = CRITICAL: direct root SSH login permitted — successful brute-force gives immediate full server control with no privilege escalation required
- ssh_port_open=true + fail2ban_running=true + password_auth=no = good finding: SSH hardened — brute-force protection active and key-only authentication enforced
- ssh_port_open=true + fail2ban_running=true = good finding: SSH brute-force protection active via fail2ban
- ssh_port_open=true + password_auth=no = good finding: SSH key-only authentication enforced, password attacks impossible
- ssh_port_open=false = container/managed environment; omit all SSH findings entirely
- headers_scan_blocked=true = the headers check was blocked by bot protection (WAF/CDN, e.g. Cloudflare returned a 403 challenge page). The challenge page's own headers do NOT represent the real site. Do NOT report any security headers (HSTS, CSP, X-Frame-Options, etc.) as missing or weak — they cannot be verified. Add exactly one low finding: "Security headers unverifiable — scan blocked by bot protection (HTTP <headers_response_code>)."
- csp_quality.grade=unverifiable or hsts_quality.grade=unverifiable = same as headers_scan_blocked — do not report missing headers
- csp_quality.grade=missing or weak + any XSS code finding = actively exploitable XSS without browser-side mitigation
- csp_quality.acknowledged contains 'unsafe-inline-wordpress-compat' or 'unsafe-eval-wordpress-compat' = these are deliberate WordPress compatibility requirements (plugins/themes require inline scripts; underscore.js templates require eval). Do NOT report these as findings or weaknesses — they are known trade-offs. Only flag unsafe-inline/eval if they appear in csp_quality.issues (i.e. the CSP is externally managed and these are uncontrolled)
- csp_quality.acknowledged contains 'unsafe-inline-removed-by-nonces' = nonce-based CSP is active, unsafe-inline is a no-op — report as a good finding
- hsts_quality.grade=missing or max_age < 31536000 = HTTPS not enforced long-term, HTTP downgrade / MITM possible
- server_version_leak.leaks_version=true + unpatched software = version fingerprinting directly aids targeted exploitation — escalate severity

Return ONLY a JSON object (no markdown, no code fences, no explanation):
{
  "score": <integer 0-100>,
  "score_label": "<Excellent|Good|Fair|Poor|Critical>",
  "summary": "<2-3 sentence executive summary — lead with the most critical finding>",
  "critical": [{"title":"...","detail":"...","fix":"..."}],
  "high":     [{"title":"...","detail":"...","fix":"..."}],
  "medium":   [{"title":"...","detail":"...","fix":"..."}],
  "low":      [{"title":"...","detail":"...","fix":"..."}],
  "good":     [{"title":"...","detail":"..."}]
}

Scoring (be strict — known CVEs and modified core files force score to 0-34):
90-100: Excellent — no CVEs, clean core, hardened config, no significant exposure
75-89:  Good — minor issues only, no critical/high CVEs
55-74:  Fair — medium CVEs or some external exposure
35-54:  Poor — high CVEs, multiple exposures, or config weaknesses
0-34:   Critical — critical CVE, modified core files, webshell indicators, or actively exploitable exposure

Name exact plugin slugs, CVE IDs, file paths, and settings in every finding. Include GOOD PRACTICES for correctly hardened items.
PROMPT;
    }

    public static function ajax_deep_scan(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $cache_only = ! empty( $_POST['cache_only'] );

        // Page-load pre-fill: return cache silently or signal nothing cached
        if ( $cache_only ) {
            $cached = get_option( 'csdt_deep_scan_v1' );
            if ( $cached !== false ) {
                wp_send_json_success( array_merge( $cached, [ 'from_cache' => true ] ) );
            } else {
                wp_send_json_success( [ 'no_cache' => true ] );
            }
            return;
        }

        $ai_cfg  = CSDT_AI_Dispatcher::get_config();
        $provider = $ai_cfg['provider'];
        if ( ! $ai_cfg['key'] ) {
            wp_send_json_error( [ 'message' => 'No API key configured.', 'need_key' => true ] );
            return;
        }

        // Clear previous result and mark as running
        delete_option( 'csdt_deep_scan_v1' );
        set_transient( 'csdt_deep_scan_status', [ 'status' => 'running', 'started_at' => time() ], 900 );

        // Send response immediately, then run scan after connection closes
        CSDT_AI_Dispatcher::send_and_continue( [ 'queued' => true ] );
        self::cron_deep_scan();
        exit;
    }

    public static function cron_deep_scan(): void {
        if ( function_exists( 'set_time_limit' ) ) { set_time_limit( 0 ); }

        try {
            if ( get_transient( 'csdt_deep_scan_cancelled' ) ) {
                delete_transient( 'csdt_deep_scan_cancelled' );
                return;
            }

            $model     = get_option( 'csdt_devtools_deep_scan_model', '_auto_deep' );
            $base_data = self::gather_security_data();
            $external  = self::gather_external_checks();
            $code_scan   = self::scan_plugin_code();
            $code_triage = self::triage_code_snippets_with_ai( $code_scan );

            if ( get_transient( 'csdt_deep_scan_cancelled' ) ) {
                delete_transient( 'csdt_deep_scan_cancelled' );
                return;
            }

            $msg_internal = 'WordPress internal configuration data (JSON):' . "\n\n" . wp_json_encode( $base_data, JSON_PRETTY_PRINT );
            $msg_external = 'WordPress external exposure, plugin code scan, and AI code triage data (JSON):' . "\n\n" . wp_json_encode( [
                'external_checks'  => $external,
                'plugin_code_scan' => $code_scan,
                'code_triage'      => $code_triage,
            ], JSON_PRETTY_PRINT );

            if ( function_exists( 'curl_multi_init' ) ) {
                $texts    = CSDT_AI_Dispatcher::call_parallel( [
                    [ 'system' => self::default_internal_scan_prompt(), 'user' => $msg_internal, 'model' => $model, 'max_tokens' => 4096 ],
                    [ 'system' => self::default_external_scan_prompt(), 'user' => $msg_external, 'model' => $model, 'max_tokens' => 4096 ],
                ] );
                $report = CSDT_AI_Dispatcher::merge_reports( CSDT_AI_Dispatcher::parse_json_report( $texts[0] ), CSDT_AI_Dispatcher::parse_json_report( $texts[1] ) );
            } else {
                // Fallback: single sequential call
                $text   = CSDT_AI_Dispatcher::call( self::default_deep_scan_prompt(), 'WordPress site full security data (JSON):' . "\n\n" . wp_json_encode( [ 'internal' => $base_data, 'external_checks' => $external, 'plugin_code_scan' => $code_scan, 'code_triage' => $code_triage ], JSON_PRETTY_PRINT ), $model, 8192 );
                $report = CSDT_AI_Dispatcher::parse_json_report( $text );
            }

        } catch ( \Throwable $e ) {
            set_transient( 'csdt_deep_scan_status', [ 'status' => 'error', 'message' => $e->getMessage() ], 300 );
            return;
        }

        if ( get_transient( 'csdt_deep_scan_cancelled' ) ) {
            delete_transient( 'csdt_deep_scan_cancelled' );
            return;
        }

        $output = [
            'report'      => $report,
            'code_triage' => $code_triage,
            'model_used'  => get_option( 'csdt_devtools_ai_provider', 'anthropic' ) . '/' . $model,
            'scanned_at'  => time(),
            'from_cache'  => false,
        ];

        update_option( 'csdt_deep_scan_v1', $output, false );
        set_transient( 'csdt_deep_scan_status', [ 'status' => 'complete', 'completed_at' => time() ], 900 );
        self::append_scan_history( 'deep', $report, $output['model_used'], $output['scanned_at'] );
    }

    public static function append_scan_history( string $type, array $report, string $model_used, int $scanned_at ): void {
        $history = get_option( 'csdt_scan_history', [] );
        if ( ! is_array( $history ) ) { $history = []; }
        array_unshift( $history, [
            'type'           => $type,
            'score'          => $report['score']       ?? null,
            'score_label'    => $report['score_label'] ?? '',
            'summary'        => $report['summary']     ?? '',
            'critical_count' => count( $report['critical'] ?? [] ),
            'high_count'     => count( $report['high']     ?? [] ),
            'model_used'     => $model_used,
            'scanned_at'     => $scanned_at,
            'findings'       => [
                'critical' => $report['critical'] ?? [],
                'high'     => $report['high']     ?? [],
                'medium'   => $report['medium']   ?? [],
                'low'      => $report['low']      ?? [],
                'good'     => $report['good']     ?? [],
            ],
        ] );
        // Keep last 50 across both scan types
        $history = array_slice( $history, 0, 50 );
        update_option( 'csdt_scan_history', $history, false );
    }

    public static function ajax_scan_history(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
            return;
        }
        wp_send_json_success( get_option( 'csdt_scan_history', [] ) );
    }

    public static function ajax_scan_status(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : 'standard';

        if ( $type === 'adhoc' ) {
            $status = get_transient( 'csdt_adhoc_scan_status' );
            if ( ! $status ) {
                wp_send_json_success( [ 'status' => 'idle' ] );
                return;
            }
            if ( $status['status'] === 'running' ) {
                wp_send_json_success( [ 'status' => 'running' ] );
                return;
            }
            if ( $status['status'] === 'complete' ) {
                $history = get_option( 'csdt_adhoc_scans', [] );
                $latest  = is_array( $history ) && ! empty( $history ) ? $history[0] : null;
                if ( $latest ) {
                    wp_send_json_success( [ 'status' => 'complete', 'data' => $latest ] );
                    return;
                }
            }
            if ( $status['status'] === 'error' ) {
                wp_send_json_success( [ 'status' => 'error', 'message' => $status['message'] ?? 'Scan failed.' ] );
                return;
            }
            wp_send_json_success( [ 'status' => 'idle' ] );
            return;
        }

        if ( $type === 'deep' ) {
            $status_key = 'csdt_deep_scan_status';
            $result_key = 'csdt_deep_scan_v1';
        } else {
            $status_key = 'csdt_vuln_scan_status';
            $result_key = 'csdt_security_scan_v2';
        }

        $status = get_transient( $status_key );
        $result = get_option( $result_key );

        if ( ! $status ) {
            if ( $result ) {
                wp_send_json_success( [ 'status' => 'complete', 'data' => array_merge( $result, [ 'from_cache' => true ] ) ] );
            } else {
                wp_send_json_success( [ 'status' => 'idle' ] );
            }
            return;
        }

        if ( $status['status'] === 'running' ) {
            wp_send_json_success( [ 'status' => 'running' ] );
            return;
        }

        if ( $status['status'] === 'complete' && $result ) {
            wp_send_json_success( [ 'status' => 'complete', 'data' => array_merge( $result, [ 'from_cache' => false ] ) ] );
            return;
        }

        if ( $status['status'] === 'error' ) {
            wp_send_json_success( [ 'status' => 'error', 'message' => $status['message'] ?? 'Scan failed.' ] );
            return;
        }

        wp_send_json_success( [ 'status' => 'idle' ] );
    }

    // ── Adhoc scan — external URL deep probe ──────────────────────────────

    public static function ajax_adhoc_scan(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
            return;
        }

        $target_url = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '';
        if ( ! $target_url || ! filter_var( $target_url, FILTER_VALIDATE_URL ) ) {
            wp_send_json_error( [ 'message' => 'Invalid target URL.' ] );
            return;
        }

        if ( ! CSDT_AI_Dispatcher::has_key() ) {
            wp_send_json_error( [ 'message' => 'No API key configured.', 'need_key' => true ] );
            return;
        }

        delete_transient( 'csdt_adhoc_scan_cancelled' );
        set_transient( 'csdt_adhoc_scan_status', [ 'status' => 'running', 'started_at' => time(), 'target_url' => $target_url ], 900 );

        CSDT_AI_Dispatcher::send_and_continue( [ 'queued' => true ] );
        self::run_adhoc_scan( $target_url );
        exit;
    }

    private static function run_adhoc_scan( string $target_url ): void {
        if ( function_exists( 'set_time_limit' ) ) { set_time_limit( 0 ); } // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- background scan must not time out.

        try {
            if ( get_transient( 'csdt_adhoc_scan_cancelled' ) ) {
                delete_transient( 'csdt_adhoc_scan_cancelled' );
                return;
            }

            $external = self::gather_external_checks( $target_url );
            $model    = get_option( 'csdt_devtools_deep_scan_model', '_auto_deep' );
            $msg      = 'External WordPress site security probe for ' . $target_url . ". Data (JSON):\n\n"
                      . wp_json_encode( [ 'target_url' => $target_url, 'external_checks' => $external ], JSON_PRETTY_PRINT );

            $text   = CSDT_AI_Dispatcher::call( self::default_external_scan_prompt(), $msg, $model, 4096 );
            $report = CSDT_AI_Dispatcher::parse_json_report( $text );
        } catch ( \Throwable $e ) {
            set_transient( 'csdt_adhoc_scan_status', [ 'status' => 'error', 'message' => $e->getMessage() ], 300 );
            return;
        }

        $output = [
            'target_url'  => $target_url,
            'report'      => $report,
            'model_used'  => get_option( 'csdt_devtools_ai_provider', 'anthropic' ) . '/' . $model,
            'scanned_at'  => time(),
        ];

        $history = get_option( 'csdt_adhoc_scans', [] );
        if ( ! is_array( $history ) ) { $history = []; }
        array_unshift( $history, $output );
        update_option( 'csdt_adhoc_scans', array_slice( $history, 0, 20 ), false );

        set_transient( 'csdt_adhoc_scan_status', [ 'status' => 'complete', 'completed_at' => time() ], 900 );
    }

    public static function ajax_adhoc_delete(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
            return;
        }
        $idx     = isset( $_POST['idx'] ) ? (int) sanitize_key( $_POST['idx'] ) : -1;
        $history = get_option( 'csdt_adhoc_scans', [] );
        if ( is_array( $history ) && $idx >= 0 && isset( $history[ $idx ] ) ) {
            array_splice( $history, $idx, 1 );
            update_option( 'csdt_adhoc_scans', $history, false );
        }
        wp_send_json_success( [ 'deleted' => true ] );
    }

}
