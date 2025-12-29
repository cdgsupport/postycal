<?php
/**
 * Cron Handler Class
 *
 * Handles scheduled tasks and category transition logic.
 *
 * @package PostyCal
 * @since 2.0.0
 */

declare(strict_types=1);

namespace PostyCal;

/**
 * Handles cron operations for PostyCal.
 */
class Cron_Handler {

    /**
     * Schedule manager instance.
     *
     * @var Schedule_Manager
     */
    private Schedule_Manager $schedule_manager;

    /**
     * Constructor.
     *
     * @param Schedule_Manager $schedule_manager The schedule manager.
     */
    public function __construct( Schedule_Manager $schedule_manager ) {
        $this->schedule_manager = $schedule_manager;
    }

    /**
     * Process all schedules (called by cron).
     *
     * @return void
     */
    public function process_all_schedules(): void {
        $schedules = $this->schedule_manager->get_all();

        if ( empty( $schedules ) ) {
            Logger::debug( 'No schedules to process' );
            return;
        }

        Logger::info( 'Starting scheduled category check', [ 'schedule_count' => count( $schedules ) ] );

        foreach ( $schedules as $index => $schedule ) {
            $this->process_schedule( $schedule, $index );
        }

        Logger::info( 'Completed scheduled category check' );
    }

    /**
     * Process a single schedule.
     *
     * @param Schedule $schedule The schedule to process.
     * @param int      $index    The schedule index.
     * @return void
     */
    private function process_schedule( Schedule $schedule, int $index ): void {
        if ( ! $schedule->taxonomy_exists() ) {
            Logger::warning(
                'Skipping schedule with invalid taxonomy',
                [
                    'schedule' => $schedule->name,
                    'taxonomy' => $schedule->taxonomy,
                ]
            );
            return;
        }

        $posts = $this->get_upcoming_posts( $schedule );

        if ( empty( $posts ) ) {
            Logger::debug( 'No upcoming posts to check', [ 'schedule' => $schedule->name ] );
            return;
        }

        $transitioned = 0;

        foreach ( $posts as $post ) {
            if ( $this->maybe_transition_post( $post->ID, $schedule ) ) {
                ++$transitioned;
            }
        }

        if ( $transitioned > 0 ) {
            Logger::info(
                'Transitioned posts',
                [
                    'schedule' => $schedule->name,
                    'count'    => $transitioned,
                ]
            );
        }
    }

    /**
     * Get posts in the upcoming category for a schedule.
     *
     * @param Schedule $schedule The schedule.
     * @return array<\WP_Post> Array of posts.
     */
    private function get_upcoming_posts( Schedule $schedule ): array {
        $args = [
            'post_type'      => $schedule->post_type,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'tax_query'      => [
                [
                    'taxonomy' => $schedule->taxonomy,
                    'field'    => 'slug',
                    'terms'    => $schedule->upcoming_term,
                ],
            ],
            'fields'         => 'all',
        ];

        $query = new \WP_Query( $args );

        return $query->posts;
    }

    /**
     * Check and possibly transition a single post.
     *
     * @param int      $post_id  The post ID.
     * @param Schedule $schedule The schedule.
     * @return bool True if post was transitioned.
     */
    private function maybe_transition_post( int $post_id, Schedule $schedule ): bool {
        $date = Date_Handler::get_post_date( $post_id, $schedule );

        if ( null === $date ) {
            Logger::debug( 'No date found for post', [ 'post_id' => $post_id ] );
            return false;
        }

        if ( ! Date_Handler::should_transition( $date, null, $schedule->use_time ) ) {
            return false;
        }

        return $this->transition_post_to_past( $post_id, $schedule );
    }

    /**
     * Transition a post to the past category.
     *
     * @param int      $post_id  The post ID.
     * @param Schedule $schedule The schedule.
     * @return bool True on success.
     */
    private function transition_post_to_past( int $post_id, Schedule $schedule ): bool {
        return $this->set_post_term( $post_id, $schedule, $schedule->past_term );
    }

    /**
     * Set initial category when post is saved.
     *
     * Called by acf/save_post and save_post hooks. The post_id can be:
     * - int: Regular post ID
     * - string 'new_post': New post being created (ACF)
     * - string 'options': Options page (ACF)
     * - string '{taxonomy}_{term_id}': Term being edited (ACF)
     * - string 'user_{user_id}': User being edited (ACF)
     *
     * @param int|string $post_id The post ID (can be string for special ACF cases).
     * @param \WP_Post|null $post The post object (only from save_post hook).
     * @param bool|null $update Whether this is an update (only from save_post hook).
     * @return void
     */
    public function set_initial_category( int|string $post_id, ?\WP_Post $post = null, ?bool $update = null ): void {
        // Skip non-post contexts (options pages, terms, users, new posts).
        if ( ! is_numeric( $post_id ) ) {
            Logger::debug( 'Skipping non-post context', [ 'post_id' => $post_id ] );
            return;
        }

        $post_id = (int) $post_id;

        // Skip autosaves.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            Logger::debug( 'Skipping autosave', [ 'post_id' => $post_id ] );
            return;
        }

        // Skip revisions.
        if ( wp_is_post_revision( $post_id ) ) {
            Logger::debug( 'Skipping revision', [ 'post_id' => $post_id ] );
            return;
        }

        $post_type = get_post_type( $post_id );

        if ( false === $post_type ) {
            Logger::debug( 'Could not determine post type', [ 'post_id' => $post_id ] );
            return;
        }

        $schedules = $this->schedule_manager->get_for_post_type( $post_type );

        if ( empty( $schedules ) ) {
            // Don't log this - it's expected for most post types.
            return;
        }

        Logger::debug(
            'Processing post save',
            [
                'post_id'        => $post_id,
                'post_type'      => $post_type,
                'schedule_count' => count( $schedules ),
            ]
        );

        foreach ( $schedules as $schedule ) {
            $this->assign_category_by_date( $post_id, $schedule );
        }
    }

    /**
     * Assign category based on date.
     *
     * @param int      $post_id  The post ID.
     * @param Schedule $schedule The schedule.
     * @return void
     */
    private function assign_category_by_date( int $post_id, Schedule $schedule ): void {
        if ( ! $schedule->taxonomy_exists() ) {
            Logger::warning(
                'Cannot assign category - taxonomy does not exist',
                [
                    'post_id'  => $post_id,
                    'taxonomy' => $schedule->taxonomy,
                ]
            );
            return;
        }

        // Log what we're looking for.
        Logger::debug(
            'Looking for date field',
            [
                'post_id'    => $post_id,
                'field_name' => $schedule->date_field,
                'field_type' => $schedule->field_type,
            ]
        );

        $date = Date_Handler::get_post_date( $post_id, $schedule );

        if ( null === $date ) {
            Logger::info(
                'No date found during save - skipping category assignment',
                [
                    'post_id'    => $post_id,
                    'schedule'   => $schedule->name,
                    'field_name' => $schedule->date_field,
                ]
            );
            return;
        }

        $is_past = Date_Handler::is_date_past( $date, null, $schedule->use_time );
        $term    = $is_past ? $schedule->past_term : $schedule->upcoming_term;

        Logger::info(
            'Assigning category based on date',
            [
                'post_id'   => $post_id,
                'schedule'  => $schedule->name,
                'date'      => $date->format( 'Y-m-d H:i:s' ),
                'is_past'   => $is_past,
                'term'      => $term,
                'use_time'  => $schedule->use_time,
            ]
        );

        $this->set_post_term( $post_id, $schedule, $term );
    }

    /**
     * Set a term on a post, replacing existing terms.
     *
     * Uses a single wp_set_object_terms call to avoid race conditions.
     *
     * @param int      $post_id  The post ID.
     * @param Schedule $schedule The schedule.
     * @param string   $term     The term slug to set.
     * @return bool True on success.
     */
    private function set_post_term( int $post_id, Schedule $schedule, string $term ): bool {
        // Validate term exists.
        $term_obj = get_term_by( 'slug', $term, $schedule->taxonomy );

        if ( false === $term_obj ) {
            Logger::error(
                'Term does not exist',
                [
                    'post_id'  => $post_id,
                    'term'     => $term,
                    'taxonomy' => $schedule->taxonomy,
                ]
            );
            return false;
        }

        // Set term (replace mode - append = false).
        $result = wp_set_object_terms( $post_id, $term, $schedule->taxonomy, false );

        if ( is_wp_error( $result ) ) {
            Logger::error(
                'Failed to set post term',
                [
                    'post_id' => $post_id,
                    'term'    => $term,
                    'error'   => $result->get_error_message(),
                ]
            );
            return false;
        }

        Logger::debug(
            'Set post term',
            [
                'post_id' => $post_id,
                'term'    => $term,
            ]
        );

        return true;
    }

    /**
     * Manually trigger processing for all schedules.
     *
     * @return array<string, int> Results with schedule names and transition counts.
     */
    public function trigger_manual_run(): array {
        $results   = [];
        $schedules = $this->schedule_manager->get_all();

        Logger::info( 'Manual trigger initiated', [ 'schedule_count' => count( $schedules ) ] );

        foreach ( $schedules as $schedule ) {
            $posts       = $this->get_upcoming_posts( $schedule );
            $transitioned = 0;

            foreach ( $posts as $post ) {
                if ( $this->maybe_transition_post( $post->ID, $schedule ) ) {
                    ++$transitioned;
                }
            }

            $results[ $schedule->name ] = $transitioned;
        }

        Logger::info( 'Manual trigger completed', [ 'results' => $results ] );

        return $results;
    }
}
