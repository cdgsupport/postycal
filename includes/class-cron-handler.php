<?php
/**
 * Cron Handler Class
 *
 * Handles scheduled tasks and post lifecycle transitions.
 *
 * Transition rules:
 *   - Draft/pending posts in the upcoming term whose go-live date has passed
 *     are published and moved to the active term.
 *   - Published posts in the active term whose expiration date has passed
 *     are set to private and moved to the past term.
 *
 * On manual post save, the correct term is set immediately based on the
 * current date vs the stored dates. Post status changes are deferred to
 * the cron so editors retain control during the current editing session.
 *
 * @package PostyCal
 * @since 2.0.0
 */

declare(strict_types=1);

namespace PostyCal;

/**
 * Handles cron operations and post lifecycle for PostyCal.
 */
class Cron_Handler {

    /**
     * Schedule manager instance.
     *
     * @var Schedule_Manager
     */
    private Schedule_Manager $schedule_manager;

    /**
     * Guard flag to prevent re-entrant save_post processing when
     * wp_update_post is called from within a save_post hook.
     *
     * @var bool
     */
    private bool $is_updating = false;

    /**
     * Constructor.
     *
     * @param Schedule_Manager $schedule_manager The schedule manager.
     */
    public function __construct( Schedule_Manager $schedule_manager ) {
        $this->schedule_manager = $schedule_manager;
    }

    // -------------------------------------------------------------------------
    // Cron entry point
    // -------------------------------------------------------------------------

    /**
     * Process all schedules (called by the daily cron event).
     *
     * @return void
     */
    public function process_all_schedules(): void {
        $schedules = $this->schedule_manager->get_all();

        if ( empty( $schedules ) ) {
            Logger::debug( 'No schedules to process' );
            return;
        }

        Logger::info( 'Starting scheduled check', [ 'schedule_count' => count( $schedules ) ] );

        foreach ( $schedules as $schedule ) {
            $this->process_schedule( $schedule );
        }

        Logger::info( 'Completed scheduled check' );
    }

    /**
     * Process a single schedule: check go-live and expiration transitions.
     *
     * @param Schedule $schedule The schedule to process.
     * @return void
     */
    private function process_schedule( Schedule $schedule ): void {
        if ( ! $schedule->taxonomy_exists() ) {
            Logger::warning(
                'Skipping schedule with invalid taxonomy',
                [ 'schedule' => $schedule->name, 'taxonomy' => $schedule->taxonomy ]
            );
            return;
        }

        $published = $this->process_go_live_transitions( $schedule );
        $expired   = $this->process_expiry_transitions( $schedule );

        if ( $published > 0 || $expired > 0 ) {
            Logger::info(
                'Processed schedule transitions',
                [ 'schedule' => $schedule->name, 'published' => $published, 'expired' => $expired ]
            );
        }
    }

    /**
     * Publish draft posts whose go-live date has passed.
     *
     * Also handles the edge case where both dates have already passed
     * (goes directly to the expired state).
     *
     * @param Schedule $schedule The schedule.
     * @return int Number of posts transitioned.
     */
    private function process_go_live_transitions( Schedule $schedule ): int {
        $posts = $this->get_posts_by_terms(
            $schedule,
            [ $schedule->upcoming_term, $schedule->active_term ],
            [ 'draft', 'pending' ]
        );

        $count = 0;

        foreach ( $posts as $post ) {
            $go_live = Date_Handler::get_go_live_date( $post->ID, $schedule );

            if ( null === $go_live ) {
                continue;
            }

            if ( ! Date_Handler::is_date_past( $go_live, null, $schedule->use_time ) ) {
                continue;
            }

            $expiration = Date_Handler::get_expiration_date( $post->ID, $schedule );

            if ( null !== $expiration && Date_Handler::is_date_past( $expiration, null, $schedule->use_time ) ) {
                // Both dates passed — expire directly without going through active.
                $this->apply_expiry( $post->ID, $schedule );
            } else {
                $this->apply_go_live( $post->ID, $schedule );
            }

            ++$count;
        }

        return $count;
    }

    /**
     * Expire published posts whose expiration date has passed.
     *
     * @param Schedule $schedule The schedule.
     * @return int Number of posts transitioned.
     */
    private function process_expiry_transitions( Schedule $schedule ): int {
        $posts = $this->get_posts_by_terms(
            $schedule,
            [ $schedule->active_term ],
            [ 'publish' ]
        );

        $count = 0;

        foreach ( $posts as $post ) {
            $expiration = Date_Handler::get_expiration_date( $post->ID, $schedule );

            if ( null === $expiration ) {
                continue;
            }

            if ( ! Date_Handler::is_date_past( $expiration, null, $schedule->use_time ) ) {
                continue;
            }

            $this->apply_expiry( $post->ID, $schedule );
            ++$count;
        }

        return $count;
    }

    // -------------------------------------------------------------------------
    // Save-post entry point
    // -------------------------------------------------------------------------

    /**
     * Assign the correct taxonomy term when a post is saved.
     *
     * Called by save_post at priority 20, after meta box data has been
     * saved at priority 10. Only sets the term — post status changes
     * are handled by the cron so editors aren't surprised by immediate
     * publish/private transitions while they are actively editing.
     *
     * @param int $post_id The post ID.
     * @return void
     */
    public function assign_term_on_save( int $post_id ): void {
        // Prevent re-entrant calls triggered by wp_update_post inside this class.
        if ( $this->is_updating ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        $post_type = get_post_type( $post_id );

        if ( false === $post_type ) {
            return;
        }

        $schedules = $this->schedule_manager->get_for_post_type( $post_type );

        if ( empty( $schedules ) ) {
            return;
        }

        foreach ( $schedules as $schedule ) {
            $this->set_term_by_dates( $post_id, $schedule );
        }
    }

    // -------------------------------------------------------------------------
    // Manual trigger
    // -------------------------------------------------------------------------

    /**
     * Run all schedule transitions immediately (admin manual trigger).
     *
     * @return array<string, array{published: int, expired: int}> Results keyed by schedule name.
     */
    public function trigger_manual_run(): array {
        $results   = [];
        $schedules = $this->schedule_manager->get_all();

        Logger::info( 'Manual trigger initiated', [ 'schedule_count' => count( $schedules ) ] );

        foreach ( $schedules as $schedule ) {
            $results[ $schedule->name ] = [
                'published' => $this->process_go_live_transitions( $schedule ),
                'expired'   => $this->process_expiry_transitions( $schedule ),
            ];
        }

        Logger::info( 'Manual trigger completed', [ 'results' => $results ] );

        return $results;
    }

    // -------------------------------------------------------------------------
    // Core transition helpers
    // -------------------------------------------------------------------------

    /**
     * Publish a post and assign the active term.
     *
     * @param int      $post_id  The post ID.
     * @param Schedule $schedule The schedule.
     * @return void
     */
    private function apply_go_live( int $post_id, Schedule $schedule ): void {
        $this->set_post_term( $post_id, $schedule, $schedule->active_term );
        $this->update_post_status( $post_id, 'publish' );

        Logger::info( 'Post published (go-live)', [ 'post_id' => $post_id, 'schedule' => $schedule->name ] );
    }

    /**
     * Set a post to private and assign the past term.
     *
     * @param int      $post_id  The post ID.
     * @param Schedule $schedule The schedule.
     * @return void
     */
    private function apply_expiry( int $post_id, Schedule $schedule ): void {
        $this->set_post_term( $post_id, $schedule, $schedule->past_term );
        $this->update_post_status( $post_id, 'private' );

        Logger::info( 'Post expired', [ 'post_id' => $post_id, 'schedule' => $schedule->name ] );
    }

    /**
     * Determine the correct term for a post based on its dates and set it.
     *
     * Does not change post_status — that is the cron's responsibility.
     *
     * @param int      $post_id  The post ID.
     * @param Schedule $schedule The schedule.
     * @return void
     */
    private function set_term_by_dates( int $post_id, Schedule $schedule ): void {
        if ( ! $schedule->taxonomy_exists() ) {
            return;
        }

        $go_live    = Date_Handler::get_go_live_date( $post_id, $schedule );
        $expiration = Date_Handler::get_expiration_date( $post_id, $schedule );

        if ( null === $go_live || null === $expiration ) {
            Logger::debug(
                'Skipping term assignment — dates not set',
                [ 'post_id' => $post_id, 'schedule' => $schedule->name ]
            );
            return;
        }

        $is_go_live_passed  = Date_Handler::is_date_past( $go_live, null, $schedule->use_time );
        $is_expiry_passed   = Date_Handler::is_date_past( $expiration, null, $schedule->use_time );

        if ( $is_expiry_passed ) {
            $term = $schedule->past_term;
        } elseif ( $is_go_live_passed ) {
            $term = $schedule->active_term;
        } else {
            $term = $schedule->upcoming_term;
        }

        Logger::debug(
            'Setting term on save',
            [
                'post_id'  => $post_id,
                'schedule' => $schedule->name,
                'term'     => $term,
            ]
        );

        $this->set_post_term( $post_id, $schedule, $term );
    }

    // -------------------------------------------------------------------------
    // Low-level helpers
    // -------------------------------------------------------------------------

    /**
     * Query posts matching specific taxonomy terms and post statuses.
     *
     * @param Schedule $schedule The schedule.
     * @param string[] $terms    Term slugs to match (IN operator).
     * @param string[] $statuses Post statuses to match.
     * @return \WP_Post[]
     */
    private function get_posts_by_terms( Schedule $schedule, array $terms, array $statuses ): array {
        $args = [
            'post_type'      => $schedule->post_type,
            'post_status'    => $statuses,
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'tax_query'      => [
                [
                    'taxonomy' => $schedule->taxonomy,
                    'field'    => 'slug',
                    'terms'    => $terms,
                    'operator' => 'IN',
                ],
            ],
            'fields'         => 'all',
        ];

        return ( new \WP_Query( $args ) )->posts;
    }

    /**
     * Set a single taxonomy term on a post, replacing any existing terms.
     *
     * @param int      $post_id  The post ID.
     * @param Schedule $schedule The schedule.
     * @param string   $term     Term slug to assign.
     * @return bool True on success.
     */
    private function set_post_term( int $post_id, Schedule $schedule, string $term ): bool {
        $term_obj = get_term_by( 'slug', $term, $schedule->taxonomy );

        if ( false === $term_obj ) {
            Logger::error(
                'Cannot assign term — term does not exist',
                [ 'post_id' => $post_id, 'term' => $term, 'taxonomy' => $schedule->taxonomy ]
            );
            return false;
        }

        $result = wp_set_object_terms( $post_id, $term, $schedule->taxonomy, false );

        if ( is_wp_error( $result ) ) {
            Logger::error(
                'wp_set_object_terms failed',
                [ 'post_id' => $post_id, 'term' => $term, 'error' => $result->get_error_message() ]
            );
            return false;
        }

        return true;
    }

    /**
     * Update post status without triggering re-entrant processing.
     *
     * @param int    $post_id The post ID.
     * @param string $status  Target post status ('publish' or 'private').
     * @return void
     */
    private function update_post_status( int $post_id, string $status ): void {
        if ( get_post_status( $post_id ) === $status ) {
            return;
        }

        $this->is_updating = true;
        wp_update_post( [ 'ID' => $post_id, 'post_status' => $status ] );
        $this->is_updating = false;
    }
}
