<?php
/**
 * PostyCal Uninstall
 *
 * Removes all plugin data when the plugin is deleted through WordPress.
 *
 * Post types, taxonomies and their terms are intentionally left in place —
 * they hold user content, and the posts created under them would become
 * unreachable if the terms were removed.
 *
 * @package PostyCal
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Remove PostyCal data for the current site.
 *
 * @return void
 */
function postycal_uninstall_site(): void {
    global $wpdb;

    // Remove all PostyCal post meta (go-live and expiration date fields).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
            $wpdb->esc_like( '_postycal_' ) . '%'
        )
    );

    delete_option( 'pc_schedules' );
    delete_option( 'pc_post_types' );
    delete_option( 'pc_taxonomies' );

    delete_transient( 'postycal_flush_rewrites' );

    wp_clear_scheduled_hook( 'pc_daily_category_check' );
}

if ( is_multisite() ) {
    $postycal_site_ids = get_sites(
        [
            'fields' => 'ids',
            'number' => 0,
        ]
    );

    foreach ( $postycal_site_ids as $postycal_site_id ) {
        switch_to_blog( (int) $postycal_site_id );
        postycal_uninstall_site();
        restore_current_blog();
    }

    unset( $postycal_site_ids, $postycal_site_id );
} else {
    postycal_uninstall_site();
}
