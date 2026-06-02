<?php
/**
 * PostyCal Uninstall
 *
 * Removes all plugin data when the plugin is deleted through WordPress.
 * Post meta created by PostyCal uses the prefix _postycal_ so we can
 * bulk-delete it with a single query.
 *
 * @package PostyCal
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Remove all PostyCal post meta (go-live and expiration date fields).
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_postycal_%'"
);

// Remove plugin options.
delete_option( 'pc_schedules' );

// Clear any scheduled cron events.
wp_clear_scheduled_hook( 'pc_daily_category_check' );
