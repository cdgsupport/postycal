<?php
/**
 * PostyCal Uninstall
 *
 * Removes all plugin data when the plugin is deleted through WordPress.
 *
 * @package PostyCal
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Remove all PostyCal post meta (go-live and expiration date fields).
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_postycal_%'" );

// Remove plugin options.
delete_option( 'pc_schedules' );
delete_option( 'pc_post_types' );
delete_option( 'pc_taxonomies' );

// Clear the rewrite-flush transient.
delete_transient( 'postycal_flush_rewrites' );

// Clear the scheduled cron event.
wp_clear_scheduled_hook( 'pc_daily_category_check' );
