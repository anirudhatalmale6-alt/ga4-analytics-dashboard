<?php
/**
 * Plugin Name: Analytics Dashboard
 * Description: A wp-admin dashboard that reads live traffic figures from Google Analytics 4 using the official Analytics Data API.
 * Version: 1.4.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('GA4DASH_VER', '1.4.0');
define('GA4DASH_DIR', plugin_dir_path(__FILE__));
define('GA4DASH_URL', plugin_dir_url(__FILE__));
define('GA4DASH_OPT_PROPERTY', 'ga4dash_property_id');
define('GA4DASH_OPT_SA', 'ga4dash_sa_json');

require_once GA4DASH_DIR . 'includes/class-ga4-auth.php';
require_once GA4DASH_DIR . 'includes/class-ga4-client.php';
require_once GA4DASH_DIR . 'includes/class-ga4-reports.php';
require_once GA4DASH_DIR . 'includes/visitors.php';

register_activation_hook(__FILE__, 'ga4dash_visitors_install');

function ga4dash_reports() {
	$sa = get_option(GA4DASH_OPT_SA, '');
	$property = get_option(GA4DASH_OPT_PROPERTY, '');
	if (!$sa || !$property) {
		return new WP_Error('ga4_setup', 'Add the GA4 Property ID and the service account key on the Settings tab first.');
	}
	$auth = new GA4_Auth($sa);
	if (!$auth->is_configured()) {
		return new WP_Error('ga4_setup', 'The service account key could not be read. Paste the full JSON key file.');
	}
	return new GA4_Reports(new GA4_Client($auth, $property));
}

add_action('admin_menu', 'ga4dash_menu');
function ga4dash_menu() {
	add_menu_page('Analytics Dashboard', 'Analytics', 'manage_options', 'ga4-dashboard', 'ga4dash_render_dashboard', 'dashicons-chart-area', 3);
	add_submenu_page('ga4-dashboard', 'Analytics Dashboard', 'Dashboard', 'manage_options', 'ga4-dashboard', 'ga4dash_render_dashboard');
	add_submenu_page('ga4-dashboard', 'Analytics Settings', 'Settings', 'manage_options', 'ga4-dashboard-settings', 'ga4dash_render_settings');
}

add_action('admin_enqueue_scripts', 'ga4dash_assets');
function ga4dash_assets($hook) {
	if (strpos($hook, 'ga4-dashboard') === false) return;
	wp_enqueue_style('ga4dash-leaflet', GA4DASH_URL . 'admin/assets/leaflet.css', [], '1.9.4');
	wp_enqueue_style('ga4dash', GA4DASH_URL . 'admin/assets/dashboard.css', ['ga4dash-leaflet'], GA4DASH_VER);
	wp_enqueue_script('ga4dash-chart', GA4DASH_URL . 'admin/assets/chart.umd.min.js', [], '4.4.1', true);
	wp_enqueue_script('ga4dash-leaflet', GA4DASH_URL . 'admin/assets/leaflet.js', [], '1.9.4', true);
	wp_enqueue_script('ga4dash', GA4DASH_URL . 'admin/assets/dashboard.js', ['ga4dash-chart', 'ga4dash-leaflet'], GA4DASH_VER, true);
	wp_localize_script('ga4dash', 'GA4DASH', [
		'ajax'  => admin_url('admin-ajax.php'),
		'nonce' => wp_create_nonce('ga4dash'),
	]);
}

function ga4dash_render_dashboard() {
	include GA4DASH_DIR . 'admin/dashboard.php';
}

function ga4dash_render_settings() {
	if (!current_user_can('manage_options')) return;

	if (isset($_POST['ga4dash_save']) && check_admin_referer('ga4dash_settings')) {
		$property = preg_replace('/[^0-9]/', '', wp_unslash($_POST['ga4dash_property'] ?? ''));
		update_option(GA4DASH_OPT_PROPERTY, $property, false);

		$raw = trim((string) wp_unslash($_POST['ga4dash_sa'] ?? ''));
		if ($raw !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded) && !empty($decoded['client_email']) && !empty($decoded['private_key'])) {
				update_option(GA4DASH_OPT_SA, $raw, false);
				echo '<div class="notice notice-success"><p>Settings saved. Service account key updated.</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>That did not look like a valid service account JSON key. The Property ID was saved; the key was left unchanged.</p></div>';
			}
		} else {
			echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
		}
	}

	$property = esc_attr(get_option(GA4DASH_OPT_PROPERTY, ''));
	$sa = get_option(GA4DASH_OPT_SA, '');
	$sa_info = '';
	if ($sa) {
		$d = json_decode($sa, true);
		$sa_info = is_array($d) && !empty($d['client_email']) ? $d['client_email'] : 'configured';
	}
	?>
	<div class="wrap ga4dash-settings">
		<h1>Analytics Settings</h1>
		<form method="post">
			<?php wp_nonce_field('ga4dash_settings'); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ga4dash_property">GA4 Property ID</label></th>
					<td>
						<input name="ga4dash_property" id="ga4dash_property" type="text" class="regular-text" value="<?php echo $property; ?>" placeholder="e.g. 123456789" />
						<p class="description">The numeric Property ID from GA4 Admin, Property Settings. Not the G-XXXX Measurement ID.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ga4dash_sa">Service account key (JSON)</label></th>
					<td>
						<?php if ($sa_info): ?>
							<p class="ga4dash-sa-set">Key on file: <code><?php echo esc_html($sa_info); ?></code></p>
						<?php endif; ?>
						<textarea name="ga4dash_sa" id="ga4dash_sa" rows="6" class="large-text code" placeholder="Paste the full JSON key here to set or replace it. Leave blank to keep the current key."></textarea>
						<p class="description">Read-only. Add this service account as a Viewer on the GA4 property, then paste its JSON key here.</p>
					</td>
				</tr>
			</table>
			<p>
				<button type="submit" name="ga4dash_save" value="1" class="button button-primary">Save Settings</button>
				<button type="button" id="ga4dash-test" class="button">Test connection</button>
				<span id="ga4dash-test-result" class="ga4dash-test-result"></span>
			</p>
		</form>

		<hr />
		<h2>Live visitor tracking</h2>
		<p class="description">These two values connect the site to the Visitors tab (real IPs and the live map). Add them to the site's hosting environment. This does not affect the Google Analytics figures above.</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Endpoint URL</th>
				<td><input type="text" class="large-text code" readonly value="<?php echo esc_attr(rest_url('ga4dash/v1')); ?>" onfocus="this.select()" /></td>
			</tr>
			<tr>
				<th scope="row">Ingest key</th>
				<td><input type="text" class="large-text code" readonly value="<?php echo esc_attr(get_option(GA4DASH_OPT_INGEST_KEY, '')); ?>" onfocus="this.select()" /></td>
			</tr>
		</table>
	</div>
	<?php
}

add_action('wp_ajax_ga4dash_data', 'ga4dash_ajax_data');
function ga4dash_ajax_data() {
	check_ajax_referer('ga4dash', 'nonce');
	if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Not allowed.'], 403);

	$reports = ga4dash_reports();
	if (is_wp_error($reports)) wp_send_json_error(['message' => $reports->get_error_message()]);

	$preset = isset($_POST['range']) ? sanitize_text_field(wp_unslash($_POST['range'])) : '28d';
	$start = isset($_POST['start']) ? sanitize_text_field(wp_unslash($_POST['start'])) : '';
	$end = isset($_POST['end']) ? sanitize_text_field(wp_unslash($_POST['end'])) : '';

	$data = $reports->fetch($preset, $start, $end);
	if (is_wp_error($data)) wp_send_json_error(['message' => $data->get_error_message()]);

	wp_send_json_success($data);
}

add_action('wp_ajax_ga4dash_realtime', 'ga4dash_ajax_realtime');
function ga4dash_ajax_realtime() {
	check_ajax_referer('ga4dash', 'nonce');
	if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Not allowed.'], 403);

	$reports = ga4dash_reports();
	if (is_wp_error($reports)) wp_send_json_error(['message' => $reports->get_error_message()]);

	$data = $reports->realtime();
	if (is_wp_error($data)) wp_send_json_error(['message' => $data->get_error_message()]);

	wp_send_json_success($data);
}

add_action('wp_ajax_ga4dash_audience', 'ga4dash_ajax_audience');
function ga4dash_ajax_audience() {
	check_ajax_referer('ga4dash', 'nonce');
	if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Not allowed.'], 403);

	$reports = ga4dash_reports();
	if (is_wp_error($reports)) wp_send_json_error(['message' => $reports->get_error_message()]);

	$preset = isset($_POST['range']) ? sanitize_text_field(wp_unslash($_POST['range'])) : '28d';
	$start = isset($_POST['start']) ? sanitize_text_field(wp_unslash($_POST['start'])) : '';
	$end = isset($_POST['end']) ? sanitize_text_field(wp_unslash($_POST['end'])) : '';

	$data = $reports->audience($preset, $start, $end);
	if (is_wp_error($data)) wp_send_json_error(['message' => $data->get_error_message()]);

	wp_send_json_success($data);
}

add_action('wp_ajax_ga4dash_test', 'ga4dash_ajax_test');
function ga4dash_ajax_test() {
	check_ajax_referer('ga4dash', 'nonce');
	if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Not allowed.'], 403);

	$reports = ga4dash_reports();
	if (is_wp_error($reports)) wp_send_json_error(['message' => $reports->get_error_message()]);

	$data = $reports->fetch('7d');
	if (is_wp_error($data)) wp_send_json_error(['message' => $data->get_error_message()]);

	$users = isset($data['summary']['totalUsers']['current']) ? (int) $data['summary']['totalUsers']['current'] : 0;
	wp_send_json_success(['message' => 'Connected. Last 7 days reported ' . number_format_i18n($users) . ' users.']);
}
