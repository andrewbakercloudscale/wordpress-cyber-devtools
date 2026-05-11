<?php
/**
 * APCu Object Cache Drop-in for WordPress
 *
 * Eliminates N+1 patterns from WordPress core (wp_options, wp_users, wp_posts,
 * wp_count_comments) by keeping them in PHP shared memory between requests.
 * No Redis, no Memcached, no extra services — just APCu (built into PHP).
 *
 * INSTALLATION (one-time, run from repo root):
 *   bash wordpress-cyber-devtools/install-object-cache.sh
 *
 * REQUIREMENTS:
 *   - PHP APCu extension: docker exec pi_wordpress apt-get install -y php-apcu
 *   - Restart container:  docker restart pi_wordpress
 *
 * Falls back to in-memory-only mode if APCu is not loaded (safe, no breakage).
 *
 * @package CloudScale_DevTools
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CSDT_APCU_AVAILABLE', extension_loaded( 'apcu' ) && ( php_sapi_name() === 'cli' ? (bool) ini_get( 'apc.enable_cli' ) : true ) );

// ── Global functions WordPress calls ─────────────────────────────────────────

function wp_cache_init() {
    global $wp_object_cache;
    $wp_object_cache = new WP_Object_Cache();
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
    global $wp_object_cache;
    return $wp_object_cache->add( $key, $data, $group, (int) $expire );
}

function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ) {
    global $wp_object_cache;
    $results = [];
    foreach ( $data as $k => $v ) {
        $results[ $k ] = $wp_object_cache->add( $k, $v, $group, (int) $expire );
    }
    return $results;
}

function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
    global $wp_object_cache;
    return $wp_object_cache->replace( $key, $data, $group, (int) $expire );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
    global $wp_object_cache;
    return $wp_object_cache->set( $key, $data, $group, (int) $expire );
}

function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) {
    global $wp_object_cache;
    $results = [];
    foreach ( $data as $k => $v ) {
        $results[ $k ] = $wp_object_cache->set( $k, $v, $group, (int) $expire );
    }
    return $results;
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
    global $wp_object_cache;
    return $wp_object_cache->get( $key, $group, $force, $found );
}

function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
    global $wp_object_cache;
    return $wp_object_cache->get_multiple( $keys, $group, $force );
}

function wp_cache_delete( $key, $group = '' ) {
    global $wp_object_cache;
    return $wp_object_cache->delete( $key, $group );
}

function wp_cache_delete_multiple( array $keys, $group = '' ) {
    global $wp_object_cache;
    $results = [];
    foreach ( $keys as $k ) {
        $results[ $k ] = $wp_object_cache->delete( $k, $group );
    }
    return $results;
}

function wp_cache_flush() {
    global $wp_object_cache;
    return $wp_object_cache->flush();
}

function wp_cache_flush_runtime() {
    global $wp_object_cache;
    return $wp_object_cache->flush_runtime();
}

function wp_cache_flush_group( $group ) {
    global $wp_object_cache;
    return $wp_object_cache->flush_group( $group );
}

function wp_cache_close() {
    return true;
}

function wp_cache_switch_to_blog( $blog_id ) {
    global $wp_object_cache;
    $wp_object_cache->switch_to_blog( $blog_id );
}

function wp_cache_add_global_groups( $groups ) {
    global $wp_object_cache;
    $wp_object_cache->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
    global $wp_object_cache;
    $wp_object_cache->add_non_persistent_groups( $groups );
}

function wp_cache_incr( $key, $offset = 1, $group = '' ) {
    global $wp_object_cache;
    return $wp_object_cache->incr( $key, (int) $offset, $group );
}

function wp_cache_decr( $key, $offset = 1, $group = '' ) {
    global $wp_object_cache;
    return $wp_object_cache->decr( $key, (int) $offset, $group );
}

function wp_cache_supports( $feature ) {
    switch ( $feature ) {
        case 'add_multiple':
        case 'set_multiple':
        case 'get_multiple':
        case 'delete_multiple':
        case 'flush_runtime':
        case 'flush_group':
            return true;
        default:
            return false;
    }
}

// ── WP_Object_Cache ───────────────────────────────────────────────────────────

class WP_Object_Cache {

    private string $blog_prefix      = '';
    private array  $global_groups    = [];
    private array  $non_persistent   = [];
    private array  $local            = [];   // per-request mirror

    public function __construct() {
        global $blog_id;
        $this->blog_prefix = is_multisite() ? ( (int) $blog_id ) . ':' : '';
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private function key( $key, string $group ): string {
        $g = $group ?: 'default';
        $p = in_array( $g, $this->global_groups, true ) ? '' : $this->blog_prefix;
        return 'wp:' . $p . $g . ':' . $key;
    }

    private function persistent( string $group ): bool {
        $g = $group ?: 'default';
        return CSDT_APCU_AVAILABLE && ! in_array( $g, $this->non_persistent, true );
    }

    private function copy( $val ) {
        return is_object( $val ) ? clone $val : $val;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    public function get( $key, $group = '', bool $force = false, ?bool &$found = null ) {
        $apc_key = $this->key( $key, $group ?: 'default' );

        if ( ! $force && array_key_exists( $apc_key, $this->local ) ) {
            $found = true;
            return $this->copy( $this->local[ $apc_key ] );
        }

        if ( $this->persistent( $group ) ) {
            $ok    = false;
            $value = apcu_fetch( $apc_key, $ok );
            $found = $ok;
            if ( $ok ) {
                $this->local[ $apc_key ] = $value;
                return $this->copy( $value );
            }
            return false;
        }

        $found = false;
        return false;
    }

    public function get_multiple( array $keys, $group = '', bool $force = false ): array {
        $out = [];
        foreach ( $keys as $k ) {
            $found        = null;
            $out[ $k ] = $this->get( $k, $group, $force, $found );
            if ( ! $found ) {
                $out[ $k ] = false;
            }
        }
        return $out;
    }

    public function set( $key, $data, $group = '', int $expire = 0 ): bool {
        $apc_key = $this->key( $key, $group ?: 'default' );
        $stored  = $this->copy( $data );
        $this->local[ $apc_key ] = $stored;

        if ( $this->persistent( $group ) ) {
            // expire 0 = no TTL (APCu evicts on memory pressure)
            return (bool) apcu_store( $apc_key, $stored, max( 0, $expire ) );
        }
        return true;
    }

    public function add( $key, $data, $group = '', int $expire = 0 ): bool {
        $apc_key = $this->key( $key, $group ?: 'default' );
        if ( array_key_exists( $apc_key, $this->local ) ) {
            return false;
        }
        if ( $this->persistent( $group ) && apcu_exists( $apc_key ) ) {
            return false;
        }
        return $this->set( $key, $data, $group, $expire );
    }

    public function replace( $key, $data, $group = '', int $expire = 0 ): bool {
        $apc_key   = $this->key( $key, $group ?: 'default' );
        $in_local  = array_key_exists( $apc_key, $this->local );
        $in_apcu   = $this->persistent( $group ) && apcu_exists( $apc_key );
        if ( ! $in_local && ! $in_apcu ) {
            return false;
        }
        return $this->set( $key, $data, $group, $expire );
    }

    public function delete( $key, $group = '' ): bool {
        $apc_key = $this->key( $key, $group ?: 'default' );
        unset( $this->local[ $apc_key ] );
        if ( $this->persistent( $group ) ) {
            apcu_delete( $apc_key );
        }
        return true;
    }

    public function flush(): bool {
        $this->local = [];
        if ( CSDT_APCU_AVAILABLE ) {
            apcu_clear_cache();
        }
        return true;
    }

    public function flush_runtime(): bool {
        $this->local = [];
        return true;
    }

    public function flush_group( $group ): bool {
        $g      = $group ?: 'default';
        $prefix = 'wp:' . ( in_array( $g, $this->global_groups, true ) ? '' : $this->blog_prefix ) . $g . ':';
        foreach ( array_keys( $this->local ) as $k ) {
            if ( str_starts_with( $k, $prefix ) ) {
                unset( $this->local[ $k ] );
            }
        }
        if ( CSDT_APCU_AVAILABLE ) {
            $iter = new APCUIterator( '/^' . preg_quote( $prefix, '/' ) . '/', APC_ITER_KEY );
            apcu_delete( $iter );
        }
        return true;
    }

    public function incr( $key, int $offset = 1, $group = '' ) {
        $val = $this->get( $key, $group );
        if ( false === $val ) {
            return false;
        }
        $val = max( 0, (int) $val + $offset );
        $this->set( $key, $val, $group );
        return $val;
    }

    public function decr( $key, int $offset = 1, $group = '' ) {
        return $this->incr( $key, -$offset, $group );
    }

    public function switch_to_blog( int $blog_id ): void {
        $this->blog_prefix = is_multisite() ? $blog_id . ':' : '';
    }

    public function add_global_groups( $groups ): void {
        $this->global_groups = array_unique( array_merge( $this->global_groups, (array) $groups ) );
    }

    public function add_non_persistent_groups( $groups ): void {
        $this->non_persistent = array_unique( array_merge( $this->non_persistent, (array) $groups ) );
    }

    public function stats(): void {}

    public function is_valid_key( $key ): bool {
        return is_string( $key ) || is_int( $key );
    }
}
