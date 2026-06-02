<?php
/**
 * Plugin Name: PostyCal
 * Plugin URI: https://crawforddesigngroup.com/postycal
 * Description: Automatically manages post category transitions based on date fields
 * Version: 2.0.5
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Author: Crawford Design Group
 * Author URI: https://crawforddesigngroup.com/
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: postycal
 * Domain Path: /languages
 *
 * @package PostyCal
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'POSTYCAL_VERSION', '2.0.5' );
define( 'POSTYCAL_PLUGIN_FILE', __FILE__ );
define( 'POSTYCAL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'POSTYCAL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'POSTYCAL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Buffer period in seconds (1 day) - configurable constant.
define( 'POSTYCAL_TRANSITION_BUFFER', DAY_IN_SECONDS );

/**
 * Autoloader for PostyCal classes.
 *
 * @param string $class_name The class name to load.
 * @return void
 */
spl_autoload_register( function ( string $class_name ): void {
    $prefix = 'PostyCal\\';

    if ( strpos( $class_name, $prefix ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class_name, strlen( $prefix ) );
    $file_name      = 'class-' . str_replace( [ '\\', '_' ], [ '/', '-' ], strtolower( $relative_class ) ) . '.php';
    $file_path      = POSTYCAL_PLUGIN_DIR . 'includes/' . $file_name;

    if ( file_exists( $file_path ) ) {
        require_once $file_path;
    }
} );

/**
 * Return the Unix timestamp for the next midnight in the site's configured timezone.
 *
 * strtotime('tomorrow midnight') uses the PHP/server timezone, which may differ
 * from the WordPress timezone setting. Using DateTimeImmutable with the WP
 * timezone ensures the cron fires at midnight local time.
 *
 * @return int Unix timestamp.
 */
function postycal_next_midnight(): int {
    $timezone = PostyCal\Date_Handler::get_timezone();
    $midnight  = new \DateTimeImmutable( 'tomorrow midnight', $timezone );
    return $midnight->getTimestamp();
}

/**
 * Initialize the plugin.
 *
 * @return void
 */
function postycal_init(): void {
    // Load text domain.
    load_plugin_textdomain(
        'postycal',
        false,
        dirname( POSTYCAL_PLUGIN_BASENAME ) . '/languages/'
    );

    // Initialize core plugin.
    PostyCal\Core::get_instance();
}
add_action( 'plugins_loaded', 'postycal_init' );

/**
 * Plugin activation hook.
 *
 * @return void
 */
function postycal_activate(): void {
    // Ensure options exist with defaults.
    if ( get_option( 'pc_schedules' ) === false ) {
        add_option( 'pc_schedules', [] );
    }
    if ( get_option( 'pc_post_types' ) === false ) {
        add_option( 'pc_post_types', [] );
    }
    if ( get_option( 'pc_taxonomies' ) === false ) {
        add_option( 'pc_taxonomies', [] );
    }

    // Register any already-saved CPTs and taxonomies so rewrite rules include them.
    $post_type_manager = new PostyCal\Post_Type_Manager();
    $taxonomy_manager  = new PostyCal\Taxonomy_Manager();
    $post_type_manager->register_all();
    $taxonomy_manager->register_all();

    // Schedule cron if we have schedules.
    $schedules = get_option( 'pc_schedules', [] );
    if ( ! empty( $schedules ) && ! wp_next_scheduled( 'pc_daily_category_check' ) ) {
        wp_schedule_event( postycal_next_midnight(), 'daily', 'pc_daily_category_check' );
    }

    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'postycal_activate' );

/**
 * Plugin deactivation hook.
 *
 * @return void
 */
function postycal_deactivate(): void {
    wp_clear_scheduled_hook( 'pc_daily_category_check' );
}
register_deactivation_hook( __FILE__, 'postycal_deactivate' );

/**
 * Plugin uninstall hook.
 *
 * This is registered via uninstall.php for better security.
 *
 * @see uninstall.php
 */
