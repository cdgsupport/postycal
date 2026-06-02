<?php
/**
 * Post Type Data Class
 *
 * Represents a single custom post type managed by PostyCal.
 *
 * @package PostyCal
 * @since 2.1.0
 */

declare(strict_types=1);

namespace PostyCal;

/**
 * Custom post type configuration object.
 */
class Post_Type {

    /**
     * Singular label (e.g. "Alert").
     *
     * @var string
     */
    public readonly string $name;

    /**
     * Plural label (e.g. "Alerts").
     *
     * @var string
     */
    public readonly string $plural;

    /**
     * Post type slug — the key passed to register_post_type().
     * Max 20 characters, lowercase alphanumeric and underscores only.
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
     * Whether the post type has an archive page.
     *
     * @var bool
     */
    public readonly bool $has_archive;

    /**
     * Whether to expose the post type in the REST API (required for Gutenberg).
     *
     * @var bool
     */
    public readonly bool $show_in_rest;

    /**
     * List of supported features (title, editor, thumbnail, excerpt).
     *
     * @var string[]
     */
    public readonly array $supports;

    /**
     * Dashicons class for the admin menu icon (e.g. 'dashicons-megaphone').
     *
     * @var string
     */
    public readonly string $menu_icon;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $data Post type data.
     */
    public function __construct( array $data ) {
        $this->name         = sanitize_text_field( $data['name'] ?? '' );
        $this->plural       = sanitize_text_field( $data['plural'] ?? $this->name . 's' );
        $this->slug         = $this->sanitize_slug( $data['slug'] ?? '' );
        $this->description  = sanitize_textarea_field( $data['description'] ?? '' );
        $this->has_archive  = (bool) ( $data['has_archive'] ?? false );
        $this->show_in_rest = (bool) ( $data['show_in_rest'] ?? true );
        $this->supports     = $this->sanitize_supports( $data['supports'] ?? [ 'title', 'editor' ] );
        $this->menu_icon    = sanitize_text_field( $data['menu_icon'] ?? 'dashicons-admin-post' );
    }

    /**
     * Sanitize the post type slug.
     *
     * WordPress post type names must be lowercase alphanumeric / underscores,
     * must not start with "wp_", and are capped at 20 characters.
     *
     * @param mixed $slug Raw slug value.
     * @return string
     */
    private function sanitize_slug( mixed $slug ): string {
        $slug = strtolower( sanitize_key( (string) $slug ) );
        $slug = preg_replace( '/[^a-z0-9_]/', '_', $slug ) ?? $slug;
        $slug = ltrim( $slug, '_' );

        // Prevent collision with the reserved wp_ prefix.
        if ( str_starts_with( $slug, 'wp_' ) ) {
            $slug = 'pc_' . $slug;
        }

        return substr( $slug, 0, 20 );
    }

    /**
     * Sanitize the supports array against the allowed set.
     *
     * @param mixed $supports Raw supports value.
     * @return string[]
     */
    private function sanitize_supports( mixed $supports ): array {
        $allowed = [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions', 'comments' ];

        if ( ! is_array( $supports ) ) {
            return [ 'title', 'editor' ];
        }

        $clean = array_filter(
            array_map( 'sanitize_key', $supports ),
            fn( string $s ): bool => in_array( $s, $allowed, true )
        );

        // Title is always required.
        if ( ! in_array( 'title', $clean, true ) ) {
            array_unshift( $clean, 'title' );
        }

        return array_values( $clean );
    }

    /**
     * Check that the minimum required fields are present and valid.
     *
     * @return bool
     */
    public function is_valid(): bool {
        return ! empty( $this->name ) && ! empty( $this->slug ) && strlen( $this->slug ) <= 20;
    }

    /**
     * Build the args array for register_post_type().
     *
     * @return array<string, mixed>
     */
    public function get_registration_args(): array {
        $singular = $this->name;
        $plural   = $this->plural;
        $lower    = strtolower( $plural );

        return [
            'labels'       => [
                'name'               => $plural,
                'singular_name'      => $singular,
                'add_new'            => __( 'Add New', 'postycal' ),
                'add_new_item'       => sprintf( __( 'Add New %s', 'postycal' ), $singular ),
                'edit_item'          => sprintf( __( 'Edit %s', 'postycal' ), $singular ),
                'new_item'           => sprintf( __( 'New %s', 'postycal' ), $singular ),
                'view_item'          => sprintf( __( 'View %s', 'postycal' ), $singular ),
                'all_items'          => sprintf( __( 'All %s', 'postycal' ), $plural ),
                'search_items'       => sprintf( __( 'Search %s', 'postycal' ), $plural ),
                'not_found'          => sprintf( __( 'No %s found.', 'postycal' ), $lower ),
                'not_found_in_trash' => sprintf( __( 'No %s found in Trash.', 'postycal' ), $lower ),
            ],
            'description'  => $this->description,
            'public'       => true,
            'has_archive'  => $this->has_archive,
            'show_in_rest' => $this->show_in_rest,
            'supports'     => $this->supports,
            'menu_icon'    => $this->menu_icon ?: 'dashicons-admin-post',
            'rewrite'      => [ 'slug' => $this->slug ],
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
            'has_archive'  => $this->has_archive,
            'show_in_rest' => $this->show_in_rest,
            'supports'     => $this->supports,
            'menu_icon'    => $this->menu_icon,
        ];
    }
}
