<?php
/**
 * Schedule Data Class
 *
 * Represents a single PostyCal schedule configuration.
 *
 * @package PostyCal
 * @since 2.0.0
 */

declare(strict_types=1);

namespace PostyCal;

/**
 * Schedule data object.
 */
class Schedule {

    /**
     * Schedule name.
     *
     * @var string
     */
    public readonly string $name;

    /**
     * Post type to monitor.
     *
     * @var string
     */
    public readonly string $post_type;

    /**
     * Taxonomy for category assignment.
     *
     * @var string
     */
    public readonly string $taxonomy;

    /**
     * ACF date field name.
     *
     * @var string
     */
    public readonly string $date_field;

    /**
     * Field type: 'single' or 'repeater'.
     *
     * @var string
     */
    public readonly string $field_type;

    /**
     * Sub-field name for repeater fields.
     *
     * @var string
     */
    public readonly string $sub_field;

    /**
     * Date logic for repeaters: 'earliest', 'latest', or 'any_past'.
     *
     * @var string
     */
    public readonly string $date_logic;

    /**
     * Pre-date (upcoming) term slug.
     *
     * @var string
     */
    public readonly string $upcoming_term;

    /**
     * Post-date (past) term slug.
     *
     * @var string
     */
    public readonly string $past_term;

    /**
     * Whether to use time component for transitions.
     *
     * @var bool
     */
    public readonly bool $use_time;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $data Schedule data array.
     */
    public function __construct( array $data ) {
        $this->name          = sanitize_text_field( $data['name'] ?? '' );
        $this->post_type     = sanitize_key( $data['post_type'] ?? '' );
        $this->taxonomy      = sanitize_key( $data['taxonomy'] ?? '' );
        $this->date_field    = sanitize_text_field( $data['date_field'] ?? '' );
        $this->field_type    = $this->validate_field_type( $data['field_type'] ?? 'single' );
        $this->sub_field     = sanitize_text_field( $data['sub_field'] ?? '' );
        $this->date_logic    = $this->validate_date_logic( $data['date_logic'] ?? 'earliest' );
        $this->upcoming_term = sanitize_text_field( $data['upcoming_term'] ?? '' );
        $this->past_term     = sanitize_text_field( $data['past_term'] ?? '' );
        $this->use_time      = (bool) ( $data['use_time'] ?? false );
    }

    /**
     * Validate field type.
     *
     * @param string $type The field type.
     * @return string Valid field type.
     */
    private function validate_field_type( string $type ): string {
        $valid_types = [ 'single', 'repeater' ];
        return in_array( $type, $valid_types, true ) ? $type : 'single';
    }

    /**
     * Validate date logic.
     *
     * @param string $logic The date logic.
     * @return string Valid date logic.
     */
    private function validate_date_logic( string $logic ): string {
        $valid_logic = [ 'earliest', 'latest', 'any_past' ];
        return in_array( $logic, $valid_logic, true ) ? $logic : 'earliest';
    }

    /**
     * Check if schedule is valid.
     *
     * @return bool True if valid, false otherwise.
     */
    public function is_valid(): bool {
        if ( empty( $this->name ) || empty( $this->post_type ) || empty( $this->taxonomy ) ) {
            return false;
        }

        if ( empty( $this->date_field ) || empty( $this->upcoming_term ) || empty( $this->past_term ) ) {
            return false;
        }

        if ( 'repeater' === $this->field_type && empty( $this->sub_field ) ) {
            return false;
        }

        return true;
    }

    /**
     * Validate that referenced post type exists.
     *
     * @return bool True if post type exists.
     */
    public function post_type_exists(): bool {
        return post_type_exists( $this->post_type );
    }

    /**
     * Validate that referenced taxonomy exists.
     *
     * @return bool True if taxonomy exists.
     */
    public function taxonomy_exists(): bool {
        return taxonomy_exists( $this->taxonomy );
    }

    /**
     * Validate that terms exist in the taxonomy.
     *
     * @return array<string, bool> Array with 'upcoming' and 'past' keys.
     */
    public function terms_exist(): array {
        return [
            'upcoming' => term_exists( $this->upcoming_term, $this->taxonomy ) !== null,
            'past'     => term_exists( $this->past_term, $this->taxonomy ) !== null,
        ];
    }

    /**
     * Convert schedule to array.
     *
     * @return array<string, string> Schedule data array.
     */
    public function to_array(): array {
        return [
            'name'          => $this->name,
            'post_type'     => $this->post_type,
            'taxonomy'      => $this->taxonomy,
            'date_field'    => $this->date_field,
            'field_type'    => $this->field_type,
            'sub_field'     => $this->sub_field,
            'date_logic'    => $this->date_logic,
            'upcoming_term' => $this->upcoming_term,
            'past_term'     => $this->past_term,
            'use_time'      => $this->use_time,
        ];
    }

    /**
     * Check if this schedule matches a post type.
     *
     * @param string $post_type The post type to check.
     * @return bool True if matches.
     */
    public function matches_post_type( string $post_type ): bool {
        return $this->post_type === $post_type;
    }

    /**
     * Is this a repeater field schedule?
     *
     * @return bool True if repeater.
     */
    public function is_repeater(): bool {
        return 'repeater' === $this->field_type;
    }
}
