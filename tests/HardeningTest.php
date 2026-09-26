<?php

use PHPUnit\Framework\TestCase;

/**
 * Error visibility, capability gate, request headers/body, secret masking,
 * HTML validation and the admin-side auth profile masking.
 */
final class HardeningTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpws_test_caps']      = array();
		$GLOBALS['wpws_test_user_caps'] = array();
		$GLOBALS['wpws_test_post']      = null;
		$GLOBALS['wpws_test_preview']   = false;
		$GLOBALS['wpws_test_options']   = array();
	}

	// ------------------------------------------------------------ (2) errors

	public function test_errors_hidden_from_visitors() {
		$this->assertSame( '', trim( WP_Web_Scraper::get_content( 'ftp://example.test/', '', array( 'on_error' => 'error_show' ) ) ) );
	}

	public function test_errors_shown_escaped_to_editors() {
		$GLOBALS['wpws_test_caps'] = array( 'edit_posts' );
		$out = WP_Web_Scraper::get_content( 'ftp://<b>x</b>/', '', array( 'on_error' => 'error_show' ) );
		$this->assertStringContainsString( '<span class="wpws-error">', $out );
		$this->assertStringNotContainsString( '<b>', $out );
	}

	public function test_custom_fallback_text_is_kses_filtered() {
		$out = WP_Web_Scraper::get_content( 'ftp://x/', '', array( 'on_error' => 'Nema podataka<script>alert(1)</script>' ) );
		$this->assertSame( 'Nema podataka', trim( $out ) );
	}

	public function test_debug_comment_only_for_privileged_and_masked() {
		$args = array( 'debug' => 1, 'on_error' => 'error_hide', 'auth_token' => 'SECRET', 'request_headers' => 'X-Api-Key=SECRET' );
		$this->assertStringNotContainsString( '<!--', WP_Web_Scraper::get_content( 'ftp://x/', '', $args ) );

		$masked = WP_Web_Scraper::mask_secrets( $args );
		$this->assertSame( '***', $masked['auth_token'] );
		$this->assertStringNotContainsString( 'SECRET', $masked['request_headers'] );
	}

	// ------------------------------------------------------------ (4) capability

	public function test_contributor_post_cannot_scrape() {
		$GLOBALS['wpws_test_post']      = (object) array( 'post_author' => 7 );
		$GLOBALS['wpws_test_user_caps'] = array( 7 => array( 'edit_posts' ) );
		$this->assertFalse( WP_Web_Scraper::author_can_scrape() );
		$this->assertSame( '', WP_Web_Scraper::shortcode( array( 'url' => 'https://1.1.1.1/' ) ) );
	}

	public function test_editor_post_can_scrape() {
		$GLOBALS['wpws_test_post']      = (object) array( 'post_author' => 3 );
		$GLOBALS['wpws_test_user_caps'] = array( 3 => array( 'edit_others_posts' ) );
		$this->assertTrue( WP_Web_Scraper::author_can_scrape() );
	}

	public function test_preview_by_contributor_blocked_even_on_editor_post() {
		$GLOBALS['wpws_test_post']      = (object) array( 'post_author' => 3 );
		$GLOBALS['wpws_test_user_caps'] = array( 3 => array( 'edit_others_posts' ) );
		$GLOBALS['wpws_test_preview']   = true;
		$GLOBALS['wpws_test_caps']      = array( 'edit_posts' );
		$this->assertFalse( WP_Web_Scraper::author_can_scrape() );
	}

	public function test_no_post_context_is_allowed_and_cap_is_filterable() {
		$this->assertTrue( WP_Web_Scraper::author_can_scrape() );
		add_filter( 'wpws_required_capability', function () { return 'edit_posts'; } );
		$GLOBALS['wpws_test_post']      = (object) array( 'post_author' => 7 );
		$GLOBALS['wpws_test_user_caps'] = array( 7 => array( 'edit_posts' ) );
		$this->assertTrue( WP_Web_Scraper::author_can_scrape() );
		remove_all_filters( 'wpws_required_capability' );
	}

	// ------------------------------------------------------------ (6) headers

	public function test_parse_request_headers() {
		$h = WP_Web_Scraper::parse_request_headers( 'Accept=application/json&X-Api-Key=abc&Host=evil.test&Bad Name=x&X-Inject=a%0D%0ASet-Cookie:%20x' );
		$this->assertSame( 'application/json', $h['Accept'] );
		$this->assertSame( 'abc', $h['X-Api-Key'] );
		$this->assertArrayNotHasKey( 'Host', $h );
		$this->assertStringNotContainsString( "\n", $h['X-Inject'] );
		$this->assertCount( 3, $h );
	}

	// ------------------------------------------------------------ HTML validation

	public function test_many_void_elements_are_not_deep_nesting() {
		$html = str_repeat( '<p><img src="a.png"><br></p>', 1500 );
		$this->assertTrue( WP_Web_Scraper_Security::validate_html_content( $html )['valid'] );
	}

	public function test_minified_single_line_over_100kb_is_accepted() {
		$this->assertTrue( WP_Web_Scraper_Security::validate_html_content( str_repeat( '<div>x</div>', 20000 ) )['valid'] );
	}

	public function test_real_deep_nesting_is_rejected() {
		$this->assertFalse( WP_Web_Scraper_Security::validate_html_content( str_repeat( '<div>', 1200 ) )['valid'] );
	}

	// ------------------------------------------------------------ (9) profile masking

	public function test_mask_auth_profiles() {
		$raw = "api | bearer | T0K\n# note\nsvc | basic | user | pa|ss\nk | header | X-Api-Key | V";
		$this->assertSame(
			"api | bearer | ********\n# note\nsvc | basic | user | ********\nk | header | X-Api-Key | ********",
			WP_Web_Scraper_Admin::mask_auth_profiles( $raw )
		);
	}

	public function test_unmask_restores_secrets_and_accepts_new_ones() {
		$stored    = "api | bearer | T0K\nsvc | basic | user | pa|ss";
		$submitted = WP_Web_Scraper_Admin::mask_auth_profiles( $stored ) . "\nnew | bearer | FRESH";
		$result    = WP_Web_Scraper_Admin::unmask_auth_profiles( $submitted, $stored );
		$p = WP_Web_Scraper_Security::parse_auth_profiles( $result, false );
		$this->assertSame( 'T0K', $p['api']['token'] );
		$this->assertSame( 'pa|ss', $p['svc']['pass'] );
		$this->assertSame( 'FRESH', $p['new']['token'] );
	}

	public function test_unmask_drops_mask_when_identity_changed() {
		// Username changed but password left masked: never save "********" as a password.
		$result = WP_Web_Scraper_Admin::unmask_auth_profiles( 'svc | basic | other | ********', 'svc | basic | user | pw' );
		$this->assertStringNotContainsString( '********', $result );
		$this->assertSame( '', $result );
	}

	public function test_sanitize_options_keeps_secret_through_masked_save() {
		$GLOBALS['wpws_test_options']['wpws_options'] = array( 'auth_profiles' => 'api | bearer | T0K' );
		$clean = WP_Web_Scraper_Admin::sanitize_options( array( 'auth_profiles' => 'api | bearer | ********' ) );
		$this->assertSame( 'api | bearer | T0K', $clean['auth_profiles'] );
	}
}
