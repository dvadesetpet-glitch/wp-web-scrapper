<?php
/**
 * Security and validation class for WP Web Scraper
 *
 * @package WP_Web_Scraper
 * @since 4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Web_Scraper_Security {

	const MAX_URL_LENGTH = 2048;
	const MAX_QUERY_LENGTH = 10000;
	const MAX_RESPONSE_SIZE = 10485760; // 10MB
	const MAX_REDIRECTS = 5;
	const MAX_DOM_DEPTH = 1000;
	
	/**
	 * Built-in callbacks that are always safe to run on scraped content.
	 * Extend with the "wpws_allowed_callbacks" filter.
	 */
	private static $builtin_allowed_callbacks = array(
		// Plugin helpers (see wpws.php).
		'wpws_filter_table', 'wpws_filter_table_advanced',
		'wpws_keep_first_n_columns', 'wpws_keep_first_n_rows',
		'wpws_keep_mobile_table_columns', 'wpws_bold_words', 'wpws_bold_gorica',
		// Harmless WordPress / PHP text helpers.
		'strip_tags', 'wp_strip_all_tags', 'trim', 'wptexturize',
		'esc_html', 'sanitize_text_field', 'wpautop',
	);

	/**
	 * Private IP ranges for SSRF protection
	 */
	private static $private_ip_ranges = array(
		'0.0.0.0/8',        // "this" network
		'10.0.0.0/8',
		'100.64.0.0/10',    // CGNAT
		'172.16.0.0/12',
		'192.168.0.0/16',
		'127.0.0.0/8',
		'169.254.0.0/16',   // link-local (incl. cloud metadata 169.254.169.254)
		'::1/128',
		'fc00::/7',
		'fe80::/10'
	);

	/**
	 * Validate URL and check for SSRF vulnerabilities
	 *
	 * @param string $url URL to validate
	 * @param array $options Security options
	 * @return array|WP_Error Array with 'valid' => true/false and 'error' => message, or WP_Error
	 */
	public static function validate_url( $url, $options = array() ) {
		
		// Get security options
		$security_options = self::get_security_options();
		$options = wp_parse_args( $options, $security_options );
		
		// Check URL length
		if ( strlen( $url ) > self::MAX_URL_LENGTH ) {
			return array( 'valid' => false, 'error' => 'URL exceeds maximum length' );
		}
		
		// Check for dangerous protocols
		$dangerous_protocols = array( 'file://', 'data:', 'javascript:', 'vbscript:', 'about:', 'chrome:', 'chrome-extension:' );
		foreach ( $dangerous_protocols as $protocol ) {
			if ( stripos( $url, $protocol ) === 0 ) {
				return array( 'valid' => false, 'error' => 'Dangerous protocol detected' );
			}
		}
		
		// Check for null bytes
		if ( strpos( $url, "\0" ) !== false ) {
			return array( 'valid' => false, 'error' => 'URL contains null bytes' );
		}
		
		// Check for encoded null bytes
		if ( strpos( $url, '%00' ) !== false || strpos( $url, '%2500' ) !== false ) {
			return array( 'valid' => false, 'error' => 'URL contains encoded null bytes' );
		}
		
		// Trim and clean URL
		$url = trim( $url );
		
		// Remove any HTML entities that might interfere
		$url = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
		
		// Basic URL format validation using filter_var
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			// If filter_var fails, try to parse it anyway (some valid URLs might fail filter_var)
			// But we'll still check the scheme
		}
		
		// Parse URL
		$parsed_url = wp_parse_url( $url );
		
		if ( false === $parsed_url || empty( $parsed_url ) ) {
			return array( 'valid' => false, 'error' => 'Invalid URL format' );
		}
		
		// Check scheme (only http and https allowed)
		// If scheme is not in parsed_url, try to extract it from the URL string
		$scheme = '';
		if ( isset( $parsed_url['scheme'] ) && ! empty( $parsed_url['scheme'] ) ) {
			$scheme = strtolower( trim( $parsed_url['scheme'] ) );
		} else {
			// Try to extract scheme from URL string using regex
			if ( preg_match( '/^([a-z][a-z0-9+.-]*):/i', $url, $matches ) ) {
				$scheme = strtolower( trim( $matches[1] ) );
			}
		}
		
		if ( empty( $scheme ) ) {
			return array( 'valid' => false, 'error' => 'URL scheme not found. Only HTTP and HTTPS schemes are allowed' );
		}
		
		if ( ! in_array( $scheme, array( 'http', 'https' ) ) ) {
			return array( 'valid' => false, 'error' => 'Only HTTP and HTTPS schemes are allowed. Found: ' . $scheme );
		}
		
		// Ensure parsed_url has scheme for later use
		if ( ! isset( $parsed_url['scheme'] ) ) {
			$parsed_url['scheme'] = $scheme;
		}
		
		// Require HTTPS if configured
		if ( $options['require_https'] && strtolower( $parsed_url['scheme'] ) !== 'https' ) {
			return array( 'valid' => false, 'error' => 'HTTPS is required' );
		}
		
		// Check host
		if ( ! isset( $parsed_url['host'] ) || empty( $parsed_url['host'] ) ) {
			return array( 'valid' => false, 'error' => 'URL must contain a host' );
		}
		
		$host = $parsed_url['host'];
		
		// Check domain whitelist
		if ( ! empty( $options['whitelist_domains'] ) ) {
			$whitelist = array_map( 'trim', explode( "\n", $options['whitelist_domains'] ) );
			$whitelist = array_filter( $whitelist );
			
			$allowed = false;
			foreach ( $whitelist as $allowed_domain ) {
				if ( $host === $allowed_domain || strpos( $host, '.' . $allowed_domain ) !== false ) {
					$allowed = true;
					break;
				}
			}
			
			if ( ! $allowed ) {
				return array( 'valid' => false, 'error' => 'Domain not in whitelist' );
			}
		}
		
		// Check domain blacklist
		if ( ! empty( $options['blacklist_domains'] ) ) {
			$blacklist = array_map( 'trim', explode( "\n", $options['blacklist_domains'] ) );
			$blacklist = array_filter( $blacklist );
			
			foreach ( $blacklist as $blocked_domain ) {
				if ( $host === $blocked_domain || strpos( $host, '.' . $blocked_domain ) !== false ) {
					return array( 'valid' => false, 'error' => 'Domain is blacklisted' );
				}
			}
		}
		
		// SSRF protection
		if ( ! $options['allow_localhost'] ) {
			$ssrf_check = self::check_ssrf_protection( $host );
			if ( ! $ssrf_check['allowed'] ) {
				return array( 'valid' => false, 'error' => $ssrf_check['error'] );
			}
		}
		
		return array( 'valid' => true, 'error' => '' );
	}
	
	/**
	 * Check if host is allowed (SSRF protection)
	 *
	 * @param string $host Hostname or IP address
	 * @return array Array with 'allowed' => true/false and 'error' => message
	 */
	public static function check_ssrf_protection( $host ) {
		static $resolved = array();
		$host = strtolower( trim( $host ) );
		if ( isset( $resolved[ $host ] ) ) return $resolved[ $host ];

		// Strip brackets from IPv6 literal hosts (e.g. "[::1]").
		$bare = trim( $host, '[]' );

		// Localhost variations.
		$localhost_variations = array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' );
		if ( in_array( $bare, $localhost_variations, true ) ) {
			return $resolved[ $host ] = array( 'allowed' => false, 'error' => 'Access to localhost is not allowed' );
		}

		// DNS rebinding attempts (trailing-dot tricks).
		foreach ( array( 'localhost.', '127.0.0.1.', '0.0.0.0.' ) as $pattern ) {
			if ( stripos( $host, $pattern ) === 0 ) {
				return $resolved[ $host ] = array( 'allowed' => false, 'error' => 'DNS rebinding attempt detected' );
			}
		}

		// IP spoofing via hex/octal/decimal integer encodings (e.g. 0x7f000001,
		// 017700000001, 2130706433 — all 127.0.0.1). A purely numeric host with
		// no dots is never a valid public hostname.
		if ( preg_match( '/^0x[0-9a-f]+$/i', $bare )
			|| preg_match( '/^0[0-7]+$/', $bare )
			|| preg_match( '/^\d+$/', $bare ) ) {
			return $resolved[ $host ] = array( 'allowed' => false, 'error' => 'IP spoofing attempt detected' );
		}

		// Collect every IP the host resolves to (A + AAAA), or the literal IP.
		// Checking ALL records closes the gap where a host has both a public A
		// record and a private AAAA record (or vice versa).
		$ips = array();
		if ( filter_var( $bare, FILTER_VALIDATE_IP ) ) {
			$ips[] = $bare;
		} else {
			if ( function_exists( 'dns_get_record' ) ) {
				$records = @dns_get_record( $host, DNS_A );
				$aaaa    = @dns_get_record( $host, DNS_AAAA );
				if ( is_array( $aaaa ) ) $records = array_merge( is_array( $records ) ? $records : array(), $aaaa );
				if ( is_array( $records ) ) {
					foreach ( $records as $rec ) {
						if ( ! empty( $rec['ip'] ) )   $ips[] = $rec['ip'];
						if ( ! empty( $rec['ipv6'] ) ) $ips[] = $rec['ipv6'];
					}
				}
			}
			// Fallback to IPv4-only resolution if dns_get_record gave nothing.
			if ( empty( $ips ) ) {
				$ip4 = gethostbyname( $host );
				if ( $ip4 !== $host ) $ips[] = $ip4;
			}
		}

		// If nothing resolved, let the HTTP layer fail naturally rather than
		// block a possibly-legitimate host on a resolver hiccup.
		if ( empty( $ips ) ) {
			return $resolved[ $host ] = array( 'allowed' => true, 'error' => '' );
		}

		foreach ( $ips as $ip ) {
			foreach ( self::$private_ip_ranges as $range ) {
				if ( self::ip_in_range( $ip, $range ) ) {
					return $resolved[ $host ] = array( 'allowed' => false, 'error' => 'Access to private IP addresses is not allowed' );
				}
			}
		}

		$result = array( 'allowed' => true, 'error' => '' );
		$resolved[ $host ] = $result;
		return $result;
	}

	/**
	 * Check if IP is in range
	 *
	 * @param string $ip IP address
	 * @param string $range IP range (CIDR notation)
	 * @return bool
	 */
	private static function ip_in_range( $ip, $range ) {
		
		if ( strpos( $range, '/' ) === false ) {
			return $ip === $range;
		}
		
		list( $subnet, $mask ) = explode( '/', $range );
		$mask = (int) $mask;
		
		// Determine IP version for both IP and subnet
		$ip_is_ipv6 = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) !== false;
		$subnet_is_ipv6 = filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) !== false;
		
		// If IP and subnet are different versions, they can't match
		if ( $ip_is_ipv6 !== $subnet_is_ipv6 ) {
			return false;
		}
		
		// IPv6
		if ( $ip_is_ipv6 ) {
			return self::ipv6_in_range( $ip, $subnet, $mask );
		}
		
		// IPv4 - validate mask range
		if ( $mask < 0 || $mask > 32 ) {
			return false;
		}

		// /0 matches everything; also avoids undefined "<< 32" shift below.
		if ( $mask === 0 ) {
			return ip2long( $ip ) !== false;
		}

		$ip_long = ip2long( $ip );
		$subnet_long = ip2long( $subnet );
		
		// Check if ip2long succeeded
		if ( $ip_long === false || $subnet_long === false ) {
			return false;
		}
		
		$mask_long = -1 << ( 32 - $mask );
		$subnet_long &= $mask_long;
		
		return ( $ip_long & $mask_long ) === $subnet_long;
	}
	
	/**
	 * Check if IPv6 is in range
	 *
	 * @param string $ip IPv6 address
	 * @param string $subnet Subnet
	 * @param int $mask Mask
	 * @return bool
	 */
	private static function ipv6_in_range( $ip, $subnet, $mask ) {
		
		$ip_bin = inet_pton( $ip );
		$subnet_bin = inet_pton( $subnet );
		
		if ( $ip_bin === false || $subnet_bin === false ) {
			return false;
		}
		
		$bytes = (int) $mask / 8;
		$bits = (int) $mask % 8;
		
		$ip_bytes = substr( $ip_bin, 0, $bytes );
		$subnet_bytes = substr( $subnet_bin, 0, $bytes );
		
		if ( $ip_bytes !== $subnet_bytes ) {
			return false;
		}
		
		if ( $bits > 0 && $bytes < strlen( $ip_bin ) ) {
			$ip_byte = ord( $ip_bin[ $bytes ] );
			$subnet_byte = ord( $subnet_bin[ $bytes ] );
			$mask_byte = 0xFF << ( 8 - $bits );
			
			return ( $ip_byte & $mask_byte ) === ( $subnet_byte & $mask_byte );
		}
		
		return true;
	}
	
	/**
	 * Validate query string
	 *
	 * @param string $query Query string
	 * @param string $query_type Query type (cssselector, xpath, regex)
	 * @return array Array with 'valid' => true/false and 'error' => message
	 */
	public static function validate_query( $query, $query_type = 'cssselector' ) {
		
		// Check length
		if ( strlen( $query ) > self::MAX_QUERY_LENGTH ) {
			return array( 'valid' => false, 'error' => 'Query exceeds maximum length' );
		}
		
		// Basic XSS protection - check for script tags
		if ( preg_match( '/<script/i', $query ) ) {
			return array( 'valid' => false, 'error' => 'Query contains potentially dangerous content' );
		}
		
		// Additional validation based on query type
		if ( $query_type === 'regex' ) {
			// Test if regex is valid
			$test_result = @preg_match( $query, '' );
			if ( $test_result === false ) {
				return array( 'valid' => false, 'error' => 'Invalid regular expression' );
			}
			
			// Check for potentially dangerous regex patterns (ReDoS protection)
			$dangerous_patterns = array(
				'/([a-zA-Z]+)*/',
				'/(a+)+/',
				'/(a|a)+/',
				'/(a|aa)+/',
			);
			
			// Simple check for nested quantifiers (basic ReDoS detection)
			if ( preg_match( '/\([^)]*\+[^)]*\)\+/', $query ) || preg_match( '/\([^)]*\*[^)]*\)\*/', $query ) ) {
				// This is a basic check - more sophisticated ReDoS detection would be needed for production
				// For now, we'll just log a warning but allow it
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'WP Web Scraper: Potentially dangerous regex pattern detected: ' . $query );
				}
			}
		}
		
		// XPath injection protection
		if ( $query_type === 'xpath' ) {
			// Check for potentially dangerous XPath expressions
			$dangerous_xpath = array( 'file://', 'system(', 'exec(', 'eval(' );
			foreach ( $dangerous_xpath as $pattern ) {
				if ( stripos( $query, $pattern ) !== false ) {
					return array( 'valid' => false, 'error' => 'XPath contains potentially dangerous content' );
				}
			}
		}
		
		// JSONPath validation
		if ( $query_type === 'jsonpath' ) {
			// Allow only dot-notation, brackets, wildcards, digits, letters, hyphens, underscores
			if ( preg_match( '/<script/i', $query ) ) {
				return array( 'valid' => false, 'error' => 'JSONPath contains potentially dangerous content' );
			}
			// Reject shell-injection patterns
			if ( preg_match( '/[`$();{}|><]/', str_replace( array( '$.' ), '', $query ) ) ) {
				return array( 'valid' => false, 'error' => 'JSONPath contains invalid characters' );
			}
			return array( 'valid' => true, 'error' => '' );
		}

		// CSS selector injection protection
		if ( $query_type === 'cssselector' ) {
			// Check for HTML tags (potential XSS) - but allow > and < as valid CSS selector operators
			// Only block if < or > appears in a context that suggests HTML tags
			if ( preg_match( '/<[a-z]/i', $query ) || preg_match( '/<\/[a-z]/i', $query ) ) {
				return array( 'valid' => false, 'error' => 'CSS selector contains potentially dangerous HTML tags' );
			}
			// Check for script injection attempts in attribute selectors
			if ( preg_match( '/["\'].*script.*["\']/i', $query ) ) {
				return array( 'valid' => false, 'error' => 'CSS selector contains potentially dangerous content' );
			}
		}
		
		return array( 'valid' => true, 'error' => '' );
	}
	
	/**
	 * Validate callback function
	 *
	 * @param string $callback Callback function name
	 * @param array $options Security options
	 * @return array Array with 'valid' => true/false and 'error' => message
	 */
	public static function validate_callback( $callback, $options = array() ) {

		if ( empty( $callback ) ) {
			return array( 'valid' => true, 'error' => '' );
		}

		// Check if function exists and is callable.
		if ( ! is_callable( $callback ) ) {
			return array( 'valid' => false, 'error' => 'Callback function is not callable' );
		}

		// Array/method callbacks are not allowed: WP_Web_Scraper methods leak
		// internals/re-enter the scraper, and arbitrary object methods are hard
		// to reason about. Use a plain function and allow-list it instead.
		if ( is_array( $callback ) ) {
			return array( 'valid' => false, 'error' => 'Array/method callbacks are not allowed; use an allow-listed function name' );
		}

		if ( ! is_string( $callback ) ) {
			return array( 'valid' => false, 'error' => 'Invalid callback type' );
		}

		$name = strtolower( trim( $callback ) );

		// Allow-list approach: only explicitly permitted functions may run on
		// scraped content. This prevents content authors from invoking arbitrary
		// PHP functions (phpinfo, file ops, etc.) via the callback attribute.
		foreach ( self::get_allowed_callbacks() as $allowed ) {
			if ( $name === strtolower( $allowed ) ) {
				return array( 'valid' => true, 'error' => '' );
			}
		}

		// Any function in the plugin/extension "wpws_" namespace is allowed:
		// defining such a function already requires code-level (theme/plugin)
		// access, which is a higher privilege than writing a shortcode.
		if ( preg_match( '/^wpws_[a-z0-9_]+$/', $name ) ) {
			return array( 'valid' => true, 'error' => '' );
		}

		return array(
			'valid' => false,
			'error' => 'Callback "' . $callback . '" is not allow-listed. Register it via the "wpws_allowed_callbacks" filter.',
		);
	}

	/**
	 * Return the list of callback function names permitted on scraped content.
	 * Filterable so site owners can register their own helpers.
	 *
	 * @return array Lower-/exact-case function names.
	 */
	public static function get_allowed_callbacks() {
		/**
		 * Filter the allow-list of callbacks usable via the `callback`/`callback_raw`
		 * shortcode attributes.
		 *
		 * @param array $callbacks Function names.
		 */
		return (array) apply_filters( 'wpws_allowed_callbacks', self::$builtin_allowed_callbacks );
	}
	
	/**
	 * Sanitize HTML output
	 *
	 * @param string $content HTML content
	 * @param array|null $allowed_html Allowed HTML tags (wp_kses format)
	 * @return string Sanitized content
	 */
	public static function sanitize_html_output( $content, $allowed_html = null ) {
		
		// Get security options
		$security_options = self::get_security_options();
		
		if ( ! $security_options['sanitize_html'] ) {
			return $content;
		}
		
		// Default allowed HTML tags
		if ( $allowed_html === null ) {
			$allowed_html = wp_kses_allowed_html( 'post' );
		}
		
		// Strip <script> blocks including their content before wp_kses runs.
		// wp_kses removes the <script> tag but preserves inner text; this removes both.
		$content = preg_replace( '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i', '', $content );
		
		// Use wp_kses for sanitization
		$content = wp_kses( $content, $allowed_html );
		
		return $content;
	}
	
	/**
	 * Get security options from database. Result is cached for the duration of the request.
	 *
	 * @return array Security options
	 */
	public static function get_security_options() {
		static $options = null;
		if ( $options !== null ) return $options;

		$wpws_options = get_option( 'wpws_options', array() );

		$options = array(
			'sanitize_html' => isset( $wpws_options['sanitize_html'] ) ? (bool) $wpws_options['sanitize_html'] : true,
			'allow_localhost' => isset( $wpws_options['allow_localhost'] ) ? (bool) $wpws_options['allow_localhost'] : false,
			'require_https' => isset( $wpws_options['require_https'] ) ? (bool) $wpws_options['require_https'] : false,
			'whitelist_domains' => isset( $wpws_options['whitelist_domains'] ) ? $wpws_options['whitelist_domains'] : '',
			'blacklist_domains' => isset( $wpws_options['blacklist_domains'] ) ? $wpws_options['blacklist_domains'] : '',
			'rate_limit_max'    => isset( $wpws_options['rate_limit_max'] ) ? absint( $wpws_options['rate_limit_max'] ) : 20,
			'rate_limit_window' => isset( $wpws_options['rate_limit_window'] ) ? absint( $wpws_options['rate_limit_window'] ) : 60,
		);
		return $options;
	}
	
	/**
	 * Check rate limit
	 *
	 * @param string $identifier Unique identifier (e.g., IP address or domain)
	 * @param array $options Rate limit options
	 * @return array Array with 'allowed' => true/false and 'error' => message
	 */
	public static function check_rate_limit( $identifier, $options = array() ) {
		
		$security_options = self::get_security_options();
		$options = wp_parse_args( $options, $security_options );
		
		$max_requests = $options['rate_limit_max'];
		$window = $options['rate_limit_window'];
		
		if ( $max_requests <= 0 || $window <= 0 ) {
			return array( 'allowed' => true, 'error' => '' );
		}
		
		$cache_key = 'wpws_rate_limit_' . md5( $identifier );
		$requests = get_transient( $cache_key );
		
		if ( $requests === false ) {
			$requests = array();
		}
		
		// Remove old requests outside the window
		$current_time = time();
		$requests = array_filter( $requests, function( $timestamp ) use ( $current_time, $window ) {
			return ( $current_time - $timestamp ) < $window;
		} );
		
		// Check if limit exceeded
		if ( count( $requests ) >= $max_requests ) {
			return array( 'allowed' => false, 'error' => 'Rate limit exceeded. Please try again later.' );
		}
		
		// Add current request
		$requests[] = $current_time;
		set_transient( $cache_key, $requests, $window );
		
		return array( 'allowed' => true, 'error' => '' );
	}
	
	/**
	 * Validate HTTP response
	 *
	 * @param array|WP_Error $response HTTP response
	 * @return array Array with 'valid' => true/false and 'error' => message
	 */
	public static function validate_response( $response ) {
		
		if ( is_wp_error( $response ) ) {
			return array( 'valid' => false, 'error' => $response->get_error_message() );
		}
		
		// Check response code
		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code >= 400 ) {
			return array( 'valid' => false, 'error' => 'HTTP error: ' . $response_code );
		}
		
		// Check response size
		$response_body = wp_remote_retrieve_body( $response );
		if ( strlen( $response_body ) > self::MAX_RESPONSE_SIZE ) {
			return array( 'valid' => false, 'error' => 'Response exceeds maximum size limit' );
		}
		
		// Check Content-Type header for potentially dangerous content
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( $content_type ) {
			$content_type_lower = strtolower( $content_type );
			
			// Block executable content types
			$dangerous_types = array( 'application/x-executable', 'application/x-msdownload', 'application/x-sh' );
			foreach ( $dangerous_types as $dangerous ) {
				if ( strpos( $content_type_lower, $dangerous ) !== false ) {
					return array( 'valid' => false, 'error' => 'Response contains executable content type' );
				}
			}
		}
		
		return array( 'valid' => true, 'error' => '' );
	}
	
	/**
	 * Validate HTML content for potential issues
	 *
	 * @param string $html HTML content
	 * @return array Array with 'valid' => true/false and 'error' => message
	 */
	public static function validate_html_content( $html ) {
		
		// Check for null bytes
		if ( strpos( $html, "\0" ) !== false ) {
			return array( 'valid' => false, 'error' => 'HTML contains null bytes' );
		}
		
		// Check for extremely deep nesting (potential DoS)
		$depth = 0;
		$max_depth = 0;
		$len = strlen( $html );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( $html[$i] === '<' && $i + 1 < $len && $html[$i + 1] !== '/' && $html[$i + 1] !== '!' ) {
				$depth++;
				$max_depth = max( $max_depth, $depth );
				if ( $max_depth > self::MAX_DOM_DEPTH ) {
					return array( 'valid' => false, 'error' => 'HTML contains excessive nesting depth' );
				}
			} elseif ( $html[$i] === '<' && $i + 1 < $len && $html[$i + 1] === '/' ) {
				$depth = max( 0, $depth - 1 );
			}
		}
		
		// Check for extremely long lines (potential DoS)
		$lines = explode( "\n", $html );
		foreach ( $lines as $line ) {
			if ( strlen( $line ) > 100000 ) { // 100KB per line
				return array( 'valid' => false, 'error' => 'HTML contains excessively long lines' );
			}
		}
		
		return array( 'valid' => true, 'error' => '' );
	}
	
	/**
	 * Sanitize callback input/output
	 *
	 * @param mixed $data Data to sanitize
	 * @return mixed Sanitized data
	 */
	public static function sanitize_callback_data( $data ) {
		
		if ( is_string( $data ) ) {
			// Remove null bytes
			$data = str_replace( "\0", '', $data );
			
			// Remove encoded null bytes
			$data = str_replace( array( '%00', '%2500' ), '', $data );
			
			// Limit length
			if ( strlen( $data ) > self::MAX_RESPONSE_SIZE ) {
				$data = substr( $data, 0, self::MAX_RESPONSE_SIZE );
			}
		} elseif ( is_array( $data ) ) {
			// Limit array depth to prevent recursion issues
			$max_depth = 10;
			$current_depth = 0;
			$data = self::sanitize_callback_data_recursive( $data, $current_depth, $max_depth );
		} elseif ( is_object( $data ) ) {
			// Convert objects to arrays for sanitization (basic protection)
			// In production, you might want to handle this differently
			$data = (array) $data;
			$data = self::sanitize_callback_data( $data );
		}
		
		return $data;
	}
	
	/**
	 * Recursively sanitize callback data with depth limit
	 *
	 * @param mixed $data Data to sanitize
	 * @param int $current_depth Current recursion depth
	 * @param int $max_depth Maximum allowed depth
	 * @return mixed Sanitized data
	 */
	private static function sanitize_callback_data_recursive( $data, $current_depth, $max_depth ) {
		
		if ( $current_depth >= $max_depth ) {
			return ''; // Truncate if too deep
		}
		
		if ( is_array( $data ) ) {
			$sanitized = array();
			foreach ( $data as $key => $value ) {
				$sanitized_key = is_string( $key ) ? sanitize_key( $key ) : $key;
				$sanitized[ $sanitized_key ] = self::sanitize_callback_data_recursive( $value, $current_depth + 1, $max_depth );
			}
			return $sanitized;
		} elseif ( is_string( $data ) ) {
			// Remove null bytes
			$data = str_replace( "\0", '', $data );
			$data = str_replace( array( '%00', '%2500' ), '', $data );
			
			// Limit length
			if ( strlen( $data ) > self::MAX_RESPONSE_SIZE ) {
				$data = substr( $data, 0, self::MAX_RESPONSE_SIZE );
			}
			return $data;
		}
		
		return $data;
	}
	
	/**
	 * Check for potential memory exhaustion
	 *
	 * @param string $content Content to check
	 * @return bool True if safe, false if potentially dangerous
	 */
	public static function check_memory_safety( $content ) {
		
		// Estimate memory usage (rough calculation)
		$estimated_memory = strlen( $content ) * 2; // Rough estimate
		$memory_limit = ini_get( 'memory_limit' );
		
		if ( $memory_limit ) {
			$memory_limit_bytes = self::convert_to_bytes( $memory_limit );
			$current_memory = memory_get_usage( true );
			$available_memory = $memory_limit_bytes - $current_memory;
			
			// If estimated memory usage would exceed 80% of available memory, warn
			if ( $estimated_memory > ( $available_memory * 0.8 ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'WP Web Scraper: Potential memory exhaustion detected. Content size: ' . strlen( $content ) . ' bytes' );
				}
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * Convert memory limit string to bytes
	 *
	 * @param string $val Memory limit string (e.g., "128M")
	 * @return int Bytes
	 */
	private static function convert_to_bytes( $val ) {
		
		$val = trim( $val );
		$last = strtolower( $val[ strlen( $val ) - 1 ] );
		$val = (int) $val;
		
		switch ( $last ) {
			case 'g':
				$val *= 1024;
			case 'm':
				$val *= 1024;
			case 'k':
				$val *= 1024;
		}
		
		return $val;
	}
}
