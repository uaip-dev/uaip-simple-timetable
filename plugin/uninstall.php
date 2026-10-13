<?php
/**
 * Uninstall: removes the timetable's events, locations, instructors, categories and settings,
 * but only when the site owner turned on "Delete data on uninstall". Otherwise only the
 * cache is cleared.
 *
 * @package UaipSimpleTimetable
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Removes this plugin's data for the current site.
 */
function uaip_tt_uninstall_site() {
	global $wpdb;

	$salt = get_option( 'uaip_tt_cache_salt' );
	if ( $salt ) {
		delete_transient( 'uaip_tt_snapshot_' . $salt );
	}
	delete_option( 'uaip_tt_cache_salt' );

	$settings = get_option( 'uaip_tt_settings', array() );
	if ( ! is_array( $settings ) || empty( $settings['delete_data'] ) ) {
		return;
	}

	foreach ( array( 'uaip_tt_event', 'uaip_tt_location', 'uaip_tt_instructor' ) as $post_type ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup; the post type is no longer registered.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $post_type ) );
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the taxonomy is no longer registered, so get_terms() cannot list it.
	$term_ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'uaip_tt_category' ) );
	foreach ( $term_ids as $term_id ) {
		delete_term_meta( (int) $term_id, 'uaip_tt_color' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			$wpdb->term_taxonomy,
			array(
				'term_id'  => (int) $term_id,
				'taxonomy' => 'uaip_tt_category',
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d", (int) $term_id ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $wpdb->terms, array( 'term_id' => (int) $term_id ) );
		}
	}

	delete_option( 'uaip_tt_settings' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $uaip_tt_site_id ) {
		switch_to_blog( $uaip_tt_site_id );
		uaip_tt_uninstall_site();
		restore_current_blog();
	}
} else {
	uaip_tt_uninstall_site();
}
