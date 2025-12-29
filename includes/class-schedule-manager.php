<?php
/**
 * Schedule Manager Class
 *
 * Handles CRUD operations for PostyCal schedules.
 *
 * @package PostyCal
 * @since 2.0.0
 */

declare(strict_types=1);

namespace PostyCal;

/**
 * Manages PostyCal schedules.
 */
class Schedule_Manager {

    /**
     * Option name for storing schedules.
     *
     * @var string
     */
    private const OPTION_NAME = 'pc_schedules';

    /**
     * Cached schedules.
     *
     * @var array<int, Schedule>|null
     */
    private ?array $schedules = null;

    /**
     * Get all schedules.
     *
     * @return array<int, Schedule>
     */
    public function get_all(): array {
        if ( null === $this->schedules ) {
            $this->load_schedules();
        }
        return $this->schedules ?? [];
    }

    /**
     * Get a schedule by index.
     *
     * @param int $index The schedule index.
     * @return Schedule|null
     */
    public function get( int $index ): ?Schedule {
        $schedules = $this->get_all();
        return $schedules[ $index ] ?? null;
    }

    /**
     * Get schedules for a specific post type.
     *
     * @param string $post_type The post type.
     * @return array<int, Schedule>
     */
    public function get_for_post_type( string $post_type ): array {
        return array_filter(
            $this->get_all(),
            fn( Schedule $schedule ): bool => $schedule->matches_post_type( $post_type )
        );
    }

    /**
     * Add a new schedule.
     *
     * @param array<string, mixed> $data Schedule data.
     * @return bool|int False on failure, new index on success.
     */
    public function add( array $data ): bool|int {
        $schedule = new Schedule( $data );

        if ( ! $schedule->is_valid() ) {
            Logger::error( 'Attempted to add invalid schedule', [ 'data' => $data ] );
            return false;
        }

        $validation = $this->validate_schedule_references( $schedule );
        if ( ! empty( $validation ) ) {
            Logger::warning( 'Schedule references invalid entities', $validation );
        }

        $schedules   = $this->get_all();
        $schedules[] = $schedule;
        $new_index   = count( $schedules ) - 1;

        if ( $this->save_schedules( $schedules ) ) {
            $this->maybe_schedule_cron();
            return $new_index;
        }

        return false;
    }

    /**
     * Update an existing schedule.
     *
     * @param int                  $index The schedule index.
     * @param array<string, mixed> $data  Updated schedule data.
     * @return bool True on success, false on failure.
     */
    public function update( int $index, array $data ): bool {
        $schedules = $this->get_all();

        if ( ! isset( $schedules[ $index ] ) ) {
            Logger::error( 'Attempted to update non-existent schedule', [ 'index' => $index ] );
            return false;
        }

        $schedule = new Schedule( $data );

        if ( ! $schedule->is_valid() ) {
            Logger::error( 'Attempted to update schedule with invalid data', [ 'data' => $data ] );
            return false;
        }

        $schedules[ $index ] = $schedule;

        return $this->save_schedules( $schedules );
    }

    /**
     * Delete a schedule.
     *
     * @param int $index The schedule index.
     * @return bool True on success, false on failure.
     */
    public function delete( int $index ): bool {
        $schedules = $this->get_all();

        if ( ! isset( $schedules[ $index ] ) ) {
            Logger::error( 'Attempted to delete non-existent schedule', [ 'index' => $index ] );
            return false;
        }

        array_splice( $schedules, $index, 1 );

        $result = $this->save_schedules( $schedules );

        if ( $result && empty( $schedules ) ) {
            wp_clear_scheduled_hook( 'pc_daily_category_check' );
        }

        return $result;
    }

    /**
     * Check if there are any schedules.
     *
     * @return bool True if schedules exist.
     */
    public function has_schedules(): bool {
        return ! empty( $this->get_all() );
    }

    /**
     * Get schedule count.
     *
     * @return int Number of schedules.
     */
    public function count(): int {
        return count( $this->get_all() );
    }

    /**
     * Load schedules from database.
     *
     * @return void
     */
    private function load_schedules(): void {
        $raw_schedules    = get_option( self::OPTION_NAME, [] );
        $this->schedules  = [];

        if ( ! is_array( $raw_schedules ) ) {
            return;
        }

        foreach ( $raw_schedules as $data ) {
            if ( is_array( $data ) ) {
                $schedule = new Schedule( $data );
                if ( $schedule->is_valid() ) {
                    $this->schedules[] = $schedule;
                }
            }
        }
    }

    /**
     * Save schedules to database.
     *
     * @param array<int, Schedule> $schedules Schedules to save.
     * @return bool True on success.
     */
    private function save_schedules( array $schedules ): bool {
        $data = array_map(
            fn( Schedule $schedule ): array => $schedule->to_array(),
            $schedules
        );

        $result = update_option( self::OPTION_NAME, $data );

        if ( $result ) {
            $this->schedules = $schedules;
        }

        return $result;
    }

    /**
     * Validate that schedule references exist.
     *
     * @param Schedule $schedule The schedule to validate.
     * @return array<string, string> Validation errors.
     */
    private function validate_schedule_references( Schedule $schedule ): array {
        $errors = [];

        if ( ! $schedule->post_type_exists() ) {
            $errors['post_type'] = sprintf(
                /* translators: %s: post type name */
                __( 'Post type "%s" does not exist.', 'postycal' ),
                $schedule->post_type
            );
        }

        if ( ! $schedule->taxonomy_exists() ) {
            $errors['taxonomy'] = sprintf(
                /* translators: %s: taxonomy name */
                __( 'Taxonomy "%s" does not exist.', 'postycal' ),
                $schedule->taxonomy
            );
        }

        $terms = $schedule->terms_exist();
        if ( ! $terms['upcoming'] ) {
            $errors['upcoming_term'] = sprintf(
                /* translators: %s: term slug */
                __( 'Term "%s" does not exist.', 'postycal' ),
                $schedule->upcoming_term
            );
        }

        if ( ! $terms['past'] ) {
            $errors['past_term'] = sprintf(
                /* translators: %s: term slug */
                __( 'Term "%s" does not exist.', 'postycal' ),
                $schedule->past_term
            );
        }

        return $errors;
    }

    /**
     * Ensure cron is scheduled if we have schedules.
     *
     * @return void
     */
    private function maybe_schedule_cron(): void {
        if ( $this->has_schedules() && ! wp_next_scheduled( 'pc_daily_category_check' ) ) {
            wp_schedule_event( strtotime( 'tomorrow midnight' ), 'daily', 'pc_daily_category_check' );
        }
    }

    /**
     * Clear cached schedules to force reload.
     *
     * @return void
     */
    public function clear_cache(): void {
        $this->schedules = null;
    }

    /**
     * Get raw schedules data for export/backup.
     *
     * @return array<int, array<string, string>>
     */
    public function export(): array {
        return array_map(
            fn( Schedule $schedule ): array => $schedule->to_array(),
            $this->get_all()
        );
    }

    /**
     * Import schedules from data.
     *
     * @param array<int, array<string, mixed>> $data Schedules data.
     * @return int Number of schedules imported.
     */
    public function import( array $data ): int {
        $imported  = 0;
        $schedules = [];

        foreach ( $data as $item ) {
            if ( is_array( $item ) ) {
                $schedule = new Schedule( $item );
                if ( $schedule->is_valid() ) {
                    $schedules[] = $schedule;
                    ++$imported;
                }
            }
        }

        if ( $imported > 0 ) {
            $this->save_schedules( $schedules );
            $this->maybe_schedule_cron();
        }

        return $imported;
    }
}
