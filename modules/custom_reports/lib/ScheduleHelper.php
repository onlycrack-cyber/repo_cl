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

	// Data source of a report: problems of Zabbix or the output of an external script (ScriptReport).
	public const SOURCE_PROBLEMS = 'problems';
	public const SOURCE_SCRIPT = 'script';

	// Media: one media type for all recipients / every user through his own active media.
	public const MEDIA_FIXED = 'fixed';
	public const MEDIA_USER = 'user';

	public const FREQUENCY_DAILY = 'daily';
	public const FREQUENCY_WEEKLY = 'weekly';

	// Delivery history: runs kept per schedule, recipients kept per run.
	public const HISTORY_RUNS = 50;
	public const HISTORY_DELIVERIES = 300;

	// Result of a run: every message delivered / some failed / none delivered / no problems, nothing sent.
	public const STATUS_OK = 'ok';
	public const STATUS_PARTIAL = 'partial';
	public const STATUS_ERROR = 'error';
	public const STATUS_EMPTY = 'empty';

	// What started a run.
	public const RUN_SCHEDULE = 'schedule';
	public const RUN_MANUAL = 'manual';
	public const RUN_TEST = 'test';

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
			'source' => self::SOURCE_PROBLEMS,
			'script' => '',
			'script_params' => [],
			'script_format' => ScriptReport::FORMAT_AUTO,
			'groupids' => [],
			'hostids' => [],
			'severities' => [],
			'period' => Period::ALL,
			'show_suppressed' => 0,
			'problem_name' => '',
			'acknowledged' => ReportBuilder::ACK_ALL,
			'sortfield' => ReportBuilder::SORT_SEVERITY,
			'sortorder' => ReportBuilder::SORT_DESC,
			'analytics' => PeriodAnalytics::PERIOD_AUTO,
			'problem_list' => ReportBuilder::LIST_FULL,
			'include_changes' => 0,
			'frequency' => self::FREQUENCY_DAILY,
			'weekday' => 1,
			'time' => '09:00',
			'userids' => [],
			'usrgrpids' => [],
			'extra_sendto' => [],
			'mediatypeid' => '0',
			'media_mode' => self::MEDIA_FIXED,
			'format' => ReportRenderer::FORMAT_SUMMARY,
			'split' => ReportSplitter::SPLIT_NONE,
			'skip_empty' => 0,
			// Every user gets only the problems of the hosts he may see in Zabbix.
			'recipient_access' => 1,
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

		$source = $schedule['source'] === self::SOURCE_SCRIPT ? self::SOURCE_SCRIPT : self::SOURCE_PROBLEMS;

		return [
			'scheduleid' => (string) $schedule['scheduleid'],
			'name' => trim((string) $schedule['name']),
			'enabled' => (int) !empty($schedule['enabled']),
			'source' => $source,
			'script' => trim((string) $schedule['script']),
			'script_params' => ScriptReport::normalizeParams($schedule['script_params']),
			'script_format' => in_array($schedule['script_format'], ScriptReport::FORMATS, true)
				? $schedule['script_format']
				: ScriptReport::FORMAT_AUTO,
			'groupids' => $filter['groupids'],
			'hostids' => $filter['hostids'],
			'severities' => $filter['severities'],
			'period' => $filter['period'],
			'show_suppressed' => $filter['show_suppressed'],
			'problem_name' => $filter['problem_name'],
			'acknowledged' => $filter['acknowledged'],
			'sortfield' => $filter['sortfield'],
			'sortorder' => $filter['sortorder'],
			'analytics' => in_array($schedule['analytics'], PeriodAnalytics::PERIODS, true)
				? $schedule['analytics']
				: PeriodAnalytics::PERIOD_AUTO,
			'problem_list' => $filter['problem_list'],
			'include_changes' => (int) !empty($schedule['include_changes']),
			'frequency' => $schedule['frequency'] === self::FREQUENCY_WEEKLY ? self::FREQUENCY_WEEKLY : self::FREQUENCY_DAILY,
			'weekday' => min(7, max(1, (int) $schedule['weekday'])),
			'time' => trim((string) $schedule['time']),
			'userids' => ReportBuilder::ids($schedule['userids']),
			'usrgrpids' => ReportBuilder::ids($schedule['usrgrpids']),
			'extra_sendto' => RecipientResolver::normalizeSendto($extra_sendto),
			'mediatypeid' => (string) $schedule['mediatypeid'],
			'media_mode' => $schedule['media_mode'] === self::MEDIA_USER ? self::MEDIA_USER : self::MEDIA_FIXED,
			'format' => in_array($schedule['format'], ReportRenderer::FORMATS, true)
				? $schedule['format']
				: ReportRenderer::FORMAT_SUMMARY,
			'split' => $source === self::SOURCE_PROBLEMS && ReportSplitter::isValid((string) $schedule['split'])
				? (string) $schedule['split']
				: ReportSplitter::SPLIT_NONE,
			'skip_empty' => (int) !empty($schedule['skip_empty']),
			'recipient_access' => (int) !empty($schedule['recipient_access']),
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

	/**
	 * Additional addresses of the schedule or of its active recipient rules.
	 */
	public static function hasExtraSendto(array $schedule): bool {
		if ($schedule['extra_sendto']) {
			return true;
		}

		foreach (self::getActiveRecipientRules($schedule)['rules'] ?? [] as $rule) {
			if ($rule['extra_sendto']) {
				return true;
			}
		}

		return false;
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
			$errors[] = I18n::t('Incorrect value for field "Name": cannot be empty.');
		}
		elseif (mb_strlen($schedule['name'], 'UTF-8') > 255) {
			$errors[] = I18n::t('Incorrect value for field "Name": value is too long.');
		}

		if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $schedule['time'])) {
			$errors[] = I18n::t('Incorrect value for field "Time": expected format is hh:mm (00:00-23:59).');
		}

		if ($schedule['source'] === self::SOURCE_SCRIPT) {
			if ($schedule['script'] === '') {
				$errors[] = I18n::t('Incorrect value for field "Script": a script must be selected.');
			}
			elseif (!ScriptReport::isValidName($schedule['script'])) {
				$errors[] = I18n::s('Incorrect script name "%1$s".', $schedule['script']);
			}
		}

		if (!ctype_digit($schedule['mediatypeid'])) {
			$errors[] = I18n::t('Incorrect value for field "Media type": a media type must be selected.');
		}
		elseif ($schedule['mediatypeid'] === '0') {
			// "Own media of every user": the media type is needed only for additional addresses.
			if ($schedule['media_mode'] !== self::MEDIA_USER) {
				$errors[] = I18n::t('Incorrect value for field "Media type": a media type must be selected.');
			}
			elseif (self::hasExtraSendto($schedule)) {
				$errors[] = I18n::t('Select a media type for the additional recipients (chat ID, e-mail, ...), or remove them.');
			}
		}

		$active_rules = self::getActiveRecipientRules($schedule);

		if (!self::hasRecipients($schedule) && $active_rules === null) {
			$errors[] = I18n::t('At least one recipient (user, user group or additional address) is required.');
		}

		if (!self::isValidSendto($schedule['extra_sendto'])) {
			$errors[] = I18n::t('Incorrect value for field "Additional recipients".');
		}

		if ($active_rules !== null) {
			$key = $active_rules['key'];
			[$table, $not_selected, $not_in_filter] = $key === 'groupids'
				? [
					I18n::t('Recipients per host group'),
					I18n::t('host groups must be selected'),
					I18n::t('all host groups must also be selected in the report filter "Host groups" (or leave the filter empty)')
				]
				: [
					I18n::t('Recipients per host'),
					I18n::t('hosts must be selected'),
					I18n::t('all hosts must also be selected in the report filter "Hosts" (or leave the filter empty)')
				];

			foreach ($active_rules['rules'] as $index => $rule) {
				$row = $index + 1;

				if (!$rule[$key]) {
					$errors[] = I18n::s('%1$s, row %2$s: %3$s.', $table, $row, $not_selected);
				}

				if (!self::hasRecipients($rule)) {
					$errors[] = I18n::s('%1$s, row %2$s: at least one recipient is required.', $table, $row);
				}

				if (!self::isValidSendto($rule['extra_sendto'])) {
					$errors[] = I18n::s('%1$s, row %2$s: incorrect additional recipients.', $table, $row);
				}

				// With a filter only the filtered objects become report parts.
				if ($schedule[$key] && array_diff($rule[$key], $schedule[$key])) {
					$errors[] = I18n::s('%1$s, row %2$s: %3$s.', $table, $row, $not_in_filter);
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
	 * The next scheduled moment after $now.
	 */
	public static function nextOccurrence(array $schedule, int $now, string $timezone): int {
		[$hours, $minutes] = array_map('intval', explode(':', $schedule['time']) + [1 => 0]);

		$last = (new DateTimeImmutable('@'.self::lastOccurrence($schedule, $now, $timezone)))
			->setTimezone(new DateTimeZone($timezone));
		$next = $last->modify($schedule['frequency'] === self::FREQUENCY_WEEKLY ? '+7 days' : '+1 day')
			->setTime($hours, $minutes);

		return $next->getTimestamp();
	}

	public static function runStatus(array $result): string {
		if ($result['failed'] == 0) {
			return $result['parts'] == 0 ? self::STATUS_EMPTY : self::STATUS_OK;
		}

		return $result['sent'] > 0 ? self::STATUS_PARTIAL : self::STATUS_ERROR;
	}

	/**
	 * Record of a run for the delivery history.
	 *
	 * @param string      $trigger  RUN_*.
	 * @param int         $started  Unix time.
	 * @param array|null  $result   Result of ReportService (null if the run failed before delivery).
	 * @param string|null $error    Error that stopped the run (no recipients, disabled media type, API error).
	 * @param string      $user     Who started a manual or test run.
	 */
	public static function makeRun(string $trigger, int $started, ?array $result, ?string $error, string $user = ''): array {
		$run = [
			'time' => $started,
			'duration' => max(0, time() - $started),
			'trigger' => $trigger,
			'user' => $user,
			'status' => $result !== null && $error === null ? self::runStatus($result) : self::STATUS_ERROR,
			'media_type' => $result['media_type'] ?? '',
			'source' => $result['source'] ?? self::SOURCE_PROBLEMS,
			'problems' => $result['problems'] ?? 0,
			'parts' => $result['parts'] ?? 0,
			'sent' => $result['sent'] ?? 0,
			'failed' => $result['failed'] ?? 0,
			'skipped' => $result['skipped'] ?? 0,
			'message' => $error ?? '',
			// One line per recipient and part; capped so that one run cannot blow up the history file.
			'deliveries' => array_slice($result['deliveries'] ?? [], 0, self::HISTORY_DELIVERIES),
			'deliveries_total' => count($result['deliveries'] ?? [])
		];

		return $run;
	}

	/**
	 * Short text for the schedule list and the CLI log.
	 */
	public static function runSummary(array $run): string {
		if ($run['message'] !== '') {
			return $run['message'];
		}

		$text = I18n::s(($run['source'] ?? self::SOURCE_PROBLEMS) === self::SOURCE_SCRIPT
				? 'Records: %1$s, reports: %2$s, sent: %3$s, failed: %4$s.'
				: 'Problems: %1$s, reports: %2$s, sent: %3$s, failed: %4$s.',
			$run['problems'], $run['parts'],
			$run['sent'], $run['failed']
		);

		// The same error of several report parts is listed once: "not-an-email: Invalid e-mail address. (x4)".
		$errors = [];

		foreach ($run['deliveries'] as $delivery) {
			if ($delivery['status'] !== 'sent') {
				$error = ($delivery['recipient'] !== '' ? $delivery['recipient'].': ' : '').$delivery['error'];
				$errors[$error] = ($errors[$error] ?? 0) + 1;
			}
		}

		foreach ($errors as $error => $count) {
			$text .= ' '.$error.($count > 1 ? ' (x'.$count.')' : '');
		}

		return mb_substr($text, 0, 2000, 'UTF-8');
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
			return I18n::s('Weekly on %1$s at %2$s', I18n::t(self::WEEKDAYS[$schedule['weekday']]), $schedule['time']);
		}

		return I18n::s('Daily at %1$s', $schedule['time']);
	}
}
