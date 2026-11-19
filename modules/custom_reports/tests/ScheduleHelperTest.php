<?php declare(strict_types = 0);

namespace Modules\CustomReports\Tests;

use Modules\CustomReports\Lib\ScheduleHelper;

class ScheduleHelperTest extends TestCase {

	public function testNormalizeKeepsSafeDefaults(): void {
		$schedule = ScheduleHelper::normalize(['name' => '  Daily  ', 'unknown_key' => 'x']);

		$this->assertSame('Daily', $schedule['name']);
		$this->assertArrayNotHasKey('unknown_key', $schedule);
		$this->assertSame(1, $schedule['recipient_access'], 'permissions of recipients are on by default');
		$this->assertSame(ScheduleHelper::FREQUENCY_DAILY, $schedule['frequency']);
	}

	public function testOldSchedulesGetPermissionsOn(): void {
		$stored = ScheduleHelper::defaults();
		unset($stored['recipient_access']);

		$this->assertSame(1, ScheduleHelper::normalize($stored)['recipient_access']);
	}

	public function testDailyOccurrences(): void {
		$schedule = ScheduleHelper::normalize(['frequency' => 'daily', 'time' => '09:00']);
		// 2026-03-10 08:00 UTC.
		$now = gmmktime(8, 0, 0, 3, 10, 2026);

		$this->assertSame(gmmktime(9, 0, 0, 3, 9, 2026), ScheduleHelper::lastOccurrence($schedule, $now, 'UTC'));
		$this->assertSame(gmmktime(9, 0, 0, 3, 10, 2026), ScheduleHelper::nextOccurrence($schedule, $now, 'UTC'));
	}

	public function testWeeklyOccurrenceInTimezone(): void {
		// Monday 09:00 in Moscow (UTC+3) = 06:00 UTC.
		$schedule = ScheduleHelper::normalize(['frequency' => 'weekly', 'weekday' => 1, 'time' => '09:00']);
		// Wednesday 2026-03-11 12:00 UTC.
		$now = gmmktime(12, 0, 0, 3, 11, 2026);

		$this->assertSame(gmmktime(6, 0, 0, 3, 9, 2026), ScheduleHelper::lastOccurrence($schedule, $now, 'Europe/Moscow'));
		$this->assertSame(gmmktime(6, 0, 0, 3, 16, 2026), ScheduleHelper::nextOccurrence($schedule, $now, 'Europe/Moscow'));
	}

	public function testDaylightSavingKeepsLocalTime(): void {
		// Berlin switches to summer time on 2026-03-29: 09:00 local is 08:00 UTC before and 07:00 UTC after.
		$schedule = ScheduleHelper::normalize(['frequency' => 'daily', 'time' => '09:00']);
		$now = gmmktime(10, 0, 0, 3, 28, 2026);

		$this->assertSame(gmmktime(7, 0, 0, 3, 29, 2026), ScheduleHelper::nextOccurrence($schedule, $now, 'Europe/Berlin'));
	}

	public function testIsDue(): void {
		$schedule = ScheduleHelper::normalize(['frequency' => 'daily', 'time' => '09:00', 'enabled' => 1]);
		$occurrence = gmmktime(9, 0, 0, 3, 10, 2026);

		$this->assertTrue(ScheduleHelper::isDue($schedule, [], $occurrence + 30, 3600, 'UTC'));
		$this->assertFalse(ScheduleHelper::isDue($schedule, ['last_run' => $occurrence + 10], $occurrence + 30, 3600, 'UTC'),
			'already sent'
		);
		$this->assertFalse(ScheduleHelper::isDue($schedule, [], $occurrence + 7200, 3600, 'UTC'), 'older than the grace period');

		$schedule['enabled'] = 0;
		$this->assertFalse(ScheduleHelper::isDue($schedule, [], $occurrence + 30, 3600, 'UTC'));
	}

	public function testRunStatus(): void {
		$this->assertSame(ScheduleHelper::STATUS_OK, ScheduleHelper::runStatus(['sent' => 2, 'failed' => 0, 'parts' => 1]));
		$this->assertSame(ScheduleHelper::STATUS_EMPTY, ScheduleHelper::runStatus(['sent' => 0, 'failed' => 0, 'parts' => 0]));
		$this->assertSame(ScheduleHelper::STATUS_PARTIAL, ScheduleHelper::runStatus(['sent' => 1, 'failed' => 1, 'parts' => 1]));
		$this->assertSame(ScheduleHelper::STATUS_ERROR, ScheduleHelper::runStatus(['sent' => 0, 'failed' => 1, 'parts' => 1]));
	}

	public function testResendKeys(): void {
		$delivery = static function (string $recipient, string $status, string $mediatypeid = '50'): array {
			return ['part' => '', 'recipient' => $recipient, 'mediatypeid' => $mediatypeid, 'status' => $status,
				'error' => '', 'time' => 0
			];
		};
		$run = ['trigger' => ScheduleHelper::RUN_SCHEDULE, 'deliveries' => [
			$delivery('a', 'sent'), $delivery('b', 'failed'), $delivery('', 'failed'), $delivery('c', 'skipped'),
			$delivery('d', 'failed', '')
		]];

		$this->assertCount(1, ScheduleHelper::resendKeys($run), 'only failed deliveries to an address with a media type');
		$this->assertSame([], ScheduleHelper::resendKeys(['trigger' => ScheduleHelper::RUN_TEST] + $run), 'not a test');
	}
}
