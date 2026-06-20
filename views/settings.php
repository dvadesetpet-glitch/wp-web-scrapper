<?php $current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings'; ?>
<div class="wrap">
    <h2><?php _e( 'WP WS Reborn 2026' , 'wp-web-scraper' )?></h2>

    <h2 class="nav-tab-wrapper">
        <a class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>" href="options-general.php?page=wp_web_scraper&tab=settings"><?php _e( 'Settings' , 'wp-web-scraper' )?></a>
        <a class="nav-tab <?php echo $current_tab === 'sandbox' ? 'nav-tab-active' : ''; ?>" href="options-general.php?page=wp_web_scraper&tab=sandbox"><?php _e( 'Sandbox' , 'wp-web-scraper' )?></a>
        <a class="nav-tab <?php echo $current_tab === 'import' ? 'nav-tab-active' : ''; ?>" href="options-general.php?page=wp_web_scraper&tab=import"><?php _e( 'Import' , 'wp-web-scraper' )?></a>
        <a class="nav-tab <?php echo $current_tab === 'help' ? 'nav-tab-active' : ''; ?>" href="options-general.php?page=wp_web_scraper&tab=help"><?php _e( 'Help' , 'wp-web-scraper' )?></a>
    </h2>    
    
	<div id="poststuff">
		<div id="post-body" class="metabox-holder columns-2">

			<div id="post-body-content">

            <?php if($current_tab === 'settings'): ?>	
                <form method="post" action="options.php"> 
                    <?php settings_fields('wpws_options'); ?>

                    <?php do_settings_sections('wp_web_scraper_settings'); ?>

                    <?php submit_button(); ?>
                </form>
            <?php endif; ?>	

            <?php if($current_tab === 'sandbox'): ?>
                <form method="post" action="?page=wp_web_scraper&tab=sandbox">
                  <?php wp_nonce_field('wpws-sandbox') ?>
                  <p><?php _e( 'A simple testing &amp; shortcode building tool for WP WS Reborn 2026.' , 'wp-web-scraper' )?></p>
                  <div class="wpws-examples">
                    <p class="description"><strong><?php _e( 'Quick examples', 'wp-web-scraper' ); ?></strong> — <?php _e( 'click one to prefill URL, Query and Other Arguments, then press “Test Scrap”.', 'wp-web-scraper' ); ?></p>

                    <p><span class="wpws-ex-cat"><?php _e( 'CSS selector', 'wp-web-scraper' ); ?>:</span>
                      <a href="#" class="wpws-sandbox-example" data-url="https://example.com" data-query="h1"><?php _e( 'Heading (h1)', 'wp-web-scraper' ); ?></a> ·
                      <a href="#" class="wpws-sandbox-example" data-url="https://example.com" data-query="p" data-args="output=text"><?php _e( 'Paragraph → plain text', 'wp-web-scraper' ); ?></a> ·
                      <a href="#" class="wpws-sandbox-example" data-url="https://example.com" data-query="a"><?php _e( 'Link (absolute href)', 'wp-web-scraper' ); ?></a> ·
                      <a href="#" class="wpws-sandbox-example" data-url="https://www.w3schools.com/tags/tag_hn.asp" data-query="h2" data-args="lt=3"><?php _e( 'First 3 h2 headings (lt=3)', 'wp-web-scraper' ); ?></a>
                    </p>

                    <p><span class="wpws-ex-cat"><?php _e( 'HTML tables', 'wp-web-scraper' ); ?>:</span>
                      <a href="#" class="wpws-sandbox-example" data-url="https://www.w3schools.com/html/html_tables.asp" data-query="#customers tr" data-args="eq=3"><?php _e( '4th row only (eq=3)', 'wp-web-scraper' ); ?></a> ·
                      <a href="#" class="wpws-sandbox-example" data-url="https://www.w3schools.com/html/html_tables.asp" data-query="#customers" data-args="callback=wpws_keep_first_3_columns"><?php _e( 'Keep first 3 columns (callback)', 'wp-web-scraper' ); ?></a> ·
                      <a href="#" class="wpws-sandbox-example" data-url="https://www.w3schools.com/html/html_tables.asp" data-query="#customers" data-args="callback=wpws_keep_first_4_rows"><?php _e( 'Keep first 4 rows (callback)', 'wp-web-scraper' ); ?></a> ·
                      <a href="#" class="wpws-sandbox-example" data-url="https://www.w3schools.com/html/html_tables.asp" data-query="#customers" data-args="callback=wpws_drop_columns:2"><?php _e( 'Drop column 2 (keep the rest)', 'wp-web-scraper' ); ?></a>
                    </p>

                    <p><span class="wpws-ex-cat"><?php _e( 'XPath', 'wp-web-scraper' ); ?>:</span>
                      <a href="#" class="wpws-sandbox-example" data-url="https://example.com" data-query="//h1" data-args="query_type=xpath&amp;output=text"><?php _e( 'Heading via XPath', 'wp-web-scraper' ); ?></a> ·
                      <a href="#" class="wpws-sandbox-example" data-url="https://example.com" data-query="//a" data-args="query_type=xpath"><?php _e( 'Link element', 'wp-web-scraper' ); ?></a>
                    </p>

                    <p><span class="wpws-ex-cat"><?php _e( 'Regex', 'wp-web-scraper' ); ?>:</span>
                      <a href="#" class="wpws-sandbox-example" data-url="https://example.com" data-query="/&lt;title&gt;.*?&lt;\/title&gt;/i" data-args="query_type=regex&amp;output=text"><?php _e( 'Page title', 'wp-web-scraper' ); ?></a> ·
                      <a href="#" class="wpws-sandbox-example" data-url="https://example.com" data-query="/href=&quot;(https?:[^&quot;]+)&quot;/i" data-args="query_type=regex"><?php _e( 'All href URLs', 'wp-web-scraper' ); ?></a>
                    </p>

                    <p><span class="wpws-ex-cat"><?php _e( 'JSONPath (JSON APIs)', 'wp-web-scraper' ); ?>:</span>
                      <a href="#" class="wpws-sandbox-example" data-url="https://jsonplaceholder.typicode.com/users" data-query="$.0.name" data-args="query_type=jsonpath"><?php _e( 'First user’s name', 'wp-web-scraper' ); ?></a> ·
                      <a href="#" class="wpws-sandbox-example" data-url="https://jsonplaceholder.typicode.com/users" data-query="$.*.email" data-args="query_type=jsonpath&amp;glue=,"><?php _e( 'All emails (wildcard)', 'wp-web-scraper' ); ?></a> ·
                      <a href="#" class="wpws-sandbox-example" data-url="https://jsonplaceholder.typicode.com/todos/1" data-query="$.title" data-args="query_type=jsonpath"><?php _e( 'Single field', 'wp-web-scraper' ); ?></a>
                    </p>
                  </div>
                    <table class="form-table">
                        <tbody>
                            <tr><th scope="row"><?php _e( 'Source URL' , 'wp-web-scraper' )?></th><td><fieldset><div class="wpws-url-row"><input name="url" type="text" id="url" class="large-text" value="<?php echo isset( $_POST['url'] ) ? esc_attr( wp_unslash( $_POST['url'] ) ) : ''; ?>"><button type="button" class="button" id="wpws-open-url-btn" title="<?php esc_attr_e( 'Open URL in new tab', 'wp-web-scraper' ); ?>"><?php _e( 'Open in new tab', 'wp-web-scraper' ); ?></button></div></fieldset></td></tr>
                            <tr><th scope="row"><?php _e( 'Query' , 'wp-web-scraper' )?></th><td><fieldset><input name="query" type="text" id="query" class="large-text wpws-tooltip" value="<?php echo isset( $_POST['query'] ) ? esc_attr( wp_unslash( $_POST['query'] ) ) : ''; ?>" data-tooltip="<strong>Query Examples:</strong><br/><br/><strong>CSS Selector:</strong><br/>#content<br/>.article-title<br/>div.post &gt; h2<br/><br/><strong>XPath:</strong><br/>//div[@class='content']<br/>//h1[@id='title']<br/><br/><strong>Regex:</strong><br/>/&lt;h1&gt;(.*?)&lt;\/h1&gt;/"><p class="description"><?php 
                                $guide_base = plugins_url( 'help-files/html/wpws-guide.html', WPWS__PLUGIN_DIR . 'wpws.php' );
                                printf( __( '<a href="%s" class="wpws-guide-link">CSS selector, XPath or Regex query</a> to fetch data.' , 'wp-web-scraper' ), esc_url( $guide_base . '#query-types' ) );
                            ?></p></fieldset></td></tr>
                            <tr><th scope="row"><?php _e( 'Other Arguments' , 'wp-web-scraper' )?></th><td><fieldset><input name="args" type="text" id="args" class="large-text wpws-tooltip" value="<?php echo isset( $_POST['args'] ) ? esc_attr( wp_unslash( $_POST['args'] ) ) : ''; ?>" autocomplete="off" data-tooltip="<strong>Common Arguments:</strong><br/><br/>query_type=cssselector<br/>output=html<br/>cache=60<br/>timeout=2<br/>glue=, <br/>eq=first<br/>gt=2<br/>lt=5"><p class="description"><?php 
                                printf( __( '<a href="%s" class="wpws-guide-link">Arguments</a> in HTTP query string format (e.g. <code>eq=3&amp;output=html&amp;cache=60</code>).', 'wp-web-scraper' ), esc_url( $guide_base . '#request-args' ) );
                            ?> <a data-args="<?php echo isset( $default_args ) ? esc_attr( http_build_query( $default_args ) ) : ''; ?>" id="load_default_args"><?php _e( 'Load defaults.' , 'wp-web-scraper' )?></a></p></fieldset></td></tr>
                        </tbody>
                    </table>
                    <?php submit_button( __( 'Test Scrap', 'wp-web-scraper' ) ); ?>
                </form>

                <hr style="margin: 20px 0;" />

                <h3><?php _e( 'Test Shortcode', 'wp-web-scraper' ); ?></h3>
                <form method="post" action="?page=wp_web_scraper&tab=sandbox">
                  <?php wp_nonce_field('wpws-sandbox') ?>
                  <p><?php _e( 'Paste a complete shortcode to test it directly', 'wp-web-scraper' )?></p>
                  <p class="wpws-examples"><span class="wpws-ex-cat"><?php _e( 'Examples', 'wp-web-scraper' ); ?>:</span>
                    <a href="#" class="wpws-shortcode-example" data-shortcode="<?php echo esc_attr( '[wpws url="https://example.com" query="h1"]' ); ?>"><?php _e( 'Basic CSS', 'wp-web-scraper' ); ?></a> ·
                    <a href="#" class="wpws-shortcode-example" data-shortcode="<?php echo esc_attr( '[wpws url="https://jsonplaceholder.typicode.com/users" query="$.*.name" query_type="jsonpath" glue=", "]' ); ?>"><?php _e( 'JSONPath list', 'wp-web-scraper' ); ?></a> ·
                    <a href="#" class="wpws-shortcode-example" data-shortcode="<?php echo esc_attr( '[wpws_atom_links url="https://feeds.bbci.co.uk/news/rss.xml" extension="jpg"]' ); ?>"><?php _e( 'RSS image links', 'wp-web-scraper' ); ?></a> ·
                    <a href="#" class="wpws-shortcode-example" data-shortcode="<?php echo esc_attr( '[wpws_atom_zip_links url="https://oss.uredjenazemlja.hr/oss/public/atom/atom_feed.xml" extension="zip"]' ); ?>"><?php _e( 'Atom .zip links', 'wp-web-scraper' ); ?></a>
                  </p>
                    <table class="form-table">
                        <tbody>
                            <tr><th scope="row"><?php _e( 'Shortcode', 'wp-web-scraper' )?></th><td><fieldset>
                              <div class="wpws-shortcode-row">
                                <textarea name="shortcode_test" id="shortcode_test" class="large-text code" rows="3" placeholder='[wpws url="https://example.com" query="#content"]'><?php echo isset( $_POST['shortcode_test'] ) ? esc_textarea( wp_unslash( $_POST['shortcode_test'] ) ) : ''; ?></textarea>
                                <div class="wpws-shortcode-buttons">
                                  <button type="button" class="button" id="wpws-shortcode-fill-form" title="<?php esc_attr_e( 'Parse shortcode and fill Source URL, Query, and Other Arguments above', 'wp-web-scraper' ); ?>"><?php _e( 'Fill form from shortcode', 'wp-web-scraper' ); ?></button>
                                  <button type="button" class="button" id="wpws-shortcode-clear" title="<?php esc_attr_e( 'Clear shortcode field', 'wp-web-scraper' ); ?>"><?php _e( 'Clear', 'wp-web-scraper' ); ?></button>
                                </div>
                              </div>
                              <p class="description"><?php _e( 'Paste a complete WPWS shortcode to test its output. Use “Fill form from shortcode” to copy URL, Query, and Arguments into the fields above.', 'wp-web-scraper' )?></p>
                            </fieldset></td></tr>
                        </tbody>
                    </table>
                    <?php submit_button( __( 'Test Shortcode', 'wp-web-scraper' ) ); ?>
                </form>

                <?php if(isset($result_output)) : ?>
                    <?php if(isset($is_shortcode_test) && $is_shortcode_test === true && isset($shortcode_input)) : ?>
                        <!-- Shortcode test result -->
                        <div id="wpws-shortcode-test-result" style="margin-top: 20px;">
                            <h3><?php _e( 'Shortcode Test Result', 'wp-web-scraper' ); ?></h3>
                            <div style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; margin-bottom: 15px;">
                                <h4><?php _e( 'Input Shortcode:', 'wp-web-scraper' ); ?></h4>
                                <code style="display: block; padding: 10px; background: #fff; border: 1px solid #ccc; word-break: break-all;"><?php echo esc_html( $shortcode_input ); ?></code>
                            </div>
                            <div style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd;">
                                <h4><?php _e( 'Output:', 'wp-web-scraper' ); ?></h4>
                                <div style="padding: 10px; background: #fff; border: 1px solid #ccc; min-height: 50px;">
                                    <?php echo $result_output; ?>
                                </div>
                            </div>
                        </div>
                    <?php else : ?>
                        <!-- Regular form result -->
                        <div id="wpws-sandbox" class="categorydiv">
                            <ul id="sandbox-tabs" class="sandbox-tabs">
                                <li class="tabs"><a href="#output"><?php _e( 'Output' , 'wp-web-scraper' )?></a></li>
                                <li class="hide-if-no-js tabs"><a href="#shortcode"><?php _e( 'Shortcode' , 'wp-web-scraper' )?></a></li>	
                                <li class="hide-if-no-js tabs"><a href="#tt"><?php _e( 'Template Tag' , 'wp-web-scraper' )?></a></li>	
                                <li class="hide-if-no-js tabs"><a href="#debug"><?php _e( 'Debug info' , 'wp-web-scraper' )?></a></li>
                            </ul>

                            <div id="output" class="tabs-panel">
                                <?php echo $result_output?>
                            </div>		

                            <div id="shortcode" class="tabs-panel">
                            <p class="description"><?php _e( 'Make sure you paste this in the Text mode in the post editor' , 'wp-web-scraper' )?></p>
                            <br />
                            <code>
                            [wpws url="<?php echo esc_attr($result_url_shortcode)?>" query="<?php echo esc_attr($result_query_shortcode?? '')?>"
                            <?php foreach($modified_args_shortcode as $key => $value) : ?>
                                <?php echo esc_html($key)?>="<?php echo esc_attr($value)?>"
                            <?php endforeach; ?>
                            ]
                            </code>
                            </div>

                            <div id="tt" class="tabs-panel">
                            <p class="description"><?php _e( 'Template tag for use in your theme' , 'wp-web-scraper' )?></p>
                            <br />
                            <code>
                            &lt;?php echo wpws_get_content('<?php echo esc_attr($result_url)?>', '<?php echo esc_attr($result_query)?>'
                            <?php echo (count($modified_args_tt) > 0 ? ', array(' : '')?>
                            <?php foreach($modified_args_tt as $key => $value) : ?>
                                '<?php echo esc_html($key)?>' => '<?php echo esc_attr($value)?>',
                            <?php endforeach; ?>
                            <?php echo (count($modified_args_tt) > 0 ? ')' : '')?>
                            ); ?&gt;
                            </code>
                            </div>      

                            <div id="debug" class="tabs-panel">
                                <h4><?php _e( 'Scrap source and info' , 'wp-web-scraper' )?></h4>
                                <table class="args">
                                            <tr><td class="key"><?php _e( 'Source URL' , 'wp-web-scraper' )?></td><td class="value"><a href="<?php echo esc_url($result_url)?>" target="_blank"><?php echo esc_html($result_url)?></a></td></tr>
                                    <tr><td class="key"><?php _e( 'Query' , 'wp-web-scraper' )?> (<?php echo esc_html($result_args['query_type'])?>)</td><td class="value"><?php echo esc_html($result_query)?></td></tr>
                                    <tr><td class="key"><?php _e( 'Matches' , 'wp-web-scraper' )?></td><td class="value"><?php echo isset($result_error) && $result_error !== null ? '<span style="color:#b32d2e">'.esc_html($result_error).'</span>' : esc_html( isset($result_count) ? $result_count : 0 ); ?></td></tr>
                                    <tr><td class="key"><?php _e( 'Fetch time' , 'wp-web-scraper' )?></td><td class="value"><?php echo isset($result_time) ? esc_html($result_time).' s' : '—'; ?></td></tr>
                                    <tr><td class="key"><?php _e( 'WPWS Cache Control' , 'wp-web-scraper' )?></td><td class="value"><?php echo esc_html($result_xcache)?></td></tr>
                                </table>
                                <h4><?php _e( 'Other arguments' , 'wp-web-scraper' )?></h4>
                                <table class="args">
                                <?php foreach($result_args as $key => $value) : ?>
                                    <tr>
                                      <td class="key"><?php echo $key?></td>
                                      <td class="value"><?php var_dump($value)?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>	

            <?php endif; ?>	

            <?php if($current_tab === 'import'): ?>
            <?php if(isset($post_id) && is_int($post_id)): ?><div id="message" class="updated below-h2"><p>Post published. <a href="<?php echo get_permalink( $post_id )?>" target="_blank">View Post</a>. <?php echo edit_post_link( 'Edit post', '', '', $post_id )?>.</p></div><?php endif; ?>
            <?php if(isset($post_id) && $post_id === false): ?><div id="message" class="updated error below-h2"><p>Error importing and posting.</p></div><?php endif; ?>
                <form method="post" action="?page=wp_web_scraper&tab=import">
                <?php wp_nonce_field('wpws-import') ?>
                <p><?php _e( 'A simple import utility to create posts using data scraped by WP WS Reborn 2026' , 'wp-web-scraper' )?></p>
                <table class="form-table">
                    <tbody>
                        <tr>
                          <th scope="row"><label for="post_sc_url">URL</label></th>
                          <td><input name="post_sc_url" type="url" id="post_sc_url" class="large-text code" value="<?php echo isset( $wpws_last_import['post_sc_url'] ) ? esc_attr( $wpws_last_import['post_sc_url'] ) : ''; ?>">
                          <p class="description">If used this will override the URL param of all WPWS shortcodes in Title, Content and Tags</p></td>
                        </tr>                      
                        <tr>
                          <th scope="row"><label for="post_title_sc">Title</label><p class="description">Shortcodes supported</p></th>
                          <td><input name="post_title_sc" type="text" id="post_title_sc" class="large-text code" required="required" value="<?php echo isset( $wpws_last_import['post_title_sc'] ) ? esc_attr( htmlspecialchars( $wpws_last_import['post_title_sc'] ) ) : ''; ?>"></td>
                        </tr>
                        <tr>
                          <th scope="row"><label for="post_content_sc">Content</label><p class="description">Shortcodes supported</p></th>
                          <td><?php wp_editor( isset( $wpws_last_import['post_content_sc'] ) ? $wpws_last_import['post_content_sc'] : '', 'postcontentsc', array( 'textarea_name' => 'post_content_sc', 'textarea_rows' => 10 ) ); ?>
                            <fieldset>
                              <label><input type="checkbox" name="post_content_sc_mode" value="shortcode" <?php checked( isset( $wpws_last_import['post_content_sc_mode'] ) ? $wpws_last_import['post_content_sc_mode'] : '', 'shortcode')?>> <span>Post as shortcode to keep it dynamic</span></label><br>
                            </fieldset>                
                          </td>
                        </tr>
                        <tr>
                          <th scope="row"><label for="tags_input_sc">Tags</label><p class="description">Shortcodes supported</p></th>
                          <td><input name="tags_input_sc" type="text" id="tags_input_sc" class="large-text code" value="<?php echo isset( $wpws_last_import['tags_input_sc'] ) ? esc_attr( htmlspecialchars( $wpws_last_import['tags_input_sc'] ) ) : ''; ?>">
                            <p class="description">Commas in the output are used as separators</p></td>
                        </tr>                        
                        <tr>
                          <th scope="row">Categories</th>
                          <td>
                            <ul class="cat-checklist category-checklist">
                              <?php wp_category_checklist(); ?>
                            </ul>
                          </td>
                        </tr>
                        <tr>
                          <th scope="row"><label for="post_status">Post Status</label></th>
                          <td>
                            <select name="post_status">
                              <option value="publish" <?php selected( isset( $wpws_last_import['post_status'] ) ? $wpws_last_import['post_status'] : 'draft', 'publish' ); ?>>Publish</option>
                              <option value="draft" <?php selected( isset( $wpws_last_import['post_status'] ) ? $wpws_last_import['post_status'] : 'draft', 'draft' ); ?>>Draft</option>
                              <option value="pending" <?php selected( isset( $wpws_last_import['post_status'] ) ? $wpws_last_import['post_status'] : 'draft', 'pending' ); ?>>Pending Review</option>
                              <option value="private" <?php selected( isset( $wpws_last_import['post_status'] ) ? $wpws_last_import['post_status'] : 'draft', 'private' ); ?>>Private</option>
                            </select>
                          </td>
                        </tr>                        
                        <tr>
                          <th scope="row">Author</th>
                          <td>
                            <?php wp_dropdown_users(array('name' => 'post_author')); ?>
                          </td>
                        </tr>

                    </tbody>
                </table>
                <?php submit_button( __( 'Import and Post', 'wp-web-scraper' ) ); ?>
                </form>
            <?php endif; ?>

            <?php if($current_tab === 'help'): ?>
                <style>
                    .help-section {
                        margin-bottom: 30px;
                        padding: 20px;
                        background: #fff;
                        border: 1px solid #ccc;
                        border-radius: 4px;
                    }
                    .help-section h3 {
                        margin-top: 0;
                        padding-bottom: 10px;
                        border-bottom: 2px solid #0073aa;
                        color: #0073aa;
                    }
                    .help-section h4 {
                        color: #333;
                        margin-top: 20px;
                    }
                    .help-section pre {
                        background: #f5f5f5;
                        padding: 15px;
                        border-left: 4px solid #0073aa;
                        overflow-x: auto;
                        white-space: pre-wrap;
                        word-wrap: break-word;
                    }
                    .help-section code {
                        background: #f0f0f0;
                        padding: 2px 6px;
                        border-radius: 3px;
                        font-size: 90%;
                        white-space: pre-wrap;
                        word-wrap: break-word;
                    }
                    .help-section pre code {
                        background: transparent;
                        padding: 0;
                    }
                    .help-section ul {
                        margin-left: 20px;
                    }
                    .help-section li {
                        margin-bottom: 8px;
                    }
                    .help-section .note {
                        background: #fff3cd;
                        border-left: 4px solid #ffc107;
                        padding: 12px;
                        margin: 15px 0;
                    }
                    .help-section .info {
                        background: #d1ecf1;
                        border-left: 4px solid #17a2b8;
                        padding: 12px;
                        margin: 15px 0;
                    }
                </style>

                <!-- BASIC USAGE -->
                <div class="help-section">
                    <h3>Basic Usage</h3>
                    <p>WP WS Reborn 2026 allows you to fetch content from any website and display it on your WordPress site using a <strong>shortcode</strong> or <strong>template tag function</strong>.</p>
                    
                    <h4>Shortcode Syntax (for posts, pages, widgets)</h4>
                    <pre><code>[wpws url="https://example.com" query="div.content" debug="1"]</code></pre>
                    
                    <h4>Template Tag Syntax (for PHP themes)</h4>
                    <pre><code>&lt;?php echo wpws_get_content('https://example.com', 'div.content', array('debug' => 1)); ?&gt;</code></pre>
                    
                    <div class="note">
                        <strong>Note:</strong> URL and Query are required parameters. All other arguments are optional.
                    </div>
                </div>

                <!-- QUERY TYPES -->
                <div class="help-section">
                    <h3>Query Types</h3>
                    <p>The plugin supports three methods for finding content on web pages:</p>
                    
                    <h4>1. CSS Selector (default)</h4>
                    <p>The simplest way to find elements using CSS selector syntax:</p>
                    <ul>
                        <li><code>div.content</code> - fetches all div elements with class "content"</li>
                        <li><code>#header</code> - fetches element with ID "header"</li>
                        <li><code>.sidebar</code> - fetches all elements with class "sidebar"</li>
                        <li><code>ul li</code> - fetches all li elements inside ul</li>
                        <li><code>a[href]</code> - fetches all links with href attribute</li>
                    </ul>
                    <p><strong>Example:</strong></p>
                    <pre><code>[wpws url="https://example.com" query="article.post h2.title"]</code></pre>
                    
                    <h4>2. XPath</h4>
                    <p>More powerful method for complex queries. You must add <code>query_type="xpath"</code>:</p>
                    <ul>
                        <li><code>//div[@class='content']</code> - fetches div with class content</li>
                        <li><code>//a[@href]</code> - fetches all links with href attribute</li>
                        <li><code>//h2[contains(@class, 'title')]</code> - fetches h2 containing class title</li>
                        <li><code>//channel/item/title</code> - for RSS/XML feeds</li>
                    </ul>
                    <p><strong>Example:</strong></p>
                    <pre><code>[wpws url="https://example.com/rss" query="//item/title" query_type="xpath"]</code></pre>
                    
                    <h4>3. Regular Expression (Regex)</h4>
                    <p>For advanced pattern matching scenarios. You must add <code>query_type="regex"</code>:</p>
                    <p><strong>Example:</strong></p>
                    <pre><code>[wpws url="https://example.com" query="/&lt;h2&gt;(.*?)&lt;\/h2&gt;/" query_type="regex"]</code></pre>

                    <h4>4. JSONPath (for JSON APIs)</h4>
                    <p>For JSON sources (REST APIs). Add <code>query_type="jsonpath"</code>. Simplified dot-notation: named keys, numeric indices (negatives allowed), and the <code>*</code> wildcard; a leading <code>$.</code> is optional.</p>
                    <ul>
                        <li><code>$.title</code> - the <code>title</code> field</li>
                        <li><code>$.0.name</code> - <code>name</code> of the first array item (<code>$.-1.name</code> = last)</li>
                        <li><code>$.items.*.id</code> - <code>id</code> of every element under <code>items</code></li>
                    </ul>
                    <pre><code>[wpws url="https://jsonplaceholder.typicode.com/users" query="$.*.email" query_type="jsonpath" glue=", "]</code></pre>

                    <div class="info">
                        <strong>Tip:</strong> Use the <strong>Sandbox tab</strong> to test queries before using them in production!
                    </div>
                </div>

                <!-- ARGUMENTS -->
                <div class="help-section">
                    <h3>Arguments</h3>
                    
                    <h4>Request Arguments (how content is fetched)</h4>
                    <ul>
                        <li><code>cache="60"</code> - cache timeout in minutes (default: 60)</li>
                        <li><code>timeout="10"</code> - request timeout in seconds (default: 10, minimum 5)</li>
                        <li><code>useragent</code> – custom User-Agent (default: latest Chrome desktop)</li>
                        <li><code>headers="key1=value1&key2=value2"</code> – extra POST body (query string); request becomes POST</li>
                        <li><code>auth_type="bearer"</code> + <code>auth_token="…"</code>, or <code>auth_type="basic"</code> + <code>auth_user</code>/<code>auth_pass</code> – HTTP authentication</li>
                        <li><code>mobile="1"</code> + <code>mobile_type="iphone"</code> (android/iphone/ipad/generic) – request the mobile version</li>
                        <li><code>ajax="1"</code> – lazy-load via AJAX after page render; <code>background_refresh="1"</code> – stale-while-revalidate cache refresh via WP-Cron</li>
                    </ul>
                    
                    <h4>Output Arguments (how content is displayed)</h4>
                    <ul>
                        <li><code>output="html"</code> - HTML format (default) or <code>output="text"</code> - text only without HTML tags</li>
                        <li><code>glue="&lt;br&gt;"</code> - string to join multiple results (default: new line)</li>
                        <li><code>debug="1"</code> - displays debug information in HTML comment (default: 0)</li>
                        <li><code>on_error="error_show"</code> - displays error or <code>on_error="error_hide"</code> - hides error</li>
                    </ul>
                    
                    <h4>Filter Arguments (filtering results)</h4>
                    <ul>
                        <li><code>eq="0"</code> - returns element at position 0 (first). Can be "first", "last" or number</li>
                        <li><code>gt="2"</code> - returns elements greater than position 2</li>
                        <li><code>lt="5"</code> - returns elements less than position 5</li>
                    </ul>
                    <p><strong>Example:</strong></p>
                    <pre><code>[wpws url="https://example.com" query="ul li" lt="10"]</code></pre>
                    
                    <h4>Remove and Replace</h4>
                    <ul>
                        <li><code>remove_query=".ads"</code> - removes all elements matching the query</li>
                        <li><code>remove_query_type="cssselector"</code> - query type for removal</li>
                        <li><code>replace_query=".old"</code> - finds elements to replace</li>
                        <li><code>replace_with="&lt;div&gt;new&lt;/div&gt;"</code> - new content</li>
                        <li><code>replace_query_type="cssselector"</code> - query type for replacement</li>
                    </ul>
                    
                    <h4>Link Correction</h4>
                    <ul>
                        <li><code>basehref="1"</code> - converts relative links to absolute (default: 1)</li>
                        <li><code>a_target="_blank"</code> - sets target="_blank" on all links</li>
                    </ul>
                    
                    <h4>Callback Functions</h4>
                    <ul>
                        <li><code>callback_raw="wpws_my_function"</code> – called before parsing; receives raw response (string or array)</li>
                        <li><code>callback="wpws_my_function"</code> – called after parsing; receives the final processed string</li>
                        <li><strong>Parameterised:</strong> <code>callback="wpws_drop_columns:4"</code> passes <code>"4"</code> as a second argument to the function</li>
                    </ul>
                    <div class="note">
                        <strong>Allow-list:</strong> only allow-listed functions may run as callbacks. Any function named <code>wpws_*</code> is allowed automatically; to permit your own non-<code>wpws_</code> function, add it via the <code>wpws_allowed_callbacks</code> filter.
                    </div>
                </div>

                <!-- TABLE HELPERS -->
                <div class="help-section">
                    <h3>Table Filtering Helpers</h3>
                    <p>Scrape a table and keep only the columns/rows you want. Use these via <code>callback</code> with the <code>name:spec</code> syntax. Numbers are <strong>1-based</strong>; specs support single (<code>4</code>), lists (<code>4_7_9</code>) and ranges (<code>5-8</code>).</p>
                    <ul>
                        <li><code>callback="wpws_drop_columns:2"</code> – remove column 2, keep the rest</li>
                        <li><code>callback="wpws_keep_columns:1-3_7"</code> – keep only columns 1, 2, 3 and 7</li>
                        <li><code>callback="wpws_drop_rows:2"</code> / <code>callback="wpws_keep_rows:1_3-5"</code> – same for rows</li>
                    </ul>
                    <pre><code>[wpws url="https://www.w3schools.com/html/html_tables.asp" query="#customers" callback="wpws_drop_columns:2"]</code></pre>
                    <p>Also available: <code>wpws_keep_first_3_columns</code> … <code>_10_columns</code>, <code>wpws_keep_first_N_rows</code>, combos like <code>wpws_keep_4_cols_5_rows</code>, and <code>wpws_keep_mobile_table_columns</code> (keeps columns 1, 2, 3, 4, 10).</p>
                    <div class="info">
                        <strong>Tip:</strong> Tables with merged cells (<code>colspan</code>/<code>rowspan</code>) may not line up by position — prefer <code>wpws_keep_columns</code> with an explicit list.
                    </div>
                </div>

                <!-- FEED SHORTCODES -->
                <div class="help-section">
                    <h3>Feed Link Shortcodes (RSS / Atom)</h3>
                    <p>Extract links of a given file type from any feed — e.g. images from RSS or downloads from Atom.</p>
                    <h4>wpws_atom_links (regex on the raw feed)</h4>
                    <pre><code>[wpws_atom_links url="http://rss.cnn.com/rss/cnn_topstories.rss" extension="jpg"]</code></pre>
                    <h4>wpws_atom_zip_links (DOM-based)</h4>
                    <pre><code>[wpws_atom_zip_links url="https://example.com/atom_feed.xml" extension="zip" format="plain"]</code></pre>
                    <p>Arguments: <code>url</code> (required), <code>extension</code> (without dot), <code>format="list"</code> or <code>"plain"</code>, <code>cache</code> (minutes).</p>
                </div>

                <!-- GUTENBERG BLOCK -->
                <div class="help-section">
                    <h3>Gutenberg Block</h3>
                    <p>In the block editor, add the <strong>WP Web Scraper</strong> block (search “scraper”). Configure URL, query, query type, output, cache, authentication and background refresh in the sidebar — a live preview renders in the editor. Output is server-side rendered, so it always reflects the current source on the front end.</p>
                </div>

                <!-- EXAMPLES -->
                <div class="help-section">
                    <h3>Usage Examples</h3>
                    
                    <h4>Example 1: W3Schools Heading Elements</h4>
                    <pre><code>[wpws url="https://www.w3schools.com/tags/tag_hn.asp" query="h2" lt="3"]</code></pre>
                    <p><em>Fetches the first 3 <code>&lt;h2&gt;</code> headings from the W3Schools heading tags documentation page.</em></p>
                    <div class="note">
                        <strong>Why this works:</strong> BBC News uses <code>&lt;h2&gt;</code> tags for all headlines. Using <code>lt="5"</code> limits results to first 5. The <code>debug="1"</code> shows cache status and request details. Test this in the Sandbox tab first!
                    </div>
                    
                    <h4>Example 2: RSS feed with XPath</h4>
                    <pre><code>[wpws url="https://feeds.bbci.co.uk/news/rss.xml" query="//item/title" query_type="xpath" glue="&lt;br&gt;" output="text" lt="10"]</code></pre>
                    <p><em>Fetches the first 10 item titles from the BBC News RSS feed.</em></p>
                    
                    <h4>Example 3: Table from external website</h4>
                    <pre><code>[wpws url="https://example.com/data" query="table.prices" basehref="1" a_target="_blank"]</code></pre>
                    <p><em>Fetches price table and fixes links to work correctly.</em></p>
                    
                    <h4>Example 4: Removing ads from content</h4>
                    <pre><code>[wpws url="https://example.com/article" query="article.content" remove_query=".advertisement"]</code></pre>
                    <p><em>Fetches article content but removes all advertisements.</em></p>
                    
                    <h4>Example 5: Dynamic URL with parameters</h4>
                    <pre><code>[wpws url="https://api.example.com/user/___user_id___" query=".user-info"]</code></pre>
                    <p><em>URL will automatically replace ___user_id___ with value from $_GET['user_id'].</em></p>
                </div>

                <!-- FILTERING EXAMPLES (eq, gt, lt) -->
                <div class="help-section">
                    <h3>Filtering Examples - eq, gt, lt</h3>
                    <p>Use <code>eq</code>, <code>gt</code>, and <code>lt</code> to filter multiple results.</p>
                    
                    <h4>Example with BBC News Headlines</h4>
                    <pre><code>[wpws url="https://www.bbc.com/news" query="h2" output="text" lt="10"]</code></pre>
                    <p><em>Gets first 10 headlines from BBC News homepage</em></p>
                    
                    <h4>Using eq (Equal - Get specific element)</h4>
                    <pre><code>[wpws url="https://www.bbc.com/news" query="h2" eq="0" output="text"]</code></pre>
                    <p><em>Returns only the first headline (index 0)</em></p>
                    
                    <pre><code>[wpws url="https://www.bbc.com/news" query="h2" eq="first" output="text"]</code></pre>
                    <p><em>Returns first headline. Same as eq="0"</em></p>
                    
                    <pre><code>[wpws url="https://www.bbc.com/news" query="h2" eq="last" output="text"]</code></pre>
                    <p><em>Returns the last headline found on the page</em></p>
                    
                    <h4>Using lt (Less Than - Get first N elements)</h4>
                    <pre><code>[wpws url="https://www.bbc.com/news" query="h2" lt="3" output="text" glue="&lt;br&gt;"]</code></pre>
                    <p><em>Returns first 3 headlines separated by line breaks</em></p>
                    
                    <pre><code>[wpws url="https://www.bbc.com/news" query="h2" lt="5" output="text" glue=" | "]</code></pre>
                    <p><em>Returns first 5 headlines separated by " | "</em></p>
                    
                    <h4>Using gt (Greater Than - Skip first N elements)</h4>
                    <pre><code>[wpws url="https://www.bbc.com/news" query="h2" gt="2" lt="8" output="text" glue="&lt;br&gt;"]</code></pre>
                    <p><em>Skips first 3 headlines (0,1,2), then shows next 5 (indexes 3-7)</em></p>
                    
                    <pre><code>[wpws url="https://www.bbc.com/news" query="h2" gt="9" output="text" glue=", "]</code></pre>
                    <p><em>Shows all headlines after the 10th one (index 10 and above)</em></p>
                    
                    <h4>Example with Navigation Menu</h4>
                    <div class="info">
                        <strong>Navigation items:</strong> Home (0), News (1), Sport (2), Business (3), Innovation (4), Culture (5), Arts (6), Travel (7), Earth (8), Audio (9), Video (10), Live (11)
                    </div>
                    
                    <pre><code>[wpws url="https://www.bbc.com/news" query="nav a" lt="5" output="text" glue=" | "]</code></pre>
                    <p><em>Returns: Home | News | Sport | Business | Innovation</em></p>
                    
                    <pre><code>[wpws url="https://www.bbc.com/news" query="nav a" gt="1" lt="6" output="text" glue=" → "]</code></pre>
                    <p><em>Returns: Sport → Business → Innovation → Culture</em></p>
                    
                    <pre><code>[wpws url="https://www.bbc.com/news" query="nav a" gt="8" output="text" glue=", "]</code></pre>
                    <p><em>Returns: Audio, Video, Live</em></p>
                    
                    <div class="note">
                        <strong>Remember:</strong> Indexes start at 0. <code>lt="5"</code> means indexes 0-4 (first 5 items). <code>gt="2"</code> means index 3 and above (skips 0,1,2). <code>gt="2" lt="6"</code> means indexes 3, 4, 5.
                    </div>
                </div>

                <!-- SANDBOX TESTING -->
                <div class="help-section">
                    <h3>Sandbox Tab - Testing</h3>
                    <p>The Sandbox tab allows safe testing before putting shortcode on live pages:</p>
                    
                    <h4>Test Scrap</h4>
                    <ul>
                        <li>Enter <strong>Source URL</strong> - URL from which you want to fetch content</li>
                        <li>Enter <strong>Query</strong> - CSS/XPath/Regex query</li>
                        <li><strong>Debug Mode checkbox</strong> - check for detailed debug information (cache status, parsed content, etc.)</li>
                        <li>Enter <strong>Other Arguments</strong> - additional options (e.g. cache=60&timeout=15)</li>
                        <li>Click <strong>Test Scrap</strong></li>
                    </ul>
                    
                    <p><strong>Tip:</strong> Debug mode automatically adds <code>debug=1</code> to Other Arguments field and vice versa - you can use either method!</p>
                    
                    <h4>Test Shortcode</h4>
                    <ul>
                        <li>Enter complete shortcode in <strong>Shortcode Test</strong> field</li>
                        <li>Click <strong>Test Shortcode</strong></li>
                    </ul>
                    
                    <p>Results will be displayed in tabs:</p>
                    <ul>
                        <li><strong>Output</strong> - final result</li>
                        <li><strong>Shortcode</strong> - generated shortcode ready for copying</li>
                        <li><strong>Template Tag</strong> - PHP code for themes</li>
                        <li><strong>Debug info</strong> - details about request, cache, etc.</li>
                    </ul>
                </div>

                <!-- IMPORT FUNCTIONALITY -->
                <div class="help-section">
                    <h3>Import Tab - Creating Posts</h3>
                    <p>The Import tab allows automatic creation of WordPress posts from scraped content:</p>
                    
                    <h4>How to use:</h4>
                    <ol>
                        <li><strong>URL</strong> - optional, override URL for all shortcodes in the form</li>
                        <li><strong>Title</strong> - can contain shortcode, e.g. <code>[wpws url="..." query="h1"]</code></li>
                        <li><strong>Content</strong> - can contain shortcodes</li>
                        <li><strong>Tags</strong> - can contain shortcode that returns comma-separated tags</li>
                        <li>Select <strong>categories</strong></li>
                        <li>Select <strong>post status</strong> (publish, draft, pending, private)</li>
                        <li>Select <strong>author</strong></li>
                        <li>Click <strong>Import and Post</strong></li>
                    </ol>
                    
                    <div class="info">
                        <strong>Dynamic content:</strong> You can check "Post as shortcode to keep it dynamic" so content remains as shortcode and updates automatically.
                    </div>
                </div>

                <!-- SECURITY -->
                <div class="help-section">
                    <h3>Security and Optimization</h3>
                    
                    <h4>Security features:</h4>
                    <ul>
                        <li><strong>SSRF protection</strong> - blocks localhost / private IPs and re-validates every redirect (configurable; whitelist &amp; blacklist domains, require HTTPS)</li>
                        <li><strong>Rate limiting</strong> - default 20 requests per minute per domain (configurable in Settings)</li>
                        <li><strong>Allow-listed callbacks</strong> - only <code>wpws_*</code> and registered functions may run</li>
                        <li><strong>Input sanitization</strong> - all inputs are sanitized</li>
                        <li><strong>CSRF protection</strong> - WordPress nonce verification</li>
                        <li><strong>XSS protection</strong> - output filtered with <code>wp_kses</code>; <code>&lt;script&gt;</code> stripped; credentials masked in debug output</li>
                    </ul>
                    <p>Configure these under the <strong>Security</strong> and <strong>Rate Limiting</strong> sections of the Settings tab.</p>
                    
                    <h4>Performance optimization:</h4>
                    <ul>
                        <li>Use <strong>higher cache</strong> for rarely changing content</li>
                        <li>Install <strong>persistent cache plugin</strong> (Redis, Memcached)</li>
                        <li>Reduce <strong>timeout</strong> so page loads faster</li>
                        <li>Use <strong>lt/gt</strong> filters to fetch only needed content</li>
                    </ul>
                    
                    <h4>Best Practices:</h4>
                    <ul>
                        <li>Always test in Sandbox first</li>
                        <li>Use <code>debug="1"</code> during development</li>
                        <li>Check target site's robots.txt</li>
                        <li>Respect copyright and ToS</li>
                        <li>Use cache to reduce number of requests</li>
                        <li>Don't scrape too fast (rate limiting)</li>
                        <li>Don't scrape sensitive data</li>
                    </ul>
                </div>

                <!-- IMPORTANT NOTES -->
                <div class="help-section">
                    <h3>Important Notes</h3>
                    <div class="note">
                        <ul style="margin:0;">
                            <li><strong>PHP version:</strong> PHP 7.4 or newer required</li>
                            <li><strong>WordPress version:</strong> WordPress 5.0+</li>
                            <li><strong>Dependency:</strong> Symfony CSS Selector component (already included)</li>
                            <li><strong>Cache:</strong> WordPress Transients API (persistent cache recommended)</li>
                            <li><strong>HTTP:</strong> WordPress HTTP API</li>
                        </ul>
                    </div>
                </div>

                <!-- ADDITIONAL RESOURCES -->
                <div class="help-section">
                    <h3>Additional Resources</h3>
                   <?php $guide_url = plugins_url( 'help-files/html/wpws-guide.html', WPWS__PLUGIN_DIR . 'wpws.php' ); ?>
                   <ul>
                       <li><a href="<?php echo esc_url( $guide_url ); ?>" class="wpws-guide-link">Complete guide</a></li>
                       <li><a href="<?php echo esc_url( $guide_url . '#basic-usage' ); ?>" class="wpws-guide-link">Basic usage (shortcode &amp; template tag)</a></li>
                       <li><a href="<?php echo esc_url( $guide_url . '#query-types' ); ?>" class="wpws-guide-link">Query types (CSS, XPath, Regex)</a></li>
                       <li><a href="<?php echo esc_url( $guide_url . '#request-args' ); ?>" class="wpws-guide-link">Request arguments</a></li>
                       <li><a href="<?php echo esc_url( $guide_url . '#response-args' ); ?>" class="wpws-guide-link">Response / output arguments</a></li>
                       <li><a href="<?php echo esc_url( $guide_url . '#filters' ); ?>" class="wpws-guide-link">Filters (eq, gt, lt)</a></li>
                       <li><a href="<?php echo esc_url( $guide_url . '#table-helpers' ); ?>" class="wpws-guide-link">Table filtering helpers (keep/drop columns &amp; rows)</a></li>
                       <li><a href="<?php echo esc_url( $guide_url . '#auth' ); ?>" class="wpws-guide-link">Authentication</a></li>
                       <li><a href="<?php echo esc_url( $guide_url . '#ajax' ); ?>" class="wpws-guide-link">AJAX lazy load &amp; background refresh</a></li>
                       <li><a href="<?php echo esc_url( $guide_url . '#atom' ); ?>" class="wpws-guide-link">Atom/RSS feed link shortcodes</a></li>
                       <li><a href="<?php echo esc_url( $guide_url . '#block' ); ?>" class="wpws-guide-link">Gutenberg block</a></li>
                       <li><a href="<?php echo esc_url( $guide_url . '#security' ); ?>" class="wpws-guide-link">Security &amp; allow-listed callbacks</a></li>
                       <li><a href="<?php echo esc_url( $guide_url . '#examples' ); ?>" class="wpws-guide-link">Examples</a></li>
                       <li><a href="<?php echo esc_url( $guide_url . '#requirements' ); ?>" class="wpws-guide-link">Requirements &amp; support</a></li>
                       <li><a href="https://wordpress.org/support/plugin/wp-web-scrapper/" target="_blank" rel="noopener">WordPress.org Support</a></li>
                   </ul>
                </div>

            <?php endif; ?>    

			</div>

			<div id="postbox-container-1" class="postbox-container">
				<div id="formatdiv" class="postbox ">
					<h3 class="hndle"><span><?php _e( 'Quick Help' , 'wp-web-scraper' )?></span></h3>
					<div class="inside">
                        <h4><?php _e( 'Quick Start', 'wp-web-scraper' )?></h4>
                        <p><strong><?php _e( 'Shortcode:', 'wp-web-scraper' )?></strong></p>
                        <code style="display:block;padding:8px;background:#f5f5f5;margin-bottom:10px;">[wpws url="..." query="..."]</code>
                        
                        <p><strong><?php _e( 'Template Tag:', 'wp-web-scraper' )?></strong></p>
                        <code style="display:block;padding:8px;background:#f5f5f5;margin-bottom:15px;">wpws_get_content($url, $query)</code>
                        
                        <h4><?php _e( 'Query Types', 'wp-web-scraper' )?></h4>
                        <ul style="margin-left:20px;line-height:1.6;">
                            <li><?php _e( 'CSS: <code>div.content</code>', 'wp-web-scraper' )?></li>
                            <li><?php _e( 'XPath: <code>//div</code>', 'wp-web-scraper' )?></li>
                            <li><?php _e( 'Regex: <code>/pattern/</code>', 'wp-web-scraper' )?></li>
                            <li><?php _e( 'JSONPath: <code>$.0.name</code>', 'wp-web-scraper' )?></li>
                        </ul>

                        <h4><?php _e( 'Useful Arguments', 'wp-web-scraper' )?></h4>
                        <ul style="margin-left:20px;line-height:1.6;">
                            <li><code>cache="60"</code> - <?php _e( 'cache in minutes', 'wp-web-scraper' )?></li>
                            <li><code>output="text"</code> - <?php _e( 'text only', 'wp-web-scraper' )?></li>
                            <li><code>query_type="jsonpath"</code> - <?php _e( 'for JSON APIs', 'wp-web-scraper' )?></li>
                            <li><code>callback="wpws_drop_columns:2"</code> - <?php _e( 'drop a table column', 'wp-web-scraper' )?></li>
                            <li><code>debug="1"</code> - <?php _e( 'debug mode', 'wp-web-scraper' )?></li>
                        </ul>
                        
                        <h4><?php _e( 'Filters', 'wp-web-scraper' )?></h4>
                        <ul style="margin-left:20px;line-height:1.6;">
                            <li><code>eq="1"</code> - <?php _e( 'get element at index 1', 'wp-web-scraper' )?></li>
                            <li><code>lt="5"</code> - <?php _e( 'first 5 results', 'wp-web-scraper' )?></li>
                            <li><code>gt="2"</code> - <?php _e( 'skip first 3, get rest', 'wp-web-scraper' )?></li>
                        </ul>
                        
                        <h4><?php _e( 'Test First!', 'wp-web-scraper' )?></h4>
                        <p><?php _e( 'Use the <strong>Sandbox</strong> tab to test before production.', 'wp-web-scraper' )?></p>
                        
                        <h4><?php _e( 'Documentation', 'wp-web-scraper' )?></h4>
                        <p><a href="?page=wp_web_scraper&tab=help"><?php _e( 'See Help tab', 'wp-web-scraper' )?></a></p>
                        <p><a href="<?php echo esc_url( plugins_url( 'help-files/html/wpws-guide.html', WPWS__PLUGIN_DIR . 'wpws.php' ) ); ?>" class="wpws-guide-link"><?php _e( 'Complete guide (wpws-guide)', 'wp-web-scraper' )?></a></p>
                        <p>
                            <a href="<?php echo esc_url( plugins_url( 'help-files/html/wpws-guide.html', WPWS__PLUGIN_DIR . 'wpws.php' ) . '#query-types' ); ?>" class="wpws-guide-link"><?php _e( 'Query types', 'wp-web-scraper' )?></a>
                             · 
                            <a href="<?php echo esc_url( plugins_url( 'help-files/html/wpws-guide.html', WPWS__PLUGIN_DIR . 'wpws.php' ) . '#request-args' ); ?>" class="wpws-guide-link"><?php _e( 'Arguments', 'wp-web-scraper' )?></a>
                             · 
                            <a href="<?php echo esc_url( plugins_url( 'help-files/html/wpws-guide.html', WPWS__PLUGIN_DIR . 'wpws.php' ) . '#examples' ); ?>" class="wpws-guide-link"><?php _e( 'Examples', 'wp-web-scraper' )?></a>
                        </p>
                        
                        <h4><?php _e( 'Support', 'wp-web-scraper' )?></h4>
                        <p><a href="https://wordpress.org/support/plugin/wp-web-scrapper/" target="_blank" rel="noopener"><?php _e( 'WordPress.org Forum', 'wp-web-scraper' )?></a></p>
					</div>
				</div>
			</div>

		</div> <!-- #post-body -->
	</div> <!-- #poststuff -->    
    
</div>