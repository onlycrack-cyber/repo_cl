<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib\Transport;

use CURLFile;
use RuntimeException;

/**
 * Telegram Bot API client. The bot token is taken from the parameters of a Zabbix "Webhook"
 * media type (Token / api_token), the chat ID from the user media "Send to" field.
 */
class TelegramTransport {

	private string $token;
	private string $api_url;
	private string $proxy;
	private int $timeout;

	public function __construct(string $token, string $api_url = 'https://api.telegram.org', string $proxy = '',
			int $timeout = 30) {
		if (!function_exists('curl_init')) {
			throw new RuntimeException('PHP extension "curl" is required for Telegram.');
		}

		if ($token === '' || strpos($token, '<') !== false) {
			throw new RuntimeException('Telegram bot token is not set in the media type parameters.');
		}

		$this->token = $token;
		$this->api_url = rtrim($api_url, '/');
		$this->proxy = $proxy;
		$this->timeout = $timeout;
	}

	/**
	 * Media type parameters as name => value.
	 */
	public static function getParameters(array $media_type): array {
		$parameters = [];

		foreach ($media_type['parameters'] ?? [] as $parameter) {
			if (array_key_exists('name', $parameter)) {
				$parameters[$parameter['name']] = (string) $parameter['value'];
			}
		}

		return $parameters;
	}

	/**
	 * Detects a Telegram webhook by its parameters or script.
	 */
	public static function isTelegram(array $media_type): bool {
		$parameters = self::getParameters($media_type);

		return self::findToken($parameters) !== ''
			|| stripos($media_type['name'] ?? '', 'telegram') !== false
			|| stripos($media_type['script'] ?? '', 'api.telegram.org') !== false;
	}

	public static function findToken(array $parameters): string {
		foreach (['Token', 'api_token', 'token', 'telegramToken', 'bot_token'] as $name) {
			if (array_key_exists($name, $parameters) && $parameters[$name] !== '') {
				return $parameters[$name];
			}
		}

		return '';
	}

	public static function findProxy(array $parameters): string {
		foreach (['HTTPProxy', 'api_proxy', 'proxy'] as $name) {
			if (array_key_exists($name, $parameters) && $parameters[$name] !== '') {
				return $parameters[$name];
			}
		}

		return '';
	}

	public function sendMessage(string $chat_id, string $text): void {
		$this->request('sendMessage', [
			'chat_id' => $chat_id,
			'text' => $text,
			'parse_mode' => 'HTML',
			'disable_web_page_preview' => 'true'
		]);
	}

	public function sendDocument(string $chat_id, string $file_name, string $content, string $mime_type,
			string $caption = ''): void {
		$tmp_file = tempnam(sys_get_temp_dir(), 'crtg');

		if ($tmp_file === false || file_put_contents($tmp_file, $content) === false) {
			throw new RuntimeException('Cannot create a temporary file for the Telegram document.');
		}

		try {
			$this->request('sendDocument', [
				'chat_id' => $chat_id,
				'caption' => mb_substr($caption, 0, 1024, 'UTF-8'),
				'document' => new CURLFile($tmp_file, $mime_type, $file_name)
			]);
		}
		finally {
			@unlink($tmp_file);
		}
	}

	private function request(string $method, array $fields): void {
		$ch = curl_init($this->api_url.'/bot'.$this->token.'/'.$method);
		$options = [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $fields,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout)
		];

		if ($this->proxy !== '') {
			$options[CURLOPT_PROXY] = $this->proxy;
		}

		curl_setopt_array($ch, $options);
		$body = curl_exec($ch);
		$http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		if ($body === false) {
			throw new RuntimeException('Telegram request failed: '.$error);
		}

		$response = json_decode($body, true);

		if (!is_array($response) || empty($response['ok'])) {
			throw new RuntimeException(sprintf('Telegram API error (HTTP %d): %s', $http_code,
				is_array($response) ? ($response['description'] ?? 'unknown error') : substr((string) $body, 0, 200)
			));
		}
	}
}
