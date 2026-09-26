<?php
/**
 * Minimal WordPress stand-ins so the plugin's pure logic (table helpers,
 * parser, JSONPath, SSRF checks, auth profiles, callback allow-list) can be
 * unit-tested without a WordPress install or database.
 *
 * Only what the tested code paths call is stubbed. Anything that needs real
 * WordPress (HTTP, transients, admin UI) is covered by manual/e2e testing.
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['wpws_test_options'] = array();
$GLOBALS['wpws_test_filters'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['wpws_test_options'] ) ? $GLOBALS['wpws_test_options'][ $name ] : $default;
}
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['wpws_test_filters'][ $hook ][] = $cb;
	return true;
}
function remove_all_filters( $hook ) {
	unset( $GLOBALS['wpws_test_filters'][ $hook ] );
}
function apply_filters( $hook, $value ) {
	foreach ( isset( $GLOBALS['wpws_test_filters'][ $hook ] ) ? $GLOBALS['wpws_test_filters'][ $hook ] : array() as $cb ) {
		$value = call_user_func( $cb, $value );
	}
	return $value;
}
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return true; }
function register_activation_hook( $file, $cb ) {}
function register_deactivation_hook( $file, $cb ) {}
function is_admin() { return false; }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/wpws/'; }
function plugin_basename( $file ) { return 'wpws/' . basename( $file ); }
function get_bloginfo( $what ) { return $what === 'charset' ? 'UTF-8' : ''; }
function wp_parse_args( $args, $defaults = array() ) {
	if ( is_object( $args ) ) $args = get_object_vars( $args );
	elseif ( ! is_array( $args ) ) parse_str( (string) $args, $args );
	return array_merge( $defaults, $args );
}
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }

// --- Escaping / kses -----------------------------------------------------------
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function esc_attr__( $s, $d = '' ) { return esc_attr( $s ); }
function wp_kses_post( $s ) { return preg_replace( '#<script\b.*?</script>#is', '', (string) $s ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }

// --- Users / context (tests flip these) -------------------------------------
$GLOBALS['wpws_test_caps']     = array();   // caps of the current user
$GLOBALS['wpws_test_user_caps']= array();   // user_id => caps
$GLOBALS['wpws_test_post']     = null;
$GLOBALS['wpws_test_preview']  = false;
function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['wpws_test_caps'], true ); }
function user_can( $user_id, $cap ) { return in_array( $cap, isset( $GLOBALS['wpws_test_user_caps'][ $user_id ] ) ? $GLOBALS['wpws_test_user_caps'][ $user_id ] : array(), true ); }
function is_user_logged_in() { return ! empty( $GLOBALS['wpws_test_caps'] ); }
function get_post() { return $GLOBALS['wpws_test_post']; }
function is_preview() { return $GLOBALS['wpws_test_preview']; }

// --- Transients / object cache (in memory) -----------------------------------
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['wpws_test_transients'] = array();
$GLOBALS['wpws_test_cache']      = array();
function get_transient( $k ) { return isset( $GLOBALS['wpws_test_transients'][ $k ] ) ? $GLOBALS['wpws_test_transients'][ $k ] : false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['wpws_test_transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['wpws_test_transients'][ $k ] ); return true; }
function wp_using_ext_object_cache() { return true; }
function wp_cache_add( $k, $v, $g = '', $ttl = 0 ) { if ( isset( $GLOBALS['wpws_test_cache'][ $g ][ $k ] ) ) return false; $GLOBALS['wpws_test_cache'][ $g ][ $k ] = $v; return true; }
function wp_cache_incr( $k, $n = 1, $g = '' ) { if ( ! isset( $GLOBALS['wpws_test_cache'][ $g ][ $k ] ) ) return false; return $GLOBALS['wpws_test_cache'][ $g ][ $k ] += $n; }
function wp_cache_delete( $k, $g = '' ) { unset( $GLOBALS['wpws_test_cache'][ $g ][ $k ] ); return true; }
function wp_next_scheduled( $h, $a = array() ) { return false; }
function wp_schedule_single_event( $t, $h, $a = array() ) { $GLOBALS['wpws_test_scheduled'][] = array( $h, $a ); return true; }
function remove_action( $h, $cb, $p = 10 ) { return true; }

// --- HTTP (scripted responses) ------------------------------------------------
class WP_Error {
	private $m;
	public function __construct( $code = '', $message = '' ) { $this->m = $message; }
	public function get_error_message() { return $this->m; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
$GLOBALS['wpws_test_http']     = array(); // queue of responses (array or WP_Error)
$GLOBALS['wpws_test_requests'] = array(); // log of [url, args]
function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['wpws_test_requests'][] = array( $url, $args );
	return array_shift( $GLOBALS['wpws_test_http'] ) ?: new WP_Error( 'x', 'no scripted response' );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : ''; }
function wp_remote_retrieve_response_message( $r ) { return is_array( $r ) ? $r['response']['message'] : ''; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
function wp_remote_retrieve_headers( $r ) { return is_array( $r ) ? $r['headers'] : array(); }
function wp_remote_retrieve_header( $r, $h ) { return ( is_array( $r ) && isset( $r['headers'][ $h ] ) ) ? $r['headers'][ $h ] : ''; }

require dirname( __DIR__ ) . '/wpws.php';
require dirname( __DIR__ ) . '/class.wpws-admin.php';
