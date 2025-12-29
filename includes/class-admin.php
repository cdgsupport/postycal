<?php
/**
 * Admin Class
 *
 * Handles admin functionality, settings page, and AJAX handlers.
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
     * Nonce action name.
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
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_notices', [ $this, 'display_date_field_warning' ] );

        // AJAX handlers for schedule management.
        add_action( 'wp_ajax_postycal_save_schedule', [ $this, 'ajax_save_schedule' ] );
        add_action( 'wp_ajax_postycal_delete_schedule', [ $this, 'ajax_delete_schedule' ] );
        add_action( 'wp_ajax_postycal_trigger_cron', [ $this, 'ajax_trigger_cron' ] );

        // AJAX handlers for dynamic field loading.
        add_action( 'wp_ajax_postycal_get_acf_fields', [ $this, 'ajax_get_acf_fields' ] );
        add_action( 'wp_ajax_postycal_get_taxonomy_terms', [ $this, 'ajax_get_taxonomy_terms' ] );
    }

    /**
     * Add admin menu pages.
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
     * Enqueue admin assets.
     *
     * @param string $hook The current admin page hook.
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
     * Get internationalized strings for JavaScript.
     *
     * @return array<string, string>
     */
    private function get_i18n_strings(): array {
        return [
            'confirmDelete'        => __( 'Are you sure you want to delete this schedule?', 'postycal' ),
            'saveError'            => __( 'Error saving schedule. Please try again.', 'postycal' ),
            'deleteError'          => __( 'Error deleting schedule. Please try again.', 'postycal' ),
            'triggerSuccess'       => __( 'Schedules processed successfully.', 'postycal' ),
            'triggerError'         => __( 'Error processing schedules. Please try again.', 'postycal' ),
            'addSchedule'          => __( 'Add New Schedule', 'postycal' ),
            'editSchedule'         => __( 'Edit Schedule', 'postycal' ),
            'processing'           => __( 'Processing...', 'postycal' ),
            // Dynamic dropdown strings.
            'selectPostTypeFirst'  => __( 'Select Post Type first', 'postycal' ),
            'selectTaxonomyFirst'  => __( 'Select Taxonomy first', 'postycal' ),
            'selectRepeaterFirst'  => __( 'Select a repeater field first', 'postycal' ),
            'selectDateField'      => __( 'Select a date field', 'postycal' ),
            'selectSubField'       => __( 'Select a sub-field', 'postycal' ),
            'selectTerm'           => __( 'Select a term', 'postycal' ),
            'loading'              => __( 'Loading...', 'postycal' ),
            'noFieldsFound'        => __( 'No date fields found', 'postycal' ),
            'noTermsFound'         => __( 'No terms found', 'postycal' ),
            'errorLoadingFields'   => __( 'Error loading fields', 'postycal' ),
            'errorLoadingTerms'    => __( 'Error loading terms', 'postycal' ),
            'dateFields'           => __( 'Date Fields', 'postycal' ),
            'repeaterFields'       => __( 'Repeater Fields (with date sub-fields)', 'postycal' ),
        ];
    }

    /**
     * Display warning if post has empty date field.
     *
     * @return void
     */
    public function display_date_field_warning(): void {
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

        foreach ( $schedules as $schedule ) {
            $date = Date_Handler::get_post_date( $post->ID, $schedule );

            if ( null === $date ) {
                $this->render_warning_notice();
                return;
            }
        }
    }

    /**
     * Render warning notice for missing date field.
     *
     * @return void
     */
    private function render_warning_notice(): void {
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e( 'PostyCal Warning:', 'postycal' ); ?></strong>
                <?php esc_html_e( 'No date is set. This post may not be visible to viewers of your site until a date is assigned.', 'postycal' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * AJAX handler for saving a schedule.
     *
     * @return void
     */
    public function ajax_save_schedule(): void {
        $this->verify_ajax_request();

        $data = $this->sanitize_schedule_data( $_POST );

        if ( empty( $data ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid schedule data.', 'postycal' ) ] );
        }

        $index = isset( $_POST['index'] ) && '' !== $_POST['index'] ? absint( $_POST['index'] ) : null;

        if ( null !== $index ) {
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
     * AJAX handler for deleting a schedule.
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
     * AJAX handler for manually triggering cron.
     *
     * @return void
     */
    public function ajax_trigger_cron(): void {
        $this->verify_ajax_request();

        $cron_handler = Core::get_instance()->get_cron_handler();
        $results      = $cron_handler->trigger_manual_run();

        wp_send_json_success( [
            'message' => __( 'Schedules processed successfully.', 'postycal' ),
            'results' => $results,
        ] );
    }

    /**
     * AJAX handler for getting ACF fields for a post type.
     *
     * @return void
     */
    public function ajax_get_acf_fields(): void {
        $this->verify_ajax_request();

        if ( ! isset( $_POST['post_type'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Post type is required.', 'postycal' ) ] );
        }

        $post_type = sanitize_key( wp_unslash( $_POST['post_type'] ) );

        if ( ! post_type_exists( $post_type ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post type.', 'postycal' ) ] );
        }

        $fields = $this->get_acf_date_fields_for_post_type( $post_type );

        wp_send_json_success( [
            'fields' => $fields,
        ] );
    }

    /**
     * AJAX handler for getting taxonomy terms.
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

        $terms = $this->get_taxonomy_terms( $taxonomy );

        wp_send_json_success( [
            'terms' => $terms,
        ] );
    }

    /**
     * Get ACF date fields for a post type.
     *
     * @param string $post_type The post type.
     * @return array<int, array<string, string>> Array of field data.
     */
    private function get_acf_date_fields_for_post_type( string $post_type ): array {
        $fields = [];

        // Check if ACF is active.
        if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
            return $fields;
        }

        // Get all field groups for this post type.
        $field_groups = acf_get_field_groups( [
            'post_type' => $post_type,
        ] );

        foreach ( $field_groups as $group ) {
            $group_fields = acf_get_fields( $group['key'] );

            if ( ! is_array( $group_fields ) ) {
                continue;
            }

            foreach ( $group_fields as $field ) {
                $this->extract_date_fields( $field, $fields, $group['title'] );
            }
        }

        return $fields;
    }

    /**
     * Extract date fields from ACF field (handles nested/repeater fields).
     *
     * @param array<string, mixed>               $field      The ACF field.
     * @param array<int, array<string, string>> &$fields    Reference to fields array.
     * @param string                             $group_name The field group name.
     * @param string                             $prefix     The field name prefix for nested fields.
     * @return void
     */
    private function extract_date_fields( array $field, array &$fields, string $group_name, string $prefix = '' ): void {
        $field_name = $prefix ? $prefix . '_' . $field['name'] : $field['name'];
        $field_type = $field['type'] ?? '';

        // Date and date/time picker fields.
        if ( in_array( $field_type, [ 'date_picker', 'date_time_picker' ], true ) ) {
            $fields[] = [
                'name'       => $field['name'],
                'label'      => $field['label'] ?? $field['name'],
                'type'       => $field_type,
                'group'      => $group_name,
                'full_name'  => $field_name,
                'is_subfield' => ! empty( $prefix ),
                'parent'     => $prefix,
            ];
        }

        // Repeater fields - look for date fields inside.
        if ( 'repeater' === $field_type && ! empty( $field['sub_fields'] ) ) {
            $fields[] = [
                'name'        => $field['name'],
                'label'       => $field['label'] ?? $field['name'],
                'type'        => 'repeater',
                'group'       => $group_name,
                'full_name'   => $field_name,
                'is_subfield' => false,
                'parent'      => '',
                'sub_fields'  => $this->get_repeater_date_subfields( $field['sub_fields'] ),
            ];
        }

        // Flexible content and groups - recursively check for date fields.
        if ( in_array( $field_type, [ 'group', 'flexible_content' ], true ) ) {
            $sub_fields = $field['sub_fields'] ?? $field['layouts'] ?? [];
            foreach ( $sub_fields as $sub_field ) {
                if ( isset( $sub_field['sub_fields'] ) ) {
                    // Layout in flexible content.
                    foreach ( $sub_field['sub_fields'] as $layout_field ) {
                        $this->extract_date_fields( $layout_field, $fields, $group_name, $field['name'] );
                    }
                } else {
                    $this->extract_date_fields( $sub_field, $fields, $group_name, $field['name'] );
                }
            }
        }
    }

    /**
     * Get date subfields from a repeater field.
     *
     * @param array<int, array<string, mixed>> $sub_fields The repeater's sub fields.
     * @return array<int, array<string, string>> Array of date subfield data.
     */
    private function get_repeater_date_subfields( array $sub_fields ): array {
        $date_subfields = [];

        foreach ( $sub_fields as $sub_field ) {
            $field_type = $sub_field['type'] ?? '';

            if ( in_array( $field_type, [ 'date_picker', 'date_time_picker' ], true ) ) {
                $date_subfields[] = [
                    'name'  => $sub_field['name'],
                    'label' => $sub_field['label'] ?? $sub_field['name'],
                    'type'  => $field_type,
                ];
            }
        }

        return $date_subfields;
    }

    /**
     * Get terms for a taxonomy.
     *
     * @param string $taxonomy The taxonomy name.
     * @return array<int, array<string, string>> Array of term data.
     */
    private function get_taxonomy_terms( string $taxonomy ): array {
        $terms = get_terms( [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return [];
        }

        $term_data = [];

        foreach ( $terms as $term ) {
            $term_data[] = [
                'slug' => $term->slug,
                'name' => $term->name,
                'id'   => $term->term_id,
            ];
        }

        return $term_data;
    }

    /**
     * Verify AJAX request.
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
     * Sanitize schedule data from POST.
     *
     * @param array<string, mixed> $post POST data.
     * @return array<string, string|bool> Sanitized schedule data.
     */
    private function sanitize_schedule_data( array $post ): array {
        return [
            'name'          => isset( $post['name'] ) ? sanitize_text_field( wp_unslash( $post['name'] ) ) : '',
            'post_type'     => isset( $post['post_type'] ) ? sanitize_key( wp_unslash( $post['post_type'] ) ) : '',
            'taxonomy'      => isset( $post['taxonomy'] ) ? sanitize_key( wp_unslash( $post['taxonomy'] ) ) : '',
            'date_field'    => isset( $post['date_field'] ) ? sanitize_text_field( wp_unslash( $post['date_field'] ) ) : '',
            'field_type'    => isset( $post['field_type'] ) ? sanitize_text_field( wp_unslash( $post['field_type'] ) ) : 'single',
            'sub_field'     => isset( $post['sub_field'] ) ? sanitize_text_field( wp_unslash( $post['sub_field'] ) ) : '',
            'date_logic'    => isset( $post['date_logic'] ) ? sanitize_text_field( wp_unslash( $post['date_logic'] ) ) : 'earliest',
            'upcoming_term' => isset( $post['upcoming_term'] ) ? sanitize_text_field( wp_unslash( $post['upcoming_term'] ) ) : '',
            'past_term'     => isset( $post['past_term'] ) ? sanitize_text_field( wp_unslash( $post['past_term'] ) ) : '',
            'use_time'      => ! empty( $post['use_time'] ),
        ];
    }

    /**
     * Render settings page.
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

            <?php $this->render_acf_notice(); ?>
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
     * Render ACF notice if not active.
     *
     * @return void
     */
    private function render_acf_notice(): void {
        if ( function_exists( 'get_field' ) ) {
            return;
        }
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e( 'Notice:', 'postycal' ); ?></strong>
                <?php esc_html_e( 'Advanced Custom Fields (ACF) is not active. ACF is required for PostyCal to function.', 'postycal' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render info box.
     *
     * @return void
     */
    private function render_info_box(): void {
        ?>
        <div class="postycal-info-box">
            <h2><?php esc_html_e( 'How PostyCal Works', 'postycal' ); ?></h2>
            <p><?php esc_html_e( 'PostyCal automatically transitions posts between two taxonomy terms based on a date field:', 'postycal' ); ?></p>
            <ol>
                <li><strong><?php esc_html_e( 'Pre-Date Category:', 'postycal' ); ?></strong> <?php esc_html_e( 'Posts are assigned this category when their date is in the future.', 'postycal' ); ?></li>
                <li><strong><?php esc_html_e( 'Post-Date Category:', 'postycal' ); ?></strong> <?php esc_html_e( 'Posts automatically move to this category after their date has passed.', 'postycal' ); ?></li>
            </ol>
            <p><?php esc_html_e( 'The plugin checks dates in two ways:', 'postycal' ); ?></p>
            <ul>
                <li><?php esc_html_e( "When a post is saved, it's immediately assigned to the correct category based on its date.", 'postycal' ); ?></li>
                <li><?php esc_html_e( 'A daily automated check moves posts from Pre-Date to Post-Date category when their date passes.', 'postycal' ); ?></li>
            </ul>
            <p><strong><?php esc_html_e( 'Repeater Support:', 'postycal' ); ?></strong> <?php esc_html_e( 'The plugin can handle ACF Repeater fields with multiple dates. You can choose to transition based on the earliest date, latest date, or when any date has passed.', 'postycal' ); ?></p>
        </div>
        <?php
    }

    /**
     * Render schedules table.
     *
     * @param array<Schedule> $schedules Array of schedules.
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
                    <th scope="col"><?php esc_html_e( 'Date Field', 'postycal' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Field Type', 'postycal' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Pre-Date Term', 'postycal' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Post-Date Term', 'postycal' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Actions', 'postycal' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $schedules ) ) : ?>
                    <tr class="no-items">
                        <td colspan="8">
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
     * Render a single schedule row.
     *
     * @param Schedule $schedule The schedule.
     * @param int      $index    The schedule index.
     * @return void
     */
    private function render_schedule_row( Schedule $schedule, int $index ): void {
        $field_type_label = $schedule->is_repeater()
            ? sprintf(
                /* translators: %s: date logic type */
                __( 'Repeater (%s)', 'postycal' ),
                $schedule->date_logic
            )
            : __( 'Single', 'postycal' );

        // Add time indicator if enabled.
        if ( $schedule->use_time ) {
            $field_type_label .= ' ' . __( '+ Time', 'postycal' );
        }
        ?>
        <tr data-index="<?php echo esc_attr( (string) $index ); ?>">
            <td><?php echo esc_html( $schedule->name ); ?></td>
            <td><?php echo esc_html( $schedule->post_type ); ?></td>
            <td><?php echo esc_html( $schedule->taxonomy ); ?></td>
            <td><?php echo esc_html( $schedule->date_field ); ?></td>
            <td><?php echo esc_html( $field_type_label ); ?></td>
            <td><?php echo esc_html( $schedule->upcoming_term ); ?></td>
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
     * Render schedule modal.
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
                                <p class="description"><?php esc_html_e( 'Select a post type to load available ACF date fields.', 'postycal' ); ?></p>
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
                                <p class="description"><?php esc_html_e( 'Select a taxonomy to load available terms.', 'postycal' ); ?></p>
                            </td>
                        </tr>
                        <tr id="postycal-date-field-row">
                            <th scope="row">
                                <label for="postycal-date-field"><?php esc_html_e( 'ACF Date Field', 'postycal' ); ?></label>
                            </th>
                            <td>
                                <select id="postycal-date-field" name="date_field" required>
                                    <option value=""><?php esc_html_e( 'Select Post Type first', 'postycal' ); ?></option>
                                </select>
                                <span class="spinner" id="postycal-fields-spinner"></span>
                                <p class="description" id="postycal-date-field-desc"><?php esc_html_e( 'Select the ACF date or date/time field to use.', 'postycal' ); ?></p>
                                <p class="description postycal-manual-entry" style="display: none;">
                                    <label>
                                        <input type="checkbox" id="postycal-manual-field-entry">
                                        <?php esc_html_e( 'Enter field name manually', 'postycal' ); ?>
                                    </label>
                                </p>
                                <input type="text" id="postycal-date-field-manual" name="date_field_manual" class="regular-text" style="display: none;" placeholder="<?php esc_attr_e( 'Enter ACF field name', 'postycal' ); ?>">
                            </td>
                        </tr>
                        <tr id="postycal-sub-field-row" style="display: none;">
                            <th scope="row">
                                <label for="postycal-sub-field"><?php esc_html_e( 'Date Sub-field', 'postycal' ); ?></label>
                            </th>
                            <td>
                                <select id="postycal-sub-field" name="sub_field">
                                    <option value=""><?php esc_html_e( 'Select a repeater field first', 'postycal' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'Select the date field within the repeater.', 'postycal' ); ?></p>
                                <input type="text" id="postycal-sub-field-manual" name="sub_field_manual" class="regular-text" style="display: none;" placeholder="<?php esc_attr_e( 'Enter sub-field name', 'postycal' ); ?>">
                            </td>
                        </tr>
                        <tr id="postycal-date-logic-row" style="display: none;">
                            <th scope="row">
                                <label for="postycal-date-logic"><?php esc_html_e( 'Date Logic', 'postycal' ); ?></label>
                            </th>
                            <td>
                                <select id="postycal-date-logic" name="date_logic">
                                    <option value="earliest"><?php esc_html_e( 'Use earliest date', 'postycal' ); ?></option>
                                    <option value="latest"><?php esc_html_e( 'Use latest date', 'postycal' ); ?></option>
                                    <option value="any_past"><?php esc_html_e( 'Transition when any date has passed', 'postycal' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'How to handle multiple dates in the repeater.', 'postycal' ); ?></p>
                            </td>
                        </tr>
                        <tr id="postycal-upcoming-term-row">
                            <th scope="row">
                                <label for="postycal-upcoming-term"><?php esc_html_e( 'Pre-Date Category', 'postycal' ); ?></label>
                            </th>
                            <td>
                                <select id="postycal-upcoming-term" name="upcoming_term" required>
                                    <option value=""><?php esc_html_e( 'Select Taxonomy first', 'postycal' ); ?></option>
                                </select>
                                <span class="spinner" id="postycal-terms-spinner"></span>
                                <p class="description"><?php esc_html_e( 'Posts will be assigned to this term when their date is in the future.', 'postycal' ); ?></p>
                            </td>
                        </tr>
                        <tr id="postycal-past-term-row">
                            <th scope="row">
                                <label for="postycal-past-term"><?php esc_html_e( 'Post-Date Category', 'postycal' ); ?></label>
                            </th>
                            <td>
                                <select id="postycal-past-term" name="past_term" required>
                                    <option value=""><?php esc_html_e( 'Select Taxonomy first', 'postycal' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'Posts will be moved to this term after their date has passed.', 'postycal' ); ?></p>
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
                                    <?php esc_html_e( 'When enabled, posts transition immediately when the datetime passes. When disabled, posts transition at midnight after the date (with a 24-hour buffer).', 'postycal' ); ?>
                                </p>
                                <p class="description">
                                    <strong><?php esc_html_e( 'Tip:', 'postycal' ); ?></strong>
                                    <?php esc_html_e( 'Enable this when using ACF Date/Time Picker fields. Leave disabled for Date Picker fields.', 'postycal' ); ?>
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
}
