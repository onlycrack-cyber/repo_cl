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
			throw new RuntimeException(sprintf('Media type with ID "%s" does not exist or is not accessible.',
				$mediatypeid
			));
		}

		$media_type = reset($media_types);

		if ((int) $media_type['status'] != self::MEDIA_TYPE_STATUS_ACTIVE) {
			throw new RuntimeException(sprintf('Media type "%s" is disabled.', $media_type['name']));
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
	 * @return array  ['sent' => int, 'failed' => int, 'errors' => string[]]
	 */
	public function dispatch(array $media_type, array $sendto, array $report, string $format, string $title): array {
		$result = ['sent' => 0, 'failed' => 0, 'errors' => []];
		$channel = self::getChannel($media_type);

		try {
			$sender = $this->createSender($media_type, $channel, $report, $format, $title);
		}
		catch (Throwable $e) {
			$result['failed'] = count($sendto);
			$result['errors'][] = $e->getMessage();
			$this->logger->error(sprintf('[%s] %s', $title, $e->getMessage()));

			return $result;
		}

		foreach ($sendto as $recipient) {
			try {
				$sender($recipient);
				$result['sent']++;
				$this->logger->info(sprintf('[%s] sent via "%s" (%s, %s) to %s', $title, $media_type['name'], $channel,
					$format, $recipient
				));
			}
			catch (Throwable $e) {
				$result['failed']++;
				$result['errors'][] = $recipient.': '.$e->getMessage();
				$this->logger->error(sprintf('[%s] failed via "%s" to %s: %s', $title, $media_type['name'], $recipient,
					$e->getMessage()
				));
			}
		}

		return $result;
	}

	/**
	 * Prepares the message once and returns a function that delivers it to one recipient.
	 */
	private function createSender(array $media_type, string $channel, array $report, string $format,
			string $title): callable {
		$timeout = (int) $this->config['timeout'];
		$subject = sprintf('%s: %d active problem(s)', $title, $report['total']);
		$frontend_url = (string) $this->config['frontend_url'];

		switch ($channel) {
			case self::CHANNEL_EMAIL:
				$transport = new SmtpTransport($media_type, $this->getSmtpPassword($media_type), $timeout);
				$attachments = [];

				if ($format === ReportRenderer::FORMAT_HTML) {
					$body = ReportRenderer::renderHtml($report, $title, ['frontend_url' => $frontend_url]);
					$is_html = true;
				}
				elseif ($format === ReportRenderer::FORMAT_CSV || $format === ReportRenderer::FORMAT_PDF) {
					$body = ReportRenderer::renderText($report, $title, 0, false);
					$is_html = false;
					[$name, $type, $content] = $this->makeDocument($report, $title, $format);
					$attachments[] = ['name' => $name, 'type' => $type, 'content' => $content];
				}
				else {
					$is_html = (int) ($media_type['message_format'] ?? 0) == self::MESSAGE_FORMAT_HTML;
					$body = ReportRenderer::renderText($report, $title, 0, $is_html);

					if ($is_html) {
						$body = '<html><body><pre style="font-family:Arial,sans-serif;white-space:pre-wrap">'.
							$body.'</pre></body></html>';
					}
				}

				return static function (string $recipient) use ($transport, $subject, $body, $is_html, $attachments) {
					if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
						throw new RuntimeException('Invalid e-mail address.');
					}

					$transport->send($recipient, $subject, $body, $is_html, $attachments);
				};

			case self::CHANNEL_TELEGRAM:
				$parameters = TelegramTransport::getParameters($media_type);
				$proxy = TelegramTransport::findProxy($parameters);
				$transport = new TelegramTransport(TelegramTransport::findToken($parameters),
					(string) $this->config['telegram_api_url'], $proxy !== '' ? $proxy : (string) $this->config['proxy'],
					$timeout
				);
				$text = ReportRenderer::renderText($report, $title, (int) $this->config['telegram_max_length'], true);
				$document = null;

				if ($format !== ReportRenderer::FORMAT_SUMMARY) {
					$document = $this->makeDocument($report, $title, $format);
				}

				return static function (string $recipient) use ($transport, $text, $document, $subject) {
					if (!preg_match('/^-?\d+$|^@[A-Za-z0-9_]{5,}$/', $recipient)) {
						throw new RuntimeException('Invalid Telegram chat ID.');
					}

					$transport->sendMessage($recipient, $text);

					if ($document !== null) {
						$transport->sendDocument($recipient, $document[0], $document[2], $document[1], $subject);
					}
				};

			case self::CHANNEL_SCRIPT:
				$transport = new ScriptTransport($media_type, (string) $this->config['alert_scripts_path'], $timeout);
				$message = $format === ReportRenderer::FORMAT_HTML
					? ReportRenderer::renderHtml($report, $title, ['frontend_url' => $frontend_url])
					: ReportRenderer::renderText($report, $title, 0, false);

				return static function (string $recipient) use ($transport, $subject, $message) {
					$transport->send($recipient, $subject, $message);
				};

			default:
				throw new RuntimeException(sprintf('Media type "%s" is not supported: only Email, Script and '.
					'Telegram webhook media types can deliver reports.', $media_type['name']
				));
		}
	}

	/**
	 * Report file for e-mail attachments and Telegram documents.
	 *
	 * @return array  [file name, MIME type, content]
	 */
	private function makeDocument(array $report, string $title, string $format): array {
		$time = $report['generated'];

		if ($format === ReportRenderer::FORMAT_CSV) {
			return [ReportRenderer::fileName($title, 'csv', $time), 'text/csv', ReportRenderer::renderCsv($report)];
		}

		$html = ReportRenderer::renderHtml($report, $title, ['frontend_url' => (string) $this->config['frontend_url']]);

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
