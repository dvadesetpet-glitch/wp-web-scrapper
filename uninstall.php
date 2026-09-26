<?php
/**
 * Uninstall WP WS Reborn 2026: remove every trace the plugin stored.
 *
 * Runs only when the plugin is deleted from the Plugins screen (not on
 * deactivation). Covers options, cache entries, AJAX jobs, locks, rate-limit
 * counters, legacy v1 cache rows and scheduled background refreshes — on every
 * site of a multisite network.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function wpws_uninstall_site() {
	global $wpdb;

	delete_option( 'wpws_options' );
	delete_option( 'wpws_last_import' );
	delete_option( 'wpws_db_version' );

	// Transients (cache "wpws_c_", jobs "wpws_job_", atom caches, refresh
	// requests, legacy v1 keys), locks and rate-limit counters.
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\\_transient\\_wpws\\_%'
		    OR option_name LIKE '\\_transient\\_timeout\\_wpws\\_%'
		    OR option_name LIKE 'wpws\\_lock\\_%'
		    OR option_name LIKE 'wpws\\_rl\\_%'"
	);

	wp_unschedule_hook( 'wpws_background_refresh' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( $site_id );
		wpws_uninstall_site();
		restore_current_blog();
	}
} else {
	wpws_uninstall_site();
}

// With a persistent object cache, transients live there rather than in the DB.
if ( function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
	wp_cache_flush_group( 'wpws_rate_limit' );
	wp_cache_flush_group( 'wpws_lock' );
}
