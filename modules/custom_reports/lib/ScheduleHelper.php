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

	// Common recipients get every part / only parts of groups without own recipients.
	public const COMMON_ALL = 'all';
	public const COMMON_FALLBACK = 'fallback';

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
			'problem_name' => '',
			'acknowledged' => ReportBuilder::ACK_ALL,
			'sortfield' => ReportBuilder::SORT_SEVERITY,
			'sortorder' => ReportBuilder::SORT_DESC,
			'frequency' => self::FREQUENCY_DAILY,
			'weekday' => 1,
			'time' => '09:00',
			'userids' => [],
			'usrgrpids' => [],
			'extra_sendto' => [],
			'mediatypeid' => '0',
			'format' => ReportRenderer::FORMAT_SUMMARY,
			'split' => ReportSplitter::SPLIT_NONE,
			'skip_empty' => 0,
			'group_recipients' => [],
			'host_recipients' => [],
			'common_recipients' => self::COMMON_ALL,
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
			'problem_name' => $filter['problem_name'],
			'acknowledged' => $filter['acknowledged'],
			'sortfield' => $filter['sortfield'],
			'sortorder' => $filter['sortorder'],
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
			'split' => ReportSplitter::isValid((string) $schedule['split'])
				? (string) $schedule['split']
				: ReportSplitter::SPLIT_NONE,
			'skip_empty' => (int) !empty($schedule['skip_empty']),
			'group_recipients' => self::normalizeRecipientRules($schedule['group_recipients'], 'groupids'),
			'host_recipients' => self::normalizeRecipientRules($schedule['host_recipients'], 'hostids'),
			'common_recipients' => $schedule['common_recipients'] === self::COMMON_FALLBACK
				? self::COMMON_FALLBACK
				: self::COMMON_ALL,
			'created_by' => (string) $schedule['created_by'],
			'updated_at' => (int) $schedule['updated_at']
		];
	}

	/**
	 * Per-part recipient rules. For each split mode: schedule field => object ID field of a rule.
	 * A report part (host group or host) is sent to the recipients of every rule that lists its object.
	 */
	public const RECIPIENT_RULES = [
		ReportSplitter::SPLIT_GROUP => ['field' => 'group_recipients', 'key' => 'groupids'],
		ReportSplitter::SPLIT_HOST => ['field' => 'host_recipients', 'key' => 'hostids']
	];

	/**
	 * @param mixed  $rules       List of [<key> => [], 'userids' => [], 'usrgrpids' => [], 'extra_sendto' => ...].
	 * @param string $key         "groupids" or "hostids".
	 * @param bool   $keep_empty  Keep rules without data (rows of the edit form).
	 *
	 * @return array
	 */
	public static function normalizeRecipientRules($rules, string $key, bool $keep_empty = false): array {
		if (!is_array($rules)) {
			return [];
		}

		$result = [];

		foreach ($rules as $rule) {
			if (!is_array($rule)) {
				continue;
			}

			$extra_sendto = $rule['extra_sendto'] ?? [];

			if (!is_array($extra_sendto)) {
				$extra_sendto = preg_split('/[\r\n,;]+/', (string) $extra_sendto);
			}

			$rule = [
				$key => ReportBuilder::ids($rule[$key] ?? []),
				'userids' => ReportBuilder::ids($rule['userids'] ?? []),
				'usrgrpids' => ReportBuilder::ids($rule['usrgrpids'] ?? []),
				'extra_sendto' => RecipientResolver::normalizeSendto($extra_sendto)
			];

			if ($keep_empty || $rule[$key] || self::hasRecipients($rule)) {
				$result[] = $rule;
			}
		}

		return $result;
	}

	public static function hasRecipients(array $recipients): bool {
		return $recipients['userids'] || $recipients['usrgrpids'] || $recipients['extra_sendto'];
	}

	/**
	 * Recipient rules used by the schedule: only those of the current split mode.
	 *
	 * @return array|null  ['rules' => array, 'key' => string] or null if the schedule has no per-part recipients.
	 */
	public static function getActiveRecipientRules(array $schedule): ?array {
		if (!array_key_exists($schedule['split'], self::RECIPIENT_RULES)) {
			return null;
		}

		['field' => $field, 'key' => $key] = self::RECIPIENT_RULES[$schedule['split']];

		return $schedule[$field] ? ['rules' => $schedule[$field], 'key' => $key] : null;
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

		$active_rules = self::getActiveRecipientRules($schedule);

		if (!self::hasRecipients($schedule) && $active_rules === null) {
			$errors[] = 'At least one recipient (user, user group or additional address) is required.';
		}

		if (!self::isValidSendto($schedule['extra_sendto'])) {
			$errors[] = 'Incorrect value for field "Additional recipients".';
		}

		if ($active_rules !== null) {
			$key = $active_rules['key'];
			[$table, $objects, $filter_field] = $key === 'groupids'
				? ['Recipients per host group', 'host groups', 'Host groups']
				: ['Recipients per host', 'hosts', 'Hosts'];

			foreach ($active_rules['rules'] as $index => $rule) {
				$row = $index + 1;

				if (!$rule[$key]) {
					$errors[] = sprintf('%s, row %d: %s must be selected.', $table, $row, $objects);
				}

				if (!self::hasRecipients($rule)) {
					$errors[] = sprintf('%s, row %d: at least one recipient is required.', $table, $row);
				}

				if (!self::isValidSendto($rule['extra_sendto'])) {
					$errors[] = sprintf('%s, row %d: incorrect additional recipients.', $table, $row);
				}

				// With a filter only the filtered objects become report parts.
				if ($schedule[$key] && array_diff($rule[$key], $schedule[$key])) {
					$errors[] = sprintf('%s, row %d: all %s must also be selected in the report filter "%s" '.
						'(or leave the filter empty).', $table, $row, $objects, $filter_field
					);
				}
			}
		}

		return $errors;
	}

	private static function isValidSendto(array $sendto_list): bool {
		foreach ($sendto_list as $sendto) {
			if (mb_strlen($sendto, 'UTF-8') > 1024 || preg_match('/[\x00-\x1F]/', $sendto)) {
				return false;
			}
		}

		return true;
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
