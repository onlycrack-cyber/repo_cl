<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Schedule normalization, validation and due-time calculation.
 */
class ScheduleHelper {

	public const FREQUENCY_DAILY = 'daily';
	public const FREQUENCY_WEEKLY = 'weekly';

	public const WEEKDAYS = [
		1 => 'Monday',
		2 => 'Tuesday',
		3 => 'Wednesday',
		4 => 'Thursday',
		5 => 'Friday',
		6 => 'Saturday',
		7 => 'Sunday'
	];

	public static function defaults(): array {
		return [
			'scheduleid' => '',
			'name' => '',
			'enabled' => 1,
			'groupids' => [],
			'hostids' => [],
			'severities' => [],
			'period' => Period::ALL,
			'show_suppressed' => 0,
			'frequency' => self::FREQUENCY_DAILY,
			'weekday' => 1,
			'time' => '09:00',
			'userids' => [],
			'usrgrpids' => [],
			'extra_sendto' => [],
			'mediatypeid' => '0',
			'format' => ReportRenderer::FORMAT_SUMMARY,
			'created_by' => '0',
			'updated_at' => 0
		];
	}

	public static function normalize(array $input): array {
		$schedule = array_replace(self::defaults(), array_intersect_key($input, self::defaults()));
		$filter = ReportBuilder::normalizeFilter($schedule);

		$extra_sendto = $schedule['extra_sendto'];

		if (!is_array($extra_sendto)) {
			$extra_sendto = preg_split('/[\r\n,;]+/', (string) $extra_sendto);
		}

		return [
			'scheduleid' => (string) $schedule['scheduleid'],
			'name' => trim((string) $schedule['name']),
			'enabled' => (int) !empty($schedule['enabled']),
			'groupids' => $filter['groupids'],
			'hostids' => $filter['hostids'],
			'severities' => $filter['severities'],
			'period' => $filter['period'],
			'show_suppressed' => $filter['show_suppressed'],
			'frequency' => $schedule['frequency'] === self::FREQUENCY_WEEKLY ? self::FREQUENCY_WEEKLY : self::FREQUENCY_DAILY,
			'weekday' => min(7, max(1, (int) $schedule['weekday'])),
			'time' => trim((string) $schedule['time']),
			'userids' => ReportBuilder::ids($schedule['userids']),
			'usrgrpids' => ReportBuilder::ids($schedule['usrgrpids']),
			'extra_sendto' => RecipientResolver::normalizeSendto($extra_sendto),
			'mediatypeid' => (string) $schedule['mediatypeid'],
			'format' => in_array($schedule['format'], ReportRenderer::FORMATS, true)
				? $schedule['format']
				: ReportRenderer::FORMAT_SUMMARY,
			'created_by' => (string) $schedule['created_by'],
			'updated_at' => (int) $schedule['updated_at']
		];
	}

	/**
	 * @return string[]  Validation errors.
	 */
	public static function validate(array $schedule): array {
		$errors = [];

		if ($schedule['name'] === '') {
			$errors[] = 'Incorrect value for field "Name": cannot be empty.';
		}
		elseif (mb_strlen($schedule['name'], 'UTF-8') > 255) {
			$errors[] = 'Incorrect value for field "Name": value is too long.';
		}

		if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $schedule['time'])) {
			$errors[] = 'Incorrect value for field "Time": expected format is hh:mm (00:00-23:59).';
		}

		if (!ctype_digit($schedule['mediatypeid']) || $schedule['mediatypeid'] === '0') {
			$errors[] = 'Incorrect value for field "Media type": a media type must be selected.';
		}

		if (!$schedule['userids'] && !$schedule['usrgrpids'] && !$schedule['extra_sendto']) {
			$errors[] = 'At least one recipient (user, user group or additional address) is required.';
		}

		foreach ($schedule['extra_sendto'] as $sendto) {
			if (mb_strlen($sendto, 'UTF-8') > 1024 || preg_match('/[\x00-\x1F]/', $sendto)) {
				$errors[] = 'Incorrect value for field "Additional recipients".';
				break;
			}
		}

		return $errors;
	}

	/**
	 * The latest scheduled moment that is not later than $now.
	 */
	public static function lastOccurrence(array $schedule, int $now, string $timezone): int {
		[$hours, $minutes] = array_map('intval', explode(':', $schedule['time']) + [1 => 0]);

		$tz = new DateTimeZone($timezone);
		$current = (new DateTimeImmutable('@'.$now))->setTimezone($tz);
		$candidate = $current->setTime($hours, $minutes);

		if ($schedule['frequency'] === self::FREQUENCY_WEEKLY) {
			$days_back = ((int) $current->format('N') - (int) $schedule['weekday'] + 7) % 7;
			$candidate = $candidate->modify('-'.$days_back.' days')->setTime($hours, $minutes);

			if ($candidate->getTimestamp() > $now) {
				$candidate = $candidate->modify('-7 days')->setTime($hours, $minutes);
			}
		}
		elseif ($candidate->getTimestamp() > $now) {
			$candidate = $candidate->modify('-1 day')->setTime($hours, $minutes);
		}

		return $candidate->getTimestamp();
	}

	/**
	 * A schedule is due when its latest occurrence is after both the last run and the last edit,
	 * and not older than the grace period (a missed run is sent once cron is back).
	 */
	public static function isDue(array $schedule, array $state, int $now, int $grace_period, string $timezone): bool {
		if (!$schedule['enabled']) {
			return false;
		}

		$occurrence = self::lastOccurrence($schedule, $now, $timezone);
		$reference = max((int) ($state['last_run'] ?? 0), (int) $schedule['updated_at']);

		return $occurrence > $reference && $now - $occurrence <= $grace_period;
	}

	public static function describe(array $schedule): string {
		if ($schedule['frequency'] === self::FREQUENCY_WEEKLY) {
			return sprintf('Weekly on %s at %s', self::WEEKDAYS[$schedule['weekday']], $schedule['time']);
		}

		return sprintf('Daily at %s', $schedule['time']);
	}
}
