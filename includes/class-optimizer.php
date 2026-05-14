<?php
/**
 * Optimizer tab — DB orphaned table cleanup, plugin stack scanner, DB intelligence, and AI debug log.
 *
 * @package CloudScale_DevTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CSDT_Optimizer {

    public static function ajax_db_orphaned_scan(): void {
        check_ajax_referer( CloudScale_DevTools::OPTIMIZER_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;
        $prefix     = $wpdb->prefix;
        $prefix_len = strlen( $prefix );
        $core       = CSDT_Site_Audit::core_table_suffixes();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $all_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );

        // Build a set of table suffixes owned by currently-active plugins via their table-prefix mappings.
        // This prevents tables belonging to installed plugins from appearing as "orphaned".
        $active_plugin_suffixes = [];
        $active_plugins = (array) get_option( 'active_plugins', [] );
        foreach ( $active_plugins as $plugin_file ) {
            $slug = dirname( $plugin_file );
            // Map known plugin slugs to the table-suffix prefixes they own.
            $slug_suffix_map = [
                'cloudscale-wordpress-free-analytics' => [ 'cs_analytics_' ],
            ];
            if ( isset( $slug_suffix_map[ $slug ] ) ) {
                foreach ( $slug_suffix_map[ $slug ] as $sfx ) {
                    $active_plugin_suffixes[] = $sfx;
                }
            }
        }

        $non_core = array_values( array_filter( $all_tables, function ( $t ) use ( $prefix_len, $core, $active_plugin_suffixes ) {
            $suffix = substr( $t, $prefix_len );
            if ( in_array( $suffix, $core, true ) ) {
                return false;
            }
            foreach ( $active_plugin_suffixes as $sfx ) {
                if ( str_starts_with( $suffix, $sfx ) ) {
                    return false;
                }
            }
            return true;
        } ) );

        if ( empty( $non_core ) ) {
            wp_send_json_success( [ 'tables' => [] ] );
            return;
        }

        // Fetch row counts and sizes from information_schema
        $placeholders = implode( ',', array_fill( 0, count( $non_core ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT TABLE_NAME as name, TABLE_ROWS as rows,
                        ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024) as size_kb,
                        TABLE_TYPE as table_type,
                        DATE_FORMAT(CREATE_TIME, '%%Y-%%m-%%d') as created_date
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
                ...$non_core
            )
        );

        $size_map    = [];
        $rows_map    = [];
        $type_map    = [];
        $created_map = [];
        foreach ( $rows as $r ) {
            $size_map[ $r->name ]    = (int) $r->size_kb;
            $rows_map[ $r->name ]    = (int) $r->rows;
            $type_map[ $r->name ]    = $r->table_type ?? 'BASE TABLE';
            $created_map[ $r->name ] = $r->created_date ?? '';
        }

        $result = [];
        foreach ( $non_core as $table ) {
            $suffix   = substr( $table, $prefix_len );
            $result[] = [
                'table'        => $table,
                'plugin'       => CSDT_Site_Audit::guess_plugin_from_suffix( $suffix ),
                'rows'         => $rows_map[ $table ] ?? 0,
                'size_kb'      => $size_map[ $table ] ?? 0,
                'table_type'   => $type_map[ $table ] ?? 'BASE TABLE',
                'created_date' => $created_map[ $table ] ?? '',
            ];
        }

        usort( $result, fn( $a, $b ) => strcmp( $a['plugin'] . $a['table'], $b['plugin'] . $b['table'] ) );

        wp_send_json_success( [ 'tables' => $result ] );
    }

    public static function ajax_db_identify_table(): void {
        check_ajax_referer( CloudScale_DevTools::OPTIMIZER_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $table_names = json_decode( wp_unslash( $_POST['table_names'] ?? '' ), true );
        if ( ! is_array( $table_names ) || empty( $table_names ) ) {
            wp_send_json_error( 'No tables specified.' );
            return;
        }

        global $wpdb;

        // Build table descriptions: name + columns for each
        $descriptions = [];
        foreach ( $table_names as $table ) {
            $table = sanitize_text_field( $table );
            if ( ! $table ) { continue; }
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $cols = $wpdb->get_col( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`' );
            if ( $cols ) {
                $descriptions[] = '- ' . $table . ': ' . implode( ', ', $cols );
            }
        }

        if ( empty( $descriptions ) ) {
            wp_send_json_error( 'No valid tables found.' );
            return;
        }

        $prompt = "Identify which WordPress plugin or theme created each of these database tables.\n"
                . "Return ONLY a valid JSON object. Each key is the full table name. Each value is an object with:\n"
                . "  \"plugin\": plugin name (2-5 words)\n"
                . "  \"description\": one sentence describing what the plugin does\n"
                . "  \"url\": plugin homepage URL (wordpress.org/plugins/... or official site)\n"
                . "  \"confidence\": \"High\", \"Medium\", or \"Low\"\n"
                . "No markdown, no explanation, only the JSON.\n\n"
                . "Tables:\n" . implode( "\n", $descriptions );

        if ( function_exists( 'set_time_limit' ) ) { set_time_limit( 120 ); }

        try {
            $raw_text = CSDT_AI_Dispatcher::call( '', $prompt, '_auto', 8192 );
        } catch ( \RuntimeException $e ) {
            wp_send_json_error( 'AI error: ' . $e->getMessage() );
            return;
        }

        if ( ! $raw_text ) {
            wp_send_json_error( 'AI did not respond.' );
            return;
        }

        // Strip optional ```json ... ``` fences
        $raw_text = preg_replace( '/^```(?:json)?\s*/i', '', $raw_text );
        $raw_text = preg_replace( '/\s*```\s*$/i', '', $raw_text );

        $map = json_decode( trim( $raw_text ), true );
        if ( ! is_array( $map ) ) {
            wp_send_json_error( 'AI returned unexpected format.' );
            return;
        }

        // Sanitize values — support both flat string and object per entry
        $clean = [];
        foreach ( $map as $tbl => $entry ) {
            $key = sanitize_text_field( $tbl );
            if ( is_array( $entry ) ) {
                $clean[ $key ] = [
                    'plugin'      => sanitize_text_field( $entry['plugin']      ?? '' ),
                    'description' => sanitize_text_field( $entry['description'] ?? '' ),
                    'url'         => esc_url_raw( $entry['url']                 ?? '' ),
                    'confidence'  => in_array( $entry['confidence'] ?? '', [ 'High', 'Medium', 'Low' ], true )
                                     ? $entry['confidence'] : 'Low',
                ];
            } else {
                $clean[ $key ] = [ 'plugin' => sanitize_text_field( (string) $entry ) ];
            }
        }

        wp_send_json_success( [ 'map' => $clean ] );
    }

    public static function ajax_db_archive_tables(): void {
        check_ajax_referer( CloudScale_DevTools::OPTIMIZER_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $tables = json_decode( wp_unslash( $_POST['tables'] ?? '' ), true );
        if ( ! is_array( $tables ) || empty( $tables ) ) {
            wp_send_json_error( 'No tables specified.' );
            return;
        }

        global $wpdb;
        $prefix = $wpdb->prefix;
        $core   = CSDT_Site_Audit::core_table_suffixes();
        $prefix_len = strlen( $prefix );
        $date   = gmdate( 'Ymd' );

        $archived = [];
        $errors   = [];

        foreach ( $tables as $table ) {
            $table = sanitize_text_field( $table );
            if ( ! str_starts_with( $table, $prefix ) ) {
                $errors[] = $table . ' (wrong prefix)';
                continue;
            }
            if ( in_array( substr( $table, $prefix_len ), $core, true ) ) {
                $errors[] = $table . ' (core — protected)';
                continue;
            }
            $new_name = '_trash_' . $date . '_' . $table;
            // Avoid collision
            $i = 1;
            while ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_name ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $new_name = '_trash_' . $date . '_' . $i . '_' . $table;
                $i++;
            }
            // Check if this is a VIEW — RENAME TABLE doesn't work on views
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $table_type = $wpdb->get_var( $wpdb->prepare(
                "SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                $table
            ) );

            if ( $table_type === 'VIEW' ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $ok = $wpdb->query( 'DROP VIEW `' . esc_sql( $table ) . '`' );
                if ( $ok === false ) {
                    $db_err = $wpdb->last_error ?: 'unknown MySQL error';
                    $errors[] = $table . ' (VIEW drop failed: ' . $db_err . ')';
                } else {
                    $archived[] = $table . ' (VIEW dropped)';
                }
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $ok = $wpdb->query( 'RENAME TABLE `' . esc_sql( $table ) . '` TO `' . esc_sql( $new_name ) . '`' );
                if ( $ok === false ) {
                    $db_err = $wpdb->last_error ?: 'unknown MySQL error';
                    $errors[] = $table . ' (' . $db_err . ')';
                } else {
                    $archived[] = $table;
                }
            }
        }

        if ( ! empty( $errors ) ) {
            wp_send_json_error( [
                'archived' => count( $archived ),
                'message'  => 'Archived ' . count( $archived ) . ', failed: ' . implode( ', ', $errors ),
            ] );
            return;
        }

        wp_send_json_success( [
            'archived' => count( $archived ),
            'message'  => 'Moved ' . count( $archived ) . ' table(s) to Recycle Bin.',
        ] );
    }

    public static function ajax_db_trash_scan(): void {
        check_ajax_referer( CloudScale_DevTools::OPTIMIZER_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $all    = $wpdb->get_col( 'SHOW TABLES' );
        $trash  = array_values( array_filter( $all, fn( $t ) => preg_match( '/^_trash_\d{8}_/', $t ) ) );

        if ( empty( $trash ) ) {
            wp_send_json_success( [ 'tables' => [] ] );
            return;
        }

        $placeholders = implode( ',', array_fill( 0, count( $trash ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT TABLE_NAME as name, TABLE_ROWS as row_count, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024) as size_kb,
                        DATE_FORMAT(CREATE_TIME, '%%Y-%%m-%%d') as created_date
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
                ...$trash
            )
        );
        $size_map    = [];
        $rows_map    = [];
        $created_map = [];
        foreach ( $rows as $r ) {
            $size_map[ $r->name ]    = (int) $r->size_kb;
            $rows_map[ $r->name ]    = (int) $r->row_count;
            $created_map[ $r->name ] = $r->created_date ?? '';
        }

        $result = [];
        foreach ( $trash as $t ) {
            // Derive original table name by stripping _trash_YYYYMMDD_ or _trash_YYYYMMDD_N_ prefix
            $original = preg_replace( '/^_trash_\d{8}_(?:\d+_)?/', '', $t );
            $suffix   = substr( $original, strlen( $wpdb->prefix ) );
            $result[] = [
                'trash_table'    => $t,
                'original_table' => $original,
                'size_kb'        => $size_map[ $t ] ?? 0,
                'rows'           => $rows_map[ $t ] ?? 0,
                'created_date'   => $created_map[ $t ] ?? '',
                'plugin'         => CSDT_Site_Audit::guess_plugin_from_suffix( $suffix ),
                'plugin_url'     => CSDT_Site_Audit::guess_plugin_url_from_suffix( $suffix ),
            ];
        }

        usort( $result, fn( $a, $b ) => strcmp( $a['original_table'], $b['original_table'] ) );

        wp_send_json_success( [ 'tables' => $result ] );
    }

    public static function ajax_db_restore_tables(): void {
        check_ajax_referer( CloudScale_DevTools::OPTIMIZER_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $tables = json_decode( wp_unslash( $_POST['tables'] ?? '' ), true );
        if ( ! is_array( $tables ) || empty( $tables ) ) {
            wp_send_json_error( 'No tables specified.' );
            return;
        }

        global $wpdb;
        $restored = [];
        $errors   = [];

        foreach ( $tables as $table ) {
            $table = sanitize_text_field( $table );
            if ( ! preg_match( '/^_trash_\d{8}_/', $table ) ) {
                $errors[] = $table . ' (not a trash table)';
                continue;
            }
            $original = preg_replace( '/^_trash_\d{8}_(?:\d+_)?/', '', $table );
            // If original name is already taken, skip
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $original ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $errors[] = $table . ' (original name ' . $original . ' already exists)';
                continue;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $ok = $wpdb->query( 'RENAME TABLE `' . esc_sql( $table ) . '` TO `' . esc_sql( $original ) . '`' );
            if ( $ok === false ) {
                $errors[] = $table;
            } else {
                $restored[] = $original;
            }
        }

        if ( ! empty( $errors ) ) {
            wp_send_json_error( [
                'restored' => count( $restored ),
                'message'  => 'Restored ' . count( $restored ) . ', failed: ' . implode( ', ', $errors ),
            ] );
            return;
        }

        wp_send_json_success( [
            'restored' => count( $restored ),
            'message'  => 'Restored ' . count( $restored ) . ' table(s) successfully.',
        ] );
    }

    public static function ajax_db_drop_tables(): void {
        check_ajax_referer( CloudScale_DevTools::OPTIMIZER_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $tables_json = wp_unslash( $_POST['tables'] ?? '' );
        $tables      = json_decode( $tables_json, true );
        if ( ! is_array( $tables ) || empty( $tables ) ) {
            wp_send_json_error( 'No tables specified.' );
            return;
        }

        global $wpdb;
        $dropped = [];
        $errors  = [];

        foreach ( $tables as $table ) {
            $table = sanitize_text_field( $table );
            // Only allow dropping tables in the recycle bin (_trash_ prefix)
            if ( ! preg_match( '/^_trash_\d{8}_/', $table ) ) {
                $errors[] = $table . ' (not in recycle bin — archive first)';
                continue;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result = $wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' );
            if ( $result === false ) {
                $errors[] = $table;
            } else {
                $dropped[] = $table;
            }
        }

        if ( ! empty( $errors ) ) {
            wp_send_json_error( [
                'dropped' => count( $dropped ),
                'message' => 'Dropped ' . count( $dropped ) . ', failed: ' . implode( ', ', $errors ),
            ] );
            return;
        }

        wp_send_json_success( [
            'dropped' => count( $dropped ),
            'message' => 'Permanently deleted ' . count( $dropped ) . ' table(s).',
        ] );
    }

    // ── Optimizer: Plugin Stack Scanner ──────────────────────────────

    public static function ajax_plugin_stack_scan(): void {
        check_ajax_referer( CloudScale_DevTools::OPTIMIZER_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $replacements = self::get_plugin_replacements();
        $active       = (array) get_option( 'active_plugins', [] );
        $all_plugins  = get_plugins();
        $matched      = [];
        $total_saving = 0;

        $active_set = array_flip( $active );
        foreach ( $all_plugins as $plugin_file => $info ) {
            if ( ! isset( $replacements[ $plugin_file ] ) ) {
                continue;
            }
            $r         = $replacements[ $plugin_file ];
            $is_active = isset( $active_set[ $plugin_file ] );
            $matched[] = [
                'file'    => $plugin_file,
                'name'    => ! empty( $info['Name'] ) ? $info['Name'] : $r['name'],
                'version' => $info['Version'] ?? '',
                'feature' => $r['feature'],
                'tab'     => $r['tab'],
                'cost'    => $r['cost'],
                'active'  => $is_active,
            ];
            if ( $is_active ) {
                $total_saving += $r['cost'];
            }
        }

        $match_count = count( $matched );
        set_transient( 'csdt_plugin_stack_last', [
            'match_count'  => $match_count,
            'total_saving' => $total_saving,
            'at'           => time(),
        ], 7 * DAY_IN_SECONDS );

        wp_send_json_success( [
            'matched'      => $matched,
            'total_saving' => $total_saving,
            'active_count' => count( $active ),
        ] );
    }

    private static function get_plugin_replacements(): array {
        return [
            // Security scanners / firewalls
            'wordfence/wordfence.php'                                          => [ 'name' => 'Wordfence Security',            'feature' => 'AI Cyber Audit + Quick Fixes + Brute Force Protection',  'tab' => 'security',   'cost' => 119 ],
            'better-wp-security/better-wp-security.php'                       => [ 'name' => 'iThemes Security',              'feature' => 'Hide Login URL + Brute Force + Security Audit',          'tab' => 'login',      'cost' => 99  ],
            'all-in-one-wp-security-and-firewall/wp-security.php'             => [ 'name' => 'All-In-One Security & Firewall', 'feature' => 'Hide Login URL + Brute Force + Hardening Quick Fixes',  'tab' => 'login',      'cost' => 0   ],
            'sucuri-scanner/sucuri.php'                                        => [ 'name' => 'Sucuri Security',                'feature' => 'AI Cyber Audit + Server Logs',                           'tab' => 'security',   'cost' => 199 ],
            'wp-cerber/wp-cerber.php'                                          => [ 'name' => 'WP Cerber Security',             'feature' => 'Brute Force Protection + Hide Login URL',                'tab' => 'login',      'cost' => 99  ],
            'shield-security/icwp-wpsf.php'                                   => [ 'name' => 'Shield Security',                'feature' => 'AI Cyber Audit + Brute Force + Login Security',          'tab' => 'security',   'cost' => 69  ],
            // Two-factor authentication
            'wp-2fa/wp-2fa.php'                                                => [ 'name' => 'WP 2FA',                         'feature' => 'Two-Factor Auth (email OTP, TOTP, Passkeys)',             'tab' => 'login',      'cost' => 79  ],
            'miniorange-2-factor-authentication/miniorange_2_factor_authentication.php' => [ 'name' => 'miniOrange 2FA',       'feature' => 'Two-Factor Auth (email OTP, TOTP)',                       'tab' => 'login',      'cost' => 99  ],
            'google-authenticator/google-authenticator.php'                    => [ 'name' => 'Google Authenticator',           'feature' => 'Two-Factor Auth (TOTP authenticator app)',                'tab' => 'login',      'cost' => 0   ],
            'duo-wordpress/duo.php'                                            => [ 'name' => 'Duo Two-Factor Auth',             'feature' => 'Two-Factor Authentication',                               'tab' => 'login',      'cost' => 0   ],
            'two-factor/two-factor.php'                                        => [ 'name' => 'Two Factor',                     'feature' => 'Two-Factor Auth (email OTP, TOTP, Passkeys)',             'tab' => 'login',      'cost' => 0   ],
            // Login protection / hide login
            'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php' => [ 'name' => 'Limit Login Attempts Reloaded',  'feature' => 'Brute Force Protection (per-account lockout)',            'tab' => 'login',      'cost' => 0   ],
            'loginpress/loginpress.php'                                        => [ 'name' => 'LoginPress',                     'feature' => 'Hide Login URL',                                          'tab' => 'login',      'cost' => 49  ],
            'wps-hide-login/wps-hide-login.php'                                => [ 'name' => 'WPS Hide Login',                 'feature' => 'Hide Login URL',                                          'tab' => 'login',      'cost' => 0   ],
            'rename-wp-login/rename-wp-login.php'                              => [ 'name' => 'Rename wp-login.php',            'feature' => 'Hide Login URL',                                          'tab' => 'login',      'cost' => 0   ],
            'sf-move-login/sf-move-login.php'                                  => [ 'name' => 'Move Login',                     'feature' => 'Hide Login URL',                                          'tab' => 'login',      'cost' => 0   ],
            // SMTP
            'wp-mail-smtp/wp_mail_smtp.php'                                    => [ 'name' => 'WP Mail SMTP',                   'feature' => 'SMTP Mail (authenticated delivery + email log)',          'tab' => 'mail',       'cost' => 49  ],
            'post-smtp/postman-smtp.php'                                       => [ 'name' => 'Post SMTP',                      'feature' => 'SMTP Mail (authenticated delivery)',                       'tab' => 'mail',       'cost' => 0   ],
            'easy-wp-smtp/easy-wp-smtp.php'                                    => [ 'name' => 'Easy WP SMTP',                   'feature' => 'SMTP Mail',                                               'tab' => 'mail',       'cost' => 0   ],
            'fluent-smtp/fluent-smtp.php'                                      => [ 'name' => 'FluentSMTP',                     'feature' => 'SMTP Mail',                                               'tab' => 'mail',       'cost' => 0   ],
            'sendgrid-email-delivery-simplified/wpsendgrid.php'                => [ 'name' => 'SendGrid',                       'feature' => 'SMTP Mail (use any SMTP provider)',                       'tab' => 'mail',       'cost' => 0   ],
            // Code syntax highlighting
            'enlighter/enlighter.php'                                          => [ 'name' => 'Enlighter Syntax Highlighter',   'feature' => 'Code Block (190+ languages, 14 themes, zero CDN)',       'tab' => 'migrate',    'cost' => 29  ],
            'syntaxhighlighter/syntaxhighlighter.php'                          => [ 'name' => 'SyntaxHighlighter Evolved',      'feature' => 'Code Block (190+ languages)',                             'tab' => 'migrate',    'cost' => 0   ],
            'prismatic/prismatic.php'                                          => [ 'name' => 'Prismatic',                      'feature' => 'Code Block (190+ languages, 14 themes)',                  'tab' => 'migrate',    'cost' => 29  ],
            'code-syntax-block/index.php'                                      => [ 'name' => 'Code Syntax Block',              'feature' => 'Code Block (Gutenberg block)',                            'tab' => 'migrate',    'cost' => 0   ],
            'urvanov-syntax-highlighter/urvanov-syntax-highlighter.php'        => [ 'name' => 'Urvanov Syntax Highlighter',     'feature' => 'Code Block',                                              'tab' => 'migrate',    'cost' => 0   ],
            // SQL / database tools
            'wp-phpmyadmin-extension/wp-phpmyadmin-extension.php'              => [ 'name' => 'WP phpMyAdmin',                  'feature' => 'SQL Query Tool (read-only, safe, wp-admin only)',         'tab' => 'sql',        'cost' => 0   ],
            'adminer-for-wordpress/adminer-for-wordpress.php'                  => [ 'name' => 'Adminer for WordPress',          'feature' => 'SQL Query Tool',                                          'tab' => 'sql',        'cost' => 0   ],
            // Log viewers / debug tools
            'wp-log-viewer/wp-log-viewer.php'                                  => [ 'name' => 'WP Log Viewer',                  'feature' => 'Server Logs (live search, tail mode, multiple sources)',  'tab' => 'logs',       'cost' => 0   ],
            'query-monitor/query-monitor.php'                                  => [ 'name' => 'Query Monitor',                  'feature' => 'Performance Monitor + Server Logs',                       'tab' => 'logs',       'cost' => 0   ],
            'debug-bar/debug-bar.php'                                          => [ 'name' => 'Debug Bar',                      'feature' => 'Performance Monitor',                                     'tab' => 'logs',       'cost' => 0   ],
            // Social / OG images
            'wordpress-seo/wp-seo.php'                                         => [ 'name' => 'Yoast SEO',                      'feature' => 'Thumbnails (og:image generation + social preview scan)',  'tab' => 'thumbnails', 'cost' => 99  ],
            'seo-by-rank-math/rank-math.php'                                   => [ 'name' => 'Rank Math SEO',                  'feature' => 'Thumbnails (og:image generation)',                        'tab' => 'thumbnails', 'cost' => 0   ],
        ];
    }

    // ── Optimizer: Update Risk Scanner ───────────────────────────────

    public static function ajax_update_risk_scan(): void {
        check_ajax_referer( CloudScale_DevTools::OPTIMIZER_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $update_data = get_site_transient( 'update_plugins' );
        if ( ! $update_data || empty( $update_data->response ) ) {
            // Force a fresh check
            wp_update_plugins();
            $update_data = get_site_transient( 'update_plugins' );
        }

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        $plugins     = [];

        if ( ! empty( $update_data->response ) ) {
            foreach ( $update_data->response as $plugin_file => $data ) {
                $info      = $all_plugins[ $plugin_file ] ?? [];
                $plugins[] = [
                    'file'            => $plugin_file,
                    'slug'            => $data->slug ?? dirname( $plugin_file ),
                    'name'            => ! empty( $info['Name'] ) ? $info['Name'] : ( $data->slug ?? $plugin_file ),
                    'current_version' => $info['Version'] ?? '?',
                    'new_version'     => $data->new_version ?? '?',
                ];
            }
        }

        wp_send_json_success( [ 'plugins' => $plugins ] );
    }

    public static function ajax_update_risk_assess(): void {
        check_ajax_referer( CloudScale_DevTools::OPTIMIZER_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $slug            = sanitize_text_field( wp_unslash( $_POST['slug']            ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $current_version = sanitize_text_field( wp_unslash( $_POST['current_version'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $new_version     = sanitize_text_field( wp_unslash( $_POST['new_version']     ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $plugin_name     = sanitize_text_field( wp_unslash( $_POST['name']            ?? $slug ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ( ! $slug ) {
            wp_send_json_error( 'Missing slug' );
        }

        // Fetch changelog from WordPress.org
        $changelog = '';
        $api_url   = add_query_arg( [
            'action'                     => 'plugin_information',
            'request[slug]'              => $slug,
            'request[fields][sections]'  => '1',
        ], 'https://api.wordpress.org/plugins/info/1.2/' );

        $response = wp_remote_get( $api_url, [ 'timeout' => 10 ] );
        if ( ! is_wp_error( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! empty( $body['sections']['changelog'] ) ) {
                $changelog = wp_strip_all_tags( $body['sections']['changelog'] );
                $changelog = mb_substr( $changelog, 0, 3000 );
            }
        }

        $has_key = CSDT_AI_Dispatcher::has_key();

        if ( $has_key && $changelog ) {
            $system   = 'You are a WordPress plugin update risk assessor. Given a plugin name, version numbers, and changelog, classify the update as exactly one of: "patch" (security fix or bug fix — apply immediately), "minor" (new features, low breaking risk), or "breaking" (major version, deprecated APIs, DB migrations, or significant structural changes — review before applying). Respond with ONLY valid JSON, no other text: {"risk":"patch","reason":"One sentence."}';
            $user_msg = "Plugin: {$plugin_name}\nCurrent version: {$current_version}\nNew version: {$new_version}\n\nChangelog:\n{$changelog}";
            try {
                $raw  = CSDT_AI_Dispatcher::call( $system, $user_msg, '_auto', 150 );
                $raw  = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw ) );
                $raw  = preg_replace( '/\s*```$/', '', $raw );
                $data = json_decode( $raw, true );
                if ( is_array( $data ) && ! empty( $data['risk'] ) ) {
                    wp_send_json_success( [
                        'risk'   => in_array( $data['risk'], [ 'patch', 'minor', 'breaking' ], true ) ? $data['risk'] : 'minor',
                        'reason' => sanitize_text_field( $data['reason'] ?? '' ),
                        'source' => 'ai',
                    ] );
                    return;
                }
            } catch ( \Throwable $e ) {
                // Fall through to semver fallback
            }
        }

        // Semver fallback
        $risk = self::update_risk_from_semver( $current_version, $new_version );
        wp_send_json_success( [
            'risk'   => $risk,
            'reason' => $changelog ? 'Based on version number change (AI unavailable).' : 'No changelog found — assessed from version number only.',
            'source' => 'semver',
        ] );
    }

    private static function update_risk_from_semver( string $current, string $new ): string {
        preg_match( '/^(\d+)\.(\d+)/', $current, $cm );
        preg_match( '/^(\d+)\.(\d+)/', $new,     $nm );
        $c_maj = (int) ( $cm[1] ?? 0 );
        $n_maj = (int) ( $nm[1] ?? 0 );
        $c_min = (int) ( $cm[2] ?? 0 );
        $n_min = (int) ( $nm[2] ?? 0 );
        if ( $n_maj > $c_maj ) return 'breaking';
        if ( $n_min > $c_min ) return 'minor';
        return 'patch';
    }

    // ── Optimizer: Database Intelligence Engine ──────────────────────

    public static function ajax_db_intelligence_scan(): void {
        check_ajax_referer( CloudScale_DevTools::OPTIMIZER_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;

        // Autoloaded options
        $al = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(option_value)),0) AS total_bytes
             FROM {$wpdb->options}
             WHERE autoload IN ('yes','on','1','true')",
            ARRAY_A
        );
        $autoload_total_kb = round( (float) $al['total_bytes'] / 1024, 1 );
        $autoload_count    = (int) $al['cnt'];

        $top_autoloaded = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT option_name, ROUND(LENGTH(option_value)/1024,1) AS size_kb
             FROM {$wpdb->options}
             WHERE autoload IN ('yes','on','1','true')
             ORDER BY LENGTH(option_value) DESC
             LIMIT 10",
            ARRAY_A
        );

        // Expired transients
        $tr = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(option_name)+LENGTH(option_value)),0) AS total_bytes
             FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_timeout_%'
               AND CAST(option_value AS UNSIGNED) < UNIX_TIMESTAMP()",
            ARRAY_A
        );
        $expired_transients    = (int) $tr['cnt'];
        $expired_transients_kb = round( (float) $tr['total_bytes'] / 1024, 1 );

        // Post revisions
        $rv = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(LENGTH(post_content)+LENGTH(post_title)+LENGTH(post_excerpt)),0) AS total_bytes
             FROM {$wpdb->posts}
             WHERE post_type = 'revision'",
            ARRAY_A
        );
        $revisions_count = (int) $rv['cnt'];
        $revisions_kb    = round( (float) $rv['total_bytes'] / 1024, 1 );

        // Orphaned postmeta
        $orphan_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = pm.post_id)"
        );

        // Table sizes
        $tables = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT TABLE_NAME AS tbl,
                        TABLE_ROWS AS `rows`,
                        ROUND(DATA_LENGTH/1024,0) AS data_kb,
                        ROUND(INDEX_LENGTH/1024,0) AS index_kb,
                        ROUND(COALESCE(DATA_FREE,0)/1024,0) AS overhead_kb,
                        ENGINE AS engine
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s
                 ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC",
                DB_NAME
            ),
            ARRAY_A
        );
        $total_db_kb      = 0;
        $total_overhead_kb = 0;
        foreach ( (array) $tables as $t ) {
            $total_db_kb       += (int) $t['data_kb'] + (int) $t['index_kb'];
            $total_overhead_kb += (int) $t['overhead_kb'];
        }

        // Rule-based findings
        $findings = [];

        if ( $autoload_total_kb > 300 ) {
            $top_names = implode( ', ', array_map(
                function ( $r ) { return $r['option_name'] . ' (' . $r['size_kb'] . ' KB)'; },
                array_slice( (array) $top_autoloaded, 0, 5 )
            ) );
            $findings[] = [
                'title'      => 'Large Autoload Cache (' . $autoload_total_kb . ' KB)',
                'detail'     => $autoload_count . ' options autoload on every page load, consuming ' . $autoload_total_kb . ' KB. Top offenders: ' . $top_names . '.',
                'fix'        => 'Review the top autoloaded options. Deactivate unused plugins that add large rows. Consider a plugin like Auctollo Autoload Manager to flip individual options to not autoload.',
                'severity'   => $autoload_total_kb > 1000 ? 'high' : 'medium',
                'fix_action' => null,
            ];
        }

        if ( $expired_transients > 20 ) {
            $findings[] = [
                'title'      => 'Expired Transients (' . $expired_transients . ')',
                'detail'     => $expired_transients . ' expired transients are still in the database, consuming ' . $expired_transients_kb . ' KB. They bloat the wp_options table and inflate autoload queries.',
                'fix'        => 'Click Fix It to delete all expired transients immediately. They regenerate on demand as needed.',
                'severity'   => $expired_transients > 200 ? 'medium' : 'low',
                'fix_action' => 'db_delete_expired_transients',
            ];
        }

        if ( $revisions_count > 200 ) {
            $findings[] = [
                'title'      => 'Post Revisions (' . number_format( $revisions_count ) . ' rows, ' . $revisions_kb . ' KB)',
                'detail'     => number_format( $revisions_count ) . ' post revisions stored, using ' . $revisions_kb . ' KB. WordPress stores unlimited revisions by default, inflating the wp_posts table.',
                'fix'        => "Click Fix It to delete all revisions. Going forward, add define('WP_POST_REVISIONS', 5) to wp-config.php to cap future revisions per post.",
                'severity'   => $revisions_count > 1000 ? 'medium' : 'low',
                'fix_action' => 'db_delete_revisions',
            ];
        }

        if ( $orphan_count > 50 ) {
            $findings[] = [
                'title'      => 'Orphaned Post Meta (' . number_format( $orphan_count ) . ' rows)',
                'detail'     => number_format( $orphan_count ) . ' rows in wp_postmeta reference posts that no longer exist. Left behind by deleted posts or poorly-cleaned-up plugins.',
                'fix'        => 'Click Fix It to delete all orphaned postmeta rows.',
                'severity'   => 'low',
                'fix_action' => 'db_delete_orphaned_postmeta',
            ];
        }

        if ( $total_overhead_kb > 1024 ) {
            $overhead_mb = round( $total_overhead_kb / 1024, 1 );
            $findings[]  = [
                'title'      => 'Table Fragmentation (' . $overhead_mb . ' MB reclaimable)',
                'detail'     => $overhead_mb . ' MB of overhead detected from deleted rows. OPTIMIZE TABLE reclaims this space and can improve query performance.',
                'fix'        => 'Click Fix It to run OPTIMIZE TABLE across all tables. May take a few seconds on large databases.',
                'severity'   => $total_overhead_kb > 10240 ? 'medium' : 'low',
                'fix_action' => 'db_optimize_tables',
            ];
        }

        if ( empty( $findings ) ) {
            $findings[] = [
                'title'      => 'Database looks healthy',
                'detail'     => 'No significant bloat detected. Autoload size, transients, revisions, and postmeta are all within normal thresholds.',
                'fix'        => 'No action needed.',
                'severity'   => 'info',
                'fix_action' => null,
            ];
        }

        wp_send_json_success( [
            'stats'    => [
                'autoload_total_kb'     => $autoload_total_kb,
                'autoload_count'        => $autoload_count,
                'top_autoloaded'        => $top_autoloaded,
                'expired_transients'    => $expired_transients,
                'expired_transients_kb' => $expired_transients_kb,
                'revisions_count'       => $revisions_count,
                'revisions_kb'          => $revisions_kb,
                'orphaned_postmeta'     => $orphan_count,
                'total_db_kb'           => $total_db_kb,
                'total_overhead_kb'     => $total_overhead_kb,
                'tables'                => $tables,
            ],
            'findings' => $findings,
        ] );
    }

    public static function ajax_db_intelligence_fix(): void {
        check_ajax_referer( CloudScale_DevTools::OPTIMIZER_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $fix_id = isset( $_POST['fix_id'] ) ? sanitize_key( wp_unslash( $_POST['fix_id'] ) ) : '';
        global $wpdb;

        switch ( $fix_id ) {
            case 'db_delete_expired_transients':
                $deleted = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    "DELETE a, b
                     FROM {$wpdb->options} a
                     LEFT JOIN {$wpdb->options} b
                         ON b.option_name = REPLACE(a.option_name,'_transient_timeout_','_transient_')
                     WHERE a.option_name LIKE '_transient_timeout_%'
                       AND CAST(a.option_value AS UNSIGNED) < UNIX_TIMESTAMP()"
                );
                wp_send_json_success( [ 'message' => 'Deleted ' . intdiv( $deleted, 2 ) . ' expired transients.' ] );
                return;

            case 'db_delete_revisions':
                $count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"
                );
                $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    "DELETE pm FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE p.post_type = 'revision'"
                );
                $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'"
                );
                wp_send_json_success( [ 'message' => 'Deleted ' . number_format( $count ) . ' revisions.' ] );
                return;

            case 'db_delete_orphaned_postmeta':
                $count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                     WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = pm.post_id)"
                );
                $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    "DELETE pm FROM {$wpdb->postmeta} pm
                     WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = pm.post_id)"
                );
                wp_send_json_success( [ 'message' => 'Deleted ' . number_format( $count ) . ' orphaned meta rows.' ] );
                return;

            case 'db_optimize_tables':
                $db_tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                foreach ( (array) $db_tables as $tbl ) {
                    $wpdb->query( 'OPTIMIZE TABLE `' . esc_sql( $tbl ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
                }
                wp_send_json_success( [ 'message' => 'Optimized ' . count( (array) $db_tables ) . ' tables.' ] );
                return;

            default:
                wp_send_json_error( 'Unknown fix ID' );
        }
    }

    // ── Optimizer: AI Debugging Assistant ────────────────────────────

    public static function ajax_ai_debug_log(): void {
        check_ajax_referer( CloudScale_DevTools::OPTIMIZER_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $raw_input = isset( $_POST['input'] ) ? wp_unslash( $_POST['input'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $input     = sanitize_textarea_field( $raw_input );
        if ( empty( trim( $input ) ) ) {
            wp_send_json_error( [ 'message' => 'No input provided.' ] );
        }

        $site_ctx = sprintf( 'WordPress %s, PHP %s', get_bloginfo( 'version' ), PHP_VERSION );
        $system   = 'You are a WordPress debugging expert. The user provides an error message, log excerpt, or problem description. Identify the root cause and give specific actionable steps to fix it. Be direct and practical. Structure your response with exactly three sections: **Root Cause** (1-2 sentences), **Why It Happens** (2-3 sentences explaining the underlying mechanism), **How to Fix It** (numbered steps). Use backtick formatting for file paths, function names, and code snippets. Do not pad with generic advice.';
        $user_msg = "Site context: {$site_ctx}\n\nError / Problem:\n{$input}";

        $result = CSDT_AI_Dispatcher::call( $system, $user_msg, '_auto', 1024 );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'analysis' => $result ] );
    }

}
