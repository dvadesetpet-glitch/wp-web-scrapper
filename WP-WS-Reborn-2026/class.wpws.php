<?php

/**
 * Core plugin class for WP Web Scraper.
 *
 * Responsible for shortcode handling, HTTP requests, parsing, filtering,
 * and returning sanitized output to WordPress.
 */

require_once __DIR__ . '/class.wpws-parser.php';

class WP_Web_Scraper {

	// -------------------------------------------------------------------------
	// Constants & static state
	// -------------------------------------------------------------------------

	/** @var string Last requested URL */
	public static $url;
	/** @var string Last query (CSS/XPath/regex) */
	public static $query;
	/** @var array  Last resolved arguments used for the request */
	public static $args;
	/** @var string Cache control header value */
	public static $xcache;
	/** @var string|null Last error message (if any) */
	public static $error;
	/** @var float|null Microtime at start of request (for debug output) */
	public static $microtime;
	/** @var int Number of elements the last query matched */
	public static $count = 0;

	const MAX_REDIRECTS = 5;
	const MAX_RESPONSE_SIZE = 10485760; // 10MB
	const MAX_DOM_DEPTH = 1000;

	// -------------------------------------------------------------------------
	// Registration, views, activation
	// -------------------------------------------------------------------------

	/**
	 * Register shortcodes and widget filters based on saved options.
	 * Called on plugin init from the main plugin bootstrap.
	 */
	public static function init() {
		load_plugin_textdomain( 'wp-web-scraper' );
		$wpws_options = get_option( 'wpws_options', array() );
		if ( ! empty( $wpws_options['sc_posts'] ) )
			add_shortcode( 'wpws', array( 'WP_Web_Scraper', 'shortcode' ) );
		add_shortcode( 'wpws_atom_zip_links', array( 'WP_Web_Scraper', 'shortcode_atom_zip_links' ) );
		add_shortcode( 'wpws_atom_links', array( 'WP_Web_Scraper', 'shortcode_atom_links' ) );
		if ( ! empty( $wpws_options['sc_widgets'] ) )
			add_filter( 'widget_text', 'do_shortcode' );

		// AJAX scrape endpoint (logged-in and public)
		add_action( 'wp_ajax_wpws_scrape',        array( 'WP_Web_Scraper', 'ajax_scrape' ) );
		add_action( 'wp_ajax_nopriv_wpws_scrape', array( 'WP_Web_Scraper', 'ajax_scrape' ) );

		// Register front-end script (enqueued on demand inside get_content when ajax=1)
		add_action( 'wp_enqueue_scripts', array( 'WP_Web_Scraper', 'register_frontend_assets' ) );

		// WP-Cron background cache refresh
		add_action( 'wpws_background_refresh', array( 'WP_Web_Scraper', 'background_refresh' ) );

		// Gutenberg block
		if ( function_exists( 'register_block_type' ) ) {
			self::register_block();
		}
	}

	/**
	 * Render a view from the plugin's views directory.
	 *
	 * @param string $name View filename without extension.
	 * @param array  $vars Variables to extract into the view scope.
	 */
	public static function view( $name, $vars = array() ) {
		$file = WPWS__PLUGIN_DIR . 'views/' . $name . '.php';
		if ( ! empty( $vars ) )
			extract( $vars, EXTR_OVERWRITE );
		unset( $vars );
		include( $file );
	}

	/** Plugin activation: sets default options. */
	public static function plugin_activate() {
		$default_wpws_options = array(
			'sc_posts'           => 1,
			'sc_widgets'         => 1,
			'tt'                 => 1,
			'on_error'           => 'error_show',
			'useragent'          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
			'timeout'            => 10,
			'cache'              => 60,
			'sanitize_html'      => 1,
			'allow_localhost'    => 0,
			'require_https'      => 0,
			'whitelist_domains'  => '',
			'blacklist_domains'  => '',
			'rate_limit_max'     => 20,
			'rate_limit_window'  => 60,
		);
		add_option( 'wpws_options', $default_wpws_options );
	}

	public static function plugin_deactivate() {}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the appropriate error output based on the on_error setting.
	 * Requires self::$error and self::$args to be set before calling.
	 */
	private static function _handle_error() {
		$on_error = self::$args['on_error'];
		if ( $on_error === 'error_hide' ) return '';
		if ( $on_error === 'error_show' ) return self::$error;
		return ! empty( $on_error ) ? $on_error : self::$error;
	}

	/**
	 * Fetch a URL via wp_remote_get and return the body string, or WP_Error on failure.
	 * No caching – callers handle cache themselves.
	 */
	private static function _fetch_remote_body( $url ) {
		$wpws_options = get_option( 'wpws_options', array() );
		$timeout      = isset( $wpws_options['timeout'] ) ? absint( $wpws_options['timeout'] ) : 15;
		$useragent    = isset( $wpws_options['useragent'] ) ? $wpws_options['useragent'] : 'Mozilla/5.0 (compatible; WP-WS-Reborn2026/' . WPWS__VERSION . ')';

		$response = self::_http_request_ssrf_safe( $url, array(
			'timeout'    => max( 5, $timeout ),
			'user-agent' => $useragent,
			'sslverify'  => true,
		) );

		if ( is_wp_error( $response ) ) return $response;

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'wpws_http_error', 'HTTP ' . $code );
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Perform an HTTP request while re-validating every redirect hop against the
	 * SSRF allow-list. WordPress' own redirect following does NOT re-check the
	 * destination, so a public URL could 30x-redirect to an internal address
	 * (e.g. cloud metadata). We therefore follow redirects manually and validate
	 * each Location target before fetching it.
	 *
	 * @param string $url  Initial URL (already validated by the caller).
	 * @param array  $args wp_remote_request args.
	 * @return array|WP_Error Final response array or WP_Error.
	 */
	private static function _http_request_ssrf_safe( $url, $args ) {

		// Cap the amount of data WordPress will pull into memory.
		if ( ! isset( $args['limit_response_size'] ) ) {
			$args['limit_response_size'] = self::MAX_RESPONSE_SIZE;
		}

		// Follow redirects ourselves so each hop can be re-validated.
		$args['redirection'] = 0;

		$max = self::MAX_REDIRECTS;
		for ( $i = 0; $i <= $max; $i++ ) {

			// Re-validate the current URL (SSRF, scheme, blacklist). The first
			// iteration is cheap (results are cached) and redundant by design.
			$validation = WP_Web_Scraper_Security::validate_url( $url );
			if ( ! $validation['valid'] ) {
				return new WP_Error( 'wpws_ssrf_blocked', 'Blocked request: ' . $validation['error'] );
			}

			$response = wp_remote_request( $url, $args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				if ( empty( $location ) ) {
					return $response; // Redirect without a target; hand back as-is.
				}
				// Resolve relative redirects against the current URL.
				$url = WP_Http::make_absolute_url( $location, $url );
				continue;
			}

			return $response;
		}

		return new WP_Error( 'http_request_failed', 'Too many redirects' );
	}

	/**
	 * Render an array of URLs as HTML list or plain newline-separated text.
	 */
	private static function _render_link_list( $links, $format, $css_class ) {
		if ( strtolower( $format ) === 'plain' ) {
			return implode( "\n", array_map( 'esc_url', $links ) );
		}
		$html = '<ul class="' . esc_attr( $css_class ) . '">';
		foreach ( $links as $href ) {
			$html .= '<li><a href="' . esc_url( $href ) . '">' . esc_html( $href ) . '</a></li>';
		}
		return $html . '</ul>';
	}

	// -------------------------------------------------------------------------
	// Shortcode & content pipeline
	// -------------------------------------------------------------------------

	/**
	 * Main shortcode handler for [wpws] usage in posts, pages, and widgets.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string Rendered HTML or text output.
	 */
	public static function shortcode( $atts ) {
		$default_args = array(
			'url'         => '',
			'query'       => '',
			'urldecode'   => 1,
			'querydecode' => 0,
		);
		// Backward compatibility
		if ( isset( $atts['selector'] ) ) {
			$atts['query']      = $atts['selector'];
			$atts['query_type'] = 'cssselector';
		}
		if ( isset( $atts['xpath'] ) ) {
			$atts['query']      = $atts['xpath'];
			$atts['query_type'] = 'xpath';
		}
		$args         = wp_parse_args( $atts, $default_args );
		$args['url']  = str_replace( array( '&#038;', '&#38;', '&amp;' ), '&', $args['url'] );
		if ( isset( $args['headers'] ) ) {
			$args['headers'] = str_replace( array( '&#038;', '&#38;', '&amp;' ), '&', $args['headers'] );
		}
		if ( $args['urldecode'] == 1 ) {
			$args['url'] = urldecode( $args['url'] );
			if ( isset( $args['headers'] ) )
				$args['headers'] = urldecode( $args['headers'] );
		}
		if ( $args['querydecode'] == 1 ) {
			$args['query'] = urldecode( $args['query'] );
			if ( isset( $args['remove_query'] ) )
				$args['remove_query'] = urldecode( $args['remove_query'] );
			if ( isset( $args['replace_query'] ) )
				$args['replace_query'] = urldecode( $args['replace_query'] );
		}
		return self::get_content( $args['url'], $args['query'], $args );
	}

	/**
	 * Shortcode [wpws_atom_zip_links]: fetch Atom/RSS feed and return links with a given extension.
	 *
	 * @param array|string $atts url, format (list|plain), extension, cache (minutes).
	 * @return string HTML list or plain URLs, or error comment.
	 */
	public static function shortcode_atom_zip_links( $atts ) {
		$args = shortcode_atts( array(
			'url'       => '',
			'format'    => 'list',
			'extension' => 'zip',
			'cache'     => 60,
		), $atts, 'wpws_atom_zip_links' );

		$url = html_entity_decode( trim( $args['url'] ), ENT_QUOTES, 'UTF-8' );
		if ( $url === '' ) {
			return '<!-- wpws_atom_zip_links: url is required -->';
		}

		$url_validation = WP_Web_Scraper_Security::validate_url( $url );
		if ( ! $url_validation['valid'] ) {
			return '<!-- wpws_atom_zip_links: ' . esc_html( $url_validation['error'] ) . ' -->';
		}

		$extension     = strtolower( preg_replace( '/[^a-z0-9]/i', '', $args['extension'] ) ) ?: 'zip';
		$cache_minutes = absint( $args['cache'] );
		$cache_key     = 'wpws_atom_ext_' . $extension . '_' . md5( $url );

		$links = $cache_minutes > 0 ? get_transient( $cache_key ) : false;
		if ( $links === false ) {
			$body = self::_fetch_remote_body( $url );
			if ( is_wp_error( $body ) ) {
				return '<!-- wpws_atom_zip_links: ' . esc_html( $body->get_error_message() ) . ' -->';
			}
			$links = self::_parse_atom_zip_links( $body, $extension );
			if ( $links === null ) {
				return '<!-- wpws_atom_zip_links: failed to parse XML -->';
			}
			if ( $cache_minutes > 0 ) {
				set_transient( $cache_key, $links, $cache_minutes * 60 );
			}
		}

		if ( empty( $links ) ) {
			return '<!-- wpws_atom_zip_links: no .' . esc_html( $extension ) . ' links found -->';
		}

		return self::_render_link_list( $links, $args['format'], 'wpws-atom-zip-links' );
	}

	/**
	 * Shortcode [wpws_atom_links]: fetch feed, extract all URLs ending with given extension via regex.
	 *
	 * @param array|string $atts url, extension, format (list|plain), cache (minutes).
	 * @return string HTML list, plain URLs, or error comment.
	 */
	public static function shortcode_atom_links( $atts ) {
		$args = shortcode_atts( array(
			'url'       => '',
			'extension' => 'jpg',
			'format'    => 'list',
			'cache'     => 60,
		), $atts, 'wpws_atom_links' );

		$url = html_entity_decode( trim( $args['url'] ), ENT_QUOTES, 'UTF-8' );
		if ( $url === '' ) {
			return '<!-- wpws_atom_links: url is required -->';
		}

		$url_validation = WP_Web_Scraper_Security::validate_url( $url );
		if ( ! $url_validation['valid'] ) {
			return '<!-- wpws_atom_links: ' . esc_html( $url_validation['error'] ) . ' -->';
		}

		$extension     = strtolower( preg_replace( '/[^a-z0-9]/i', '', $args['extension'] ) ) ?: 'jpg';
		$cache_minutes = absint( $args['cache'] );
		$cache_key     = 'wpws_atom_links_' . $extension . '_' . md5( $url );

		$links = $cache_minutes > 0 ? get_transient( $cache_key ) : false;
		if ( $links === false ) {
			$body = self::_fetch_remote_body( $url );
			if ( is_wp_error( $body ) ) {
				return '<!-- wpws_atom_links: ' . esc_html( $body->get_error_message() ) . ' -->';
			}
			$links = self::_parse_atom_links_regex( $body, $extension );
			if ( empty( $links ) ) {
				return '<!-- wpws_atom_links: no http(s) links ending with .' . esc_html( $extension ) . ' found -->';
			}
			if ( $cache_minutes > 0 ) {
				set_transient( $cache_key, $links, $cache_minutes * 60 );
			}
		}

		return self::_render_link_list( $links, $args['format'], 'wpws-atom-links' );
	}

	/**
	 * Parse raw feed body with regex: find all http(s) URLs ending with the given extension.
	 *
	 * @param string $body      Raw response body.
	 * @param string $extension File extension without dot (e.g. jpg).
	 * @return array Unique URLs (empty if none found).
	 */
	public static function _parse_atom_links_regex( $body, $extension = 'jpg' ) {
		if ( empty( $body ) || ! is_string( $body ) ) return array();
		$extension = strtolower( preg_replace( '/[^a-z0-9]/i', '', $extension ) );
		if ( $extension === '' ) return array();
		$ext_quoted = preg_quote( $extension, '/' );
		$pattern    = '/(?:href|url)\s*=\s*["\'](https?:\/\/[^"\']*\.' . $ext_quoted . ')["\']/i';
		$links      = array();
		if ( preg_match_all( $pattern, $body, $m ) && ! empty( $m[1] ) ) {
			$links = array_values( array_unique( array_map( 'trim', $m[1] ) ) );
		}
		return $links;
	}

	/**
	 * Parse Atom/RSS XML: return URLs with the given extension from href/url attributes.
	 *
	 * @param string $xml_string Raw XML.
	 * @param string $extension  File extension without dot (e.g. zip).
	 * @return array|null Matching URLs, or null on parse error.
	 */
	public static function _parse_atom_zip_links( $xml_string, $extension = 'zip' ) {
		if ( empty( $xml_string ) || ! is_string( $xml_string ) ) return null;
		$extension  = strtolower( preg_replace( '/[^a-z0-9]/i', '', $extension ) ) ?: 'zip';
		$suffix     = '.' . $extension;
		$suffix_len = strlen( $suffix );

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		// LIBXML_NONET blocks network access during parsing (XXE hardening).
		if ( ! @$dom->loadXML( $xml_string, LIBXML_NONET ) ) {
			libxml_clear_errors();
			return null;
		}

		$links    = array();
		$media_ns = 'http://search.yahoo.com/mrss/';

		foreach ( $dom->getElementsByTagNameNS( $media_ns, 'content' ) as $node ) {
			$val = trim( $node->getAttribute( 'url' ) );
			if ( $val !== '' && strtolower( substr( $val, -$suffix_len ) ) === $suffix ) $links[] = $val;
		}
		foreach ( $dom->getElementsByTagName( 'enclosure' ) as $node ) {
			$val = trim( $node->getAttribute( 'url' ) );
			if ( $val !== '' && strtolower( substr( $val, -$suffix_len ) ) === $suffix ) $links[] = $val;
		}

		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'atom', 'http://www.w3.org/2005/Atom' );
		foreach ( $xpath->query( "//atom:link[@href] | //*[local-name()='link' and namespace-uri()='' and @href]" ) as $node ) {
			$val = trim( $node->getAttribute( 'href' ) );
			if ( $val !== '' && strtolower( substr( $val, -$suffix_len ) ) === $suffix ) $links[] = $val;
		}

		libxml_clear_errors();
		return array_values( array_unique( $links ) );
	}

	/**
	 * Core pipeline: fetch remote content, apply the query, and post‑process the result.
	 *
	 * @param string $url   Target URL to scrape.
	 * @param string $query Optional CSS/XPath/regex query.
	 * @param array  $args  Additional request, filter, and output options.
	 * @return string       Final output string.
	 */
	public static function get_content( $url, $query = '', $args = array() ) {

		$mt_start = microtime( true );

		// Normalise URL and set up args early so _handle_error() can use them.
		$url         = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );
		self::$url   = $url;
		self::$query = $query;
		self::$args  = wp_parse_args( $args, self::get_default_args() );
		self::$error = null;
		self::$count = 0;

		// Validate URL
		$url_validation = WP_Web_Scraper_Security::validate_url( $url );
		if ( ! $url_validation['valid'] ) {
			self::$error = 'URL validation failed: ' . $url_validation['error'];
			return self::_handle_error();
		}

		// Rate limit check (per domain)
		$parsed_url = wp_parse_url( $url );
		$domain     = isset( $parsed_url['host'] ) ? $parsed_url['host'] : $url;
		$rate_limit_check = WP_Web_Scraper_Security::check_rate_limit( $domain );
		if ( ! $rate_limit_check['allowed'] ) {
			self::$error = $rate_limit_check['error'];
			return self::_handle_error();
		}

		// AJAX lazy-load: return a placeholder div; wpws-frontend.js will populate it.
		if ( ! empty( self::$args['ajax'] ) && self::$args['ajax'] == 1 ) {
			wp_enqueue_script( 'wpws-frontend' );
			$pass_args = array_diff_key( self::$args, array_flip( array( 'ajax', 'on_error', 'debug' ) ) );
			return '<div class="wpws-ajax-placeholder"'
				. ' data-wpws-url="' . esc_attr( $url ) . '"'
				. ' data-wpws-query="' . esc_attr( $query ) . '"'
				. ' data-wpws-args="' . esc_attr( wp_json_encode( $pass_args ) ) . '"'
				. ' data-wpws-nonce="' . esc_attr( wp_create_nonce( 'wpws_ajax_nonce' ) ) . '">'
				. '</div>';
		}

		// Validate query. Read the type from the normalised self::$args, not the
		// raw $args param — callers may pass $args as a query-string (e.g. the
		// admin Sandbox), in which case $args['query_type'] would be unreadable
		// and a regex/XPath query could be wrongly validated as a CSS selector.
		if ( ! empty( $query ) ) {
			$query_type       = isset( self::$args['query_type'] ) ? self::$args['query_type'] : 'cssselector';
			$query_validation = WP_Web_Scraper_Security::validate_query( $query, $query_type );
			if ( ! $query_validation['valid'] ) {
				self::$error = 'Query validation failed: ' . $query_validation['error'];
				return self::_handle_error();
			}
		}

		// Resolve ___QUERY_STRING___ and ___(param)___ placeholders in URL
		if ( strpos( $url, '__' ) !== false ) {
			if ( strpos( $url, '___QUERY_STRING___' ) !== false ) {
				$url = str_replace( '___QUERY_STRING___', isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : '', $url );
			} else {
				$url = preg_replace_callback( '/___(.*?)___/', function( $matches ) {
					$key = isset( $matches[1] ) ? sanitize_key( $matches[1] ) : '';
					return isset( $_REQUEST[ $key ] ) ? sanitize_text_field( $_REQUEST[ $key ] ) : '';
				}, $url );
			}
		}

		if ( isset( self::$args['headers'] ) && strpos( self::$args['headers'], '__' ) !== false ) {
			if ( strstr( self::$args['headers'], '___QUERY_STRING___' ) ) {
				self::$args['headers'] = str_replace( '___QUERY_STRING___', isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : '', self::$args['headers'] );
			} else {
				self::$args['headers'] = preg_replace_callback( '/___(.*?)___/', function( $matches ) {
					$key = isset( $matches[1] ) ? sanitize_key( $matches[1] ) : '';
					return isset( $_REQUEST[ $key ] ) ? sanitize_text_field( $_REQUEST[ $key ] ) : '';
				}, self::$args['headers'] );
			}
		}

		self::$url = $url;

		$response = self::remote_request( $url, self::$args );

		// Validate response
		$response_validation = WP_Web_Scraper_Security::validate_response( $response );
		if ( ! $response_validation['valid'] ) {
			self::$error = 'Response validation failed: ' . $response_validation['error'];
			return self::_handle_error();
		}

		$content = '';

		if ( ! is_wp_error( $response ) ) {

			$response_body = wp_remote_retrieve_body( $response );

			if ( ! WP_Web_Scraper_Security::check_memory_safety( $response_body ) ) {
				self::$error = 'Content too large - potential memory exhaustion';
				return self::_handle_error();
			}

			$html_validation = WP_Web_Scraper_Security::validate_html_content( $response_body );
			if ( ! $html_validation['valid'] ) {
				self::$error = 'HTML validation failed: ' . $html_validation['error'];
				return self::_handle_error();
			}

			$wpws_parser = new WP_Web_Scraper_Parser( $response_body, self::$args['charset'] );

			$response_headers = wp_remote_retrieve_headers( $response );
			if ( is_object( $response_headers ) ) {
				$response_headers = $response_headers->getAll();
			}
			self::$xcache = isset( $response_headers['X-WPWS-Cache-Control'] ) ? $response_headers['X-WPWS-Cache-Control'] : '';

			if ( $query === '' ) {
				$content = $response_body;
			} else {
				if ( self::$args['query_type'] === 'cssselector' )
					$wpws_parser->parse_selector( $query );
				if ( self::$args['query_type'] === 'xpath' )
					$wpws_parser->parse_xpath( $query );
				if ( self::$args['query_type'] === 'regex' )
					$wpws_parser->parse_regex( $query );
				if ( self::$args['query_type'] === 'jsonpath' )
					$wpws_parser->parse_jsonpath( $query );

				if ( $wpws_parser->error !== null ) {
					self::$error = 'Error parsing: ' . $wpws_parser->error;
				} elseif ( version_compare( PHP_VERSION, '5.3.3', '<' ) ) {
					self::$error = 'Error parsing: PHP version 5.3.3 or greater is required for parsing';
				} else {
					$content     = $wpws_parser->result;
					self::$count = (int) $wpws_parser->count;
				}
			}

		} else {
			self::$error = 'Error fetching: ' . $response->get_error_message();
		}

		$ob_header = PHP_EOL;
		$ob_footer = PHP_EOL;

		if ( self::$args['debug'] == 1 ) {
			// Never expose credentials in the (publicly visible) debug comment.
			$debug_args = self::$args;
			foreach ( array( 'auth_user', 'auth_pass', 'auth_token' ) as $secret ) {
				if ( ! empty( $debug_args[ $secret ] ) ) $debug_args[ $secret ] = '***';
			}
			if ( ! empty( $debug_args['headers'] ) && is_string( $debug_args['headers'] )
				&& stripos( $debug_args['headers'], 'authorization' ) !== false ) {
				$debug_args['headers'] = '*** (contains credentials) ***';
			}
			$ob_header = PHP_EOL .
				'<!--' . PHP_EOL .
				' Start of web scrap (created by wp-web-scraper)' . PHP_EOL .
				' Source URL: ' . self::$url . PHP_EOL .
				' Query: ' . self::$query . ' (' . self::$args['query_type'] . ')' . PHP_EOL .
				' Other options: ' . print_r( $debug_args, true ) . '-->' . PHP_EOL;
			$ob_footer = PHP_EOL .
				'<!--' . PHP_EOL .
				' End of web scrap' . PHP_EOL .
				' WPWS Cache Control: ' . self::$xcache . PHP_EOL .
				' Computing time: ' . round( microtime( true ) - $mt_start, 4 ) . ' seconds' . PHP_EOL .
				'-->' . PHP_EOL;
		}

		$ob_body = ( self::$error === null )
			? self::filter_content( $content, self::$args )
			: self::_handle_error();

		return $ob_header . $ob_body . $ob_footer;
	}

	/**
	 * Apply result‑level filters, link manipulation, callbacks, and sanitisation.
	 *
	 * @param string|array $content      Raw or already‑parsed content.
	 * @param array        $response_args Effective argument set.
	 * @return string                    Cleaned and ready‑to‑render HTML/text.
	 */
	public static function filter_content( $content, $response_args = array() ) {

		// callback_raw – early hook on the raw response
		if ( $response_args['callback_raw'] !== '' ) {
			$content = self::_run_content_callback( $response_args['callback_raw'], $content );
		}

		// Filter array results (from CSS/XPath/regex parser)
		if ( is_array( $content ) && ! empty( $content ) ) {

			$i             = array();
			$content_count = count( $content );

			if ( $response_args['eq'] !== '' ) {
				$eq_value = trim( $response_args['eq'] );
				if ( strtolower( $eq_value ) === 'last' ) {
					$i[] = $content_count - 1;
				} elseif ( strtolower( $eq_value ) === 'first' ) {
					$i[] = 0;
				} elseif ( is_numeric( $eq_value ) ) {
					$eq_index = (int) round( $eq_value );
					if ( $eq_index >= 0 && $eq_index < $content_count ) $i[] = $eq_index;
				}
			}

			$has_gt = $response_args['gt'] !== '' && is_numeric( $response_args['gt'] );
			$has_lt = $response_args['lt'] !== '' && is_numeric( $response_args['lt'] );

			if ( $has_gt && $has_lt ) {
				$gt_value = (int) round( $response_args['gt'] );
				$lt_value = (int) round( $response_args['lt'] );
				if ( $gt_value < $lt_value ) {
					for ( $j = $gt_value + 1; $j < $lt_value && $j < $content_count; $j++ ) {
						if ( $j >= 0 ) $i[] = $j;
					}
				}
			} elseif ( $has_gt ) {
				$gt_value = (int) round( $response_args['gt'] );
				for ( $j = $gt_value + 1; $j < $content_count; $j++ ) {
					if ( $j >= 0 ) $i[] = $j;
				}
			} elseif ( $has_lt ) {
				$lt_value  = (int) round( $response_args['lt'] );
				$max_index = min( $lt_value - 1, $content_count - 1 );
				for ( $j = 0; $j <= $max_index; $j++ ) $i[] = $j;
			}

			if ( ! empty( $i ) ) {
				$i        = array_unique( $i );
				sort( $i );
				$filtered = array();
				foreach ( $content as $key => $value ) {
					if ( in_array( $key, $i ) ) $filtered[] = $value;
				}
				$content = $filtered;
			}

			if ( strtolower( $response_args['output'] ) === 'text' ) {
				$content = array_map( 'strip_tags', $content );
			}

			$content = implode( $response_args['glue'], $content );
		}

		$wpws_parser = new WP_Web_Scraper_Parser( $content, self::$args['charset'] );

		// Remove
		if ( $response_args['remove_query'] !== '' ) {
			if ( $response_args['remove_query_type'] === 'regex' )
				$content = preg_replace( $response_args['remove_query'], '', $content );
			if ( $response_args['remove_query_type'] === 'cssselector' )
				$content = $wpws_parser->replace_selector( $response_args['remove_query'], '' );
			if ( $response_args['remove_query_type'] === 'xpath' )
				$content = $wpws_parser->replace_xpath( $response_args['remove_query'], '' );
			$wpws_parser = new WP_Web_Scraper_Parser( $content, self::$args['charset'] );
		}

		// Replace
		if ( $response_args['replace_query'] !== '' ) {
			if ( $response_args['replace_query_type'] === 'regex' ) {
				$replace_query = $response_args['replace_query'];
				$replace_with  = $response_args['replace_with'];
				// Use JSON (not unserialize) to safely decode array patterns/replacements.
				$decoded = json_decode( urldecode( $replace_query ), true );
				if ( is_array( $decoded ) ) $replace_query = $decoded;
				$decoded = json_decode( urldecode( $replace_with ), true );
				if ( is_array( $decoded ) ) $replace_with = $decoded;
				$content = preg_replace( $replace_query, $replace_with, $content );
			}
			if ( $response_args['replace_query_type'] === 'cssselector' )
				$content = $wpws_parser->replace_selector( $response_args['replace_query'], $response_args['replace_with'] );
			if ( $response_args['replace_query_type'] === 'xpath' )
				$content = $wpws_parser->replace_xpath( $response_args['replace_query'], $response_args['replace_with'] );
			$wpws_parser = new WP_Web_Scraper_Parser( $content, self::$args['charset'] );
		}

		// Basehref
		if ( $response_args['basehref'] ) {
			$base = is_numeric( $response_args['basehref'] ) ? self::$url : $response_args['basehref'];
			if ( $response_args['basehref'] != 0 ) {
				$basehref_result = $wpws_parser->basehref( $base );
				$content = ( $response_args['output'] == 'text' )
					? str_replace( array( '<p>', '</p>' ), '', $basehref_result )
					: $basehref_result;
				$wpws_parser = new WP_Web_Scraper_Parser( $content, self::$args['charset'] );
			}
		}

		// a_target
		if ( $response_args['a_target'] )
			$content = $wpws_parser->a_target( $response_args['a_target'] );

		// Callback
		if ( $response_args['callback'] !== '' ) {
			$content = self::_run_content_callback( $response_args['callback'], $content );
		}

		return WP_Web_Scraper_Security::sanitize_html_output( $content );
	}

	/**
	 * Run a (validated, allow-listed) callback on content, supporting an optional
	 * "name:arg1,arg2" syntax for parameterised callbacks — e.g.
	 * callback="wpws_drop_columns:4" passes "4" as the second argument.
	 *
	 * @param string       $callback Callback spec ("func" or "func:a,b").
	 * @param string|array $content  Content to transform.
	 * @return string|array          Transformed (and re-sanitised) content, or
	 *                               the original content if validation fails.
	 */
	private static function _run_content_callback( $callback, $content ) {

		$func       = $callback;
		$extra_args = array();

		// Split "name:args" — the function name never contains a colon.
		if ( is_string( $callback ) && strpos( $callback, ':' ) !== false ) {
			list( $func, $argstr ) = explode( ':', $callback, 2 );
			$func       = trim( $func );
			$extra_args = ( trim( $argstr ) === '' ) ? array() : array_map( 'trim', explode( ',', $argstr ) );
		}

		$cb_validation = WP_Web_Scraper_Security::validate_callback( $func );
		if ( ! $cb_validation['valid'] || ! is_callable( $func ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WP Web Scraper: Callback validation failed: ' . $cb_validation['error'] );
			}
			return $content;
		}

		$content = WP_Web_Scraper_Security::sanitize_callback_data( $content );
		$result  = call_user_func_array( $func, array_merge( array( $content ), $extra_args ) );
		return WP_Web_Scraper_Security::sanitize_callback_data( $result );
	}

	// -------------------------------------------------------------------------
	// HTTP request & cache
	// -------------------------------------------------------------------------

	/**
	 * Retrieve the raw HTTP response (or cached copy). Wraps wp_remote_request().
	 *
	 * @param string $url          Site URL to retrieve.
	 * @param array  $request_args Request options (timeout, cache, useragent, headers, etc.).
	 * @return WP_Error|array The response array or WP_Error on failure.
	 */
	public static function remote_request( $url, $request_args = array() ) {

		$has_custom_headers = isset( $request_args['headers'] )
			&& $request_args['headers']
			&& ! empty( $request_args['headers'] )
			&& ! is_array( $request_args['headers'] );

		if ( $has_custom_headers ) {
			parse_str( $request_args['headers'], $body );
			$request_args['method'] = 'POST';
			$request_args['body']   = $body;
		}

		// User-agent
		if ( isset( $request_args['mobile'] ) && $request_args['mobile'] ) {
			$mobile_type              = isset( $request_args['mobile_type'] ) ? strtolower( $request_args['mobile_type'] ) : 'generic';
			$request_args['user-agent'] = self::get_mobile_user_agent( $mobile_type );
		} elseif ( isset( $request_args['useragent'] ) ) {
			$request_args['user-agent'] = $request_args['useragent'];
		}

		$request_args['sslverify']   = true;
		// Floor of 5s mirrors _fetch_remote_body(); the activation default of 2s
		// is too aggressive and causes spurious failures on slower hosts.
		$request_args['timeout']     = max( 5, isset( $request_args['timeout'] ) ? absint( $request_args['timeout'] ) : 5 );
		$request_args['redirection'] = self::MAX_REDIRECTS;
		$request_args['httpversion'] = '1.1';

		if ( ! isset( $request_args['headers'] ) || ! is_array( $request_args['headers'] ) ) {
			$request_args['headers'] = array();
		}

		// ── HTTP Authentication ───────────────────────────────────────────────
		$auth_type = isset( $request_args['auth_type'] ) ? strtolower( $request_args['auth_type'] ) : 'none';
		if ( $auth_type === 'bearer' && ! empty( $request_args['auth_token'] ) ) {
			$request_args['headers']['Authorization'] = 'Bearer ' . $request_args['auth_token'];
		} elseif ( $auth_type === 'basic' && ! empty( $request_args['auth_user'] ) ) {
			$request_args['headers']['Authorization'] = 'Basic ' . base64_encode( $request_args['auth_user'] . ':' . $request_args['auth_pass'] );
		}

		if ( isset( $request_args['mobile'] ) && $request_args['mobile'] ) {
			$mobile_type = isset( $request_args['mobile_type'] ) ? strtolower( $request_args['mobile_type'] ) : 'generic';
			$request_args['headers']['Accept']          = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7';
			$request_args['headers']['Accept-Language'] = 'en-US,en;q=0.9';
			$request_args['headers']['Accept-Encoding'] = 'gzip, deflate, br';
			if ( $mobile_type === 'android' || $mobile_type === 'generic' ) {
				$request_args['headers']['Sec-CH-UA']              = '"Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"';
				$request_args['headers']['Sec-CH-UA-Mobile']       = '?1';
				$request_args['headers']['Sec-CH-UA-Platform']     = '"Android"';
				$request_args['headers']['Sec-Fetch-Dest']         = 'document';
				$request_args['headers']['Sec-Fetch-Mode']         = 'navigate';
				$request_args['headers']['Sec-Fetch-Site']         = 'none';
				$request_args['headers']['Sec-Fetch-User']         = '?1';
				$request_args['headers']['Upgrade-Insecure-Requests'] = '1';
			} elseif ( $mobile_type === 'iphone' || $mobile_type === 'ipad' ) {
				$request_args['headers']['Sec-Fetch-Dest'] = 'document';
				$request_args['headers']['Sec-Fetch-Mode'] = 'navigate';
				$request_args['headers']['Sec-Fetch-Site'] = 'none';
				$request_args['headers']['Sec-Fetch-User'] = '?1';
			}
			$request_args['headers']['Viewport-Width'] = '390';
		} else {
			$request_args['headers']['Accept']          = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
			$request_args['headers']['Accept-Language'] = 'en-US,en;q=0.5';
			$request_args['headers']['Accept-Encoding'] = 'gzip, deflate';
		}

		// ── Build cache key ───────────────────────────────────────────────────
		$cache_key_parts = array( $url );
		if ( isset( $request_args['mobile'] ) && $request_args['mobile'] ) {
			$mobile_type       = isset( $request_args['mobile_type'] ) ? strtolower( $request_args['mobile_type'] ) : 'generic';
			$cache_key_parts[] = '_mobile_' . $mobile_type;
		}
		if ( ! empty( $request_args['headers'] ) ) {
			$hh = $request_args['headers'];
			ksort( $hh );
			$cache_key_parts[] = serialize( $hh );
		}

		$hash      = md5( implode( '', $cache_key_parts ) );
		$transient = 'wpws_' . $hash;
		$stale_key = 'wpws_stale_' . $hash;
		$etag_key  = 'wpws_etag_' . $hash;
		$lm_key    = 'wpws_lm_' . $hash;
		$ct_key    = 'wpws_ct_' . $hash;

		$skip_cache = ( isset( $request_args['cache'] ) && $request_args['cache'] == 0 );
		$ttl        = ( ! $skip_cache && isset( $request_args['cache'] ) && (int) $request_args['cache'] > 0 )
		              ? (int) $request_args['cache'] * 60 : 0;

		// ── Main cache hit ────────────────────────────────────────────────────
		$cache = $skip_cache ? false : get_transient( $transient );
		if ( $cache !== false && is_array( $cache ) ) {
			// Schedule background refresh when cache is ≥90% through its TTL
			if ( $ttl > 0 && ! empty( $request_args['background_refresh'] ) ) {
				$created_at = get_transient( $ct_key );
				if ( $created_at !== false && ( time() - (int) $created_at ) >= $ttl * 0.9 ) {
					$req_key = 'wpws_req_' . $hash;
					if ( ! get_transient( $req_key ) ) {
						set_transient( $req_key, array(
							'url'       => $url,
							'args'      => $request_args,
							'transient' => $transient,
							'stale_key' => $stale_key,
							'ct_key'    => $ct_key,
							'ttl'       => $ttl,
						), 300 );
						if ( ! wp_next_scheduled( 'wpws_background_refresh', array( $hash ) ) ) {
							wp_schedule_single_event( time() + 1, 'wpws_background_refresh', array( $hash ) );
						}
					}
				}
			}
			if ( is_object( $cache['headers'] ) ) $cache['headers'] = $cache['headers']->getAll();
			if ( is_array( $cache['headers'] ) ) $cache['headers']['X-WPWS-Cache-Control'] = 'Cache-hit Transients API';
			return $cache;
		}

		// ── Conditional request: ETag / Last-Modified ─────────────────────────
		if ( ! $skip_cache ) {
			$stored_etag = get_transient( $etag_key );
			$stored_lm   = get_transient( $lm_key );
			if ( $stored_etag ) $request_args['headers']['If-None-Match']     = $stored_etag;
			if ( $stored_lm   ) $request_args['headers']['If-Modified-Since'] = $stored_lm;
		}

		// ── Fetch (redirects re-validated against the SSRF allow-list) ────────
		$response = self::_http_request_ssrf_safe( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			// Fall back to stale copy on network error
			$stale = $skip_cache ? false : get_transient( $stale_key );
			if ( $stale !== false && is_array( $stale ) ) {
				if ( is_object( $stale['headers'] ) ) $stale['headers'] = $stale['headers']->getAll();
				if ( is_array( $stale['headers'] ) ) $stale['headers']['X-WPWS-Cache-Control'] = 'Stale-cache (network error)';
				return $stale;
			}
			return new WP_Error( 'wpws_remote_request_failed', $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		// ── 304 Not Modified: re-use stale ────────────────────────────────────
		if ( $response_code === 304 ) {
			$stale = $skip_cache ? false : get_transient( $stale_key );
			if ( $stale !== false && is_array( $stale ) ) {
				if ( $ttl > 0 ) {
					set_transient( $transient, $stale, $ttl );
					set_transient( $ct_key, time(), $ttl + 86400 );
				}
				if ( is_object( $stale['headers'] ) ) $stale['headers'] = $stale['headers']->getAll();
				if ( is_array( $stale['headers'] ) ) $stale['headers']['X-WPWS-Cache-Control'] = 'Cache-refreshed (304)';
				return $stale;
			}
			return new WP_Error( 'wpws_http_error', 'HTTP 304 but no stale cache available' );
		}

		// ── HTTP error: try stale before giving up ────────────────────────────
		if ( $response_code >= 400 ) {
			$stale = $skip_cache ? false : get_transient( $stale_key );
			if ( $stale !== false && is_array( $stale ) ) {
				if ( is_object( $stale['headers'] ) ) $stale['headers'] = $stale['headers']->getAll();
				if ( is_array( $stale['headers'] ) ) $stale['headers']['X-WPWS-Cache-Control'] = 'Stale-cache (HTTP ' . $response_code . ')';
				return $stale;
			}
			return new WP_Error( 'wpws_http_error', 'HTTP error: ' . $response_code );
		}

		// ── Success: store ETag / Last-Modified for next conditional request ──
		$response_headers = wp_remote_retrieve_headers( $response );
		if ( is_object( $response_headers ) ) $response_headers = $response_headers->getAll();

		if ( ! $skip_cache && is_array( $response_headers ) ) {
			$etag_ttl = $ttl > 0 ? $ttl + 86400 : 86400;
			if ( ! empty( $response_headers['etag'] ) )
				set_transient( $etag_key, $response_headers['etag'], $etag_ttl );
			if ( ! empty( $response_headers['last-modified'] ) )
				set_transient( $lm_key, $response_headers['last-modified'], $etag_ttl );
		}

		// ── Store response in main cache + stale ──────────────────────────────
		if ( ! $skip_cache && $ttl > 0 ) {
			set_transient( $transient, $response, $ttl );
			set_transient( $stale_key, $response, $ttl + 86400 * 7 ); // 7-day stale window
			set_transient( $ct_key, time(), $ttl + 86400 );
		}

		if ( is_array( $response_headers ) ) {
			$response_headers['X-WPWS-Cache-Control'] = 'Remote-fetch via WP_Http';
			$response['headers'] = $response_headers;
		}
		return $response;
	}

	// -------------------------------------------------------------------------
	// Defaults & helpers
	// -------------------------------------------------------------------------

	/**
	 * Default shortcode/request arguments merged with saved plugin options.
	 * Result is cached for the duration of the request.
	 *
	 * @return array Default args.
	 */
	public static function get_default_args() {
		static $default_args = null;
		if ( $default_args !== null ) return $default_args;

		$wpws_options = get_option( 'wpws_options', array() );
		$default_args = array(
			'headers'            => '',
			'cache'              => isset( $wpws_options['cache'] ) ? absint( $wpws_options['cache'] ) : 60,
			'useragent'          => isset( $wpws_options['useragent'] ) ? sanitize_text_field( $wpws_options['useragent'] ) : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
			'timeout'            => isset( $wpws_options['timeout'] ) ? absint( $wpws_options['timeout'] ) : 2,
			'on_error'           => isset( $wpws_options['on_error'] ) ? sanitize_text_field( $wpws_options['on_error'] ) : 'error_show',
			'output'             => 'html',
			'glue'               => PHP_EOL,
			'eq'                 => '',
			'gt'                 => '',
			'lt'                 => '',
			'query_type'         => 'cssselector',
			'remove_query'       => '',
			'remove_query_type'  => 'cssselector',
			'replace_query'      => '',
			'replace_query_type' => 'cssselector',
			'replace_with'       => '',
			'basehref'           => 1,
			'a_target'           => '',
			'callback_raw'       => '',
			'callback'           => '',
			// Off by default: the debug comment is emitted into public page HTML.
			'debug'              => 0,
			'charset'            => get_bloginfo( 'charset' ),
			'mobile'             => 0,
			'mobile_type'        => 'generic',
			// HTTP authentication
			'auth_type'          => 'none',   // none | basic | bearer
			'auth_user'          => '',
			'auth_pass'          => '',
			'auth_token'         => '',
			// AJAX lazy loading
			'ajax'               => 0,
			// Stale-while-revalidate background refresh
			'background_refresh' => 0,
		);
		return $default_args;
	}

	/**
	 * Get mobile User-Agent string based on device type.
	 *
	 * @param string $type Device type: android, iphone, ipad, or generic.
	 * @return string Mobile User-Agent string.
	 */
	public static function get_mobile_user_agent( $type = 'generic' ) {
		$agents = array(
			'android' => 'Mozilla/5.0 (Linux; Android 13; SM-G998B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
			'iphone'  => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
			'ipad'    => 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
			'generic' => 'Mozilla/5.0 (Linux; Android 13; Mobile) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
		);
		$type = strtolower( $type );
		return isset( $agents[ $type ] ) ? $agents[ $type ] : $agents['generic'];
	}

	// -------------------------------------------------------------------------
	// AJAX lazy loading
	// -------------------------------------------------------------------------

	/** Register front-end script (always registered; enqueued on demand in get_content). */
	public static function register_frontend_assets() {
		wp_register_script(
			'wpws-frontend',
			WPWS__PLUGIN_URL . 'views/js/wpws-frontend.js',
			array( 'jquery' ),
			WPWS__VERSION,
			true
		);
		wp_localize_script( 'wpws-frontend', 'wpwsAjax', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'wpws_ajax_nonce' ),
		) );
	}

	/** AJAX handler: verify nonce, run get_content, return JSON. */
	public static function ajax_scrape() {
		check_ajax_referer( 'wpws_ajax_nonce', 'nonce' );

		$url   = isset( $_POST['wpws_url'] )   ? esc_url_raw( wp_unslash( $_POST['wpws_url'] ) )                     : '';
		$query = isset( $_POST['wpws_query'] ) ? sanitize_text_field( wp_unslash( $_POST['wpws_query'] ) )           : '';
		$args  = isset( $_POST['wpws_args'] )  ? (array) json_decode( wp_unslash( $_POST['wpws_args'] ), true ) : array();

		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => 'Missing URL' ), 400 );
		}

		// Disallow ajax=1 inside the AJAX handler itself (prevent recursion)
		$args['ajax'] = 0;

		wp_send_json_success( array( 'html' => self::get_content( $url, $query, $args ) ) );
	}

	// -------------------------------------------------------------------------
	// WP-Cron background cache refresh
	// -------------------------------------------------------------------------

	/** Called by WP-Cron to silently refresh a cached response before it expires. */
	public static function background_refresh( $hash ) {
		$req_key  = 'wpws_req_' . $hash;
		$req_data = get_transient( $req_key );
		delete_transient( $req_key );

		if ( ! is_array( $req_data ) || empty( $req_data['url'] ) ) return;

		// Skip cache read so we always get a fresh copy
		$fetch_args                 = $req_data['args'];
		$fetch_args['cache']        = 0;
		$fetch_args['background_refresh'] = 0; // no recursion

		$response = self::_http_request_ssrf_safe( $req_data['url'], $fetch_args );
		if ( is_wp_error( $response ) ) return;
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 || $code === 304 ) return;

		$ttl = (int) $req_data['ttl'];
		set_transient( $req_data['transient'], $response, $ttl );
		set_transient( $req_data['stale_key'], $response, $ttl + 86400 * 7 );
		set_transient( $req_data['ct_key'], time(), $ttl + 86400 );
	}

	// -------------------------------------------------------------------------
	// Gutenberg block
	// -------------------------------------------------------------------------

	/** Register the wpws/scraper Gutenberg block (editor script + server-side render). */
	public static function register_block() {
		wp_register_script(
			'wpws-block',
			WPWS__PLUGIN_URL . 'views/js/wpws-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render' ),
			WPWS__VERSION
		);
		register_block_type( 'wpws/scraper', array(
			'editor_script'   => 'wpws-block',
			'render_callback' => array( 'WP_Web_Scraper', 'render_block' ),
			'attributes'      => array(
				'url'                => array( 'type' => 'string',  'default' => '' ),
				'query'              => array( 'type' => 'string',  'default' => '' ),
				'query_type'         => array( 'type' => 'string',  'default' => 'cssselector' ),
				'cache'              => array( 'type' => 'integer', 'default' => 60 ),
				'output'             => array( 'type' => 'string',  'default' => 'html' ),
				'auth_type'          => array( 'type' => 'string',  'default' => 'none' ),
				'auth_user'          => array( 'type' => 'string',  'default' => '' ),
				'auth_pass'          => array( 'type' => 'string',  'default' => '' ),
				'auth_token'         => array( 'type' => 'string',  'default' => '' ),
				'background_refresh' => array( 'type' => 'integer', 'default' => 0 ),
			),
		) );
	}

	/** Render callback for the Gutenberg block (server-side render). */
	public static function render_block( $attributes ) {
		$url   = isset( $attributes['url'] )   ? $attributes['url']   : '';
		$query = isset( $attributes['query'] ) ? $attributes['query'] : '';
		$args  = array_diff_key( $attributes, array_flip( array( 'url', 'query' ) ) );
		return '<div class="wp-block-wpws-scraper">' . self::get_content( $url, $query, $args ) . '</div>';
	}
}
