<?php

use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase {

	protected function tearDown(): void {
		remove_all_filters( 'wpws_allowed_callbacks' );
		remove_all_filters( 'wpws_auth_profiles' );
		$GLOBALS['wpws_test_options'] = array();
	}

	private static function ip_in_range( $ip, $range ) {
		$m = new ReflectionMethod( 'WP_Web_Scraper_Security', 'ip_in_range' );
		$m->setAccessible( true );
		return $m->invoke( null, $ip, $range );
	}

	// ------------------------------------------------------------ IP ranges

	/** @dataProvider rangeProvider */
	public function test_ip_in_range( $ip, $range, $expected ) {
		$this->assertSame( $expected, self::ip_in_range( $ip, $range ) );
	}

	public function rangeProvider() {
		return array(
			array( '10.1.2.3', '10.0.0.0/8', true ),
			array( '11.0.0.1', '10.0.0.0/8', false ),
			array( '172.31.255.255', '172.16.0.0/12', true ),
			array( '172.32.0.0', '172.16.0.0/12', false ),
			array( '169.254.169.254', '169.254.0.0/16', true ),
			array( '100.64.0.1', '100.64.0.0/10', true ),
			array( '8.8.8.8', '0.0.0.0/0', true ),
			array( 'fd12::1', 'fc00::/7', true ),
			array( 'fe80::1', 'fe80::/10', true ),
			array( '2001:db8::1', 'fc00::/7', false ),
			array( '10.0.0.1', 'fc00::/7', false ),   // mixed families never match
		);
	}

	// ------------------------------------------------------------ SSRF

	/** @dataProvider blockedHostProvider */
	public function test_ssrf_blocks( $host ) {
		$r = WP_Web_Scraper_Security::check_ssrf_protection( $host );
		$this->assertFalse( $r['allowed'], $host );
	}

	public function blockedHostProvider() {
		return array(
			'localhost'        => array( 'localhost' ),
			'loopback'         => array( '127.0.0.1' ),
			'loopback range'   => array( '127.8.8.8' ),
			'ipv6 loopback'    => array( '[::1]' ),
			'private 10/8'     => array( '10.0.0.5' ),
			'private 192.168'  => array( '192.168.1.1' ),
			'cloud metadata'   => array( '169.254.169.254' ),
			'ula ipv6'         => array( 'fd00::1' ),
			'hex integer ip'   => array( '0x7f000001' ),
			'decimal int ip'   => array( '2130706433' ),
			'octal ip'         => array( '017700000001' ),
			'trailing dot'     => array( 'localhost.' ),
			'unresolvable'     => array( 'no-such-host.invalid' ),
		);
	}

	public function test_ssrf_allows_public_ip_and_reports_it() {
		$r = WP_Web_Scraper_Security::check_ssrf_protection( '93.184.216.34' );
		$this->assertTrue( $r['allowed'] );
		$this->assertSame( array( '93.184.216.34' ), $r['ips'] );
	}

	public function test_validate_url_rejects_bad_schemes() {
		foreach ( array( 'file:///etc/passwd', 'gopher://x.test/', 'javascript:alert(1)', 'ftp://1.1.1.1/' ) as $url ) {
			$r = WP_Web_Scraper_Security::validate_url( $url, array( 'allow_localhost' => true ) );
			$this->assertFalse( $r['valid'], $url );
		}
	}

	public function test_validate_url_null_bytes_and_https_requirement() {
		$this->assertFalse( WP_Web_Scraper_Security::validate_url( 'http://1.1.1.1/%00', array( 'allow_localhost' => true ) )['valid'] );
		$this->assertFalse( WP_Web_Scraper_Security::validate_url( 'http://1.1.1.1/', array( 'require_https' => true ) )['valid'] );
	}

	public function test_validate_url_whitelist_and_blacklist() {
		$wl = array( 'allow_localhost' => true, 'whitelist_domains' => "example.test" );
		$this->assertTrue( WP_Web_Scraper_Security::validate_url( 'https://sub.example.test/', $wl )['valid'] );
		$this->assertFalse( WP_Web_Scraper_Security::validate_url( 'https://other.test/', $wl )['valid'] );
		$bl = array( 'allow_localhost' => true, 'blacklist_domains' => "bad.test" );
		$this->assertFalse( WP_Web_Scraper_Security::validate_url( 'https://www.bad.test/', $bl )['valid'] );
	}

	public function test_validate_url_returns_validated_ips_for_pinning() {
		$r = WP_Web_Scraper_Security::validate_url( 'https://1.1.1.1/x' );
		$this->assertTrue( $r['valid'] );
		$this->assertSame( array( '1.1.1.1' ), $r['ips'] );
	}

	// ------------------------------------------------------------ callbacks

	public function test_builtin_callbacks_allowed() {
		foreach ( array( 'wpws_drop_columns', 'wpws_keep_columns', 'wpws_filter_table_advanced', 'wpws_bold_words', 'wpws_keep_first_7_rows', 'strip_tags' ) as $cb ) {
			$this->assertTrue( WP_Web_Scraper_Security::validate_callback( $cb )['valid'], $cb );
		}
	}

	public function test_fetching_and_arbitrary_functions_rejected() {
		foreach ( array( 'wpws_get_content', 'phpinfo', 'system', 'file_get_contents' ) as $cb ) {
			$this->assertFalse( WP_Web_Scraper_Security::validate_callback( $cb )['valid'], $cb );
		}
	}

	public function test_no_wpws_prefix_wildcard() {
		if ( ! function_exists( 'wpws_test_custom_cb' ) ) {
			eval( 'function wpws_test_custom_cb( $h ) { return $h; }' );
		}
		$this->assertFalse( WP_Web_Scraper_Security::validate_callback( 'wpws_test_custom_cb' )['valid'] );
		add_filter( 'wpws_allowed_callbacks', function ( $c ) { $c[] = 'wpws_test_custom_cb'; return $c; } );
		$this->assertTrue( WP_Web_Scraper_Security::validate_callback( 'wpws_test_custom_cb' )['valid'] );
	}

	public function test_array_callbacks_rejected() {
		$this->assertFalse( WP_Web_Scraper_Security::validate_callback( array( 'WP_Web_Scraper', 'get_content' ) )['valid'] );
	}

	// ------------------------------------------------------------ auth profiles

	public function test_parse_auth_profiles() {
		$raw = "api | bearer | TOK\n# comment\nsvc|basic|user|pa|ss\nk | header | X-Api-Key | V\nBad Name|bearer|x\nempty|bearer|\nh|header|Bad Header|v\nweird|digest|x";
		$p   = WP_Web_Scraper_Security::parse_auth_profiles( $raw, false );
		$this->assertSame( array( 'api', 'svc', 'k' ), array_keys( $p ) );
		$this->assertSame( array( 'type' => 'bearer', 'token' => 'TOK' ), $p['api'] );
		$this->assertSame( 'pa|ss', $p['svc']['pass'] );
		$this->assertSame( array( 'type' => 'header', 'header' => 'X-Api-Key', 'value' => 'V' ), $p['k'] );
	}

	public function test_profile_names_are_case_insensitive_and_read_from_options() {
		$GLOBALS['wpws_test_options']['wpws_options'] = array( 'auth_profiles' => 'MyApi | bearer | T' );
		$this->assertSame( 'T', WP_Web_Scraper_Security::get_auth_profile( 'myapi' )['token'] );
		$this->assertSame( 'T', WP_Web_Scraper_Security::get_auth_profile( ' MYAPI ' )['token'] );
		$this->assertNull( WP_Web_Scraper_Security::get_auth_profile( 'other' ) );
	}

	public function test_auth_profiles_filter() {
		add_filter( 'wpws_auth_profiles', function ( $p ) { $p['env'] = array( 'type' => 'bearer', 'token' => 'FROM_ENV' ); return $p; } );
		$this->assertSame( 'FROM_ENV', WP_Web_Scraper_Security::get_auth_profile( 'env' )['token'] );
	}

	public function test_line_validator() {
		$this->assertTrue( WP_Web_Scraper_Security::parse_auth_profiles_line_is_valid( 'a | bearer | t' ) );
		$this->assertFalse( WP_Web_Scraper_Security::parse_auth_profiles_line_is_valid( 'a | bearer' ) );
	}
}
