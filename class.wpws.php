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
	/** Lifetime of a stored AJAX lazy-load job (re-created on the next render). */
	const AJAX_JOB_TTL = 2592000; // 30 days

	// -------------------------------------------------------------------------
	// Registration, views, activation
	// -------------------------------------------------------------------------

	/**
	 * Register shortcodes and widget filters based on saved options.
	 * Called on plugin init from the main plugin bootstrap.
	 */
	public static function init() {
		load_plugin_textdomain( 'wp-web-scraper' );
		self::maybe_upgrade();
		$wpws_options = get_option( 'wpws_options', array() );
		if ( ! empty( $wpws_options['sc_posts'] ) )
			add_shortcode( 'wpws', array( 'WP_Web_Scraper', 'shortcode' ) );
		add_shortcode( 'wpws_atom_zip_links', array( 'WP_Web_Scraper', 'shortcode_atom_zip_links' ) );
		add_shortcode( 'wpws_atom_links', array( 'WP_Web_Scraper', 'shortcode_atom_links' ) );
		if ( ! empty( $wpws_options['sc_widgets'] ) )
			add_filter( 'widget_text', 'do_shortcode' );

		// AJAX scrape endpoint (logged-in and public). The request carries only
		// an opaque job ID; URL, query and arguments never come from the client
		// (see _store_ajax_job / ajax_scrape).
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

	/** Storage schema version (bump when stored data formats change). */
	const DB_VERSION = 2;

	/**
	 * One-time data migrations. v2: the cache moved from three full-response
	 * transients per URL (wpws_<hash>, wpws_stale_, wpws_ct_ …) to one slim
	 * entry (wpws_c_<hash>); delete the old rows instead of letting them sit
	 * in wp_options for up to 7 days.
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'wpws_db_version', 1 ) >= self::DB_VERSION ) return;
		self::delete_legacy_cache();
		update_option( 'wpws_db_version', self::DB_VERSION, false );
	}

	/** Remove v1 cache transients (only stored in wp_options without an object cache). */
	public static function delete_legacy_cache() {
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name REGEXP '^_transient(_timeout)?_wpws_([0-9a-f]{32}|(stale|ct|etag|lm)_[0-9a-f]{32})$'
			    OR option_name LIKE '\\_transient%\\_wpws\\_rate\\_limit\\_%'"
		);
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
		$on_error = isset( self::$args['on_error'] ) ? self::$args['on_error'] : 'error_show';
		if ( $on_error === 'error_hide' ) return '';
		if ( $on_error === 'error_show' || $on_error === '' ) {
			// Technical details (HTTP codes, blocked hosts, rate limits…) are
			// shown only to people who can fix them; visitors see nothing.
			return self::can_see_errors()
				? '<span class="wpws-error">' . esc_html( (string) self::$error ) . '</span>'
				: '';
		}
		// Custom fallback text chosen by the author, e.g. on_error="Data unavailable".
		return wp_kses_post( $on_error );
	}

	/**
	 * Whether the current viewer may see scraper errors and debug output.
	 * Default: users who can edit posts. Filter: wpws_show_errors.
	 *
	 * @return bool
	 */
	public static function can_see_errors() {
		$can = function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' );
		return (bool) apply_filters( 'wpws_show_errors', $can );
	}

	/**
	 * Diagnostic HTML comment, emitted only for viewers who can see errors.
	 * "--" is neutralised so the message cannot close the comment early.
	 *
	 * @param string $message Plain-text message.
	 * @return string
	 */
	private static function _admin_notice( $message ) {
		if ( ! self::can_see_errors() ) return '';
		return '<!-- ' . str_replace( '--', '- -', esc_html( $message ) ) . ' -->';
	}

	/**
	 * Capability required from the author of content that uses the scraper
	 * (shortcode or block). Default edit_others_posts (Editor, Administrator).
	 *
	 * @return string
	 */
	public static function required_capability() {
		return (string) apply_filters( 'wpws_required_capability', 'edit_others_posts' );
	}

	/**
	 * Whether scraper shortcodes/blocks may run in the current context.
	 *
	 * Without this, anyone who can write a post (Contributor and up) could make
	 * the server fetch arbitrary URLs just by previewing a draft or through the
	 * block editor's server-side preview. Checked against:
	 *  - the author of the post being rendered (covers publish + preview), and
	 *  - the current user during previews and REST (block editor) renders.
	 * Content outside a post (widgets, template tags) is not restricted:
	 * editing those already requires higher privileges.
	 *
	 * @return bool
	 */
	public static function author_can_scrape() {
		$cap  = self::required_capability();
		$post = function_exists( 'get_post' ) ? get_post() : null;
		if ( $post && ! empty( $post->post_author ) && ! user_can( (int) $post->post_author, $cap ) ) {
			return false;
		}
		$is_rest = defined( 'REST_REQUEST' ) && REST_REQUEST;
		if ( ( $is_rest || ( function_exists( 'is_preview' ) && is_preview() ) )
			&& is_user_logged_in() && ! current_user_can( $cap ) ) {
			return false;
		}
		return true;
	}

	/** Output used when author_can_scrape() refuses to run. */
	private static function _not_permitted() {
		return self::can_see_errors()
			? '<span class="wpws-error">' . esc_html( sprintf( 'WP Web Scraper: the author of this content needs the "%s" capability to use it.', self::required_capability() ) ) . '</span>'
			: '';
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

			// Rate limit real outgoing fetches only (per remote host). Checking
			// it on every page view — cache hits included — made busy pages hit
			// the limit and render nothing even though no request was made.
			if ( $i === 0 ) {
				$rate = WP_Web_Scraper_Security::check_rate_limit( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				if ( ! $rate['allowed'] ) {
					return new WP_Error( 'wpws_rate_limited', $rate['error'] );
				}
			}

			// Pin the connection to the IPs that validate_url() just checked,
			// so a DNS-rebinding server cannot answer the HTTP client's own
			// lookup with a private address.
			$pin      = self::_pin_dns( $url, isset( $validation['ips'] ) ? $validation['ips'] : array() );
			$response = wp_remote_request( $url, $args );
			if ( $pin ) {
				remove_action( 'http_api_curl', $pin, 10 );
			}
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
	 * Register a one-shot http_api_curl hook that forces cURL to connect to an
	 * already-validated IP for $url's host (CURLOPT_RESOLVE). TLS still
	 * verifies the certificate against the hostname.
	 *
	 * Only effective with the cURL transport (the WordPress default when the
	 * extension is present). Returns the hook callback so the caller can
	 * remove it, or null when nothing needs pinning.
	 *
	 * @param string $url URL about to be requested.
	 * @param array  $ips Validated IPs for its host.
	 * @return callable|null
	 */
	private static function _pin_dns( $url, $ips ) {
		if ( empty( $ips ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return null;
		}
		$host = strtolower( trim( $parts['host'], '[]' ) );
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return null; // IP literal: nothing to resolve.
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'http';
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( $scheme === 'https' ? 443 : 80 );

		// Prefer IPv4 (most compatible); bracket IPv6 for CURLOPT_RESOLVE.
		$ip = null;
		foreach ( $ips as $candidate ) {
			if ( filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) { $ip = $candidate; break; }
		}
		if ( $ip === null ) {
			$ip = '[' . $ips[0] . ']';
		}
		$entry = $host . ':' . $port . ':' . $ip;

		$cb = function ( $handle, $r = null, $request_url = '' ) use ( $entry, $host ) {
			if ( $request_url !== '' && strtolower( (string) wp_parse_url( $request_url, PHP_URL_HOST ) ) !== $host ) {
				return;
			}
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $entry ) );
		};
		add_action( 'http_api_curl', $cb, 10, 3 );
		return $cb;
	}

	/**
	 * Store the parameters of an AJAX lazy-load request server-side and return
	 * an opaque, unguessable ID for the placeholder.
	 *
	 * The browser only ever sends this ID back, so visitors cannot make the
	 * site fetch arbitrary URLs, and credentials/headers never appear in the
	 * page HTML. The ID is an HMAC of the job (keyed with the site salt), so
	 * it is stable across page views — full-page caches keep working.
	 *
	 * @param string $url   Source URL (unresolved placeholders are fine).
	 * @param string $query Query.
	 * @param array  $args  Resolved arguments.
	 * @return string 32-char hex job ID.
	 */
	private static function _store_ajax_job( $url, $query, $args ) {
		$args = array_diff_key( $args, array_flip( array( 'ajax', 'debug' ) ) );
		ksort( $args );
		$job = array( 'url' => $url, 'query' => $query, 'args' => $args );
		$id  = wp_hash( serialize( $job ), 'nonce' );
		$key = 'wpws_job_' . $id;
		if ( get_transient( $key ) === false ) {
			set_transient( $key, $job, self::AJAX_JOB_TTL );
		}
		return $id;
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
		if ( ! self::author_can_scrape() ) {
			return self::_not_permitted();
		}
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
		// Query-string style args: WordPress encodes "&" in post content.
		$qs_args = array( 'headers', 'post_body', 'request_headers' );
		foreach ( $qs_args as $k ) {
			if ( isset( $args[ $k ] ) ) {
				$args[ $k ] = str_replace( array( '&#038;', '&#38;', '&amp;' ), '&', $args[ $k ] );
			}
		}
		if ( $args['urldecode'] == 1 ) {
			$args['url'] = urldecode( $args['url'] );
			foreach ( $qs_args as $k ) {
				if ( isset( $args[ $k ] ) )
					$args[ $k ] = urldecode( $args[ $k ] );
			}
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
		if ( ! self::author_can_scrape() ) {
			return self::_not_permitted();
		}
		$args = shortcode_atts( array(
			'url'       => '',
			'format'    => 'list',
			'extension' => 'zip',
			'cache'     => 60,
		), $atts, 'wpws_atom_zip_links' );

		$url = html_entity_decode( trim( $args['url'] ), ENT_QUOTES, 'UTF-8' );
		if ( $url === '' ) {
			return self::_admin_notice( 'wpws_atom_zip_links: url is required' );
		}

		$url_validation = WP_Web_Scraper_Security::validate_url( $url );
		if ( ! $url_validation['valid'] ) {
			return self::_admin_notice( 'wpws_atom_zip_links: ' . $url_validation['error'] );
		}

		$extension     = strtolower( preg_replace( '/[^a-z0-9]/i', '', $args['extension'] ) ) ?: 'zip';
		$cache_minutes = absint( $args['cache'] );
		$cache_key     = 'wpws_atom_ext_' . $extension . '_' . md5( $url );

		$links = $cache_minutes > 0 ? get_transient( $cache_key ) : false;
		if ( $links === false ) {
			$body = self::_fetch_remote_body( $url );
			if ( is_wp_error( $body ) ) {
				return self::_admin_notice( 'wpws_atom_zip_links: ' . $body->get_error_message() );
			}
			$links = self::_parse_atom_zip_links( $body, $extension );
			if ( $links === null ) {
				return self::_admin_notice( 'wpws_atom_zip_links: failed to parse XML' );
			}
			if ( $cache_minutes > 0 ) {
				set_transient( $cache_key, $links, $cache_minutes * 60 );
			}
		}

		if ( empty( $links ) ) {
			return self::_admin_notice( 'wpws_atom_zip_links: no .' . $extension . ' links found' );
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
		if ( ! self::author_can_scrape() ) {
			return self::_not_permitted();
		}
		$args = shortcode_atts( array(
			'url'       => '',
			'extension' => 'jpg',
			'format'    => 'list',
			'cache'     => 60,
		), $atts, 'wpws_atom_links' );

		$url = html_entity_decode( trim( $args['url'] ), ENT_QUOTES, 'UTF-8' );
		if ( $url === '' ) {
			return self::_admin_notice( 'wpws_atom_links: url is required' );
		}

		$url_validation = WP_Web_Scraper_Security::validate_url( $url );
		if ( ! $url_validation['valid'] ) {
			return self::_admin_notice( 'wpws_atom_links: ' . $url_validation['error'] );
		}

		$extension     = strtolower( preg_replace( '/[^a-z0-9]/i', '', $args['extension'] ) ) ?: 'jpg';
		$cache_minutes = absint( $args['cache'] );
		$cache_key     = 'wpws_atom_links_' . $extension . '_' . md5( $url );

		$links = $cache_minutes > 0 ? get_transient( $cache_key ) : false;
		if ( $links === false ) {
			$body = self::_fetch_remote_body( $url );
			if ( is_wp_error( $body ) ) {
				return self::_admin_notice( 'wpws_atom_links: ' . $body->get_error_message() );
			}
			$links = self::_parse_atom_links_regex( $body, $extension );
			if ( empty( $links ) ) {
				return self::_admin_notice( 'wpws_atom_links: no http(s) links ending with .' . $extension . ' found' );
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

		// Rate limiting is applied to actual outgoing fetches (cache misses) in
		// _http_request_ssrf_safe(), not here on every render.

		// AJAX lazy-load: return a placeholder carrying only an opaque job ID.
		// URL, query, headers and credentials stay on the server.
		if ( ! empty( self::$args['ajax'] ) && self::$args['ajax'] == 1 ) {
			wp_enqueue_script( 'wpws-frontend' );
			$job_id = self::_store_ajax_job( $url, $query, self::$args );
			return '<div class="wpws-ajax-placeholder" data-wpws-id="' . esc_attr( $job_id ) . '"></div>';
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

		foreach ( array( 'headers', 'post_body' ) as $body_key ) {
			if ( empty( self::$args[ $body_key ] ) || ! is_string( self::$args[ $body_key ] ) || strpos( self::$args[ $body_key ], '__' ) === false ) {
				continue;
			}
			if ( strstr( self::$args[ $body_key ], '___QUERY_STRING___' ) ) {
				self::$args[ $body_key ] = str_replace( '___QUERY_STRING___', isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : '', self::$args[ $body_key ] );
			} else {
				self::$args[ $body_key ] = preg_replace_callback( '/___(.*?)___/', function( $matches ) {
					$key = isset( $matches[1] ) ? sanitize_key( $matches[1] ) : '';
					return isset( $_REQUEST[ $key ] ) ? sanitize_text_field( $_REQUEST[ $key ] ) : '';
				}, self::$args[ $body_key ] );
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

		// Debug comment: only for viewers who may see errors (never for the
		// public), with secrets masked and "--" neutralised so scraped values
		// cannot close the comment and inject markup.
		if ( self::$args['debug'] == 1 && self::can_see_errors() ) {
			$debug_args = self::mask_secrets( self::$args );
			$safe       = function ( $v ) { return str_replace( '--', '- -', (string) $v ); };
			$ob_header = PHP_EOL .
				'<!--' . PHP_EOL .
				' Start of web scrap (created by wp-web-scraper)' . PHP_EOL .
				' Source URL: ' . $safe( self::$url ) . PHP_EOL .
				' Query: ' . $safe( self::$query ) . ' (' . $safe( self::$args['query_type'] ) . ')' . PHP_EOL .
				' Other options: ' . $safe( print_r( $debug_args, true ) ) . '-->' . PHP_EOL;
			$ob_footer = PHP_EOL .
				'<!--' . PHP_EOL .
				' End of web scrap' . PHP_EOL .
				' WPWS Cache Control: ' . $safe( self::$xcache ) . PHP_EOL .
				' Computing time: ' . round( microtime( true ) - $mt_start, 4 ) . ' seconds' . PHP_EOL .
				'-->' . PHP_EOL;
		}

		$ob_body = ( self::$error === null )
			? self::filter_content( $content, self::$args )
			: self::_handle_error();

		return $ob_header . $ob_body . $ob_footer;
	}

	/**
	 * Mask credential-bearing arguments for any diagnostic output.
	 *
	 * @param array $args Arguments.
	 * @return array Copy with secrets replaced by "***".
	 */
	public static function mask_secrets( $args ) {
		foreach ( array( 'auth_user', 'auth_pass', 'auth_token' ) as $secret ) {
			if ( ! empty( $args[ $secret ] ) ) $args[ $secret ] = '***';
		}
		foreach ( array( 'headers', 'post_body', 'request_headers' ) as $k ) {
			if ( ! empty( $args[ $k ] ) && is_string( $args[ $k ] )
				&& preg_match( '/auth|token|pass|secret|key|cookie|session/i', $args[ $k ] ) ) {
				$args[ $k ] = '*** (may contain credentials) ***';
			}
		}
		return $args;
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
	 * Cache model (one transient per request, key "wpws_c_<hash>"):
	 *  - The entry stores a slim response (status, 3 headers, body) plus its
	 *    creation time. Bodies over 1 KB are gzip-compressed + base64-encoded.
	 *    Previously the full WP_HTTP response object was stored twice (main +
	 *    7-day stale copy) with the body duplicated inside the object — up to
	 *    ~40 MB per URL in wp_options on sites without an object cache.
	 *  - The entry lives ttl + 7 days; within ttl it is fresh, afterwards it is
	 *    the stale fallback for errors, 304s and refresh-in-progress.
	 *  - Only one process refreshes an expired entry (lock); concurrent visitors
	 *    get the stale copy instead of all hitting the remote site at once.
	 *
	 * @param string $url          Site URL to retrieve.
	 * @param array  $request_args Request options (timeout, cache, useragent, headers, etc.).
	 * @return WP_Error|array The response array or WP_Error on failure.
	 */
	public static function remote_request( $url, $request_args = array() ) {

		// ── POST body ─────────────────────────────────────────────────────────
		// "post_body" is the clear name; "headers" is the historic (misleading)
		// name for the same thing and is still honoured.
		$post_body = '';
		if ( ! empty( $request_args['post_body'] ) && is_string( $request_args['post_body'] ) ) {
			$post_body = $request_args['post_body'];
		} elseif ( ! empty( $request_args['headers'] ) && is_string( $request_args['headers'] ) ) {
			$post_body = $request_args['headers'];
		}
		if ( $post_body !== '' ) {
			parse_str( $post_body, $body );
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

		// From here on "headers" is the real HTTP header array.
		$request_args['headers'] = array();

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

		// ── Extra request headers (request_headers="Name=value&Other=value") ──
		if ( ! empty( $request_args['request_headers'] ) && is_string( $request_args['request_headers'] ) ) {
			foreach ( self::parse_request_headers( $request_args['request_headers'] ) as $name => $value ) {
				$request_args['headers'][ $name ] = $value;
			}
		}

		// ── HTTP Authentication (applied last so it cannot be overridden) ─────
		// Preferred: a named profile from Settings (secret never in post content).
		if ( ! empty( $request_args['auth_profile'] ) ) {
			$profile = WP_Web_Scraper_Security::get_auth_profile( $request_args['auth_profile'] );
			if ( $profile === null ) {
				return new WP_Error( 'wpws_auth_profile', 'Unknown auth profile "' . sanitize_key( $request_args['auth_profile'] ) . '"' );
			}
			$request_args['auth_type'] = $profile['type'];
			if ( $profile['type'] === 'bearer' ) {
				$request_args['auth_token'] = $profile['token'];
			} elseif ( $profile['type'] === 'basic' ) {
				$request_args['auth_user'] = $profile['user'];
				$request_args['auth_pass'] = $profile['pass'];
			} elseif ( $profile['type'] === 'header' ) {
				$request_args['headers'][ $profile['header'] ] = $profile['value'];
			}
		}

		// Legacy inline credentials (auth_user/auth_pass/auth_token attributes).
		$auth_type = isset( $request_args['auth_type'] ) ? strtolower( $request_args['auth_type'] ) : 'none';
		if ( $auth_type === 'bearer' && ! empty( $request_args['auth_token'] ) ) {
			$request_args['headers']['Authorization'] = 'Bearer ' . $request_args['auth_token'];
		} elseif ( $auth_type === 'basic' && ! empty( $request_args['auth_user'] ) ) {
			$request_args['headers']['Authorization'] = 'Basic ' . base64_encode( $request_args['auth_user'] . ':' . $request_args['auth_pass'] );
		}

		// ── Cache key: URL + method/body + headers (credentials hashed, never stored) ──
		$hh = $request_args['headers'];
		ksort( $hh );
		$hash = md5( $url . '|' . ( isset( $request_args['body'] ) ? serialize( $request_args['body'] ) : '' ) . '|' . serialize( $hh ) );
		$key  = 'wpws_c_' . $hash;

		$skip_cache = ( ! isset( $request_args['cache'] ) || (int) $request_args['cache'] <= 0 );
		$ttl        = $skip_cache ? 0 : (int) $request_args['cache'] * 60;

		$entry = $skip_cache ? null : self::_cache_get( $key );

		// ── Fresh hit ─────────────────────────────────────────────────────────
		if ( $entry && ( time() - $entry['created'] ) < $ttl ) {
			if ( ! empty( $request_args['background_refresh'] ) && ( time() - $entry['created'] ) >= $ttl * 0.9 ) {
				self::_schedule_background_refresh( $hash, $url, $request_args, $ttl );
			}
			return self::_cache_to_response( $entry, 'Cache-hit Transients API' );
		}

		// ── Miss or expired: only one process refreshes ───────────────────────
		$locked = false;
		if ( ! $skip_cache ) {
			$locked = self::_lock_acquire( $hash, $request_args['timeout'] + 15 );
			if ( ! $locked && $entry ) {
				return self::_cache_to_response( $entry, 'Stale-cache (refresh in progress)' );
			}
			// No lock and nothing cached (cold start): fetch anyway; the
			// per-host rate limit still bounds the burst.
		}

		try {
			// Conditional request against what we already have.
			if ( $entry ) {
				if ( ! empty( $entry['headers']['etag'] ) )          $request_args['headers']['If-None-Match']     = $entry['headers']['etag'];
				if ( ! empty( $entry['headers']['last-modified'] ) ) $request_args['headers']['If-Modified-Since'] = $entry['headers']['last-modified'];
			}

			// Fetch (redirects re-validated against the SSRF allow-list).
			$response = self::_http_request_ssrf_safe( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				if ( $entry ) return self::_cache_to_response( $entry, 'Stale-cache (network error)' );
				return new WP_Error( 'wpws_remote_request_failed', $response->get_error_message() );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( $code === 304 ) {
				if ( $entry ) {
					$entry['created'] = time();
					self::_cache_set( $key, $entry, $ttl );
					return self::_cache_to_response( $entry, 'Cache-refreshed (304)' );
				}
				return new WP_Error( 'wpws_http_error', 'HTTP 304 but no cached copy available' );
			}

			if ( $code >= 400 ) {
				if ( $entry ) return self::_cache_to_response( $entry, 'Stale-cache (HTTP ' . $code . ')' );
				return new WP_Error( 'wpws_http_error', 'HTTP error: ' . $code );
			}

			$fresh = self::_response_to_cache( $response );
			if ( ! $skip_cache && $ttl > 0 ) {
				self::_cache_set( $key, $fresh, $ttl );
			}
			return self::_cache_to_response( $fresh, 'Remote-fetch via WP_Http' );
		} finally {
			if ( $locked ) self::_lock_release( $hash );
		}
	}

	/**
	 * Parse request_headers ("Name=value&Other=value") into a safe header map.
	 * Header names must be tokens; hop-by-hop and routing headers are refused.
	 *
	 * @param string $raw Query-string formatted headers.
	 * @return array name => value
	 */
	public static function parse_request_headers( $raw ) {
		parse_str( (string) $raw, $pairs );
		$blocked = array( 'host', 'content-length', 'transfer-encoding', 'connection', 'te', 'upgrade', 'proxy-authorization', 'expect' );
		$out     = array();
		foreach ( (array) $pairs as $name => $value ) {
			$name = trim( (string) $name );
			if ( ! is_string( $value ) || ! preg_match( '/^[A-Za-z0-9-]+$/', $name ) ) continue;
			if ( in_array( strtolower( $name ), $blocked, true ) ) continue;
			$out[ $name ] = str_replace( array( "\r", "\n" ), '', $value );
		}
		return $out;
	}

	// ── Cache helpers ────────────────────────────────────────────────────────

	/** Headers worth keeping from a response (lower-case). */
	private static $kept_headers = array( 'content-type', 'etag', 'last-modified' );

	/** Slim, storable form of a WP_HTTP response. */
	private static function _response_to_cache( $response ) {
		$headers = array();
		foreach ( self::$kept_headers as $h ) {
			$v = wp_remote_retrieve_header( $response, $h );
			if ( $v !== '' && $v !== null ) $headers[ $h ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
		}
		return array(
			'v'       => 2,
			'created' => time(),
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'message' => (string) wp_remote_retrieve_response_message( $response ),
			'headers' => $headers,
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/** Rebuild a WP_HTTP-style response array from a cache entry. */
	private static function _cache_to_response( $entry, $label ) {
		$headers = $entry['headers'];
		$headers['X-WPWS-Cache-Control'] = $label;
		return array(
			'headers'  => $headers,
			'body'     => $entry['body'],
			'response' => array( 'code' => $entry['code'], 'message' => $entry['message'] ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** Store an entry for ttl + 7 days (stale window); body compressed when worthwhile. */
	private static function _cache_set( $key, $entry, $ttl ) {
		$stored = $entry;
		if ( strlen( $entry['body'] ) > 1024 && function_exists( 'gzcompress' ) ) {
			// base64: wp_options.option_value is a text column; raw gzip bytes
			// are not valid UTF-8 and could be mangled by the DB layer.
			$stored['body'] = base64_encode( gzcompress( $entry['body'], 6 ) );
			$stored['gz']   = 1;
		}
		set_transient( $key, $stored, $ttl + 7 * DAY_IN_SECONDS );
	}

	/** Load and decode an entry; null if missing or unreadable. */
	private static function _cache_get( $key ) {
		$e = get_transient( $key );
		if ( ! is_array( $e ) || ! isset( $e['v'], $e['created'], $e['body'] ) || (int) $e['v'] !== 2 ) {
			return null;
		}
		if ( ! empty( $e['gz'] ) ) {
			$raw = base64_decode( $e['body'], true );
			$raw = $raw === false ? false : @gzuncompress( $raw );
			if ( $raw === false ) return null;
			$e['body'] = $raw;
			unset( $e['gz'] );
		}
		return $e;
	}

	/**
	 * Try to take the refresh lock for a cache entry. Atomic: object cache
	 * add() or INSERT IGNORE on a unique option_name. A lock older than $ttl
	 * seconds (crashed process) is taken over with a compare-and-set UPDATE.
	 */
	private static function _lock_acquire( $hash, $ttl ) {
		$name = 'wpws_lock_' . $hash;
		$now  = time();
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			return wp_cache_add( $name, $now, 'wpws_lock', $ttl );
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			$name, (string) $now
		) );
		if ( (int) $wpdb->rows_affected === 1 ) {
			return true;
		}
		$held_since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( $held_since > 0 && ( $now - $held_since ) > $ttl ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				(string) $now, $name, (string) $held_since
			) );
			return (int) $wpdb->rows_affected === 1;
		}
		return false;
	}

	private static function _lock_release( $hash ) {
		$name = 'wpws_lock_' . $hash;
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			wp_cache_delete( $name, 'wpws_lock' );
			return;
		}
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
	}

	/** Queue a WP-Cron refresh for an entry that is about to expire. */
	private static function _schedule_background_refresh( $hash, $url, $request_args, $ttl ) {
		$req_key = 'wpws_req_' . $hash;
		if ( get_transient( $req_key ) ) return;
		$args = $request_args;
		unset( $args['headers']['If-None-Match'], $args['headers']['If-Modified-Since'] );
		set_transient( $req_key, array( 'url' => $url, 'args' => $args, 'ttl' => $ttl ), 300 );
		if ( ! wp_next_scheduled( 'wpws_background_refresh', array( $hash ) ) ) {
			wp_schedule_single_event( time() + 1, 'wpws_background_refresh', array( $hash ) );
		}
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
			// Legacy name: despite "headers", this is a POST body (query string).
			'headers'            => '',
			// Query string sent as POST body; the request becomes a POST.
			'post_body'          => '',
			// Extra HTTP request headers as a query string, e.g. "Accept=application/json&X-Foo=bar".
			'request_headers'    => '',
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
			// HTTP authentication. Prefer auth_profile (defined in Settings);
			// the inline fields below are kept for backward compatibility.
			'auth_profile'       => '',
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
		) );
	}

	/**
	 * AJAX handler: look up a job stored by _store_ajax_job(), run it, return JSON.
	 *
	 * Only a job ID is accepted from the client. IDs are HMACs keyed with the
	 * site salt and exist only for requests an author placed on a page, so the
	 * endpoint can no longer be used as an open proxy. No nonce is required:
	 * it protected nothing here (the same anonymous nonce was printed on the
	 * page for everyone) and broke lazy-loading on full-page-cached pages
	 * once it expired.
	 */
	public static function ajax_scrape() {
		$id = isset( $_POST['wpws_id'] ) ? strtolower( (string) wp_unslash( $_POST['wpws_id'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid request' ), 400 );
		}

		$job = get_transient( 'wpws_job_' . $id );
		if ( ! is_array( $job ) || empty( $job['url'] ) ) {
			wp_send_json_error( array( 'message' => 'Unknown or expired request' ), 404 );
		}

		$args         = isset( $job['args'] ) ? (array) $job['args'] : array();
		$args['ajax'] = 0; // prevent recursion

		wp_send_json_success( array( 'html' => self::get_content( $job['url'], (string) $job['query'], $args ) ) );
	}

	// -------------------------------------------------------------------------
	// WP-Cron background cache refresh
	// -------------------------------------------------------------------------

	/** Called by WP-Cron to silently refresh a cached response before it expires. */
	public static function background_refresh( $hash ) {
		$hash = preg_replace( '/[^a-f0-9]/', '', (string) $hash );
		$req_key  = 'wpws_req_' . $hash;
		$req_data = get_transient( $req_key );
		delete_transient( $req_key );

		if ( ! is_array( $req_data ) || empty( $req_data['url'] ) ) return;
		if ( ! self::_lock_acquire( $hash, 60 ) ) return; // a visitor is already refreshing

		try {
			$fetch_args = $req_data['args'];
			$response   = self::_http_request_ssrf_safe( $req_data['url'], $fetch_args );
			if ( is_wp_error( $response ) ) return;
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) return;
			self::_cache_set( 'wpws_c_' . $hash, self::_response_to_cache( $response ), (int) $req_data['ttl'] );
		} finally {
			self::_lock_release( $hash );
		}
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
				'auth_profile'       => array( 'type' => 'string',  'default' => '' ),
				// Legacy inline credentials: still honoured so existing blocks keep
				// working, but no longer editable in the UI (see wpws-block.js).
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
		if ( ! self::author_can_scrape() ) {
			return self::_not_permitted();
		}
		$url   = isset( $attributes['url'] )   ? $attributes['url']   : '';
		$query = isset( $attributes['query'] ) ? $attributes['query'] : '';
		$args  = array_diff_key( $attributes, array_flip( array( 'url', 'query' ) ) );
		return '<div class="wp-block-wpws-scraper">' . self::get_content( $url, $query, $args ) . '</div>';
	}
}
