<?php
/**
 * Custom 404 page with games and hi-score leaderboard.
 *
 * @package CloudScale_DevTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CSDT_Custom_404 {


    /** Returns the 12 built-in colour scheme definitions for the 404 page. */
    public static function get_404_schemes(): array {
        return [
            'ocean'    => [ 'name' => 'Ocean',    'bg1' => '#cce9fb', 'bg2' => '#a8d8f0', 'acc' => '#f57c00', 'da' => '#e65100', 'text' => '#0d2a4a', 'card' => 'rgba(255,255,255,0.45)', 'dm' => false ],
            'midnight' => [ 'name' => 'Midnight', 'bg1' => '#0f172a', 'bg2' => '#1e293b', 'acc' => '#60a5fa', 'da' => '#3b82f6', 'text' => '#e2e8f0', 'card' => 'rgba(15,23,42,0.65)',  'dm' => true  ],
            'forest'   => [ 'name' => 'Forest',   'bg1' => '#d1fae5', 'bg2' => '#a7f3d0', 'acc' => '#059669', 'da' => '#047857', 'text' => '#064e3b', 'card' => 'rgba(255,255,255,0.45)', 'dm' => false ],
            'sunset'   => [ 'name' => 'Sunset',   'bg1' => '#fff1e6', 'bg2' => '#fde68a', 'acc' => '#ea580c', 'da' => '#c2410c', 'text' => '#7c2d12', 'card' => 'rgba(255,255,255,0.45)', 'dm' => false ],
            'slate'    => [ 'name' => 'Slate',    'bg1' => '#e2e8f0', 'bg2' => '#cbd5e1', 'acc' => '#7c3aed', 'da' => '#6d28d9', 'text' => '#1e293b', 'card' => 'rgba(255,255,255,0.45)', 'dm' => false ],
            'rose'     => [ 'name' => 'Rose',     'bg1' => '#fff1f2', 'bg2' => '#fecdd3', 'acc' => '#e11d48', 'da' => '#be123c', 'text' => '#881337', 'card' => 'rgba(255,255,255,0.45)', 'dm' => false ],
            'emerald'  => [ 'name' => 'Emerald',  'bg1' => '#ecfdf5', 'bg2' => '#d1fae5', 'acc' => '#d97706', 'da' => '#b45309', 'text' => '#064e3b', 'card' => 'rgba(255,255,255,0.45)', 'dm' => false ],
            'violet'   => [ 'name' => 'Violet',   'bg1' => '#1e1b4b', 'bg2' => '#312e81', 'acc' => '#a78bfa', 'da' => '#7c3aed', 'text' => '#ede9fe', 'card' => 'rgba(49,46,129,0.5)',   'dm' => true  ],
            'charcoal' => [ 'name' => 'Charcoal', 'bg1' => '#1c1c1e', 'bg2' => '#2c2c2e', 'acc' => '#f57c00', 'da' => '#e65100', 'text' => '#e5e5ea', 'card' => 'rgba(44,44,46,0.6)',    'dm' => true  ],
            'arctic'   => [ 'name' => 'Arctic',   'bg1' => '#f0fdfa', 'bg2' => '#ccfbf1', 'acc' => '#0d9488', 'da' => '#0f766e', 'text' => '#134e4a', 'card' => 'rgba(255,255,255,0.45)', 'dm' => false ],
            'copper'   => [ 'name' => 'Copper',   'bg1' => '#fdf6ec', 'bg2' => '#fde8c8', 'acc' => '#b45309', 'da' => '#92400e', 'text' => '#451a03', 'card' => 'rgba(255,255,255,0.45)', 'dm' => false ],
            'cosmic'   => [ 'name' => 'Cosmic',   'bg1' => '#0a0015', 'bg2' => '#1a0033', 'acc' => '#e879f9', 'da' => '#d946ef', 'text' => '#fae8ff', 'card' => 'rgba(26,0,51,0.5)',     'dm' => true  ],
        ];
    }

    /** Builds inline CSS overrides for the chosen colour scheme (empty string for default). */
    public static function get_404_scheme_css( string $key ): string {
        $schemes = self::get_404_schemes();
        if ( ! isset( $schemes[ $key ] ) || 'ocean' === $key ) {
            return '';
        }
        $s    = $schemes[ $key ];
        $bg1  = esc_attr( $s['bg1'] );
        $bg2  = esc_attr( $s['bg2'] );
        $acc  = esc_attr( $s['acc'] );
        $da   = esc_attr( $s['da'] );
        $text = esc_attr( $s['text'] );
        $card = esc_attr( $s['card'] );
        $css  = "body{background:linear-gradient(160deg,{$bg1} 0%,{$bg2} 100%);color:{$text};}";
        $css .= ".cs404-heading{background:linear-gradient(135deg,{$acc},{$da});-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;}";
        $css .= ".cs404-btn,.cs404-home-btn{background:linear-gradient(135deg,{$acc},{$da});box-shadow:0 4px 24px {$acc}44;}";
        $css .= ".cs404-btn:hover,.cs404-home-btn:hover{box-shadow:0 6px 28px {$acc}66;}";
        $css .= ".cs404-tab.active{background:linear-gradient(135deg,{$acc},{$da});box-shadow:0 2px 12px {$acc}44;}";
        $css .= "#cs404-game{background:{$card};border-color:rgba(128,128,128,0.2);}";
        $css .= ".cs404-lb-score{color:{$acc};}";
        $css .= ".cs404-lb-row-gold{background:{$acc}18;}";
        if ( $s['dm'] ) {
            $css .= ".cs404-desc,.cs404-site-name,.cs404-tagline{color:{$text};}";
            $css .= ".cs404-tab{background:rgba(255,255,255,0.08);color:{$text};border-color:rgba(255,255,255,0.1);}";
            $css .= ".cs404-tab:hover{background:rgba(255,255,255,0.14);}";
            $css .= ".cs404-miner-btn{background:rgba(255,255,255,0.1);color:{$text};border-color:rgba(255,255,255,0.15);}";
            $css .= "#cs404-lb-panel{background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.1);}";
            $css .= ".cs404-lb-header{background:rgba(255,255,255,0.07);color:{$text};}";
            $css .= ".cs404-lb-name{color:{$text};}.cs404-lb-empty{color:{$text};}";
            $css .= ".cs404-lb-row{border-bottom-color:rgba(255,255,255,0.07);}";
        }
        return $css;
    }

    /**
     * Serves the dynamic colour-scheme CSS for the custom 404 page.
     *
     * Hooked on template_redirect at priority 0 (before the 404 handler at priority 1).
     * The 404 template references this endpoint via <link> to avoid echoing a <style> tag.
     *
     * @since 1.9.882
     * @return void
     */
    public static function serve_scheme_css(): void {
        if ( empty( $_GET['csdt_404_css'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only CSS endpoint
        $scheme = sanitize_key( wp_unslash( $_GET['csdt_404_css'] ) );
        $css    = self::get_404_scheme_css( $scheme );
        if ( ! $css ) {
            wp_die( esc_html__( 'Not found', 'cloudscale-devtools' ), '', [ 'response' => 404 ] );
        }
        nocache_headers();
        header( 'Content-Type: text/css; charset=utf-8' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-validated hex colours from hardcoded palette; no user input reaches the CSS string
        echo $css;
        exit;
    }

    /**
     * Intercepts WordPress 404 responses and outputs the custom games page.
     *
     * Hooked on `template_redirect` at priority 1.
     */
    public static function maybe_custom_404(): void {
        if ( ! is_404() ) { return; }
        $is_preview = isset( $_GET['csdt_devtools_preview_scheme'] ) && current_user_can( 'manage_options' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $is_preview && ! get_option( CloudScale_DevTools::CUSTOM_404_OPTION, 1 ) ) { return; }

        status_header( 404 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );

        $site_name    = get_bloginfo( 'name' );
        $site_tagline = get_bloginfo( 'description' );
        $home_url     = home_url( '/' );
        $logo_html    = '';
        if ( has_custom_logo() ) {
            $logo_html = get_custom_logo();
        } elseif ( $icon_url = get_site_icon_url( 64 ) ) {
            $logo_html = '<img src="' . esc_url( $icon_url ) . '" alt="" width="48" height="48">';
        }

        $root_path = plugin_dir_path( __DIR__ );
        $root_url  = plugin_dir_url( __DIR__ );
        $css_path  = $root_path . 'assets/cs-custom-404.css';
        $js_path   = $root_path . 'assets/cs-custom-404.js';
        $css_url   = $root_url . 'assets/cs-custom-404.css?ver=' . CloudScale_DevTools::VERSION . '.' . filemtime( $css_path );
        $js_url    = $root_url . 'assets/cs-custom-404.js?ver=' . CloudScale_DevTools::VERSION . '.' . filemtime( $js_path );

        $preview_key   = isset( $_GET['csdt_devtools_preview_scheme'] ) ? sanitize_key( wp_unslash( $_GET['csdt_devtools_preview_scheme'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only palette preview
        $all_schemes   = self::get_404_schemes();
        $active_scheme = ( $preview_key && isset( $all_schemes[ $preview_key ] ) ) ? $preview_key : get_option( CloudScale_DevTools::SCHEME_404_OPTION, 'ocean' );
        $scheme_css    = self::get_404_scheme_css( $active_scheme );
        ?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html__( 'Page Not Found', 'cloudscale-devtools' ); ?> &mdash; <?php echo esc_html( $site_name ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
<?php if ( $scheme_css ) : ?>
<link rel="stylesheet" href="<?php echo esc_url( add_query_arg( [ 'csdt_404_css' => $active_scheme, 'ver' => CloudScale_DevTools::VERSION ], home_url( '/' ) ) ); ?>">
<?php endif; ?>
</head>
<body>
<div class="cs404-heading-row">
<h1 class="cs404-heading">404 <?php echo esc_html__( 'Page Not Found', 'cloudscale-devtools' ); ?></h1>
<a href="<?php echo esc_url( $home_url ); ?>" class="cs404-home-btn">&#8592; Home</a>
</div>
<div class="cs404-dots" aria-hidden="true">
    <div class="cs404-dot" style="width:3px;height:3px;top:11%;left:7%;opacity:.7;"></div>
    <div class="cs404-dot" style="width:2px;height:2px;top:19%;left:86%;opacity:.5;"></div>
    <div class="cs404-dot" style="width:4px;height:4px;top:73%;left:6%;opacity:.6;"></div>
    <div class="cs404-dot" style="width:2px;height:2px;top:81%;left:91%;opacity:.5;"></div>
    <div class="cs404-dot" style="width:3px;height:3px;top:44%;left:3%;opacity:.4;"></div>
    <div class="cs404-dot" style="width:2px;height:2px;top:34%;left:96%;opacity:.4;"></div>
    <div class="cs404-dot" style="width:5px;height:5px;top:89%;left:50%;opacity:.25;background:#f57c00;"></div>
    <div class="cs404-dot" style="width:3px;height:3px;top:5%;left:48%;opacity:.35;background:#f57c00;"></div>
</div>
<div class="cs404-game-wrap">
    <div class="cs404-tabs">
        <button class="cs404-tab active" data-game="runner">🏃 Runner</button>
        <button class="cs404-tab" data-game="jetpack">🚀 Jetpack</button>
        <button class="cs404-tab" data-game="racer">🚗 Racer</button>
        <button class="cs404-tab" data-game="miner">⛏ Miner</button>
        <button class="cs404-tab" data-game="asteroids">🌌 Asteroids</button>
        <button class="cs404-tab" data-game="snake">🐍 Snake</button>
        <button class="cs404-tab" data-game="spaceinvaders">👾 Invaders</button>
    </div>
    <div style="position:relative;display:inline-block;max-width:100%;">
        <canvas id="cs404-game" width="620" height="280" aria-label="404 Olympics mini-games"></canvas>
        <div id="cs404-name-overlay" style="display:none;position:absolute;inset:0;z-index:10;background:rgba(13,42,74,0.88);border-radius:10px;flex-direction:column;align-items:center;justify-content:center;gap:14px;box-shadow:inset 0 0 0 2px rgba(245,124,0,0.6);">
            <p style="font-size:22px;font-weight:900;color:#f57c00;margin:0;">🏆 New High Score!</p>
            <p style="font-size:14px;color:#cce9fb;margin:0;">Enter your name:</p>
            <input id="cs404-name-input" type="text" maxlength="20" placeholder="Your name"
                style="font-size:16px;padding:8px 14px;border:2px solid #f57c00;border-radius:8px;outline:none;text-align:center;width:200px;">
            <button id="cs404-name-save"
                style="background:linear-gradient(135deg,#f57c00,#e65100);color:#fff;border:none;border-radius:8px;padding:9px 28px;font-size:15px;font-weight:700;cursor:pointer;">
                Save
            </button>
        </div>
    </div>
    <div id="cs404-miner-ctrl" class="cs404-miner-ctrl">
        <button id="cs404-ml" class="cs404-miner-btn">◀</button>
        <button id="cs404-mj" class="cs404-miner-btn">▲ Jump</button>
        <button id="cs404-mr" class="cs404-miner-btn">▶</button>
    </div>
    <div id="cs404-asteroids-ctrl" class="cs404-miner-ctrl">
        <button id="cs404-asl" class="cs404-miner-btn">◀</button>
        <button id="cs404-asu" class="cs404-miner-btn">▲ Thrust</button>
        <button id="cs404-ass" class="cs404-miner-btn">● Shoot</button>
        <button id="cs404-asr" class="cs404-miner-btn">▶</button>
    </div>
    <div id="cs404-si-ctrl" class="cs404-miner-ctrl" style="display:none;">
        <button id="cs404-sil" class="cs404-miner-btn">◀</button>
        <button id="cs404-sif" class="cs404-miner-btn">● Fire</button>
        <button id="cs404-sir" class="cs404-miner-btn">▶</button>
    </div>
    <div id="cs404-4dir-ctrl" style="display:none;grid-template-columns:repeat(3,44px);grid-template-rows:repeat(3,44px);gap:4px;justify-content:center;margin-top:10px;">
        <span></span>
        <button id="cs404-4up" class="cs404-miner-btn" style="grid-column:2;grid-row:1;">▲</button>
        <span></span>
        <button id="cs404-4lt" class="cs404-miner-btn" style="grid-column:1;grid-row:2;">◀</button>
        <span style="grid-column:2;grid-row:2;"></span>
        <button id="cs404-4rt" class="cs404-miner-btn" style="grid-column:3;grid-row:2;">▶</button>
        <span></span>
        <button id="cs404-4dn" class="cs404-miner-btn" style="grid-column:2;grid-row:3;">▼</button>
        <span></span>
    </div>
    <div id="cs404-lb-panel">
        <div class="cs404-lb-header">
            <span id="cs404-lb-title">🏆 Runner — Top 10</span>
        </div>
        <div id="cs404-lb-body">
            <p class="cs404-lb-empty">No scores yet — be the first!</p>
        </div>
    </div>
</div>
<div class="cs404-wrap">
    <p class="cs404-desc"><?php echo esc_html__( "The page you're looking for doesn't exist or may have been moved.", 'cloudscale-devtools' ); ?></p>
    <a href="<?php echo esc_url( $home_url ); ?>" class="cs404-btn">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
        <?php echo esc_html__( 'Back to Home', 'cloudscale-devtools' ); ?>
    </a>
    <a href="https://andrewbaker.ninja/wordpress-plugin-help/cloudscale-cyber-devtools-help/" class="cs404-plugin-badge" target="_blank" rel="noopener noreferrer">
        <span class="cs404-plugin-badge-icon">⚡</span>
        <span>
            <span class="cs404-plugin-badge-label">Powered by</span>
            <span class="cs404-plugin-badge-name">CloudScale Devtools</span>
        </span>
    </a>
    <div class="cs404-brand">
        <?php if ( $logo_html ) : ?><div class="cs404-logo"><?php echo wp_kses_post( $logo_html ); ?></div><?php endif; ?>
        <p class="cs404-site-name"><?php echo esc_html( $site_name ); ?></p>
        <?php if ( $site_tagline ) : ?><p class="cs404-tagline"><?php echo esc_html( $site_tagline ); ?></p><?php endif; ?>
    </div>
</div>

<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- standalone 404 exit-page; data passed via data-* attributes to avoid inline scripts ?>
<script src="<?php echo esc_url( $js_url ); ?>" data-api="<?php echo esc_attr( rest_url( CloudScale_DevTools::HISCORE_NS ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( CloudScale_DevTools::SCORE_NONCE_ACTION ) ); ?>"></script>

</body>
</html>
        <?php
        exit;
    }

    /** Registers per-game hi-score REST endpoints. */
    public static function register_hiscore_routes(): void {
        register_rest_route( CloudScale_DevTools::HISCORE_NS, '/hiscore/(?P<game>runner|jetpack|racer|miner|asteroids|snake|mrdo)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'rest_get_hiscore' ],
                // Public leaderboard read — no authentication required.
                'permission_callback' => static fn() => true,
                'args'                => [ 'game' => [ 'required' => true, 'type' => 'string' ] ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'rest_set_hiscore' ],
                // Public score submission — open to guests by design (404 mini-games).
                // CSRF protection is enforced via nonce verification in the callback.
                'permission_callback' => static fn() => true,
                'args'                => [
                    'game'  => [ 'required' => true, 'type' => 'string' ],
                    'score' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1, 'maximum' => 999999 ],
                    'name'  => [ 'required' => true, 'type' => 'string', 'maxLength' => 30 ],
                ],
            ],
        ] );
    }

    /** Returns the top-10 leaderboard for one game. */
    public static function rest_get_hiscore( WP_REST_Request $request ): WP_REST_Response {
        $game = sanitize_key( $request->get_param( 'game' ) );
        $raw  = get_option( 'csdt_devtools_leaderboard_' . $game, '' );
        $lb   = $raw ? json_decode( $raw, true ) : [];
        if ( ! is_array( $lb ) ) { $lb = []; }
        return rest_ensure_response( [ 'leaderboard' => $lb ] );
    }

    /** Inserts a score into the top-10 leaderboard for one game. */
    public static function rest_set_hiscore( WP_REST_Request $request ) {
        $nonce = $request->get_header( 'x_wp_score_nonce' );
        if ( ! $nonce || ! wp_verify_nonce( sanitize_text_field( $nonce ), CloudScale_DevTools::SCORE_NONCE_ACTION ) ) {
            return new WP_Error( 'forbidden', __( 'Invalid nonce.', 'cloudscale-devtools' ), [ 'status' => 403 ] );
        }
        $game  = sanitize_key( $request->get_param( 'game' ) );
        $score = (int) $request->get_param( 'score' );
        $name  = sanitize_text_field( $request->get_param( 'name' ) );

        $score_caps = [ 'runner' => 999999, 'jetpack' => 999999, 'racer' => 999999, 'miner' => 2000, 'asteroids' => 999999, 'snake' => 9990, 'mrdo' => 99990 ];
        if ( isset( $score_caps[ $game ] ) && $score > $score_caps[ $game ] ) {
            return new WP_Error( 'score_invalid', __( 'Score exceeds maximum for this game.', 'cloudscale-devtools' ), [ 'status' => 422 ] );
        }

        // Rate limit: max 5 submissions per IP per game per 10 minutes.
        $ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $ip_key = 'csdt_devtools_rl_' . md5( $ip . $game );
        $count  = (int) get_transient( $ip_key );
        if ( $count >= 5 ) {
            return new WP_Error( 'rate_limited', __( 'Too many score submissions. Try again later.', 'cloudscale-devtools' ), [ 'status' => 429 ] );
        }
        set_transient( $ip_key, $count + 1, 600 );

        $raw = get_option( 'csdt_devtools_leaderboard_' . $game, '' );
        $lb  = $raw ? json_decode( $raw, true ) : [];
        if ( ! is_array( $lb ) ) { $lb = []; }

        foreach ( $lb as $entry ) {
            if ( (int) $entry['score'] === $score && $entry['name'] === $name ) {
                return rest_ensure_response( [ 'ok' => false, 'leaderboard' => $lb ] );
            }
        }
        $lowest = isset( $lb[9] ) ? (int) $lb[9]['score'] : 0;
        if ( count( $lb ) >= 10 && $score <= $lowest ) {
            return rest_ensure_response( [ 'ok' => false, 'leaderboard' => $lb ] );
        }
        $lb[] = [ 'score' => $score, 'name' => $name ];
        usort( $lb, fn( $a, $b ) => (int) $b['score'] - (int) $a['score'] );
        $lb = array_slice( $lb, 0, 10 );
        update_option( 'csdt_devtools_leaderboard_' . $game, wp_json_encode( $lb ), false );
        return rest_ensure_response( [ 'ok' => true, 'leaderboard' => $lb ] );
    }

    /** AJAX handler: saves the 404 enable toggle and colour scheme. */
    public static function ajax_save_404_settings(): void {
        check_ajax_referer( 'csdt_devtools_404_settings', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden.', 'cloudscale-devtools' ) );
        }

        $custom_404 = isset( $_POST['custom_404'] ) ? ( absint( wp_unslash( $_POST['custom_404'] ) ) ? 1 : 0 ) : 0;
        update_option( CloudScale_DevTools::CUSTOM_404_OPTION, $custom_404 );

        if ( isset( $_POST['scheme'] ) ) {
            $schemes    = self::get_404_schemes();
            $scheme_key = sanitize_key( wp_unslash( $_POST['scheme'] ) );
            if ( isset( $schemes[ $scheme_key ] ) ) {
                update_option( CloudScale_DevTools::SCHEME_404_OPTION, $scheme_key );
            }
        }

        wp_send_json_success( [ 'custom_404' => $custom_404, 'scheme' => get_option( CloudScale_DevTools::SCHEME_404_OPTION, 'ocean' ) ] );
    }

    /** Renders the 404 Games settings panel. */
    public static function render_404_panel(): void {
        $current_scheme = get_option( CloudScale_DevTools::SCHEME_404_OPTION, 'ocean' );
        $enabled        = (bool) get_option( CloudScale_DevTools::CUSTOM_404_OPTION, 1 );
        ?>
        <div class="cs-panel" id="cs-panel-404">
            <div class="cs-section-header" style="background:linear-gradient(135deg,#f57c00,#e65100);">
                <span>🎮 404 GAMES PAGE</span>
                <?php CloudScale_DevTools::render_explain_btn( '404-games', '404 Games Page', [
                    [ 'name' => 'Enable',        'rec' => 'Toggle', 'desc' => 'When enabled, replaces the default WordPress 404 page with a fun interactive page featuring 7 mini-games: Runner, Jetpack, Racer, Miner, Asteroids, Snake, and Mr. Do!. No theme dependency — works even if the active theme is broken.' ],
                    [ 'name' => 'Colour Scheme', 'rec' => 'Optional', 'desc' => 'Choose from 12 built-in colour palettes. Changes take effect immediately. Use Preview to see the result before saving.' ],
                ] ); ?>
            </div>
            <div class="cs-panel-body">
                <div class="cs-field" style="margin-bottom:20px;">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                        <input type="checkbox" id="cs-404-enabled" <?php checked( $enabled ); ?>>
                        <span class="cs-label" style="margin:0;"><?php esc_html_e( 'Enable custom 404 games page', 'cloudscale-devtools' ); ?></span>
                    </label>
                    <span class="cs-hint"><?php esc_html_e( 'Replaces the default WordPress 404 with 5 playable mini-games and a global leaderboard.', 'cloudscale-devtools' ); ?></span>
                    <div id="cs-404-toggle-msg" style="margin-top:8px;display:none;"></div>
                </div>

                <div class="cs-field" style="margin-bottom:16px;">
                    <label class="cs-label"><?php esc_html_e( 'Colour Scheme:', 'cloudscale-devtools' ); ?></label>
                    <div class="cs-pcr-scheme-grid" id="cs-404-scheme-grid" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:8px;">
                        <?php foreach ( self::get_404_schemes() as $key => $s ) : ?>
                        <button type="button" class="cs-404-scheme-swatch<?php echo $key === $current_scheme ? ' active' : ''; ?>"
                            data-scheme="<?php echo esc_attr( $key ); ?>"
                            style="border:2px solid <?php echo $key === $current_scheme ? '#f57c00' : '#ddd'; ?>;border-radius:8px;padding:4px;background:none;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;width:76px;">
                            <span style="display:block;width:60px;height:36px;border-radius:5px;background:linear-gradient(135deg,<?php echo esc_attr( $s['bg1'] ); ?>,<?php echo esc_attr( $s['bg2'] ); ?>);position:relative;">
                                <span style="position:absolute;bottom:4px;right:4px;width:12px;height:12px;border-radius:50%;background:<?php echo esc_attr( $s['acc'] ); ?>;"></span>
                            </span>
                            <span style="font-size:11px;color:#333;"><?php echo esc_html( $s['name'] ); ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="display:flex;align-items:center;gap:10px;margin-top:16px;">
                    <button type="button" class="cs-btn-primary" id="cs-404-save-scheme">💾 <?php esc_html_e( 'Save Scheme', 'cloudscale-devtools' ); ?></button>
                    <a href="<?php echo esc_url( home_url( '/this-page-does-not-exist' ) ); ?>" target="_blank" rel="noopener" id="cs-404-preview-link"
                       style="display:inline-block;padding:7px 16px;border-radius:5px;background:#555;color:#fff;text-decoration:none;font-size:13px;">
                        <?php esc_html_e( 'Preview 404', 'cloudscale-devtools' ); ?> &rarr;
                    </a>
                    <span id="cs-404-scheme-msg" style="display:none;"></span>
                </div>
            </div>
        </div>
        <?php
    }

}
