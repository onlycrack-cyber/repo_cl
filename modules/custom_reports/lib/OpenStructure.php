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

	// Target times by severity: acknowledgement and resolution (ModuleConfig "response_targets").
	public const DEFAULT_TARGETS = [
		Severity::DISASTER => ['ack' => 900, 'resolve' => 4 * 3600],
		Severity::HIGH => ['ack' => 1800, 'resolve' => 8 * 3600],
		Severity::AVERAGE => ['ack' => 4 * 3600, 'resolve' => 3 * 86400],
		Severity::WARNING => ['ack' => 86400, 'resolve' => 7 * 86400]
	];

	private const TOP_GROUPS = 10;
	private const TOP_ROWS = 5;

	public static function compute(array $report, ?array $targets = null): array {
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

		$unacknowledged = count(array_filter($problems, static function (array $problem): bool {
			return !$problem['acknowledged'];
		}));

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
			'unacknowledged' => $unacknowledged,
			'targets' => self::targets($problems, $targets ?? self::DEFAULT_TARGETS),
			'alerts' => $alerts
		];
	}

	/**
	 * Open problems against the target times of their severity: not acknowledged later than "ack", open longer than
	 * "resolve". The most overdue problems first (by how many times the target is exceeded).
	 *
	 * @return array  ['rows' => [severity => ['ack', 'resolve', 'open', 'ack_overdue', 'resolve_overdue', 'in_time']],
	 *                 'checked' => int, 'overdue' => int, 'top' => list of problem + ['breach', 'over', 'ratio']]
	 */
	public static function targets(array $problems, array $targets): array {
		$rows = [];

		foreach (Severity::descending() as $severity) {
			$target = $targets[$severity] ?? null;

			if (!is_array($target) || (int) ($target['ack'] ?? 0) <= 0 && (int) ($target['resolve'] ?? 0) <= 0) {
				continue;
			}

			$rows[$severity] = ['ack' => max(0, (int) ($target['ack'] ?? 0)),
				'resolve' => max(0, (int) ($target['resolve'] ?? 0)), 'open' => 0, 'ack_overdue' => 0,
				'resolve_overdue' => 0, 'in_time' => 0
			];
		}

		$top = [];

		foreach ($problems as $problem) {
			if (!array_key_exists($problem['severity'], $rows)) {
				continue;
			}

			$row = &$rows[$problem['severity']];
			$row['open']++;
			$breach = null;

			if ($row['resolve'] > 0 && $problem['age'] > $row['resolve']) {
				$row['resolve_overdue']++;
				$breach = ['breach' => 'resolve', 'over' => $problem['age'] - $row['resolve'],
					'ratio' => $problem['age'] / $row['resolve']
				];
			}

			if (!$problem['acknowledged'] && $row['ack'] > 0 && $problem['age'] > $row['ack']) {
				$row['ack_overdue']++;
				$ratio = $problem['age'] / $row['ack'];

				// Not even acknowledged: the more urgent breach unless the resolution one is bigger.
				if ($breach === null) {
					$breach = ['breach' => 'ack', 'over' => $problem['age'] - $row['ack'], 'ratio' => $ratio];
				}
				else {
					$breach['breach'] = 'both';
				}
			}

			if ($breach === null) {
				$row['in_time']++;
			}
			else {
				$top[] = $problem + $breach;
			}

			unset($row);
		}

		// Higher severity first, then how many times the target is exceeded.
		usort($top, static function (array $a, array $b): int {
			return [$b['severity'], $b['ratio']] <=> [$a['severity'], $a['ratio']];
		});

		$checked = array_sum(array_column($rows, 'open'));

		return [
			'rows' => $rows,
			'checked' => $checked,
			'overdue' => count($top),
			'top' => array_slice($top, 0, self::TOP_ROWS)
		];
	}
}
