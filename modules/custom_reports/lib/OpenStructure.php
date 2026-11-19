<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * "Structure of open problems" of the dashboard: how old the open problems are, where they are (host groups), what is
 * not acknowledged and which problems produce the most alerts. Computed from a report of ReportBuilder::build()
 * (host groups loaded), nothing is requested again.
 */
class OpenStructure {

	// Age columns: upper limits in seconds (null - no limit).
	public const AGES = ['hour' => 3600, 'day' => 86400, 'week' => 604800, 'older' => null];

	private const TOP_GROUPS = 10;
	private const TOP_ROWS = 5;

	public static function compute(array $report): array {
		$problems = $report['problems'];

		// Age x severity.
		$ages = [];

		foreach (Severity::descending() as $severity) {
			$ages[$severity] = array_fill_keys(array_keys(self::AGES), 0);
		}

		foreach ($problems as $problem) {
			foreach (self::AGES as $key => $limit) {
				if ($limit === null || $problem['age'] < $limit) {
					$ages[$problem['severity']][$key]++;
					break;
				}
			}
		}

		// Open problems by host group (a problem counts once per group, even with several hosts of the group).
		$groups = [];

		foreach ($problems as $problem) {
			$problem_groups = [];

			foreach ($problem['hosts'] as $host) {
				$problem_groups += $report['host_groups'][$host['hostid']] ?? [];
			}

			foreach ($problem_groups as $groupid => $name) {
				$row = &$groups[$groupid];
				$row = $row ?? ['name' => $name, 'total' => 0, 'high' => 0, 'unacknowledged' => 0,
					'by_severity' => array_fill_keys(array_keys(Severity::NAMES), 0)
				];
				$row['total']++;
				$row['by_severity'][$problem['severity']]++;
				$row['high'] += $problem['severity'] >= Severity::HIGH ? 1 : 0;
				$row['unacknowledged'] += $problem['acknowledged'] ? 0 : 1;
				unset($row);
			}
		}

		usort($groups, static function (array $a, array $b): int {
			return [$b['high'], $b['total'], $a['name']] <=> [$a['high'], $a['total'], $b['name']];
		});

		// Not acknowledged: by severity and the oldest High / Disaster ones.
		$unacknowledged = array_values(array_filter($problems, static function (array $problem): bool {
			return !$problem['acknowledged'];
		}));
		$unack_by_severity = array_fill_keys(array_keys(Severity::NAMES), 0);

		foreach ($unacknowledged as $problem) {
			$unack_by_severity[$problem['severity']]++;
		}

		$oldest = array_values(array_filter($unacknowledged, static function (array $problem): bool {
			return $problem['severity'] >= Severity::HIGH;
		}));
		usort($oldest, static function (array $a, array $b): int {
			return $b['age'] <=> $a['age'];
		});

		// Alerts of the period for the unresolved problems (period analytics).
		$alerts = null;

		if ($report['analytics'] !== null && $report['unresolved']) {
			$with_alerts = array_values(array_filter($report['unresolved'], static function (array $item): bool {
				return $item['alerts_sent'] !== null;
			}));
			usort($with_alerts, static function (array $a, array $b): int {
				return $b['alerts_sent'] <=> $a['alerts_sent'];
			});

			$alerts = [
				'sent' => array_sum(array_column($with_alerts, 'alerts_sent')),
				'failed' => array_sum(array_column($with_alerts, 'alerts_failed')),
				'silent' => count(array_filter($with_alerts, static function (array $item): bool {
					return $item['alerts_sent'] == 0;
				})),
				'top' => array_slice(array_filter($with_alerts, static function (array $item): bool {
					return $item['alerts_sent'] > 0;
				}), 0, self::TOP_ROWS)
			];
		}

		return [
			'total' => count($problems),
			'ages' => $ages,
			'groups' => array_slice($groups, 0, self::TOP_GROUPS),
			'groups_total' => count($groups),
			'unacknowledged' => count($unacknowledged),
			'unack_by_severity' => $unack_by_severity,
			'oldest_unacknowledged' => array_slice($oldest, 0, self::TOP_ROWS),
			'alerts' => $alerts
		];
	}
}
