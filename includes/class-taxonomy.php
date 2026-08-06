<?php
/**
 * Taxonomy Data Class
 *
 * Represents a single custom taxonomy managed by PostyCal.
 *
 * @package PostyCal
 * @since 2.1.0
 */

declare(strict_types=1);

namespace PostyCal;

/**
 * Custom taxonomy configuration object.
 */
class Taxonomy {

    /**
     * Singular label (e.g. "Alert Status").
     *
     * @var string
     */
    public readonly string $name;

    /**
     * Plural label (e.g. "Alert Statuses").
     *
     * @var string
     */
    public readonly string $plural;

    /**
     * Taxonomy slug — the key passed to register_taxonomy().
     * Max 32 characters, lowercase alphanumeric and underscores only.
     *
     * @var string
     */
    public readonly string $slug;

    /**
     * Optional description.
     *
     * @var string
     */
    public readonly string $description;

    /**
     * Whether the taxonomy is hierarchical (category-like vs tag-like).
     *
     * @var bool
     */
    public readonly bool $hierarchical;

    /**
     * Whether to expose in the REST API.
     *
     * @var bool
     */
    public readonly bool $show_in_rest;

    /**
     * Post type slugs this taxonomy is assigned to.
     *
     * @var string[]
     */
    public readonly array $post_types;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $data Taxonomy data.
     */
    public function __construct( array $data ) {
        $this->name         = sanitize_text_field( $data['name'] ?? '' );
        // Use ?: rather than ?? — the admin form always submits the key, so an
        // omitted plural arrives as an empty string, not null.
        $this->plural       = sanitize_text_field( $data['plural'] ?? '' ) ?: $this->name . 's';
        $this->slug         = $this->sanitize_slug( $data['slug'] ?? '' );
        $this->description  = sanitize_textarea_field( $data['description'] ?? '' );
        $this->hierarchical = (bool) ( $data['hierarchical'] ?? false );
        $this->show_in_rest = (bool) ( $data['show_in_rest'] ?? true );
        $this->post_types   = $this->sanitize_post_types( $data['post_types'] ?? [] );
    }

    /**
     * Sanitize the taxonomy slug.
     *
     * WordPress taxonomy names are capped at 32 characters and must be
     * lowercase alphanumeric / underscores.
     *
     * @param mixed $slug Raw value.
     * @return string
     */
    private function sanitize_slug( mixed $slug ): string {
        $slug = strtolower( sanitize_key( (string) $slug ) );
        $slug = preg_replace( '/[^a-z0-9_]/', '_', $slug ) ?? $slug;
        $slug = ltrim( $slug, '_' );

        return substr( $slug, 0, 32 );
    }

    /**
     * Sanitize the list of post type slugs.
     *
     * @param mixed $post_types Raw value.
     * @return string[]
     */
    private function sanitize_post_types( mixed $post_types ): array {
        if ( ! is_array( $post_types ) ) {
            return [];
        }

        return array_values(
            array_filter(
                array_map( 'sanitize_key', $post_types ),
                fn( string $s ): bool => ! empty( $s )
            )
        );
    }

    /**
     * Check that the minimum required fields are present.
     *
     * @return bool
     */
    public function is_valid(): bool {
        return ! empty( $this->name )
            && ! empty( $this->slug )
            && strlen( $this->slug ) <= 32
            && ! empty( $this->post_types );
    }

    /**
     * Build the args array for register_taxonomy().
     *
     * @return array<string, mixed>
     */
    public function get_registration_args(): array {
        $singular = $this->name;
        $plural   = $this->plural;

        return [
            'labels'            => [
                'name'              => $plural,
                'singular_name'     => $singular,
                'search_items'      => sprintf( __( 'Search %s', 'postycal' ), $plural ),
                'all_items'         => sprintf( __( 'All %s', 'postycal' ), $plural ),
                'edit_item'         => sprintf( __( 'Edit %s', 'postycal' ), $singular ),
                'update_item'       => sprintf( __( 'Update %s', 'postycal' ), $singular ),
                'add_new_item'      => sprintf( __( 'Add New %s', 'postycal' ), $singular ),
                'new_item_name'     => sprintf( __( 'New %s Name', 'postycal' ), $singular ),
                'menu_name'         => $plural,
            ],
            'description'       => $this->description,
            'hierarchical'      => $this->hierarchical,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => $this->show_in_rest,
            'rewrite'           => [ 'slug' => $this->slug ],
        ];
    }

    /**
     * Convert to plain array for storage.
     *
     * @return array<string, mixed>
     */
    public function to_array(): array {
        return [
            'name'         => $this->name,
            'plural'       => $this->plural,
            'slug'         => $this->slug,
            'description'  => $this->description,
            'hierarchical' => $this->hierarchical,
            'show_in_rest' => $this->show_in_rest,
            'post_types'   => $this->post_types,
        ];
    }
}
