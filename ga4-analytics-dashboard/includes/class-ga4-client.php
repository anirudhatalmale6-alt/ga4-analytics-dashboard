<?php
// Thin wrapper over the GA4 Data API runReport / batchRunReports endpoints.
if (!defined('ABSPATH')) exit;

class GA4_Client {
	private $auth;
	private $property;

	public function __construct(GA4_Auth $auth, $property_id) {
		$this->auth = $auth;
		$this->property = preg_replace('/[^0-9]/', '', (string) $property_id);
	}

	public function has_property() {
		return $this->property !== '';
	}

	public function batch_run_reports($requests) {
		$token = $this->auth->get_access_token();
		if (is_wp_error($token)) return $token;
		if (!$this->has_property()) {
			return new WP_Error('ga4_no_prop', 'GA4 Property ID is not set.');
		}

		$url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . $this->property . ':batchRunReports';
		$body = $this->request($url, ['requests' => array_values($requests)], $token);
		if (is_wp_error($body)) return $body;
		return isset($body['reports']) ? $body['reports'] : [];
	}

	public function run_realtime_report($request) {
		$token = $this->auth->get_access_token();
		if (is_wp_error($token)) return $token;
		if (!$this->has_property()) {
			return new WP_Error('ga4_no_prop', 'GA4 Property ID is not set.');
		}
		$url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . $this->property . ':runRealtimeReport';
		return $this->request($url, $request, $token);
	}

	private function request($url, $payload, $token) {
		$resp = wp_remote_post($url, [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode($payload),
		]);
		if (is_wp_error($resp)) return $resp;

		$code = wp_remote_retrieve_response_code($resp);
		$body = json_decode(wp_remote_retrieve_body($resp), true);
		if ($code !== 200) {
			$msg = $body['error']['message'] ?? ('GA4 API returned status ' . $code . '.');
			return new WP_Error('ga4_api', $msg);
		}
		return is_array($body) ? $body : [];
	}
}
