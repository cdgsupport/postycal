<?php
/**
 * Logger Class
 *
 * Handles logging for PostyCal operations.
 *
 * @package PostyCal
 * @since 2.0.0
 */

declare(strict_types=1);

namespace PostyCal;

/**
 * Logging utility for PostyCal.
 */
class Logger {

    /**
     * Log levels.
     */
    private const LEVEL_DEBUG   = 'DEBUG';
    private const LEVEL_INFO    = 'INFO';
    private const LEVEL_WARNING = 'WARNING';
    private const LEVEL_ERROR   = 'ERROR';

    /**
     * Log a debug message.
     *
     * @param string               $message The message to log.
     * @param array<string, mixed> $context Additional context.
     * @return void
     */
    public static function debug( string $message, array $context = [] ): void {
        self::log( self::LEVEL_DEBUG, $message, $context );
    }

    /**
     * Log an info message.
     *
     * @param string               $message The message to log.
     * @param array<string, mixed> $context Additional context.
     * @return void
     */
    public static function info( string $message, array $context = [] ): void {
        self::log( self::LEVEL_INFO, $message, $context );
    }

    /**
     * Log a warning message.
     *
     * @param string               $message The message to log.
     * @param array<string, mixed> $context Additional context.
     * @return void
     */
    public static function warning( string $message, array $context = [] ): void {
        self::log( self::LEVEL_WARNING, $message, $context );
    }

    /**
     * Log an error message.
     *
     * @param string               $message The message to log.
     * @param array<string, mixed> $context Additional context.
     * @return void
     */
    public static function error( string $message, array $context = [] ): void {
        self::log( self::LEVEL_ERROR, $message, $context );
    }

    /**
     * Log a message.
     *
     * @param string               $level   The log level.
     * @param string               $message The message to log.
     * @param array<string, mixed> $context Additional context.
     * @return void
     */
    private static function log( string $level, string $message, array $context = [] ): void {
        // Only log if WP_DEBUG_LOG is enabled.
        if ( ! self::should_log( $level ) ) {
            return;
        }

        $timestamp     = gmdate( 'Y-m-d H:i:s' );
        $context_string = ! empty( $context ) ? ' ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES ) : '';
        $log_message   = sprintf(
            '[%s] [PostyCal] [%s] %s%s',
            $timestamp,
            $level,
            $message,
            $context_string
        );

        // Use WordPress debug log.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( $log_message );
    }

    /**
     * Check if we should log at this level.
     *
     * @param string $level The log level.
     * @return bool True if should log.
     */
    private static function should_log( string $level ): bool {
        // Check if debugging is enabled.
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return false;
        }

        if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
            return false;
        }

        // Always log errors and warnings.
        if ( in_array( $level, [ self::LEVEL_ERROR, self::LEVEL_WARNING ], true ) ) {
            return true;
        }

        // Only log debug/info if POSTYCAL_DEBUG is defined.
        if ( defined( 'POSTYCAL_DEBUG' ) && POSTYCAL_DEBUG ) {
            return true;
        }

        // Default: only log info level and above.
        return self::LEVEL_INFO === $level;
    }
}
