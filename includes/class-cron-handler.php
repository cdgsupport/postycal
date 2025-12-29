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
     * @param int $post_id The post ID.
     * @return void
     */
    public function set_initial_category( int $post_id ): void {
        $post_type = get_post_type( $post_id );

        if ( false === $post_type ) {
            return;
        }

        $schedules = $this->schedule_manager->get_for_post_type( $post_type );

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

        $date = Date_Handler::get_post_date( $post_id, $schedule );

        if ( null === $date ) {
            Logger::debug(
                'No date found during save',
                [
                    'post_id'  => $post_id,
                    'schedule' => $schedule->name,
                ]
            );
            return;
        }

        $is_past = Date_Handler::is_date_past( $date, null, $schedule->use_time );
        $term    = $is_past ? $schedule->past_term : $schedule->upcoming_term;

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
