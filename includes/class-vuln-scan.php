<?php
/**
 * Vulnerability Scanner — WPScan API key management and scan execution.
 *
 * @package CloudScale_DevTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CSDT_Vuln_Scan {

    public static function ajax_vuln_save_key(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $provider        = isset( $_POST['provider'] )    ? sanitize_key( wp_unslash( $_POST['provider'] ) )             : 'anthropic';
        $raw_key         = isset( $_POST['api_key'] )     ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) )       : '';
        $raw_gemini      = isset( $_POST['gemini_key'] )  ? sanitize_text_field( wp_unslash( $_POST['gemini_key'] ) )    : '';
        $clean_key       = trim( str_replace( '•', '', $raw_key ) );
        $clean_gemini    = trim( str_replace( '•', '', $raw_gemini ) );
        $model           = isset( $_POST['model'] )       ? sanitize_text_field( wp_unslash( $_POST['model'] ) )         : '_auto';
        $deep_model      = isset( $_POST['deep_model'] )  ? sanitize_text_field( wp_unslash( $_POST['deep_model'] ) )   : '_auto_deep';
        $prompt          = isset( $_POST['prompt'] )      ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) )   : '';

        update_option( 'csdt_devtools_ai_provider',    $provider,   true );
        if ( $clean_key     !== '' ) { update_option( 'csdt_devtools_anthropic_key', $clean_key,    true ); }
        if ( $clean_gemini  !== '' ) { update_option( 'csdt_devtools_gemini_key',    $clean_gemini, true ); }
        update_option( 'csdt_devtools_security_model',  $model,      true );
        update_option( 'csdt_devtools_deep_scan_model', $deep_model, true );
        update_option( 'csdt_devtools_security_prompt', $prompt,     true );
        delete_option( 'csdt_security_scan_v2' );
        delete_option( 'csdt_deep_scan_v1' );

        $saved_ant = get_option( 'csdt_devtools_anthropic_key', '' );
        $saved_gem = get_option( 'csdt_devtools_gemini_key', '' );
        $has_key   = $provider === 'gemini' ? ! empty( $saved_gem ) : ! empty( $saved_ant );
        wp_send_json_success( [
            'saved'         => true,
            'has_key'       => $has_key,
            'masked'        => $saved_ant ? '••••••••' . substr( $saved_ant, -4 ) : '',
            'maskedGemini'  => $saved_gem ? '••••••••' . substr( $saved_gem, -4 ) : '',
        ] );
    }

    public static function ajax_security_test_key(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'anthropic';
        $raw_key  = isset( $_POST['api_key'] )  ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        $key      = trim( str_replace( '•', '', $raw_key ) );

        if ( $provider === 'gemini' ) {
            if ( ! $key ) { $key = get_option( 'csdt_devtools_gemini_key', '' ); }
            if ( ! $key ) { wp_send_json_error( [ 'message' => 'No Gemini API key provided.' ] ); return; }

            $url  = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . rawurlencode( $key );
            $resp = wp_remote_post( $url, [
                'timeout' => 15,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [ 'contents' => [ [ 'role' => 'user', 'parts' => [ [ 'text' => 'Hi' ] ] ] ] ] ),
            ] );
        } else {
            if ( ! $key ) { $key = get_option( 'csdt_devtools_anthropic_key', '' ); }
            if ( ! $key ) { wp_send_json_error( [ 'message' => 'No API key provided.' ] ); return; }

            $resp = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
                'timeout' => 15,
                'headers' => [
                    'x-api-key'         => $key,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ],
                'body' => wp_json_encode( [
                    'model'      => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 10,
                    'messages'   => [ [ 'role' => 'user', 'content' => 'Hi' ] ],
                ] ),
            ] );
        }

        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'message' => 'Connection error: ' . $resp->get_error_message() ] );
            return;
        }

        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code === 200 ) {
            wp_send_json_success( [ 'valid' => true, 'message' => '✓ API key is valid' ] );
        } else {
            $body = json_decode( wp_remote_retrieve_body( $resp ), true );
            $err  = $body['error']['message'] ?? $body['error']['status'] ?? "HTTP {$code}";
            wp_send_json_error( [ 'valid' => false, 'message' => $err ] );
        }
    }

    public static function ajax_vuln_scan(): void {
        check_ajax_referer( CloudScale_DevTools::SECURITY_NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $cache_only = ! empty( $_POST['cache_only'] );

        // Page-load pre-fill: return cache silently or signal nothing cached
        if ( $cache_only ) {
            $cached = get_option( 'csdt_security_scan_v2' );
            if ( $cached !== false ) {
                wp_send_json_success( array_merge( $cached, [ 'from_cache' => true ] ) );
            } else {
                wp_send_json_success( [ 'no_cache' => true ] );
            }
            return;
        }

        $ai_cfg = CSDT_AI_Dispatcher::get_config();
        if ( ! $ai_cfg['key'] ) {
            wp_send_json_error( [ 'message' => 'No API key configured.', 'need_key' => true ] );
            return;
        }

        // Clear previous result and mark as running
        delete_option( 'csdt_security_scan_v2' );
        set_transient( 'csdt_vuln_scan_status', [ 'status' => 'running', 'started_at' => time() ], 600 );

        // Send response immediately, then run scan after connection closes
        CSDT_AI_Dispatcher::send_and_continue( [ 'queued' => true ] );
        self::cron_vuln_scan();
        exit;
    }

    public static function cron_vuln_scan(): void {
        if ( function_exists( 'set_time_limit' ) ) { set_time_limit( 0 ); }

        if ( get_transient( 'csdt_vuln_scan_cancelled' ) ) {
            delete_transient( 'csdt_vuln_scan_cancelled' );
            return;
        }

        try {
            $model         = get_option( 'csdt_devtools_security_model', '_auto' );
            $system_prompt = get_option( 'csdt_devtools_security_prompt', '' ) ?: CSDT_Site_Audit::default_security_prompt();
            $user_message  = 'WordPress site security data (JSON):' . "\n\n" . wp_json_encode( CSDT_Site_Audit::gather_security_data(), JSON_PRETTY_PRINT );

            $text = CSDT_AI_Dispatcher::call( $system_prompt, $user_message, $model, 4096 );
        } catch ( \Throwable $e ) {
            set_transient( 'csdt_vuln_scan_status', [ 'status' => 'error', 'message' => $e->getMessage() ], 300 );
            return;
        }

        $text   = preg_replace( '/^```(?:json)?\s*/i', '', trim( $text ) );
        $text   = preg_replace( '/\s*```$/', '', $text );
        $report = json_decode( $text, true );

        if ( ! $report || ! isset( $report['score'] ) ) {
            set_transient( 'csdt_vuln_scan_status', [ 'status' => 'error', 'message' => 'AI returned unexpected format.' ], 300 );
            return;
        }

        $output = [
            'report'     => $report,
            'model_used' => get_option( 'csdt_devtools_ai_provider', 'anthropic' ) . '/' . $model,
            'scanned_at' => time(),
            'from_cache' => false,
        ];

        update_option( 'csdt_security_scan_v2', $output, false );
        set_transient( 'csdt_vuln_scan_status', [ 'status' => 'complete', 'completed_at' => time() ], 600 );
        CSDT_Site_Audit::append_scan_history( 'standard', $report, $output['model_used'], $output['scanned_at'] );
    }

}
