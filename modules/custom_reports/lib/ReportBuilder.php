<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * Collects unresolved problems and aggregates them into a report structure.
 *
 * Report structure:
 *   generated      int    Unix time of generation.
 *   filter         array  Normalized filter.
 *   filter_info    array  Human readable filter (group/host names, severities, period).
 *   problems       array  List of problems: eventid, triggerid, clock, age, severity, name, acknowledged, hosts.
 *   total          int    Number of problems in the report.
 *   truncated      bool   True if the limit was reached.
 *   by_severity    array  severity => count.
 *   top_hosts      array  List of [hostid, name, count], max 5.
 *   host_groups    array  hostid => [groupid => name], only if requested.
 *   severity_meta  array  severity => [name, color].
 */
class ReportBuilder {

	// EVENT_SOURCE_TRIGGERS and EVENT_OBJECT_TRIGGER.
	private const EVENT_SOURCE_TRIGGERS = 0;
	private const EVENT_OBJECT_TRIGGER = 0;

	private const TOP_HOSTS = 5;

	private ApiClientInterface $api;
	private int $limit;

	public function __construct(ApiClientInterface $api, int $limit = 1000) {
		$this->api = $api;
		$this->limit = max(1, $limit);
	}

	public static function normalizeFilter(array $filter): array {
		$period = (string) ($filter['period'] ?? Period::ALL);

		return [
			'groupids' => self::ids($filter['groupids'] ?? []),
			'hostids' => self::ids($filter['hostids'] ?? []),
			'severities' => Severity::sanitize($filter['severities'] ?? []),
			'period' => Period::isValid($period) ? $period : Period::ALL,
			'show_suppressed' => (int) !empty($filter['show_suppressed'])
		];
	}

	public static function emptyReport(array $filter = []): array {
		$by_severity = array_fill_keys(array_keys(Severity::NAMES), 0);
		$severity_meta = [];

		foreach (Severity::NAMES as $severity => $name) {
			$severity_meta[$severity] = ['name' => $name, 'color' => Severity::COLORS[$severity]];
		}

		return [
			'generated' => time(),
			'filter' => self::normalizeFilter($filter),
			'filter_info' => ['groups' => [], 'hosts' => [], 'severities' => [], 'period' => Period::ALL, 'scope' => ''],
			'host_groups' => [],
			'problems' => [],
			'total' => 0,
			'truncated' => false,
			'by_severity' => $by_severity,
			'top_hosts' => [],
			'severity_meta' => $severity_meta
		];
	}

	/**
	 * @param array    $filter
	 * @param int|null $now
	 * @param bool     $with_host_groups  Load host groups of the hosts with problems (needed to split by group).
	 *
	 * @throws ApiException
	 */
	public function build(array $filter, ?int $now = null, bool $with_host_groups = false): array {
		$now = $now ?? time();
		$filter = self::normalizeFilter($filter);
		$report = self::emptyReport($filter);
		$report['generated'] = $now;
		$report['severity_meta'] = $this->getSeverityMeta();

		$params = [
			'output' => ['eventid', 'objectid', 'clock', 'name', 'severity', 'acknowledged'],
			'source' => self::EVENT_SOURCE_TRIGGERS,
			'object' => self::EVENT_OBJECT_TRIGGER,
			'recent' => false,
			'sortfield' => ['eventid'],
			'sortorder' => 'DESC',
			'limit' => $this->limit + 1
		];

		if (!$filter['show_suppressed']) {
			$params['suppressed'] = false;
		}

		if ($filter['groupids']) {
			$params['groupids'] = $filter['groupids'];
		}

		if ($filter['hostids']) {
			$params['hostids'] = $filter['hostids'];
		}

		if ($filter['severities']) {
			$params['severities'] = $filter['severities'];
		}

		[$time_from, $time_till] = Period::toRange($filter['period'], $now);

		if ($time_from !== null) {
			$params['time_from'] = $time_from;
		}

		if ($time_till !== null) {
			$params['time_till'] = $time_till;
		}

		$problems = $this->api->call('problem.get', $params);

		if (count($problems) > $this->limit) {
			$problems = array_slice($problems, 0, $this->limit);
			$report['truncated'] = true;
		}

		$triggers = [];

		if ($problems) {
			$triggers = $this->api->call('trigger.get', [
				'output' => ['triggerid'],
				'triggerids' => array_values(array_unique(array_column($problems, 'objectid'))),
				'selectHosts' => ['hostid', 'name'],
				'preservekeys' => true
			]);
		}

		foreach ($problems as $problem) {
			$hosts = [];

			if (array_key_exists($problem['objectid'], $triggers)) {
				foreach ($triggers[$problem['objectid']]['hosts'] as $host) {
					$hosts[] = ['hostid' => $host['hostid'], 'name' => $host['name']];
				}
			}

			$report['problems'][] = [
				'eventid' => $problem['eventid'],
				'triggerid' => $problem['objectid'],
				'clock' => (int) $problem['clock'],
				'age' => max(0, $now - (int) $problem['clock']),
				'severity' => (int) $problem['severity'],
				'name' => $problem['name'],
				'acknowledged' => (int) $problem['acknowledged'],
				'hosts' => $hosts
			];
		}

		usort($report['problems'], static function (array $a, array $b): int {
			return [$b['severity'], $b['clock']] <=> [$a['severity'], $a['clock']];
		});

		self::aggregate($report);

		if ($with_host_groups) {
			$report['host_groups'] = $this->getHostGroups($report['problems']);
		}

		$report['filter_info'] = $this->getFilterInfo($filter, $report['severity_meta']);

		return $report;
	}

	/**
	 * Recalculates totals, severity counters and top hosts from $report['problems'].
	 */
	public static function aggregate(array &$report): void {
		$report['by_severity'] = array_fill_keys(array_keys(Severity::NAMES), 0);
		$host_counts = [];

		foreach ($report['problems'] as $problem) {
			$report['by_severity'][$problem['severity']]++;

			foreach ($problem['hosts'] as $host) {
				if (!array_key_exists($host['hostid'], $host_counts)) {
					$host_counts[$host['hostid']] = ['hostid' => $host['hostid'], 'name' => $host['name'], 'count' => 0];
				}

				$host_counts[$host['hostid']]['count']++;
			}
		}

		usort($host_counts, static function (array $a, array $b): int {
			return [$b['count'], $a['name']] <=> [$a['count'], $b['name']];
		});

		$report['total'] = count($report['problems']);
		$report['top_hosts'] = array_slice($host_counts, 0, self::TOP_HOSTS);
	}

	/**
	 * A copy of the report limited to the given problems (used to split a report by host or host group).
	 *
	 * @param array  $report
	 * @param array  $problems
	 * @param string $scope     Human readable scope, e.g. "Host group: Linux servers".
	 */
	public static function subset(array $report, array $problems, string $scope): array {
		$report['problems'] = array_values($problems);
		$report['filter_info']['scope'] = $scope;
		$report['truncated'] = false;
		self::aggregate($report);

		return $report;
	}

	/**
	 * @return array  hostid => [groupid => name]
	 */
	private function getHostGroups(array $problems): array {
		$hostids = [];

		foreach ($problems as $problem) {
			foreach ($problem['hosts'] as $host) {
				$hostids[$host['hostid']] = true;
			}
		}

		if (!$hostids) {
			return [];
		}

		$hosts = $this->api->call('host.get', [
			'output' => ['hostid'],
			'hostids' => array_keys($hostids),
			'selectHostGroups' => ['groupid', 'name']
		]);

		$result = [];

		foreach ($hosts as $host) {
			$result[$host['hostid']] = array_column($host['hostgroups'], 'name', 'groupid');
		}

		return $result;
	}

	/**
	 * Severity names and colors as configured in Administration -> General -> Trigger displaying options.
	 */
	private function getSeverityMeta(): array {
		$meta = self::emptyReport()['severity_meta'];
		$output = [];

		foreach (array_keys(Severity::NAMES) as $severity) {
			$output[] = 'severity_name_'.$severity;
			$output[] = 'severity_color_'.$severity;
		}

		try {
			$settings = $this->api->call('settings.get', ['output' => $output]);
		}
		catch (ApiException $e) {
			return $meta;
		}

		foreach (array_keys(Severity::NAMES) as $severity) {
			if (!empty($settings['severity_name_'.$severity])) {
				$meta[$severity]['name'] = $settings['severity_name_'.$severity];
			}

			if (!empty($settings['severity_color_'.$severity])) {
				$meta[$severity]['color'] = $settings['severity_color_'.$severity];
			}
		}

		return $meta;
	}

	private function getFilterInfo(array $filter, array $severity_meta): array {
		$info = ['groups' => [], 'hosts' => [], 'severities' => [], 'period' => $filter['period'], 'scope' => ''];

		if ($filter['groupids']) {
			$groups = $this->api->call('hostgroup.get', [
				'output' => ['groupid', 'name'],
				'groupids' => $filter['groupids']
			]);
			$info['groups'] = array_column($groups, 'name', 'groupid');
			natcasesort($info['groups']);
		}

		if ($filter['hostids']) {
			$hosts = $this->api->call('host.get', [
				'output' => ['hostid', 'name'],
				'hostids' => $filter['hostids']
			]);
			$info['hosts'] = array_column($hosts, 'name', 'hostid');
			natcasesort($info['hosts']);
		}

		foreach ($filter['severities'] as $severity) {
			$info['severities'][] = $severity_meta[$severity]['name'];
		}

		return $info;
	}

	/**
	 * @param mixed $ids
	 *
	 * @return string[]
	 */
	public static function ids($ids): array {
		if (!is_array($ids)) {
			return [];
		}

		$result = [];

		foreach ($ids as $id) {
			if (is_scalar($id) && ctype_digit((string) $id) && (string) $id !== '0') {
				$result[] = (string) $id;
			}
		}

		return array_values(array_unique($result));
	}
}
