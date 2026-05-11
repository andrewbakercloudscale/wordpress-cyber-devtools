<?php
/**
 * AI Dispatcher — single entry point for all Anthropic / Gemini calls.
 *
 * Replaces the duplicated provider logic that previously lived in both
 * dispatch_ai_call() (wp_remote_post) and build_ai_curl_handle() (curl_multi).
 * All callers should use this class instead of rolling their own HTTP.
 *
 * @package CloudScale_DevTools
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CSDT_AI_Dispatcher {

    // ── Model aliases ────────────────────────────────────────────────

    private static function resolve_model( string $provider, string $model ): string {
        if ( $provider === 'gemini' ) {
            if ( $model === '_auto' || $model === '_auto_deep' ) { return 'gemini-1.5-pro'; }
            return $model;
        }
        // Anthropic
        if ( $model === '_auto' )      { return 'claude-opus-4-7'; }
        if ( $model === '_auto_deep' ) { return 'claude-opus-4-7'; }
        return $model;
    }

    // ── Shared payload builder ────────────────────────────────────────

    /**
     * Returns [ 'url' => string, 'headers' => array, 'body' => string ].
     */
    private static function build_request( string $provider, string $system, string $user_message, string $model, int $max_tokens ): array {
        $model = self::resolve_model( $provider, $model );

        if ( $provider === 'gemini' ) {
            $key = get_option( 'csdt_devtools_gemini_key', '' );
            if ( ! $key ) { throw new \RuntimeException( 'No Gemini API key configured.' ); }
            return [
                'url'     => 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key ),
                'headers' => [ 'Content-Type: application/json' ],
                'body'    => wp_json_encode( [
                    'systemInstruction' => [ 'parts' => [ [ 'text' => $system ] ] ],
                    'contents'          => [ [ 'role' => 'user', 'parts' => [ [ 'text' => $user_message ] ] ] ],
                    'generationConfig'  => [ 'maxOutputTokens' => $max_tokens ],
                ] ),
            ];
        }

        // Anthropic
        $key = get_option( 'csdt_devtools_anthropic_key', '' );
        if ( ! $key ) { throw new \RuntimeException( 'No Anthropic API key configured.' ); }
        return [
            'url'     => 'https://api.anthropic.com/v1/messages',
            'headers' => [
                'x-api-key: ' . $key,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            'body'    => wp_json_encode( [
                'model'      => $model,
                'max_tokens' => $max_tokens,
                'system'     => $system,
                'messages'   => [ [ 'role' => 'user', 'content' => $user_message ] ],
            ] ),
        ];
    }

    // ── Parse raw response text ───────────────────────────────────────

    private static function parse_response_text( string $provider, string $raw_body ): string {
        $data = json_decode( $raw_body, true );
        if ( ! $data ) { throw new \RuntimeException( 'Empty or invalid API response.' ); }
        if ( isset( $data['error'] ) ) {
            throw new \RuntimeException( $data['error']['message'] ?? 'API error.' );
        }
        if ( $provider === 'gemini' ) {
            return trim( $data['candidates'][0]['content']['parts'][0]['text'] ?? '' );
        }
        return trim( $data['content'][0]['text'] ?? '' );
    }

    // ── Single call (wp_remote_post) ──────────────────────────────────

    /**
     * Make a single synchronous AI call. Returns the model's text response.
     *
     * @param string $force_provider Optional provider override ('anthropic'|'gemini'). Empty = use configured.
     * @throws \RuntimeException on API or network error.
     */
    public static function call( string $system, string $user_message, string $model, int $max_tokens, string $force_provider = '' ): string {
        $provider = $force_provider !== '' ? $force_provider : get_option( 'csdt_devtools_ai_provider', 'anthropic' );
        $req      = self::build_request( $provider, $system, $user_message, $model, $max_tokens );

        // Convert curl-style "Key: value" headers to WP associative format.
        $wp_headers = [];
        foreach ( $req['headers'] as $h ) {
            if ( strpos( $h, ': ' ) !== false ) {
                [ $name, $value ] = explode( ': ', $h, 2 );
                $wp_headers[ $name ] = $value;
            } else {
                $wp_headers[] = $h;
            }
        }

        $resp = wp_remote_post( $req['url'], [
            'timeout' => 180,
            'headers' => $wp_headers,
            'body'    => $req['body'],
        ] );

        if ( is_wp_error( $resp ) ) { throw new \RuntimeException( $resp->get_error_message() ); }
        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        if ( $code !== 200 ) {
            $api = json_decode( $body, true );
            $msg = $api['error']['message'] ?? "HTTP {$code}";
            if ( $code === 529 ) {
                $msg = 'Anthropic API is overloaded — please try again in a few seconds.';
            } elseif ( $code === 429 ) {
                $msg = 'Rate limit reached — please wait a moment and try again.';
            }
            throw new \RuntimeException( $msg );
        }
        return self::parse_response_text( $provider, $body );
    }

    // ── Parallel calls (curl_multi) ───────────────────────────────────

    /**
     * Fire multiple AI calls in parallel.
     *
     * Each $calls entry: [ 'system' => string, 'user' => string, 'model' => string, 'max_tokens' => int ]
     * Returns an array (same indexes) of text responses.
     *
     * @throws \RuntimeException on build or parse error.
     */
    public static function call_parallel( array $calls ): array {
        $provider = get_option( 'csdt_devtools_ai_provider', 'anthropic' );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_init -- wp_remote_* has no parallel multi-handle equivalent; curl_multi is required for concurrent AI calls.
        $mh       = curl_multi_init();
        $handles  = [];

        foreach ( $calls as $i => $call ) {
            $req  = self::build_request( $provider, $call['system'], $call['user'], $call['model'], $call['max_tokens'] );
            $ch   = curl_init();
            curl_setopt_array( $ch, [
                CURLOPT_URL            => $req['url'],
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $req['body'],
                CURLOPT_HTTPHEADER     => $req['headers'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 180,
                CURLOPT_SSL_VERIFYPEER => true,
            ] );
            $handles[ $i ] = $ch;
            curl_multi_add_handle( $mh, $ch );
        }

        $running = null;
        do {
            curl_multi_exec( $mh, $running );
            if ( $running ) { curl_multi_select( $mh, 1.0 ); }
        } while ( $running > 0 );

        $texts = [];
        foreach ( $handles as $i => $ch ) {
            $texts[ $i ] = self::parse_response_text( $provider, (string) curl_multi_getcontent( $ch ) );
            curl_multi_remove_handle( $mh, $ch );
            curl_close( $ch );
        }
        curl_multi_close( $mh );
        return $texts;
    }

    // ── JSON response helpers ─────────────────────────────────────────

    /**
     * Strip markdown fences and decode JSON. Throws if missing 'score' key.
     */
    public static function parse_json_report( string $text ): array {
        $text   = preg_replace( '/^```(?:json)?\s*/i', '', trim( $text ) );
        $text   = preg_replace( '/\s*```$/', '', $text );
        $report = json_decode( $text, true );
        if ( ! $report || ! isset( $report['score'] ) ) {
            throw new \RuntimeException( 'AI returned unexpected format.' );
        }
        return $report;
    }

    /**
     * Merge two scan reports (internal 45% + external 55% weighting).
     */
    public static function merge_reports( array $a, array $b ): array {
        $score = (int) round( $a['score'] * 0.45 + $b['score'] * 0.55 );
        $label = $score >= 90 ? 'Excellent' : ( $score >= 75 ? 'Good' : ( $score >= 55 ? 'Fair' : ( $score >= 35 ? 'Poor' : 'Critical' ) ) );
        $sum_a = rtrim( $a['summary'] ?? '', '. ' );
        $sum_b = ltrim( $b['summary'] ?? '' );
        return [
            'score'       => $score,
            'score_label' => $label,
            'summary'     => $sum_a . '. ' . $sum_b,
            'critical'    => array_merge( $a['critical'] ?? [], $b['critical'] ?? [] ),
            'high'        => array_merge( $a['high']     ?? [], $b['high']     ?? [] ),
            'medium'      => array_merge( $a['medium']   ?? [], $b['medium']   ?? [] ),
            'low'         => array_merge( $a['low']      ?? [], $b['low']      ?? [] ),
            'good'        => array_merge( $a['good']     ?? [], $b['good']     ?? [] ),
        ];
    }

    // ── Background execution helper ───────────────────────────────────

    /**
     * Send a JSON success response to the browser immediately, then keep
     * the PHP process alive for background work (PHP-FPM / fastcgi).
     */
    public static function send_and_continue( array $data ): void {
        if ( function_exists( 'set_time_limit' ) ) { set_time_limit( 0 ); } // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required for background AI calls that must not time out.
        while ( ob_get_level() ) { ob_end_clean(); }
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Connection: close' );
        $body = wp_json_encode( [ 'success' => true, 'data' => $data ] );
        header( 'Content-Length: ' . strlen( $body ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $body;
        flush();
        if ( function_exists( 'fastcgi_finish_request' ) ) { fastcgi_finish_request(); }
    }

    // ── Convenience helpers ───────────────────────────────────────────

    /** Returns true when at least one text-AI API key is configured. */
    public static function has_key(): bool {
        return ! empty( get_option( 'csdt_devtools_anthropic_key', '' ) )
            || ! empty( get_option( 'csdt_devtools_gemini_key', '' ) );
    }

    /** Returns true when an OpenAI key is configured. */
    public static function has_openai_key(): bool {
        return ! empty( get_option( 'csdt_devtools_openai_key', '' ) );
    }

    /**
     * Call GPT-4o mini for text generation (uses the OpenAI key).
     *
     * @throws \RuntimeException on API or network error.
     */
    public static function call_openai_text( string $system, string $user_message, int $max_tokens = 300, string $model = 'gpt-4o-mini' ): string {
        if ( $model === '_auto' ) { $model = 'gpt-4o'; }
        $key = get_option( 'csdt_devtools_openai_key', '' );
        if ( ! $key ) { throw new \RuntimeException( 'No OpenAI API key configured.' ); }

        $resp = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'      => $model,
                'max_tokens' => $max_tokens,
                'messages'   => [
                    [ 'role' => 'system', 'content' => $system ],
                    [ 'role' => 'user',   'content' => $user_message ],
                ],
            ] ),
        ] );

        if ( is_wp_error( $resp ) ) { throw new \RuntimeException( $resp->get_error_message() ); }
        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        $data = json_decode( $body, true );
        if ( $code !== 200 ) {
            throw new \RuntimeException( $data['error']['message'] ?? "HTTP {$code}" );
        }
        return trim( $data['choices'][0]['message']['content'] ?? '' );
    }

    /**
     * Generate an image via DALL-E 3. Returns the temporary image URL.
     *
     * @param string $prompt  The image description.
     * @param string $size    DALL-E size string — '1792x1024' (default landscape) or '1024x1024'.
     * @param string $quality 'standard' or 'hd'.
     * @throws \RuntimeException on API or network error.
     */
    public static function generate_image( string $prompt, string $size = '1792x1024', string $quality = 'standard' ): string {
        $key = get_option( 'csdt_devtools_openai_key', '' );
        if ( ! $key ) { throw new \RuntimeException( 'No OpenAI API key configured.' ); }


        $resp = wp_remote_post( 'https://api.openai.com/v1/images/generations', [
            'timeout' => 90,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'   => 'dall-e-3',
                'prompt'  => $prompt,
                'n'       => 1,
                'size'    => $size,
                'quality' => $quality,
            ] ),
        ] );

        if ( is_wp_error( $resp ) ) { throw new \RuntimeException( $resp->get_error_message() ); }
        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        $data = json_decode( $body, true );
        if ( $code !== 200 ) {
            throw new \RuntimeException( $data['error']['message'] ?? "HTTP {$code}" );
        }
        $url = $data['data'][0]['url'] ?? '';
        if ( ! $url ) { throw new \RuntimeException( 'DALL-E returned no image URL.' ); }
        return $url;
    }

    /**
     * Returns the active provider config: [ 'provider', 'key' ].
     * 'key' is empty string when none is configured.
     *
     * @return array{ provider: string, key: string }
     */
    public static function get_config(): array {
        $provider = get_option( 'csdt_devtools_ai_provider', 'anthropic' );
        $key      = $provider === 'gemini'
            ? get_option( 'csdt_devtools_gemini_key', '' )
            : get_option( 'csdt_devtools_anthropic_key', '' );
        return [ 'provider' => $provider, 'key' => $key ];
    }
}
