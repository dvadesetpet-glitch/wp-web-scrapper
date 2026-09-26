<?php

use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase {

	private $html = '<html><body><div id="content"><h2 class="title">One</h2><div class="post"><h2>Two</h2></div><a href="/f.zip">z</a><a href="https://x.test/a.pdf">p</a></div></body></html>';

	public function test_css_selector() {
		$p = new WP_Web_Scraper_Parser( $this->html );
		$this->assertSame( array( '<h2>Two</h2>' ), $p->parse_selector( 'div.post > h2' ) );
		$this->assertNull( $p->error );
	}

	public function test_css_selector_attribute_suffix() {
		$p = new WP_Web_Scraper_Parser( $this->html );
		$this->assertSame( array( '<a href="/f.zip">z</a>' ), $p->parse_selector( 'a[href$=".zip"]' ) );
	}

	public function test_css_selector_html_mode_is_case_insensitive_for_tags() {
		$p = new WP_Web_Scraper_Parser( $this->html );
		$this->assertSame( 2, count( (array) $p->parse_selector( 'H2' ) ) );
	}

	public function test_invalid_css_selector_sets_error() {
		$p = new WP_Web_Scraper_Parser( $this->html );
		$this->assertNull( $p->parse_selector( 'div[' ) );
		$this->assertSame( 'Invalid CSS selector', $p->error );
	}

	public function test_xpath() {
		$p = new WP_Web_Scraper_Parser( $this->html );
		$this->assertSame( array( '<h2 class="title">One</h2>' ), $p->parse_xpath( "//h2[@class='title']" ) );
	}

	public function test_regex() {
		$p = new WP_Web_Scraper_Parser( $this->html );
		$this->assertSame( array( '<h2>Two</h2>' ), $p->parse_regex( '#<h2>[^<]*</h2>#' ) );
	}

	public function test_empty_result_sets_error() {
		$p = new WP_Web_Scraper_Parser( $this->html );
		$p->parse_selector( 'table' );
		$this->assertSame( 'Query returned empty response', $p->error );
	}

	public function test_basehref_makes_links_absolute() {
		$p = new WP_Web_Scraper_Parser( '<a href="/f.zip">z</a>' );
		$this->assertStringContainsString( 'href="https://example.test/f.zip"', $p->basehref( 'https://example.test/dir/page.html' ) );
	}

	public function test_remove_by_selector() {
		$p = new WP_Web_Scraper_Parser( '<div><p class="ad">x</p><p>keep</p></div>' );
		$out = $p->replace_selector( '.ad', '' );
		$this->assertStringNotContainsString( 'ad', $out );
		$this->assertStringContainsString( 'keep', $out );
	}

	public function test_no_php_deprecations_from_css_library() {
		$errors = array();
		set_error_handler( function ( $no, $str ) use ( &$errors ) { $errors[] = $str; return true; } );
		WP_Web_Scraper_Parser::css_to_xpath( 'ul li:first-child a[href^="https"]' );
		restore_error_handler();
		$this->assertSame( array(), $errors );
	}
}
