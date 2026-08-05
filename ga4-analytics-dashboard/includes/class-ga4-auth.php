<?php
// Turns a service account key into a short-lived Analytics read token.
if (!defined('ABSPATH')) exit;

class GA4_Auth {
	private $creds;

	public function __construct($json) {
		$this->creds = is_array($json) ? $json : json_decode((string) $json, true);
	}

	public function is_configured() {
		return !empty($this->creds['client_email']) && !empty($this->creds['private_key']);
	}

	public function get_access_token() {
		if (!$this->is_configured()) {
			return new WP_Error('ga4_no_creds', 'Service account key is missing or invalid.');
		}

		$key = 'ga4dash_tok_' . md5($this->creds['client_email']);
		$cached = get_transient($key);
		if ($cached) return $cached;

		$aud = $this->creds['token_uri'] ?? 'https://oauth2.googleapis.com/token';
		$now = time();
		$jwt = $this->sign([
			'iss'   => $this->creds['client_email'],
			'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
			'aud'   => $aud,
			'iat'   => $now,
			'exp'   => $now + 3600,
		]);
		if (is_wp_error($jwt)) return $jwt;

		$resp = wp_remote_post($aud, [
			'timeout' => 20,
			'body'    => [
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			],
		]);
		if (is_wp_error($resp)) return $resp;

		$body = json_decode(wp_remote_retrieve_body($resp), true);
		if (empty($body['access_token'])) {
			$msg = $body['error_description'] ?? ($body['error'] ?? 'Token request was rejected.');
			return new WP_Error('ga4_token', $msg);
		}

		$ttl = max(60, intval($body['expires_in'] ?? 3600) - 300);
		set_transient($key, $body['access_token'], $ttl);
		return $body['access_token'];
	}

	private function sign($claims) {
		$input = $this->b64(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $this->b64($claims);
		$sig = '';
		if (!openssl_sign($input, $sig, $this->creds['private_key'], 'SHA256')) {
			return new WP_Error('ga4_sign', 'Could not sign the request. The service account private key looks invalid.');
		}
		return $input . '.' . $this->b64url($sig);
	}

	private function b64($arr) {
		return $this->b64url(wp_json_encode($arr));
	}

	private function b64url($data) {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}
}
