<?php

use PHPUnit\Framework\TestCase;

/**
 * Cache layer (one slim, compressed entry per request), stale fallbacks,
 * stampede lock, conditional requests and request construction.
 * HTTP is scripted via the wp_remote_request() stub in bootstrap.php.
 */
final class CacheTest extends TestCase {

	const URL = 'https://93.184.216.34/page';

	protected function setUp(): void {
		$GLOBALS['wpws_test_transients'] = array();
		$GLOBALS['wpws_test_cache']      = array();
		$GLOBALS['wpws_test_http']       = array();
		$GLOBALS['wpws_test_requests']   = array();
		$GLOBALS['wpws_test_options']    = array();
	}

	private static function resp( $body, $code = 200, $headers = array() ) {
		return array( 'headers' => $headers, 'body' => $body, 'response' => array( 'code' => $code, 'message' => 'OK' ), 'cookies' => array() );
	}

	private static function entry_key() {
		$keys = preg_grep( '/^wpws_c_[0-9a-f]{32}$/', array_keys( $GLOBALS['wpws_test_transients'] ) );
		return reset( $keys );
	}

	private static function age_entry( $seconds ) {
		$k = self::entry_key();
		$GLOBALS['wpws_test_transients'][ $k ]['created'] -= $seconds;
	}

	public function test_fetch_then_cache_hit_with_single_compressed_entry() {
		$body = str_repeat( '<p>Zagreb</p>', 500 );
		$GLOBALS['wpws_test_http'][] = self::resp( $body, 200, array( 'content-type' => 'text/html', 'etag' => '"v1"', 'set-cookie' => 'x' ) );

		$r1 = WP_Web_Scraper::remote_request( self::URL, array( 'cache' => 60 ) );
		$r2 = WP_Web_Scraper::remote_request( self::URL, array( 'cache' => 60 ) );

		$this->assertSame( $body, $r1['body'] );
		$this->assertSame( $body, $r2['body'] );
		$this->assertSame( 'Cache-hit Transients API', $r2['headers']['X-WPWS-Cache-Control'] );
		$this->assertCount( 1, $GLOBALS['wpws_test_requests'] );

		$this->assertCount( 1, $GLOBALS['wpws_test_transients'], 'exactly one stored entry (no separate stale/ct/etag keys)' );
		$stored = $GLOBALS['wpws_test_transients'][ self::entry_key() ];
		$this->assertSame( 1, $stored['gz'] );
		$this->assertLessThan( strlen( $body ) / 10, strlen( serialize( $stored ) ), 'stored entry is much smaller than the body' );
		$this->assertArrayNotHasKey( 'set-cookie', $stored['headers'], 'only content-type/etag/last-modified are kept' );
	}

	public function test_cache_zero_never_stores() {
		$GLOBALS['wpws_test_http'][] = self::resp( 'a' );
		$GLOBALS['wpws_test_http'][] = self::resp( 'b' );
		WP_Web_Scraper::remote_request( self::URL, array( 'cache' => 0 ) );
		$r = WP_Web_Scraper::remote_request( self::URL, array( 'cache' => 0 ) );
		$this->assertSame( 'b', $r['body'] );
		$this->assertSame( array(), $GLOBALS['wpws_test_transients'] );
	}

	public function test_expired_entry_sends_conditional_request_and_304_refreshes() {
		$GLOBALS['wpws_test_http'][] = self::resp( 'old', 200, array( 'etag' => '"v1"', 'last-modified' => 'Mon, 01 Jan 2026 00:00:00 GMT' ) );
		WP_Web_Scraper::remote_request( self::URL, array( 'cache' => 1 ) );
		self::age_entry( 120 );

		$GLOBALS['wpws_test_http'][] = self::resp( '', 304 );
		$r = WP_Web_Scraper::remote_request( self::URL, array( 'cache' => 1 ) );

		$sent = $GLOBALS['wpws_test_requests'][1][1]['headers'];
		$this->assertSame( '"v1"', $sent['If-None-Match'] );
		$this->assertSame( 'old', $r['body'] );
		$this->assertSame( 'Cache-refreshed (304)', $r['headers']['X-WPWS-Cache-Control'] );
		$this->assertLessThan( 5, time() - $GLOBALS['wpws_test_transients'][ self::entry_key() ]['created'] );
	}

	public function test_stale_served_on_http_error_and_network_error() {
		$GLOBALS['wpws_test_http'][] = self::resp( 'good' );
		WP_Web_Scraper::remote_request( self::URL, array( 'cache' => 1 ) );
		self::age_entry( 120 );

		$GLOBALS['wpws_test_http'][] = self::resp( 'boom', 503 );
		$r = WP_Web_Scraper::remote_request( self::URL, array( 'cache' => 1 ) );
		$this->assertSame( 'good', $r['body'] );
		$this->assertSame( 'Stale-cache (HTTP 503)', $r['headers']['X-WPWS-Cache-Control'] );

		$GLOBALS['wpws_test_http'][] = new WP_Error( 'x', 'timeout' );
		$r = WP_Web_Scraper::remote_request( self::URL, array( 'cache' => 1 ) );
		$this->assertSame( 'Stale-cache (network error)', $r['headers']['X-WPWS-Cache-Control'] );
	}

	public function test_stampede_lock_serves_stale_without_fetching() {
		$GLOBALS['wpws_test_http'][] = self::resp( 'v1' );
		WP_Web_Scraper::remote_request( self::URL, array( 'cache' => 1 ) );
		self::age_entry( 120 );

		// Another process holds the refresh lock.
		$hash = substr( self::entry_key(), strlen( 'wpws_c_' ) );
		wp_cache_add( 'wpws_lock_' . $hash, time(), 'wpws_lock' );

		$r = WP_Web_Scraper::remote_request( self::URL, array( 'cache' => 1 ) );
		$this->assertSame( 'v1', $r['body'] );
		$this->assertSame( 'Stale-cache (refresh in progress)', $r['headers']['X-WPWS-Cache-Control'] );
		$this->assertCount( 1, $GLOBALS['wpws_test_requests'], 'no second remote request' );
	}

	public function test_lock_released_after_fetch_even_on_error() {
		$GLOBALS['wpws_test_http'][] = new WP_Error( 'x', 'down' );
		WP_Web_Scraper::remote_request( self::URL, array( 'cache' => 5 ) );
		$this->assertEmpty( isset( $GLOBALS['wpws_test_cache']['wpws_lock'] ) ? array_filter( $GLOBALS['wpws_test_cache']['wpws_lock'] ) : array() );
	}

	public function test_post_body_and_legacy_headers_arg_make_a_post() {
		$GLOBALS['wpws_test_http'][] = self::resp( 'a' );
		$GLOBALS['wpws_test_http'][] = self::resp( 'b' );
		WP_Web_Scraper::remote_request( self::URL, array( 'post_body' => 'q=zagreb&page=2' ) );
		WP_Web_Scraper::remote_request( self::URL, array( 'headers' => 'q=split' ) );
		$this->assertSame( 'POST', $GLOBALS['wpws_test_requests'][0][1]['method'] );
		$this->assertSame( array( 'q' => 'zagreb', 'page' => '2' ), $GLOBALS['wpws_test_requests'][0][1]['body'] );
		$this->assertSame( array( 'q' => 'split' ), $GLOBALS['wpws_test_requests'][1][1]['body'] );
		$this->assertIsArray( $GLOBALS['wpws_test_requests'][1][1]['headers'] );
	}

	public function test_request_headers_sent_and_auth_profile_wins() {
		$GLOBALS['wpws_test_options']['wpws_options'] = array( 'auth_profiles' => 'api | bearer | T0K' );
		$GLOBALS['wpws_test_http'][] = self::resp( 'a' );
		WP_Web_Scraper::remote_request( self::URL, array(
			'request_headers' => 'Accept=application/json&Authorization=Bearer%20FAKE',
			'auth_profile'    => 'api',
		) );
		$h = $GLOBALS['wpws_test_requests'][0][1]['headers'];
		$this->assertSame( 'application/json', $h['Accept'] );
		$this->assertSame( 'Bearer T0K', $h['Authorization'] );
	}

	public function test_different_credentials_do_not_share_cache() {
		$GLOBALS['wpws_test_options']['wpws_options'] = array( 'auth_profiles' => "a | bearer | A\nb | bearer | B" );
		$GLOBALS['wpws_test_http'][] = self::resp( 'for-a' );
		$GLOBALS['wpws_test_http'][] = self::resp( 'for-b' );
		$ra = WP_Web_Scraper::remote_request( self::URL, array( 'cache' => 60, 'auth_profile' => 'a' ) );
		$rb = WP_Web_Scraper::remote_request( self::URL, array( 'cache' => 60, 'auth_profile' => 'b' ) );
		$this->assertSame( 'for-a', $ra['body'] );
		$this->assertSame( 'for-b', $rb['body'] );
	}
}
