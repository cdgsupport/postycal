<?php
/**
 * Admin Class
 *
 * Handles the tabbed settings page (Schedules / Post Types / Taxonomies),
 * meta boxes on post edit screens, and all AJAX handlers.
 *
 * @package PostyCal
 * @since 2.0.0
 */

declare(strict_types=1);

namespace PostyCal;

/**
 * Admin functionality for PostyCal.
 */
class Admin {

    /** @var Schedule_Manager */
    private Schedule_Manager $schedule_manager;

    /** @var Post_Type_Manager */
    private Post_Type_Manager $post_type_manager;

    /** @var Taxonomy_Manager */
    private Taxonomy_Manager $taxonomy_manager;

    /** @var string */
    private string $hook_suffix = '';

    /** @var string */
    private const NONCE_ACTION = 'postycal_admin';

    /**
     * Constructor.
     *
     * @param Schedule_Manager  $schedule_manager
     * @param Post_Type_Manager $post_type_manager
     * @param Taxonomy_Manager  $taxonomy_manager
     */
    public function __construct(
        Schedule_Manager $schedule_manager,
        Post_Type_Manager $post_type_manager,
        Taxonomy_Manager $taxonomy_manager
    ) {
        $this->schedule_manager  = $schedule_manager;
        $this->post_type_manager = $post_type_manager;
        $this->taxonomy_manager  = $taxonomy_manager;
        $this->setup_hooks();
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    private function setup_hooks(): void {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
        add_action( 'save_post', [ $this, 'save_meta_box_data' ], 10, 1 );
        add_action( 'admin_head', [ $this, 'maybe_hide_publish_date' ] );
        add_action( 'admin_notices', [ $this, 'display_missing_dates_notice' ] );

        // Schedule AJAX.
        add_action( 'wp_ajax_postycal_save_schedule', [ $this, 'ajax_save_schedule' ] );
        add_action( 'wp_ajax_postycal_delete_schedule', [ $this, 'ajax_delete_schedule' ] );
        add_action( 'wp_ajax_postycal_trigger_cron', [ $this, 'ajax_trigger_cron' ] );

        // Post Type AJAX.
        add_action( 'wp_ajax_postycal_save_post_type', [ $this, 'ajax_save_post_type' ] );
        add_action( 'wp_ajax_postycal_delete_post_type', [ $this, 'ajax_delete_post_type' ] );

        // Taxonomy AJAX.
        add_action( 'wp_ajax_postycal_save_taxonomy', [ $this, 'ajax_save_taxonomy' ] );
        add_action( 'wp_ajax_postycal_delete_taxonomy', [ $this, 'ajax_delete_taxonomy' ] );

        // Dynamic dropdown AJAX.
        add_action( 'wp_ajax_postycal_get_post_type_taxonomies', [ $this, 'ajax_get_post_type_taxonomies' ] );
        add_action( 'wp_ajax_postycal_get_taxonomy_terms', [ $this, 'ajax_get_taxonomy_terms' ] );
    }

    // -------------------------------------------------------------------------
    // Settings page
    // -------------------------------------------------------------------------

    public function add_admin_menu(): void {
        $this->hook_suffix = add_options_page(
            __( 'PostyCal Settings', 'postycal' ),
            __( 'PostyCal', 'postycal' ),
            'manage_options',
            'postycal-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== $this->hook_suffix ) {
            return;
        }

        wp_enqueue_style(
            'postycal-admin',
            POSTYCAL_PLUGIN_URL . 'admin/css/admin.css',
            [],
            POSTYCAL_VERSION
        );

        wp_enqueue_script(
            'postycal-admin',
            POSTYCAL_PLUGIN_URL . 'admin/js/admin.js',
            [ 'jquery' ],
            POSTYCAL_VERSION,
            true
        );

        wp_localize_script(
            'postycal-admin',
            'postycal',
            [
                'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                'nonce'      => wp_create_nonce( self::NONCE_ACTION ),
                'schedules'  => $this->schedule_manager->export(),
                'postTypes'  => $this->post_type_manager->export(),
                'taxonomies' => $this->taxonomy_manager->export(),
                'i18n'       => $this->get_i18n_strings(),
            ]
        );
    }

    /**
     * @return array<string, string>
     */
    private function get_i18n_strings(): array {
        return [
            // Shared.
            'confirmDelete'       => __( 'Are you sure you want to delete this item?', 'postycal' ),
            'saveError'           => __( 'Error saving. Please try again.', 'postycal' ),
            'deleteError'         => __( 'Error deleting. Please try again.', 'postycal' ),
            'processing'          => __( 'Processing...', 'postycal' ),
            'loading'             => __( 'Loading...', 'postycal' ),
            'noItemsFound'        => __( 'No items configured yet.', 'postycal' ),
            'editButton'          => __( 'Edit', 'postycal' ),
            'deleteButton'        => __( 'Delete', 'postycal' ),

            // Schedule.
            'triggerSuccess'      => __( 'Schedules processed successfully.', 'postycal' ),
            'triggerError'        => __( 'Error processing schedules. Please try again.', 'postycal' ),
            'addSchedule'         => __( 'Add New Schedule', 'postycal' ),
            'editSchedule'        => __( 'Edit Schedule', 'postycal' ),
            'published'           => __( 'published', 'postycal' ),
            'expired'             => __( 'expired', 'postycal' ),
            'selectPostTypeFirst' => __( 'Select Post Type first', 'postycal' ),
            'selectTaxonomyFirst' => __( 'Select Taxonomy first', 'postycal' ),
            'noTaxonomiesFound'   => __( 'No taxonomies found for this post type', 'postycal' ),
            'errorLoadingTax'     => __( 'Error loading taxonomies', 'postycal' ),
            'selectTerm'          => __( 'Select a term', 'postycal' ),
            'noTermsFound'        => __( 'No terms found', 'postycal' ),
            'errorLoadingTerms'   => __( 'Error loading terms', 'postycal' ),

            // Post types.
            'addPostType'         => __( 'Add New Post Type', 'postycal' ),
            'editPostType'        => __( 'Edit Post Type', 'postycal' ),
            'noSchedules'         => __( 'No schedules configured. Click "Add New Schedule" to create one.', 'postycal' ),
            'noPostTypes'         => __( 'No post types created yet. Click "Add New Post Type" to create one.', 'postycal' ),
            'noTaxonomies'        => __( 'No taxonomies created yet. Click "Add New Taxonomy" to create one.', 'postycal' ),

            // Taxonomies.
            'addTaxonomy'         => __( 'Add New Taxonomy', 'postycal' ),
            'editTaxonomy'        => __( 'Edit Taxonomy', 'postycal' ),
        ];
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap postycal-settings">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <nav class="nav-tab-wrapper postycal-tab-nav">
                <a href="#" class="nav-tab nav-tab-active" data-tab="schedules">
                    <?php esc_html_e( 'Schedules', 'postycal' ); ?>
                </a>
                <a href="#" class="nav-tab" data-tab="post-types">
                    <?php esc_html_e( 'Post Types', 'postycal' ); ?>
                </a>
                <a href="#" class="nav-tab" data-tab="taxonomies">
                    <?php esc_html_e( 'Taxonomies', 'postycal' ); ?>
                </a>
            </nav>

            <?php /* ---- SCHEDULES TAB ---- */ ?>
            <div id="postycal-tab-schedules" class="postycal-tab-panel">
                <?php $this->render_info_box(); ?>
                <h2><?php esc_html_e( 'Schedules', 'postycal' ); ?></h2>
                <div id="postycal-schedules-container">
                    <?php $this->render_schedules_table( $this->schedule_manager->get_all() ); ?>
                </div>
                <p class="submit">
                    <button type="button" class="button button-primary" id="postycal-add-schedule">
                        <?php esc_html_e( 'Add New Schedule', 'postycal' ); ?>
                    </button>
                    <button type="button" class="button button-secondary" id="postycal-trigger-cron"
                        <?php echo $this->schedule_manager->has_schedules() ? '' : 'style="display:none;"'; ?>>
                        <?php esc_html_e( 'Run All Schedules Now', 'postycal' ); ?>
                    </button>
                </p>
            </div>

            <?php /* ---- POST TYPES TAB ---- */ ?>
            <div id="postycal-tab-post-types" class="postycal-tab-panel" style="display:none;">
                <h2><?php esc_html_e( 'Post Types', 'postycal' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Create custom post types that PostyCal will manage. After saving, the post type is available immediately in the Schedules and Taxonomies tabs.', 'postycal' ); ?>
                </p>
                <div id="postycal-post-types-container">
                    <?php $this->render_post_types_table( $this->post_type_manager->get_all() ); ?>
                </div>
                <p class="submit">
                    <button type="button" class="button button-primary" id="postycal-add-post-type">
                        <?php esc_html_e( 'Add New Post Type', 'postycal' ); ?>
                    </button>
                </p>
            </div>

            <?php /* ---- TAXONOMIES TAB ---- */ ?>
            <div id="postycal-tab-taxonomies" class="postycal-tab-panel" style="display:none;">
                <h2><?php esc_html_e( 'Taxonomies', 'postycal' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Create taxonomies and assign them to post types. Seed terms (Upcoming, Active, Past) are created automatically so the taxonomy is ready for a PostyCal schedule immediately.', 'postycal' ); ?>
                </p>
                <div id="postycal-taxonomies-container">
                    <?php $this->render_taxonomies_table( $this->taxonomy_manager->get_all() ); ?>
                </div>
                <p class="submit">
                    <button type="button" class="button button-primary" id="postycal-add-taxonomy">
                        <?php esc_html_e( 'Add New Taxonomy', 'postycal' ); ?>
                    </button>
                </p>
            </div>

            <?php $this->render_schedule_modal(); ?>
            <?php $this->render_post_type_modal(); ?>
            <?php $this->render_taxonomy_modal(); ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Info box
    // -------------------------------------------------------------------------

    private function render_info_box(): void {
        ?>
        <div class="postycal-info-box">
            <h2><?php esc_html_e( 'How PostyCal Works', 'postycal' ); ?></h2>
            <p><?php esc_html_e( 'PostyCal manages the full lifecycle of time-sensitive posts using two dates and three taxonomy terms:', 'postycal' ); ?></p>
            <ol>
                <li><strong><?php esc_html_e( 'Before go-live:', 'postycal' ); ?></strong> <?php esc_html_e( 'Post stays as a draft, assigned the Upcoming term.', 'postycal' ); ?></li>
                <li><strong><?php esc_html_e( 'On go-live date:', 'postycal' ); ?></strong> <?php esc_html_e( 'PostyCal publishes the post and assigns the Active term.', 'postycal' ); ?></li>
                <li><strong><?php esc_html_e( 'On expiration date:', 'postycal' ); ?></strong> <?php esc_html_e( 'PostyCal sets the post to private and assigns the Past term.', 'postycal' ); ?></li>
            </ol>
            <p><strong><?php esc_html_e( 'Recommended setup order:', 'postycal' ); ?></strong> <?php esc_html_e( 'Post Types → Taxonomies (with seed terms) → Schedules.', 'postycal' ); ?></p>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Schedules table & modal
    // -------------------------------------------------------------------------

    /**
     * @param Schedule[] $schedules
     */
    private function render_schedules_table( array $schedules ): void {
        ?>
        <table class="wp-list-table widefat fixed striped" id="postycal-schedules-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'postycal' ); ?></th>
                    <th><?php esc_html_e( 'Post Type', 'postycal' ); ?></th>
                    <th><?php esc_html_e( 'Taxonomy', 'postycal' ); ?></th>
                    <th><?php esc_html_e( 'Upcoming Term', 'postycal' ); ?></th>
                    <th><?php esc_html_e( 'Active Term', 'postycal' ); ?></th>
                    <th><?php esc_html_e( 'Past Term', 'postycal' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'postycal' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $schedules ) ) : ?>
                    <tr class="no-items"><td colspan="7"><?php esc_html_e( 'No schedules configured. Click "Add New Schedule" to create one.', 'postycal' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $schedules as $i => $s ) : ?>
                        <tr data-index="<?php echo esc_attr( (string) $i ); ?>">
                            <td><?php echo esc_html( $s->name ); ?></td>
                            <td><?php echo esc_html( $s->post_type ); ?></td>
                            <td><?php echo esc_html( $s->taxonomy . ( $s->use_time ? ' (time-aware)' : '' ) ); ?></td>
                            <td><?php echo esc_html( $s->upcoming_term ); ?></td>
                            <td><?php echo esc_html( $s->active_term ); ?></td>
                            <td><?php echo esc_html( $s->past_term ); ?></td>
                            <td>
                                <button type="button" class="button postycal-edit-schedule" data-index="<?php echo esc_attr( (string) $i ); ?>"><?php esc_html_e( 'Edit', 'postycal' ); ?></button>
                                <button type="button" class="button postycal-delete-schedule" data-index="<?php echo esc_attr( (string) $i ); ?>"><?php esc_html_e( 'Delete', 'postycal' ); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_schedule_modal(): void {
        ?>
        <div id="postycal-modal" class="postycal-modal" style="display:none;">
            <div class="postycal-modal-backdrop"></div>
            <div class="postycal-modal-content">
                <h2 id="postycal-modal-title"><?php esc_html_e( 'Add New Schedule', 'postycal' ); ?></h2>
                <form id="postycal-schedule-form">
                    <input type="hidden" id="postycal-schedule-index" name="index" value="">
                    <table class="form-table">
                        <tr>
                            <th><label for="postycal-name"><?php esc_html_e( 'Schedule Name', 'postycal' ); ?></label></th>
                            <td><input type="text" id="postycal-name" name="name" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="postycal-post-type"><?php esc_html_e( 'Post Type', 'postycal' ); ?></label></th>
                            <td>
                                <select id="postycal-post-type" name="post_type" required>
                                    <option value=""><?php esc_html_e( 'Select Post Type', 'postycal' ); ?></option>
                                    <?php foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pt ) : ?>
                                        <option value="<?php echo esc_attr( $pt->name ); ?>"><?php echo esc_html( $pt->label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Selecting a post type loads its registered taxonomies below.', 'postycal' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="postycal-taxonomy"><?php esc_html_e( 'Taxonomy', 'postycal' ); ?></label></th>
                            <td>
                                <select id="postycal-taxonomy" name="taxonomy" required>
                                    <option value=""><?php esc_html_e( 'Select Post Type first', 'postycal' ); ?></option>
                                </select>
                                <span class="spinner" id="postycal-tax-spinner"></span>
                                <p class="description"><?php esc_html_e( 'Selecting a taxonomy loads its terms below.', 'postycal' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="postycal-upcoming-term"><?php esc_html_e( 'Upcoming Term', 'postycal' ); ?></label></th>
                            <td>
                                <select id="postycal-upcoming-term" name="upcoming_term" required>
                                    <option value=""><?php esc_html_e( 'Select Taxonomy first', 'postycal' ); ?></option>
                                </select>
                                <span class="spinner" id="postycal-terms-spinner"></span>
                                <p class="description"><?php esc_html_e( 'Assigned while the post is a draft awaiting go-live.', 'postycal' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="postycal-active-term"><?php esc_html_e( 'Active Term', 'postycal' ); ?></label></th>
                            <td>
                                <select id="postycal-active-term" name="active_term" required>
                                    <option value=""><?php esc_html_e( 'Select Taxonomy first', 'postycal' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'Assigned when the post is published.', 'postycal' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="postycal-past-term"><?php esc_html_e( 'Past Term', 'postycal' ); ?></label></th>
                            <td>
                                <select id="postycal-past-term" name="past_term" required>
                                    <option value=""><?php esc_html_e( 'Select Taxonomy first', 'postycal' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'Assigned when the post is set to private after expiration.', 'postycal' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Time-Aware', 'postycal' ); ?></th>
                            <td>
                                <label><input type="checkbox" id="postycal-use-time" name="use_time" value="1"> <?php esc_html_e( 'Use exact time for transitions (requires datetime-local fields)', 'postycal' ); ?></label>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Schedule', 'postycal' ); ?></button>
                        <button type="button" class="button" id="postycal-cancel"><?php esc_html_e( 'Cancel', 'postycal' ); ?></button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Post types table & modal
    // -------------------------------------------------------------------------

    /**
     * @param Post_Type[] $post_types
     */
    private function render_post_types_table( array $post_types ): void {
        ?>
        <table class="wp-list-table widefat fixed striped" id="postycal-post-types-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'postycal' ); ?></th>
                    <th><?php esc_html_e( 'Slug', 'postycal' ); ?></th>
                    <th><?php esc_html_e( 'Supports', 'postycal' ); ?></th>
                    <th><?php esc_html_e( 'Archive', 'postycal' ); ?></th>
                    <th><?php esc_html_e( 'REST', 'postycal' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'postycal' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $post_types ) ) : ?>
                    <tr class="no-items"><td colspan="6"><?php esc_html_e( 'No post types created yet. Click "Add New Post Type" to create one.', 'postycal' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $post_types as $i => $pt ) : ?>
                        <tr data-index="<?php echo esc_attr( (string) $i ); ?>">
                            <td><?php echo esc_html( $pt->name ); ?> <span class="description">(<?php echo esc_html( $pt->plural ); ?>)</span></td>
                            <td><code><?php echo esc_html( $pt->slug ); ?></code></td>
                            <td><?php echo esc_html( implode( ', ', $pt->supports ) ); ?></td>
                            <td><?php echo $pt->has_archive ? '✓' : '—'; ?></td>
                            <td><?php echo $pt->show_in_rest ? '✓' : '—'; ?></td>
                            <td>
                                <button type="button" class="button postycal-edit-post-type" data-index="<?php echo esc_attr( (string) $i ); ?>"><?php esc_html_e( 'Edit', 'postycal' ); ?></button>
                                <button type="button" class="button postycal-delete-post-type" data-index="<?php echo esc_attr( (string) $i ); ?>"><?php esc_html_e( 'Delete', 'postycal' ); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_post_type_modal(): void {
        $supports_options = [
            'editor'        => __( 'Editor (content)', 'postycal' ),
            'thumbnail'     => __( 'Featured Image', 'postycal' ),
            'excerpt'       => __( 'Excerpt', 'postycal' ),
            'custom-fields' => __( 'Custom Fields', 'postycal' ),
            'revisions'     => __( 'Revisions', 'postycal' ),
            'comments'      => __( 'Comments', 'postycal' ),
        ];
        ?>
        <div id="postycal-cpt-modal" class="postycal-modal" style="display:none;">
            <div class="postycal-modal-backdrop"></div>
            <div class="postycal-modal-content">
                <h2 id="postycal-cpt-modal-title"><?php esc_html_e( 'Add New Post Type', 'postycal' ); ?></h2>
                <form id="postycal-cpt-form">
                    <input type="hidden" id="postycal-cpt-index" name="index" value="">
                    <table class="form-table">
                        <tr>
                            <th><label for="postycal-cpt-name"><?php esc_html_e( 'Singular Name', 'postycal' ); ?></label></th>
                            <td>
                                <input type="text" id="postycal-cpt-name" name="name" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. Alert', 'postycal' ); ?>">
                                <p class="description"><?php esc_html_e( 'Used in the admin menu (e.g. "Edit Alert").', 'postycal' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="postycal-cpt-plural"><?php esc_html_e( 'Plural Name', 'postycal' ); ?></label></th>
                            <td>
                                <input type="text" id="postycal-cpt-plural" name="plural" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Alerts', 'postycal' ); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="postycal-cpt-slug"><?php esc_html_e( 'Slug', 'postycal' ); ?></label></th>
                            <td>
                                <input type="text" id="postycal-cpt-slug" name="slug" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. alert', 'postycal' ); ?>" maxlength="20" pattern="[a-z0-9_]{1,20}">
                                <p class="description"><?php esc_html_e( 'Lowercase letters, numbers, and underscores only. Max 20 characters. Cannot be changed after posts are created.', 'postycal' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="postycal-cpt-description"><?php esc_html_e( 'Description', 'postycal' ); ?></label></th>
                            <td><textarea id="postycal-cpt-description" name="description" class="regular-text" rows="2"></textarea></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Supports', 'postycal' ); ?></th>
                            <td>
                                <fieldset>
                                    <label><input type="checkbox" value="title" checked disabled> <?php esc_html_e( 'Title (always on)', 'postycal' ); ?></label><br>
                                    <?php foreach ( $supports_options as $value => $label ) : ?>
                                        <label>
                                            <input type="checkbox" name="supports[]" value="<?php echo esc_attr( $value ); ?>" class="postycal-cpt-supports"
                                                <?php echo in_array( $value, [ 'editor' ], true ) ? 'checked' : ''; ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </label><br>
                                    <?php endforeach; ?>
                                </fieldset>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Options', 'postycal' ); ?></th>
                            <td>
                                <label><input type="checkbox" id="postycal-cpt-has-archive" name="has_archive" value="1"> <?php esc_html_e( 'Has archive page', 'postycal' ); ?></label><br>
                                <label><input type="checkbox" id="postycal-cpt-show-in-rest" name="show_in_rest" value="1" checked> <?php esc_html_e( 'Show in REST API (required for Gutenberg)', 'postycal' ); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="postycal-cpt-menu-icon"><?php esc_html_e( 'Menu Icon', 'postycal' ); ?></label></th>
                            <td>
                                <input type="text" id="postycal-cpt-menu-icon" name="menu_icon" class="regular-text" placeholder="dashicons-admin-post">
                                <p class="description">
                                    <?php esc_html_e( 'Dashicons class name.', 'postycal' ); ?>
                                    <a href="https://developer.wordpress.org/resource/dashicons/" target="_blank" rel="noopener"><?php esc_html_e( 'Browse icons', 'postycal' ); ?></a>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Post Type', 'postycal' ); ?></button>
                        <button type="button" class="button postycal-cpt-cancel"><?php esc_html_e( 'Cancel', 'postycal' ); ?></button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Taxonomies table & modal
    // -------------------------------------------------------------------------

    /**
     * @param Taxonomy[] $taxonomies
     */
    private function render_taxonomies_table( array $taxonomies ): void {
        ?>
        <table class="wp-list-table widefat fixed striped" id="postycal-taxonomies-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'postycal' ); ?></th>
                    <th><?php esc_html_e( 'Slug', 'postycal' ); ?></th>
                    <th><?php esc_html_e( 'Post Types', 'postycal' ); ?></th>
                    <th><?php esc_html_e( 'Hierarchical', 'postycal' ); ?></th>
                    <th><?php esc_html_e( 'REST', 'postycal' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'postycal' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $taxonomies ) ) : ?>
                    <tr class="no-items"><td colspan="6"><?php esc_html_e( 'No taxonomies created yet. Click "Add New Taxonomy" to create one.', 'postycal' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $taxonomies as $i => $tax ) : ?>
                        <tr data-index="<?php echo esc_attr( (string) $i ); ?>">
                            <td><?php echo esc_html( $tax->name ); ?></td>
                            <td><code><?php echo esc_html( $tax->slug ); ?></code></td>
                            <td><?php echo esc_html( implode( ', ', $tax->post_types ) ); ?></td>
                            <td><?php echo $tax->hierarchical ? '✓' : '—'; ?></td>
                            <td><?php echo $tax->show_in_rest ? '✓' : '—'; ?></td>
                            <td>
                                <button type="button" class="button postycal-edit-taxonomy" data-index="<?php echo esc_attr( (string) $i ); ?>"><?php esc_html_e( 'Edit', 'postycal' ); ?></button>
                                <button type="button" class="button postycal-delete-taxonomy" data-index="<?php echo esc_attr( (string) $i ); ?>"><?php esc_html_e( 'Delete', 'postycal' ); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_taxonomy_modal(): void {
        ?>
        <div id="postycal-tax-modal" class="postycal-modal" style="display:none;">
            <div class="postycal-modal-backdrop"></div>
            <div class="postycal-modal-content">
                <h2 id="postycal-tax-modal-title"><?php esc_html_e( 'Add New Taxonomy', 'postycal' ); ?></h2>
                <form id="postycal-tax-form">
                    <input type="hidden" id="postycal-tax-index" name="index" value="">
                    <table class="form-table">
                        <tr>
                            <th><label for="postycal-tax-name"><?php esc_html_e( 'Singular Name', 'postycal' ); ?></label></th>
                            <td>
                                <input type="text" id="postycal-tax-name" name="name" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. Alert Status', 'postycal' ); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="postycal-tax-plural"><?php esc_html_e( 'Plural Name', 'postycal' ); ?></label></th>
                            <td>
                                <input type="text" id="postycal-tax-plural" name="plural" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Alert Statuses', 'postycal' ); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="postycal-tax-slug"><?php esc_html_e( 'Slug', 'postycal' ); ?></label></th>
                            <td>
                                <input type="text" id="postycal-tax-slug" name="slug" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. alert_status', 'postycal' ); ?>" maxlength="32" pattern="[a-z0-9_]{1,32}">
                                <p class="description"><?php esc_html_e( 'Lowercase letters, numbers, and underscores only. Max 32 characters.', 'postycal' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Assign to Post Types', 'postycal' ); ?></th>
                            <td>
                                <fieldset id="postycal-tax-post-types">
                                    <?php foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pt ) : ?>
                                        <label>
                                            <input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>">
                                            <?php echo esc_html( $pt->label ); ?> <code>(<?php echo esc_html( $pt->name ); ?>)</code>
                                        </label><br>
                                    <?php endforeach; ?>
                                </fieldset>
                                <p class="description"><?php esc_html_e( 'Select one or more post types to attach this taxonomy to.', 'postycal' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Options', 'postycal' ); ?></th>
                            <td>
                                <label><input type="checkbox" id="postycal-tax-hierarchical" name="hierarchical" value="1"> <?php esc_html_e( 'Hierarchical (like categories)', 'postycal' ); ?></label><br>
                                <label><input type="checkbox" id="postycal-tax-show-in-rest" name="show_in_rest" value="1" checked> <?php esc_html_e( 'Show in REST API', 'postycal' ); ?></label>
                            </td>
                        </tr>
                        <tr id="postycal-tax-seed-row">
                            <th><?php esc_html_e( 'Seed Terms', 'postycal' ); ?></th>
                            <td>
                                <p class="description" style="margin-top:0;"><?php esc_html_e( 'Optional. These terms will be created automatically so the taxonomy is ready for a PostyCal schedule.', 'postycal' ); ?></p>
                                <label><?php esc_html_e( 'Upcoming term name:', 'postycal' ); ?><br>
                                    <input type="text" name="seed_upcoming" class="regular-text" value="<?php esc_attr_e( 'Upcoming', 'postycal' ); ?>">
                                </label><br><br>
                                <label><?php esc_html_e( 'Active term name:', 'postycal' ); ?><br>
                                    <input type="text" name="seed_active" class="regular-text" value="<?php esc_attr_e( 'Active', 'postycal' ); ?>">
                                </label><br><br>
                                <label><?php esc_html_e( 'Past term name:', 'postycal' ); ?><br>
                                    <input type="text" name="seed_past" class="regular-text" value="<?php esc_attr_e( 'Past', 'postycal' ); ?>">
                                </label>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Taxonomy', 'postycal' ); ?></button>
                        <button type="button" class="button postycal-tax-cancel"><?php esc_html_e( 'Cancel', 'postycal' ); ?></button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Meta boxes
    // -------------------------------------------------------------------------

    public function register_meta_boxes(): void {
        $registered_types = [];

        foreach ( $this->schedule_manager->get_all() as $schedule ) {
            if ( in_array( $schedule->post_type, $registered_types, true ) ) {
                continue;
            }

            add_meta_box(
                'postycal_publication_dates',
                __( 'Publication Schedule', 'postycal' ),
                [ $this, 'render_meta_box' ],
                $schedule->post_type,
                'side',
                'high'
            );

            $registered_types[] = $schedule->post_type;
        }
    }

    public function render_meta_box( \WP_Post $post ): void {
        foreach ( $this->schedule_manager->get_for_post_type( $post->post_type ) as $schedule ) {
            $this->render_schedule_date_fields( $post, $schedule );
        }
    }

    private function render_schedule_date_fields( \WP_Post $post, Schedule $schedule ): void {
        $go_live    = get_post_meta( $post->ID, $schedule->get_go_live_meta_key(), true );
        $expiration = get_post_meta( $post->ID, $schedule->get_expiration_meta_key(), true );
        $input_type = $schedule->use_time ? 'datetime-local' : 'date';
        $key        = $schedule->schedule_key;

        wp_nonce_field( 'postycal_save_' . $key, 'postycal_nonce_' . $key );

        $multiple = count( $this->schedule_manager->get_for_post_type( $post->post_type ) ) > 1;
        if ( $multiple ) {
            echo '<h4 style="margin:0 0 8px;">' . esc_html( $schedule->name ) . '</h4>';
        }
        ?>
        <p>
            <label for="postycal_go_live_<?php echo esc_attr( $key ); ?>"><strong><?php esc_html_e( 'Go-Live Date', 'postycal' ); ?></strong></label><br>
            <input type="<?php echo esc_attr( $input_type ); ?>" id="postycal_go_live_<?php echo esc_attr( $key ); ?>" name="postycal_go_live_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $go_live ); ?>" class="widefat postycal-date-field" required>
        </p>
        <p>
            <label for="postycal_expiration_<?php echo esc_attr( $key ); ?>"><strong><?php esc_html_e( 'Expiration Date', 'postycal' ); ?></strong></label><br>
            <input type="<?php echo esc_attr( $input_type ); ?>" id="postycal_expiration_<?php echo esc_attr( $key ); ?>" name="postycal_expiration_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $expiration ); ?>" class="widefat postycal-date-field" required>
        </p>
        <?php
    }

    public function save_meta_box_data( int $post_id ): void {
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $schedules = $this->schedule_manager->get_for_post_type( get_post_type( $post_id ) ?: '' );

        foreach ( $schedules as $schedule ) {
            $key = $schedule->schedule_key;

            if ( ! isset( $_POST[ 'postycal_nonce_' . $key ] ) ) {
                continue;
            }

            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ 'postycal_nonce_' . $key ] ) ), 'postycal_save_' . $key ) ) {
                continue;
            }

            foreach ( [ 'postycal_go_live_' . $key => $schedule->get_go_live_meta_key(), 'postycal_expiration_' . $key => $schedule->get_expiration_meta_key() ] as $field => $meta_key ) {
                if ( isset( $_POST[ $field ] ) ) {
                    update_post_meta( $post_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Post editor helpers
    // -------------------------------------------------------------------------

    public function maybe_hide_publish_date(): void {
        global $post, $pagenow;

        if ( ! in_array( $pagenow, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        if ( empty( $this->schedule_manager->get_for_post_type( $post->post_type ) ) ) {
            return;
        }

        echo '<style>.misc-pub-publishdate,.misc-pub-curtime{display:none!important;}</style>';
    }

    public function display_missing_dates_notice(): void {
        global $post, $pagenow;

        if ( ! in_array( $pagenow, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        if ( ! $post instanceof \WP_Post || $post->ID <= 0 || 'auto-draft' === $post->post_status ) {
            return;
        }

        foreach ( $this->schedule_manager->get_for_post_type( $post->post_type ) as $schedule ) {
            $go_live    = get_post_meta( $post->ID, $schedule->get_go_live_meta_key(), true );
            $expiration = get_post_meta( $post->ID, $schedule->get_expiration_meta_key(), true );

            if ( empty( $go_live ) || empty( $expiration ) ) {
                ?>
                <div class="notice notice-warning">
                    <p><strong><?php esc_html_e( 'PostyCal:', 'postycal' ); ?></strong> <?php esc_html_e( 'This post is missing a go-live or expiration date. It will not be published automatically until both dates are set.', 'postycal' ); ?></p>
                </div>
                <?php
                return;
            }
        }
    }

    // -------------------------------------------------------------------------
    // AJAX — schedules
    // -------------------------------------------------------------------------

    public function ajax_save_schedule(): void {
        $this->verify_ajax_request();

        $data     = $this->sanitize_schedule_data( $_POST );
        $required = [ 'name', 'post_type', 'taxonomy', 'upcoming_term', 'active_term', 'past_term' ];

        foreach ( $required as $field ) {
            if ( empty( $data[ $field ] ) ) {
                wp_send_json_error( [ 'message' => sprintf( __( 'Required field missing: %s', 'postycal' ), $field ) ] );
            }
        }

        $index = isset( $_POST['index'] ) && '' !== $_POST['index'] ? absint( $_POST['index'] ) : null;

        if ( null !== $index ) {
            $existing = $this->schedule_manager->get( $index );
            if ( null !== $existing ) {
                $data['schedule_key'] = $existing->schedule_key;
            }
            $result = $this->schedule_manager->update( $index, $data );
        } else {
            $result = $this->schedule_manager->add( $data );
        }

        if ( false === $result ) {
            wp_send_json_error( [ 'message' => __( 'Failed to save schedule.', 'postycal' ) ] );
        }

        wp_send_json_success( [ 'message' => __( 'Schedule saved.', 'postycal' ), 'schedules' => $this->schedule_manager->export() ] );
    }

    public function ajax_delete_schedule(): void {
        $this->verify_ajax_request();

        $index  = absint( $_POST['index'] ?? 0 );
        $result = $this->schedule_manager->delete( $index );

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( 'Failed to delete schedule.', 'postycal' ) ] );
        }

        wp_send_json_success( [ 'message' => __( 'Schedule deleted.', 'postycal' ), 'schedules' => $this->schedule_manager->export() ] );
    }

    public function ajax_trigger_cron(): void {
        $this->verify_ajax_request();

        $results = Core::get_instance()->get_cron_handler()->trigger_manual_run();

        wp_send_json_success( [ 'message' => __( 'Schedules processed.', 'postycal' ), 'results' => $results ] );
    }

    // -------------------------------------------------------------------------
    // AJAX — post types
    // -------------------------------------------------------------------------

    public function ajax_save_post_type(): void {
        $this->verify_ajax_request();

        $data = $this->sanitize_post_type_data( $_POST );

        if ( empty( $data['name'] ) || empty( $data['slug'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Name and slug are required.', 'postycal' ) ] );
        }

        $index = isset( $_POST['index'] ) && '' !== $_POST['index'] ? absint( $_POST['index'] ) : null;

        if ( null !== $index ) {
            $result = $this->post_type_manager->update( $index, $data );
        } else {
            $result = $this->post_type_manager->add( $data );
        }

        if ( false === $result ) {
            wp_send_json_error( [ 'message' => __( 'Failed to save post type. The slug may already exist.', 'postycal' ) ] );
        }

        wp_send_json_success( [ 'message' => __( 'Post type saved.', 'postycal' ), 'postTypes' => $this->post_type_manager->export() ] );
    }

    public function ajax_delete_post_type(): void {
        $this->verify_ajax_request();

        $index  = absint( $_POST['index'] ?? 0 );
        $result = $this->post_type_manager->delete( $index );

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( 'Failed to delete post type.', 'postycal' ) ] );
        }

        wp_send_json_success( [ 'message' => __( 'Post type deleted.', 'postycal' ), 'postTypes' => $this->post_type_manager->export() ] );
    }

    // -------------------------------------------------------------------------
    // AJAX — taxonomies
    // -------------------------------------------------------------------------

    public function ajax_save_taxonomy(): void {
        $this->verify_ajax_request();

        $data = $this->sanitize_taxonomy_data( $_POST );

        if ( empty( $data['name'] ) || empty( $data['slug'] ) || empty( $data['post_types'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Name, slug, and at least one post type are required.', 'postycal' ) ] );
        }

        $index        = isset( $_POST['index'] ) && '' !== $_POST['index'] ? absint( $_POST['index'] ) : null;
        $is_new       = ( null === $index );
        $seed_terms   = $is_new ? [
            sanitize_text_field( wp_unslash( $_POST['seed_upcoming'] ?? '' ) ),
            sanitize_text_field( wp_unslash( $_POST['seed_active'] ?? '' ) ),
            sanitize_text_field( wp_unslash( $_POST['seed_past'] ?? '' ) ),
        ] : [];

        if ( null !== $index ) {
            $result = $this->taxonomy_manager->update( $index, $data );
        } else {
            $result = $this->taxonomy_manager->add( $data );
        }

        if ( false === $result ) {
            wp_send_json_error( [ 'message' => __( 'Failed to save taxonomy. The slug may already exist.', 'postycal' ) ] );
        }

        // Seed terms for new taxonomies.
        $seeded = [];
        if ( $is_new && ! empty( array_filter( $seed_terms ) ) ) {
            $taxonomy_index = is_int( $result ) ? $result : $index;
            $taxonomy       = $this->taxonomy_manager->get( (int) $taxonomy_index );

            if ( null !== $taxonomy ) {
                $seeded = $this->taxonomy_manager->seed_terms( $taxonomy, $seed_terms );
            }
        }

        wp_send_json_success( [
            'message'    => __( 'Taxonomy saved.', 'postycal' ),
            'taxonomies' => $this->taxonomy_manager->export(),
            'seeded'     => $seeded,
        ] );
    }

    public function ajax_delete_taxonomy(): void {
        $this->verify_ajax_request();

        $index  = absint( $_POST['index'] ?? 0 );
        $result = $this->taxonomy_manager->delete( $index );

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( 'Failed to delete taxonomy.', 'postycal' ) ] );
        }

        wp_send_json_success( [ 'message' => __( 'Taxonomy deleted.', 'postycal' ), 'taxonomies' => $this->taxonomy_manager->export() ] );
    }

    // -------------------------------------------------------------------------
    // AJAX — dynamic dropdowns
    // -------------------------------------------------------------------------

    public function ajax_get_post_type_taxonomies(): void {
        $this->verify_ajax_request();

        $post_type = sanitize_key( wp_unslash( $_POST['post_type'] ?? '' ) );

        if ( ! post_type_exists( $post_type ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post type.', 'postycal' ) ] );
        }

        $taxonomies = get_object_taxonomies( $post_type, 'objects' );
        $data       = [];

        foreach ( $taxonomies as $taxonomy ) {
            if ( $taxonomy->public ) {
                $data[] = [ 'slug' => $taxonomy->name, 'name' => $taxonomy->label ];
            }
        }

        wp_send_json_success( [ 'taxonomies' => $data ] );
    }

    public function ajax_get_taxonomy_terms(): void {
        $this->verify_ajax_request();

        $taxonomy = sanitize_key( wp_unslash( $_POST['taxonomy'] ?? '' ) );

        if ( ! taxonomy_exists( $taxonomy ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid taxonomy.', 'postycal' ) ] );
        }

        $terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name' ] );

        if ( is_wp_error( $terms ) ) {
            wp_send_json_error( [ 'message' => __( 'Error loading terms.', 'postycal' ) ] );
        }

        wp_send_json_success( [
            'terms' => array_map( fn( \WP_Term $t ): array => [ 'slug' => $t->slug, 'name' => $t->name ], $terms ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    private function verify_ajax_request(): void {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'postycal' ) ], 403 );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'postycal' ) ], 403 );
        }
    }

    /** @return array<string, string|bool> */
    private function sanitize_schedule_data( array $post ): array {
        return [
            'name'          => sanitize_text_field( wp_unslash( $post['name'] ?? '' ) ),
            'post_type'     => sanitize_key( wp_unslash( $post['post_type'] ?? '' ) ),
            'taxonomy'      => sanitize_key( wp_unslash( $post['taxonomy'] ?? '' ) ),
            'upcoming_term' => sanitize_title( wp_unslash( $post['upcoming_term'] ?? '' ) ),
            'active_term'   => sanitize_title( wp_unslash( $post['active_term'] ?? '' ) ),
            'past_term'     => sanitize_title( wp_unslash( $post['past_term'] ?? '' ) ),
            'use_time'      => ! empty( $post['use_time'] ),
        ];
    }

    /** @return array<string, mixed> */
    private function sanitize_post_type_data( array $post ): array {
        $supports = isset( $post['supports'] ) && is_array( $post['supports'] )
            ? array_map( 'sanitize_key', $post['supports'] )
            : [ 'title', 'editor' ];

        if ( ! in_array( 'title', $supports, true ) ) {
            array_unshift( $supports, 'title' );
        }

        return [
            'name'         => sanitize_text_field( wp_unslash( $post['name'] ?? '' ) ),
            'plural'       => sanitize_text_field( wp_unslash( $post['plural'] ?? '' ) ),
            'slug'         => sanitize_key( wp_unslash( $post['slug'] ?? '' ) ),
            'description'  => sanitize_textarea_field( wp_unslash( $post['description'] ?? '' ) ),
            'has_archive'  => ! empty( $post['has_archive'] ),
            'show_in_rest' => ! empty( $post['show_in_rest'] ),
            'supports'     => $supports,
            'menu_icon'    => sanitize_text_field( wp_unslash( $post['menu_icon'] ?? '' ) ),
        ];
    }

    /** @return array<string, mixed> */
    private function sanitize_taxonomy_data( array $post ): array {
        $post_types = isset( $post['post_types'] ) && is_array( $post['post_types'] )
            ? array_map( 'sanitize_key', $post['post_types'] )
            : [];

        return [
            'name'         => sanitize_text_field( wp_unslash( $post['name'] ?? '' ) ),
            'plural'       => sanitize_text_field( wp_unslash( $post['plural'] ?? '' ) ),
            'slug'         => sanitize_key( wp_unslash( $post['slug'] ?? '' ) ),
            'description'  => sanitize_textarea_field( wp_unslash( $post['description'] ?? '' ) ),
            'hierarchical' => ! empty( $post['hierarchical'] ),
            'show_in_rest' => ! empty( $post['show_in_rest'] ),
            'post_types'   => $post_types,
        ];
    }
}
