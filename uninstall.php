<?php
/**
 * PostyCal Uninstall
 *
 * Removes all plugin data when the plugin is deleted.
 *
 * @package PostyCal
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove plugin options.
delete_option( 'pc_schedules' );

// Clear any scheduled cron events.
wp_clear_scheduled_hook( 'pc_daily_category_check' );
