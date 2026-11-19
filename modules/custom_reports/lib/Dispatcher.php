<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

use Modules\CustomReports\Lib\Transport\ScriptTransport;
use Modules\CustomReports\Lib\Transport\SmtpTransport;
use Modules\CustomReports\Lib\Transport\TelegramTransport;
use RuntimeException;
use Throwable;

/**
 * Delivers a rendered report through a Zabbix media type.
 *
 * Supported media types: Email, Script and Webhook (Telegram). Other webhooks run JavaScript inside
 * Zabbix server and cannot be executed outside of it.
 */
class Dispatcher {

	// MEDIA_TYPE_* constants of the frontend.
	public const TYPE_EMAIL = 0;
	public const TYPE_EXEC = 1;
	public const TYPE_SMS = 2;
	public const TYPE_WEBHOOK = 4;

	public const CHANNEL_EMAIL = 'email';
	public const CHANNEL_TELEGRAM = 'telegram';
	public const CHANNEL_SCRIPT = 'script';
	public const CHANNEL_UNSUPPORTED = 'unsupported';

	// MEDIA_TYPE_STATUS_ACTIVE, ZBX_MEDIA_MESSAGE_FORMAT_HTML.
	private const MEDIA_TYPE_STATUS_ACTIVE = 0;
	private const MESSAGE_FORMAT_HTML = 1;

	private ApiClientInterface $api;
	private Logger $logger;
	private array $config;

	/** @var callable|null */
	private $secret_resolver;

	public function __construct(ApiClientInterface $api, Logger $logger, array $config, ?callable $secret_resolver = null) {
		$this->api = $api;
		$this->logger = $logger;
		$this->config = $config;
		$this->secret_resolver = $secret_resolver;
	}

	public static function getChannel(array $media_type): string {
		switch ((int) $media_type['type']) {
			case self::TYPE_EMAIL:
				return self::CHANNEL_EMAIL;

			case self::TYPE_EXEC:
				return self::CHANNEL_SCRIPT;

			case self::TYPE_WEBHOOK:
				return TelegramTransport::isTelegram($media_type) ? self::CHANNEL_TELEGRAM : self::CHANNEL_UNSUPPORTED;

			default:
				return self::CHANNEL_UNSUPPORTED;
		}
	}

	/**
	 * @throws RuntimeException
	 */
	public function getMediaType(string $mediatypeid): array {
		$media_types = $this->api->call('mediatype.get', [
			'output' => 'extend',
			'mediatypeids' => [$mediatypeid]
		]);

		if (!$media_types) {
			throw new RuntimeException(I18n::s('Media type with ID "%1$s" does not exist or is not accessible.',
				$mediatypeid
			));
		}

		$media_type = reset($media_types);

		if ((int) $media_type['status'] != self::MEDIA_TYPE_STATUS_ACTIVE) {
			throw new RuntimeException(I18n::s('Media type "%1$s" is disabled.', $media_type['name']));
		}

		return $media_type;
	}

	/**
	 * @param array    $media_type  Result of getMediaType().
	 * @param string[] $sendto
	 * @param array    $report      Result of ReportBuilder::build().
	 * @param string   $format      ReportRenderer::FORMAT_*.
	 * @param string   $title
	 *
	 * @return array  ['sent' => int, 'failed' => int, 'errors' => string[],
	 *                 'deliveries' => list of ['recipient', 'status' (sent|failed), 'error', 'time']]
	 */
	public function dispatch(array $media_type, array $sendto, array $report, string $format, string $title): array {
		$result = ['sent' => 0, 'failed' => 0, 'errors' => [], 'deliveries' => []];
		$channel = self::getChannel($media_type);

		try {
			$sender = $this->createSender($media_type, $channel, $report, $format, $title);
		}
		catch (Throwable $e) {
			$result['failed'] = count($sendto);
			$result['errors'][] = $e->getMessage();
			$this->logger->error(sprintf('[%s] %s', $title, $e->getMessage()));

			foreach ($sendto as $recipient) {
				$result['deliveries'][] = ['recipient' => $recipient, 'status' => 'failed', 'error' => $e->getMessage(),
					'time' => time()
				];
			}

			return $result;
		}

		$retries = max(0, (int) ($this->config['send_retries'] ?? 0));
		$delay = max(0, (int) ($this->config['send_retry_delay'] ?? 0));

		foreach ($sendto as $recipient) {
			for ($attempt = 1; ; $attempt++) {
				try {
					$sender($recipient);
					$result['sent']++;
					$result['deliveries'][] = ['recipient' => $recipient, 'status' => 'sent', 'error' => '',
						'time' => time(), 'attempts' => $attempt
					];
					$this->logger->info(sprintf('[%s] sent via "%s" (%s, %s) to %s%s', $title, $media_type['name'], $channel,
						$format, $recipient, $attempt > 1 ? sprintf(' (attempt %d)', $attempt) : ''
					));
					break;
				}
				catch (Throwable $e) {
					if ($attempt <= $retries && self::isRetryable($e)) {
						$pause = $delay * (2 ** ($attempt - 1));
						$this->logger->warning(sprintf('[%s] attempt %d via "%s" to %s failed: %s; retry in %d s.', $title,
							$attempt, $media_type['name'], $recipient, $e->getMessage(), $pause
						));

						if ($pause > 0) {
							($this->sleep)($pause);
						}

						continue;
					}

					$result['failed']++;
					$result['errors'][] = $recipient.': '.$e->getMessage();
					$result['deliveries'][] = ['recipient' => $recipient, 'status' => 'failed', 'error' => $e->getMessage(),
						'time' => time(), 'attempts' => $attempt
					];
					$this->logger->error(sprintf('[%s] failed via "%s" to %s after %d attempt(s): %s', $title,
						$media_type['name'], $recipient, $attempt, $e->getMessage()
					));
					break;
				}
			}
		}

		return $result;
	}

	/**
	 * A temporary failure is worth a retry (no connection, timeout, SMTP 4xx, Telegram 429 / 5xx, script exit code);
	 * a wrong address or configuration is not.
	 */
	public static function isRetryable(Throwable $e): bool {
		$message = $e->getMessage();
		$permanent = [
			'/^SMTP error after .*: 5\d\d/s',
			'/^Telegram API error \(HTTP 4(?!29)\d\d\)/',
			'/does not exist or is not executable/',
			'/is not set in the media type parameters/',
			'/has no sender address/',
			'/password is not available/',
			'/is required for Telegram/'
		];

		foreach ($permanent as $pattern) {
			if (preg_match($pattern, $message)) {
				return false;
			}
		}

		return true;
	}

	/** @var callable  function (int $seconds): void - replaced in tests. */
	private $sleep = 'sleep';

	public function setSleep(callable $sleep): void {
		$this->sleep = $sleep;
	}

	/**
	 * Prepares the message once and returns a function that delivers it to one recipient.
	 */
	private function createSender(array $media_type, string $channel, array $report, string $format,
			string $title): callable {
		$timeout = (int) $this->config['timeout'];
		$subject = self::isScript($report)
			? ScriptReportRenderer::subject($report, $title)
			: I18n::s('%1$s: %2$s active problem(s)', $title, $report['total']);
		$frontend_url = (string) $this->config['frontend_url'];

		switch ($channel) {
			case self::CHANNEL_EMAIL:
				$transport = new SmtpTransport($media_type, $this->getSmtpPassword($media_type), $timeout);
				$attachments = [];

				if ($format === ReportRenderer::FORMAT_HTML) {
					$body = self::html($report, $title, $frontend_url);
					$is_html = true;
				}
				elseif ($format === ReportRenderer::FORMAT_CSV || $format === ReportRenderer::FORMAT_PDF) {
					$body = self::text($report, $title, 0, false);
					$is_html = false;
					[$name, $type, $content] = $this->makeDocument($report, $title, $format);
					$attachments[] = ['name' => $name, 'type' => $type, 'content' => $content];
				}
				else {
					$is_html = (int) ($media_type['message_format'] ?? 0) == self::MESSAGE_FORMAT_HTML;
					$body = self::text($report, $title, 0, $is_html);

					if ($is_html) {
						$body = '<html><body><pre style="font-family:Arial,sans-serif;white-space:pre-wrap">'.
							$body.'</pre></body></html>';
					}
				}

				return static function (string $recipient) use ($transport, $subject, $body, $is_html, $attachments) {
					if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
						throw new RuntimeException(I18n::t('Invalid e-mail address.'));
					}

					$transport->send($recipient, $subject, $body, $is_html, $attachments);
				};

			case self::CHANNEL_TELEGRAM:
				$parameters = TelegramTransport::getParameters($media_type);
				$proxy = TelegramTransport::findProxy($parameters);

				return $this->telegramSender(TelegramTransport::findToken($parameters), $proxy, $report, $format, $title,
					$subject, true
				);

			case self::CHANNEL_SCRIPT:
				$telegram = $this->scriptTelegram($media_type);

				// The bot token of this Telegram script is known: the module sends the messages itself (copyable
				// tables, several messages) and the report file, the script is not run.
				if ($telegram !== null && $telegram['messages']) {
					return $this->telegramSender($telegram['token'], '', $report, $format, $title, $subject, true);
				}

				// The message is the short summary (a whole HTML report does not fit into a command argument), split
				// into several calls if it is long; the report file goes to the last call ({REPORT.FILE}), or - if the
				// bot token is known - the module sends it to the same chat as a document.
				$transport = new ScriptTransport($media_type, (string) $this->config['alert_scripts_path'], $timeout);
				$max_length = (int) $this->config['script_message_max_length'];
				$max_length = $max_length > 0 ? min($max_length, ScriptTransport::MAX_ARGUMENT) : ScriptTransport::MAX_ARGUMENT;
				$markup = $this->config['script_message_format'] === 'html';
				$messages = self::isScript($report)
					? ScriptReportRenderer::renderMessages($report, $title, $max_length, $markup)
					: [self::text($report, $title, $max_length, $markup)];
				$document = $format !== ReportRenderer::FORMAT_SUMMARY
					? $this->makeDocument($report, $title, $format)
					: null;
				$document_sender = $telegram !== null && $document !== null
					? $this->telegramSender($telegram['token'], '', null, $format, $title, $subject, false, $document)
					: null;

				return static function (string $recipient) use ($transport, $subject, $messages, $document,
						$document_sender) {
					$file = null;

					try {
						if ($document !== null && $document_sender === null) {
							$path = tempnam(sys_get_temp_dir(), 'cr_report_');

							if ($path === false || file_put_contents($path, $document[2]) === false) {
								throw new RuntimeException('Cannot write a temporary report file.');
							}

							$file = ['path' => $path, 'name' => $document[0], 'type' => $document[1]];
						}

						$count = count($messages);

						foreach (array_values($messages) as $index => $message) {
							$transport->send($recipient, $count > 1 ? $subject.' ('.($index + 1).'/'.$count.')' : $subject,
								$message, $index == $count - 1 ? $file : null
							);
						}
					}
					finally {
						if ($file !== null) {
							@unlink($file['path']);
						}
					}

					if ($document_sender !== null) {
						$document_sender($recipient);
					}
				};

			default:
				throw new RuntimeException(I18n::s('Media type "%1$s" is not supported: only Email, Script and '.
					'Telegram webhook media types can deliver reports.', $media_type['name']
				));
		}
	}

	/**
	 * Telegram delivery through the Bot API: messages (when $send_messages) and the report file.
	 *
	 * @param array|null $report    Report for the messages (null - only the document).
	 * @param array|null $document  Prepared report file; made from the report and the format if null.
	 */
	private function telegramSender(string $token, string $proxy, ?array $report, string $format, string $title,
			string $subject, bool $send_messages, ?array $document = null): callable {
		$transport = new TelegramTransport($token, (string) $this->config['telegram_api_url'],
			$proxy !== '' ? $proxy : (string) $this->config['proxy'], (int) $this->config['timeout']
		);
		$max_length = (int) $this->config['telegram_max_length'];
		$texts = [];

		if ($send_messages && $report !== null) {
			// A script report may need several messages (a long table continues in the next one).
			$texts = self::isScript($report)
				? ScriptReportRenderer::renderMessages($report, $title, $max_length, true)
				: [self::text($report, $title, $max_length, true)];
		}

		if ($document === null && $report !== null && $format !== ReportRenderer::FORMAT_SUMMARY) {
			$document = $this->makeDocument($report, $title, $format);
		}

		return static function (string $recipient) use ($transport, $texts, $document, $subject) {
			if (!preg_match('/^-?\d+$|^@[A-Za-z0-9_]{5,}$/', $recipient)) {
				throw new RuntimeException(I18n::t('Invalid Telegram chat ID.'));
			}

			foreach ($texts as $text) {
				$transport->sendMessage($recipient, $text);
			}

			if ($document !== null) {
				$transport->sendDocument($recipient, $document[0], $document[2], $document[1], $subject);
			}
		};
	}

	/**
	 * Bot token of a Telegram alert script from "script_telegram" of the config (key: script name, media type
	 * name or ID; value: token, or ['token' => ..., 'messages' => true] to send the messages through the Bot API too).
	 *
	 * @return array|null  ['token' => string, 'messages' => bool]
	 */
	private function scriptTelegram(array $media_type): ?array {
		$map = $this->config['script_telegram'] ?? [];

		if (!is_array($map) || !$map) {
			return null;
		}

		foreach ([basename((string) ($media_type['exec_path'] ?? '')), (string) $media_type['name'],
				(string) $media_type['mediatypeid']] as $key) {
			if ($key === '' || !array_key_exists($key, $map)) {
				continue;
			}

			$value = $map[$key];
			$token = is_array($value) ? (string) ($value['token'] ?? '') : (string) $value;

			if (trim($token) !== '') {
				return ['token' => trim($token), 'messages' => is_array($value) && !empty($value['messages'])];
			}
		}

		return null;
	}

	/**
	 * Report file for e-mail attachments and Telegram documents.
	 *
	 * @return array  [file name, MIME type, content]
	 */
	private function makeDocument(array $report, string $title, string $format): array {
		$time = $report['generated'];

		if ($format === ReportRenderer::FORMAT_CSV) {
			return [ReportRenderer::fileName($title, 'csv', $time), 'text/csv', self::csv($report)];
		}

		$html = self::html($report, $title, (string) $this->config['frontend_url']);

		if ($format === ReportRenderer::FORMAT_PDF) {
			try {
				return [ReportRenderer::fileName($title, 'pdf', $time), 'application/pdf',
					(new PdfConverter($this->config))->convert($html)
				];
			}
			catch (RuntimeException $e) {
				// The report is still delivered, as HTML.
				$this->logger->warning(sprintf('[%s] PDF is not available, sending HTML instead: %s', $title,
					$e->getMessage()
				));
			}
		}

		return [ReportRenderer::fileName($title, 'html', $time), 'text/html', $html];
	}

	/**
	 * Reports of external scripts (ScriptReport) have their own renderer.
	 */
	public static function isScript(array $report): bool {
		return ($report['source'] ?? '') === ScriptReport::SOURCE;
	}

	private static function html(array $report, string $title, string $frontend_url): string {
		return self::isScript($report)
			? ScriptReportRenderer::renderHtml($report, $title)
			: ReportRenderer::renderHtml($report, $title, ['frontend_url' => $frontend_url]);
	}

	private static function text(array $report, string $title, int $max_length, bool $html): string {
		return self::isScript($report)
			? ScriptReportRenderer::renderText($report, $title, $max_length, $html)
			: ReportRenderer::renderText($report, $title, $max_length, $html);
	}

	private static function csv(array $report): string {
		return self::isScript($report) ? ScriptReportRenderer::renderCsv($report) : ReportRenderer::renderCsv($report);
	}

	private function getSmtpPassword(array $media_type): ?string {
		if ((int) ($media_type['smtp_authentication'] ?? 0) == 0) {
			return null;
		}

		if (isset($media_type['passwd']) && $media_type['passwd'] !== '') {
			return (string) $media_type['passwd'];
		}

		$passwords = $this->config['smtp_passwords'];

		if (is_array($passwords) && array_key_exists($media_type['mediatypeid'], $passwords)) {
			return (string) $passwords[$media_type['mediatypeid']];
		}

		if ($this->secret_resolver !== null) {
			return ($this->secret_resolver)((string) $media_type['mediatypeid']);
		}

		return null;
	}
}
