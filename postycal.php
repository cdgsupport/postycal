<?php
/**
 * Plugin Name: PostyCal
 * Plugin URI: https://crawforddesigngp.com/postycal
 * Description: Automatically manages post category transitions based on date fields
 * Version: 1.5.0
 * Author: Crawford Design Group
 * Author URI: https://crawforddesigngp.com.com/
 * License: GPL v2 or later
 * Text Domain: postycal
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants
define('PC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PC_PLUGIN_PATH', plugin_dir_path(__FILE__));

/**
 * Main plugin class
 */
class PostyCal {
	
	private $schedules;
	
	public function __construct() {
		$this->schedules = get_option('pc_schedules', array());
		
		// Hook into WordPress
		add_action('init', array($this, 'init'));
		add_action('admin_menu', array($this, 'add_admin_menu'));
		add_action('admin_init', array($this, 'admin_init'));
		add_action('wp_ajax_pc_save_schedule', array($this, 'ajax_save_schedule'));
		add_action('wp_ajax_pc_delete_schedule', array($this, 'ajax_delete_schedule'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
		
		// Initialize functionality for all schedules
		if (!empty($this->schedules)) {
			add_action('init', array($this, 'initialize_cron'));
			add_action('acf/save_post', array($this, 'set_initial_category'), 20);
			add_action('pc_daily_category_check', array($this, 'check_all_schedules'));
			add_action('admin_notices', array($this, 'check_date_fields_notice'));
		}
	}
	
	/**
	 * Initialize the plugin
	 */
	public function init() {
		// Load plugin textdomain for translations
		load_plugin_textdomain('postycal', false, dirname(plugin_basename(__FILE__)) . '/languages/');
	}
	
	/**
	 * Enqueue admin scripts
	 */
	public function enqueue_admin_scripts($hook) {
		if ($hook !== 'settings_page_pc-settings' && $hook !== 'tools_page_pc-trigger-cron') {
			return;
		}
		
		wp_enqueue_script('pc-admin', PC_PLUGIN_URL . 'admin.js', array('jquery'), '1.0.0', true);
		wp_localize_script('pc-admin', 'pc_ajax', array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('pc_nonce')
		));
	}
	
	/**
	 * Initialize admin
	 */
	public function admin_init() {
		// Register settings
		register_setting('pc_settings_group', 'pc_schedules');
	}
	
	/**
	 * Initialize the cron job
	 */
	public function initialize_cron() {
		if (!wp_next_scheduled('pc_daily_category_check')) {
			wp_schedule_event(strtotime('tomorrow midnight'), 'daily', 'pc_daily_category_check');
		}
	}
	
	/**
	 * Check if current post has empty date fields and show warning
	 */
	public function check_date_fields_notice() {
		global $post, $pagenow;
		
		// Only show on post edit screens
		if (!in_array($pagenow, array('post.php', 'post-new.php'))) {
			return;
		}
		
		if (!$post) {
			return;
		}
		
		$post_type = get_post_type($post);
		$has_warning = false;
		
		// Check each schedule
		foreach ($this->schedules as $schedule) {
			if ($post_type !== $schedule['post_type']) {
				continue;
			}
			
			$post_date = $this->get_post_date($post->ID, $schedule);
			
			if (!$post_date) {
				$has_warning = true;
				break;
			}
		}
		
		if ($has_warning) {
			?>
			<div class="notice notice-warning">
				<p><strong><?php _e('Warning!', 'postycal'); ?></strong></p>
				<p><?php _e('If no date is set, post may not be visible to viewers of your site.', 'postycal'); ?></p>
			</div>
			<?php
		}
	}
	
	/**
	 * Get field value from ACF
	 */
	private function get_field_value($field_name, $post_id) {
		if (function_exists('get_field')) {
			return get_field($field_name, $post_id);
		}
		
		return false;
	}
	
	/**
	 * Get the relevant date from a post based on schedule settings
	 */
	private function get_post_date($post_id, $schedule) {
		$field_type = isset($schedule['field_type']) ? $schedule['field_type'] : 'single';
		
		if ($field_type === 'single') {
			// Single date field
			return $this->get_field_value($schedule['date_field'], $post_id);
		} else {
			// Repeater field
			if (!function_exists('get_field')) {
				return false;
			}
			
			$repeater = get_field($schedule['date_field'], $post_id);
			if (!$repeater || !is_array($repeater)) {
				return false;
			}
			
			$dates = array();
			$sub_field = $schedule['sub_field'];
			
			foreach ($repeater as $row) {
				if (isset($row[$sub_field]) && !empty($row[$sub_field])) {
					$unix_date = strtotime($row[$sub_field]);
					if ($unix_date !== false) {
						$dates[] = $unix_date;
					}
				}
			}
			
			if (empty($dates)) {
				return false;
			}
			
			$date_logic = isset($schedule['date_logic']) ? $schedule['date_logic'] : 'earliest';
			
			switch ($date_logic) {
				case 'latest':
					$selected_date = max($dates);
					break;
				case 'any_past':
					// Check if any date has passed
					$current_time = current_time('timestamp', true);
					$current_date = gmdate('Ymd', $current_time);
					$unix_current_date = strtotime($current_date);
					
					foreach ($dates as $date) {
						$gmt_date = gmdate('Ymd', $date);
						$unix_gmt_date = strtotime($gmt_date);
						if ($unix_gmt_date < $unix_current_date) {
							return date('Y-m-d', $date);
						}
					}
					// If no past dates, use earliest future date
					$selected_date = min($dates);
					break;
				case 'earliest':
				default:
					$selected_date = min($dates);
					break;
			}
			
			return date('Y-m-d', $selected_date);
		}
	}
	
	/**
	 * Set initial category when post is saved
	 */
	public function set_initial_category($post_id) {
		$post_type = get_post_type($post_id);
		
		// Check each schedule
		foreach ($this->schedules as $schedule) {
			if ($post_type !== $schedule['post_type']) {
				continue;
			}
			
			$post_date = $this->get_post_date($post_id, $schedule);
			
			if (!$post_date) {
				continue;
			}
			
			// Verify the taxonomy exists
			if (!taxonomy_exists($schedule['taxonomy'])) {
				continue;
			}
			
			// Convert post date to the correct format
			$unix_post_date = strtotime($post_date);
			if ($unix_post_date === false) {
				continue;
			}
			
			$gmt_post_date = gmdate('Ymd', $unix_post_date);
			$unix_gmt_post_date = strtotime($gmt_post_date);
			
			// Get current time
			$current_time = current_time('timestamp', true);
			$current_date = gmdate('Ymd', $current_time);
			$unix_current_date = strtotime($current_date);
			
			$taxonomy = $schedule['taxonomy'];
			$upcoming_term = $schedule['upcoming_term'];
			$past_term = $schedule['past_term'];
			
			// Set appropriate category
			if ($unix_gmt_post_date < $unix_current_date) {
				// Remove all terms first, then set only the past term
				wp_set_object_terms($post_id, array(), $taxonomy);
				wp_set_object_terms($post_id, $past_term, $taxonomy, false);
			} else {
				// Remove all terms first, then set only the upcoming term
				wp_set_object_terms($post_id, array(), $taxonomy);
				wp_set_object_terms($post_id, $upcoming_term, $taxonomy, false);
			}
		}
	}
	
	/**
	 * Check all schedules
	 */
	public function check_all_schedules() {
		foreach ($this->schedules as $schedule) {
			$this->check_schedule_posts($schedule);
		}
	}
	
	/**
	 * Check posts for a specific schedule
	 */
	private function check_schedule_posts($schedule) {
		if (!taxonomy_exists($schedule['taxonomy'])) {
			return;
		}
		
		$current_time = current_time('timestamp', true);
		$current_date = gmdate('Ymd', $current_time);
		$unix_current_date = strtotime($current_date);
		
		$taxonomy = $schedule['taxonomy'];
		$upcoming_term = $schedule['upcoming_term'];
		$past_term = $schedule['past_term'];
		
		// Query all upcoming posts
		$args = array(
			'post_type' => $schedule['post_type'],
			'posts_per_page' => -1,
			'tax_query' => array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => $upcoming_term,
				),
			),
		);
		
		$upcoming_posts = new WP_Query($args);
		
		if ($upcoming_posts->have_posts()) {
			while ($upcoming_posts->have_posts()) {
				$upcoming_posts->the_post();
				$post_id = get_the_ID();
				
				$post_date = $this->get_post_date($post_id, $schedule);
				
				if ($post_date) {
					$unix_post_date = strtotime($post_date);
					if ($unix_post_date === false) continue;
					
					$gmt_post_date = gmdate('Ymd', $unix_post_date);
					$unix_gmt_post_date = strtotime($gmt_post_date);
					
					// Check if date has passed (with one day buffer)
					if (($unix_gmt_post_date + (24 * 60 * 60)) < $unix_current_date) {
						// Remove all terms first, then set only the past term
						wp_set_object_terms($post_id, array(), $taxonomy);
						wp_set_object_terms($post_id, $past_term, $taxonomy, false);
					}
				}
			}
		}
		
		wp_reset_postdata();
	}
	
	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_options_page(
			__('PostyCal', 'postycal'),
			__('PostyCal', 'postycal'),
			'manage_options',
			'pc-settings',
			array($this, 'settings_page')
		);
		
		// Add manual trigger under Tools if we have schedules
		if (!empty($this->schedules)) {
			add_management_page(
				__('Trigger PostyCal', 'postycal'),
				__('Trigger PostyCal', 'postycal'),
				'manage_options',
				'pc-trigger-cron',
				array($this, 'trigger_page')
			);
		}
	}
	
	/**
	 * AJAX handler for saving schedule
	 */
	public function ajax_save_schedule() {
		check_ajax_referer('pc_nonce', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_die();
		}
		
		$schedule_data = array(
			'name' => sanitize_text_field($_POST['name']),
			'post_type' => sanitize_text_field($_POST['post_type']),
			'taxonomy' => sanitize_text_field($_POST['taxonomy']),
			'date_field' => sanitize_text_field($_POST['date_field']),
			'field_type' => sanitize_text_field($_POST['field_type']),
			'sub_field' => sanitize_text_field($_POST['sub_field']),
			'date_logic' => sanitize_text_field($_POST['date_logic']),
			'upcoming_term' => sanitize_text_field($_POST['upcoming_term']),
			'past_term' => sanitize_text_field($_POST['past_term'])
		);
		
		$schedules = get_option('pc_schedules', array());
		
		if (isset($_POST['index']) && $_POST['index'] !== '') {
			// Update existing schedule
			$index = intval($_POST['index']);
			$schedules[$index] = $schedule_data;
		} else {
			// Add new schedule
			$schedules[] = $schedule_data;
		}
		
		update_option('pc_schedules', $schedules);
		
		wp_send_json_success();
	}
	
	/**
	 * AJAX handler for deleting schedule
	 */
	public function ajax_delete_schedule() {
		check_ajax_referer('pc_nonce', 'nonce');
		
		if (!current_user_can('manage_options')) {
			wp_die();
		}
		
		$index = intval($_POST['index']);
		$schedules = get_option('pc_schedules', array());
		
		if (isset($schedules[$index])) {
			array_splice($schedules, $index, 1);
			update_option('pc_schedules', $schedules);
		}
		
		wp_send_json_success();
	}
	
	/**
	 * Settings page
	 */
	public function settings_page() {
		// Handle manual trigger
		if (isset($_GET['trigger_cron']) && $_GET['trigger_cron'] == 'true' && !empty($this->schedules)) {
			$this->check_all_schedules();
			echo '<div class="notice notice-success is-dismissible"><p>' . __('PostyCal has been manually triggered and executed.', 'postycal') . '</p></div>';
		}
		
		?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>
			
			<?php if (!function_exists('get_field')): ?>
				<div class="notice notice-warning">
					<p><?php _e('Warning: Advanced Custom Fields (ACF) is not active. ACF is required for repeater field functionality.', 'postycal'); ?></p>
				</div>
			<?php endif; ?>
			
			<div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; border-radius: 4px;">
				<h2 style="margin-top: 0;"><?php _e('How PostyCal Works', 'postycal'); ?></h2>
				<p><?php _e('PostyCal automatically transitions posts between two taxonomy terms based on a date field:', 'postycal'); ?></p>
				<ol>
					<li><strong><?php _e('Pre-Date Category:', 'postycal'); ?></strong> <?php _e('Posts are assigned this category when their date is in the future.', 'postycal'); ?></li>
					<li><strong><?php _e('Post-Date Category:', 'postycal'); ?></strong> <?php _e('Posts automatically move to this category after their date has passed.', 'postycal'); ?></li>
				</ol>
				<p><?php _e('The plugin checks dates in two ways:', 'postycal'); ?></p>
				<ul>
					<li><?php _e('When a post is saved, it\'s immediately assigned to the correct category based on its date.', 'postycal'); ?></li>
					<li><?php _e('A daily automated check moves posts from Pre-Date to Post-Date category when their date passes.', 'postycal'); ?></li>
				</ul>
				<p><strong><?php _e('Repeater Support:', 'postycal'); ?></strong> <?php _e('The plugin can handle ACF Repeater fields with multiple dates. You can choose to transition based on the earliest date, latest date, or when any date has passed.', 'postycal'); ?></p>
				<p><em><?php _e('Example: An event post with a future date starts in "upcoming" category and automatically moves to "past" category after the event date.', 'postycal'); ?></em></p>
			</div>
			
			<h2><?php _e('Schedule Management', 'postycal'); ?></h2>
			
			<table class="widefat" id="pc-schedules-table">
				<thead>
					<tr>
						<th><?php _e('Name', 'postycal'); ?></th>
						<th><?php _e('Post Type', 'postycal'); ?></th>
						<th><?php _e('Taxonomy', 'postycal'); ?></th>
						<th><?php _e('Date Field', 'postycal'); ?></th>
						<th><?php _e('Field Type', 'postycal'); ?></th>
						<th><?php _e('Pre-Date Term', 'postycal'); ?></th>
						<th><?php _e('Post-Date Term', 'postycal'); ?></th>
						<th><?php _e('Actions', 'postycal'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($this->schedules)): ?>
						<tr>
							<td colspan="8"><?php _e('No schedules configured. Click "Add New Schedule" to create one.', 'postycal'); ?></td>
						</tr>
					<?php else: ?>
						<?php foreach ($this->schedules as $index => $schedule): ?>
							<tr>
								<td><?php echo esc_html($schedule['name']); ?></td>
								<td><?php echo esc_html($schedule['post_type']); ?></td>
								<td><?php echo esc_html($schedule['taxonomy']); ?></td>
								<td><?php echo esc_html($schedule['date_field']); ?></td>
								<td>
									<?php 
									$field_type = isset($schedule['field_type']) ? $schedule['field_type'] : 'single';
									if ($field_type === 'repeater') {
										$date_logic = isset($schedule['date_logic']) ? $schedule['date_logic'] : 'earliest';
										echo __('Repeater', 'postycal') . ' (' . esc_html($date_logic) . ')';
									} else {
										echo __('Single', 'postycal');
									}
									?>
								</td>
								<td><?php echo esc_html($schedule['upcoming_term']); ?></td>
								<td><?php echo esc_html($schedule['past_term']); ?></td>
								<td>
									<button class="button pc-edit-schedule" data-index="<?php echo $index; ?>"><?php _e('Edit', 'postycal'); ?></button>
									<button class="button pc-delete-schedule" data-index="<?php echo $index; ?>"><?php _e('Delete', 'postycal'); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			
			<p><button class="button button-primary" id="pc-add-schedule"><?php _e('Add New Schedule', 'postycal'); ?></button></p>
			
			<?php if (!empty($this->schedules)): ?>
				<hr />
				<h2><?php _e('Manual Trigger', 'postycal'); ?></h2>
				<p><?php _e('You can manually run all schedules by clicking the button below.', 'postycal'); ?></p>
				<a href="<?php echo add_query_arg('trigger_cron', 'true'); ?>" class="button button-secondary"><?php _e('Run All Schedules Now', 'postycal'); ?></a>
			<?php endif; ?>
		</div>
		
		<!-- Schedule Form Modal -->
		<div id="pc-schedule-modal" style="display: none;">
			<div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999;">
				<div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; padding: 20px; border-radius: 4px; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto;">
					<h2 id="pc-modal-title"><?php _e('Add New Schedule', 'postycal'); ?></h2>
					<form id="pc-schedule-form">
						<input type="hidden" id="pc-schedule-index" value="">
						
						<p>
							<label for="pc-schedule-name"><?php _e('Schedule Name:', 'postycal'); ?></label><br>
							<input type="text" id="pc-schedule-name" class="regular-text" required>
						</p>
						
						<p>
							<label for="pc-schedule-post-type"><?php _e('Post Type:', 'postycal'); ?></label><br>
							<select id="pc-schedule-post-type" required>
								<option value=""><?php _e('Select Post Type', 'postycal'); ?></option>
								<?php
								$post_types = get_post_types(array('public' => true), 'objects');
								foreach ($post_types as $post_type) {
									echo '<option value="' . esc_attr($post_type->name) . '">' . esc_html($post_type->label) . '</option>';
								}
								?>
							</select>
						</p>
						
						<p>
							<label for="pc-schedule-taxonomy"><?php _e('Taxonomy:', 'postycal'); ?></label><br>
							<select id="pc-schedule-taxonomy" required>
								<option value=""><?php _e('Select Taxonomy', 'postycal'); ?></option>
								<?php
								$taxonomies = get_taxonomies(array('public' => true), 'objects');
								foreach ($taxonomies as $taxonomy) {
									echo '<option value="' . esc_attr($taxonomy->name) . '">' . esc_html($taxonomy->label) . '</option>';
								}
								?>
							</select>
						</p>
						
						<p>
							<label for="pc-schedule-field-type"><?php _e('Field Type:', 'postycal'); ?></label><br>
							<select id="pc-schedule-field-type" required>
								<option value="single"><?php _e('Single Date Field', 'postycal'); ?></option>
								<option value="repeater"><?php _e('Repeater Field', 'postycal'); ?></option>
							</select>
						</p>
						
						<p>
							<label for="pc-schedule-date-field"><?php _e('ACF Date Field Name:', 'postycal'); ?></label><br>
							<input type="text" id="pc-schedule-date-field" class="regular-text" required>
							<span class="description"><?php _e('For repeaters, enter the repeater field name.', 'postycal'); ?></span>
						</p>
						
						<div id="pc-repeater-options" style="display: none;">
							<p>
								<label for="pc-schedule-sub-field"><?php _e('Date Sub-field Name:', 'postycal'); ?></label><br>
								<input type="text" id="pc-schedule-sub-field" class="regular-text">
								<span class="description"><?php _e('The name of the date field within the repeater.', 'postycal'); ?></span>
							</p>
							
							<p>
								<label for="pc-schedule-date-logic"><?php _e('Date Logic:', 'postycal'); ?></label><br>
								<select id="pc-schedule-date-logic">
									<option value="earliest"><?php _e('Use earliest date', 'postycal'); ?></option>
									<option value="latest"><?php _e('Use latest date', 'postycal'); ?></option>
									<option value="any_past"><?php _e('Transition when any date has passed', 'postycal'); ?></option>
								</select>
								<span class="description"><?php _e('How to handle multiple dates in the repeater.', 'postycal'); ?></span>
							</p>
						</div>
						
						<p>
							<label for="pc-schedule-upcoming-term"><?php _e('Pre-Date Category Slug:', 'postycal'); ?></label><br>
							<input type="text" id="pc-schedule-upcoming-term" class="regular-text" required>
						</p>
						
						<p>
							<label for="pc-schedule-past-term"><?php _e('Post-Date Category Slug:', 'postycal'); ?></label><br>
							<input type="text" id="pc-schedule-past-term" class="regular-text" required>
						</p>
						
						<p>
							<button type="submit" class="button button-primary"><?php _e('Save Schedule', 'postycal'); ?></button>
							<button type="button" class="button" id="pc-cancel-schedule"><?php _e('Cancel', 'postycal'); ?></button>
						</p>
					</form>
				</div>
			</div>
		</div>
		
		<script>
		jQuery(document).ready(function($) {
			var schedules = <?php echo json_encode($this->schedules); ?>;
			
			// Toggle repeater options
			function updateFieldOptions() {
				var fieldType = $('#pc-schedule-field-type').val();
				
				if (fieldType === 'repeater') {
					$('#pc-repeater-options').show();
					$('#pc-schedule-sub-field').prop('required', true);
				} else {
					$('#pc-repeater-options').hide();
					$('#pc-schedule-sub-field').prop('required', false);
				}
			}
			
			$('#pc-schedule-field-type').on('change', updateFieldOptions);
			
			// Add new schedule
			$('#pc-add-schedule').on('click', function() {
				$('#pc-modal-title').text('<?php _e('Add New Schedule', 'postycal'); ?>');
				$('#pc-schedule-form')[0].reset();
				$('#pc-schedule-index').val('');
				$('#pc-schedule-field-type').val('single');
				updateFieldOptions();
				$('#pc-schedule-modal').show();
			});
			
			// Edit schedule
			$(document).on('click', '.pc-edit-schedule', function() {
				var index = $(this).data('index');
				var schedule = schedules[index];
				
				$('#pc-modal-title').text('<?php _e('Edit Schedule', 'postycal'); ?>');
				$('#pc-schedule-index').val(index);
				$('#pc-schedule-name').val(schedule.name);
				$('#pc-schedule-post-type').val(schedule.post_type);
				$('#pc-schedule-taxonomy').val(schedule.taxonomy);
				$('#pc-schedule-date-field').val(schedule.date_field);
				$('#pc-schedule-field-type').val(schedule.field_type || 'single');
				$('#pc-schedule-sub-field').val(schedule.sub_field || '');
				$('#pc-schedule-date-logic').val(schedule.date_logic || 'earliest');
				$('#pc-schedule-upcoming-term').val(schedule.upcoming_term);
				$('#pc-schedule-past-term').val(schedule.past_term);
				updateFieldOptions();
				$('#pc-schedule-modal').show();
			});
			
			// Cancel
			$('#pc-cancel-schedule').on('click', function() {
				$('#pc-schedule-modal').hide();
			});
			
			// Save schedule
			$('#pc-schedule-form').on('submit', function(e) {
				e.preventDefault();
				
				var data = {
					action: 'pc_save_schedule',
					nonce: pc_ajax.nonce,
					name: $('#pc-schedule-name').val(),
					post_type: $('#pc-schedule-post-type').val(),
					taxonomy: $('#pc-schedule-taxonomy').val(),
					date_field: $('#pc-schedule-date-field').val(),
					field_type: $('#pc-schedule-field-type').val(),
					sub_field: $('#pc-schedule-sub-field').val(),
					date_logic: $('#pc-schedule-date-logic').val(),
					upcoming_term: $('#pc-schedule-upcoming-term').val(),
					past_term: $('#pc-schedule-past-term').val(),
					index: $('#pc-schedule-index').val()
				};
				
				$.post(pc_ajax.ajaxurl, data, function(response) {
					if (response.success) {
						location.reload();
					} else {
						alert('Error saving schedule');
					}
				});
			});
			
			// Delete schedule
			$(document).on('click', '.pc-delete-schedule', function() {
				if (!confirm('<?php _e('Are you sure you want to delete this schedule?', 'postycal'); ?>')) {
					return;
				}
				
				var data = {
					action: 'pc_delete_schedule',
					nonce: pc_ajax.nonce,
					index: $(this).data('index')
				};
				
				$.post(pc_ajax.ajaxurl, data, function(response) {
					if (response.success) {
						location.reload();
					} else {
						alert('Error deleting schedule');
					}
				});
			});
		});
		</script>
		<?php
	}
	
	/**
	 * Trigger page
	 */
	public function trigger_page() {
		if (isset($_GET['trigger_cron']) && $_GET['trigger_cron'] == 'true') {
			$this->check_all_schedules();
			echo '<div class="notice notice-success is-dismissible"><p>' . __('PostyCal has been manually triggered and executed.', 'postycal') . '</p></div>';
		}
		
		?>
		<div class="wrap">
			<h1><?php _e('Manually Trigger PostyCal', 'postycal'); ?></h1>
			<p><?php _e('Click the button below to manually run all schedules and check all posts for category updates.', 'postycal'); ?></p>
			<a href="<?php echo add_query_arg('trigger_cron', 'true'); ?>" class="button button-primary"><?php _e('Run All Schedules Now', 'postycal'); ?></a>
			
			<?php if (!empty($this->schedules)): ?>
				<h2><?php _e('Active Schedules', 'postycal'); ?></h2>
				<ul>
					<?php foreach ($this->schedules as $schedule): ?>
						<li>
							<?php echo esc_html($schedule['name']); ?> (<?php echo esc_html($schedule['post_type']); ?>)
							<?php if (isset($schedule['field_type']) && $schedule['field_type'] === 'repeater'): ?>
								- <?php _e('Repeater field', 'postycal'); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}

// Initialize the plugin
function pc_init() {
	new PostyCal();
}
add_action('plugins_loaded', 'pc_init');

// Activation hook
register_activation_hook(__FILE__, 'pc_activate');
function pc_activate() {
	// No default options are set on activation
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'pc_deactivate');
function pc_deactivate() {
	// Clear the scheduled hook
	wp_clear_scheduled_hook('pc_daily_category_check');
}
