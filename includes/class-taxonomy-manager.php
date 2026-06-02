<?php
/**
 * Taxonomy Manager Class
 *
 * Handles CRUD operations for PostyCal-managed custom taxonomies and
 * registers them with WordPress on init.
 *
 * @package PostyCal
 * @since 2.1.0
 */

declare(strict_types=1);

namespace PostyCal;

/**
 * Manages PostyCal custom taxonomies.
 */
class Taxonomy_Manager {

    /**
     * Option name for storing taxonomy definitions.
     *
     * @var string
     */
    private const OPTION_NAME = 'pc_taxonomies';

    /**
     * Transient key used to signal that rewrite rules need flushing.
     *
     * @var string
     */
    private const FLUSH_TRANSIENT = 'postycal_flush_rewrites';

    /**
     * In-memory cache of loaded taxonomies.
     *
     * @var Taxonomy[]|null
     */
    private ?array $taxonomies = null;

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register all PostyCal taxonomies with WordPress.
     *
     * Called on init at priority 6, after PostyCal CPTs are registered
     * (priority 5), so that CPT slugs are valid object types.
     *
     * @return void
     */
    public function register_all(): void {
        foreach ( $this->get_all() as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy->slug ) ) {
                register_taxonomy(
                    $taxonomy->slug,
                    $taxonomy->post_types,
                    $taxonomy->get_registration_args()
                );
            } else {
                // Taxonomy exists (possibly from a previous registration) —
                // ensure it is still attached to all configured post types.
                foreach ( $taxonomy->post_types as $post_type ) {
                    register_taxonomy_for_object_type( $taxonomy->slug, $post_type );
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /**
     * Get all stored taxonomies.
     *
     * @return Taxonomy[]
     */
    public function get_all(): array {
        if ( null === $this->taxonomies ) {
            $this->load();
        }
        return $this->taxonomies ?? [];
    }

    /**
     * Get a single taxonomy by index.
     *
     * @param int $index Storage index.
     * @return Taxonomy|null
     */
    public function get( int $index ): ?Taxonomy {
        return $this->get_all()[ $index ] ?? null;
    }

    /**
     * Add a new taxonomy.
     *
     * @param array<string, mixed> $data Taxonomy data.
     * @return int|false New index on success, false on failure.
     */
    public function add( array $data ): int|false {
        $taxonomy = new Taxonomy( $data );

        if ( ! $taxonomy->is_valid() ) {
            Logger::error( 'Attempted to add invalid taxonomy', [ 'data' => $data ] );
            return false;
        }

        if ( $this->slug_exists( $taxonomy->slug ) ) {
            Logger::error( 'Taxonomy slug already exists', [ 'slug' => $taxonomy->slug ] );
            return false;
        }

        $all       = $this->get_all();
        $all[]     = $taxonomy;
        $new_index = count( $all ) - 1;

        if ( $this->save( $all ) ) {
            $this->schedule_flush();
            return $new_index;
        }

        return false;
    }

    /**
     * Update an existing taxonomy.
     *
     * @param int                  $index Index of the taxonomy to update.
     * @param array<string, mixed> $data  Updated data.
     * @return bool
     */
    public function update( int $index, array $data ): bool {
        $all = $this->get_all();

        if ( ! isset( $all[ $index ] ) ) {
            Logger::error( 'Attempted to update non-existent taxonomy', [ 'index' => $index ] );
            return false;
        }

        $taxonomy = new Taxonomy( $data );

        if ( ! $taxonomy->is_valid() ) {
            Logger::error( 'Attempted to update taxonomy with invalid data', [ 'data' => $data ] );
            return false;
        }

        $all[ $index ] = $taxonomy;

        if ( $this->save( $all ) ) {
            $this->schedule_flush();
            return true;
        }

        return false;
    }

    /**
     * Delete a taxonomy by index.
     *
     * @param int $index Storage index.
     * @return bool
     */
    public function delete( int $index ): bool {
        $all = $this->get_all();

        if ( ! isset( $all[ $index ] ) ) {
            Logger::error( 'Attempted to delete non-existent taxonomy', [ 'index' => $index ] );
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
     * Create seed terms inside a taxonomy immediately after it is registered.
     *
     * Called from the AJAX save handler on new taxonomy creation only.
     * Registers the taxonomy first (init may have already fired) so that
     * wp_insert_term can find it.
     *
     * @param Taxonomy $taxonomy  The newly created taxonomy.
     * @param string[] $term_names Term names to create (empty strings are skipped).
     * @return array<string, int|string> Map of term_name => term_id or WP_Error message.
     */
    public function seed_terms( Taxonomy $taxonomy, array $term_names ): array {
        // Ensure the taxonomy is registered in this request.
        if ( ! taxonomy_exists( $taxonomy->slug ) ) {
            register_taxonomy(
                $taxonomy->slug,
                $taxonomy->post_types,
                $taxonomy->get_registration_args()
            );
        }

        $results = [];

        foreach ( $term_names as $term_name ) {
            $term_name = sanitize_text_field( $term_name );

            if ( empty( $term_name ) ) {
                continue;
            }

            $result = wp_insert_term( $term_name, $taxonomy->slug );

            if ( is_wp_error( $result ) ) {
                Logger::warning(
                    'Failed to seed term',
                    [ 'term' => $term_name, 'taxonomy' => $taxonomy->slug, 'error' => $result->get_error_message() ]
                );
                $results[ $term_name ] = $result->get_error_message();
            } else {
                $results[ $term_name ] = $result['term_id'];
            }
        }

        return $results;
    }

    /**
     * Export all taxonomies as plain arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public function export(): array {
        return array_map(
            fn( Taxonomy $t ): array => $t->to_array(),
            $this->get_all()
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether a taxonomy slug is already registered.
     *
     * @param string $slug The slug to check.
     * @return bool
     */
    public function slug_exists( string $slug ): bool {
        return taxonomy_exists( $slug );
    }

    /**
     * Load taxonomies from the database.
     *
     * @return void
     */
    private function load(): void {
        $raw             = get_option( self::OPTION_NAME, [] );
        $this->taxonomies = [];

        if ( ! is_array( $raw ) ) {
            return;
        }

        foreach ( $raw as $data ) {
            if ( is_array( $data ) ) {
                $taxonomy = new Taxonomy( $data );
                if ( $taxonomy->is_valid() ) {
                    $this->taxonomies[] = $taxonomy;
                }
            }
        }
    }

    /**
     * Persist taxonomies to the database.
     *
     * update_option returns false when data is unchanged — treat as success.
     *
     * @param Taxonomy[] $taxonomies Taxonomies to save.
     * @return bool
     */
    private function save( array $taxonomies ): bool {
        $data   = array_map( fn( Taxonomy $t ): array => $t->to_array(), $taxonomies );
        $result = update_option( self::OPTION_NAME, $data );

        if ( $result || get_option( self::OPTION_NAME ) === $data ) {
            $this->taxonomies = $taxonomies;
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
