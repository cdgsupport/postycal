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
     * @var Post_Type_Manager
     */
    private Post_Type_Manager $post_type_manager;

    /**
     * @var Taxonomy_Manager
     */
    private Taxonomy_Manager $taxonomy_manager;

    /**
     * @var Schedule_Manager
     */
    private Schedule_Manager $schedule_manager;

    /**
     * @var Cron_Handler
     */
    private Cron_Handler $cron_handler;

    /**
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
     * Private constructor.
     */
    private function __construct() {
        $this->init_components();
        $this->setup_hooks();
    }

    /**
     * Initialize all plugin components.
     *
     * @return void
     */
    private function init_components(): void {
        $this->post_type_manager = new Post_Type_Manager();
        $this->taxonomy_manager  = new Taxonomy_Manager();
        $this->schedule_manager  = new Schedule_Manager();
        $this->cron_handler      = new Cron_Handler( $this->schedule_manager );

        if ( is_admin() ) {
            $this->admin = new Admin(
                $this->schedule_manager,
                $this->post_type_manager,
                $this->taxonomy_manager
            );
        }
    }

    /**
     * Register all WordPress hooks.
     *
     * Registration order:
     *   init priority  5 — PostyCal CPTs (must exist before taxonomies)
     *   init priority  6 — PostyCal taxonomies (attached to CPTs)
     *   init priority 99 — flush rewrite rules if a CPT/taxonomy changed
     *
     * save_post priority 10 — Admin saves meta box date fields
     * save_post priority 20 — Cron_Handler assigns the correct term
     *
     * @return void
     */
    private function setup_hooks(): void {
        add_action( 'init', [ $this->post_type_manager, 'register_all' ], 5 );
        add_action( 'init', [ $this->taxonomy_manager, 'register_all' ], 6 );
        add_action( 'init', [ $this, 'maybe_flush_rewrites' ], 99 );

        add_action( POSTYCAL_CRON_HOOK, [ $this->cron_handler, 'process_all_schedules' ] );
        add_action( 'save_post', [ $this->cron_handler, 'assign_term_on_save' ], 20, 1 );

        add_action( 'admin_init', [ $this, 'ensure_cron' ] );
    }

    /**
     * Repair the recurring event if it has gone missing or drifted to the
     * wrong recurrence (e.g. after a database restore, or a migration from a
     * version that always scheduled it daily).
     *
     * @return void
     */
    public function ensure_cron(): void {
        $this->schedule_manager->sync_cron();
    }

    /**
     * Flush rewrite rules once after a CPT or taxonomy change.
     *
     * The managers set a short-lived transient when they save. This hook
     * checks for it on the next init (which may be the same request for
     * AJAX or the next page load) and flushes exactly once.
     *
     * @return void
     */
    public function maybe_flush_rewrites(): void {
        if ( get_transient( 'postycal_flush_rewrites' ) ) {
            delete_transient( 'postycal_flush_rewrites' );
            flush_rewrite_rules();
            Logger::debug( 'Flushed rewrite rules after PostyCal structure change' );
        }
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /**
     * @return Post_Type_Manager
     */
    public function get_post_type_manager(): Post_Type_Manager {
        return $this->post_type_manager;
    }

    /**
     * @return Taxonomy_Manager
     */
    public function get_taxonomy_manager(): Taxonomy_Manager {
        return $this->taxonomy_manager;
    }

    /**
     * @return Schedule_Manager
     */
    public function get_schedule_manager(): Schedule_Manager {
        return $this->schedule_manager;
    }

    /**
     * @return Cron_Handler
     */
    public function get_cron_handler(): Cron_Handler {
        return $this->cron_handler;
    }

    private function __clone() {}

    /**
     * @throws \Exception
     */
    public function __wakeup(): void {
        throw new \Exception( 'Cannot unserialize singleton.' );
    }
}
