<?php

/*
Plugin Name: WP WS Reborn 2026
Plugin URI: http://wp-ws.net/
Description: Web scraper for WordPress. Display realtime data from any website in posts, pages or sidebar. Fork of WP Web Scraper with feed link shortcodes (wpws_atom_links, wpws_atom_zip_links).
Version: 1.2
Author: Akshay Raje (original), Reborn 2026
Author URI: http://webdlabs.com/
*/

// Make sure we don't expose any info if called directly. Silence is golden.
if (!function_exists('add_action'))
	exit;

define('WPWS__PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPWS__PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPWS__PLUGIN_FILE', plugin_basename(__FILE__));
define('WPWS__VERSION', '1.2');

require_once( WPWS__PLUGIN_DIR . 'class.wpws-security.php' );
require_once( WPWS__PLUGIN_DIR . 'class.wpws.php' );

register_activation_hook(__FILE__, array('WP_Web_Scraper', 'plugin_activate'));
register_deactivation_hook(__FILE__, array('WP_Web_Scraper', 'plugin_deactivate'));

add_action('init', array('WP_Web_Scraper', 'init'));

if (is_admin()) {
	require_once( WPWS__PLUGIN_DIR . 'class.wpws-admin.php' );
	add_action('init', array('WP_Web_Scraper_Admin', 'init'));
}

$wpws_options = get_option('wpws_options', array());
if ( !function_exists('wpws_get_content') && isset($wpws_options['tt']) && $wpws_options['tt'] == 1 ) {
    function wpws_get_content($url, $query = '', $args = array()){
        return WP_Web_Scraper::get_content($url, $query, $args);
    }
}

/**
 * Generic helper function to filter table by columns and/or rows
 * 
 * @param string $html HTML content containing table(s)
 * @param int $num_columns Number of columns to keep (0 = keep all)
 * @param int $num_rows Number of rows to keep (0 = keep all, includes header)
 * @return string Modified HTML
 */
if ( !function_exists('wpws_filter_table') ) {
    function wpws_filter_table($html, $num_columns = 0, $num_rows = 0) {
        // Keep first N columns / rows == ranges "1-N". Delegates to the advanced
        // implementation so there is a single copy of the DOM-filtering logic.
        $columns_spec = $num_columns > 0 ? '1-' . (int) $num_columns : '';
        $rows_spec    = $num_rows    > 0 ? '1-' . (int) $num_rows    : '';
        return wpws_filter_table_advanced($html, $columns_spec, $rows_spec);
    }
}

// Generic N-based helpers (use these directly in PHP code)
if ( !function_exists('wpws_keep_first_n_columns') ) {
    function wpws_keep_first_n_columns($html, $num_columns = 3) { return wpws_filter_table($html, $num_columns, 0); }
}
if ( !function_exists('wpws_keep_first_n_rows') ) {
    function wpws_keep_first_n_rows($html, $num_rows = 3) { return wpws_filter_table($html, 0, $num_rows); }
}

// Named column callbacks (callback="wpws_keep_first_N_columns")
if ( !function_exists('wpws_keep_first_3_columns') )  { function wpws_keep_first_3_columns($h)  { return wpws_keep_first_n_columns($h, 3);  } }
if ( !function_exists('wpws_keep_first_4_columns') )  { function wpws_keep_first_4_columns($h)  { return wpws_keep_first_n_columns($h, 4);  } }
if ( !function_exists('wpws_keep_first_5_columns') )  { function wpws_keep_first_5_columns($h)  { return wpws_keep_first_n_columns($h, 5);  } }
if ( !function_exists('wpws_keep_first_6_columns') )  { function wpws_keep_first_6_columns($h)  { return wpws_keep_first_n_columns($h, 6);  } }
if ( !function_exists('wpws_keep_first_7_columns') )  { function wpws_keep_first_7_columns($h)  { return wpws_keep_first_n_columns($h, 7);  } }
if ( !function_exists('wpws_keep_first_8_columns') )  { function wpws_keep_first_8_columns($h)  { return wpws_keep_first_n_columns($h, 8);  } }
if ( !function_exists('wpws_keep_first_9_columns') )  { function wpws_keep_first_9_columns($h)  { return wpws_keep_first_n_columns($h, 9);  } }
if ( !function_exists('wpws_keep_first_10_columns') ) { function wpws_keep_first_10_columns($h) { return wpws_keep_first_n_columns($h, 10); } }

// Named row callbacks (callback="wpws_keep_first_N_rows")
if ( !function_exists('wpws_keep_first_3_rows') )  { function wpws_keep_first_3_rows($h)  { return wpws_keep_first_n_rows($h, 3);  } }
if ( !function_exists('wpws_keep_first_4_rows') )  { function wpws_keep_first_4_rows($h)  { return wpws_keep_first_n_rows($h, 4);  } }
if ( !function_exists('wpws_keep_first_5_rows') )  { function wpws_keep_first_5_rows($h)  { return wpws_keep_first_n_rows($h, 5);  } }
if ( !function_exists('wpws_keep_first_6_rows') )  { function wpws_keep_first_6_rows($h)  { return wpws_keep_first_n_rows($h, 6);  } }
if ( !function_exists('wpws_keep_first_7_rows') )  { function wpws_keep_first_7_rows($h)  { return wpws_keep_first_n_rows($h, 7);  } }
if ( !function_exists('wpws_keep_first_8_rows') )  { function wpws_keep_first_8_rows($h)  { return wpws_keep_first_n_rows($h, 8);  } }
if ( !function_exists('wpws_keep_first_9_rows') )  { function wpws_keep_first_9_rows($h)  { return wpws_keep_first_n_rows($h, 9);  } }
if ( !function_exists('wpws_keep_first_10_rows') ) { function wpws_keep_first_10_rows($h) { return wpws_keep_first_n_rows($h, 10); } }

// Named combined column+row callbacks
if ( !function_exists('wpws_keep_3_cols_3_rows') ) { function wpws_keep_3_cols_3_rows($h) { return wpws_filter_table($h, 3, 3); } }
if ( !function_exists('wpws_keep_4_cols_3_rows') ) { function wpws_keep_4_cols_3_rows($h) { return wpws_filter_table($h, 4, 3); } }
if ( !function_exists('wpws_keep_3_cols_5_rows') ) { function wpws_keep_3_cols_5_rows($h) { return wpws_filter_table($h, 3, 5); } }
if ( !function_exists('wpws_keep_4_cols_5_rows') ) { function wpws_keep_4_cols_5_rows($h) { return wpws_filter_table($h, 4, 5); } }
if ( !function_exists('wpws_keep_3_cols_4_rows') ) { function wpws_keep_3_cols_4_rows($h) { return wpws_filter_table($h, 3, 4); } }
if ( !function_exists('wpws_keep_4_cols_4_rows') ) { function wpws_keep_4_cols_4_rows($h) { return wpws_filter_table($h, 4, 4); } }
if ( !function_exists('wpws_keep_5_cols_3_rows') ) { function wpws_keep_5_cols_3_rows($h) { return wpws_filter_table($h, 5, 3); } }
if ( !function_exists('wpws_keep_5_cols_4_rows') ) { function wpws_keep_5_cols_4_rows($h) { return wpws_filter_table($h, 5, 4); } }
if ( !function_exists('wpws_keep_5_cols_5_rows') ) { function wpws_keep_5_cols_5_rows($h) { return wpws_filter_table($h, 5, 5); } }

/**
 * Helper function to keep only mobile table columns (like ksz-zagreb.hr mobile view)
 * Keeps: #, Klub, Pob, Por, Bod (columns 1, 2, 3, 4, and 10)
 * Usage: callback="wpws_keep_mobile_table_columns"
 * 
 * @param string $html HTML content containing table(s)
 * @return string Modified HTML with only mobile columns
 */
if ( !function_exists('wpws_keep_mobile_table_columns') ) {
    function wpws_keep_mobile_table_columns($html) {
        // Use new advanced filtering function
        return wpws_filter_table_advanced($html, '1_2_3_4_10', '');
    }
}

// ============================================
// ADVANCED FLEXIBLE TABLE FILTERING
// ============================================

/**
 * Parse column/row specification string into array of indices
 * Supports formats: "1", "1-3", "1_2_5", "1-3_7", "1-3_7-9"
 * 
 * @param string $spec Specification string (e.g. "1-3_7" or "1_2_5")
 * @return array Array of 0-indexed indices to keep
 */
if ( !function_exists('wpws_parse_table_spec') ) {
    function wpws_parse_table_spec($spec) {
        $indices = array();
        
        if (empty($spec)) {
            return $indices;
        }
        
        // Split by underscore to get individual parts
        $parts = explode('_', $spec);
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }
            
            // Check if it's a range (contains dash)
            if (strpos($part, '-') !== false) {
                // Range format: "1-3" or "7-9"
                list($start, $end) = explode('-', $part, 2);
                $start = (int) trim($start);
                $end = (int) trim($end);
                
                // Convert to 0-indexed and add all indices in range
                for ($i = $start - 1; $i <= $end - 1; $i++) {
                    if ($i >= 0) {
                        $indices[] = $i;
                    }
                }
            } else {
                // Single number format: "1" or "5"
                $num = (int) trim($part);
                if ($num > 0) {
                    // Convert to 0-indexed
                    $indices[] = $num - 1;
                }
            }
        }
        
        // Remove duplicates and sort
        $indices = array_unique($indices);
        sort($indices);
        
        return $indices;
    }
}

/**
 * Advanced flexible table filtering function
 * Filters table by specific columns and/or rows based on specification strings
 * 
 * @param string $html HTML content containing table(s)
 * @param string $columns_spec Column specification (e.g. "1-3_7" for columns 1-3 and 7)
 * @param string $rows_spec Row specification (e.g. "2-5_7-11" for rows 2-5 and 7-11)
 * @return string Modified HTML
 */
if ( !function_exists('wpws_filter_table_advanced') ) {
    function wpws_filter_table_advanced($html, $columns_spec = '', $rows_spec = '') {
        // Return original HTML if no specifications provided
        if (empty($columns_spec) && empty($rows_spec)) {
            return $html;
        }
        
        // Check if HTML is empty or not a string
        if (empty($html) || !is_string($html)) {
            return $html;
        }
        
        // Load HTML into DOMDocument
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        
        // Wrap HTML in body tag if it doesn't contain html/body tags
        // This handles cases where query returns only a table element or a div containing table
        $wrapped_html = $html;
        if (stripos($html, '<html') === false && stripos($html, '<body') === false) {
            $wrapped_html = '<?xml encoding="UTF-8"><body>' . $html . '</body>';
        } else {
            $wrapped_html = '<?xml encoding="UTF-8">' . $html;
        }
        
        $dom->loadHTML($wrapped_html, LIBXML_NONET);
        libxml_clear_errors();

        $xpath  = new DOMXPath($dom);
        $tables = $xpath->query('//table');

        if ($tables->length === 0) {
            return $html;
        }

        $modified = false;
        
        $keep_column_indices = !empty($columns_spec) ? wpws_parse_table_spec($columns_spec) : null;
        $keep_row_indices    = !empty($rows_spec)    ? wpws_parse_table_spec($rows_spec)    : null;

        foreach ($tables as $table) {
            $rows      = $xpath->query('.//tr', $table);
            $row_count = $rows->length;

            // Filter rows if specification provided
            if ($keep_row_indices !== null && !empty($keep_row_indices)) {
                // Remove rows that are not in keep_row_indices (in reverse order)
                for ($i = $row_count - 1; $i >= 0; $i--) {
                    if (!in_array($i, $keep_row_indices)) {
                        $row = $rows->item($i);
                        if ($row && $row->parentNode) {
                            $row->parentNode->removeChild($row);
                            $modified = true;
                        }
                    }
                }
                // Re-query rows after removal
                $rows = $xpath->query('.//tr', $table);
            }
            
            // Filter columns if specification provided
            if ($keep_column_indices !== null && !empty($keep_column_indices)) {
                foreach ($rows as $row) {
                    $cells      = $xpath->query('.//th | .//td', $row);
                    $cell_count = $cells->length;

                    // Only filter if we have cells and specification
                    if ($cell_count > 0) {
                        // Remove cells that are not in keep_column_indices (in reverse order)
                        for ($i = $cell_count - 1; $i >= 0; $i--) {
                            if (!in_array($i, $keep_column_indices)) {
                                $cell = $cells->item($i);
                                if ($cell && $cell->parentNode) {
                                    $cell->parentNode->removeChild($cell);
                                    $modified = true;
                                }
                            }
                        }
                    }
                }
            }
            
            if ($keep_row_indices !== null && !empty($keep_row_indices)) {
                $modified = true;
            }
        }
        
        // Always return modified HTML if we have specifications
        // Even if no modifications were made, we should return the processed HTML
        if ($keep_column_indices !== null || $keep_row_indices !== null) {
            $body = $dom->getElementsByTagName('body')->item(0);
            if ($body) {
                $html = '';
                foreach ($body->childNodes as $child) {
                    $html .= $dom->saveHTML($child);
                }
                return $html;
            }
        }
        
        return $html;
    }
}

/**
 * Advanced table filtering — EXCLUDE mode.
 * Removes the specified columns and/or rows (keeps everything else). Use this
 * when it is easier to say which columns/rows to drop than which to keep
 * (e.g. keep 6 of 10 columns by dropping the other 4).
 *
 * Indices are 1-based in the spec (same format as wpws_parse_table_spec):
 *   "4"        → drop the 4th column/row
 *   "4_7_9"    → drop columns/rows 4, 7 and 9
 *   "5-8"      → drop columns/rows 5 through 8
 *
 * @param string $html         HTML containing table(s)
 * @param string $columns_spec Columns to DROP (empty = keep all columns)
 * @param string $rows_spec    Rows to DROP (empty = keep all rows)
 * @return string Modified HTML
 */
if ( !function_exists('wpws_filter_table_exclude') ) {
    function wpws_filter_table_exclude($html, $columns_spec = '', $rows_spec = '') {
        if (empty($columns_spec) && empty($rows_spec)) {
            return $html;
        }
        if (empty($html) || !is_string($html)) {
            return $html;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if (stripos($html, '<html') === false && stripos($html, '<body') === false) {
            $wrapped_html = '<?xml encoding="UTF-8"><body>' . $html . '</body>';
        } else {
            $wrapped_html = '<?xml encoding="UTF-8">' . $html;
        }
        $dom->loadHTML($wrapped_html, LIBXML_NONET);
        libxml_clear_errors();

        $xpath  = new DOMXPath($dom);
        $tables = $xpath->query('//table');
        if ($tables->length === 0) {
            return $html;
        }

        $drop_columns = !empty($columns_spec) ? wpws_parse_table_spec($columns_spec) : array();
        $drop_rows    = !empty($rows_spec)    ? wpws_parse_table_spec($rows_spec)    : array();

        foreach ($tables as $table) {
            $rows = $xpath->query('.//tr', $table);

            // Drop whole rows (reverse order so indices stay valid).
            if (!empty($drop_rows)) {
                for ($i = $rows->length - 1; $i >= 0; $i--) {
                    if (in_array($i, $drop_rows)) {
                        $row = $rows->item($i);
                        if ($row && $row->parentNode) {
                            $row->parentNode->removeChild($row);
                        }
                    }
                }
                $rows = $xpath->query('.//tr', $table);
            }

            // Drop cells at the dropped column positions in every remaining row.
            if (!empty($drop_columns)) {
                foreach ($rows as $row) {
                    $cells = $xpath->query('.//th | .//td', $row);
                    for ($i = $cells->length - 1; $i >= 0; $i--) {
                        if (in_array($i, $drop_columns)) {
                            $cell = $cells->item($i);
                            if ($cell && $cell->parentNode) {
                                $cell->parentNode->removeChild($cell);
                            }
                        }
                    }
                }
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body) {
            $out = '';
            foreach ($body->childNodes as $child) {
                $out .= $dom->saveHTML($child);
            }
            return $out;
        }

        return $html;
    }
}

// Parameterised table callbacks. Usable from a shortcode via the "name:args"
// callback syntax, e.g. callback="wpws_drop_columns:4" or
// callback="wpws_keep_columns:1-3_7". args use the 1-based spec format.
if ( !function_exists('wpws_keep_columns') ) {
    function wpws_keep_columns($html, $cols = '') { return wpws_filter_table_advanced($html, (string) $cols, ''); }
}
if ( !function_exists('wpws_drop_columns') ) {
    function wpws_drop_columns($html, $cols = '') { return wpws_filter_table_exclude($html, (string) $cols, ''); }
}
if ( !function_exists('wpws_keep_rows') ) {
    function wpws_keep_rows($html, $rows = '') { return wpws_filter_table_advanced($html, '', (string) $rows); }
}
if ( !function_exists('wpws_drop_rows') ) {
    function wpws_drop_rows($html, $rows = '') { return wpws_filter_table_exclude($html, '', (string) $rows); }
}

// ============================================
// TEXT FORMATTING FUNCTIONS
// ============================================

/**
 * Bold specific words in HTML content
 * 
 * @param string|array $html HTML content (string or array for callback_raw)
 * @param string|array $words Words to bold (comma-separated string or array)
 * @return string|array Modified HTML
 */
if ( !function_exists('wpws_bold_words') ) {
    function wpws_bold_words($html, $words = '') {
        // Handle array input (from callback_raw)
        if (is_array($html)) {
            $result = array();
            foreach ($html as $item) {
                if (is_string($item) && !empty($item)) {
                    $result[] = wpws_bold_words($item, $words);
                } else {
                    $result[] = $item;
                }
            }
            return $result;
        }
        
        // Handle string input (from callback)
        if (empty($html) || !is_string($html)) {
            return $html;
        }
        
        // If no words specified, return original
        if (empty($words)) {
            return $html;
        }
        
        // Convert words to array if it's a string
        if (is_string($words)) {
            $words = array_map('trim', explode(',', $words));
        }
        
        if (!is_array($words) || empty($words)) {
            return $html;
        }
        
        // Escape words for regex
        $escaped_words = array_map('preg_quote', $words, array_fill(0, count($words), '/'));
        
        // Create regex pattern to match whole words only (case-insensitive)
        // This ensures we don't match partial words (e.g., "Gorica" won't match "Goricanski")
        $pattern = '/\b(' . implode('|', $escaped_words) . ')\b/iu';
        
        // Replace with bold version, preserving original case
        $html = preg_replace_callback($pattern, function($matches) {
            return '<strong>' . $matches[1] . '</strong>';
        }, $html);
        
        return $html;
    }
}

/**
 * Bold specific words - wrapper for common use case
 * Usage: callback="wpws_bold_gorica"
 */
if ( !function_exists('wpws_bold_gorica') ) {
    function wpws_bold_gorica($html) {
        return wpws_bold_words($html, 'Gorica');
    }
}
