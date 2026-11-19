<?php declare(strict_types = 0);

namespace Modules\CustomReports\Tests;

use Modules\CustomReports\Lib\Logger;
use Modules\CustomReports\Lib\ReportService;
use Modules\CustomReports\Lib\ScheduleHelper;

/**
 * Delivery of schedules through the test alert script: who gets what.
 */
class ReportServiceTest extends TestCase {

	private function service(array $config = []): ReportService {
		return new ReportService($this->api(), new Logger($this->tmp.'/test.log'), $this->config($config));
	}

	private function schedule(array $values): array {
		return ScheduleHelper::normalize($values + [
			'scheduleid' => 'test',
			'name' => 'Daily',
			'mediatypeid' => self::MEDIATYPE_SCRIPT,
			'format' => 'summary',
			'analytics' => 'none'
		]);
	}

	/**
	 * Messages of the alert script by address: [sendto => problem names found in the message].
	 */
	private function received(): array {
		$names = [];

		foreach (self::TRIGGERS as [, , $name]) {
			$names[] = $name;
		}

		$result = [];

		foreach ($this->scriptCalls() as $call) {
			foreach ($names as $name) {
				if (strpos($call['message'], $name) !== false) {
					$result[$call['sendto']][] = $name;
				}
			}

			$result[$call['sendto']] = $result[$call['sendto']] ?? [];
		}

		ksort($result);

		return array_map(static function (array $list): array {
			sort($list);

			return $list;
		}, $result);
	}

	public function testEveryUserGetsOnlyWhatHeMaySee(): void {
		$result = $this->service()->runSchedule($this->schedule([
			'userids' => ['1', '2', '3', '5'],
			'extra_sendto' => 'boss'
		]));

		$all = ['Disk failed', 'High memory', 'Nginx is down', 'Port down', 'Replication lag', 'Shared host info'];

		$this->assertSame([
			'boss' => $all,
			'u1' => $all,
			'u2' => ['High memory', 'Nginx is down', 'Shared host info'],
			'u5' => ['Disk failed']
		], $this->received());

		$this->assertSame(4, $result['sent']);
		$this->assertSame(0, $result['failed']);
		$this->assertSame(1, $result['skipped']);

		$skipped = array_values(array_filter($result['deliveries'], static function (array $d): bool {
			return $d['status'] === 'skipped';
		}));
		$this->assertSame('u3', $skipped[0]['recipient']);
	}

	public function testPermissionsCanBeTurnedOff(): void {
		$result = $this->service()->runSchedule($this->schedule([
			'userids' => ['2', '3'],
			'recipient_access' => 0
		]));

		$this->assertSame(2, $result['sent']);
		$this->assertCount(6, $this->received()['u3']);
	}

	public function testReportIsBuiltOncePerSetOfRights(): void {
		$api = $this->api();
		$service = new ReportService($api, new Logger($this->tmp.'/test.log'), $this->config());
		// Users 2 and 4 differ, 1 is Super admin: three sets of rights, three reports.
		$service->runSchedule($this->schedule(['userids' => ['1', '2', '4']]));

		$this->assertCount(3, $api->paramsOf('problem.get'));
	}

	public function testSplitByGroupSendsOnlyVisibleParts(): void {
		$result = $this->service()->runSchedule($this->schedule([
			'userids' => ['1', '2'],
			'groupids' => ['10', '20'],
			'split' => 'group'
		]));

		$parts = [];

		foreach ($result['deliveries'] as $delivery) {
			$parts[$delivery['recipient']][] = $delivery['part'];
		}

		sort($parts['u1']);
		sort($parts['u2']);
		$this->assertSame(['DB', 'Web'], $parts['u1']);
		// "shared" (Web + DB) is visible to user 2 through Web, so he gets the DB part with its problem only.
		$this->assertSame(['DB', 'Web'], $parts['u2']);

		foreach ($this->scriptCalls() as $call) {
			if ($call['sendto'] === 'u2' && substr($call['subject'], -5) === ' - DB') {
				$this->assertStringContainsString('Shared host info', $call['message']);
				$this->assertStringNotContainsString('Disk failed', $call['message']);
			}
		}

		$result = $this->service()->runSchedule($this->schedule([
			'userids' => ['5'],
			'groupids' => ['10', '20'],
			'split' => 'group'
		]));
		$this->assertSame(['DB'], array_column($result['deliveries'], 'part'), 'Web is denied for user 5');
	}

	public function testResendGoesOnlyToFailedDeliveries(): void {
		$schedule = $this->schedule(['userids' => ['1'], 'extra_sendto' => "boss\nops"]);
		$this->failNextScriptCalls(1);

		$first = $this->service()->runSchedule($schedule);
		$this->assertSame(1, $first['failed']);

		$run = ScheduleHelper::makeRun(ScheduleHelper::RUN_SCHEDULE, self::NOW, $first, null);
		$only = ScheduleHelper::pendingResends([$run])[0];
		$this->assertCount(1, $only);

		$failed = array_values(array_filter($first['deliveries'], static function (array $d): bool {
			return $d['status'] === 'failed';
		}))[0]['recipient'];

		@unlink($this->tmp.'/calls.log');
		$second = $this->service()->runSchedule($schedule, $only);

		$this->assertSame(1, $second['sent']);
		$this->assertSame([$failed], array_keys($this->received()));

		// Delivered by the resend: nothing is left to resend in the first run.
		$resend = ScheduleHelper::makeRun(ScheduleHelper::RUN_RESEND, self::NOW + 60, $second, null);
		$this->assertSame([[], []], ScheduleHelper::pendingResends([$resend, $run]));
	}

	public function testTemporaryFailureIsRetried(): void {
		$this->failNextScriptCalls(2);
		$result = $this->service(['send_retries' => 2])->runSchedule($this->schedule(['extra_sendto' => 'ops']));

		$this->assertSame(1, $result['sent']);
		$this->assertSame(3, $result['deliveries'][0]['attempts']);
	}

	public function testNoRecipientsIsAnError(): void {
		$this->expectException(\RuntimeException::class);
		$this->service()->runSchedule($this->schedule(['userids' => []]));
	}
}
