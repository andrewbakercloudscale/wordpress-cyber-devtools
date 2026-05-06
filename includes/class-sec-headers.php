<?php
/**
 * Security Headers — X-Content-Type-Options, X-Frame-Options, Referrer-Policy,
 * Permissions-Policy panel and AJAX; header scan tool.
 *
 * @package CloudScale_DevTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CSDT_Security_Headers {

    /** @return array<string,array<string,mixed>> */
    private static function header_defs(): array {
        return [
            'strict-transport-security' => [
                'name'      => 'Strict-Transport-Security',
                'default'   => 'max-age=31536000; includeSubDomains',
                'fixed'     => false,
                'risk'      => 'safe',
                'rec'       => '✅ Safe — Highly recommended',
                'desc'      => 'Forces browsers to use HTTPS exclusively for 1 year. Prevents protocol downgrade attacks and cookie hijacking.',
                'risk_note' => 'CloudScale only sends this header over HTTPS — it is never sent on an HTTP connection. Safe to enable immediately.',
                'presets'   => [
                    'max-age=31536000; includeSubDomains' => '1 yr + subdomains',
                    'max-age=31536000'                   => '1 yr, main domain only',
                    'max-age=86400'                      => '1 day (testing only)',
                ],
            ],
            'x-content-type-options' => [
                'name'      => 'X-Content-Type-Options',
                'default'   => 'nosniff',
                'fixed'     => true,
                'risk'      => 'safe',
                'rec'       => '✅ Safe — Zero risk',
                'desc'      => 'Prevents browsers from MIME-sniffing away from the declared content type. Stops certain XSS attacks via content confusion.',
                'risk_note' => 'Value is always "nosniff". No configuration needed, no known compatibility issues.',
                'presets'   => [],
            ],
            'x-frame-options' => [
                'name'      => 'X-Frame-Options',
                'default'   => 'SAMEORIGIN',
                'fixed'     => false,
                'risk'      => 'low',
                'rec'       => '🟡 Low risk — Review if your site is embedded elsewhere',
                'desc'      => 'Controls whether your site can appear inside an iframe on another domain. Prevents clickjacking attacks.',
                'risk_note' => 'SAMEORIGIN allows embedding on your own domain only. If a partner site or tool legitimately embeds your pages in an iframe, you may need to disable this header for that use case.',
                'presets'   => [
                    'SAMEORIGIN' => 'SAMEORIGIN (recommended)',
                    'DENY'       => 'DENY (most restrictive)',
                ],
            ],
            'referrer-policy' => [
                'name'      => 'Referrer-Policy',
                'default'   => 'strict-origin-when-cross-origin',
                'fixed'     => false,
                'risk'      => 'low',
                'rec'       => '🟡 Low risk — May reduce referrer data in analytics',
                'desc'      => 'Controls how much of the page URL is sent to other sites when a user follows a link away from your site.',
                'risk_note' => '"strict-origin-when-cross-origin" sends only the domain (not the path) to external sites. GA4 handles this correctly. If analytics referrers look wrong, switch to "no-referrer-when-downgrade".',
                'presets'   => [
                    'strict-origin-when-cross-origin' => 'strict-origin-when-cross-origin (recommended)',
                    'no-referrer-when-downgrade'      => 'no-referrer-when-downgrade (more referrer data)',
                    'same-origin'                     => 'same-origin',
                    'no-referrer'                     => 'no-referrer (most private)',
                ],
            ],
            'permissions-policy' => [
                'name'      => 'Permissions-Policy',
                'default'   => 'camera=(), microphone=(), geolocation=(), payment=()',
                'fixed'     => false,
                'risk'      => 'review',
                'rec'       => '🔧 Configure — Untick any API your site uses',
                'desc'      => 'Restricts which browser features this page and its iframes can access. Each checkbox blocks one API.',
                'risk_note' => 'Tick to block each browser API. Untick any that your site actually uses — maps need Geolocation, WooCommerce / Apple Pay / passkeys need Payment API.',
                'presets'   => [],
                'directives' => [
                    'camera'      => [ 'label' => 'Camera' ],
                    'microphone'  => [ 'label' => 'Microphone' ],
                    'geolocation' => [ 'label' => 'Geolocation' ],
                    'payment'     => [ 'label' => 'Payment API' ],
                ],
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function load_headers_config(): array {
        $defs       = self::header_defs();
        $config_raw = get_option( 'csdt_sec_headers_config', null );
        if ( null === $config_raw ) {
            // Migrate from legacy all-or-nothing flag.
            $legacy = get_option( 'csdt_devtools_safe_headers_enabled', '0' ) === '1';
            $config = [];
            foreach ( $defs as $key => $def ) {
                $config[ $key ] = [ 'enabled' => $legacy, 'value' => $def['default'] ];
            }
        } else {
            $config = json_decode( (string) $config_raw, true );
            if ( ! is_array( $config ) ) { $config = []; }
            foreach ( $defs as $key => $def ) {
                if ( ! isset( $config[ $key ] ) ) {
                    $config[ $key ] = [ 'enabled' => false, 'value' => $def['default'] ];
                }
            }
        }
        return $config;
    }

    public static function render_security_headers_panel( bool $show_divider = true ): void {
        $defs           = self::header_defs();
        $config         = self::load_headers_config();
        $ext_ack        = get_option( 'csdt_devtools_sec_headers_ack', '0' ) === '1';
        $passkey_active = get_option( 'csdt_devtools_2fa_method', 'off' ) === 'passkey';
        $woo_active     = function_exists( 'WC' ) || class_exists( 'WooCommerce' );

        $risk_styles = [
            'safe'   => [ 'bg' => '#dcfce7', 'color' => '#15803d', 'border' => '#86efac' ],
            'low'    => [ 'bg' => '#fef9c3', 'color' => '#854d0e', 'border' => '#fde047' ],
            'review' => [ 'bg' => '#fff7ed', 'color' => '#c2410c', 'border' => '#fed7aa' ],
        ];
        ?>
        <?php if ( $show_divider ) : ?><hr class="cs-sec-divider"><?php endif; ?>
        <div class="cs-section-header" style="background:linear-gradient(90deg,#1e3a5f 0%,#1d4ed8 100%);border-left:3px solid #60a5fa;margin-bottom:0;border-radius:6px 6px 0 0;">
            <span>🔒 <?php esc_html_e( 'Security Headers', 'cloudscale-devtools' ); ?></span>
            <span class="cs-header-hint"><?php esc_html_e( 'Enable, configure, and fine-tune each header individually', 'cloudscale-devtools' ); ?></span>
            <?php CloudScale_DevTools::render_explain_btn( 'sec-headers', 'Security Headers', [
                [ 'name' => 'What these headers do',  'rec' => 'Info', 'html' => 'These five headers are low-risk, high-value hardening controls recommended by OWASP and required by most security audits. They are sent with every frontend page response and have no effect on wp-admin.' ],
                [ 'name' => 'Risk badges explained',  'rec' => 'Info', 'html' => '<strong>✅ Safe</strong> — enable without review. No known compatibility issues.<br><br><strong>🟡 Low risk</strong> — enable and monitor. May affect a minority of setups (e.g. sites embedded in iframes, analytics referrer data).<br><br><strong>🔧 Configure</strong> — read the recommendation before enabling. The default value may block features your site intentionally uses.' ],
                [ 'name' => 'Set Externally option',  'rec' => 'Info', 'html' => 'If your Cloudflare, nginx, or CDN configuration already sends these headers, tick <strong>Set Externally</strong> instead of enabling them here. Sending duplicate headers can cause browser conflicts.' ],
                [ 'name' => 'CSP is separate',        'rec' => 'Info', 'html' => 'Content Security Policy is configured separately in the panel below — it has many more options and requires a testing phase before enforcement.' ],
            ] ); ?>
        </div>
        <div style="padding:20px;background:#fff;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 6px 6px;margin-bottom:0;" id="cs-sec-headers-panel">

        <?php
        // Use stored master flag rather than deriving from individual header states,
        // so the UI correctly reflects master-off even when per-header flags are enabled.
        $master_on = get_option( 'csdt_devtools_safe_headers_enabled', '0' ) === '1';
        ?>
        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:10px 14px;border-radius:8px;border:2px solid <?php echo $master_on ? '#3b82f6' : '#e2e8f0'; ?>;background:<?php echo $master_on ? '#eff6ff' : '#f8fafc'; ?>;" id="csdt-sh-master-label">
            <input type="checkbox" id="csdt-sh-master" <?php checked( $master_on ); ?> style="width:17px;height:17px;flex-shrink:0;margin:0;cursor:pointer;">
            <span>
                <span style="font-size:13px;font-weight:700;color:#1e3a5f;"><?php esc_html_e( 'Manage Security Headers', 'cloudscale-devtools' ); ?></span><br>
                <span style="font-size:11px;color:#64748b;"><?php esc_html_e( 'WordPress sends headers with every page response', 'cloudscale-devtools' ); ?></span>
            </span>
        </label>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:8px 14px;border-radius:8px;border:1px solid <?php echo $ext_ack ? '#f97316' : '#e2e8f0'; ?>;background:<?php echo $ext_ack ? '#fff7ed' : '#f8fafc'; ?>;">
            <input type="checkbox" id="csdt-sec-headers-ext" <?php checked( $ext_ack ); ?> style="width:16px;height:16px;flex-shrink:0;margin:0;cursor:pointer;">
            <span>
                <span style="font-size:13px;font-weight:600;color:#9a3412;"><?php esc_html_e( 'Set Externally (Cloudflare / nginx / CDN)', 'cloudscale-devtools' ); ?></span><br>
                <span style="font-size:11px;color:#64748b;"><?php esc_html_e( 'My proxy already sends these headers — don\'t inject from PHP to avoid duplicates', 'cloudscale-devtools' ); ?></span>
            </span>
        </label>
        </div>

        <div id="csdt-sh-cards-wrap" style="<?php echo $master_on ? '' : 'opacity:.45;pointer-events:none;'; ?>">
        <?php foreach ( $defs as $key => $def ) :
            $cfg    = $config[ $key ] ?? [ 'enabled' => false, 'value' => $def['default'] ];
            $is_on  = ! empty( $cfg['enabled'] );
            $val    = esc_attr( $cfg['value'] ?? $def['default'] );
            $rs     = $risk_styles[ $def['risk'] ];
            $crd_bg = $is_on ? '#f0f9ff' : '#fafafa';
            $crd_bd = $is_on ? '#93c5fd' : '#e2e8f0';
            $val_op = $is_on ? '' : 'opacity:.45;pointer-events:none;';
        ?>
        <div id="csdt-sh-card-<?php echo esc_attr( $key ); ?>" class="csdt-sh-card"
             style="border:1px solid <?php echo esc_attr( $crd_bd ); ?>;border-radius:8px;padding:14px;margin-bottom:10px;background:<?php echo esc_attr( $crd_bg ); ?>;">

            <div style="display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;flex:1;min-width:140px;">
                    <input type="checkbox" class="csdt-sh-toggle" data-key="<?php echo esc_attr( $key ); ?>"
                           <?php checked( $is_on ); ?> style="width:16px;height:16px;flex-shrink:0;margin:0;cursor:pointer;">
                    <code style="font-size:11px;color:#1d4ed8;background:#eff6ff;padding:3px 7px;border-radius:4px;white-space:nowrap;"><?php echo esc_html( $def['name'] ); ?></code>
                </label>
                <span style="flex-shrink:0;background:<?php echo esc_attr( $rs['bg'] ); ?>;color:<?php echo esc_attr( $rs['color'] ); ?>;border:1px solid <?php echo esc_attr( $rs['border'] ); ?>;font-size:10px;font-weight:700;padding:2px 8px;border-radius:12px;white-space:nowrap;"><?php echo esc_html( $def['rec'] ); ?></span>
            </div>

            <div class="csdt-sh-value-wrap" data-key="<?php echo esc_attr( $key ); ?>"
                 style="margin-top:10px;<?php echo esc_attr( $val_op ); ?>">
                <?php if ( $def['fixed'] ) : ?>
                <code style="display:inline-block;font-size:11px;color:#475569;background:#f1f5f9;padding:5px 9px;border-radius:4px;border:1px solid #e2e8f0;"><?php echo esc_html( $def['default'] ); ?></code>
                <?php elseif ( ! empty( $def['directives'] ) ) : ?>
                <input type="hidden" class="csdt-sh-value" data-key="<?php echo esc_attr( $key ); ?>" value="<?php echo $val; ?>">
                <div style="display:flex;flex-direction:column;gap:7px;margin-top:2px;">
                <?php foreach ( $def['directives'] as $dir => $dir_def ) :
                    $is_blocked = str_contains( (string) $val, $dir . '=()' );
                    $dir_note   = '';
                    $note_color = '#64748b';
                    if ( 'payment' === $dir ) {
                        if ( $passkey_active && $woo_active ) {
                            $dir_note   = '⚠️ Passkeys + WooCommerce active — Apple Pay, Google Pay, and passkey UI may need this unblocked.';
                            $note_color = '#c2410c';
                        } elseif ( $passkey_active ) {
                            $dir_note   = '⚠️ Passkeys active — some browsers use the Payment Request API for passkey UI. Consider unchecking.';
                            $note_color = '#c2410c';
                        } elseif ( $woo_active ) {
                            $dir_note   = '⚠️ WooCommerce active — Apple Pay / Google Pay require Payment Request API. Consider unchecking.';
                            $note_color = '#c2410c';
                        }
                    } elseif ( 'geolocation' === $dir ) {
                        $dir_note = 'Uncheck if your site uses maps or location services.';
                    }
                ?>
                <label style="display:flex;align-items:flex-start;gap:8px;font-size:12px;color:#334155;cursor:pointer;line-height:1.4;">
                    <input type="checkbox" class="csdt-pp-dir" data-dir="<?php echo esc_attr( $dir ); ?>"
                           <?php checked( $is_blocked ); ?>
                           style="width:14px;height:14px;flex-shrink:0;margin-top:2px;cursor:pointer;">
                    <span>
                        <code style="font-size:10px;color:#475569;background:#f1f5f9;padding:1px 5px;border-radius:3px;"><?php echo esc_html( $dir . '=()' ); ?></code>
                        <span style="color:#64748b;font-size:11px;margin-left:4px;"><?php echo esc_html( $dir_def['label'] ); ?></span>
                        <?php if ( $dir_note ) : ?>
                        <br><span style="font-size:10px;color:<?php echo esc_attr( $note_color ); ?>;margin-top:3px;display:inline-block;"><?php echo esc_html( $dir_note ); ?></span>
                        <?php endif; ?>
                    </span>
                </label>
                <?php endforeach; ?>
                </div>
                <?php else : ?>
                <input type="text" class="csdt-sh-value" data-key="<?php echo esc_attr( $key ); ?>"
                       value="<?php echo $val; ?>"
                       style="width:100%;font-family:monospace;font-size:12px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:4px;background:#fff;box-sizing:border-box;">
                <?php if ( ! empty( $def['presets'] ) ) : ?>
                <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:5px;">
                    <?php foreach ( $def['presets'] as $pval => $plabel ) : ?>
                    <button type="button" class="csdt-sh-preset" data-key="<?php echo esc_attr( $key ); ?>"
                            data-value="<?php echo esc_attr( $pval ); ?>"
                            title="<?php echo esc_attr( $plabel ); ?>"
                            style="font-size:10px;padding:2px 7px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:4px;cursor:pointer;color:#475569;white-space:nowrap;"><?php echo esc_html( $pval ); ?></button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <p style="margin:8px 0 0;font-size:12px;color:#64748b;line-height:1.5;"><?php echo esc_html( $def['desc'] ); ?></p>
            <div style="margin-top:6px;font-size:11px;padding:5px 8px;border-radius:4px;border-left:3px solid <?php echo esc_attr( $rs['border'] ); ?>;background:<?php echo esc_attr( $rs['bg'] ); ?>;color:<?php echo esc_attr( $rs['color'] ); ?>;"><?php echo esc_html( $def['risk_note'] ); ?></div>
        </div>
        <?php endforeach; ?>
        </div><!-- /csdt-sh-cards-wrap -->

        <div style="margin-top:6px;padding-top:14px;border-top:1px solid #e2e8f0;">
            <div style="display:flex;align-items:center;gap:10px;">
                <button type="button" id="csdt-sec-headers-save" class="cs-btn-primary cs-btn-sm"><?php esc_html_e( 'Save', 'cloudscale-devtools' ); ?></button>
                <span id="csdt-sec-headers-msg" class="cs-settings-saved">✓ <?php esc_html_e( 'Saved', 'cloudscale-devtools' ); ?></span>
            </div>
        </div>

        <?php
        $sh_history = json_decode( get_option( 'csdt_sec_headers_history', '[]' ), true );
        if ( is_array( $sh_history ) && ! empty( $sh_history ) ) :
        ?>
        <div id="csdt-sh-history-wrap" style="margin-top:18px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                <span id="csdt-sh-history-heading" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#64748b;flex:1;">
                    <?php echo esc_html( sprintf( __( 'Change History (%d saves)', 'cloudscale-devtools' ), count( $sh_history ) ) ); ?>
                </span>
                <button type="button" id="csdt-sh-history-toggle" style="font-size:11px;padding:2px 8px;background:none;border:1px solid #cbd5e1;color:#64748b;border-radius:4px;cursor:pointer;flex-shrink:0;">Show &#9660;</button>
            </div>
            <div id="csdt-sh-history-list" style="border:1px solid #e2e8f0;border-radius:6px;overflow:hidden;display:none;">
            <?php foreach ( $sh_history as $idx => $entry ) :
                $ts       = $entry['saved_at'] ?? 0;
                $label    = esc_html( $entry['label'] ?? 'Settings saved' );
                $age      = $ts ? esc_html( human_time_diff( $ts ) . ' ago' ) : '';
                $time_str = $ts ? esc_html( wp_date( 'M j · H:i', $ts ) ) : '';
                $bg       = $idx % 2 === 0 ? '#fff' : '#f8fafc';
            ?>
                <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:<?php echo esc_attr( $bg ); ?>;<?php echo $idx > 0 ? 'border-top:1px solid #e2e8f0;' : ''; ?>">
                    <span style="color:#94a3b8;font-size:11px;white-space:nowrap;min-width:110px;line-height:1.4;"><?php echo $age; ?><?php if ( $time_str ) : ?><br><span style="font-size:10px;color:#cbd5e1;"><?php echo $time_str; ?></span><?php endif; ?></span>
                    <span style="flex:1;font-size:12px;color:#334155;"><?php echo $label; ?></span>
                    <button type="button" class="csdt-sh-restore-btn" data-index="<?php echo (int) $idx; ?>"
                            style="background:none;border:1px solid #94a3b8;color:#475569;font-size:11px;font-weight:600;padding:3px 10px;border-radius:4px;cursor:pointer;white-space:nowrap;">&#x21A9; <?php esc_html_e( 'Restore', 'cloudscale-devtools' ); ?></button>
                </div>
            <?php endforeach; ?>
            </div>
            <div id="csdt-sh-restore-msg" style="display:none;margin-top:6px;font-size:12px;font-weight:600;color:#16a34a;"></div>
        </div>
        <?php endif; ?>
        </div>
        <?php
    }

    public static function ajax_sec_headers_save(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $ext_ack = isset( $_POST['ext_ack'] ) && '1' === $_POST['ext_ack'] ? '1' : '0'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        // Per-header config from JS
        $incoming_raw = isset( $_POST['headers_config'] ) ? wp_unslash( $_POST['headers_config'] ) : '{}'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $incoming     = json_decode( (string) $incoming_raw, true );
        if ( ! is_array( $incoming ) ) { $incoming = []; }

        $allowed_keys = array_keys( self::header_defs() );
        $fixed_values = [ 'x-content-type-options' => 'nosniff' ];
        $safe_config  = [];
        foreach ( $allowed_keys as $k ) {
            $entry   = $incoming[ $k ] ?? [ 'enabled' => false, 'value' => '' ];
            $enabled = ! empty( $entry['enabled'] );
            $value   = $fixed_values[ $k ] ?? sanitize_text_field( $entry['value'] ?? '' );
            $safe_config[ $k ] = [ 'enabled' => $enabled, 'value' => $value ];
        }

        // Master switch is sent separately from per-header enabled states so individual header
        // flags reflect only checkbox state, keeping history labels accurate.
        $master_on   = isset( $_POST['master'] ) && '1' === $_POST['master']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $any_enabled = $master_on && (bool) array_reduce( $safe_config, static fn( $c, $v ) => $c || ! empty( $v['enabled'] ), false );

        // Capture old state for history.
        $old_config_raw = get_option( 'csdt_sec_headers_config', null );
        $old_config     = null !== $old_config_raw ? json_decode( (string) $old_config_raw, true ) : null;
        $old_ext_ack    = get_option( 'csdt_devtools_sec_headers_ack', '0' );
        $old_enabled    = get_option( 'csdt_devtools_safe_headers_enabled', '0' );

        // Build a human-readable change label.
        $short = [
            'strict-transport-security' => 'HSTS',
            'x-content-type-options'    => 'X-Content-Type-Options',
            'x-frame-options'           => 'X-Frame-Options',
            'referrer-policy'           => 'Referrer-Policy',
            'permissions-policy'        => 'Permissions-Policy',
        ];
        $parts = [];
        if ( is_array( $old_config ) ) {
            $on = []; $off = []; $changed = [];
            foreach ( $allowed_keys as $k ) {
                $old_en  = ! empty( $old_config[ $k ]['enabled'] );
                $new_en  = $safe_config[ $k ]['enabled'];
                $old_val = trim( $old_config[ $k ]['value'] ?? '' );
                $new_val = trim( $safe_config[ $k ]['value'] ?? '' );
                if ( ! $old_en && $new_en )             { $on[]      = $short[ $k ]; }
                elseif ( $old_en && ! $new_en )         { $off[]     = $short[ $k ]; }
                elseif ( $old_en && $old_val !== $new_val ) { $changed[] = $short[ $k ]; }
            }
            if ( $on )      { $parts[] = 'Enabled: '       . implode( ', ', $on ); }
            if ( $off )     { $parts[] = 'Disabled: '      . implode( ', ', $off ); }
            if ( $changed ) { $parts[] = 'Updated value: ' . implode( ', ', $changed ); }
        } else {
            $on = array_filter( $safe_config, static fn( $v ) => ! empty( $v['enabled'] ) );
            if ( $on ) { $parts[] = 'Enabled: ' . implode( ', ', array_map( static fn( $k ) => $short[ $k ], array_keys( $on ) ) ); }
        }
        if ( $old_ext_ack !== $ext_ack ) {
            $parts[] = $ext_ack === '1' ? 'Set Externally on' : 'Set Externally off';
        }
        $label = $parts ? implode( '; ', $parts ) : 'Settings saved';

        // Push old state to rolling 10-entry history.
        $history = json_decode( get_option( 'csdt_sec_headers_history', '[]' ), true );
        if ( ! is_array( $history ) ) { $history = []; }
        array_unshift( $history, [
            'enabled'        => $old_enabled,
            'ext_ack'        => $old_ext_ack,
            'headers_config' => null !== $old_config_raw ? (string) $old_config_raw : null,
            'saved_at'       => time(),
            'label'          => $label,
        ] );
        update_option( 'csdt_sec_headers_history', wp_json_encode( array_slice( $history, 0, 10 ) ) );

        update_option( 'csdt_sec_headers_config',            wp_json_encode( $safe_config ) );
        update_option( 'csdt_devtools_safe_headers_enabled', $master_on ? '1' : '0' );
        update_option( 'csdt_devtools_sec_headers_ack',      $ext_ack );
        delete_transient( 'csdt_sec_headers_check' );

        wp_send_json_success( [
            'history_entry' => [
                'enabled'  => $old_enabled,
                'ext_ack'  => $old_ext_ack,
                'saved_at' => time(),
                'label'    => $label,
                'index'    => 0,
            ],
        ] );
    }

    public static function ajax_sec_headers_restore(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $idx     = isset( $_POST['index'] ) ? (int) wp_unslash( $_POST['index'] ) : -1;
        $history = json_decode( get_option( 'csdt_sec_headers_history', '[]' ), true );
        if ( ! is_array( $history ) || ! isset( $history[ $idx ] ) ) {
            wp_send_json_error( 'History entry not found.' );
        }

        $entry = $history[ $idx ];

        // Snapshot current live state into history before restoring.
        $current_raw = get_option( 'csdt_sec_headers_config', null );
        array_unshift( $history, [
            'enabled'        => get_option( 'csdt_devtools_safe_headers_enabled', '0' ),
            'ext_ack'        => get_option( 'csdt_devtools_sec_headers_ack', '0' ),
            'headers_config' => null !== $current_raw ? (string) $current_raw : null,
            'saved_at'       => time(),
            'label'          => 'Before restore to: ' . ( $entry['label'] ?? 'previous state' ),
        ] );
        update_option( 'csdt_sec_headers_history', wp_json_encode( array_slice( $history, 0, 10 ) ) );

        $restored_ext = $entry['ext_ack'] ?? '0';
        update_option( 'csdt_devtools_sec_headers_ack', $restored_ext );

        $response = [ 'enabled' => $entry['enabled'] ?? '0', 'ext_ack' => $restored_ext ];

        if ( ! empty( $entry['headers_config'] ) ) {
            $restored_config = json_decode( (string) $entry['headers_config'], true );
            if ( is_array( $restored_config ) ) {
                update_option( 'csdt_sec_headers_config', (string) $entry['headers_config'] );
                // Restore the master switch state from the snapshot, not derived from individual
                // header flags (which may be enabled independently of master).
                update_option( 'csdt_devtools_safe_headers_enabled', $entry['enabled'] ?? '0' );
                $response['headers_config'] = $restored_config;
            }
        } else {
            update_option( 'csdt_devtools_safe_headers_enabled', $entry['enabled'] ?? '0' );
        }

        delete_transient( 'csdt_sec_headers_check' );
        wp_send_json_success( $response );
    }

    public static function ajax_scan_headers(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }

        $sec_keys = [
            'content-security-policy',
            'content-security-policy-report-only',
            'strict-transport-security',
            'x-frame-options',
            'x-content-type-options',
            'referrer-policy',
            'permissions-policy',
        ];
        $mandatory = [ 'content-security-policy', 'strict-transport-security', 'x-frame-options', 'x-content-type-options' ];

        // ── Helper: analyse one URL ──────────────────────────────────────────
        $analyse = static function ( string $url ) use ( $sec_keys, $mandatory ): array {
            $resp = wp_remote_get( $url, [
                'timeout'     => 10,
                'sslverify'   => false,
                'redirection' => 3,
                'user-agent'  => 'CloudScale-Header-Scanner/1.0',
            ] );
            if ( is_wp_error( $resp ) ) {
                return [ 'url' => $url, 'error' => $resp->get_error_message() ];
            }
            $headers     = wp_remote_retrieve_headers( $resp );
            $status_code = (int) wp_remote_retrieve_response_code( $resp );
            // Build a raw map of all header values to reliably catch duplicates.
            // WordPress's CaseInsensitiveDictionary sometimes concatenates duplicate
            // headers with a newline rather than returning an array.
            $raw_multi = [];
            foreach ( $headers->getAll() as $raw_line ) {
                if ( strpos( $raw_line, ':' ) !== false ) {
                    [ $k, $v ] = explode( ':', $raw_line, 2 );
                    $raw_multi[ strtolower( trim( $k ) ) ][] = trim( $v );
                }
            }
            $sec = [];
            foreach ( $sec_keys as $hkey ) {
                $val = $headers[ $hkey ] ?? null;
                // Prefer raw_multi count for accurate duplicate detection.
                $all_vals = $raw_multi[ $hkey ] ?? ( null !== $val ? ( is_array( $val ) ? $val : [ $val ] ) : [] );
                if ( empty( $all_vals ) ) {
                    $sec[ $hkey ] = [ 'status' => 'missing', 'values' => [] ];
                } elseif ( count( $all_vals ) > 1 ) {
                    $sec[ $hkey ] = [ 'status' => 'duplicate', 'values' => $all_vals ];
                } else {
                    $sec[ $hkey ] = [ 'status' => 'present', 'values' => $all_vals ];
                }
            }
            return [
                'url'         => $url,
                'status_code' => $status_code,
                'sec'         => $sec,
                'all_headers' => $headers->getAll(),
            ];
        };

        // ── Homepage — full analysis ─────────────────────────────────────────
        $home_url  = home_url( '/' );
        $home_data = $analyse( $home_url );

        // Grade + warnings from homepage
        $grade    = 'A+';
        $warnings = [];
        if ( ! isset( $home_data['error'] ) ) {
            $sec          = $home_data['sec'];
            $missing_mand = 0;
            foreach ( $mandatory as $hk ) {
                if ( ( $sec[ $hk ]['status'] ?? 'missing' ) === 'missing' ) { $missing_mand++; }
            }
            // Grade by missing mandatory headers
            if ( $missing_mand >= 3 )     { $grade = 'F'; }
            elseif ( $missing_mand === 2 ) { $grade = 'D'; }
            elseif ( $missing_mand === 1 ) {
                // If the only missing mandatory header is enforced CSP but report-only is present,
                // give B — report-only means CSP is being tuned, not absent entirely.
                $only_csp_missing = ( $sec['content-security-policy']['status'] ?? 'missing' ) === 'missing'
                    && ( $sec['strict-transport-security']['status']  ?? 'missing' ) !== 'missing'
                    && ( $sec['x-frame-options']['status']            ?? 'missing' ) !== 'missing'
                    && ( $sec['x-content-type-options']['status']     ?? 'missing' ) !== 'missing';
                $has_report_only = ( $sec['content-security-policy-report-only']['status'] ?? 'missing' ) === 'present';
                $grade = ( $only_csp_missing && $has_report_only ) ? 'B' : 'C';
            } else { $grade = 'A+'; }

            // Warn when CSP is in report-only mode (not yet enforcing)
            if ( ( $sec['content-security-policy']['status'] ?? 'missing' ) === 'missing'
                && ( $sec['content-security-policy-report-only']['status'] ?? 'missing' ) === 'present' ) {
                $warnings[] = [ 'header' => 'Content-Security-Policy', 'msg' => "CSP is in report-only mode — it logs violations but blocks nothing. Switch to enforcement in the Headers → CSP panel to reach grade A+." ];
            }

            // CSP quality warnings
            $csp_val = $sec['content-security-policy']['values'][0] ?? '';
            if ( $csp_val ) {
                if ( str_contains( $csp_val, "'unsafe-inline'" ) ) {
                    $warnings[] = [ 'header' => 'Content-Security-Policy', 'msg' => "This policy contains 'unsafe-inline' which is dangerous in the script-src directive." ];
                    if ( $grade === 'A+' ) { $grade = 'A'; }
                }
                if ( str_contains( $csp_val, "'unsafe-eval'" ) ) {
                    $warnings[] = [ 'header' => 'Content-Security-Policy', 'msg' => "This policy contains 'unsafe-eval' which allows dynamic code execution." ];
                    if ( in_array( $grade, [ 'A+', 'A' ], true ) ) { $grade = 'B'; }
                }
                // When strict-dynamic is active, only nonce'd scripts load — check that
                // every <script> tag in the page HTML actually carries a nonce.
                if ( str_contains( $csp_val, "'strict-dynamic'" ) ) {
                    $page_resp = wp_remote_get( $home_url, [
                        'timeout'   => 10,
                        'sslverify' => false,
                        'user-agent'=> 'CloudScale-Header-Scanner/1.0',
                    ] );
                    if ( ! is_wp_error( $page_resp ) ) {
                        $body = wp_remote_retrieve_body( $page_resp );
                        preg_match_all( '/<script(?=[>\s])([^>]*)>/i', $body, $all_scripts );
                        $missing = 0;
                        foreach ( $all_scripts[1] as $attrs ) {
                            if ( ! preg_match( '/\bnonce\s*=/i', $attrs ) ) {
                                $missing++;
                            }
                        }
                        if ( $missing > 0 ) {
                            $warnings[] = [ 'header' => 'Content-Security-Policy', 'msg' => "{$missing} <script> tag(s) on the homepage have no nonce. With 'strict-dynamic' active, these scripts will be blocked — third-party tags (AdSense, analytics) are common culprits. Enable the CloudScale nonce output buffer or add them via wp_enqueue_scripts." ];
                            if ( in_array( $grade, [ 'A+', 'A' ], true ) ) { $grade = 'B'; }
                        }
                    }
                }
            }
            // Duplicate headers
            foreach ( $sec as $hk => $hdata ) {
                if ( $hdata['status'] === 'duplicate' ) {
                    $count = count( $hdata['values'] );
                    if ( $hk === 'content-security-policy' ) {
                        // For CSP, the browser enforces the INTERSECTION of all policies —
                        // AdSense, analytics, and other third-party scripts can be silently
                        // blocked if the two policies have different allowlists.
                        $warnings[] = [ 'header' => $hk, 'msg' => $count . ' Content-Security-Policy headers detected. The browser enforces ALL of them simultaneously (intersection, not first-wins) — AdSense and third-party scripts may be blocked by the stricter policy. One source must be removed. Common causes: Nginx/Apache adding a static CSP while the plugin also sends one.' ];
                        if ( in_array( $grade, [ 'A+', 'A', 'B' ], true ) ) { $grade = 'C'; }
                    } else {
                        $warnings[] = [ 'header' => $hk, 'msg' => $count . ' duplicate headers detected — browser behaviour is undefined. Check nginx/Apache config and plugin settings for conflicts.' ];
                        if ( $grade === 'A+' ) { $grade = 'A'; }
                    }
                }
            }
            // Optional headers missing
            foreach ( [ 'referrer-policy', 'permissions-policy' ] as $opt ) {
                if ( ( $sec[ $opt ]['status'] ?? 'missing' ) === 'missing' && in_array( $grade, [ 'A+', 'A' ], true ) ) {
                    $grade = 'B';
                }
            }
            // HSTS quality
            $hsts = $sec['strict-transport-security']['values'][0] ?? '';
            if ( $hsts && preg_match( '/max-age=(\d+)/', $hsts, $m ) && (int) $m[1] < 31536000 ) {
                $warnings[] = [ 'header' => 'Strict-Transport-Security', 'msg' => 'max-age is less than 31536000 (1 year). Increase to at least 31536000.' ];
                if ( $grade === 'A+' ) { $grade = 'A'; }
            }
            $home_data['grade']    = $grade;
            $home_data['warnings'] = $warnings;
            // Server IP
            $host = parse_url( $home_url, PHP_URL_HOST );
            $home_data['ip'] = $host ? gethostbyname( $host ) : '';
        }

        // ── Last 10 posts/pages — security headers only ──────────────────────
        $posts    = get_posts( [
            'numberposts' => 10,
            'post_status' => 'publish',
            'post_type'   => [ 'post', 'page' ],
            'orderby'     => 'date',
            'order'       => 'DESC',
        ] );
        $page_results = [];
        foreach ( $posts as $post ) {
            $purl = get_permalink( $post->ID );
            if ( ! $purl || $purl === $home_url ) { continue; }
            $d = $analyse( $purl );
            if ( isset( $d['sec'] ) ) { unset( $d['all_headers'] ); } // keep payload small
            $page_results[] = $d;
        }

        // Save to header scan history (last 20 entries).
        $scan_history = get_option( 'csdt_header_scan_history', [] );
        if ( ! is_array( $scan_history ) ) { $scan_history = []; }
        if ( ! isset( $home_data['error'] ) ) {
            array_unshift( $scan_history, [
                'grade'      => $home_data['grade'] ?? '?',
                'scanned_at' => time(),
                'missing'    => $missing_mand ?? 0,
                'warnings'   => count( $warnings ),
            ] );
            $scan_history = array_slice( $scan_history, 0, 20 );
            update_option( 'csdt_header_scan_history', $scan_history, false );
        }

        wp_send_json_success( [
            'home'         => $home_data,
            'pages'        => $page_results,
            'scan_history' => $scan_history,
        ] );
    }

    public static function render_header_scan_panel(): void {
        $history = get_option( 'csdt_header_scan_history', [] );
        if ( ! is_array( $history ) ) { $history = []; }
        $latest = $history[0] ?? null;
        $grade_colors = [ 'A+' => '#15803d', 'A' => '#16a34a', 'B' => '#d97706', 'C' => '#b45309', 'D' => '#dc2626', 'F' => '#991b1b' ];
        ?>
        <div class="cs-section-header" style="background:linear-gradient(90deg,#0f172a 0%,#1e3a5f 100%);border-left:3px solid #60a5fa;border-radius:6px 6px 0 0;margin-bottom:0;justify-content:space-between;flex-wrap:wrap;gap:8px">
            <span>🔍 <?php esc_html_e( 'Header Security Scan', 'cloudscale-devtools' ); ?></span>
            <span style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span id="cs-header-scan-last" style="font-size:11px;color:rgba(255,255,255,.5)">
                    <?php
                    if ( $latest ) {
                        $grade = $latest['grade'] ?? '?';
                        $gc    = $grade_colors[ $grade ] ?? '#9ca3af';
                        echo 'Last: ' . esc_html( human_time_diff( $latest['scanned_at'] ) ) . ' ago &nbsp;';
                        echo '<span style="font-family:monospace;font-weight:700;color:' . esc_attr( $gc ) . '">' . esc_html( $grade ) . '</span>';
                    } else {
                        esc_html_e( 'Not yet scanned', 'cloudscale-devtools' );
                    }
                    ?>
                </span>
                <button type="button" id="cs-csp-scan-btn" class="button" style="background:#3b82f6;color:#fff;border-color:#2563eb;font-weight:600;padding:5px 14px">
                    🔍 <?php esc_html_e( 'Scan Headers Now', 'cloudscale-devtools' ); ?>
                </button>
                <span id="cs-csp-scan-spinner" style="display:none;font-size:12px;color:rgba(255,255,255,.6)"><?php esc_html_e( 'Scanning…', 'cloudscale-devtools' ); ?></span>
            </span>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 6px 6px;padding:16px 20px;margin-bottom:20px">
            <p style="font-size:12px;color:#94a3b8;margin:0 0 12px"><?php esc_html_e( 'Checks the homepage and last 10 published posts/pages for duplicate CSP headers, missing security headers, and plugin conflicts.', 'cloudscale-devtools' ); ?></p>

            <!-- Timeline chart -->
            <div id="cs-header-scan-chart-wrap" style="margin-bottom:14px;<?php echo count( $history ) < 2 ? 'display:none' : ''; ?>">
                <canvas id="cs-header-scan-chart" height="120" style="width:100%;display:block;border:1px solid #e0e0e0;border-radius:4px;background:#fff"></canvas>
            </div>

            <!-- Scan results populated by JS -->
            <div id="cs-csp-scan-results"></div>

            <!-- Scan history list -->
            <div id="cs-header-scan-history" style="margin-top:14px;border-top:1px solid #f1f5f9;padding-top:12px;<?php echo empty( $history ) ? 'display:none' : ''; ?>">
                <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:#64748b;margin-bottom:8px" id="cs-header-scan-history-title">
                    <?php echo esc_html( sprintf( __( 'Scan History (%d)', 'cloudscale-devtools' ), count( $history ) ) ); ?>
                </div>
                <div id="cs-header-scan-history-list" style="border:1px solid #e2e8f0;border-radius:6px;overflow:hidden">
                <?php foreach ( $history as $i => $entry ) :
                    $grade  = $entry['grade'] ?? '?';
                    $gc     = $grade_colors[ $grade ] ?? '#64748b';
                    $bg     = $i % 2 === 0 ? '#fff' : '#f8fafc';
                    $ts_str = isset( $entry['scanned_at'] ) ? human_time_diff( $entry['scanned_at'] ) . ' ago' : '';
                    $detail = [];
                    if ( ( $entry['missing'] ?? 0 ) > 0 ) { $detail[] = $entry['missing'] . ' missing header' . ( $entry['missing'] === 1 ? '' : 's' ); }
                    if ( ( $entry['warnings'] ?? 0 ) > 0 ) { $detail[] = $entry['warnings'] . ' warning' . ( $entry['warnings'] === 1 ? '' : 's' ); }
                    ?>
                    <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:<?php echo esc_attr( $bg ); ?>;<?php echo $i > 0 ? 'border-top:1px solid #e2e8f0;' : ''; ?>">
                        <span style="font-size:17px;font-weight:900;color:<?php echo esc_attr( $gc ); ?>;width:26px;text-align:center;font-family:monospace;flex-shrink:0"><?php echo esc_html( $grade ); ?></span>
                        <span style="flex:1;font-size:11px;color:#64748b"><?php echo esc_html( implode( ' · ', $detail ) ?: __( 'All checks passed', 'cloudscale-devtools' ) ); ?></span>
                        <span style="font-size:11px;color:#94a3b8;white-space:nowrap"><?php echo esc_html( $ts_str ); ?></span>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- inline config block for header history; wp_add_inline_script not available at this render point ?>
        <script>
        (function(){
            var csHdrHistory = <?php echo wp_json_encode( $history ); ?>;

            var GRADE_SCORE = {'A+':100,'A':85,'B':65,'C':45,'D':25,'F':5};
            var GRADE_COLORS = {'A+':'#15803d','A':'#16a34a','B':'#d97706','C':'#b45309','D':'#dc2626','F':'#991b1b'};

            function drawHeaderChart() {
                var canvas = document.getElementById('cs-header-scan-chart');
                if (!canvas || csHdrHistory.length < 2) return;
                var W = canvas.offsetWidth || (window.innerWidth - 80);
                var H = 120, dpr = window.devicePixelRatio || 1;
                canvas.width = W * dpr; canvas.height = H * dpr;
                canvas.style.width = W + 'px'; canvas.style.height = H + 'px';
                var ctx = canvas.getContext('2d');
                ctx.scale(dpr, dpr);
                var pad = {t:16, r:16, b:28, l:36};
                var cw = W - pad.l - pad.r, ch = H - pad.t - pad.b;

                // Grid + y-labels
                ctx.font = '9px sans-serif'; ctx.textAlign = 'right';
                ['A+','A','B','C','F'].forEach(function(g){
                    var y = pad.t + ch - (GRADE_SCORE[g]/100)*ch;
                    ctx.strokeStyle = '#f0f0f1'; ctx.lineWidth = 1;
                    ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(W-pad.r, y); ctx.stroke();
                    ctx.fillStyle = '#9ca3af'; ctx.fillText(g, pad.l-4, y+3);
                });

                var data = csHdrHistory.slice().reverse();
                var n = data.length;
                function px(i){ return pad.l + (n<=1 ? cw/2 : (i/(n-1))*cw); }
                function py(d){ return pad.t + ch - ((GRADE_SCORE[d.grade]||50)/100)*ch; }

                // Fill
                ctx.beginPath();
                data.forEach(function(d,i){ i===0 ? ctx.moveTo(px(i),py(d)) : ctx.lineTo(px(i),py(d)); });
                ctx.lineTo(px(n-1), pad.t+ch); ctx.lineTo(px(0), pad.t+ch); ctx.closePath();
                ctx.fillStyle = 'rgba(59,130,246,0.08)'; ctx.fill();

                // Line
                ctx.beginPath(); ctx.strokeStyle = '#3b82f6'; ctx.lineWidth = 2;
                data.forEach(function(d,i){ i===0 ? ctx.moveTo(px(i),py(d)) : ctx.lineTo(px(i),py(d)); });
                ctx.stroke();

                // Dots + grade labels
                data.forEach(function(d,i){
                    var x = px(i), y = py(d);
                    ctx.beginPath(); ctx.arc(x, y, 4, 0, Math.PI*2);
                    ctx.fillStyle = GRADE_COLORS[d.grade] || '#3b82f6'; ctx.fill();
                    ctx.strokeStyle = '#fff'; ctx.lineWidth = 1.5; ctx.stroke();
                    ctx.font = 'bold 9px monospace'; ctx.fillStyle = GRADE_COLORS[d.grade] || '#3b82f6';
                    ctx.textAlign = 'center'; ctx.fillText(d.grade, x, y-7);
                });

                // X-axis dates
                ctx.font = '9px sans-serif'; ctx.textAlign = 'center'; ctx.fillStyle = '#9ca3af';
                var step = Math.max(1, Math.floor(n/5));
                data.forEach(function(d,i){
                    if (i % step !== 0 && i !== n-1) return;
                    var dt = d.scanned_at ? new Date(d.scanned_at*1000) : null;
                    if (dt) ctx.fillText((dt.getMonth()+1)+'/'+dt.getDate(), px(i), H-4);
                });
            }

            requestAnimationFrame(drawHeaderChart);
            window.addEventListener('resize', drawHeaderChart);
            window.csHdrHistory = csHdrHistory;
            window.csRedrawHeaderChart = drawHeaderChart;

            // Called by cs-csp.js after a new scan completes
            window.csUpdateHeaderScanHistory = function(newHistory) {
                if (!newHistory || !newHistory.length) return;
                csHdrHistory = newHistory;
                window.csHdrHistory = csHdrHistory;

                // Show/redraw chart
                var wrap = document.getElementById('cs-header-scan-chart-wrap');
                if (wrap && csHdrHistory.length >= 2) { wrap.style.display = ''; requestAnimationFrame(drawHeaderChart); }

                // Update last-scan badge
                var lastEl = document.getElementById('cs-header-scan-last');
                var entry = csHdrHistory[0];
                var gc = {'A+':'#15803d','A':'#16a34a','B':'#d97706','C':'#b45309','D':'#dc2626','F':'#991b1b'}[entry.grade] || '#9ca3af';
                if (lastEl) lastEl.innerHTML = 'Last: just now &nbsp;<span style="font-family:monospace;font-weight:700;color:'+gc+'">'+entry.grade+'</span>';

                // Prepend to history list
                var histWrap = document.getElementById('cs-header-scan-history');
                var list     = document.getElementById('cs-header-scan-history-list');
                var titleEl  = document.getElementById('cs-header-scan-history-title');
                if (histWrap) histWrap.style.display = '';
                if (titleEl) titleEl.textContent = 'Scan History (' + csHdrHistory.length + ')';
                if (!list) return;
                var detail = [];
                if (entry.missing > 0) detail.push(entry.missing + ' missing header' + (entry.missing===1?'':'s'));
                if (entry.warnings > 0) detail.push(entry.warnings + ' warning' + (entry.warnings===1?'':'s'));
                var row = document.createElement('div');
                row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:8px 12px;background:#f0fdf4;border-bottom:1px solid #e2e8f0';
                row.innerHTML = '<span style="font-size:17px;font-weight:900;color:'+gc+';width:26px;text-align:center;font-family:monospace;flex-shrink:0">'+entry.grade+'</span>' +
                    '<span style="flex:1;font-size:11px;color:#64748b">'+(detail.join(' · ')||'All checks passed')+'</span>' +
                    '<span style="font-size:11px;color:#94a3b8;white-space:nowrap">just now</span>';
                list.prepend(row);
            };
        })();
        </script>
        <?php
    }

    public static function ajax_scan_history_item(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized', 403 ); }
        $idx     = (int) ( $_POST['idx'] ?? -1 );
        $history = get_option( 'csdt_scan_history', [] );
        if ( ! is_array( $history ) || ! isset( $history[ $idx ] ) ) {
            wp_send_json_error( 'Not found' );
        }
        wp_send_json_success( $history[ $idx ] );
    }

}
