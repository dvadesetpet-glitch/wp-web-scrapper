<?php

use PHPUnit\Framework\TestCase;

final class JsonPathTest extends TestCase {

	private $json = '{"users":[{"name":"Ana","tags":["a","b"]},{"name":"Ivo","tags":[]}],"meta":{"count":2,"city":"Zagreb"}}';

	private function q( $path ) {
		$p = new WP_Web_Scraper_Parser( $this->json );
		return array( $p->parse_jsonpath( $path ), $p );
	}

	public function test_named_key() {
		list( $r ) = $this->q( '$.meta.city' );
		$this->assertSame( array( 'Zagreb' ), $r );
	}

	public function test_path_without_dollar() {
		list( $r ) = $this->q( 'meta.count' );
		$this->assertSame( array( '2' ), $r );
	}

	public function test_wildcard() {
		list( $r, $p ) = $this->q( '$.users.*.name' );
		$this->assertSame( array( 'Ana', 'Ivo' ), $r );
		$this->assertSame( 2, $p->count );
	}

	public function test_index_and_negative_index() {
		list( $r ) = $this->q( '$.users.0.name' );
		$this->assertSame( array( 'Ana' ), $r );
		list( $r ) = $this->q( '$.users.-1.name' );
		$this->assertSame( array( 'Ivo' ), $r );
	}

	public function test_non_scalar_is_json_encoded() {
		list( $r ) = $this->q( '$.users.0.tags' );
		$this->assertSame( array( '["a","b"]' ), $r );
	}

	public function test_root_returns_whole_document() {
		list( $r ) = $this->q( '$' );
		$this->assertSame( json_decode( $this->json, true ), json_decode( $r[0], true ) );
	}

	public function test_missing_path_sets_error() {
		list( $r, $p ) = $this->q( '$.nope.x' );
		$this->assertNull( $r );
		$this->assertSame( 'Query returned empty response', $p->error );
	}

	public function test_invalid_json_sets_error() {
		$p = new WP_Web_Scraper_Parser( '<html>not json</html>' );
		$this->assertNull( $p->parse_jsonpath( '$.a' ) );
		$this->assertStringStartsWith( 'Invalid JSON', $p->error );
	}
}
