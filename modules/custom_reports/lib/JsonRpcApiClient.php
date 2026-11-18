<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * Zabbix JSON-RPC client authenticated with an API token (Users -> API tokens).
 *
 * Used by the CLI sender. The report content is limited by the permissions of the token owner.
 */
class JsonRpcApiClient implements ApiClientInterface {

	private string $url;
	private string $token;
	private bool $verify_ssl;
	private int $timeout;
	private int $request_id = 0;

	public function __construct(string $url, string $token, bool $verify_ssl = true, int $timeout = 30) {
		if (!function_exists('curl_init')) {
			throw new ApiException('PHP extension "curl" is required.');
		}

		if ($url === '' || $token === '') {
			throw new ApiException('"api_url" and "api_token" must be set in conf/config.php.');
		}

		$this->url = $url;
		$this->token = $token;
		$this->verify_ssl = $verify_ssl;
		$this->timeout = $timeout;
	}

	public function call(string $method, array $params) {
		$request = json_encode([
			'jsonrpc' => '2.0',
			'method' => $method,
			'params' => $params,
			'id' => ++$this->request_id
		]);

		$ch = curl_init($this->url);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $request,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
			CURLOPT_SSL_VERIFYPEER => $this->verify_ssl,
			CURLOPT_SSL_VERIFYHOST => $this->verify_ssl ? 2 : 0,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json-rpc',
				'Authorization: Bearer '.$this->token
			]
		]);

		$body = curl_exec($ch);
		$http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);

		if ($body === false) {
			throw new ApiException(sprintf('API request "%s" failed: %s', $method, $curl_error));
		}

		$response = json_decode($body, true);

		if (!is_array($response)) {
			throw new ApiException(sprintf('API request "%s" returned invalid JSON (HTTP %d).', $method,
				$http_code
			));
		}

		if (array_key_exists('error', $response)) {
			throw new ApiException(sprintf('API error in "%s": %s %s', $method,
				$response['error']['message'] ?? '', $response['error']['data'] ?? ''
			));
		}

		return $response['result'] ?? null;
	}
}
