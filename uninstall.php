<?php
/**
 * Uninstall Baton — remove workflows, definition meta, and saved-value rows.
 *
 * @package Baton
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$baton_workflow_post_ids = get_posts(
	array(
		'post_type'      => 'baton_workflow',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

if ( is_array( $baton_workflow_post_ids ) ) {
	foreach ( $baton_workflow_post_ids as $baton_workflow_post_id ) {
		wp_delete_post( (int) $baton_workflow_post_id, true );
	}
}

/**
 * Drop the saved-values table (rows go with it; the workflow posts above are
 * already deleted).
 */
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall-time DDL; identifier built from $wpdb->prefix (plugin classes are not loaded here).
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'baton_saved_values' );
