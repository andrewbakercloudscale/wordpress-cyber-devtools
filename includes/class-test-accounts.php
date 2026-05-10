<?php
/**
 * Test account manager — temporary subscriber accounts for CI/Playwright pipelines.
 *
 * @package CloudScale_DevTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CSDT_Test_Accounts {

    public static function get_active_test_accounts(): array {
        $users = get_users( [
            'meta_key'   => 'csdt_test_account',
            'meta_value' => '1',
            'fields'     => [ 'ID', 'user_login' ],
        ] );

        $accounts = [];
        foreach ( $users as $u ) {
            $expires_at  = (int) get_user_meta( $u->ID, 'csdt_test_expires_at', true );
            $max_logins  = (int) get_user_meta( $u->ID, 'csdt_test_max_logins', true );
            $login_count = (int) get_user_meta( $u->ID, 'csdt_test_login_count', true );
            $accounts[] = [
                'user_id'     => $u->ID,
                'username'    => $u->user_login,
                'expires_at'  => $expires_at,
                'expires_in'  => max( 0, $expires_at - time() ),
                'max_logins'  => $max_logins,
                'login_count' => $login_count,
            ];
        }

        return $accounts;
    }

    private static function create_test_account( int $ttl = 1800 ): array {
        $username  = 'test-' . wp_generate_password( 8, false, false );
        $password  = wp_generate_password( 20 );
        $email     = $username . '@test.local';
        $user_id   = wp_create_user( $username, $password, $email );

        if ( is_wp_error( $user_id ) ) {
            return [ 'error' => $user_id->get_error_message() ];
        }

        $user = new WP_User( $user_id );
        $user->set_role( 'subscriber' );

        $expires_at  = time() + $ttl;
        $max_logins  = max( 0, (int) get_option( 'csdt_test_account_max_logins', '1' ) );

        update_user_meta( $user_id, 'csdt_test_account',     '1' );
        update_user_meta( $user_id, 'csdt_test_expires_at',  $expires_at );
        update_user_meta( $user_id, 'csdt_test_max_logins',  $max_logins );
        update_user_meta( $user_id, 'csdt_test_login_count', 0 );

        [ $app_password, $item ] = WP_Application_Passwords::create_new_application_password(
            $user_id,
            [ 'name' => 'playwright-ci' ]
        );

        if ( is_wp_error( $app_password ) ) {
            wp_delete_user( $user_id );
            return [ 'error' => $app_password->get_error_message() ];
        }

        $formatted_pw = implode( ' ', str_split( $app_password, 4 ) );

        return [
            'user_id'    => $user_id,
            'username'   => $username,
            'app_password' => $formatted_pw,
            'rest_url'   => rest_url( 'wp/v2/users/me' ),
            'expires_at' => $expires_at,
            'accounts'   => self::get_active_test_accounts(),
        ];
    }

    public static function ajax_create_test_account(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }
        check_ajax_referer( 'csdt_devtools_login_nonce', 'nonce' );

        $ttl    = (int) get_option( 'csdt_test_account_ttl', '1800' );
        $result = self::create_test_account( $ttl );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }

        wp_send_json_success( $result );
    }

    public static function ajax_revoke_test_account(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }
        check_ajax_referer( 'csdt_devtools_login_nonce', 'nonce' );

        $user_id = (int) ( $_POST['user_id'] ?? 0 );
        if ( ! $user_id ) {
            wp_send_json_error( 'Missing user_id' );
        }

        if ( get_user_meta( $user_id, 'csdt_test_account', true ) !== '1' ) {
            wp_send_json_error( 'Not a test account' );
        }

        wp_delete_user( $user_id );

        wp_send_json_success( [ 'accounts' => self::get_active_test_accounts() ] );
    }

    public static function ajax_save_test_account_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }
        check_ajax_referer( 'csdt_devtools_login_nonce', 'nonce' );

        $enabled     = ( $_POST['enabled']     ?? '0' ) === '1' ? '1' : '0';
        $ttl         = in_array( (string) ( $_POST['ttl'] ?? '1800' ), [ '300', '600', '1800', '3600', '7200', '86400' ], true )
                       ? (string) $_POST['ttl'] : '1800';
        $single_use  = ( $_POST['single_use'] ?? '0' ) === '1' ? '1' : '0';
        $max_logins  = $single_use === '1' ? 1 : max( 0, (int) ( $_POST['max_logins'] ?? 0 ) );

        update_option( 'csdt_test_accounts_enabled',    $enabled );
        update_option( 'csdt_test_account_ttl',         $ttl );
        update_option( 'csdt_test_account_single_use',  $single_use );
        update_option( 'csdt_test_account_max_logins',  (string) $max_logins );

        if ( $enabled === '1' ) {
            if ( ! wp_next_scheduled( 'csdt_cleanup_test_accounts' ) ) {
                wp_schedule_event( time() + 300, 'csdt_every_5min', 'csdt_cleanup_test_accounts' );
            }
        } else {
            wp_clear_scheduled_hook( 'csdt_cleanup_test_accounts' );
        }

        wp_send_json_success();
    }



    public static function cleanup_expired_test_accounts(): void {
        $now = time();

        // 1. Meta-tracked test accounts with an expiry timestamp.
        $users = get_users( [
            'meta_key'   => 'csdt_test_account',
            'meta_value' => '1',
            'fields'     => [ 'ID' ],
        ] );
        foreach ( $users as $u ) {
            $expires_at = (int) get_user_meta( $u->ID, 'csdt_test_expires_at', true );
            if ( $expires_at && $expires_at < $now ) {
                wp_delete_user( $u->ID );
            }
        }

        // 2. Orphaned test accounts not tracked by meta — sweep by known patterns.
        //    @test.local email domain is never a real account; cs_devtools_test* and
        //    temp-* usernames with no posts are plugin/debug artifacts safe to remove.
        $orphans = get_users( [
            'fields'     => [ 'ID', 'user_login', 'user_email', 'user_registered' ],
            'number'     => 200,
        ] );
        foreach ( $orphans as $u ) {
            $is_test_email    = str_ends_with( strtolower( $u->user_email ), '@test.local' );
            $is_test_login    = strncmp( $u->user_login, 'cs_devtools_test', 16 ) === 0;
            $is_temp_login    = strncmp( $u->user_login, 'temp-', 5 ) === 0
                             && strtotime( $u->user_registered ) < $now - DAY_IN_SECONDS
                             && (int) count_user_posts( $u->ID ) === 0;
            if ( $is_test_email || $is_test_login || $is_temp_login ) {
                wp_delete_user( $u->ID );
            }
        }
    }

    /* ── Playwright Admin Roles ──────────────────────────────────────────────── */

    public static function get_or_create_secret(): string {
        $secret = get_option( 'csdt_test_session_secret', '' );
        if ( ! $secret ) {
            $secret = wp_generate_password( 32, false, false );
            update_option( 'csdt_test_session_secret', $secret, false );
        }
        return $secret;
    }

    // The path token makes the endpoint URL itself non-guessable — an attacker
    // must enumerate a 32-char alphanumeric string before they can even attempt
    // the secret. Both layers are required to obtain a session.
    public static function get_or_create_path_token(): string {
        $token = get_option( 'csdt_test_session_path_token', '' );
        if ( ! $token ) {
            $token = wp_generate_password( 32, false, false );
            update_option( 'csdt_test_session_path_token', $token, false );
        }
        return $token;
    }

    public static function register_rest_routes(): void {
        $path_token = self::get_or_create_path_token();
        register_rest_route( 'csdt/v1', '/test-session-' . $path_token, [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_test_session' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( 'csdt/v1', '/test-logout-' . $path_token, [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_test_logout' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public static function rest_test_session( WP_REST_Request $request ): WP_REST_Response {
        // Global lock check (set after 5 bad attempts)
        if ( get_transient( 'csdt_ts_locked' ) ) {
            return new WP_REST_Response( [ 'error' => 'Temporarily locked. Try again in 10 minutes.' ], 429 );
        }

        $secret = (string) ( $request->get_param( 'secret' ) ?? '' );
        $role   = sanitize_key( (string) ( $request->get_param( 'role' )   ?? '' ) );
        $ttl    = min( 3600, max( 60, (int) ( $request->get_param( 'ttl' ) ?? 1200 ) ) );

        $stored = get_option( 'csdt_test_session_secret', '' );

        if ( ! $stored || ! hash_equals( $stored, $secret ) ) {
            $fails = (int) get_transient( 'csdt_tsf' ) + 1;
            set_transient( 'csdt_tsf', $fails, 10 * MINUTE_IN_SECONDS );
            $ip = self::get_client_ip();
            $cc = $ip ? CSDT_Geo::get_country( $ip ) : '';
            // Record every failed API attempt — include country so map works without re-resolving.
            CSDT_Login::record_security_event(
                'api_attack',
                "Test Session API: bad secret (attempt {$fails}/5)",
                "IP: {$ip}" . ( $cc ? " · {$cc}" : '' )
            );
            if ( $fails >= 5 ) {
                set_transient( 'csdt_ts_locked', true, 10 * MINUTE_IN_SECONDS );
                delete_transient( 'csdt_tsf' );
                CSDT_Login::record_security_event( 'api_attack', 'Test Session API LOCKED — 5 bad attempts', "IP: {$ip}" . ( $cc ? " · {$cc}" : '' ) );
                self::send_security_ntfy( 'Test Session API: 5 failed auth attempts. API locked for 10 min.' );
            }
            return new WP_REST_Response( [ 'error' => 'Invalid secret' ], 401 );
        }

        delete_transient( 'csdt_tsf' );

        if ( ! $role ) {
            return new WP_REST_Response( [ 'error' => 'role is required' ], 400 );
        }

        $roles_data = get_option( 'csdt_playwright_roles', [] );
        if ( ! isset( $roles_data[ $role ] ) ) {
            return new WP_REST_Response( [ 'error' => 'Role not found — create it in the Test Account Manager first.' ], 404 );
        }

        $user_id  = (int) $roles_data[ $role ]['user_id'];
        $userdata = get_userdata( $user_id );
        if ( ! $userdata ) {
            return new WP_REST_Response( [ 'error' => 'Test user missing — re-create the role.' ], 500 );
        }

        // Ensure the user has the expected WP role (heals users created by older code with custom roles).
        $expected_role = $roles_data[ $role ]['wp_role'] ?? 'administrator';
        $user_obj      = new WP_User( $user_id );
        if ( ! in_array( $expected_role, (array) $user_obj->roles, true ) ) {
            $user_obj->set_role( $expected_role );
        }

        $expiration = time() + $ttl;
        $token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
        update_user_meta( $user_id, 'csdt_playwright_last_login', time() );

        $resp = [
            'username'                => $userdata->user_login,
            'session_token'           => $token,
            'secure_auth_cookie'      => wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token ),
            'logged_in_cookie'        => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ),
            'secure_auth_cookie_name' => SECURE_AUTH_COOKIE,
            'logged_in_cookie_name'   => LOGGED_IN_COOKIE,
            'cookie_domain'           => wp_parse_url( home_url(), PHP_URL_HOST ),
            'expires_at'              => $expiration,
        ];

        // Optional: create a one-time Application Password for REST API calls (e.g. help-docs runner).
        if ( $request->get_param( 'create_app_password' ) === '1' ) {
            [ $raw_pw, $item ] = WP_Application_Passwords::create_new_application_password(
                $user_id,
                [ 'name' => 'helpdocs-' . substr( $token, 0, 8 ) ]
            );
            if ( ! is_wp_error( $raw_pw ) ) {
                $resp['app_password']      = $raw_pw;
                $resp['app_password_uuid'] = $item['uuid'];
            }
        }

        return new WP_REST_Response( $resp );
    }

    public static function rest_test_logout( WP_REST_Request $request ): WP_REST_Response {
        $secret = (string) ( $request->get_param( 'secret' ) ?? '' );
        $role   = sanitize_key( (string) ( $request->get_param( 'role' )          ?? '' ) );
        $token  = (string) ( $request->get_param( 'session_token' ) ?? '' );

        $stored = get_option( 'csdt_test_session_secret', '' );
        if ( ! $stored || ! hash_equals( $stored, $secret ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid secret' ], 401 );
        }
        if ( ! $role ) {
            return new WP_REST_Response( [ 'error' => 'role is required' ], 400 );
        }

        $roles_data = get_option( 'csdt_playwright_roles', [] );
        if ( ! isset( $roles_data[ $role ] ) ) {
            return new WP_REST_Response( [ 'error' => 'Role not found' ], 404 );
        }

        $user_id  = (int) $roles_data[ $role ]['user_id'];
        $sessions = WP_Session_Tokens::get_instance( $user_id );
        if ( $token ) {
            $sessions->destroy( $token );
        } else {
            $sessions->destroy_all();
        }

        // Clean up any associated application password.
        $app_uuid = sanitize_text_field( (string) ( $request->get_param( 'app_password_uuid' ) ?? '' ) );
        if ( $app_uuid ) {
            WP_Application_Passwords::delete_application_password( $user_id, $app_uuid );
        }

        return new WP_REST_Response( [ 'ok' => true ] );
    }

    public static function get_test_users_with_sessions(): array {
        $roles_data = get_option( 'csdt_playwright_roles', [] );
        $result     = [];
        $now        = time();
        foreach ( $roles_data as $name => $info ) {
            $user_id      = (int) ( $info['user_id'] ?? 0 );
            $userdata     = $user_id ? get_userdata( $user_id ) : false;
            $all_sessions = $user_id ? WP_Session_Tokens::get_instance( $user_id )->get_all() : [];
            $active       = array_filter( $all_sessions, fn( $s ) => $s['expiration'] > $now );
            $result[]     = [
                'name'          => $name,
                'user_id'       => $user_id,
                'username'      => $userdata ? $userdata->user_login : '(deleted)',
                'wp_role'       => $info['wp_role'] ?? '',
                'session_count' => count( $active ),
                'last_login'    => $user_id ? (int) get_user_meta( $user_id, 'csdt_playwright_last_login', true ) : 0,
            ];
        }
        return $result;
    }

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

    private static function send_security_ntfy( string $message ): void {
        $ntfy_url = get_option( 'csdt_scan_schedule_ntfy_url', '' );
        if ( ! $ntfy_url ) { return; }
        $site    = (string) get_option( 'siteurl', '' );
        $host    = $site ? wp_parse_url( $site, PHP_URL_HOST ) : '';
        $title   = '[CS Cyber] ' . ( $host ? "[{$host}] " : '' ) . 'Security Alert';
        $headers = [ 'Title' => $title, 'Priority' => 'urgent', 'Tags' => 'rotating_light' ];
        $tok     = get_option( 'csdt_scan_schedule_ntfy_token', '' );
        if ( $tok ) { $headers['Authorization'] = 'Bearer ' . $tok; }
        wp_remote_post( $ntfy_url, [ 'timeout' => 8, 'blocking' => false, 'headers' => $headers, 'body' => $message ] );
    }

    public static function ajax_create_playwright_role(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
        check_ajax_referer( 'csdt_devtools_login_nonce', 'nonce' );

        $name    = sanitize_key( wp_unslash( $_POST['name'] ?? $_POST['role_slug'] ?? '' ) );
        $wp_role = sanitize_key( wp_unslash( $_POST['wp_role'] ?? 'administrator' ) );

        if ( ! $name ) { wp_send_json_error( 'Name is required' ); }
        if ( strlen( $name ) < 3 || strlen( $name ) > 40 ) {
            wp_send_json_error( 'Name must be 3–40 characters' );
        }
        if ( ! get_role( $wp_role ) ) {
            wp_send_json_error( 'Invalid WordPress role' );
        }

        $roles_data = get_option( 'csdt_playwright_roles', [] );
        if ( isset( $roles_data[ $name ] ) ) {
            wp_send_json_error( "A test user named '{$name}' already exists" );
        }

        $username = 'csdt-playwright-' . $name;
        if ( username_exists( $username ) ) {
            wp_send_json_error( "Username '{$username}' is already taken" );
        }

        $user_id = wp_create_user( $username, wp_generate_password( 24 ), $username . '@test.local' );
        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( $user_id->get_error_message() );
        }

        ( new WP_User( $user_id ) )->set_role( $wp_role );

        $roles_data[ $name ] = [ 'user_id' => $user_id, 'wp_role' => $wp_role ];
        update_option( 'csdt_playwright_roles', $roles_data, false );
        self::get_or_create_secret();

        wp_send_json_success( [
            'name'     => $name,
            'username' => $username,
            'user_id'  => $user_id,
            'wp_role'  => $wp_role,
            'users'    => self::get_test_users_with_sessions(),
        ] );
    }

    public static function ajax_delete_playwright_role(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
        check_ajax_referer( 'csdt_devtools_login_nonce', 'nonce' );

        $name = sanitize_key( wp_unslash( $_POST['name'] ?? $_POST['role_slug'] ?? '' ) );
        if ( ! $name ) { wp_send_json_error( 'name required' ); }

        $roles_data = get_option( 'csdt_playwright_roles', [] );
        if ( isset( $roles_data[ $name ] ) ) {
            $user_id = (int) ( $roles_data[ $name ]['user_id'] ?? 0 );
            if ( $user_id ) {
                wp_delete_user( $user_id );
            }
            // Clean up any custom WP role created by the old system.
            if ( get_role( $name ) ) {
                remove_role( $name );
            }
            unset( $roles_data[ $name ] );
            update_option( 'csdt_playwright_roles', $roles_data, false );
        }

        wp_send_json_success( [ 'users' => self::get_test_users_with_sessions() ] );
    }

    public static function ajax_kill_test_sessions(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
        check_ajax_referer( 'csdt_devtools_login_nonce', 'nonce' );

        $name = sanitize_key( wp_unslash( $_POST['name'] ?? '' ) );
        if ( ! $name ) { wp_send_json_error( 'name required' ); }

        $roles_data = get_option( 'csdt_playwright_roles', [] );
        if ( ! isset( $roles_data[ $name ] ) ) { wp_send_json_error( 'Test user not found' ); }

        $user_id = (int) $roles_data[ $name ]['user_id'];
        WP_Session_Tokens::get_instance( $user_id )->destroy_all();

        wp_send_json_success( [ 'users' => self::get_test_users_with_sessions() ] );
    }

    public static function ajax_regen_test_secret(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
        check_ajax_referer( 'csdt_devtools_login_nonce', 'nonce' );

        $secret = wp_generate_password( 32, false, false );
        update_option( 'csdt_test_session_secret', $secret, false );
        wp_send_json_success( [ 'secret' => $secret ] );
    }

    public static function ajax_regen_path_token(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
        check_ajax_referer( 'csdt_devtools_login_nonce', 'nonce' );

        $token = wp_generate_password( 32, false, false );
        update_option( 'csdt_test_session_path_token', $token, false );
        $session_url = rest_url( 'csdt/v1/test-session-' . $token );
        $logout_url  = rest_url( 'csdt/v1/test-logout-' . $token );
        wp_send_json_success( [
            'path_token'  => $token,
            'session_url' => $session_url,
            'logout_url'  => $logout_url,
        ] );
    }

    public static function ajax_toggle_block_basic_auth(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); }
        check_ajax_referer( 'csdt_devtools_login_nonce', 'nonce' );

        $enabled = ( $_POST['enabled'] ?? '0' ) === '1' ? '1' : '0';
        update_option( 'csdt_block_basic_auth', $enabled );
        wp_send_json_success( [ 'enabled' => $enabled ] );
    }

    public static function filter_app_pw_for_user( $available, $user ): bool {
        if ( get_user_meta( $user->ID, 'csdt_test_account', true ) === '1' ) {
            return true;
        }
        return false;
    }

    public static function test_account_after_auth( $user, $app_password ): void {
        if ( get_user_meta( $user->ID, 'csdt_test_account', true ) !== '1' ) {
            return;
        }
        $max_logins = (int) get_user_meta( $user->ID, 'csdt_test_max_logins', true );
        if ( $max_logins <= 0 ) {
            return; // unlimited
        }
        $count = (int) get_user_meta( $user->ID, 'csdt_test_login_count', true ) + 1;
        if ( $count >= $max_logins ) {
            wp_delete_user( $user->ID );
        } else {
            update_user_meta( $user->ID, 'csdt_test_login_count', $count );
        }
    }

}
