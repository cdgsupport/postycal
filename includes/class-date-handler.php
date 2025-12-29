<?php
/**
 * Date Handler Class
 *
 * Handles date parsing, comparison, and transition logic.
 *
 * @package PostyCal
 * @since 2.0.0
 */

declare(strict_types=1);

namespace PostyCal;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Handles date operations for PostyCal.
 */
class Date_Handler {

    /**
     * Get the relevant date from a post based on schedule settings.
     *
     * @param int      $post_id  The post ID.
     * @param Schedule $schedule The schedule configuration.
     * @return DateTimeImmutable|null The parsed date or null if not found.
     */
    public static function get_post_date( int $post_id, Schedule $schedule ): ?DateTimeImmutable {
        if ( ! function_exists( 'get_field' ) ) {
            Logger::debug( 'ACF get_field not available', [ 'post_id' => $post_id ] );
            return null;
        }

        if ( $schedule->is_repeater() ) {
            return self::get_repeater_date( $post_id, $schedule );
        }

        return self::get_single_date( $post_id, $schedule->date_field );
    }

    /**
     * Get date from a single ACF field.
     *
     * @param int    $post_id    The post ID.
     * @param string $field_name The field name.
     * @return DateTimeImmutable|null The parsed date or null.
     */
    private static function get_single_date( int $post_id, string $field_name ): ?DateTimeImmutable {
        $value = get_field( $field_name, $post_id );

        Logger::debug(
            'Retrieved ACF field value',
            [
                'post_id'    => $post_id,
                'field_name' => $field_name,
                'value'      => $value,
                'type'       => gettype( $value ),
            ]
        );

        if ( empty( $value ) ) {
            return null;
        }

        return self::parse_date( $value );
    }

    /**
     * Get date from an ACF repeater field.
     *
     * @param int      $post_id  The post ID.
     * @param Schedule $schedule The schedule configuration.
     * @return DateTimeImmutable|null The relevant date based on date_logic.
     */
    private static function get_repeater_date( int $post_id, Schedule $schedule ): ?DateTimeImmutable {
        $repeater = get_field( $schedule->date_field, $post_id );

        if ( ! is_array( $repeater ) || empty( $repeater ) ) {
            return null;
        }

        $dates = [];
        foreach ( $repeater as $row ) {
            if ( isset( $row[ $schedule->sub_field ] ) && ! empty( $row[ $schedule->sub_field ] ) ) {
                $date = self::parse_date( $row[ $schedule->sub_field ] );
                if ( null !== $date ) {
                    $dates[] = $date;
                }
            }
        }

        if ( empty( $dates ) ) {
            return null;
        }

        return self::select_date_by_logic( $dates, $schedule->date_logic );
    }

    /**
     * Select a date based on the configured logic.
     *
     * @param array<DateTimeImmutable> $dates      Array of dates.
     * @param string                   $date_logic The selection logic.
     * @return DateTimeImmutable|null The selected date.
     */
    private static function select_date_by_logic( array $dates, string $date_logic ): ?DateTimeImmutable {
        if ( empty( $dates ) ) {
            return null;
        }

        $now = self::get_current_date();

        switch ( $date_logic ) {
            case 'latest':
                return self::get_latest_date( $dates );

            case 'any_past':
                // Check if any date has passed.
                foreach ( $dates as $date ) {
                    if ( self::is_date_past( $date, $now ) ) {
                        return $date;
                    }
                }
                // If no past dates, return earliest future date.
                return self::get_earliest_date( $dates );

            case 'earliest':
            default:
                return self::get_earliest_date( $dates );
        }
    }

    /**
     * Parse a date string into a DateTimeImmutable object.
     *
     * Handles various ACF date formats including:
     * - Ymd (20250115) - ACF Date Picker default
     * - Y-m-d (2025-01-15) - Standard date
     * - Y-m-d H:i:s (2025-01-15 14:30:00) - ACF Date/Time Picker
     * - d/m/Y, m/d/Y, and other common formats
     *
     * @param mixed $value The date value (string or other format).
     * @return DateTimeImmutable|null The parsed date or null.
     */
    public static function parse_date( mixed $value ): ?DateTimeImmutable {
        if ( empty( $value ) ) {
            return null;
        }

        if ( $value instanceof DateTimeImmutable ) {
            return $value;
        }

        if ( $value instanceof \DateTime ) {
            return DateTimeImmutable::createFromMutable( $value );
        }

        if ( ! is_string( $value ) ) {
            Logger::debug( 'Date value is not a string', [ 'type' => gettype( $value ) ] );
            return null;
        }

        $timezone = self::get_timezone();

        // Try ACF's common formats first.
        $acf_formats = [
            'Ymd',           // ACF Date Picker default: 20250115
            'Y-m-d',         // Standard date: 2025-01-15
            'Y-m-d H:i:s',   // ACF Date/Time Picker: 2025-01-15 14:30:00
            'Ymd H:i:s',     // Alternative datetime: 20250115 14:30:00
            'd/m/Y',         // European format: 15/01/2025
            'm/d/Y',         // US format: 01/15/2025
            'd/m/Y H:i:s',   // European datetime
            'm/d/Y H:i:s',   // US datetime
            'd-m-Y',         // European with dashes
            'm-d-Y',         // US with dashes
            'Y/m/d',         // Alternative: 2025/01/15
            'Y/m/d H:i:s',   // Alternative datetime
        ];

        foreach ( $acf_formats as $format ) {
            $date = DateTimeImmutable::createFromFormat( $format, $value, $timezone );
            if ( false !== $date ) {
                // Verify the date is valid (createFromFormat can create invalid dates).
                $errors = DateTimeImmutable::getLastErrors();
                if ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) {
                    Logger::debug(
                        'Parsed date successfully',
                        [
                            'value'  => $value,
                            'format' => $format,
                            'result' => $date->format( 'Y-m-d H:i:s' ),
                        ]
                    );
                    return $date;
                }
            }
        }

        // Fallback: try PHP's natural parsing.
        try {
            $date = new DateTimeImmutable( $value, $timezone );
            Logger::debug(
                'Parsed date with natural parsing',
                [
                    'value'  => $value,
                    'result' => $date->format( 'Y-m-d H:i:s' ),
                ]
            );
            return $date;
        } catch ( \Exception $e ) {
            Logger::warning(
                'Failed to parse date',
                [
                    'value' => $value,
                    'error' => $e->getMessage(),
                ]
            );
            return null;
        }
    }

    /**
     * Get the current date (start of day).
     *
     * @return DateTimeImmutable Current date at midnight.
     */
    public static function get_current_date(): DateTimeImmutable {
        return new DateTimeImmutable( 'today', self::get_timezone() );
    }

    /**
     * Get the current datetime.
     *
     * @return DateTimeImmutable Current datetime.
     */
    public static function get_current_datetime(): DateTimeImmutable {
        return new DateTimeImmutable( 'now', self::get_timezone() );
    }

    /**
     * Get WordPress timezone.
     *
     * @return DateTimeZone The site timezone.
     */
    public static function get_timezone(): DateTimeZone {
        $timezone_string = get_option( 'timezone_string' );

        if ( ! empty( $timezone_string ) ) {
            return new DateTimeZone( $timezone_string );
        }

        // Fall back to UTC offset.
        $offset  = (float) get_option( 'gmt_offset', 0 );
        $hours   = (int) $offset;
        $minutes = ( $offset - $hours ) * 60;

        $sign   = $offset >= 0 ? '+' : '-';
        $offset = sprintf( '%s%02d:%02d', $sign, abs( $hours ), abs( $minutes ) );

        return new DateTimeZone( $offset );
    }

    /**
     * Check if a date is in the past.
     *
     * @param DateTimeImmutable $date     The date to check.
     * @param DateTimeImmutable|null $now The reference date (defaults to now).
     * @param bool              $use_time Whether to compare times or just dates.
     * @return bool True if date is past.
     */
    public static function is_date_past( DateTimeImmutable $date, ?DateTimeImmutable $now = null, bool $use_time = false ): bool {
        if ( $use_time ) {
            $now = $now ?? self::get_current_datetime();
            return $date < $now;
        }

        // Date-only comparison.
        $now = $now ?? self::get_current_date();

        // Compare just the date portions (ignore time).
        $date_start = $date->setTime( 0, 0, 0 );
        $now_start  = $now->setTime( 0, 0, 0 );

        return $date_start < $now_start;
    }

    /**
     * Check if a date should trigger transition (past with optional buffer).
     *
     * @param DateTimeImmutable      $date     The date to check.
     * @param DateTimeImmutable|null $now      The reference date (defaults to now).
     * @param bool                   $use_time Whether to compare times or just dates.
     * @return bool True if date has passed the transition threshold.
     */
    public static function should_transition( DateTimeImmutable $date, ?DateTimeImmutable $now = null, bool $use_time = false ): bool {
        if ( $use_time ) {
            // Time-aware: transition immediately when datetime passes.
            $now = $now ?? self::get_current_datetime();
            return $date < $now;
        }

        // Date-only: add buffer period before transitioning.
        $now = $now ?? self::get_current_date();

        // Add buffer period to the date.
        $date_with_buffer = $date->modify( '+' . POSTYCAL_TRANSITION_BUFFER . ' seconds' );

        // Compare just the date portions.
        $date_start = $date_with_buffer->setTime( 0, 0, 0 );
        $now_start  = $now->setTime( 0, 0, 0 );

        return $date_start < $now_start;
    }

    /**
     * Get the earliest date from an array.
     *
     * @param array<DateTimeImmutable> $dates Array of dates.
     * @return DateTimeImmutable|null The earliest date.
     */
    private static function get_earliest_date( array $dates ): ?DateTimeImmutable {
        if ( empty( $dates ) ) {
            return null;
        }

        usort(
            $dates,
            fn( DateTimeImmutable $a, DateTimeImmutable $b ): int => $a <=> $b
        );

        return $dates[0];
    }

    /**
     * Get the latest date from an array.
     *
     * @param array<DateTimeImmutable> $dates Array of dates.
     * @return DateTimeImmutable|null The latest date.
     */
    private static function get_latest_date( array $dates ): ?DateTimeImmutable {
        if ( empty( $dates ) ) {
            return null;
        }

        usort(
            $dates,
            fn( DateTimeImmutable $a, DateTimeImmutable $b ): int => $b <=> $a
        );

        return $dates[0];
    }

    /**
     * Format a date for display.
     *
     * @param DateTimeImmutable $date   The date to format.
     * @param string            $format The date format (default: WordPress date format).
     * @return string Formatted date string.
     */
    public static function format( DateTimeImmutable $date, string $format = '' ): string {
        if ( empty( $format ) ) {
            $format = get_option( 'date_format', 'Y-m-d' );
        }

        return $date->format( $format );
    }
}
