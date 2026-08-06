<?php
/**
 * Post Type Manager Class
 *
 * Handles CRUD operations for PostyCal-managed custom post types and
 * registers them with WordPress on init.
 *
 * @package PostyCal
 * @since 2.1.0
 */

declare(strict_types=1);

namespace PostyCal;

/**
 * Manages PostyCal custom post types.
 */
class Post_Type_Manager {

    /**
     * Option name for storing post type definitions.
     *
     * @var string
     */
    private const OPTION_NAME = 'pc_post_types';

    /**
     * Transient key used to signal that rewrite rules need flushing.
     *
     * @var string
     */
    private const FLUSH_TRANSIENT = 'postycal_flush_rewrites';

    /**
     * In-memory cache of loaded post types.
     *
     * @var Post_Type[]|null
     */
    private ?array $post_types = null;

    /**
     * Slugs registered with WordPress during this request.
     *
     * Lets callers tell a PostyCal-owned post type apart from a core or
     * third-party one — needed because a definition deleted mid-request is
     * still registered until the next page load.
     *
     * @var string[]
     */
    private array $registered_slugs = [];

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register all PostyCal post types with WordPress.
     *
     * Called on init at priority 5 so that PostyCal CPTs exist before
     * taxonomies (registered at priority 6) are attached to them.
     *
     * @return void
     */
    public function register_all(): void {
        foreach ( $this->get_all() as $post_type ) {
            if ( ! post_type_exists( $post_type->slug ) ) {
                register_post_type( $post_type->slug, $post_type->get_registration_args() );
            }

            $this->registered_slugs[] = $post_type->slug;
        }
    }

    /**
     * Get the slugs PostyCal registered during this request.
     *
     * @return string[]
     */
    public function get_registered_slugs(): array {
        return $this->registered_slugs;
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /**
     * Get all stored post types.
     *
     * @return Post_Type[]
     */
    public function get_all(): array {
        if ( null === $this->post_types ) {
            $this->load();
        }
        return $this->post_types ?? [];
    }

    /**
     * Get a single post type by index.
     *
     * @param int $index Storage index.
     * @return Post_Type|null
     */
    public function get( int $index ): ?Post_Type {
        return $this->get_all()[ $index ] ?? null;
    }

    /**
     * Add a new post type.
     *
     * @param array<string, mixed> $data Post type data.
     * @return int|false New index on success, false on failure.
     */
    public function add( array $data ): int|false {
        $post_type = new Post_Type( $data );

        if ( ! $post_type->is_valid() ) {
            Logger::error( 'Attempted to add invalid post type', [ 'data' => $data ] );
            return false;
        }

        if ( $this->slug_exists( $post_type->slug ) ) {
            Logger::error( 'Post type slug already exists', [ 'slug' => $post_type->slug ] );
            return false;
        }

        $all       = $this->get_all();
        $all[]     = $post_type;
        $new_index = count( $all ) - 1;

        if ( $this->save( $all ) ) {
            $this->schedule_flush();
            return $new_index;
        }

        return false;
    }

    /**
     * Update an existing post type.
     *
     * @param int                  $index Index of the post type to update.
     * @param array<string, mixed> $data  Updated data.
     * @return bool
     */
    public function update( int $index, array $data ): bool {
        $all = $this->get_all();

        if ( ! isset( $all[ $index ] ) ) {
            Logger::error( 'Attempted to update non-existent post type', [ 'index' => $index ] );
            return false;
        }

        // The slug is the database key for every post of this type — changing
        // it would orphan them all. Preserve the stored slug regardless of
        // what was submitted (the edit form also renders it read-only).
        $data['slug'] = $all[ $index ]->slug;

        $post_type = new Post_Type( $data );

        if ( ! $post_type->is_valid() ) {
            Logger::error( 'Attempted to update post type with invalid data', [ 'data' => $data ] );
            return false;
        }

        $all[ $index ] = $post_type;

        if ( $this->save( $all ) ) {
            $this->schedule_flush();
            return true;
        }

        return false;
    }

    /**
     * Delete a post type by index.
     *
     * @param int $index Storage index.
     * @return bool
     */
    public function delete( int $index ): bool {
        $all = $this->get_all();

        if ( ! isset( $all[ $index ] ) ) {
            Logger::error( 'Attempted to delete non-existent post type', [ 'index' => $index ] );
            return false;
        }

        array_splice( $all, $index, 1 );

        if ( $this->save( $all ) ) {
            $this->schedule_flush();
            return true;
        }

        return false;
    }

    /**
     * Export all post types as plain arrays (for JS/admin use).
     *
     * @return array<int, array<string, mixed>>
     */
    public function export(): array {
        return array_map(
            fn( Post_Type $pt ): array => $pt->to_array(),
            $this->get_all()
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether a slug is already registered.
     *
     * Checks both PostyCal-managed post types and core/third-party ones.
     *
     * @param string $slug The slug to check.
     * @return bool
     */
    public function slug_exists( string $slug ): bool {
        return post_type_exists( $slug );
    }

    /**
     * Load post types from the database.
     *
     * @return void
     */
    private function load(): void {
        $raw             = get_option( self::OPTION_NAME, [] );
        $this->post_types = [];

        if ( ! is_array( $raw ) ) {
            return;
        }

        foreach ( $raw as $data ) {
            if ( is_array( $data ) ) {
                $pt = new Post_Type( $data );
                if ( $pt->is_valid() ) {
                    $this->post_types[] = $pt;
                }
            }
        }
    }

    /**
     * Persist post types to the database.
     *
     * update_option returns false when data is unchanged — treat as success.
     *
     * @param Post_Type[] $post_types Post types to save.
     * @return bool
     */
    private function save( array $post_types ): bool {
        $data   = array_map( fn( Post_Type $pt ): array => $pt->to_array(), $post_types );
        $result = update_option( self::OPTION_NAME, $data );

        if ( $result || get_option( self::OPTION_NAME ) === $data ) {
            $this->post_types = $post_types;
            return true;
        }

        return false;
    }

    /**
     * Set a transient so Core flushes rewrite rules on the next init.
     *
     * @return void
     */
    private function schedule_flush(): void {
        set_transient( self::FLUSH_TRANSIENT, true, HOUR_IN_SECONDS );
    }
}
