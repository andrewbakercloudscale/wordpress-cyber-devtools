<?php
/**
 * Login security, hide-login URL, brute-force protection, and 2FA (email / TOTP).
 *
 * @package CloudScale_DevTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CSDT_Login {

    /**
     * Fired on `init` at priority 1. If the current request matches the
     * custom login slug, serve wp-login.php transparently from that URL.
     *
     * @since  1.9.4
     * @return void
     */
    public static function login_serve_custom_slug(): void {
        if ( get_option( 'csdt_devtools_login_hide_enabled', '0' ) !== '1' ) {
            return;
        }
        $slug = get_option( 'csdt_devtools_login_slug', '' );
        if ( empty( $slug ) ) {
            return;
        }

        $request = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
        $path    = wp_parse_url( $request, PHP_URL_PATH );
        if ( ! is_string( $path ) ) {
            return;
        }
        $path       = rtrim( $path, '/' );
        $home_path  = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
        $target     = $home_path . '/' . $slug;

        if ( $path !== $target ) {
            return;
        }

        // Prevent any cache layer from storing this response — ensures the
        // auth-cookie check below runs on every visit, not a cached copy.
        nocache_headers();
        // Tell LiteSpeed Server/Cache not to cache this response.
        header( 'X-LiteSpeed-Cache-Control: no-cache' );
        // Belt-and-suspenders: fire LiteSpeed Cache plugin's no-cache action.
        do_action( 'litespeed_control_set_nocache', 'login_slug' );

        // Already authenticated — send straight to the dashboard.
        // Exception: let logout, password-reset, and similar actions fall through
        // to wp-login.php so they are processed correctly.
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $passthrough = [ 'logout', 'lostpassword', 'rp', 'resetpass', 'postpass' ];
        if ( ! in_array( $action, $passthrough, true ) && is_user_logged_in() ) {
            wp_safe_redirect( admin_url() );
            exit;
        }

        // Mark that we arrived via the correct custom URL.
        define( 'CS_DEVTOOLS_LOGIN_CUSTOM_SLUG', true );

        // Set $pagenow so plugins that check `$pagenow === 'wp-login.php'`
        // behave correctly (e.g. security plugins, CAPTCHA plugins).
        global $pagenow;
        $pagenow = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional: serving login page at custom URL

        // Adjust server globals so wp-login.php sees itself at its real path
        // and generates correct self-referencing form actions.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $_SERVER['PHP_SELF']        = '/wp-login.php';
        $_SERVER['SCRIPT_FILENAME'] = ABSPATH . 'wp-login.php';
        // Keep REQUEST_URI pointing to the custom slug so redirect_to round-trips
        // correctly; site_url filter handles the form action rewrite.

        require_once ABSPATH . 'wp-login.php'; // phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude.FileIncludeFound
        exit;
    }

    /**
     * Filters the auth cookie lifetime.
     *
     * When a custom session duration has been set in the Login Security settings,
     * this overrides WordPress's default 2-day (non-remember) / 14-day (remember)
     * lifetimes with the admin-configured value — applied uniformly regardless of
     * whether the user ticked "Remember Me".
     *
     * @since  1.9.4
     * @param  int  $expiration Default expiration in seconds.
     * @param  int  $user_id    User ID being authenticated.
     * @param  bool $remember   Whether "Remember Me" was checked.
     * @return int
     */
    public static function login_session_expiration( int $expiration, int $user_id, bool $remember ): int {
        $duration = get_option( 'csdt_devtools_session_duration', 'default' );
        if ( 'default' === $duration ) {
            return $expiration;
        }
        return (int) $duration * DAY_IN_SECONDS;
    }

    /**
     * When a custom session duration is configured, forces "remember me" so the
     * auth cookie is written as a persistent cookie (non-zero browser expiry)
     * rather than a session cookie that browsers clear when closed/swiped away.
     *
     * Hooked to `login_init` (priority 5) — fires before WordPress reads
     * $_POST['rememberme'] when processing the login form POST, so wp_signon()
     * receives remember=true and wp_set_auth_cookie() sets an explicit expiry.
     *
     * Note: login_form_login is a DISPLAY hook (fires when rendering the form)
     * and never fires on a successful login POST — do NOT use that hook here.
     *
     * @since  1.8.88
     * @return void
     */
    public static function login_force_remember(): void {
        if ( get_option( 'csdt_devtools_session_duration', 'default' ) === 'default' ) {
            return;
        }
        // Only inject on an actual POST — not on GET page loads. Some security
        // plugins and WP 2FA check !empty($_POST) rather than REQUEST_METHOD to
        // detect a login attempt; injecting rememberme on GET causes them to
        // process an empty form and show "username field is empty" on first load.
        if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }
        $_POST['rememberme'] = 'forever'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    /**
     * Returns true when the auth cookie should be written as a persistent cookie.
     * Respects the custom session duration setting (always persistent) and falls
     * back to the user's original "Remember Me" choice stored in the 2FA transient.
     *
     * @since  1.9.10
     * @param  array $pending  2FA pending transient data (may be empty for non-2FA logins).
     * @return bool
     */
    private static function login_should_remember( array $pending = [] ): bool {
        if ( get_option( 'csdt_devtools_session_duration', 'default' ) !== 'default' ) {
            return true;
        }
        return ! empty( $pending['remember'] );
    }

    /**
     * Hooked to `authenticate` at priority 1. Returns a WP_Error when the
     * submitted username has been temporarily locked due to repeated failed attempts.
     *
     * @since  1.9.10
     * @param  \WP_User|\WP_Error|null $user
     * @param  string                  $username
     * @param  string                  $password
     * @return \WP_User|\WP_Error|null
     */
    public static function login_brute_force_check( $user, string $username, string $password ) {
        if ( get_option( 'csdt_devtools_brute_force_enabled', '1' ) !== '1' ) {
            return $user;
        }
        if ( empty( $username ) ) {
            return $user;
        }
        $lock_key     = 'csdt_devtools_bf_lock_' . md5( strtolower( $username ) );
        $locked_until = get_transient( $lock_key );
        if ( $locked_until === false ) {
            return $user;
        }
        $remaining = (int) $locked_until - time();
        if ( $remaining <= 0 ) {
            delete_transient( $lock_key );
            return $user;
        }
        $mins  = (int) ceil( $remaining / 60 );
        $label = $mins <= 1
            ? __( 'less than a minute', 'cloudscale-devtools' )
            : sprintf( _n( '%d minute', '%d minutes', $mins, 'cloudscale-devtools' ), $mins );
        return new \WP_Error(
            'csdt_devtools_account_locked',
            sprintf(
                /* translators: %s = remaining lockout time, e.g. "5 minutes" */
                __( 'This account has been temporarily locked due to too many failed login attempts. Please try again in %s.', 'cloudscale-devtools' ),
                $label
            )
        );
    }

    /**
     * Fired on `login_init` at priority 0 — before any other login hook.
     * If the visitor already has a valid WordPress session, redirect them
     * straight to the dashboard instead of showing the login form.
     *
     * Skipped for logout, password reset, and other non-login actions so those
     * flows are never short-circuited.
     *
     * @since  1.9.4
     * @return void
     */
    public static function login_redirect_authenticated(): void {
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $skip   = [ 'logout', 'lostpassword', 'rp', 'resetpass', 'postpass', 'register', 'csdt_devtools_2fa' ];
        if ( in_array( $action, $skip, true ) ) {
            return;
        }
        if ( ! is_user_logged_in() ) {
            return;
        }
        wp_safe_redirect( admin_url() );
        exit;
    }

    /**
     * Fired on `login_init` at priority 1. Blocks direct access to
     * wp-login.php when Hide Login is enabled.
     *
     * @since  1.9.4
     * @return void
     */
    /**
     * Replaces credential-specific login errors with a generic message to prevent
     * username enumeration. Lockout and 2FA errors are left unchanged.
     */
    public static function generic_login_errors( \WP_Error $errors ): \WP_Error {
        $enum_codes = [ 'invalid_username', 'invalid_email', 'incorrect_password', 'invalidcombo' ];
        $replaced   = false;
        foreach ( $enum_codes as $code ) {
            if ( $errors->get_error_message( $code ) ) {
                $errors->remove( $code );
                $replaced = true;
            }
        }
        if ( $replaced ) {
            $errors->add(
                'authentication_failed',
                '<strong>' . esc_html__( 'Error:', 'cloudscale-devtools' ) . '</strong> ' .
                esc_html__( 'Invalid username or password.', 'cloudscale-devtools' ) .
                '<br><small style="color:#6b7280;">Protected by <a href="https://andrewbaker.ninja" target="_blank" rel="noopener noreferrer" style="color:#6b7280;">CloudScale Cyber and Devtools</a>.</small>'
            );
        }
        return $errors;
    }

    public static function login_error_styles(): void {
        $css = '#login_error,div.error{background:#0f172a!important;border-left:4px solid #ef4444!important;border-radius:6px!important;color:#f1f5f9!important;padding:12px 16px!important;box-shadow:0 2px 8px rgba(0,0,0,.35)!important}'
             . '#login_error a,div.error a{color:#93c5fd!important}'
             . '#login_error strong,div.error strong{color:#fca5a5!important}'
             . '#login{width:380px!important;max-width:calc(100vw - 32px)!important}'
             . '.login form{margin-top:16px!important}';
        wp_add_inline_style( 'login', $css );
    }

    /**
     * Shared stats recorder for all default-login-path probes (wp-login.php and wp-admin).
     * Updates the daily count, last-hit metadata, and per-IP hits log in csdt_wplogin_blocked_stats.
     */
    private static function record_default_login_probe( string $ip ): void {
        $today  = gmdate( 'Y-m-d' );
        $now    = time();
        $stats  = get_option( 'csdt_wplogin_blocked_stats', [] );
        if ( ! isset( $stats['daily'] ) || ! is_array( $stats['daily'] ) ) {
            $stats['daily'] = [];
        }
        $stats['daily'][ $today ] = ( $stats['daily'][ $today ] ?? 0 ) + 1;
        $cutoff = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
        foreach ( array_keys( $stats['daily'] ) as $k ) {
            if ( $k < $cutoff ) unset( $stats['daily'][ $k ] );
        }

        // Per-country 7-day rolling count for the map.
        $country = CSDT_Geo::get_country( $ip );
        if ( $country ) {
            if ( ! isset( $stats['country_stats'] ) || ! is_array( $stats['country_stats'] ) ) {
                $stats['country_stats'] = [];
            }
            if ( ! isset( $stats['country_stats'][ $country ] ) || ! is_array( $stats['country_stats'][ $country ] ) ) {
                $stats['country_stats'][ $country ] = [];
            }
            $stats['country_stats'][ $country ][ $today ] = ( $stats['country_stats'][ $country ][ $today ] ?? 0 ) + 1;
            foreach ( $stats['country_stats'] as $cc => $days ) {
                foreach ( array_keys( $days ) as $day_key ) {
                    if ( $day_key < $cutoff ) unset( $stats['country_stats'][ $cc ][ $day_key ] );
                }
                if ( empty( $stats['country_stats'][ $cc ] ) ) unset( $stats['country_stats'][ $cc ] );
            }
        }
        $stats['last_ts'] = $now;
        $stats['last_ip'] = $ip;
        unset( $stats['hits'] ); // removed: replaced by ip_stats
        if ( ! isset( $stats['ip_stats'] ) || ! is_array( $stats['ip_stats'] ) ) {
            $stats['ip_stats'] = [];
        }
        if ( ! isset( $stats['ip_stats'][ $ip ] ) || ! is_array( $stats['ip_stats'][ $ip ] ) ) {
            $stats['ip_stats'][ $ip ] = [ 'last_ts' => 0, 'days' => [] ];
        }
        $stats['ip_stats'][ $ip ]['last_ts']          = $now;
        $stats['ip_stats'][ $ip ]['days'][ $today ]   = ( $stats['ip_stats'][ $ip ]['days'][ $today ] ?? 0 ) + 1;
        if ( $country ) {
            $stats['ip_stats'][ $ip ]['country'] = $country;
        }
        foreach ( $stats['ip_stats'] as $ip_key => &$ip_data ) {
            foreach ( array_keys( $ip_data['days'] ) as $day_key ) {
                if ( $day_key < $cutoff ) unset( $ip_data['days'][ $day_key ] );
            }
            if ( empty( $ip_data['days'] ) ) unset( $stats['ip_stats'][ $ip_key ] );
        }
        unset( $ip_data );
        if ( count( $stats['ip_stats'] ) > 200 ) {
            uasort( $stats['ip_stats'], fn( $a, $b ) => $b['last_ts'] - $a['last_ts'] );
            $stats['ip_stats'] = array_slice( $stats['ip_stats'], 0, 200, true );
        }
        update_option( 'csdt_wplogin_blocked_stats', $stats, false );
    }

    public static function login_block_direct_access(): void {
        if ( get_option( 'csdt_devtools_login_hide_enabled', '0' ) !== '1' ) {
            return;
        }
        $slug = get_option( 'csdt_devtools_login_slug', '' );
        if ( empty( $slug ) ) {
            return;
        }
        // Allow through: arrived via the correct custom slug.
        if ( defined( 'CS_DEVTOOLS_LOGIN_CUSTOM_SLUG' ) && CS_DEVTOOLS_LOGIN_CUSTOM_SLUG ) {
            return;
        }
        // Allow through: WP-CLI, cron, XMLRPC, and REST don't use the browser login form.
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return;
        }
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return;
        }
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            return;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }
        // Allow through: safe wp-login.php actions (password reset emails, logout, postpass).
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $safe   = [ 'logout', 'lostpassword', 'rp', 'resetpass', 'postpass', 'register', 'csdt_devtools_2fa' ];
        if ( in_array( $action, $safe, true ) ) {
            return;
        }
        // Record this blocked hit for the BF panel stats.
        $ip = CloudScale_DevTools::get_client_ip();
        self::record_default_login_probe( $ip );

        // Block — redirect direct /wp-login.php access to home.
        wp_safe_redirect( home_url( '/' ) );
        exit;
    }

    /**
     * Replaces wp_login_url() return value with the custom slug URL when enabled.
     *
     * @since  1.9.4
     * @param  string $url
     * @param  string $redirect
     * @param  bool   $force_reauth
     * @return string
     */
    /**
     * Intercepts unauthenticated wp-admin requests when Hide Login is enabled.
     * Renders a branded 403 page so the custom login slug is never revealed via redirect.
     */
    public static function login_admin_intercept(): void {
        if ( get_option( 'csdt_devtools_login_hide_enabled', '0' ) !== '1' ) {
            return;
        }
        if ( defined( 'DOING_AJAX' ) || defined( 'DOING_CRON' ) ) {
            return;
        }

        // Also intercept /wp-login and /wp-login/ (without .php — WordPress routes
        // these through index.php as a 404 rather than firing login_init).
        $req_path    = rtrim( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' );
        $home_path   = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
        $is_wp_login = ( $req_path === $home_path . '/wp-login' );

        if ( ! is_admin() && ! $is_wp_login ) {
            return;
        }
        if ( is_user_logged_in() ) {
            return;
        }

        $ip        = CloudScale_DevTools::get_client_ip();
        $site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

        // Record in the shared wplogin blocked stats (same store as wp-login.php probes).
        self::record_default_login_probe( $ip );
        status_header( 403 );
        header( 'Content-Type: text/html; charset=utf-8' );
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- custom 403 HTML page output after status_header(403); wp_head() has not fired so wp_enqueue_style() is unavailable.
        echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Access Protected</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0f172a;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
.card{text-align:center;max-width:460px;padding:48px 40px;background:#1e293b;border:1px solid #334155;border-radius:16px;box-shadow:0 25px 60px rgba(0,0,0,.5);}
.shield{width:80px;height:80px;margin:0 auto 28px;}
.badge{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#f87171;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);border-radius:20px;padding:4px 12px;margin-bottom:20px;}
h1{font-size:22px;font-weight:700;color:#f1f5f9;margin-bottom:8px;line-height:1.3;}
.site-name{font-size:13px;color:#94a3b8;margin-bottom:28px;}
.divider{height:1px;background:#475569;margin:24px 0;}
.protected-by{font-size:12px;color:#94a3b8;margin-bottom:10px;text-transform:uppercase;letter-spacing:.08em;}
.brand{font-size:17px;font-weight:700;color:#f1f5f9;text-decoration:none;transition:color .2s;}
.brand:hover{color:#60a5fa;}
.brand span{color:#ef4444;}
.help-link{display:inline-block;margin-top:18px;font-size:12px;color:#cbd5e1;text-decoration:none;border:1px solid #475569;border-radius:6px;padding:6px 14px;transition:all .2s;}
.help-link:hover{color:#f1f5f9;border-color:#94a3b8;}
.tracking{margin-top:20px;font-size:12px;color:#cbd5e1;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);border-radius:6px;padding:10px 14px;line-height:1.7;}
.tracking strong{color:#fca5a5;}
</style>
</head>
<body>
<div class="card">
  <svg class="shield" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
    <defs>
      <linearGradient id="sg" x1="40" y1="8" x2="40" y2="72" gradientUnits="userSpaceOnUse">
        <stop offset="0%" stop-color="#f87171"/>
        <stop offset="100%" stop-color="#b91c1c"/>
      </linearGradient>
    </defs>
    <path d="M40 8 L68 20 L68 42 C68 57 55 68 40 72 C25 68 12 57 12 42 L12 20 Z" fill="url(#sg)" opacity=".15"/>
    <path d="M40 8 L68 20 L68 42 C68 57 55 68 40 72 C25 68 12 57 12 42 L12 20 Z" stroke="#ef4444" stroke-width="2" fill="none"/>
    <path d="M30 40 L37 47 L52 32" stroke="#f87171" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
    <circle cx="40" cy="40" r="8" fill="none" stroke="#ef4444" stroke-width="1" stroke-dasharray="3 3" opacity=".5"/>
  </svg>
  <div class="badge">Access Protected</div>
  <h1>Admin access is restricted</h1>
  <p class="site-name">' . esc_html( $site_name ) . '</p>
  <div class="divider"></div>
  <p class="protected-by">This site is secured by</p>
  <a href="https://andrewbaker.ninja" target="_blank" rel="noopener noreferrer" class="brand">
    CloudScale <span>Cyber</span> and Devtools
  </a>
  <br>
  <a href="https://andrewbaker.ninja/wordpress-plugin-help/cloudscale-cyber-devtools-help/" target="_blank" rel="noopener noreferrer" class="help-link">
    &#x2753; Plugin Help &amp; Documentation
  </a>
  <div class="tracking">
    &#x26A0; This access attempt has been logged.<br>
    <strong>Your IP address (' . esc_html( $ip ) . ') is now being tracked.</strong>
  </div>
</div>
</body>
</html>';
        // phpcs:enable
        exit;
    }

    public static function login_custom_url( string $url, string $redirect, bool $force_reauth ): string {
        if ( get_option( 'csdt_devtools_login_hide_enabled', '0' ) !== '1' ) {
            return $url;
        }
        $slug = get_option( 'csdt_devtools_login_slug', '' );
        if ( empty( $slug ) ) {
            return $url;
        }
        // If WordPress is trying to redirect to the login page because someone hit /wp-admin/
        // while unauthenticated, send them to the home page instead — never reveal the slug.
        if ( ! empty( $redirect ) && strpos( $redirect, '/wp-admin' ) !== false && ! is_user_logged_in() ) {
            return home_url( '/' );
        }
        $custom = home_url( '/' . $slug . '/' );
        if ( ! empty( $redirect ) ) {
            $custom = add_query_arg( 'redirect_to', urlencode( $redirect ), $custom );
        }
        if ( $force_reauth ) {
            $custom = add_query_arg( 'reauth', '1', $custom );
        }
        return $custom;
    }

    /**
     * Replaces the logout URL when Hide Login is enabled.
     *
     * @since  1.9.4
     * @param  string $url
     * @param  string $redirect
     * @return string
     */
    public static function login_custom_logout_url( string $url, string $redirect ): string {
        if ( get_option( 'csdt_devtools_login_hide_enabled', '0' ) !== '1' ) {
            return $url;
        }
        $slug = get_option( 'csdt_devtools_login_slug', '' );
        if ( empty( $slug ) ) {
            return $url;
        }
        $nonce  = wp_create_nonce( 'log-out' );
        $custom = home_url( '/' . $slug . '/?action=logout&_wpnonce=' . $nonce );
        if ( ! empty( $redirect ) ) {
            $custom = add_query_arg( 'redirect_to', urlencode( $redirect ), $custom );
        }
        return $custom;
    }

    /**
     * Replaces the lost-password URL when Hide Login is enabled.
     *
     * @since  1.9.4
     * @param  string $url
     * @param  string $redirect
     * @return string
     */
    public static function login_custom_lostpassword_url( string $url, string $redirect ): string {
        if ( get_option( 'csdt_devtools_login_hide_enabled', '0' ) !== '1' ) {
            return $url;
        }
        $slug = get_option( 'csdt_devtools_login_slug', '' );
        if ( empty( $slug ) ) {
            return $url;
        }
        $custom = home_url( '/' . $slug . '/?action=lostpassword' );
        if ( ! empty( $redirect ) ) {
            $custom = add_query_arg( 'redirect_to', urlencode( $redirect ), $custom );
        }
        return $custom;
    }

    /**
     * Rewrites network_site_url() calls that reference wp-login.php.
     *
     * @since  1.9.4
     * @param  string $url
     * @param  string $path
     * @param  string $scheme
     * @return string
     */
    public static function login_custom_network_url( string $url, string $path, ?string $scheme ): string {
        return self::login_rewrite_login_url( $url, $path );
    }

    /**
     * Rewrites site_url() calls that reference wp-login.php.
     *
     * @since  1.9.4
     * @param  string $url
     * @param  string $path
     * @param  string $scheme
     * @param  int    $blog_id
     * @return string
     */
    public static function login_custom_site_url( string $url, string $path, ?string $scheme, $blog_id ): string {
        return self::login_rewrite_login_url( $url, $path );
    }

    /**
     * Helper: replaces wp-login.php in a URL with the custom slug.
     *
     * @since  1.9.4
     * @param  string $url
     * @param  string $path
     * @return string
     */
    private static function login_rewrite_login_url( string $url, string $path ): string {
        if ( get_option( 'csdt_devtools_login_hide_enabled', '0' ) !== '1' ) {
            return $url;
        }
        $slug = get_option( 'csdt_devtools_login_slug', '' );
        if ( empty( $slug ) || strpos( $path, 'wp-login.php' ) === false ) {
            return $url;
        }
        return str_replace( 'wp-login.php', $slug . '/', $url );
    }

    // ── B. Two-Factor Authentication ─────────────────────────────────────

    /**
     * Intercepts successful authentication and triggers 2FA when required.
     * Hooked to `authenticate` at priority 100 (after core password check at 20).
     *
     * @since  1.9.4
     * @param  \WP_User|\WP_Error|null $user
     * @param  string                  $username
     * @param  string                  $password
     * @return \WP_User|\WP_Error|null
     */
    public static function login_2fa_intercept( $user, string $username, string $password ) {
        // Only act on a successfully authenticated user.
        if ( ! ( $user instanceof \WP_User ) ) {
            return $user;
        }

        $method = self::login_2fa_method_for_user( $user );
        if ( $method === 'off' ) {
            return $user;
        }

        // Grace logins: allow up to N logins without 2FA being set up.
        // Useful for automated test accounts or newly invited users.
        $grace_limit = (int) get_option( 'csdt_devtools_2fa_grace_logins', '0' );
        if ( $grace_limit > 0 ) {
            $grace_count = (int) get_user_meta( $user->ID, 'csdt_devtools_2fa_grace_count', true );
            if ( $grace_count < $grace_limit ) {
                update_user_meta( $user->ID, 'csdt_devtools_2fa_grace_count', $grace_count + 1 );
                return $user; // Skip 2FA — grace login consumed.
            }
        }

        // Avoid triggering 2FA during a 2FA verification POST itself.
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $action === 'csdt_devtools_2fa' ) {
            return $user;
        }

        // Build the list of methods this user actually has available.
        $available = self::login_2fa_available_methods( $user );
        // Subset that the user has explicitly configured (vs email which is always a fallback).
        $setup = self::login_2fa_setup_methods( $user );

        // If multiple methods are registered, show a picker rather than auto-routing.
        $initial_method = count( $available ) === 1 ? $available[0] : 'picker';

        // Generate a short-lived pending token.
        $token = wp_generate_password( 32, false, false );
        $data  = [
            'user_id'   => $user->ID,
            'method'    => $initial_method,
            'available' => $available,
            'setup'     => $setup,
            'created'   => time(),
            'remember'  => ! empty( $_POST['rememberme'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
        ];

        if ( $initial_method === 'email' ) {
            // Generate + store OTP.
            $otp = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
            set_transient( CloudScale_DevTools::LOGIN_OTP_TRANSIENT . $user->ID, wp_hash( $otp ), 600 );
            // Send it.
            $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
            $to   = $user->user_email;
            $subj = sprintf( '[%s] Your login code', $site );
            $body = self::email_html_otp( $user->display_name, $site, $otp );
            add_filter( 'wp_mail_content_type', [ __CLASS__, 'email_content_type_html' ] );
            wp_mail( $to, $subj, $body );
            remove_filter( 'wp_mail_content_type', [ __CLASS__, 'email_content_type_html' ] );
        }

        set_transient( CloudScale_DevTools::LOGIN_2FA_TRANSIENT . $token, $data, 600 );

        // Redirect to the 2FA form.
        $login_url = add_query_arg( [
            'action'   => 'csdt_devtools_2fa',
            'csdt_devtools_token' => rawurlencode( $token ),
        ], wp_login_url() );

        wp_safe_redirect( $login_url );
        exit;
    }

    /**
     * Fired on `login_init`. Handles the 2FA code entry form: display and verification.
     *
     * @since  1.9.4
     * @return void
     */
    public static function login_2fa_handle(): void {
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $action !== 'csdt_devtools_2fa' ) {
            return;
        }

        $token   = isset( $_REQUEST['csdt_devtools_token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['csdt_devtools_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $pending = $token ? get_transient( CloudScale_DevTools::LOGIN_2FA_TRANSIENT . $token ) : false;

        // Invalid or expired token → back to login.
        if ( ! $pending || empty( $pending['user_id'] ) ) {
            wp_safe_redirect( wp_login_url() );
            exit;
        }

        $user_id   = (int) $pending['user_id'];
        $method    = $pending['method'];
        $available = isset( $pending['available'] ) && is_array( $pending['available'] ) ? $pending['available'] : [ $method ];
        $setup     = isset( $pending['setup'] )     && is_array( $pending['setup'] )     ? $pending['setup']     : $available;
        $error     = '';

        // ── Back-to-picker from any challenge screen ─────────────────────────
        if ( isset( $_GET['csdt_devtools_back_to_picker'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            // Always recompute available/setup fresh so stale transients
            // without those keys still show the full method list.
            $u = get_user_by( 'id', $user_id );
            if ( $u instanceof \WP_User ) {
                $pending['available'] = self::login_2fa_available_methods( $u );
                $pending['setup']     = self::login_2fa_setup_methods( $u );
                $available            = $pending['available'];
                $setup                = $pending['setup'];
            }
            $pending['method'] = 'picker';
            set_transient( CloudScale_DevTools::LOGIN_2FA_TRANSIENT . $token, $pending, 600 );
            $url = add_query_arg( [
                'action'              => 'csdt_devtools_2fa',
                'csdt_devtools_token' => rawurlencode( $token ),
            ], wp_login_url() );
            wp_safe_redirect( $url );
            exit;
        }

        // ── Method picker ────────────────────────────────────────────────────
        // When multiple methods are registered, show a selection screen before
        // the challenge so the user can choose how they want to authenticate.
        if ( $method === 'picker' ) {
            if ( isset( $_POST['csdt_devtools_method_choice'] ) ) {
                $choice = sanitize_key( wp_unslash( $_POST['csdt_devtools_method_choice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
                if ( in_array( $choice, $available, true ) ) {
                    $pending['method'] = $choice;
                    // Send email OTP now that the user chose email.
                    if ( $choice === 'email' ) {
                        $rate_key    = 'csdt_devtools_pk_fb_' . $user_id;
                        $already_sent = get_transient( $rate_key );
                        if ( ! $already_sent ) {
                            $u = get_user_by( 'id', $user_id );
                            if ( $u instanceof \WP_User ) {
                                $otp = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
                                set_transient( CloudScale_DevTools::LOGIN_OTP_TRANSIENT . $user_id, wp_hash( $otp ), 600 );
                                set_transient( $rate_key, 1, 30 );
                                $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
                                add_filter( 'wp_mail_content_type', [ __CLASS__, 'email_content_type_html' ] );
                                wp_mail( $u->user_email, sprintf( '[%s] Your login code', $site ), self::email_html_otp( $u->display_name, $site, $otp ) );
                                remove_filter( 'wp_mail_content_type', [ __CLASS__, 'email_content_type_html' ] );
                            }
                        }
                    }
                    set_transient( CloudScale_DevTools::LOGIN_2FA_TRANSIENT . $token, $pending, 600 );
                    $url = add_query_arg( [
                        'action'              => 'csdt_devtools_2fa',
                        'csdt_devtools_token' => rawurlencode( $token ),
                    ], wp_login_url() );
                    wp_safe_redirect( $url );
                    exit;
                }
            }
            self::login_2fa_render_picker( $token, $available, $setup );
            exit;
        }

        // ── Passkey → email fallback ─────────────────────────────────────────
        if ( $method === 'passkey' && ! empty( $_POST['csdt_devtools_pk_fallback'] ) ) {
            // Only send a new OTP if one hasn't been sent in the last 30 seconds (prevents spam from double-clicks).
            $rate_key    = 'csdt_devtools_pk_fb_' . $user_id;
            $already_sent = get_transient( $rate_key );
            if ( ! $already_sent ) {
                $user = get_user_by( 'id', $user_id );
                if ( $user instanceof \WP_User ) {
                    $otp  = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
                    set_transient( CloudScale_DevTools::LOGIN_OTP_TRANSIENT . $user_id, wp_hash( $otp ), 600 );
                    set_transient( $rate_key, 1, 30 ); // block re-sends for 30s
                    $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
                    add_filter( 'wp_mail_content_type', [ __CLASS__, 'email_content_type_html' ] );
                    wp_mail( $user->user_email, sprintf( '[%s] Your login code', $site ), self::email_html_otp( $user->display_name, $site, $otp ) );
                    remove_filter( 'wp_mail_content_type', [ __CLASS__, 'email_content_type_html' ] );
                }
            }
            // Update the transient to use email method.
            $pending['method'] = 'email';
            set_transient( CloudScale_DevTools::LOGIN_2FA_TRANSIENT . $token, $pending, 600 );
            $method = 'email';
        }

        // ── Passkey assertion (POST from cs-passkey login page) ──────────────
        if ( $method === 'passkey' && isset( $_POST['csdt_devtools_pk_cred_id'] ) ) {
            $result = CSDT_DevTools_Passkey::verify_login_assertion( $token, $user_id );
            if ( $result === true ) {
                delete_transient( CloudScale_DevTools::LOGIN_2FA_TRANSIENT . $token );
                wp_set_auth_cookie( $user_id, self::login_should_remember( $pending ) );
                $redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url();
                // Hooked on login_init — no output has started, so wp_safe_redirect() works correctly.
                nocache_headers();
                wp_safe_redirect( $redirect );
                exit;
            }
            // Verification failed — re-render challenge with error.
            $error = $result->get_error_message();
        }

        // ── Passkey challenge page (GET or re-render after failure) ──────────
        if ( $method === 'passkey' && empty( $_POST['csdt_devtools_2fa_code'] ) ) {
            CSDT_DevTools_Passkey::render_login_challenge( $token, $user_id, $error, $available );
            // render_login_challenge() exits.
        }

        // Handle code submission.
        if ( isset( $_POST['csdt_devtools_2fa_code'] ) ) {
            if ( ! isset( $_POST['csdt_devtools_2fa_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['csdt_devtools_2fa_nonce'] ) ), 'csdt_devtools_2fa_verify_' . $token ) ) {
                $error = __( 'Security check failed. Please try again.', 'cloudscale-devtools' );
            } else {
                $code    = preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['csdt_devtools_2fa_code'] ) ) );
                $user    = get_user_by( 'id', $user_id );
                $valid   = false;

                if ( $user instanceof \WP_User ) {
                    if ( $method === 'email' ) {
                        $stored = get_transient( CloudScale_DevTools::LOGIN_OTP_TRANSIENT . $user_id );
                        if ( $stored && hash_equals( $stored, wp_hash( $code ) ) ) {
                            $valid = true;
                            delete_transient( CloudScale_DevTools::LOGIN_OTP_TRANSIENT . $user_id );
                        }
                    } elseif ( $method === 'totp' ) {
                        $secret = get_user_meta( $user_id, 'csdt_devtools_totp_secret', true );
                        if ( $secret ) {
                            $valid = self::totp_verify( (string) $secret, $code );
                        }
                    }
                }

                if ( $valid ) {
                    delete_transient( CloudScale_DevTools::LOGIN_2FA_TRANSIENT . $token );
                    // Complete the login.
                    wp_set_auth_cookie( $user_id, self::login_should_remember( $pending ) );
                    $redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url();
                    wp_safe_redirect( $redirect );
                    exit;
                } else {
                    $error = __( 'Invalid code. Please try again.', 'cloudscale-devtools' );
                }
            }
        }

        // Render the 2FA form.
        self::login_2fa_render_form( $token, $method, $error, $user_id, count( $available ) > 1 );
        exit;
    }

    /**
     * Outputs the 2FA code entry page using WordPress's own login styles.
     *
     * @since  1.9.4
     * @param  string $token  Pending auth token.
     * @param  string $method 'email' or 'totp'.
     * @param  string $error  Optional error message.
     * @return void
     */
    private static function login_2fa_render_form( string $token, string $method, string $error = '', int $user_id = 0, bool $has_picker = false ): void {
        login_header( __( 'Two-Factor Authentication', 'cloudscale-devtools' ), '', null );

        $nonce = wp_create_nonce( 'csdt_devtools_2fa_verify_' . $token );

        if ( $method === 'email' && $user_id ) {
            $u     = get_user_by( 'id', $user_id );
            $email = $u instanceof \WP_User ? $u->user_email : '';
            if ( $email ) {
                [ $local, $domain ] = explode( '@', $email, 2 );
                $masked = ( strlen( $local ) > 2
                    ? substr( $local, 0, 1 ) . str_repeat( '*', strlen( $local ) - 2 ) . substr( $local, -1 )
                    : substr( $local, 0, 1 ) . '***' )
                    . '@' . $domain;
                $method_txt = sprintf(
                    /* translators: %s = masked email address */
                    __( 'Enter the 6-digit code sent to %s', 'cloudscale-devtools' ),
                    $masked
                );
            } else {
                $method_txt = __( 'Enter the 6-digit code sent to your email address.', 'cloudscale-devtools' );
            }
        } else {
            $method_txt = __( 'Enter the 6-digit code from your authenticator app.', 'cloudscale-devtools' );
        }

        $icon       = $method === 'email' ? '📧' : '📱';
        $picker_url = $has_picker
            ? add_query_arg( [ 'action' => 'csdt_devtools_2fa', 'csdt_devtools_token' => rawurlencode( $token ), 'csdt_devtools_back_to_picker' => '1' ], wp_login_url() )
            : '';
        ?>
        <form name="csdt_devtools_2faform" id="csdt_devtools_2faform" action="" method="post">
            <p style="text-align:center;font-size:48px;margin:0 0 8px"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — emoji literal ?></p>
            <p style="text-align:center;margin:0 0 20px;color:#555;font-size:13px;line-height:1.5"><?php echo esc_html( $method_txt ); ?></p>

            <?php if ( $error ) : ?>
                <div id="login_error" class="notice notice-error" style="margin:0 0 16px"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <p>
                <label for="csdt_devtools_2fa_code"><?php esc_html_e( 'Authentication Code', 'cloudscale-devtools' ); ?></label>
                <input type="text" name="csdt_devtools_2fa_code" id="csdt_devtools_2fa_code" class="input"
                       value="" size="20" maxlength="6"
                       inputmode="numeric" autocomplete="one-time-code"
                       placeholder="000000"
                       autofocus style="text-align:center;font-size:22px;letter-spacing:6px">
            </p>

            <?php
            $redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( $redirect ) {
                echo '<input type="hidden" name="redirect_to" value="' . esc_attr( $redirect ) . '">';
            }
            ?>

            <input type="hidden" name="action"               value="csdt_devtools_2fa">
            <input type="hidden" name="csdt_devtools_token"  value="<?php echo esc_attr( $token ); ?>">
            <input type="hidden" name="csdt_devtools_2fa_nonce" value="<?php echo esc_attr( $nonce ); ?>">

            <p class="submit">
                <input type="submit" name="wp-submit" id="wp-submit"
                       class="button button-primary button-large"
                       value="<?php esc_attr_e( 'Verify Code', 'cloudscale-devtools' ); ?>">
            </p>

            <?php if ( $method === 'email' ) : ?>
                <p style="text-align:center;margin-top:12px;font-size:12px;color:#888">
                    <?php esc_html_e( "Didn't receive a code? Check your spam folder or wait up to 1 minute.", 'cloudscale-devtools' ); ?>
                </p>
            <?php endif; ?>
        </form>

        <?php if ( $picker_url ) : ?>
        <p style="text-align:center;margin-top:14px;">
            <a href="<?php echo esc_url( $picker_url ); ?>" style="font-size:12px;color:#6b7280;text-decoration:none;">
                &larr; <?php esc_html_e( 'Other verification options', 'cloudscale-devtools' ); ?>
            </a>
        </p>
        <?php endif; ?>
        <?php
        login_footer();
    }

    /**
     * Determines which 2FA method applies to a given user.
     * Returns 'off', 'email', or 'totp'.
     *
     * @since  1.9.4
     * @param  \WP_User $user
     * @return string
     */
    private static function login_2fa_method_for_user( \WP_User $user ): string {
        $site_method = get_option( 'csdt_devtools_2fa_method', 'off' );
        $force       = get_option( 'csdt_devtools_2fa_force_admins', '0' ) === '1';

        // Passkeys always take priority when the user has any registered.
        if ( ! empty( CSDT_DevTools_Passkey::get_passkeys( $user->ID ) ) ) {
            return 'passkey';
        }

        // If force is on and user is admin, enforce the site method.
        if ( $force && user_can( $user, 'manage_options' ) && $site_method !== 'off' ) {
            // If TOTP forced but user hasn't set it up, fall back to email.
            if ( $site_method === 'totp' && get_user_meta( $user->ID, 'csdt_devtools_totp_enabled', true ) !== '1' ) {
                return 'email';
            }
            return $site_method;
        }

        // Per-user TOTP.
        if ( get_user_meta( $user->ID, 'csdt_devtools_totp_enabled', true ) === '1' ) {
            return 'totp';
        }

        // Per-user email 2FA.
        if ( get_user_meta( $user->ID, 'csdt_devtools_2fa_email_enabled', true ) === '1' ) {
            return 'email';
        }

        // Fall back to site-wide default.
        if ( $site_method !== 'off' ) {
            if ( $site_method === 'passkey' ) {
                return 'email'; // no passkeys registered — fall back to email
            }
            // TOTP as site default only applies if user has it set up.
            if ( $site_method === 'totp' ) {
                return 'email'; // safe fallback
            }
            return $site_method;
        }

        return 'off';
    }

    /**
     * Returns every 2FA method that is actually available for the given user.
     * Used to decide whether to show a method picker (multiple) or go straight
     * to the challenge (single).
     *
     * @param  \WP_User $user
     * @return string[]  Non-empty array from: 'passkey', 'totp', 'email'
     */
    private static function login_2fa_available_methods( \WP_User $user ): array {
        $methods = [];
        if ( ! empty( CSDT_DevTools_Passkey::get_passkeys( $user->ID ) ) ) {
            $methods[] = 'passkey';
        }
        if ( get_user_meta( $user->ID, 'csdt_devtools_totp_enabled', true ) === '1' ) {
            $methods[] = 'totp';
        }
        // Email is always a valid fallback when 2FA is required for this user.
        $methods[] = 'email';
        return $methods;
    }

    /**
     * Returns the subset of methods the user has explicitly configured.
     * Used to show the "Setup" badge in the picker — passkey and TOTP are
     * only here when actively registered; email only if explicitly enabled.
     *
     * @param  \WP_User $user
     * @return string[]
     */
    private static function login_2fa_setup_methods( \WP_User $user ): array {
        $setup = [];
        if ( ! empty( CSDT_DevTools_Passkey::get_passkeys( $user->ID ) ) ) {
            $setup[] = 'passkey';
        }
        if ( get_user_meta( $user->ID, 'csdt_devtools_totp_enabled', true ) === '1' ) {
            $setup[] = 'totp';
        }
        if ( get_user_meta( $user->ID, 'csdt_devtools_2fa_email_enabled', true ) === '1' ) {
            $setup[] = 'email';
        }
        return $setup;
    }

    /**
     * Renders the method-selection screen when a user has multiple 2FA options.
     *
     * @param  string   $token     2FA transient token.
     * @param  string[] $available Methods available for this user.
     * @return void
     */
    private static function login_2fa_render_picker( string $token, array $available, array $setup = [] ): void {
        login_header( __( 'Two-Factor Authentication', 'cloudscale-devtools' ), '', null );
        $labels = [
            'passkey' => [ 'icon' => '🔑', 'label' => __( 'Use a Passkey',           'cloudscale-devtools' ), 'hint' => __( 'Biometric or device PIN',        'cloudscale-devtools' ) ],
            'totp'    => [ 'icon' => '📱', 'label' => __( 'Google Authenticator',     'cloudscale-devtools' ), 'hint' => __( 'Code from your authenticator app', 'cloudscale-devtools' ) ],
            'email'   => [ 'icon' => '📧', 'label' => __( 'Send me an email code',    'cloudscale-devtools' ), 'hint' => __( 'One-time code sent to your email', 'cloudscale-devtools' ) ],
        ];
        ?>
        <p style="text-align:center;font-size:48px;margin:0 0 8px">🔐</p>
        <p style="text-align:center;margin:0 0 24px;color:#555;font-size:13px;line-height:1.5">
            <?php esc_html_e( 'Choose how you want to verify your identity.', 'cloudscale-devtools' ); ?>
        </p>
        <?php foreach ( $available as $m ) :
            if ( ! isset( $labels[ $m ] ) ) continue;
            $l         = $labels[ $m ];
            $is_setup  = in_array( $m, $setup, true );
        ?>
        <form method="post" action="" style="margin-bottom:10px">
            <input type="hidden" name="action"                       value="csdt_devtools_2fa">
            <input type="hidden" name="csdt_devtools_token"          value="<?php echo esc_attr( $token ); ?>">
            <input type="hidden" name="csdt_devtools_method_choice"  value="<?php echo esc_attr( $m ); ?>">
            <button type="submit" style="
                width:100%;padding:12px 16px;border:1px solid #d1d5db;border-radius:6px;
                background:#fff;cursor:pointer;text-align:left;display:flex;align-items:center;gap:12px;
                font-size:13px;color:#1a2332;transition:background .15s;
            " onmouseover="this.style.background='#f0f4f8'" onmouseout="this.style.background='#fff'">
                <span style="font-size:22px;line-height:1"><?php echo $l['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — emoji literal ?></span>
                <span style="flex:1">
                    <span style="display:flex;align-items:center;gap:6px">
                        <strong><?php echo esc_html( $l['label'] ); ?></strong>
                        <?php if ( $is_setup ) : ?>
                        <span style="
                            font-size:10px;font-weight:600;letter-spacing:.04em;
                            background:#dcfce7;color:#15803d;
                            padding:1px 6px;border-radius:4px;line-height:1.6;
                        "><?php esc_html_e( 'Setup', 'cloudscale-devtools' ); ?></span>
                        <?php endif; ?>
                    </span>
                    <span style="color:#6b7280;font-size:11px"><?php echo esc_html( $l['hint'] ); ?></span>
                </span>
            </button>
        </form>
        <?php endforeach; ?>
        <p style="text-align:center;margin-top:16px">
            <a href="<?php echo esc_url( wp_login_url() ); ?>" style="font-size:12px;color:#888">
                &larr; <?php esc_html_e( 'Back to login', 'cloudscale-devtools' ); ?>
            </a>
        </p>
        <?php
        login_footer();
    }

    // ── C. TOTP (RFC 6238) — pure PHP, no Composer dependency ────────────

    /**
     * Generates a random Base32 secret for TOTP.
     *
     * @since  1.9.4
     * @param  int $length Number of Base32 characters (16 = 80 bits of entropy).
     * @return string
     */
    private static function totp_generate_secret( int $length = 16 ): string {
        $secret = '';
        for ( $i = 0; $i < $length; $i++ ) {
            $secret .= CloudScale_DevTools::TOTP_CHARS[ random_int( 0, 31 ) ];
        }
        return $secret;
    }

    /**
     * Decodes a Base32-encoded string to raw binary.
     *
     * @since  1.9.4
     * @param  string $input Base32 string (upper-case, no padding required).
     * @return string Binary string.
     */
    private static function base32_decode( string $input ): string {
        $input  = strtoupper( rtrim( $input, '=' ) );
        $output = '';
        $buffer = 0;
        $bits   = 0;
        for ( $i = 0, $len = strlen( $input ); $i < $len; $i++ ) {
            $val = strpos( CloudScale_DevTools::TOTP_CHARS, $input[ $i ] );
            if ( $val === false ) {
                continue;
            }
            $buffer = ( $buffer << 5 ) | $val;
            $bits  += 5;
            if ( $bits >= 8 ) {
                $bits   -= 8;
                $output .= chr( ( $buffer >> $bits ) & 0xFF );
            }
        }
        return $output;
    }

    /**
     * Computes a 6-digit HOTP code for the given key and counter (RFC 4226 / 6238).
     *
     * @since  1.9.4
     * @param  string $secret_b32 Base32-encoded shared secret.
     * @param  int    $counter    TOTP counter value (floor(unix_time / 30)).
     * @return string Zero-padded 6-digit string.
     */
    private static function totp_compute( string $secret_b32, int $counter ): string {
        $key          = self::base32_decode( $secret_b32 );
        // Pack counter as 8-byte big-endian integer.
        $counter_bytes = pack( 'N*', 0 ) . pack( 'N*', $counter );
        $hmac          = hash_hmac( 'sha1', $counter_bytes, $key, true );
        $offset        = ord( $hmac[19] ) & 0x0F;
        $code          = (
            ( ( ord( $hmac[ $offset ]     ) & 0x7F ) << 24 ) |
            ( ( ord( $hmac[ $offset + 1 ] ) & 0xFF ) << 16 ) |
            ( ( ord( $hmac[ $offset + 2 ] ) & 0xFF ) <<  8 ) |
            (   ord( $hmac[ $offset + 3 ] ) & 0xFF )
        ) % 1000000;
        return str_pad( (string) $code, 6, '0', STR_PAD_LEFT );
    }

    /**
     * Verifies a TOTP code against the secret, allowing ±1 time-step for clock drift.
     *
     * @since  1.9.4
     * @param  string $secret_b32 Base32-encoded shared secret.
     * @param  string $code       6-digit code to verify.
     * @return bool
     */
    private static function totp_verify( string $secret_b32, string $code ): bool {
        $counter = (int) floor( time() / 30 );
        for ( $offset = -1; $offset <= 1; $offset++ ) {
            if ( hash_equals( self::totp_compute( $secret_b32, $counter + $offset ), $code ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Builds the otpauth:// URI used to provision authenticator apps via QR code.
     *
     * @since  1.9.4
     * @param  string $secret_b32 Base32-encoded secret.
     * @param  string $user_email User email shown in the app.
     * @return string Full otpauth:// URI.
     */
    private static function totp_provisioning_uri( string $secret_b32, string $user_email ): string {
        $issuer  = rawurlencode( get_bloginfo( 'name' ) );
        $account = rawurlencode( $user_email );
        return 'otpauth://totp/' . $issuer . ':' . $account
               . '?secret=' . rawurlencode( $secret_b32 )
               . '&issuer=' . $issuer
               . '&algorithm=SHA1&digits=6&period=30';
    }

    // ── D. AJAX handlers ─────────────────────────────────────────────────

    /**
     * AJAX: returns the 14-day failed login log for the brute-force panel.
     */
    public static function ajax_bf_log_fetch(): void {
        check_ajax_referer( CloudScale_DevTools::LOGIN_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }
        $log = get_option( 'csdt_devtools_bf_log', [] );
        if ( ! is_array( $log ) ) {
            $log = [];
        }
        $cutoff      = time() - 14 * DAY_IN_SECONDS;
        $log         = array_values( array_filter( $log, fn( $e ) => isset( $e[0] ) && $e[0] >= $cutoff ) );
        $today_start = mktime( 0, 0, 0 );
        $today_count = count( array_filter( $log, fn( $e ) => $e[0] >= $today_start ) );

        // Aggregate per-country failed login counts for the map.
        $cc_stats_raw = get_option( 'csdt_bf_country_stats', [] );
        $countries_bf = [];
        if ( is_array( $cc_stats_raw ) ) {
            foreach ( $cc_stats_raw as $cc => $days ) {
                if ( is_array( $days ) ) {
                    $countries_bf[ $cc ] = array_sum( $days );
                }
            }
        }

        $blocklist   = get_option( 'csdt_ip_blocklist', [] );
        $blocked_ips = is_array( $blocklist ) ? array_keys( $blocklist ) : [];

        // API attack log — from csdt_security_events, type=api_attack, last 14 days.
        $sec_events = get_option( 'csdt_security_events', [] );
        $api_log    = [];
        $countries_api = [];
        if ( is_array( $sec_events ) ) {
            foreach ( $sec_events as $ev ) {
                // Both api_attack and rest_fail are "API attacks" for map/table purposes.
                if ( ! in_array( $ev['type'] ?? '', [ 'api_attack', 'rest_fail' ], true ) ) { continue; }
                if ( ( $ev['time'] ?? 0 ) < $cutoff ) { continue; }
                // Parse IP from detail string "IP: x.x.x.x".
                $ip = '';
                $cc = '';
                if ( preg_match( '/IP:\s*([\d.a-fA-F:]+)/', $ev['detail'] ?? '', $m ) ) {
                    $ip = $m[1];
                }
                // Country stored in detail as "· CC" — use it directly to avoid geo lookup overhead.
                if ( preg_match( '/·\s*([A-Z]{2})\s*$/', $ev['detail'] ?? '', $mc ) ) {
                    $cc = $mc[1];
                } elseif ( $ip ) {
                    $cc = CSDT_Geo::get_country( $ip );
                }
                $api_log[] = [ $ev['time'], $ev['title'], $ip, $cc ];
                if ( $cc ) {
                    $countries_api[ $cc ] = ( $countries_api[ $cc ] ?? 0 ) + 1;
                }
            }
        }

        wp_send_json_success( [
            'log'          => $log,
            'now'          => time(),
            'today_count'  => $today_count,
            'countries_bf' => $countries_bf,
            'blocked_ips'  => $blocked_ips,
            'api_log'      => $api_log,
            'countries_api' => $countries_api,
        ] );
    }

    /**
     * AJAX: saves Hide Login and 2FA site-wide settings.
     *
     * @since  1.9.4
     * @return void
     */
    public static function ajax_login_save(): void {
        check_ajax_referer( CloudScale_DevTools::LOGIN_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        // Snapshot current security state for downgrade detection.
        $old_state = [
            'hide_enabled' => (string) get_option( 'csdt_devtools_login_hide_enabled', '0' ),
            'bf_enabled'   => (string) get_option( 'csdt_devtools_brute_force_enabled', '1' ),
            'force_2fa'    => (string) get_option( 'csdt_devtools_2fa_force_admins', '0' ),
            'tfa_method'   => (string) get_option( 'csdt_devtools_2fa_method', 'off' ),
            'enum_protect' => (string) get_option( 'csdt_devtools_enum_protect', '1' ),
        ];

        // Hide Login
        $hide = isset( $_POST['hide_enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['hide_enabled'] ) ) ? '1' : '0';
        $slug = isset( $_POST['login_slug'] ) ? sanitize_title( wp_unslash( $_POST['login_slug'] ) ) : '';
        $reserved = [ 'wp-login', 'wp-admin', 'login', 'admin', 'dashboard' ];
        if ( in_array( $slug, $reserved, true ) ) {
            wp_send_json_error( __( 'That slug is reserved. Please choose a different one.', 'cloudscale-devtools' ) );
        }
        update_option( 'csdt_devtools_login_hide_enabled', $hide );
        update_option( 'csdt_devtools_login_slug', $slug );

        // 2FA
        $method = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : 'off';
        if ( ! in_array( $method, [ 'off', 'email', 'totp' ], true ) ) {
            $method = 'off';
        }
        $force = isset( $_POST['force_admins'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['force_admins'] ) ) ? '1' : '0';
        update_option( 'csdt_devtools_2fa_method', $method );
        update_option( 'csdt_devtools_2fa_force_admins', $force );

        // Session duration
        $valid_durations = [ 'default', '1', '7', '14', '30', '90', '365' ];
        $duration        = isset( $_POST['session_duration'] ) ? sanitize_key( wp_unslash( $_POST['session_duration'] ) ) : 'default';
        if ( ! in_array( $duration, $valid_durations, true ) ) {
            $duration = 'default';
        }
        update_option( 'csdt_devtools_session_duration', $duration );

        // Brute-force protection
        $bf_enabled  = isset( $_POST['bf_enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['bf_enabled'] ) ) ? '1' : '0';
        $bf_attempts = isset( $_POST['bf_attempts'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['bf_attempts'] ) ) : 5;
        $bf_lockout  = isset( $_POST['bf_lockout'] )  ? (int) sanitize_text_field( wp_unslash( $_POST['bf_lockout'] ) )  : 10;
        if ( $bf_attempts < 1 || $bf_attempts > 100 )   { $bf_attempts = 5; }
        if ( $bf_lockout  < 1 || $bf_lockout  > 1440 )  { $bf_lockout  = 10; }
        $bf_enum_protect       = isset( $_POST['bf_enum_protect'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['bf_enum_protect'] ) ) ? '1' : '0';
        $bf_auto_block         = isset( $_POST['bf_auto_block_threshold'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['bf_auto_block_threshold'] ) ) : 10;
        if ( $bf_auto_block < 0 || $bf_auto_block > 1000 ) { $bf_auto_block = 10; }
        update_option( 'csdt_devtools_brute_force_enabled',       $bf_enabled );
        update_option( 'csdt_devtools_brute_force_attempts',      (string) $bf_attempts );
        update_option( 'csdt_devtools_brute_force_lockout',       (string) $bf_lockout );
        update_option( 'csdt_devtools_enum_protect',              $bf_enum_protect );
        update_option( 'csdt_devtools_bf_auto_block_threshold',   (string) $bf_auto_block );

        $honeypot = isset( $_POST['honeypot_2fa_enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['honeypot_2fa_enabled'] ) ) ? '1' : '0';
        update_option( 'csdt_honeypot_2fa_enabled', $honeypot );

        // Grace logins
        $grace_logins = isset( $_POST['grace_logins'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['grace_logins'] ) ) : 0;
        if ( $grace_logins < 0 || $grace_logins > 10 ) { $grace_logins = 0; }
        update_option( 'csdt_devtools_2fa_grace_logins', (string) $grace_logins );

        // ntfy notification preferences.
        $ntfy_valid   = isset( $_POST['ntfy_login_valid_user'] )   && '1' === sanitize_text_field( wp_unslash( $_POST['ntfy_login_valid_user'] ) )   ? '1' : '0';
        $ntfy_invalid = isset( $_POST['ntfy_login_invalid_user'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['ntfy_login_invalid_user'] ) ) ? '1' : '0';
        update_option( 'csdt_ntfy_login_valid_user',   $ntfy_valid );
        update_option( 'csdt_ntfy_login_invalid_user', $ntfy_invalid );

        // Check for security control downgrades and fire ntfy if needed.
        $new_state = [
            'hide_enabled' => $hide,
            'bf_enabled'   => $bf_enabled,
            'force_2fa'    => $force,
            'tfa_method'   => $method,
            'enum_protect' => $bf_enum_protect,
        ];
        self::check_settings_downgrade( $old_state, $new_state );

        $new_url = $hide === '1' && $slug ? home_url( '/' . $slug . '/' ) : wp_login_url();
        wp_send_json_success( [ 'login_url' => $new_url ] );
    }

    /**
     * AJAX: generates a new TOTP secret and returns the QR code URL for setup.
     * Stores the secret as a pending (unconfirmed) user meta key.
     *
     * @since  1.9.4
     * @return void
     */
    public static function ajax_totp_setup_start(): void {
        check_ajax_referer( CloudScale_DevTools::LOGIN_NONCE, 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $user_id = get_current_user_id();
        $secret  = self::totp_generate_secret();

        // Store as pending until the user verifies their first code.
        update_user_meta( $user_id, 'csdt_devtools_totp_secret_pending', $secret );

        $email = wp_get_current_user()->user_email;
        $uri   = self::totp_provisioning_uri( $secret, $email );

        wp_send_json_success( [
            'otpauth' => $uri,
            'secret'  => $secret,
        ] );
    }

    /**
     * AJAX: verifies the first TOTP code to activate the pending secret.
     *
     * @since  1.9.4
     * @return void
     */
    public static function ajax_totp_setup_verify(): void {
        check_ajax_referer( CloudScale_DevTools::LOGIN_NONCE, 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $code    = isset( $_POST['code'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['code'] ) ) ) : '';
        $user_id = get_current_user_id();
        $secret  = get_user_meta( $user_id, 'csdt_devtools_totp_secret_pending', true );

        if ( ! $secret ) {
            wp_send_json_error( __( 'No pending setup found. Please start setup again.', 'cloudscale-devtools' ) );
        }
        if ( strlen( $code ) !== 6 ) {
            wp_send_json_error( __( 'Please enter a 6-digit code.', 'cloudscale-devtools' ) );
        }

        if ( ! self::totp_verify( $secret, $code ) ) {
            wp_send_json_error( __( 'Code incorrect. Check your app\'s time sync and try again.', 'cloudscale-devtools' ) );
        }

        // Activate: promote pending secret to live.
        update_user_meta( $user_id, 'csdt_devtools_totp_secret', $secret );
        update_user_meta( $user_id, 'csdt_devtools_totp_enabled', '1' );
        delete_user_meta( $user_id, 'csdt_devtools_totp_secret_pending' );

        // If user had email 2FA, disable it (TOTP is preferred).
        delete_user_meta( $user_id, 'csdt_devtools_2fa_email_enabled' );

        // Security state changed — destroy all other open sessions.
        wp_destroy_other_sessions();

        wp_send_json_success( [ 'message' => __( 'Authenticator app activated!', 'cloudscale-devtools' ) ] );
    }

    /**
     * AJAX: disables 2FA for the current user (email or TOTP).
     *
     * @since  1.9.4
     * @return void
     */
    public static function ajax_2fa_disable(): void {
        check_ajax_referer( CloudScale_DevTools::LOGIN_NONCE, 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $method  = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';
        $user_id = get_current_user_id();

        if ( $method === 'totp' ) {
            delete_user_meta( $user_id, 'csdt_devtools_totp_secret' );
            delete_user_meta( $user_id, 'csdt_devtools_totp_secret_pending' );
            update_user_meta( $user_id, 'csdt_devtools_totp_enabled', '0' );
        } elseif ( $method === 'email' ) {
            update_user_meta( $user_id, 'csdt_devtools_2fa_email_enabled', '0' );
            delete_user_meta( $user_id, 'csdt_devtools_email_verify_pending' );
        } else {
            wp_send_json_error( 'Unknown method.' );
        }

        // Security state changed — destroy all other open sessions.
        wp_destroy_other_sessions();

        wp_send_json_success( [ 'message' => __( '2FA disabled.', 'cloudscale-devtools' ) ] );
    }

    /**
     * AJAX: sends a verification email with a callback link.
     * Email 2FA is only activated once the user clicks the link (10-min TTL).
     * Reuses this handler for both first-enable and Resend.
     *
     * Pre-send diagnostics are sourced via the `cloudscale_email_diagnostics`
     * filter — the CloudScale Backup & Restore plugin hooks this when active,
     * providing port/MTA/relay checks. If nothing hooks the filter we fall back
     * to wp_mail_failed to surface the actual SMTP transport error instead.
     *
     * @since  1.9.4
     * @return void
     */
    public static function ajax_email_2fa_enable(): void {
        check_ajax_referer( CloudScale_DevTools::LOGIN_NONCE, 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $user    = wp_get_current_user();
        $user_id = $user->ID;

        // ── Pre-send diagnostics (port / MTA / relay) ─────────────────────
        // Returns [ 'warning' => string, 'fatal' => bool ] or null when no
        // plugin has registered diagnostics for this environment.
        // CloudScale Backup & Restore hooks this filter when active.
        $diag    = apply_filters( 'cloudscale_email_diagnostics', null );
        $warning = is_array( $diag ) ? (string) ( $diag['warning'] ?? '' ) : '';
        $fatal   = is_array( $diag ) && ! empty( $diag['fatal'] );

        if ( $fatal ) {
            wp_send_json_error( [
                'message'      => __( 'Email cannot be sent from this server — see warning below.', 'cloudscale-devtools' ),
                'port_warning' => $warning,
            ] );
        }

        // ── Capture the actual SMTP transport error via wp_mail_failed ─────
        // Used when no external diagnostic plugin is active; gives the real
        // error string rather than a guessed port-probe message.
        $transport_error = '';
        $on_mail_failed  = static function ( \WP_Error $err ) use ( &$transport_error ): void {
            $transport_error = $err->get_error_message();
        };
        add_action( 'wp_mail_failed', $on_mail_failed );

        // ── Generate a single-use verification token (1-hour TTL) ────────
        $token     = wp_generate_password( 32, false, false );
        $transient = CloudScale_DevTools::EMAIL_VERIFY_TRANSIENT . $token;
        set_transient( $transient, [ 'user_id' => $user_id ], 3600 );
        update_user_meta( $user_id, 'csdt_devtools_email_verify_pending', '1' );

        $callback = add_query_arg(
            [ 'csdt_devtools_email_verify' => rawurlencode( $token ) ],
            admin_url( 'tools.php?page=' . CloudScale_DevTools::TOOLS_SLUG . '&tab=login' )
        );

        $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        add_filter( 'wp_mail_content_type', [ __CLASS__, 'email_content_type_html' ] );
        $sent = wp_mail(
            $user->user_email,
            sprintf( '[%s] Verify your email for 2FA', $site ),
            self::email_html_verify( $user->display_name, $site, $callback )
        );
        remove_filter( 'wp_mail_content_type', [ __CLASS__, 'email_content_type_html' ] );

        remove_action( 'wp_mail_failed', $on_mail_failed );

        if ( ! $sent ) {
            delete_transient( $transient );
            delete_user_meta( $user_id, 'csdt_devtools_email_verify_pending' );
            // Surface the real SMTP error if captured, otherwise the warning from diagnostics.
            $detail = $transport_error ?: $warning ?: __( 'Check your WordPress mail configuration.', 'cloudscale-devtools' );
            // Flag when SMTP isn't configured so the UI can prompt the user to set it up.
            $smtp_not_configured = get_option( 'csdt_devtools_smtp_enabled', '0' ) !== '1'
                || '' === trim( (string) get_option( 'csdt_devtools_smtp_host', '' ) );
            wp_send_json_error( [
                'message'             => sprintf( __( 'Email not sent: %s', 'cloudscale-devtools' ), $detail ),
                'port_warning'        => $warning,
                'smtp_not_configured' => $smtp_not_configured,
            ] );
        }

        $msg = sprintf(
            /* translators: %s: email address */
            __( 'Verification email sent to %s. Click the link to activate 2FA.', 'cloudscale-devtools' ),
            $user->user_email
        );

        wp_send_json_success( [
            'message'      => $msg . ( $warning ? ' ' . __( '(See warning below.)', 'cloudscale-devtools' ) : '' ),
            'port_warning' => $warning,
        ] );
    }

    /** Sets wp_mail content type to HTML (used temporarily around branded emails). */
    public static function email_content_type_html(): string {
        return 'text/html';
    }

    /**
     * Returns the branded HTML wrapper used by all CS security emails.
     *
     * @param string $inner_html Body content (already escaped).
     * @return string Full HTML document.
     */
    private static function email_html_wrap( string $inner_html ): string {
        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CloudScale</title>
</head>
<body style="margin:0;padding:0;background:#f0f2f5;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f0f2f5;">
  <tr><td align="center" style="padding:40px 16px;">
    <table width="560" cellpadding="0" cellspacing="0" role="presentation" style="max-width:560px;width:100%;">

      <!-- Header -->
      <tr>
        <td style="background:linear-gradient(135deg,#1a1f3c 0%,#2d3561 100%);border-radius:12px 12px 0 0;padding:28px 36px;text-align:center;">
          <span style="font-size:22px;font-weight:700;color:#ffffff;letter-spacing:-0.3px;">
            &#x26A1; CloudScale
          </span>
          <div style="font-size:11px;color:#a0aec0;margin-top:4px;letter-spacing:0.5px;text-transform:uppercase;">Code &amp; Security</div>
        </td>
      </tr>

      <!-- Body -->
      <tr>
        <td style="background:#ffffff;padding:36px 36px 32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">
          ' . $inner_html . '
        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style="background:#f8fafc;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;padding:18px 36px;text-align:center;">
          <p style="margin:0;font-size:12px;color:#94a3b8;">
            You\'re receiving this because you have an account on this site.<br>
            If you didn\'t request this, you can safely ignore it.
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>';
    }

    /**
     * HTML email body for the 2FA one-time login code.
     *
     * @param string $display_name User's display name.
     * @param string $site         Site name (already decoded).
     * @param string $otp          6-digit code.
     * @return string Full HTML email.
     */
    private static function email_html_otp( string $display_name, string $site, string $otp ): string {
        $inner = '
          <p style="margin:0 0 20px;font-size:15px;color:#1a202c;">Hi ' . esc_html( $display_name ) . ',</p>
          <p style="margin:0 0 24px;font-size:15px;color:#4a5568;line-height:1.6;">
            Your one-time login code for <strong>' . esc_html( $site ) . '</strong>:
          </p>

          <!-- Code box -->
          <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:28px;">
            <tr>
              <td align="center" style="background:#f0f4ff;border:2px solid #c7d2fe;border-radius:10px;padding:20px 16px;">
                <span style="font-family:\'Courier New\',Courier,monospace;font-size:38px;font-weight:700;letter-spacing:10px;color:#3730a3;">' . esc_html( $otp ) . '</span>
              </td>
            </tr>
          </table>

          <p style="margin:0 0 20px;font-size:13px;color:#718096;text-align:center;">
            &#x23F0; This code expires in <strong>10 minutes</strong>.
          </p>
          <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
          <p style="margin:0;font-size:12px;color:#e53e3e;">
            &#x26A0;&#xFE0F; If you did not attempt to log in, please <strong>change your password immediately</strong>.
          </p>';

        return self::email_html_wrap( $inner );
    }

    /**
     * HTML email body for the email 2FA verification link.
     *
     * @param string $display_name User's display name.
     * @param string $site         Site name (already decoded).
     * @param string $verify_url   Full verification URL.
     * @return string Full HTML email.
     */
    private static function email_html_verify( string $display_name, string $site, string $verify_url ): string {
        $inner = '
          <p style="margin:0 0 20px;font-size:15px;color:#1a202c;">Hi ' . esc_html( $display_name ) . ',</p>
          <p style="margin:0 0 24px;font-size:15px;color:#4a5568;line-height:1.6;">
            You requested to enable <strong>Email Two-Factor Authentication</strong> on <strong>' . esc_html( $site ) . '</strong>.
            Click the button below to verify your email address and activate 2FA.
          </p>

          <!-- CTA button -->
          <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:28px;">
            <tr>
              <td align="center">
                <a href="' . esc_url( $verify_url ) . '"
                   style="display:inline-block;background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%);color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;padding:14px 36px;border-radius:8px;letter-spacing:0.2px;">
                  &#x2714;&#xFE0F; Verify Email &amp; Activate 2FA
                </a>
              </td>
            </tr>
          </table>

          <p style="margin:0 0 12px;font-size:13px;color:#718096;text-align:center;">
            &#x23F0; This link expires in <strong>1 hour</strong>.
          </p>
          <p style="margin:0;font-size:12px;color:#a0aec0;text-align:center;word-break:break-all;">
            Or copy this URL: <a href="' . esc_url( $verify_url ) . '" style="color:#6366f1;">' . esc_html( $verify_url ) . '</a>
          </p>';

        return self::email_html_wrap( $inner );
    }

    /**
     * Handles the email verification callback link.
     * Runs on admin_init — activates email 2FA when a valid token is present.
     *
     * @since  1.9.4
     * @return void
     */
    public static function email_2fa_confirm_check(): void {
        if ( ! isset( $_GET['csdt_devtools_email_verify'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        if ( ! is_user_logged_in() ) {
            return;
        }

        $token     = sanitize_text_field( wp_unslash( $_GET['csdt_devtools_email_verify'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $transient = CloudScale_DevTools::EMAIL_VERIFY_TRANSIENT . $token;
        $data = get_transient( $transient );

        if ( ! $data || empty( $data['user_id'] ) ) {
            // Expired or invalid — redirect back without activating.
            wp_safe_redirect( admin_url( 'tools.php?page=' . CloudScale_DevTools::TOOLS_SLUG . '&tab=login&email_verify_expired=1' ) );
            exit;
        }

        $user_id = (int) $data['user_id'];

        // Verify the token belongs to the currently logged-in user.
        if ( $user_id !== get_current_user_id() ) {
            wp_safe_redirect( admin_url( 'tools.php?page=' . CloudScale_DevTools::TOOLS_SLUG . '&tab=login' ) );
            exit;
        }

        // If already activated on a previous click (e.g. email client prefetch), just redirect to success.
        if ( ! empty( $data['activated'] ) ) {
            wp_safe_redirect( admin_url( 'tools.php?page=' . CloudScale_DevTools::TOOLS_SLUG . '&tab=login&email_2fa_activated=1' ) );
            exit;
        }

        // Activate email 2FA.
        update_user_meta( $user_id, 'csdt_devtools_2fa_email_enabled', '1' );
        delete_user_meta( $user_id, 'csdt_devtools_email_verify_pending' );

        // Mark the transient as used (keep it alive for 10 min so re-clicks show success, not "expired").
        set_transient( $transient, [ 'user_id' => $user_id, 'activated' => true ], 600 );

        // Security state changed — destroy all other open sessions for this user.
        wp_destroy_other_sessions();

        wp_safe_redirect( admin_url( 'tools.php?page=' . CloudScale_DevTools::TOOLS_SLUG . '&tab=login&email_2fa_activated=1' ) );
        exit;
    }

    /**
     * Destroys all other sessions when a user resets their password.
     *
     * @since  1.9.4
     * @param  \WP_User $user  The user whose password was reset.
     * @return void
     */
    public static function on_password_reset( \WP_User $user ): void {
        // Destroy all sessions so the newly-reset password must be used everywhere.
        WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
    }

    /**
     * Destroys all other sessions when a user's email or password changes.
     *
     * @since  1.9.4
     * @param  int       $user_id      Updated user ID.
     * @param  \WP_User  $old_userdata User data before the update.
     * @return void
     */
    public static function on_profile_update( int $user_id, \WP_User $old_userdata ): void {
        $new_user = get_userdata( $user_id );
        if ( ! $new_user ) {
            return;
        }
        $email_changed    = $old_userdata->user_email !== $new_user->user_email;
        $password_changed = $old_userdata->user_pass  !== $new_user->user_pass;

        if ( $email_changed || $password_changed ) {
            // If the currently-logged-in user changed their own account, keep their
            // current session alive; destroy all others.  For admin-changed accounts
            // (different user_id) destroy every session outright.
            if ( get_current_user_id() === $user_id ) {
                wp_destroy_other_sessions();
            } else {
                WP_Session_Tokens::get_instance( $user_id )->destroy_all();
            }
        }
    }

    // ── Honeypot 2FA — fake 2FA screen on invalid credentials ────────────────
    //
    // When enabled, any failed login (wrong username OR wrong password) redirects
    // to a fake 6-digit PIN screen instead of showing a login error. Whatever the
    // attacker types is silently rejected and they land on the dark "being watched"
    // screen. Real accounts with 2FA are unaffected — they go through the real 2FA
    // flow before ever reaching wp_login_failed.

    private const HONEYPOT_ACTION = 'csdt_honeypot_2fa';

    /**
     * Hooked to `wp_login_failed`. Redirects failed logins to the honeypot 2FA screen.
     */
    public static function honeypot_redirect( string $username ): void {
        if ( get_option( 'csdt_honeypot_2fa_enabled', '0' ) !== '1' ) {
            return;
        }
        // Don't double-intercept real 2FA flows.
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $action === 'csdt_devtools_2fa' || $action === self::HONEYPOT_ACTION ) {
            return;
        }
        $redirect_to = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $url = add_query_arg( [
            'action'       => self::HONEYPOT_ACTION,
            'redirect_to'  => rawurlencode( $redirect_to ),
        ], wp_login_url() );
        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Hooked to `login_init`. Handles GET (display) and POST (always-fail) for the honeypot screen.
     */
    public static function honeypot_handle(): void {
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $action !== self::HONEYPOT_ACTION ) {
            return;
        }

        // POST — attacker submitted the fake PIN.
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $ip = self::get_client_ip();
            $cc = $ip ? CSDT_Geo::get_country( $ip ) : '';
            self::record_security_event( 'attack', 'Honeypot 2FA triggered', "IP: {$ip}" . ( $cc ? " · {$cc}" : '' ) );
            // Show the dark "being watched" screen — same HTML as the blocked probe screen.
            self::honeypot_render_watched( $ip );
            exit;
        }

        // GET — show the fake 2FA PIN entry form.
        self::honeypot_render_pin_form();
        exit;
    }

    /** Render the fake 6-digit PIN form using WP's login chrome. */
    private static function honeypot_render_pin_form(): void {
        login_header( __( 'Two-Factor Authentication', 'cloudscale-devtools' ), '', null );
        ?>
        <form name="honeypot_2fa_form" id="honeypot_2fa_form" action="" method="post">
            <p style="text-align:center;font-size:48px;margin:0 0 8px">📱</p>
            <p style="text-align:center;margin:0 0 20px;color:#555;font-size:13px;line-height:1.5">
                <?php esc_html_e( 'Enter the 6-digit code from your authenticator app to continue.', 'cloudscale-devtools' ); ?>
            </p>
            <p>
                <label for="csdt_honeypot_pin"><?php esc_html_e( 'Authentication Code', 'cloudscale-devtools' ); ?></label>
                <input type="text" name="csdt_honeypot_pin" id="csdt_honeypot_pin" class="input"
                       value="" size="20" maxlength="6"
                       inputmode="numeric" autocomplete="one-time-code"
                       placeholder="000000" autofocus
                       style="text-align:center;font-size:22px;letter-spacing:6px">
            </p>
            <input type="hidden" name="action" value="<?php echo esc_attr( self::HONEYPOT_ACTION ); ?>">
            <?php if ( isset( $_GET['redirect_to'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
            <?php endif; ?>
            <p class="submit">
                <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Verify', 'cloudscale-devtools' ); ?>">
            </p>
        </form>
        <p style="text-align:center;margin-top:16px">
            <a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( '← Back to login', 'cloudscale-devtools' ); ?></a>
        </p>
        <?php
        login_footer();
    }

    /** Render the dark "your site is being watched" terminal screen. */
    private static function honeypot_render_watched( string $ip ): void {
        $site_name = esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
        nocache_headers();
        status_header( 403 );
        header( 'Content-Type: text/html; charset=UTF-8' );
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<!DOCTYPE html><html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Access Protected</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0f172a;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
.card{text-align:center;max-width:460px;padding:48px 40px;background:#1e293b;border:1px solid #334155;border-radius:16px;box-shadow:0 25px 60px rgba(0,0,0,.5);}
.shield{width:80px;height:80px;margin:0 auto 28px;}
.badge{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#f87171;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);border-radius:20px;padding:4px 12px;margin-bottom:20px;}
h1{font-size:22px;font-weight:700;color:#f1f5f9;margin-bottom:8px;line-height:1.3;}
.site-name{font-size:13px;color:#94a3b8;margin-bottom:28px;}
.divider{height:1px;background:#475569;margin:24px 0;}
.protected-by{font-size:12px;color:#94a3b8;margin-bottom:10px;text-transform:uppercase;letter-spacing:.08em;}
.brand{font-size:17px;font-weight:700;color:#f1f5f9;text-decoration:none;}
.brand span{color:#ef4444;}
.tracking{margin-top:20px;font-size:12px;color:#cbd5e1;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);border-radius:6px;padding:10px 14px;line-height:1.7;}
.tracking strong{color:#fca5a5;}
</style>
</head>
<body>
<div class="card">
  <svg class="shield" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
    <defs><linearGradient id="sg" x1="40" y1="8" x2="40" y2="72" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="#f87171"/><stop offset="100%" stop-color="#b91c1c"/>
    </linearGradient></defs>
    <path d="M40 8 L68 20 L68 42 C68 57 55 68 40 72 C25 68 12 57 12 42 L12 20 Z" fill="url(#sg)" opacity=".15"/>
    <path d="M40 8 L68 20 L68 42 C68 57 55 68 40 72 C25 68 12 57 12 42 L12 20 Z" stroke="#ef4444" stroke-width="2" fill="none"/>
    <path d="M30 40 L37 47 L52 32" stroke="#f87171" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
  </svg>
  <div class="badge">Security Alert</div>
  <h1>Unauthorised access attempt</h1>
  <p class="site-name">' . $site_name . '</p>
  <div class="divider"></div>
  <p class="protected-by">This site is secured by</p>
  <a href="https://andrewbaker.ninja" target="_blank" rel="noopener noreferrer" class="brand">
    CloudScale <span>Cyber</span> and Devtools
  </a>
  <div class="tracking">
    &#x26A0; This authentication attempt has been logged.<br>
    <strong>IP ' . esc_html( $ip ) . ' is now being tracked.</strong>
  </div>
</div>
</body></html>';
        // phpcs:enable
    }

    // ── IP Blocklist ─────────────────────────────────────────────────────────

    /** Enforce the blocklist early — fires on `parse_request` priority 1. */
    public static function enforce_ip_blocklist(): void {
        if ( is_admin() ) { return; }
        $list = get_option( 'csdt_ip_blocklist', [] );
        if ( empty( $list ) || ! is_array( $list ) ) { return; }
        $ip = self::get_client_ip();
        if ( isset( $list[ $ip ] ) ) {
            status_header( 403 );
            nocache_headers();
            exit( 'Access denied.' );
        }
    }

    public static function ajax_ip_block(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }
        $ip     = sanitize_text_field( wp_unslash( $_POST['ip'] ?? '' ) );
        $reason = sanitize_text_field( wp_unslash( $_POST['reason'] ?? 'Manual block' ) );
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) { wp_send_json_error( 'Invalid IP address.' ); }
        $list         = get_option( 'csdt_ip_blocklist', [] );
        if ( ! is_array( $list ) ) { $list = []; }
        $list[ $ip ]  = [ 'reason' => $reason, 'blocked_at' => time() ];
        update_option( 'csdt_ip_blocklist', $list, false );
        wp_send_json_success( [ 'ip' => $ip ] );
    }

    public static function ajax_ip_unblock(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }
        $ip   = sanitize_text_field( wp_unslash( $_POST['ip'] ?? '' ) );
        $list = get_option( 'csdt_ip_blocklist', [] );
        if ( is_array( $list ) ) { unset( $list[ $ip ] ); update_option( 'csdt_ip_blocklist', $list, false ); }
        wp_send_json_success( [ 'ip' => $ip ] );
    }

    /** Returns the real client IP, honouring Cloudflare's CF-Connecting-IP header. */
    private static function get_client_ip(): string {
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
        }
        return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    }

    // ─── Security event log ──────────────────────────────────────────────

    /**
     * Append an entry to the security events log (shown on the dashboard widget).
     * Capped at 20 entries, rolling 30-day window.
     *
     * @param string $type    'downgrade' | 'attack' | 'rest_fail'
     * @param string $title   Short description shown in the widget.
     * @param string $detail  Extra context (username, IP, etc.)
     */
    public static function record_security_event( string $type, string $title, string $detail = '' ): void {
        $events = get_option( 'csdt_security_events', [] );
        if ( ! is_array( $events ) ) {
            $events = [];
        }
        $cutoff = time() - 30 * DAY_IN_SECONDS;
        $events = array_values( array_filter( $events, fn( $e ) => ( $e['time'] ?? 0 ) >= $cutoff ) );
        if ( count( $events ) >= 20 ) {
            array_shift( $events );
        }
        $events[] = [
            'time'   => time(),
            'type'   => $type,
            'title'  => $title,
            'detail' => $detail,
        ];
        update_option( 'csdt_security_events', $events, false );
    }

    // ─── ntfy helper ─────────────────────────────────────────────────────

    /**
     * Fire a ntfy.sh push notification using the shared scan-schedule credentials.
     *
     * @param string $title   Notification title (shown bold on device).
     * @param string $body    Notification body text.
     * @param string $priority  urgent|high|default|low|min
     * @param string $tags    Comma-separated ntfy tag emoji names.
     */
    public static function send_ntfy( string $title, string $body, string $priority = 'high', string $tags = 'warning' ): void {
        $ntfy_url = (string) get_option( 'csdt_scan_schedule_ntfy_url', '' );
        if ( ! $ntfy_url ) {
            return;
        }
        $site    = (string) get_option( 'siteurl', '' );
        $host    = $site ? wp_parse_url( $site, PHP_URL_HOST ) : '';
        $headers = [
            'Title'    => 'CS > Cyber: ' . ( $host ?: '' ) . ': ' . $title,
            'Priority' => $priority,
            'Tags'     => $tags,
        ];
        $ntfy_tok = (string) get_option( 'csdt_scan_schedule_ntfy_token', '' );
        if ( $ntfy_tok ) {
            $headers['Authorization'] = 'Bearer ' . $ntfy_tok;
        }
        wp_remote_post( $ntfy_url, [
            'timeout'    => 8,
            'blocking'   => false,
            'headers'    => $headers,
            'body'       => $body,
        ] );
    }

    // ─── Hook: ntfy on ANY write to a security-critical option ──────────
    // Catches WP-CLI, direct DB edits, or any path that bypasses ajax_login_save.

    private const SECURITY_OPTION_ALERTS = [
        'csdt_devtools_login_hide_enabled' => [ 'off_val' => '0', 'label' => 'Hide Login URL has been DISABLED — wp-login.php is now publicly accessible.' ],
        'csdt_devtools_brute_force_enabled' => [ 'off_val' => '0', 'label' => 'Brute-force account lockout has been DISABLED.' ],
        'csdt_devtools_2fa_force_admins'    => [ 'off_val' => '0', 'label' => 'Force 2FA for administrators has been TURNED OFF.' ],
        'csdt_devtools_enum_protect'        => [ 'off_val' => '0', 'label' => 'Account enumeration protection has been DISABLED.' ],
    ];

    public static function on_option_updated( string $option, $old_value, $new_value ): void {
        if ( ! isset( self::SECURITY_OPTION_ALERTS[ $option ] ) ) {
            return;
        }
        $cfg = self::SECURITY_OPTION_ALERTS[ $option ];
        // Only alert when the value is being switched OFF (old was on, new is off).
        if ( (string) $old_value !== $cfg['off_val'] && (string) $new_value === $cfg['off_val'] ) {
            $ip  = self::get_client_ip();
            $who = is_user_logged_in() ? wp_get_current_user()->user_login : 'system/cli';
            self::record_security_event( 'downgrade', $cfg['label'], "By: {$who} from {$ip}" );
            self::send_ntfy( 'Security control downgraded', $cfg['label'] . "\n\nChanged by: {$who}\nIP: {$ip}", 'urgent', 'rotating_light,warning' );
        }
    }

    // ─── Hook: ntfy on failed login attempt ──────────────────────────────

    /**
     * Hooked to `wp_login_failed`. Fires a ntfy alert based on whether the
     * username is a known WordPress account (valid) or not (enumeration attempt).
     */
    public static function on_login_failed( string $username ): void {
        // Skip REST API / application-password failures — those are handled by
        // on_rest_auth_failed() via application_password_failed_authentication.
        // Both hooks fire for the same REST request, so we'd send two ntfys.
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }
        // Also skip if this looks like a Basic-Auth REST call (REST_REQUEST may not
        // be defined yet when application_password_failed_authentication fires first).
        $auth_hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' );
        if ( $auth_hdr && stripos( $auth_hdr, 'Basic ' ) === 0 ) {
            return;
        }

        $notify_valid   = get_option( 'csdt_ntfy_login_valid_user',   '0' ) === '1';
        $notify_invalid = get_option( 'csdt_ntfy_login_invalid_user', '0' ) === '1';

        $is_valid_user = (bool) get_user_by( 'login', $username );
        if ( ! $is_valid_user ) {
            $is_valid_user = (bool) get_user_by( 'email', $username );
        }

        $ip = self::get_client_ip();

        // Always record valid-username attacks to the security event log — this
        // powers the "accounts targeted" count in the widget regardless of ntfy settings.
        if ( $is_valid_user ) {
            self::record_security_event(
                'attack',
                "Login attack on '{$username}'",
                "IP: {$ip}"
            );
        }

        // ntfy alerts — only fire if the relevant option is enabled.
        if ( ! $notify_valid && ! $notify_invalid ) {
            return;
        }
        if ( $is_valid_user && ! $notify_valid ) {
            return;
        }
        if ( ! $is_valid_user && ! $notify_invalid ) {
            return;
        }

        // Never reveal in the ntfy whether the username is valid or not —
        // that would confirm account existence to anyone who can read the notification.
        $priority = $is_valid_user ? 'high' : 'default';
        $tags     = $is_valid_user ? 'rotating_light' : 'warning';

        self::send_ntfy(
            'Failed login attempt',
            "Username: {$username}\nIP: {$ip}" . ( $cc ? " · {$cc}" : '' ),
            $priority,
            $tags
        );
    }

    // ─── Hook: ntfy on REST API application-password failure ─────────────

    /**
     * Hooked to `application_password_failed_authentication`.
     * Fires when a REST API request uses bad application-password credentials.
     */
    public static function on_rest_auth_failed( \WP_Error $error ): void {
        $ip  = self::get_client_ip();
        $cc  = $ip ? CSDT_Geo::get_country( $ip ) : '';

        // ── Gather every useful signal available at this hook ──────────────

        // Path probed — strip query string to avoid leaking tokens.
        $raw_uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $path     = strtok( $raw_uri, '?' ) ?: '/wp-json/';

        // HTTP method.
        $method   = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'POST';

        // Auth type: Basic vs Bearer vs other.
        $auth_hdr = isset( $_SERVER['HTTP_AUTHORIZATION'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) )
            : ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) )
                : '' );
        if ( stripos( $auth_hdr, 'Basic ' ) === 0 ) {
            $auth_type = 'Basic Auth (Application Password)';
        } elseif ( stripos( $auth_hdr, 'Bearer ' ) === 0 ) {
            $auth_type = 'Bearer Token';
        } else {
            $auth_type = $auth_hdr ? 'Unknown (' . substr( $auth_hdr, 0, 10 ) . '…)' : 'Basic Auth';
        }

        // Username tried — available in PHP_AUTH_USER before WP resets it.
        $tried_user = isset( $_SERVER['PHP_AUTH_USER'] )
            ? sanitize_user( wp_unslash( $_SERVER['PHP_AUTH_USER'] ), true )
            : '';

        // Generic reason only — never reveal whether the username exists or not.
        // Suppressing the WP error code (incorrect_password vs invalid_username)
        // prevents account enumeration via the notification channel.
        $reason = 'Authentication failed';

        // User agent (truncated).
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] )
            ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 80 )
            : '—';

        // ── Record + alert ─────────────────────────────────────────────────

        $detail = "IP: {$ip}" . ( $cc ? " · {$cc}" : '' ) . ( $tried_user ? " · user:{$tried_user}" : '' );
        self::record_security_event( 'api_attack', 'REST API auth failure', $detail );

        $body  = "🔌 REST API brute-force attempt\n";
        $body .= "─────────────────────────\n";
        $body .= "Path:      {$method} {$path}\n";
        $body .= "Auth type: {$auth_type}\n";
        $body .= "Username:  " . ( $tried_user ?: '(none)' ) . "\n";
        $body .= "IP:        {$ip}" . ( $cc ? " · {$cc}" : '' ) . "\n";
        $body .= "Reason:    {$reason}\n";
        $body .= "Agent:     {$ua}";

        if ( get_option( 'csdt_ntfy_rest_auth_fail', '1' ) === '1' ) {
            self::send_ntfy( 'REST API auth failure', $body, 'high', 'rotating_light' );
        }
    }

    /**
     * Hooked to `rest_authentication_errors` at priority 1 (early).
     * Returns a 400 Bad Request immediately when a Basic Auth header is present
     * but contains no username — this is a malformed request from a bot/scanner.
     * A legitimate client always provides a username.
     */
    public static function rest_reject_empty_basic_auth( $result ) {
        // Only act if no authentication has been established yet.
        if ( $result !== null ) {
            return $result;
        }
        $auth = isset( $_SERVER['HTTP_AUTHORIZATION'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) )
            : ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) )
                : '' );
        if ( ! $auth || stripos( $auth, 'Basic ' ) !== 0 ) {
            return $result; // Not Basic Auth — leave alone.
        }
        // Decode Basic Auth credentials.
        $decoded  = base64_decode( substr( $auth, 6 ), true );
        $username = $decoded ? (string) strstr( $decoded, ':', true ) : '';

        // 1. Empty username — malformed request from a bot.
        if ( '' === $username ) {
            return new \WP_Error( 'rest_bad_request', 'Malformed credentials.', [ 'status' => 400 ] );
        }

        // 2. Username too long — credential stuffing / buffer overflow attempt.
        //    WP usernames have a practical limit of ~60 chars; reject anything over 100.
        if ( strlen( $username ) > 100 ) {
            return new \WP_Error( 'rest_bad_request', 'Malformed credentials.', [ 'status' => 400 ] );
        }

        // 3. Invalid characters — WP usernames are alphanumeric + limited punctuation.
        //    Reject control characters, null bytes, angle brackets, SQL-injection fragments.
        if ( preg_match( '/[\x00-\x1f\x7f<>\'";\\\\]/', $username ) ) {
            return new \WP_Error( 'rest_bad_request', 'Malformed credentials.', [ 'status' => 400 ] );
        }

        return $result;
    }

    /**
     * Hooked to `rest_authentication_errors` at priority 99.
     * Replaces WP's default credential-revealing error ("Unknown username",
     * "The provided password is an application password") with a generic 401
     * so attackers cannot enumerate valid usernames via the REST API.
     */
    public static function rest_generic_auth_error( $result ) {
        // Only replace WP's own auth errors — leave other errors (e.g. nonce failures) untouched.
        if ( is_wp_error( $result ) ) {
            $reveal_codes = [ 'invalid_username', 'invalid_email', 'incorrect_password',
                              'application_passwords_disabled', 'application_passwords_disabled_for_user' ];
            foreach ( $reveal_codes as $code ) {
                if ( $result->get_error_message( $code ) ) {
                    return new \WP_Error(
                        'rest_forbidden',
                        'Authentication failed.',
                        [ 'status' => 401 ]
                    );
                }
            }
        }
        return $result;
    }

    // ─── Hook: ntfy on security control downgrade ────────────────────────

    /**
     * Called from ajax_login_save() before writing new settings.
     * Fires ntfy for any security control that is being turned off or weakened.
     */
    private static function check_settings_downgrade( array $old, array $new ): void {
        $alerts = [];

        // Hide Login disabled.
        if ( $old['hide_enabled'] === '1' && $new['hide_enabled'] === '0' ) {
            $alerts[] = 'Hide Login URL has been DISABLED — wp-login.php is now publicly accessible.';
        }

        // Brute-force protection disabled.
        if ( $old['bf_enabled'] === '1' && $new['bf_enabled'] === '0' ) {
            $alerts[] = 'Brute-force account lockout has been DISABLED.';
        }

        // Force 2FA turned off.
        if ( $old['force_2fa'] === '1' && $new['force_2fa'] === '0' ) {
            $alerts[] = 'Force 2FA for administrators has been TURNED OFF.';
        }

        // 2FA method turned off.
        if ( $old['tfa_method'] !== 'off' && $new['tfa_method'] === 'off' ) {
            $alerts[] = 'Site-wide 2FA method has been set to OFF (was: ' . $old['tfa_method'] . ').';
        }

        // Account enumeration protection disabled.
        if ( $old['enum_protect'] === '1' && $new['enum_protect'] === '0' ) {
            $alerts[] = 'Account enumeration protection has been DISABLED.';
        }

        if ( empty( $alerts ) ) {
            return;
        }

        $who  = wp_get_current_user();
        $name = $who && $who->exists() ? $who->user_login : 'unknown';
        $ip   = self::get_client_ip();
        $body = implode( "\n", $alerts ) . "\n\nChanged by: {$name}\nIP: {$ip}";

        // Record every downgrade to the security event log for the widget.
        foreach ( $alerts as $alert ) {
            self::record_security_event( 'downgrade', $alert, "By: {$name} from {$ip}" );
        }

        self::send_ntfy( 'Security control downgraded', $body, 'urgent', 'rotating_light,warning' );
    }

}
