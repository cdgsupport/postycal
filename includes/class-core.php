<?php
/**
 * Core Plugin Class
 *
 * Orchestrates all plugin functionality and manages dependencies.
 *
 * @package PostyCal
 * @since 2.0.0
 */

declare(strict_types=1);

namespace PostyCal;

/**
 * Core class for PostyCal plugin.
 */
final class Core {

    /**
     * Singleton instance.
     *
     * @var Core|null
     */
    private static ?Core $instance = null;

    /**
     * Schedule manager instance.
     *
     * @var Schedule_Manager
     */
    private Schedule_Manager $schedule_manager;

    /**
     * Cron handler instance.
     *
     * @var Cron_Handler
     */
    private Cron_Handler $cron_handler;

    /**
     * Admin instance (only in admin context).
     *
     * @var Admin|null
     */
    private ?Admin $admin = null;

    /**
     * Get singleton instance.
     *
     * @return Core
     */
    public static function get_instance(): Core {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton pattern.
     */
    private function __construct() {
        $this->init_components();
        $this->setup_hooks();
    }

    /**
     * Initialize plugin components.
     *
     * @return void
     */
    private function init_components(): void {
        $this->schedule_manager = new Schedule_Manager();
        $this->cron_handler     = new Cron_Handler( $this->schedule_manager );

        if ( is_admin() ) {
            $this->admin = new Admin( $this->schedule_manager );
        }
    }

    /**
     * Setup WordPress hooks.
     *
     * Meta box data is saved at priority 10 so that dates are written to
     * post meta before the term-assignment hook reads them at priority 20.
     *
     * @return void
     */
    private function setup_hooks(): void {
        // Daily cron.
        add_action( 'pc_daily_category_check', [ $this->cron_handler, 'process_all_schedules' ] );

        // Term assignment on every post save (after meta box save at priority 10).
        add_action( 'save_post', [ $this->cron_handler, 'assign_term_on_save' ], 20, 1 );
    }

    /**
     * Get schedule manager instance.
     *
     * @return Schedule_Manager
     */
    public function get_schedule_manager(): Schedule_Manager {
        return $this->schedule_manager;
    }

    /**
     * Get cron handler instance.
     *
     * @return Cron_Handler
     */
    public function get_cron_handler(): Cron_Handler {
        return $this->cron_handler;
    }

    /**
     * Prevent cloning.
     *
     * @return void
     */
    private function __clone() {}

    /**
     * Prevent unserialization.
     *
     * @throws \Exception When attempting to unserialize.
     * @return void
     */
    public function __wakeup(): void {
        throw new \Exception( 'Cannot unserialize singleton.' );
    }
}
