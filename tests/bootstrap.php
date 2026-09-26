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

require dirname( __DIR__ ) . '/wpws.php';
