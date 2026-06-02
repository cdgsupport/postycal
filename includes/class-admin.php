<?php
/**
 * Admin Class
 *
 * Handles the settings page, meta boxes, and AJAX handlers.
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

    /**
     * Schedule manager instance.
     *
     * @var Schedule_Manager
     */
    private Schedule_Manager $schedule_manager;

    /**
     * Admin page hook suffix.
     *
     * @var string
     */
    private string $hook_suffix = '';

    /**
     * Nonce action for settings-page AJAX requests.
     *
     * @var string
     */
    private const NONCE_ACTION = 'postycal_admin';

    /**
     * Constructor.
     *
     * @param Schedule_Manager $schedule_manager The schedule manager.
     */
    public function __construct( Schedule_Manager $schedule_manager ) {
        $this->schedule_manager = $schedule_manager;
        $this->setup_hooks();
    }

    /**
     * Setup admin hooks.
     *
     * @return void
     */
    private function setup_hooks(): void {
        // Settings page.
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Meta boxes on post edit screens.
        add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
        add_action( 'save_post', [ $this, 'save_meta_box_data' ], 10, 1 );

        // Hide WP publish-date UI on post types managed by PostyCal.
        add_action( 'admin_head', [ $this, 'maybe_hide_publish_date' ] );

        // Admin notice when dates are missing.
        add_action( 'admin_notices', [ $this, 'display_missing_dates_notice' ] );

        // AJAX — schedule CRUD.
        add_action( 'wp_ajax_postycal_save_schedule', [ $this, 'ajax_save_schedule' ] );
        add_action( 'wp_ajax_postycal_delete_schedule', [ $this, 'ajax_delete_schedule' ] );
        add_action( 'wp_ajax_postycal_trigger_cron', [ $this, 'ajax_trigger_cron' ] );

        // AJAX — dynamic term loading.
        add_action( 'wp_ajax_postycal_get_taxonomy_terms', [ $this, 'ajax_get_taxonomy_terms' ] );
    }

    // -------------------------------------------------------------------------
    // Settings page
    // -------------------------------------------------------------------------

    /**
     * Register the settings page under Settings menu.
     *
     * @return void
     */
    public function add_admin_menu(): void {
        $this->hook_suffix = add_options_page(
            __( 'PostyCal Settings', 'postycal' ),
            __( 'PostyCal', 'postycal' ),
            'manage_options',
            'postycal-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Enqueue admin assets only on the PostyCal settings page.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
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
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
                'schedules' => $this->schedule_manager->export(),
                'i18n'      => $this->get_i18n_strings(),
            ]
        );
    }

    /**
     * Internationalized strings passed to JavaScript.
     *
     * @return array<string, string>
     */
    private function get_i18n_strings(): array {
        return [
            'confirmDelete'       => __( 'Are you sure you want to delete this schedule?', 'postycal' ),
            'saveError'           => __( 'Error saving schedule. Please try again.', 'postycal' ),
            'deleteError'         => __( 'Error deleting schedule. Please try again.', 'postycal' ),
            'triggerSuccess'      => __( 'Schedules processed successfully.', 'postycal' ),
            'triggerError'        => __( 'Error processing schedules. Please try again.', 'postycal' ),
            'addSchedule'         => __( 'Add New Schedule', 'postycal' ),
            'editSchedule'        => __( 'Edit Schedule', 'postycal' ),
            'processing'          => __( 'Processing...', 'postycal' ),
            'selectTaxonomyFirst' => __( 'Select Taxonomy first', 'postycal' ),
            'selectTerm'          => __( 'Select a term', 'postycal' ),
            'loading'             => __( 'Loading...', 'postycal' ),
            'noTermsFound'        => __( 'No terms found', 'postycal' ),
            'errorLoadingTerms'   => __( 'Error loading terms', 'postycal' ),
            'noSchedules'         => __( 'No schedules configured. Click "Add New Schedule" to create one.', 'postycal' ),
            'editButton'          => __( 'Edit', 'postycal' ),
            'deleteButton'        => __( 'Delete', 'postycal' ),
            'published'           => __( 'published', 'postycal' ),
            'expired'             => __( 'expired', 'postycal' ),
        ];
    }

    /**
     * Render the main settings page.
     *
     * @return void
     */
    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $schedules = $this->schedule_manager->get_all();
        ?>
        <div class="wrap postycal-settings">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php $this->render_info_box(); ?>

            <h2><?php esc_html_e( 'Schedule Management', 'postycal' ); ?></h2>

            <div id="postycal-schedules-container">
                <?php $this->render_schedules_table( $schedules ); ?>
            </div>

            <p class="submit">
                <button type="button" class="button button-primary" id="postycal-add-schedule">
                    <?php esc_html_e( 'Add New Schedule', 'postycal' ); ?>
                </button>

                <?php if ( ! empty( $schedules ) ) : ?>
                    <button type="button" class="button button-secondary" id="postycal-trigger-cron">
                        <?php esc_html_e( 'Run All Schedules Now', 'postycal' ); ?>
                    </button>
                <?php endif; ?>
            </p>

            <?php $this->render_schedule_modal(); ?>
        </div>
        <?php
    }

    /**
     * Render the info box explaining how the plugin works.
     *
     * @return void
     */
    private function render_info_box(): void {
        ?>
        <div class="postycal-info-box">
            <h2><?php esc_html_e( 'How PostyCal Works', 'postycal' ); ?></h2>
            <p><?php esc_html_e( 'PostyCal manages the full lifecycle of time-sensitive posts using two dates and three taxonomy terms:', 'postycal' ); ?></p>
            <ol>
                <li>
                    <strong><?php esc_html_e( 'Before go-live date:', 'postycal' ); ?></strong>
                    <?php esc_html_e( 'Post stays as a draft and is assigned the Upcoming term.', 'postycal' ); ?>
                </li>
                <li>
                    <strong><?php esc_html_e( 'On go-live date:', 'postycal' ); ?></strong>
                    <?php esc_html_e( 'PostyCal publishes the post and assigns the Active term.', 'postycal' ); ?>
                </li>
                <li>
                    <strong><?php esc_html_e( 'On expiration date:', 'postycal' ); ?></strong>
                    <?php esc_html_e( 'PostyCal sets the post to private and assigns the Past term.', 'postycal' ); ?>
                </li>
            </ol>
            <p><?php esc_html_e( 'The daily cron handles publish/expire transitions automatically. Saving a post sets the term immediately based on the current date.', 'postycal' ); ?></p>
        </div>
        <?php
    }

    /**
     * Render the schedules table.
     *
     * @param Schedule[] $schedules Array of schedules.
     * @return void
     */
    private function render_schedules_table( array $schedules ): void {
        ?>
        <table class="wp-list-table widefat fixed striped" id="postycal-schedules-table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Name', 'postycal' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Post Type', 'postycal' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Taxonomy', 'postycal' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Upcoming Term', 'postycal' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Active Term', 'postycal' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Past Term', 'postycal' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Actions', 'postycal' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $schedules ) ) : ?>
                    <tr class="no-items">
                        <td colspan="7">
                            <?php esc_html_e( 'No schedules configured. Click "Add New Schedule" to create one.', 'postycal' ); ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $schedules as $index => $schedule ) : ?>
                        <?php $this->render_schedule_row( $schedule, $index ); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render a single row in the schedules table.
     *
     * @param Schedule $schedule The schedule.
     * @param int      $index    The schedule index.
     * @return void
     */
    private function render_schedule_row( Schedule $schedule, int $index ): void {
        $time_label = $schedule->use_time ? ' ' . __( '(time-aware)', 'postycal' ) : '';
        ?>
        <tr data-index="<?php echo esc_attr( (string) $index ); ?>">
            <td><?php echo esc_html( $schedule->name ); ?></td>
            <td><?php echo esc_html( $schedule->post_type ); ?></td>
            <td><?php echo esc_html( $schedule->taxonomy . $time_label ); ?></td>
            <td><?php echo esc_html( $schedule->upcoming_term ); ?></td>
            <td><?php echo esc_html( $schedule->active_term ); ?></td>
            <td><?php echo esc_html( $schedule->past_term ); ?></td>
            <td>
                <button type="button" class="button postycal-edit-schedule" data-index="<?php echo esc_attr( (string) $index ); ?>">
                    <?php esc_html_e( 'Edit', 'postycal' ); ?>
                </button>
                <button type="button" class="button postycal-delete-schedule" data-index="<?php echo esc_attr( (string) $index ); ?>">
                    <?php esc_html_e( 'Delete', 'postycal' ); ?>
                </button>
            </td>
        </tr>
        <?php
    }

    /**
     * Render the add/edit schedule modal.
     *
     * @return void
     */
    private function render_schedule_modal(): void {
        ?>
        <div id="postycal-modal" class="postycal-modal" style="display: none;">
            <div class="postycal-modal-backdrop"></div>
            <div class="postycal-modal-content">
                <h2 id="postycal-modal-title"><?php esc_html_e( 'Add New Schedule', 'postycal' ); ?></h2>

                <form id="postycal-schedule-form">
                    <input type="hidden" id="postycal-schedule-index" name="index" value="">

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="postycal-name"><?php esc_html_e( 'Schedule Name', 'postycal' ); ?></label>
                            </th>
                            <td>
                                <input type="text" id="postycal-name" name="name" class="regular-text" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="postycal-post-type"><?php esc_html_e( 'Post Type', 'postycal' ); ?></label>
                            </th>
                            <td>
                                <select id="postycal-post-type" name="post_type" required>
                                    <option value=""><?php esc_html_e( 'Select Post Type', 'postycal' ); ?></option>
                                    <?php foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $post_type ) : ?>
                                        <option value="<?php echo esc_attr( $post_type->name ); ?>">
                                            <?php echo esc_html( $post_type->label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="postycal-taxonomy"><?php esc_html_e( 'Taxonomy', 'postycal' ); ?></label>
                            </th>
                            <td>
                                <select id="postycal-taxonomy" name="taxonomy" required>
                                    <option value=""><?php esc_html_e( 'Select Taxonomy', 'postycal' ); ?></option>
                                    <?php foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $taxonomy ) : ?>
                                        <option value="<?php echo esc_attr( $taxonomy->name ); ?>">
                                            <?php echo esc_html( $taxonomy->label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Select a taxonomy to load its terms below.', 'postycal' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="postycal-upcoming-term"><?php esc_html_e( 'Upcoming Term', 'postycal' ); ?></label>
                            </th>
                            <td>
                                <select id="postycal-upcoming-term" name="upcoming_term" required>
                                    <option value=""><?php esc_html_e( 'Select Taxonomy first', 'postycal' ); ?></option>
                                </select>
                                <span class="spinner" id="postycal-terms-spinner"></span>
                                <p class="description"><?php esc_html_e( 'Assigned while the post is a draft awaiting its go-live date.', 'postycal' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="postycal-active-term"><?php esc_html_e( 'Active Term', 'postycal' ); ?></label>
                            </th>
                            <td>
                                <select id="postycal-active-term" name="active_term" required>
                                    <option value=""><?php esc_html_e( 'Select Taxonomy first', 'postycal' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'Assigned when the post is published (between go-live and expiration dates).', 'postycal' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="postycal-past-term"><?php esc_html_e( 'Past Term', 'postycal' ); ?></label>
                            </th>
                            <td>
                                <select id="postycal-past-term" name="past_term" required>
                                    <option value=""><?php esc_html_e( 'Select Taxonomy first', 'postycal' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'Assigned when the post is set to private after expiration.', 'postycal' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <?php esc_html_e( 'Time-Aware Transitions', 'postycal' ); ?>
                            </th>
                            <td>
                                <label for="postycal-use-time">
                                    <input type="checkbox" id="postycal-use-time" name="use_time" value="1">
                                    <?php esc_html_e( 'Use time component for transitions', 'postycal' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'When enabled, the meta box shows datetime pickers and transitions happen at the exact time specified. When disabled, transitions happen at midnight on the given date.', 'postycal' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e( 'Save Schedule', 'postycal' ); ?>
                        </button>
                        <button type="button" class="button" id="postycal-cancel">
                            <?php esc_html_e( 'Cancel', 'postycal' ); ?>
                        </button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Meta boxes
    // -------------------------------------------------------------------------

    /**
     * Register one meta box per post type that has an active schedule.
     *
     * Multiple schedules targeting the same post type are all rendered
     * inside a single meta box to avoid cluttering the sidebar.
     *
     * @return void
     */
    public function register_meta_boxes(): void {
        $schedules        = $this->schedule_manager->get_all();
        $registered_types = [];

        foreach ( $schedules as $schedule ) {
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

    /**
     * Render the Publication Schedule meta box.
     *
     * @param \WP_Post $post The current post.
     * @return void
     */
    public function render_meta_box( \WP_Post $post ): void {
        $schedules = $this->schedule_manager->get_for_post_type( $post->post_type );

        foreach ( $schedules as $schedule ) {
            $this->render_schedule_date_fields( $post, $schedule );
        }
    }

    /**
     * Render the go-live and expiration date inputs for one schedule.
     *
     * @param \WP_Post $post     The current post.
     * @param Schedule $schedule The schedule configuration.
     * @return void
     */
    private function render_schedule_date_fields( \WP_Post $post, Schedule $schedule ): void {
        $go_live    = get_post_meta( $post->ID, $schedule->get_go_live_meta_key(), true );
        $expiration = get_post_meta( $post->ID, $schedule->get_expiration_meta_key(), true );
        $input_type = $schedule->use_time ? 'datetime-local' : 'date';
        $key        = $schedule->schedule_key;

        wp_nonce_field( 'postycal_save_' . $key, 'postycal_nonce_' . $key );

        $multiple = count( $this->schedule_manager->get_for_post_type( $post->post_type ) ) > 1;

        if ( $multiple ) {
            echo '<h4 style="margin: 0 0 8px;">' . esc_html( $schedule->name ) . '</h4>';
        }
        ?>
        <p>
            <label for="postycal_go_live_<?php echo esc_attr( $key ); ?>">
                <strong><?php esc_html_e( 'Go-Live Date', 'postycal' ); ?></strong>
            </label><br>
            <input
                type="<?php echo esc_attr( $input_type ); ?>"
                id="postycal_go_live_<?php echo esc_attr( $key ); ?>"
                name="postycal_go_live_<?php echo esc_attr( $key ); ?>"
                value="<?php echo esc_attr( $go_live ); ?>"
                class="widefat postycal-date-field"
                required
            >
        </p>
        <p>
            <label for="postycal_expiration_<?php echo esc_attr( $key ); ?>">
                <strong><?php esc_html_e( 'Expiration Date', 'postycal' ); ?></strong>
            </label><br>
            <input
                type="<?php echo esc_attr( $input_type ); ?>"
                id="postycal_expiration_<?php echo esc_attr( $key ); ?>"
                name="postycal_expiration_<?php echo esc_attr( $key ); ?>"
                value="<?php echo esc_attr( $expiration ); ?>"
                class="widefat postycal-date-field"
                required
            >
        </p>
        <?php
    }

    /**
     * Save meta box date fields when a post is saved.
     *
     * Runs at save_post priority 10, before the term-assignment hook at 20.
     *
     * @param int $post_id The post ID.
     * @return void
     */
    public function save_meta_box_data( int $post_id ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $post_type = get_post_type( $post_id );
        $schedules = $this->schedule_manager->get_for_post_type( $post_type );

        if ( empty( $schedules ) ) {
            return;
        }

        foreach ( $schedules as $schedule ) {
            $key        = $schedule->schedule_key;
            $nonce_name = 'postycal_nonce_' . $key;

            if ( ! isset( $_POST[ $nonce_name ] ) ) {
                continue;
            }

            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_name ] ) ), 'postycal_save_' . $key ) ) {
                continue;
            }

            $go_live_field = 'postycal_go_live_' . $key;
            $exp_field     = 'postycal_expiration_' . $key;

            if ( isset( $_POST[ $go_live_field ] ) ) {
                update_post_meta(
                    $post_id,
                    $schedule->get_go_live_meta_key(),
                    sanitize_text_field( wp_unslash( $_POST[ $go_live_field ] ) )
                );
            }

            if ( isset( $_POST[ $exp_field ] ) ) {
                update_post_meta(
                    $post_id,
                    $schedule->get_expiration_meta_key(),
                    sanitize_text_field( wp_unslash( $_POST[ $exp_field ] ) )
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Post editor helpers
    // -------------------------------------------------------------------------

    /**
     * Inject CSS to hide WordPress's built-in publish-date UI on post types
     * managed by PostyCal, to avoid confusion with the PostyCal date fields.
     *
     * @return void
     */
    public function maybe_hide_publish_date(): void {
        global $post, $pagenow;

        if ( ! in_array( $pagenow, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        $schedules = $this->schedule_manager->get_for_post_type( $post->post_type );

        if ( empty( $schedules ) ) {
            return;
        }

        echo '<style>.misc-pub-publishdate,.misc-pub-curtime{display:none!important;}</style>';
    }

    /**
     * Display an admin notice when a post is missing its required dates.
     *
     * Only shown on existing (non-auto-draft) posts of a managed type.
     *
     * @return void
     */
    public function display_missing_dates_notice(): void {
        global $post, $pagenow;

        if ( ! in_array( $pagenow, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        if ( ! $post instanceof \WP_Post || $post->ID <= 0 ) {
            return;
        }

        if ( 'auto-draft' === $post->post_status ) {
            return;
        }

        $schedules = $this->schedule_manager->get_for_post_type( $post->post_type );

        if ( empty( $schedules ) ) {
            return;
        }

        foreach ( $schedules as $schedule ) {
            $go_live    = get_post_meta( $post->ID, $schedule->get_go_live_meta_key(), true );
            $expiration = get_post_meta( $post->ID, $schedule->get_expiration_meta_key(), true );

            if ( empty( $go_live ) || empty( $expiration ) ) {
                ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php esc_html_e( 'PostyCal:', 'postycal' ); ?></strong>
                        <?php esc_html_e( 'This post is missing a go-live or expiration date. It will not be published automatically until both dates are set.', 'postycal' ); ?>
                    </p>
                </div>
                <?php
                return;
            }
        }
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    /**
     * AJAX handler: save (add or update) a schedule.
     *
     * @return void
     */
    public function ajax_save_schedule(): void {
        $this->verify_ajax_request();

        $data = $this->sanitize_schedule_data( $_POST );

        $required = [ 'name', 'post_type', 'taxonomy', 'upcoming_term', 'active_term', 'past_term' ];
        foreach ( $required as $field ) {
            if ( empty( $data[ $field ] ) ) {
                wp_send_json_error( [
                    'message' => sprintf(
                        /* translators: %s: field name */
                        __( 'Required field missing: %s', 'postycal' ),
                        $field
                    ),
                ] );
            }
        }

        $index = isset( $_POST['index'] ) && '' !== $_POST['index'] ? absint( $_POST['index'] ) : null;

        if ( null !== $index ) {
            // Preserve the existing schedule_key so meta keys remain stable.
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

        wp_send_json_success( [
            'message'   => __( 'Schedule saved successfully.', 'postycal' ),
            'schedules' => $this->schedule_manager->export(),
        ] );
    }

    /**
     * AJAX handler: delete a schedule.
     *
     * @return void
     */
    public function ajax_delete_schedule(): void {
        $this->verify_ajax_request();

        if ( ! isset( $_POST['index'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid schedule index.', 'postycal' ) ] );
        }

        $index  = absint( $_POST['index'] );
        $result = $this->schedule_manager->delete( $index );

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( 'Failed to delete schedule.', 'postycal' ) ] );
        }

        wp_send_json_success( [
            'message'   => __( 'Schedule deleted successfully.', 'postycal' ),
            'schedules' => $this->schedule_manager->export(),
        ] );
    }

    /**
     * AJAX handler: manually trigger all schedule transitions.
     *
     * @return void
     */
    public function ajax_trigger_cron(): void {
        $this->verify_ajax_request();

        $results = Core::get_instance()->get_cron_handler()->trigger_manual_run();

        wp_send_json_success( [
            'message' => __( 'Schedules processed successfully.', 'postycal' ),
            'results' => $results,
        ] );
    }

    /**
     * AJAX handler: return terms for a taxonomy.
     *
     * @return void
     */
    public function ajax_get_taxonomy_terms(): void {
        $this->verify_ajax_request();

        if ( ! isset( $_POST['taxonomy'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Taxonomy is required.', 'postycal' ) ] );
        }

        $taxonomy = sanitize_key( wp_unslash( $_POST['taxonomy'] ) );

        if ( ! taxonomy_exists( $taxonomy ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid taxonomy.', 'postycal' ) ] );
        }

        $terms = get_terms( [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ] );

        if ( is_wp_error( $terms ) ) {
            wp_send_json_error( [ 'message' => __( 'Error loading terms.', 'postycal' ) ] );
        }

        $term_data = array_map(
            fn( \WP_Term $term ): array => [
                'slug' => $term->slug,
                'name' => $term->name,
            ],
            $terms
        );

        wp_send_json_success( [ 'terms' => $term_data ] );
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    /**
     * Verify nonce and capability for an AJAX request.
     *
     * Sends a JSON error and exits on failure.
     *
     * @return void
     */
    private function verify_ajax_request(): void {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'postycal' ) ], 403 );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'postycal' ) ], 403 );
        }
    }

    /**
     * Sanitize and normalise schedule data from a POST request.
     *
     * @param array<string, mixed> $post Raw POST data.
     * @return array<string, string|bool>
     */
    private function sanitize_schedule_data( array $post ): array {
        return [
            'name'          => isset( $post['name'] ) ? sanitize_text_field( wp_unslash( $post['name'] ) ) : '',
            'post_type'     => isset( $post['post_type'] ) ? sanitize_key( wp_unslash( $post['post_type'] ) ) : '',
            'taxonomy'      => isset( $post['taxonomy'] ) ? sanitize_key( wp_unslash( $post['taxonomy'] ) ) : '',
            'upcoming_term' => isset( $post['upcoming_term'] ) ? sanitize_title( wp_unslash( $post['upcoming_term'] ) ) : '',
            'active_term'   => isset( $post['active_term'] ) ? sanitize_title( wp_unslash( $post['active_term'] ) ) : '',
            'past_term'     => isset( $post['past_term'] ) ? sanitize_title( wp_unslash( $post['past_term'] ) ) : '',
            'use_time'      => ! empty( $post['use_time'] ),
        ];
    }
}
