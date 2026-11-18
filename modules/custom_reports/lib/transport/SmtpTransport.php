<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib\Transport;

use RuntimeException;

/**
 * SMTP client configured from a Zabbix "Email" media type.
 *
 * Supported media type settings: smtp_server, smtp_port, smtp_helo, smtp_email, smtp_security
 * (0 - none, 1 - STARTTLS, 2 - SSL/TLS), smtp_verify_peer, smtp_verify_host, smtp_authentication
 * (0 - none, 1 - username and password), username, passwd.
 */
class SmtpTransport {

	private const SECURITY_STARTTLS = 1;
	private const SECURITY_SSL = 2;
	private const AUTH_PASSWORD = 1;

	private array $media_type;
	private ?string $password;
	private int $timeout;

	/** @var resource|null */
	private $socket = null;

	public function __construct(array $media_type, ?string $password, int $timeout = 30) {
		$this->media_type = $media_type;
		$this->password = $password;
		$this->timeout = $timeout;
	}

	/**
	 * @param string $to
	 * @param string $subject
	 * @param string $body
	 * @param bool   $is_html
	 * @param array  $attachments  List of ['name' => ..., 'type' => ..., 'content' => ...].
	 *
	 * @throws RuntimeException
	 */
	public function send(string $to, string $subject, string $body, bool $is_html, array $attachments = []): void {
		[$from_name, $from_address] = self::parseAddress((string) $this->media_type['smtp_email']);

		if ($from_address === '') {
			throw new RuntimeException('Media type has no sender address (smtp_email).');
		}

		$message = $this->buildMessage($from_name, $from_address, $to, $subject, $body, $is_html, $attachments);

		try {
			$this->connect();
			$this->command('MAIL FROM:<'.$from_address.'>', [250]);
			$this->command('RCPT TO:<'.$to.'>', [250, 251]);
			$this->command('DATA', [354]);

			// Dot-stuffing (RFC 5321, 4.5.2).
			$data = preg_replace('/^\./m', '..', $message);
			$this->command($data."\r\n.", [250]);

			try {
				$this->command('QUIT', [221]);
			}
			catch (RuntimeException $e) {
				// The message is already accepted.
			}
		}
		finally {
			$this->disconnect();
		}
	}

	private function connect(): void {
		$mt = $this->media_type;
		$host = (string) $mt['smtp_server'];
		$port = (int) $mt['smtp_port'] ?: 25;
		$verify_peer = (bool) ($mt['smtp_verify_peer'] ?? 0);
		$verify_host = (bool) ($mt['smtp_verify_host'] ?? 0);
		$security = (int) ($mt['smtp_security'] ?? 0);

		$context = stream_context_create(['ssl' => [
			'verify_peer' => $verify_peer,
			'verify_peer_name' => $verify_host,
			'allow_self_signed' => !$verify_peer,
			'peer_name' => $host,
			'SNI_enabled' => true
		]]);

		$remote = ($security == self::SECURITY_SSL ? 'ssl://' : 'tcp://').$host.':'.$port;
		$socket = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);

		if ($socket === false) {
			throw new RuntimeException(sprintf('Cannot connect to SMTP server %s: %s (%d).', $remote, $errstr, $errno));
		}

		stream_set_timeout($socket, $this->timeout);
		$this->socket = $socket;

		$this->expect([220]);

		$helo = (string) ($mt['smtp_helo'] ?? '');

		if ($helo === '') {
			$helo = gethostname() ?: 'localhost';
		}

		$this->command('EHLO '.$helo, [250]);

		if ($security == self::SECURITY_STARTTLS) {
			$this->command('STARTTLS', [220]);

			$crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;

			if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
				$crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
			}

			if (!@stream_socket_enable_crypto($this->socket, true, $crypto)) {
				throw new RuntimeException('STARTTLS negotiation failed.');
			}

			$this->command('EHLO '.$helo, [250]);
		}

		if ((int) ($mt['smtp_authentication'] ?? 0) == self::AUTH_PASSWORD) {
			if ($this->password === null || $this->password === '') {
				throw new RuntimeException('SMTP authentication is enabled, but the password is not available '.
					'(set "smtp_passwords" in conf/config.php or enable "db_fallback").'
				);
			}

			$this->command('AUTH LOGIN', [334]);
			$this->command(base64_encode((string) $mt['username']), [334]);
			$this->command(base64_encode($this->password), [235], true);
		}
	}

	private function disconnect(): void {
		if (is_resource($this->socket)) {
			fclose($this->socket);
		}

		$this->socket = null;
	}

	private function command(string $command, array $codes, bool $secret = false): string {
		if (@fwrite($this->socket, $command."\r\n") === false) {
			throw new RuntimeException('Cannot write to SMTP server.');
		}

		return $this->expect($codes, $secret ? '***' : strtok($command, "\r\n"));
	}

	private function expect(array $codes, string $command = 'connect'): string {
		$response = '';

		while (($line = fgets($this->socket, 4096)) !== false) {
			$response .= $line;

			// Multi-line replies have "-" after the code, the last line has a space.
			if (strlen($line) < 4 || $line[3] !== '-') {
				break;
			}
		}

		if ($response === '') {
			$meta = stream_get_meta_data($this->socket);

			throw new RuntimeException(sprintf('No response from SMTP server after "%s"%s.', $command,
				$meta['timed_out'] ? ' (timeout)' : ''
			));
		}

		$code = (int) substr($response, 0, 3);

		if (!in_array($code, $codes, true)) {
			throw new RuntimeException(sprintf('SMTP error after "%s": %s', substr($command, 0, 60), trim($response)));
		}

		return $response;
	}

	private function buildMessage(string $from_name, string $from_address, string $to, string $subject, string $body,
			bool $is_html, array $attachments): string {
		$domain = substr(strrchr($from_address, '@') ?: '@localhost', 1);
		$from = $from_name !== '' ? self::encodeHeader($from_name).' <'.$from_address.'>' : $from_address;

		$headers = [
			'Date: '.date('r'),
			'From: '.$from,
			'To: <'.$to.'>',
			'Subject: '.self::encodeHeader($subject),
			'Message-ID: <'.bin2hex(random_bytes(12)).'@'.$domain.'>',
			'MIME-Version: 1.0',
			'Auto-Submitted: auto-generated'
		];

		$body_part = 'Content-Type: '.($is_html ? 'text/html' : 'text/plain').'; charset=UTF-8'."\r\n".
			'Content-Transfer-Encoding: base64'."\r\n\r\n".
			rtrim(chunk_split(base64_encode($body), 76, "\r\n"));

		if (!$attachments) {
			return implode("\r\n", $headers)."\r\n".$body_part;
		}

		$boundary = '=_cr_'.bin2hex(random_bytes(12));
		$headers[] = 'Content-Type: multipart/mixed; boundary="'.$boundary.'"';

		$message = implode("\r\n", $headers)."\r\n\r\n".'This is a multi-part message in MIME format.'."\r\n\r\n".
			'--'.$boundary."\r\n".$body_part."\r\n";

		foreach ($attachments as $attachment) {
			$name = str_replace(['"', "\r", "\n"], '', $attachment['name']);

			$message .= '--'.$boundary."\r\n".
				'Content-Type: '.$attachment['type'].'; name="'.$name.'"'."\r\n".
				'Content-Transfer-Encoding: base64'."\r\n".
				'Content-Disposition: attachment; filename="'.$name.'"'."\r\n\r\n".
				rtrim(chunk_split(base64_encode($attachment['content']), 76, "\r\n"))."\r\n";
		}

		return $message.'--'.$boundary.'--';
	}

	/**
	 * Parses "Name <user@example.com>" or "user@example.com".
	 *
	 * @return array  [name, address]
	 */
	public static function parseAddress(string $value): array {
		$value = trim($value);

		if (preg_match('/^(.*)<([^<>]+)>$/', $value, $matches)) {
			return [trim($matches[1], " \t\""), trim($matches[2])];
		}

		return ['', $value];
	}

	private static function encodeHeader(string $value): string {
		$value = str_replace(["\r", "\n"], ' ', $value);

		return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?'.base64_encode($value).'?=' : $value;
	}
}
