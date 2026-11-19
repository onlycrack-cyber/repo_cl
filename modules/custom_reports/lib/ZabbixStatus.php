<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

use RuntimeException;

/**
 * Delivery status for monitoring by Zabbix itself.
 *
 * On every run the cron sender pushes three values with history.push (Zabbix 7.0+) into trapper items of the host
 * set in "zabbix_status_host" (template zabbix/zbx_template_custom_reports.yaml):
 *   custom_reports.heartbeat   time of the run (a trigger fires on nodata(): cron is not running),
 *   custom_reports.failed      number of enabled schedules whose last delivery failed (error or partial),
 *   custom_reports.last_error  "schedule: error" lines of those schedules.
 */
class ZabbixStatus {

	public const KEY_HEARTBEAT = 'custom_reports.heartbeat';
	public const KEY_FAILED = 'custom_reports.failed';
	public const KEY_LAST_ERROR = 'custom_reports.last_error';

	private const MAX_ERROR_LENGTH = 4000;

	/**
	 * Enabled schedules whose last delivery (scheduled or "Send now") failed completely or partially.
	 *
	 * @return string[]  "Schedule name: error" per schedule.
	 */
	public static function failedSchedules(array $schedules, array $state): array {
		$failed = [];

		foreach ($schedules as $scheduleid => $schedule) {
			$status = $state[$scheduleid]['last_status'] ?? null;

			if ($schedule['enabled']
					&& in_array($status, [ScheduleHelper::STATUS_ERROR, ScheduleHelper::STATUS_PARTIAL], true)) {
				$failed[] = $schedule['name'].': '.($state[$scheduleid]['last_message'] ?? '');
			}
		}

		return $failed;
	}

	/**
	 * @throws RuntimeException  If the values are not accepted.
	 */
	public static function push(ApiClientInterface $api, string $host, int $now, array $failed): void {
		$text = implode("\n", $failed);

		if (mb_strlen($text, 'UTF-8') > self::MAX_ERROR_LENGTH) {
			$text = mb_substr($text, 0, self::MAX_ERROR_LENGTH - 3, 'UTF-8').'...';
		}

		$values = [
			['host' => $host, 'key' => self::KEY_HEARTBEAT, 'value' => (string) $now, 'clock' => $now],
			['host' => $host, 'key' => self::KEY_FAILED, 'value' => (string) count($failed), 'clock' => $now],
			['host' => $host, 'key' => self::KEY_LAST_ERROR, 'value' => $text, 'clock' => $now]
		];

		try {
			$result = $api->call('history.push', $values);
		}
		catch (ApiException $e) {
			throw new RuntimeException('history.push failed: '.$e->getMessage());
		}

		// Zabbix server answers per value: {"itemid": ...} or {"error": "..."} (e.g. item missing or not a trapper).
		$errors = [];

		foreach (($result['data'] ?? []) as $index => $item) {
			if (is_array($item) && array_key_exists('error', $item)) {
				$errors[] = ($values[$index]['key'] ?? '?').': '.$item['error'];
			}
		}

		if ($errors) {
			throw new RuntimeException(sprintf('Zabbix did not accept the values for host "%s": %s', $host,
				implode('; ', $errors)
			));
		}
	}
}
