<?php
// Live visitor log: real IPs and locations pushed from the site edge, plus IP
// block / whitelist rules. GA4 never exposes visitor IPs, so this half is fed by a
// small logger running on the site itself, which posts each visit to the REST
// endpoints below and reads the block list back to refuse unwanted IPs.
if (!defined('ABSPATH')) exit;

define('GA4DASH_OPT_INGEST_KEY', 'ga4dash_ingest_key');
define('GA4DASH_OPT_BLOCK', 'ga4dash_ip_block');
define('GA4DASH_OPT_ALLOW', 'ga4dash_ip_allow');
define('GA4DASH_HITS_TABLE_VER', '1');
define('GA4DASH_HITS_KEEP_HOURS', 24);
// Window that counts as "active right now", matching GA4's realtime definition.
define('GA4DASH_ACTIVE_MINUTES', 5);

function ga4dash_hits_table() {
	global $wpdb;
	return $wpdb->prefix . 'ga4dash_hits';
}

function ga4dash_visitors_install() {
	global $wpdb;
	$table = ga4dash_hits_table();
	$charset = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE $table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		ip VARCHAR(45) NOT NULL DEFAULT '',
		city VARCHAR(120) NOT NULL DEFAULT '',
		region VARCHAR(120) NOT NULL DEFAULT '',
		country VARCHAR(8) NOT NULL DEFAULT '',
		lat DOUBLE NULL,
		lng DOUBLE NULL,
		path VARCHAR(255) NOT NULL DEFAULT '',
		ua VARCHAR(255) NOT NULL DEFAULT '',
		ts DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY ts (ts),
		KEY ip (ip)
	) $charset;";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);
	if (!get_option(GA4DASH_OPT_INGEST_KEY)) {
		update_option(GA4DASH_OPT_INGEST_KEY, wp_generate_password(40, false, false), false);
	}
	update_option('ga4dash_hits_table_ver', GA4DASH_HITS_TABLE_VER, false);
}

// Build the table on first load (and after an update that bumps the version) so the
// plugin works whether it was freshly activated or replaced in place.
add_action('plugins_loaded', function () {
	if (get_option('ga4dash_hits_table_ver') !== GA4DASH_HITS_TABLE_VER) {
		ga4dash_visitors_install();
	}
});

/* ---------- helpers ---------- */

function ga4dash_clean_ip($ip) {
	$ip = trim((string) $ip);
	return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function ga4dash_trunc($s, $len) {
	$s = sanitize_text_field((string) $s);
	return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
}

// User-agent fragments that mark automated traffic (bots, crawlers, scanners, uptime
// checks). GA4 counts real browsers only, so filtering these keeps the live view in
// step with the Realtime tab.
function ga4dash_bot_regex() {
	return 'bot|crawl|spider|slurp|curl|wget|python|go-http|okhttp|java/|libwww|httpclient|axios|node-fetch|headless|phantom|puppeteer|playwright|pingdom|uptimerobot|statuscake|monitor|scrapy|semrush|ahrefs|mj12|dotbot|petalbot|dataforseo|censys|zgrab|masscan|facebookexternalhit|embedly|yandex|baidu|duckduckbot|bingpreview|apache-http|winhttp';
}

// Format a stored UTC timestamp for a UK audience: absolute Europe/London date-time
// plus a short relative label.
function ga4dash_fmt_uk($ts_utc) {
	$ts = strtotime($ts_utc . ' UTC');
	if (!$ts) return ['abs' => $ts_utc, 'ago' => ''];
	try {
		$dt = new DateTime('@' . $ts);
		$dt->setTimezone(new DateTimeZone('Europe/London'));
		$abs = $dt->format('d M Y, H:i');
	} catch (Exception $e) {
		$abs = gmdate('d M Y, H:i', $ts);
	}
	$diff = time() - $ts;
	if ($diff < 60)        $ago = 'just now';
	elseif ($diff < 3600)  $ago = floor($diff / 60) . ' min ago';
	elseif ($diff < 86400) $ago = floor($diff / 3600) . 'h ago';
	else                   $ago = floor($diff / 86400) . 'd ago';
	return ['abs' => $abs, 'ago' => $ago];
}

function ga4dash_rules_list($opt) {
	$out = [];
	if (is_array($opt)) {
		foreach ($opt as $ip => $meta) {
			$out[] = [
				'ip'   => $ip,
				'note' => is_array($meta) && isset($meta['note']) ? $meta['note'] : '',
				'ts'   => is_array($meta) && isset($meta['ts']) ? $meta['ts'] : '',
			];
		}
	}
	return $out;
}

function ga4dash_prune_hits() {
	global $wpdb;
	$table = ga4dash_hits_table();
	$cutoff = gmdate('Y-m-d H:i:s', time() - GA4DASH_HITS_KEEP_HOURS * 3600);
	$wpdb->query($wpdb->prepare("DELETE FROM $table WHERE ts < %s", $cutoff));
}

/* ---------- REST: fed by the site edge ---------- */

add_action('rest_api_init', function () {
	register_rest_route('ga4dash/v1', '/hit', [
		'methods'             => 'POST',
		'callback'            => 'ga4dash_rest_hit',
		'permission_callback' => 'ga4dash_rest_auth',
	]);
	register_rest_route('ga4dash/v1', '/rules', [
		'methods'             => 'GET',
		'callback'            => 'ga4dash_rest_rules',
		'permission_callback' => 'ga4dash_rest_auth',
	]);
});

function ga4dash_rest_auth($request) {
	$key = get_option(GA4DASH_OPT_INGEST_KEY, '');
	$sent = $request->get_header('X-GA4Dash-Key');
	return $key !== '' && is_string($sent) && hash_equals($key, $sent);
}

function ga4dash_rest_hit($request) {
	global $wpdb;
	$p = $request->get_json_params();
	if (!is_array($p)) $p = $request->get_params();

	$ip = ga4dash_clean_ip(isset($p['ip']) ? $p['ip'] : '');
	if ($ip === '') return new WP_REST_Response(['ok' => false], 400);

	$block = get_option(GA4DASH_OPT_BLOCK, []);
	$allow = get_option(GA4DASH_OPT_ALLOW, []);
	if (is_array($block) && isset($block[$ip])) return new WP_REST_Response(['ok' => false, 'blocked' => true], 200);
	if (is_array($allow) && isset($allow[$ip])) return new WP_REST_Response(['ok' => true, 'internal' => true], 200);

	$wpdb->insert(ga4dash_hits_table(), [
		'ip'      => $ip,
		'city'    => ga4dash_trunc(isset($p['city']) ? $p['city'] : '', 120),
		'region'  => ga4dash_trunc(isset($p['region']) ? $p['region'] : '', 120),
		'country' => ga4dash_trunc(isset($p['country']) ? $p['country'] : '', 8),
		'lat'     => (isset($p['lat']) && $p['lat'] !== '' && $p['lat'] !== null) ? (float) $p['lat'] : null,
		'lng'     => (isset($p['lng']) && $p['lng'] !== '' && $p['lng'] !== null) ? (float) $p['lng'] : null,
		'path'    => ga4dash_trunc(isset($p['path']) ? $p['path'] : '', 255),
		'ua'      => ga4dash_trunc(isset($p['ua']) ? $p['ua'] : '', 255),
		'ts'      => gmdate('Y-m-d H:i:s'),
	]);

	// Prune occasionally rather than on every hit to keep writes cheap.
	if (wp_rand(1, 20) === 1) ga4dash_prune_hits();

	return new WP_REST_Response(['ok' => true], 200);
}

function ga4dash_rest_rules($request) {
	$block = get_option(GA4DASH_OPT_BLOCK, []);
	$allow = get_option(GA4DASH_OPT_ALLOW, []);
	return new WP_REST_Response([
		'block' => is_array($block) ? array_keys($block) : [],
		'allow' => is_array($allow) ? array_keys($allow) : [],
	], 200);
}

/* ---------- admin AJAX: the Visitors tab ---------- */

add_action('wp_ajax_ga4dash_visitors', 'ga4dash_ajax_visitors');
function ga4dash_ajax_visitors() {
	check_ajax_referer('ga4dash', 'nonce');
	if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Not allowed.'], 403);

	global $wpdb;
	$table = ga4dash_hits_table();

	// Exclude bots/automated traffic (empty or bot user-agent) and whitelisted internal
	// IPs, so the figures track real human visitors the way GA4 does.
	$allow = get_option(GA4DASH_OPT_ALLOW, []);
	$allow_ips = (is_array($allow) && $allow) ? array_keys($allow) : [];
	$where = "WHERE ua <> '' AND LOWER(ua) NOT REGEXP %s";
	$params = [ga4dash_bot_regex()];
	if ($allow_ips) {
		$where .= ' AND ip NOT IN (' . implode(',', array_fill(0, count($allow_ips), '%s')) . ')';
		$params = array_merge($params, $allow_ips);
	}

	$recent_cut = gmdate('Y-m-d H:i:s', time() - GA4DASH_HITS_KEEP_HOURS * 3600);
	$rows = $wpdb->get_results($wpdb->prepare(
		"SELECT ip,city,region,country,lat,lng,path,ua,ts FROM $table $where AND ts >= %s ORDER BY ts DESC LIMIT 600",
		array_merge($params, [$recent_cut])
	), ARRAY_A);
	if (!is_array($rows)) $rows = [];

	$active_from = time() - GA4DASH_ACTIVE_MINUTES * 60;
	$seen = [];        // one row per IP (latest first), for the table
	$active_ips = [];  // distinct IPs active in the live window
	$pt_seen = [];
	$points = [];      // live map markers (active window only)

	foreach ($rows as $r) {
		$ip = $r['ip'];
		$row_ts = strtotime($r['ts'] . ' UTC');
		$is_active = ($row_ts >= $active_from);

		if (!isset($seen[$ip])) {
			$loc = trim($r['city'] . ($r['city'] && $r['country'] ? ', ' : '') . $r['country']);
			$when = ga4dash_fmt_uk($r['ts']);
			$seen[$ip] = [
				'ip'       => $ip,
				'location' => $loc !== '' ? $loc : ($r['country'] !== '' ? $r['country'] : '(unknown)'),
				'path'     => $r['path'],
				'count'    => 0,
				'last'     => $when['abs'],
				'ago'      => $when['ago'],
				'active'   => false,
			];
		}
		$seen[$ip]['count']++;
		if ($is_active) {
			$seen[$ip]['active'] = true;
			$active_ips[$ip] = true;
			if ($r['lat'] !== null && $r['lng'] !== null && !isset($pt_seen[$ip]) && count($points) < 200) {
				$pt_seen[$ip] = true;
				$points[] = [
					'ip'      => $ip,
					'lat'     => (float) $r['lat'],
					'lng'     => (float) $r['lng'],
					'city'    => $r['city'],
					'country' => $r['country'],
					'last'    => ga4dash_fmt_uk($r['ts'])['ago'],
				];
			}
		}
	}

	wp_send_json_success([
		'active'  => count($active_ips),
		'window'  => GA4DASH_ACTIVE_MINUTES,
		'recent'  => array_slice(array_values($seen), 0, 80),
		'points'  => $points,
		'block'   => ga4dash_rules_list(get_option(GA4DASH_OPT_BLOCK, [])),
		'allow'   => ga4dash_rules_list($allow),
	]);
}

add_action('wp_ajax_ga4dash_ip_rule', 'ga4dash_ajax_ip_rule');
function ga4dash_ajax_ip_rule() {
	check_ajax_referer('ga4dash', 'nonce');
	if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Not allowed.'], 403);

	$op   = isset($_POST['op']) ? sanitize_text_field(wp_unslash($_POST['op'])) : '';
	$list = isset($_POST['list']) ? sanitize_text_field(wp_unslash($_POST['list'])) : '';
	$ip   = ga4dash_clean_ip(isset($_POST['ip']) ? wp_unslash($_POST['ip']) : '');
	$note = ga4dash_trunc(isset($_POST['note']) ? wp_unslash($_POST['note']) : '', 120);

	if ($ip === '' || !in_array($list, ['block', 'allow'], true) || !in_array($op, ['add', 'remove'], true)) {
		wp_send_json_error(['message' => 'Enter a valid IP address.']);
	}

	$opt = ($list === 'block') ? GA4DASH_OPT_BLOCK : GA4DASH_OPT_ALLOW;
	$cur = get_option($opt, []);
	if (!is_array($cur)) $cur = [];
	if ($op === 'add') $cur[$ip] = ['note' => $note, 'ts' => current_time('mysql')];
	else unset($cur[$ip]);
	update_option($opt, $cur, false);

	wp_send_json_success(['list' => $list, 'rules' => ga4dash_rules_list($cur)]);
}
