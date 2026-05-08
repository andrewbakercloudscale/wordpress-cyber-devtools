<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Country lookup helper.
 *
 * Tries CF-IPCountry header first (zero overhead, accurate).
 * Falls back to the DB-IP Lite mmdb when the CF header is absent.
 *
 * DB file lives at: wp-content/uploads/csdt-geo/dbip-city-lite.mmdb
 * If the Analytics plugin's file is present at cspv-geo/, that is used as a
 * fallback so the 30 MB database isn't stored twice on the same site.
 */
class CSDT_Geo {

    // ── Country resolution ────────────────────────────────────────────────

    /**
     * Return a 2-letter ISO country code for the given IP.
     * Tries CF-IPCountry first, then DB-IP Lite.
     */
    public static function get_country( string $ip ): string {
        $cf_cc = isset( $_SERVER['HTTP_CF_IPCOUNTRY'] )
            ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) )
            : '';
        if ( strlen( $cf_cc ) === 2 && ctype_alpha( $cf_cc ) && $cf_cc !== 'XX' ) {
            return $cf_cc;
        }
        return self::lookup_dbip( $ip );
    }

    /**
     * DB-IP Lite lookup — returns 2-letter ISO code or '' on miss.
     */
    public static function lookup_dbip( string $ip ): string {
        if ( empty( $ip ) ) {
            return '';
        }
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
            return '';
        }

        $mmdb_path = self::mmdb_path();
        if ( '' === $mmdb_path ) {
            return '';
        }

        $autoload = plugin_dir_path( dirname( __FILE__ ) ) . 'lib/maxmind-db/autoload.php';
        if ( ! file_exists( $autoload ) ) {
            return '';
        }
        require_once $autoload;

        static $reader      = null;
        static $reader_path = '';

        if ( null === $reader || $reader_path !== $mmdb_path ) {
            try {
                $reader      = new \MaxMind\Db\Reader( $mmdb_path );
                $reader_path = $mmdb_path;
            } catch ( \Exception $e ) {
                $reader = null;
                return '';
            }
        }

        try {
            $record = $reader->get( $ip );
            if ( is_array( $record ) && isset( $record['country']['iso_code'] ) ) {
                return strtoupper( substr( $record['country']['iso_code'], 0, 2 ) );
            }
        } catch ( \Exception $e ) {
            // Invalid IP or corrupt DB — silent.
        }

        return '';
    }

    // ── Database file management ──────────────────────────────────────────

    /**
     * Absolute path to the active mmdb file, or '' if not installed.
     * Prefers the DevTools-own path; falls back to the Analytics plugin's
     * shared file so installations with both plugins share a single copy.
     */
    public static function mmdb_path(): string {
        $upload_dir = wp_upload_dir();
        $own_path   = $upload_dir['basedir'] . '/csdt-geo/dbip-city-lite.mmdb';
        if ( file_exists( $own_path ) ) {
            return $own_path;
        }
        $shared = $upload_dir['basedir'] . '/cspv-geo/dbip-city-lite.mmdb';
        if ( file_exists( $shared ) ) {
            return $shared;
        }
        return '';
    }

    /**
     * Download (or update) the DB-IP Lite mmdb for the current month.
     *
     * @return array|WP_Error  array { size } on success, WP_Error on failure.
     */
    public static function download_dbip() {
        $upload_dir = wp_upload_dir();
        $geo_dir    = $upload_dir['basedir'] . '/csdt-geo';
        $mmdb_path  = $geo_dir . '/dbip-city-lite.mmdb';
        $gz_path    = $geo_dir . '/dbip-city-lite.mmdb.gz';

        if ( ! file_exists( $geo_dir ) ) {
            wp_mkdir_p( $geo_dir );
        }

        $year     = gmdate( 'Y' );
        $month    = gmdate( 'm' );
        $url      = "https://download.db-ip.com/free/dbip-city-lite-{$year}-{$month}.mmdb.gz";
        $response = wp_remote_get( $url, [
            'timeout'  => 120,
            'stream'   => true,
            'filename' => $gz_path,
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'download_failed', $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            if ( file_exists( $gz_path ) ) {
                wp_delete_file( $gz_path );
            }
            return new WP_Error( 'http_error', "HTTP {$code} — the file for this month may not be available yet." );
        }

        $gz = gzopen( $gz_path, 'rb' );
        if ( ! $gz ) {
            if ( file_exists( $gz_path ) ) {
                wp_delete_file( $gz_path );
            }
            return new WP_Error( 'gz_open_failed', 'Could not open downloaded file.' );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $out = fopen( $mmdb_path, 'wb' );
        if ( ! $out ) {
            gzclose( $gz );
            if ( file_exists( $gz_path ) ) {
                wp_delete_file( $gz_path );
            }
            return new WP_Error( 'write_failed', 'Could not write database file.' );
        }

        while ( ! gzeof( $gz ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            fwrite( $out, gzread( $gz, 8192 ) );
        }
        gzclose( $gz );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $out );

        if ( file_exists( $gz_path ) ) {
            wp_delete_file( $gz_path );
        }

        $size = filesize( $mmdb_path );
        if ( $size < 1000000 ) {
            if ( file_exists( $mmdb_path ) ) {
                wp_delete_file( $mmdb_path );
            }
            return new WP_Error( 'file_too_small', 'Downloaded file is too small (' . size_format( $size ) . ').' );
        }

        update_option( 'csdt_dbip_last_updated', current_time( 'mysql' ) );
        update_option( 'csdt_dbip_installed_ym', gmdate( 'Y-m' ) );

        return [ 'size' => size_format( $size ) ];
    }

    // ── AJAX handler ──────────────────────────────────────────────────────

    public static function ajax_download_dbip(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $result = self::download_dbip();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( $result );
    }

    public static function ajax_save_dbip_settings(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $auto = isset( $_POST['auto_update'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['auto_update'] ) ) ? 'yes' : 'no';
        update_option( 'csdt_dbip_auto_update', $auto );
        wp_send_json_success();
    }

    // ── Cron auto-update ─────────────────────────────────────────────────

    /**
     * WP-Cron callback: download a fresh DB-IP Lite file if the installed
     * copy is from a previous month or is missing.
     */
    public static function auto_update_run(): void {
        if ( get_option( 'csdt_dbip_auto_update', 'yes' ) !== 'yes' ) {
            return;
        }
        $installed_ym = get_option( 'csdt_dbip_installed_ym', '' );
        if ( $installed_ym === gmdate( 'Y-m' ) ) {
            return;
        }
        self::download_dbip();
    }

    // ── UI helpers ────────────────────────────────────────────────────────

    /**
     * Render the DB-IP status + download/toggle row.
     * Called inline in the Attack Origins section of cs-code-block.php.
     */
    public static function render_dbip_status(): void {
        $mmdb_path    = self::mmdb_path();
        $installed    = '' !== $mmdb_path;
        $last_updated = get_option( 'csdt_dbip_last_updated', '' );
        $auto_update  = get_option( 'csdt_dbip_auto_update', 'yes' );
        $size_fmt     = $installed ? size_format( filesize( $mmdb_path ) ) : '';
        $updated_fmt  = $last_updated ? wp_date( 'j M Y', strtotime( $last_updated ) ) : '';

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
        <div id="csdt-dbip-status-row" style="margin-top:10px;padding:8px 12px;background:<?php echo $installed ? '#f0fdf4' : '#fff7ed'; ?>;border:1px solid <?php echo $installed ? '#86efac' : '#fed7aa'; ?>;border-radius:6px;display:flex;align-items:center;flex-wrap:wrap;gap:8px;font-size:11px;color:#374151;">
            <span style="flex:1;min-width:200px;">
                <?php if ( $installed ) : ?>
                    <span style="color:#15803d;font-weight:600;">✓ DB-IP Lite installed</span>
                    <?php if ( $size_fmt ) : ?>&nbsp;&mdash;&nbsp;<?php echo esc_html( $size_fmt ); ?><?php endif; ?>
                    <?php if ( $updated_fmt ) : ?>&nbsp;&mdash;&nbsp;updated <?php echo esc_html( $updated_fmt ); ?><?php endif; ?>
                    <span style="color:#64748b;">&nbsp;(country shown for all sites, not just Cloudflare)</span>
                <?php else : ?>
                    <span style="color:#b45309;font-weight:600;">⚠️ DB-IP Lite not installed</span>
                    <span style="color:#64748b;">&nbsp;&mdash;&nbsp;country data requires Cloudflare CF-IPCountry header</span>
                <?php endif; ?>
            </span>
            <label style="display:flex;align-items:center;gap:4px;cursor:pointer;white-space:nowrap;">
                <input type="checkbox" id="csdt-dbip-auto-update" <?php checked( $auto_update, 'yes' ); ?> style="margin:0;">
                <?php esc_html_e( 'Auto-update monthly', 'cloudscale-devtools' ); ?>
            </label>
            <button type="button" id="csdt-dbip-download-btn"
                    style="font-size:11px;padding:4px 10px;border-radius:5px;border:1px solid #3b82f6;background:#3b82f6;color:#fff;cursor:pointer;font-weight:600;white-space:nowrap;">
                <?php echo $installed ? esc_html__( '🔄 Update DB-IP Lite', 'cloudscale-devtools' ) : esc_html__( '⬇️ Download DB-IP Lite', 'cloudscale-devtools' ); ?>
            </button>
            <span id="csdt-dbip-msg" style="font-size:11px;display:none;"></span>
        </div>
        <?php
        // phpcs:enable
    }
}
