<?php

use PHPUnit\Framework\TestCase;

final class TableHelpersTest extends TestCase {

	private $table = '<table><tr><th>A</th><th>B</th><th>C</th><th>D</th></tr><tr><td>1</td><td>2</td><td>3</td><td>4</td></tr><tr><td>5</td><td>6</td><td>7</td><td>8</td></tr></table>';

	/** @dataProvider specProvider */
	public function test_parse_table_spec( $spec, $expected ) {
		$this->assertSame( $expected, array_values( wpws_parse_table_spec( $spec ) ) );
	}

	public function specProvider() {
		return array(
			'empty'            => array( '', array() ),
			'single'           => array( '3', array( 2 ) ),
			'range'            => array( '2-4', array( 1, 2, 3 ) ),
			'list'             => array( '1_3_5', array( 0, 2, 4 ) ),
			'range + single'   => array( '1-3_7', array( 0, 1, 2, 6 ) ),
			'two ranges'       => array( '1-2_5-6', array( 0, 1, 4, 5 ) ),
			'duplicates/order' => array( '3_1_3_2', array( 0, 1, 2 ) ),
			'zero ignored'     => array( '0_2', array( 1 ) ),
			'junk ignored'     => array( 'x_2', array( 1 ) ),
		);
	}

	private function cells( $html ) {
		preg_match_all( '#<t[hd]>(.*?)</t[hd]>#', $html, $m );
		return implode( ',', $m[1] );
	}

	public function test_keep_columns() {
		$this->assertSame( 'A,C,1,3,5,7', $this->cells( wpws_keep_columns( $this->table, '1_3' ) ) );
	}

	public function test_drop_columns() {
		$this->assertSame( 'A,C,D,1,3,4,5,7,8', $this->cells( wpws_drop_columns( $this->table, '2' ) ) );
	}

	public function test_keep_rows() {
		$this->assertSame( 'A,B,C,D,5,6,7,8', $this->cells( wpws_keep_rows( $this->table, '1_3' ) ) );
	}

	public function test_drop_rows() {
		$this->assertSame( 'A,B,C,D,5,6,7,8', $this->cells( wpws_drop_rows( $this->table, '2' ) ) );
	}

	public function test_filter_table_first_n() {
		$this->assertSame( 'A,B,1,2', $this->cells( wpws_filter_table( $this->table, 2, 2 ) ) );
	}

	public function test_deprecated_named_shortcut_still_works() {
		$this->assertSame( 'A,B,C,1,2,3,5,6,7', $this->cells( wpws_keep_first_3_columns( $this->table ) ) );
	}

	public function test_no_table_returns_input_unchanged() {
		$this->assertSame( '<p>hi</p>', wpws_drop_columns( '<p>hi</p>', '1' ) );
	}

	public function test_utf8_preserved() {
		$html = '<table><tr><td>Čakovec</td><td>Đakovo</td></tr></table>';
		$this->assertStringContainsString( 'Đakovo', wpws_drop_columns( $html, '1' ) );
		$this->assertStringNotContainsString( 'Čakovec', wpws_drop_columns( $html, '1' ) );
	}

	public function test_bold_words_single() {
		$this->assertSame( 'go <strong>Zagreb</strong> go', wpws_bold_words( 'go Zagreb go', 'Zagreb' ) );
	}

	public function test_bold_words_whole_words_only() {
		$this->assertSame( 'Zagrebački', wpws_bold_words( 'Zagrebački', 'Zagreb' ) );
	}

	public function test_bold_words_multiple_via_parameterised_args() {
		// callback="wpws_bold_words:Alpha,Beta" calls wpws_bold_words($html, 'Alpha', 'Beta').
		$this->assertSame( '<strong>Alpha</strong> and <strong>Beta</strong>', wpws_bold_words( 'Alpha and Beta', 'Alpha', 'Beta' ) );
	}

	public function test_bold_words_array_input() {
		$this->assertSame( array( '<strong>a</strong>', 'b' ), wpws_bold_words( array( 'a', 'b' ), 'a' ) );
	}

	public function test_site_specific_helpers_removed() {
		$this->assertFalse( function_exists( 'wpws_bold_gorica' ) );
		$this->assertFalse( function_exists( 'wpws_keep_mobile_table_columns' ) );
	}
}
