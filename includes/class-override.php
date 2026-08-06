<?php
/**
 * Schedule Override
 *
 * Per-post escape hatch from a schedule. Editors occasionally need to take a
 * single post out of the automated lifecycle — to hold something back past its
 * go-live date, to keep a post live past its expiration, or to retire one
 * early — without deleting the schedule or fudging the dates.
 *
 * The value is stored in post meta per schedule, so a post covered by two
 * schedules can be overridden for one and left automatic for the other.
 *
 * @package PostyCal
 * @since 2.2.0
 */

declare(strict_types=1);

namespace PostyCal;

/**
 * Override values and the state each one pins a post to.
 */
final class Override {

    /**
     * Follow the schedule (the default).
     */
    public const AUTO = '';

    /**
     * Leave the post entirely alone — no term and no status changes.
     */
    public const HOLD = 'hold';

    /**
     * Pin the post to the upcoming state (draft + upcoming term).
     */
    public const UPCOMING = 'upcoming';

    /**
     * Pin the post to the active state (published + active term).
     */
    public const ACTIVE = 'active';

    /**
     * Pin the post to the past state (private + past term).
     */
    public const PAST = 'past';

    /**
     * The overrides that pin a post to a specific lifecycle state.
     *
     * HOLD is deliberately excluded: it means "do nothing", not "do this".
     *
     * @return string[]
     */
    public static function pinned_values(): array {
        return [ self::UPCOMING, self::ACTIVE, self::PAST ];
    }

    /**
     * Every valid stored value.
     *
     * Kept separate from choices() so validation in the cron loops doesn't
     * trigger a translation lookup per post.
     *
     * @return string[]
     */
    public static function values(): array {
        return [ self::AUTO, self::HOLD, self::UPCOMING, self::ACTIVE, self::PAST ];
    }

    /**
     * Labels for the meta box dropdown, keyed by stored value.
     *
     * @return array<string, string>
     */
    public static function choices(): array {
        return [
            self::AUTO     => __( 'Automatic — follow the schedule', 'postycal' ),
            self::HOLD     => __( 'Hold — PostyCal makes no changes', 'postycal' ),
            self::UPCOMING => __( 'Force upcoming — keep as a draft', 'postycal' ),
            self::ACTIVE   => __( 'Force active — keep published', 'postycal' ),
            self::PAST     => __( 'Force past — make private now', 'postycal' ),
        ];
    }

    /**
     * Coerce a raw value to a known override, defaulting to automatic.
     *
     * @param mixed $value Raw meta or request value.
     * @return string
     */
    public static function sanitize( mixed $value ): string {
        if ( ! is_string( $value ) ) {
            return self::AUTO;
        }

        $value = sanitize_key( $value );

        return in_array( $value, self::values(), true ) ? $value : self::AUTO;
    }

    /**
     * Whether this override leaves the schedule in charge.
     *
     * @param string $override The override value.
     * @return bool
     */
    public static function is_automatic( string $override ): bool {
        return self::AUTO === $override;
    }

    /**
     * The term slug an override pins a post to.
     *
     * @param string   $override The override value.
     * @param Schedule $schedule The schedule the override belongs to.
     * @return string|null Term slug, or null if the override pins no term.
     */
    public static function term_for( string $override, Schedule $schedule ): ?string {
        return match ( $override ) {
            self::UPCOMING => $schedule->upcoming_term,
            self::ACTIVE   => $schedule->active_term,
            self::PAST     => $schedule->past_term,
            default        => null,
        };
    }

    /**
     * The post status an override pins a post to.
     *
     * @param string $override The override value.
     * @return string|null Post status, or null if the override pins no status.
     */
    public static function status_for( string $override ): ?string {
        return match ( $override ) {
            self::UPCOMING => 'draft',
            self::ACTIVE   => 'publish',
            self::PAST     => 'private',
            default        => null,
        };
    }

    /**
     * Human-readable label for an override value.
     *
     * @param string $override The override value.
     * @return string
     */
    public static function label( string $override ): string {
        return self::choices()[ $override ] ?? self::choices()[ self::AUTO ];
    }
}
