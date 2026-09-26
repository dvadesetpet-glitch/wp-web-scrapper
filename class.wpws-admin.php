<?php

/**
 * Admin settings and UI for WP WS Reborn 2026.
 *
 * Registers settings sections/fields, renders the main settings page
 * with tabs (Settings, Sandbox, Import, Help) and enqueues admin assets.
 */
class WP_Web_Scraper_Admin {

	/**
	 * Settings API configuration (sections, fields, option_group).
	 *
	 * @var array
	 */
	public static $settings;

	// -------------------------------------------------------------------------
	// Bootstrap & settings registration
	// -------------------------------------------------------------------------

	/** Bootstrap admin hooks. */
	public static function init() {
		add_action( 'admin_init', array( 'WP_Web_Scraper_Admin', 'admin_init' ) );
		add_action( 'admin_menu', array( 'WP_Web_Scraper_Admin', 'admin_menu' ) );
	}

	/**
	 * Register settings sections and fields, and load the text domain.
	 */
	public static function admin_init() {
		// Text domain must match the slug used by every __()/_e() call below.
		load_plugin_textdomain( 'wp-web-scraper' );
		
		// Load settings definition used by the Settings API.
		WP_Web_Scraper_Admin::$settings = 
		array(
			'sections' => array(
				array( 'id' => 'section_enable', 'title' => '', 
					'callback' => array('WP_Web_Scraper_Admin', 'section_enable_cb'), 'page' => 'wp_web_scraper_settings' ),
				array( 'id' => 'section_defaults', 'title' => __('Defaults', 'wp-web-scraper'), 
					'callback' => array('WP_Web_Scraper_Admin', 'section_defaults_cb'), 'page' => 'wp_web_scraper_settings' ),
				array( 'id' => 'section_security', 'title' => __('Security', 'wp-web-scraper'), 
					'callback' => array('WP_Web_Scraper_Admin', 'section_security_cb'), 'page' => 'wp_web_scraper_settings' ),
				array( 'id' => 'section_rate_limiting', 'title' => __('Rate Limiting', 'wp-web-scraper'), 
					'callback' => array('WP_Web_Scraper_Admin', 'section_rate_limiting_cb'), 'page' => 'wp_web_scraper_settings' ),				
			),
			'fields' => array(
				array( 'id' => 'enable', 'title' => __('Enable WP WS Reborn 2026', 'wp-web-scraper'), 
					'callback' => array('WP_Web_Scraper_Admin', 'fields_cb'), 'page' => 'wp_web_scraper_settings', 
					'section' => 'section_enable',  
					'args' => array( 'type' => 'group', 'data' => 'other_fields' ) ),
				array( 'id' => 'on_error', 'title' => __('Error Handling', 'wp-web-scraper'), 
					'callback' => array('WP_Web_Scraper_Admin', 'fields_cb'), 'page' => 'wp_web_scraper_settings', 
					'section' => 'section_defaults', 
					'args' => array( 'id' => 'on_error', 'type' => 'select', 
						'options' => array( 
							array('value' => 'error_hide', 'text' => __('Fail silently (no output on failure)', 'wp-web-scraper') ), 
							array('value' => 'error_show', 'text' => __('Display error details', 'wp-web-scraper') ) ), 
						'description' => __('Error display handling. Fail silently or display error.', 'wp-web-scraper') ) ),
				array( 'id' => 'useragent', 'title' => __('Useragent string', 'wp-web-scraper'), 
					'callback' => array('WP_Web_Scraper_Admin', 'fields_cb'), 'page' => 'wp_web_scraper_settings', 
					'section' => 'section_defaults', 
					'args' => array( 'id' => 'useragent', 'type' => 'text', 
						'description' => __('HTTP User-Agent header. Default: latest Chrome (desktop). Change to identify your bot or use another browser string.', 'wp-web-scraper') ) ),
				array( 'id' => 'timeout', 'title' => __('Timeout (seconds)', 'wp-web-scraper'), 
					'callback' => array('WP_Web_Scraper_Admin', 'fields_cb'), 'page' => 'wp_web_scraper_settings', 
					'section' => 'section_defaults', 
					'args' => array( 'id' => 'timeout', 'type' => 'number', 'step' => 1, 'min' => 1, 
						'description' => sprintf(
							__('Timeout seconds for HTTP request. <a href="%s" target="_blank" rel="noopener">Larger interval may impact pageload</a>.', 'wp-web-scraper'),
							esc_url( plugins_url( 'help-files/html/wpws-guide.html#requirements', WPWS__PLUGIN_DIR . 'wpws.php' ) )
						) ) ),
				array( 'id' => 'cache', 'title' => __('Cache expiration (minutes)', 'wp-web-scraper'), 
					'callback' => array('WP_Web_Scraper_Admin', 'fields_cb'), 'page' => 'wp_web_scraper_settings', 
					'section' => 'section_defaults', 
					'args' => array( 'id' => 'cache', 'type' => 'number', 'step' => 10, 'min' => 0, 
						'description' => sprintf(
							__('Cache expiration minutes for cached webpages. <br />Strongly recommended to <a href="%s" target="_blank" rel="noopener">use a cache plugin for better caching performance</a>.', 'wp-web-scraper'),
							esc_url( plugins_url( 'help-files/html/wpws-guide.html#requirements', WPWS__PLUGIN_DIR . 'wpws.php' ) )
						) ) ),
				// Security fields
				array( 'id' => 'sanitize_html', 'title' => __('Sanitize HTML Output', 'wp-web-scraper'), 
					'callback' => array('WP_Web_Scraper_Admin', 'fields_cb'), 'page' => 'wp_web_scraper_settings', 
					'section' => 'section_security', 
					'args' => array( 'id' => 'sanitize_html', 'type' => 'checkbox', 
						'text' => __('Enable HTML sanitization using wp_kses()', 'wp-web-scraper'),
						'description' => __('Removes potentially dangerous HTML, JavaScript, and event handlers from scraped content.', 'wp-web-scraper') ) ),
				array( 'id' => 'allow_localhost', 'title' => __('Allow Localhost Access', 'wp-web-scraper'), 
					'callback' => array('WP_Web_Scraper_Admin', 'fields_cb'), 'page' => 'wp_web_scraper_settings', 
					'section' => 'section_security', 
					'args' => array( 'id' => 'allow_localhost', 'type' => 'checkbox', 
						'text' => __('Allow access to localhost and private IP addresses', 'wp-web-scraper'),
						'description' => __('WARNING: Enabling this may expose your server to SSRF attacks. Only enable for development.', 'wp-web-scraper') ) ),
				array( 'id' => 'require_https', 'title' => __('Require HTTPS', 'wp-web-scraper'), 
					'callback' => array('WP_Web_Scraper_Admin', 'fields_cb'), 'page' => 'wp_web_scraper_settings', 
					'section' => 'section_security', 
					'args' => array( 'id' => 'require_https', 'type' => 'checkbox', 
						'text' => __('Only allow HTTPS URLs', 'wp-web-scraper'),
						'description' => __('Blocks all HTTP requests, only allows HTTPS.', 'wp-web-scraper') ) ),
				array( 'id' => 'whitelist_domains', 'title' => __('Domain Whitelist', 'wp-web-scraper'), 
					'callback' => array('WP_Web_Scraper_Admin', 'fields_cb'), 'page' => 'wp_web_scraper_settings', 
					'section' => 'section_security', 
					'args' => array( 'id' => 'whitelist_domains', 'type' => 'textarea', 
						'description' => __('One domain per line. Only these domains will be accessible. Leave empty to allow all domains.', 'wp-web-scraper') ) ),
				array( 'id' => 'blacklist_domains', 'title' => __('Domain Blacklist', 'wp-web-scraper'), 
					'callback' => array('WP_Web_Scraper_Admin', 'fields_cb'), 'page' => 'wp_web_scraper_settings', 
					'section' => 'section_security', 
					'args' => array( 'id' => 'blacklist_domains', 'type' => 'textarea', 
						'description' => __('One domain per line. These domains will be blocked.', 'wp-web-scraper') ) ),
				array( 'id' => 'auth_profiles', 'title' => __('Authentication profiles', 'wp-web-scraper'),
					'callback' => array('WP_Web_Scraper_Admin', 'fields_cb'), 'page' => 'wp_web_scraper_settings',
					'section' => 'section_security',
					'args' => array( 'id' => 'auth_profiles', 'type' => 'textarea',
						'description' => __('One profile per line: <code>name | bearer | TOKEN</code>, <code>name | basic | USER | PASSWORD</code> or <code>name | header | X-Api-Key | VALUE</code>. Use it with <code>auth_profile="name"</code> in shortcodes and blocks so credentials are not stored in post content. Lines starting with # are ignored. Developers can supply profiles via the <code>wpws_auth_profiles</code> filter instead.', 'wp-web-scraper') ) ),
				// Rate limiting fields
				array( 'id' => 'rate_limit_max', 'title' => __('Max Requests', 'wp-web-scraper'),
					'callback' => array('WP_Web_Scraper_Admin', 'fields_cb'), 'page' => 'wp_web_scraper_settings',
					'section' => 'section_rate_limiting',
					'args' => array( 'id' => 'rate_limit_max', 'type' => 'number', 'step' => 10, 'min' => 0,
						'description' => __('Maximum number of outgoing requests per remote host per time window (cache hits are not counted). Set to 0 to disable rate limiting.', 'wp-web-scraper') ) ),
				array( 'id' => 'rate_limit_window', 'title' => __('Time Window (seconds)', 'wp-web-scraper'), 
					'callback' => array('WP_Web_Scraper_Admin', 'fields_cb'), 'page' => 'wp_web_scraper_settings', 
					'section' => 'section_rate_limiting', 
					'args' => array( 'id' => 'rate_limit_window', 'type' => 'number', 'step' => 10, 'min' => 1, 
						'description' => __('Time window in seconds for rate limiting.', 'wp-web-scraper') ) ),				
			),
			'other_fields' => array(
				array( 'id' => 'sc_posts',  
					'args' => array( 'id' => 'sc_posts', 'type' => 'checkbox', 'text' => __('Enable shortcode in posts, pages', 'wp-web-scraper') ) ),
				array( 'id' => 'sc_widgets', 
					'args' => array( 'id' => 'sc_widgets', 'type' => 'checkbox', 'text' => __('Enable shortcode in widgets', 'wp-web-scraper') ) ),
				array( 'id' => 'tt',  
					'args' => array( 'id' => 'tt', 'type' => 'checkbox', 'text' => __('Enable template tag', 'wp-web-scraper') ) )				
			),
			'option_group' => 'wpws_options'
		);
		WP_Web_Scraper_Admin::settings_api( WP_Web_Scraper_Admin::$settings );
		
		// Settings page link
		add_filter( 'plugin_action_links_' . WPWS__PLUGIN_FILE, array('WP_Web_Scraper_Admin', 'plugin_settings_link'), 10, 2 );		
		
	}

	/** Register sections and fields with the Settings API. */
	public static function settings_api( $settings ) {
		foreach ($settings['sections'] as $section)
			add_settings_section( $section['id'], $section['title'], $section['callback'], $section['page'] );
		foreach ($settings['fields'] as $field)
			add_settings_field( $field['id'], $field['title'], $field['callback'], $field['page'], $field['section'], $field['args'] );
		register_setting( $settings['option_group'], $settings['option_group'], array(
			'sanitize_callback' => array( 'WP_Web_Scraper_Admin', 'sanitize_options' ),
		) );
	}

	/**
	 * Sanitize the wpws_options array on save.
	 *
	 * Previously options were stored exactly as posted. Besides accepting junk,
	 * that meant an unchecked checkbox was simply missing from the array — and a
	 * missing "sanitize_html" falls back to ON, so it could never be switched off.
	 * Checkboxes are now always stored explicitly as 0/1.
	 *
	 * @param mixed $input Raw posted value.
	 * @return array Clean options.
	 */
	public static function sanitize_options( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = array();

		foreach ( array( 'sc_posts', 'sc_widgets', 'tt', 'sanitize_html', 'allow_localhost', 'require_https' ) as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$on_error = isset( $input['on_error'] ) ? sanitize_text_field( $input['on_error'] ) : 'error_show';
		$out['on_error'] = $on_error !== '' ? $on_error : 'error_show';

		$useragent = isset( $input['useragent'] ) ? sanitize_text_field( $input['useragent'] ) : '';
		$out['useragent'] = $useragent !== ''
			? $useragent
			: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

		$out['timeout']           = isset( $input['timeout'] ) ? min( 120, max( 1, absint( $input['timeout'] ) ) ) : 10;
		$out['cache']             = isset( $input['cache'] ) ? absint( $input['cache'] ) : 60;
		$out['rate_limit_max']    = isset( $input['rate_limit_max'] ) ? absint( $input['rate_limit_max'] ) : 20;
		$out['rate_limit_window'] = isset( $input['rate_limit_window'] ) ? max( 1, absint( $input['rate_limit_window'] ) ) : 60;

		foreach ( array( 'whitelist_domains', 'blacklist_domains' ) as $key ) {
			$domains = array();
			$raw     = isset( $input[ $key ] ) ? (string) $input[ $key ] : '';
			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
				$line = strtolower( trim( $line ) );
				if ( $line !== '' && preg_match( '/^[a-z0-9.-]+$/', $line ) ) {
					$domains[] = $line;
				}
			}
			$out[ $key ] = implode( "\n", array_unique( $domains ) );
		}

		// Auth profiles: keep only lines that parse; report the rest.
		$kept = array(); $dropped = 0;
		$raw  = isset( $input['auth_profiles'] ) ? (string) $input['auth_profiles'] : '';
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( wp_strip_all_tags( $line ) );
			if ( $line === '' ) continue;
			if ( $line[0] === '#' || WP_Web_Scraper_Security::parse_auth_profiles_line_is_valid( $line ) ) {
				$kept[] = $line;
			} else {
				$dropped++;
			}
		}
		$out['auth_profiles'] = implode( "\n", $kept );
		if ( $dropped > 0 && function_exists( 'add_settings_error' ) ) {
			add_settings_error( 'wpws_options', 'wpws_auth_profiles',
				sprintf( _n( '%d authentication profile line was invalid and has been removed.', '%d authentication profile lines were invalid and have been removed.', $dropped, 'wp-web-scraper' ), $dropped ) );
		}

		return $out;
	}

	// -------------------------------------------------------------------------
	// Section & field callbacks
	// -------------------------------------------------------------------------

	/**
	 * Settings section callback for "Enable" section.
	 *
	 * Kept empty by design – fields explain themselves.
	 */
	public static function section_enable_cb() {}
	
	/**
	 * Settings section callback for "Defaults" section.
	 *
	 * Outputs a short description and link to arguments documentation.
	 */
	public static function section_defaults_cb() {
		$guide_url = defined( 'WPWS__PLUGIN_DIR' ) ? plugins_url( 'help-files/html/wpws-guide.html', WPWS__PLUGIN_DIR . 'wpws.php' ) . '#request-args' : '';
		printf( __( 'These settings are used as default <a href="%s" class="wpws-guide-link">arguments</a>, but can also be overridden in WP WS Reborn 2026 shortcodes and template tags', 'wp-web-scraper' ), esc_url( $guide_url ) );
	}
	
	/**
	 * Settings section callback for "Security" section.
	 */
	public static function section_security_cb() {
		_e( 'Security settings to protect your site from SSRF, XSS, and other attacks.', 'wp-web-scraper' );
	}
	
	/**
	 * Settings section callback for "Rate Limiting" section.
	 */
	public static function section_rate_limiting_cb() {
		_e( 'Rate limiting helps prevent abuse and DoS attacks by limiting the number of requests per time window.', 'wp-web-scraper' );
	}	
	
	/**
	 * Generic field renderer used by Settings API.
	 *
	 * @param array $option Field configuration (id, type, description, etc.).
	 */
	public static function fields_cb( $option ) {
		
		$wpws_options = get_option( WP_Web_Scraper_Admin::$settings['option_group'], array() );
		
		$options = array( $option );
		if( isset($option['type']) && $option['type'] === 'group' )
			foreach (WP_Web_Scraper_Admin::$settings[$option['data']] as $custom_field)
				$options[] = $custom_field['args'];
		
		echo '<fieldset>';
		foreach ($options as $option) {
			if ( !isset($option['id']) || !isset($option['type']) ) {
				continue;
			}
			
			$option_id = $option['id'];
			$option_value = isset($wpws_options[$option_id]) ? $wpws_options[$option_id] : '';
			
			if( $option['type'] === 'text' ){
				echo '<input name="'.WP_Web_Scraper_Admin::$settings['option_group'].'['.$option_id.']" type="text" id="'.$option_id.'" class="regular-text" value="'.esc_attr($option_value).'" />';
			}
			if( $option['type'] === 'textarea' ){
				echo '<textarea name="'.WP_Web_Scraper_Admin::$settings['option_group'].'['.$option_id.']" id="'.$option_id.'" class="large-text" rows="5">'.esc_textarea($option_value).'</textarea>';
			}
			if( $option['type'] === 'number' ){
				$step = isset($option['step']) ? $option['step'] : 1;
				$min = isset($option['min']) ? $option['min'] : 0;
				echo '<input name="'.WP_Web_Scraper_Admin::$settings['option_group'].'['.$option_id.']" type="number" id="'.$option_id.'" step="'.esc_attr($step).'" min="'.esc_attr($min).'" class="small-text" value="'.esc_attr($option_value).'" />';
			}
			if( $option['type'] === 'checkbox' ){
				$checked = isset($wpws_options[$option_id]) && $wpws_options[$option_id] == 1;
				$text = isset($option['text']) ? $option['text'] : '';
				echo '<label><input name="'.WP_Web_Scraper_Admin::$settings['option_group'].'['.$option_id.']" type="checkbox" id="'.$option_id.'" value="1" '.checked( 1, $checked, false ).' /> '.esc_html($text).'</label><br />';
			}		
			if( $option['type'] === 'select' && isset($option['options']) ){
				echo '<select name="'.WP_Web_Scraper_Admin::$settings['option_group'].'['.$option_id.']" id="'.$option_id.'">';
				foreach ($option['options'] as $value)
					echo '<option value="'.esc_attr($value['value']).'" '.selected( $value['value'], $option_value, false ).'>'.esc_html($value['text']).'</option>';
				echo '</select>';
			}
			if(isset($option['description'])) echo '<p class="description">' . wp_kses_post($option['description']) . '</p>';
		}
		echo '</fieldset>';
		
	}

	// -------------------------------------------------------------------------
	// Menu, scripts, settings page
	// -------------------------------------------------------------------------

	public static function admin_menu() {
		
		$page_hook_suffix = add_options_page(
			__('WP WS Reborn 2026 Settings', 'wp-web-scraper'), 
			__('WP WS Reborn 2026', 'wp-web-scraper'), 
			'manage_options', 
			'wp_web_scraper', 
			array('WP_Web_Scraper_Admin', 'plugin_settings_page')
		);
		add_action('admin_print_scripts-' . $page_hook_suffix, array( 'WP_Web_Scraper_Admin', 'admin_scripts' ));
	}
	
	public static function admin_scripts(){
		
		wp_enqueue_script( 'wpws-js', plugins_url( '/views/js/wpws.js', __FILE__ ), array('jquery-ui-tabs'), WPWS__VERSION );
		wp_enqueue_style( 'wpws-css', plugins_url( '/views/css/wpws.css', __FILE__ ), array(), WPWS__VERSION );
		
	}

	public static function plugin_settings_page() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		$vars['default_args'] = WP_Web_Scraper::get_default_args();
        $vars['default_args']['urldecode'] = 1;
        $vars['default_args']['querydecode'] = 0;
        
        // Settings controler
		if($tab === 'sandbox' && !empty($_POST) && wp_verify_nonce( $_POST['_wpnonce'], 'wpws-sandbox' ) !== false){
            
            // Check if shortcode test was submitted
            if( !empty( $_POST['shortcode_test'] ) ) {
                // Test shortcode directly
                $shortcode_input = trim( wp_unslash( $_POST['shortcode_test'] ) );
                $vars['shortcode_input'] = $shortcode_input;
                $vars['result_output'] = do_shortcode( $shortcode_input );
                $vars['is_shortcode_test'] = true;
            } else {
                // Regular form submission. esc_url_raw the URL; the query may be a
                // regex/XPath containing < > so it must NOT be tag-stripped here
                // (get_content() validates it). args is an HTTP query string.
                $s_url   = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
                $s_query = wp_unslash( $_POST['query'] ?? '' );
                $s_args  = wp_unslash( $_POST['args'] ?? '' );
                $_t0 = microtime( true );
                $vars['result_output'] = WP_Web_Scraper::get_content( $s_url, $s_query, $s_args );
                $vars['result_time']  = round( microtime( true ) - $_t0, 4 );
                $vars['result_count'] = WP_Web_Scraper::$count;
                $vars['result_error'] = WP_Web_Scraper::$error;
                $vars['result_url'] = WP_Web_Scraper::$url;
                $vars['result_query'] = WP_Web_Scraper::$query;
                $vars['result_args'] = WP_Web_Scraper::$args;
                if( strpos($_POST['url'], '<') !== false || strpos($_POST['url'], '>') !== false || strpos($_POST['url'], '[') !== false || strpos($_POST['url'], ']') !== false ){
                    $vars['result_url_shortcode'] = str_replace(array('<','>','[',']'), array('%3c','%3e','%5b','%5d'), WP_Web_Scraper::$url);
                    $vars['result_args']['urldecode'] = 1;
                } else {
                    $vars['result_url_shortcode'] = $vars['result_url'];
                }               
                if( strpos($_POST['query'], '<') !== false || strpos($_POST['query'], '>') !== false || strpos($_POST['query'], '[') !== false || strpos($_POST['query'], ']') !== false ){
                    $vars['result_query_shortcode'] = str_replace(array('<','>','[',']'), array('%3c','%3e','%5b','%5d'), WP_Web_Scraper::$query);
                    $vars['result_args']['querydecode'] = 1;
                } else {
                    $vars['result_query_shortcode'] = $vars['result_query'];
                }           
                $vars['modified_args_shortcode'] = array_diff_assoc($vars['result_args'], $vars['default_args']);
                $vars['modified_args_tt'] = array_diff_assoc(WP_Web_Scraper::$args, WP_Web_Scraper::get_default_args());
                $vars['result_xcache'] = WP_Web_Scraper::$xcache;
                $vars['is_shortcode_test'] = false;
            }
		}
        
        // Import controler
        if($tab === 'import' && !empty($_POST) && wp_verify_nonce( $_POST['_wpnonce'], 'wpws-import' ) !== false){
            update_option('wpws_last_import', stripslashes_deep($_POST));

            $url_str = '';
            if(!empty($_POST['post_sc_url']))
                $url_str = ' url="'.esc_url_raw( wp_unslash( $_POST['post_sc_url'] ) ).'"';

            $post_content_sc = stripslashes_deep( htmlspecialchars_decode( $_POST['post_content_sc'] ?? '' ) );
            $post_title_sc   = str_replace(']', $url_str.' debug="0" on_error="error_hide" output="text"]', stripslashes_deep( htmlspecialchars_decode( $_POST['post_title_sc'] ?? '' ) ) );
            $tags_input_sc   = str_replace(']', $url_str.' debug="0" on_error="error_hide" output="text" glue=","]', stripslashes_deep( htmlspecialchars_decode( $_POST['tags_input_sc'] ?? '' ) ) );

            // Build an explicit, sanitized post array rather than passing raw $_POST.
            $allowed_statuses = array( 'publish', 'draft', 'pending', 'private' );
            $status = isset( $_POST['post_status'] ) ? sanitize_key( $_POST['post_status'] ) : 'draft';
            if ( ! in_array( $status, $allowed_statuses, true ) ) $status = 'draft';

            $post = array(
                'post_status'   => $status,
                'post_author'   => isset( $_POST['post_author'] ) ? absint( $_POST['post_author'] ) : get_current_user_id(),
                'post_category' => isset( $_POST['post_category'] ) ? array_map( 'absint', (array) $_POST['post_category'] ) : array(),
            );

            if( ( $_POST['post_content_sc_mode'] ?? '' ) == 'shortcode'){
                $post['post_content'] = str_replace(']', $url_str.']', $post_content_sc);
            } else {
                $post_content_sc = str_replace(']', $url_str.' debug="0" on_error="error_hide"]', $post_content_sc);
                $post['post_content'] = trim( do_shortcode( $post_content_sc ) );
            }
            $post['post_title'] = sanitize_text_field( trim( do_shortcode( $post_title_sc ) ) );
            $post['tags_input'] = trim( do_shortcode( $tags_input_sc ) );

            if( ! empty( $post['post_title'] ) )
                $post_id = wp_insert_post( $post, true );
            if ( isset( $post_id ) && !is_wp_error( $post_id  ) ) {
                $vars['post_id'] = $post_id;
            } else {
                $vars['post_id'] = false;
            }
        }
        if($tab === 'import') {
            $vars['wpws_last_import'] = get_option('wpws_last_import', array());
            // Initialize post_id if not set (when no POST request)
            if ( ! isset( $vars['post_id'] ) ) {
                $vars['post_id'] = null;
            }
        }        
        
        // Render view
		WP_Web_Scraper::view( 'settings', $vars );
	}
	
	public static function plugin_settings_link($links) {
		$settings_link = '<a href="options-general.php?page=wp_web_scraper">' . __('Settings', 'wp-web-scraper') . '</a>';
		array_unshift($links, $settings_link);
		return $links;
	}

}