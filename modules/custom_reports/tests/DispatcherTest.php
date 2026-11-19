<?php declare(strict_types = 0);

namespace Modules\CustomReports\Tests;

use Modules\CustomReports\Lib\Dispatcher;
use Modules\CustomReports\Lib\Logger;
use Modules\CustomReports\Lib\ReportBuilder;
use RuntimeException;

class DispatcherTest extends TestCase {

	/**
	 * @dataProvider errors
	 */
	public function testRetryableErrors(string $message, bool $retryable): void {
		$this->assertSame($retryable, Dispatcher::isRetryable(new RuntimeException($message)), $message);
	}

	public static function errors(): array {
		return [
			['Cannot connect to SMTP server tcp://mail:25: Connection refused (111).', true],
			['No response from SMTP server after "DATA".', true],
			['SMTP error after "RCPT TO:<x@y>": 450 mailbox busy', true],
			['SMTP error after "RCPT TO:<x@y>": 550 user unknown', false],
			['Telegram API error (HTTP 429): Too Many Requests', true],
			['Telegram API error (HTTP 502): Bad Gateway', true],
			['Telegram API error (HTTP 400): chat not found', false],
			['Telegram API error (HTTP 403): bot was blocked by the user', false],
			['Telegram request failed: Operation timed out', true],
			['Alert script "/x/y.sh" does not exist or is not executable.', false],
			['Alert script "y.sh" exited with code 1: error', true],
			['Media type has no sender address (smtp_email).', false]
		];
	}

	public function testRetriesWithDoublingPause(): void {
		$pauses = [];
		$dispatcher = new Dispatcher($this->api(), new Logger($this->tmp.'/test.log'),
			$this->config(['send_retries' => 3, 'send_retry_delay' => 5])
		);
		$dispatcher->setSleep(static function (int $seconds) use (&$pauses): void {
			$pauses[] = $seconds;
		});

		$this->failNextScriptCalls(5);
		$report = (new ReportBuilder($this->api()))->build(['analytics' => 'none'], self::NOW);
		$result = $dispatcher->dispatch($dispatcher->getMediaType(self::MEDIATYPE_SCRIPT), ['a'], $report, 'summary', 'T');

		$this->assertSame([5, 10, 20], $pauses);
		$this->assertSame(1, $result['failed']);
		$this->assertSame(4, $result['deliveries'][0]['attempts']);
		$this->assertSame([], $this->scriptCalls());
	}

	public function testPermanentErrorIsNotRetried(): void {
		$pauses = 0;
		$dispatcher = new Dispatcher($this->api(), new Logger($this->tmp.'/test.log'),
			$this->config(['send_retries' => 3, 'alert_scripts_path' => $this->tmp.'/missing'])
		);
		$dispatcher->setSleep(static function () use (&$pauses): void {
			$pauses++;
		});

		$report = (new ReportBuilder($this->api()))->build(['analytics' => 'none'], self::NOW);
		$result = $dispatcher->dispatch($dispatcher->getMediaType(self::MEDIATYPE_SCRIPT), ['a'], $report, 'summary', 'T');

		$this->assertSame(0, $pauses);
		$this->assertSame(1, $result['deliveries'][0]['attempts']);
	}
}
