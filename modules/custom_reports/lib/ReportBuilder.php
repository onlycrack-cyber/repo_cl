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

	// Acknowledgement filter.
	public const ACK_ALL = -1;
	public const ACK_NO = 0;
	public const ACK_YES = 1;

	// Sortable columns.
	public const SORT_TIME = 'time';
	public const SORT_DURATION = 'duration';
	public const SORT_SEVERITY = 'severity';
	public const SORT_HOST = 'host';
	public const SORT_PROBLEM = 'problem';
	public const SORT_ACK = 'ack';

	public const SORT_FIELDS = [self::SORT_TIME, self::SORT_DURATION, self::SORT_SEVERITY, self::SORT_HOST,
		self::SORT_PROBLEM, self::SORT_ACK
	];

	// Problem list in sent reports: every open problem, or only the grouped "Unresolved problems" summary.
	public const LIST_FULL = 'full';
	public const LIST_SUMMARY = 'summary';

	public const SORT_ASC = 'ASC';
	public const SORT_DESC = 'DESC';

	private ApiClientInterface $api;
	private int $limit;
	private int $analytics_limit;

	public function __construct(ApiClientInterface $api, int $limit = 1000, int $analytics_limit = 10000) {
		$this->api = $api;
		$this->limit = max(1, $limit);
		$this->analytics_limit = max(1, $analytics_limit);
	}

	public static function normalizeFilter(array $filter): array {
		$period = (string) ($filter['period'] ?? Period::ALL);

		return [
			'groupids' => self::ids($filter['groupids'] ?? []),
			'hostids' => self::ids($filter['hostids'] ?? []),
			'severities' => Severity::sanitize($filter['severities'] ?? []),
			'period' => Period::isValid($period) ? $period : Period::ALL,
			'show_suppressed' => (int) !empty($filter['show_suppressed']),
			'problem_name' => mb_substr(trim((string) ($filter['problem_name'] ?? '')), 0, 255, 'UTF-8'),
			// Problem tags, as in Monitoring -> Problems.
			'tags' => TagFilter::normalize($filter['tags'] ?? []),
			'evaltype' => TagFilter::normalizeEvaltype($filter['evaltype'] ?? TagFilter::EVAL_AND_OR),
			'acknowledged' => in_array((int) ($filter['acknowledged'] ?? self::ACK_ALL), [self::ACK_NO, self::ACK_YES], true)
				? (int) $filter['acknowledged']
				: self::ACK_ALL,
			'sortfield' => in_array($filter['sortfield'] ?? '', self::SORT_FIELDS, true)
				? $filter['sortfield']
				: self::SORT_SEVERITY,
			'sortorder' => ($filter['sortorder'] ?? '') === self::SORT_ASC ? self::SORT_ASC : self::SORT_DESC,
			// Period analytics reads the event history (several API queries): only when it is asked for.
			'analytics' => in_array($filter['analytics'] ?? '', PeriodAnalytics::PERIODS, true)
				? $filter['analytics']
				: PeriodAnalytics::PERIOD_NONE,
			'problem_list' => ($filter['problem_list'] ?? '') === self::LIST_SUMMARY ? self::LIST_SUMMARY : self::LIST_FULL,
			// "Changes" block in sent reports: comparison with the previous period of the same length.
			'include_changes' => (int) !empty($filter['include_changes']),
			'changes_period' => array_key_exists($filter['changes_period'] ?? '', PeriodAnalytics::SECONDS)
				? $filter['changes_period']
				: '',
			// Hosts the recipients may see (RecipientAccess); null - no limit. Not shown in the report filter.
			'access_hostids' => isset($filter['access_hostids']) && is_array($filter['access_hostids'])
				? self::ids($filter['access_hostids'])
				: null
		];
	}

	/**
	 * Filter for the API queries: "hostids" limited to the hosts the recipients may see.
	 */
	public static function queryFilter(array $filter): array {
		if (($filter['access_hostids'] ?? null) !== null) {
			$filter['hostids'] = $filter['hostids']
				? array_values(array_intersect($filter['hostids'], $filter['access_hostids']))
				: $filter['access_hostids'];

			// Nothing visible: an ID that matches no host instead of "all hosts".
			if (!$filter['hostids']) {
				$filter['hostids'] = ['0'];
			}
		}

		return $filter;
	}

	public static function emptyReport(array $filter = []): array {
		$by_severity = array_fill_keys(array_keys(Severity::NAMES), 0);
		$severity_meta = [];

		foreach (Severity::NAMES as $severity => $name) {
			$severity_meta[$severity] = ['name' => I18n::t($name), 'color' => Severity::COLORS[$severity]];
		}

		return [
			'generated' => time(),
			'filter' => self::normalizeFilter($filter),
			'filter_info' => ['groups' => [], 'hosts' => [], 'severities' => [], 'period' => Period::ALL, 'scope' => '',
				'problem_name' => '', 'acknowledged' => self::ACK_ALL
			],
			'host_groups' => [],
			'open_stats' => ['oldest_age' => null, 'avg_age' => null, 'unacknowledged' => 0, 'over_1d' => 0,
				'over_7d' => 0
			],
			'analytics_data' => null,
			'analytics' => null,
			'analytics_error' => null,
			'unresolved' => [],
			'changes_data' => null,
			'changes' => null,
			'changes_error' => null,
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
		// The shown filter is the one of the schedule (without hosts and groups the recipients cannot see); the queries
		// are limited to the hosts of the recipients.
		$shown_filter = $filter;
		$filter = self::queryFilter($filter);
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

		if ($filter['problem_name'] !== '') {
			$params['search'] = ['name' => $filter['problem_name']];
		}

		if ($filter['acknowledged'] != self::ACK_ALL) {
			$params['acknowledged'] = $filter['acknowledged'] == self::ACK_YES;
		}

		$params += TagFilter::apiParams($filter);

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

		$visible = $filter['access_hostids'] !== null ? array_flip($filter['access_hostids']) : null;

		foreach ($problems as $problem) {
			$hosts = [];

			if (array_key_exists($problem['objectid'], $triggers)) {
				foreach ($triggers[$problem['objectid']]['hosts'] as $host) {
					// A trigger of several hosts is visible only if all of its hosts are (as in Zabbix).
					if ($visible !== null && !array_key_exists($host['hostid'], $visible)) {
						continue 2;
					}

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

		self::sortProblems($report['problems'], $filter['sortfield'], $filter['sortorder']);

		self::aggregate($report);

		// Period analytics: "auto" is resolved by the caller (schedule frequency); here it means 24 hours.
		$period = PeriodAnalytics::resolvePeriod($filter['analytics']);

		if ($period !== null) {
			// Analytics is optional: the list of open problems is still sent if the event history cannot be read.
			try {
				$report['analytics_data'] = (new PeriodAnalytics($this->api, $this->analytics_limit))
					->collect($filter, $period, $now, array_column($report['problems'], 'eventid'));
				$report['analytics'] = PeriodAnalytics::compute($report['analytics_data']);
				$report['unresolved'] = PeriodAnalytics::unresolved($report['problems'], $report['analytics_data']);
			}
			catch (ApiException $e) {
				$report['analytics_error'] = $e->getMessage();
			}
		}

		if ($filter['include_changes']) {
			$changes_period = $filter['changes_period'] !== ''
				? $filter['changes_period']
				: ($period ?? PeriodAnalytics::PERIOD_7D);

			try {
				$report['changes_data'] = (new ChangeAnalytics($this->api, $this->analytics_limit))
					->collect($filter, $now - PeriodAnalytics::SECONDS[$changes_period], $now);
				$report['changes'] = ChangeAnalytics::compute($report['changes_data'], ChangeAnalytics::GROUP_BY_GROUP);
			}
			catch (ApiException $e) {
				$report['changes_error'] = $e->getMessage();
			}
		}

		// Hosts of open problems, of new problems of the period and of problems carried over from before it.
		if ($with_host_groups) {
			$report['host_groups'] = $this->getHostGroups(array_merge($report['problems'],
				$report['analytics_data']['events'] ?? [], $report['analytics_data']['carried'] ?? [],
				$report['changes_data']['problems'] ?? []
			));
		}

		$report['filter_info'] = $this->getFilterInfo($shown_filter, $report['severity_meta']);

		return $report;
	}

	/**
	 * Sorts problems by a column. Ties are ordered by severity (highest first), then by time (newest first).
	 */
	public static function sortProblems(array &$problems, string $field, string $order): void {
		$key = static function (array $problem) use ($field) {
			switch ($field) {
				case self::SORT_TIME:
					return $problem['clock'];

				case self::SORT_DURATION:
					return $problem['age'];

				case self::SORT_HOST:
					return mb_strtolower(implode(', ', array_column($problem['hosts'], 'name')), 'UTF-8');

				case self::SORT_PROBLEM:
					return mb_strtolower($problem['name'], 'UTF-8');

				case self::SORT_ACK:
					return $problem['acknowledged'];

				default:
					return $problem['severity'];
			}
		};
		$direction = $order === self::SORT_ASC ? 1 : -1;

		usort($problems, static function (array $a, array $b) use ($key, $direction): int {
			$result = ($key($a) <=> $key($b)) * $direction;

			return $result != 0 ? $result : [$b['severity'], $b['clock']] <=> [$a['severity'], $a['clock']];
		});
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

		// How long the open problems have been open.
		$ages = array_column($report['problems'], 'age');
		$report['open_stats'] = [
			'oldest_age' => $ages ? max($ages) : null,
			'avg_age' => $ages ? (int) round(array_sum($ages) / count($ages)) : null,
			'unacknowledged' => count(array_filter($report['problems'], static function (array $problem): bool {
				return $problem['acknowledged'] == 0;
			})),
			'over_1d' => count(array_filter($ages, static function (int $age): bool {
				return $age > 86400;
			})),
			'over_7d' => count(array_filter($ages, static function (int $age): bool {
				return $age > 7 * 86400;
			}))
		];
	}

	/**
	 * A copy of the report limited to the given problems (used to split a report by host or host group).
	 *
	 * @param array  $report
	 * @param array  $problems
	 * @param string        $scope     Human readable scope, e.g. "Host group: Linux servers".
	 * @param callable|null $match     function (string[] $hostids): bool - events of the part, for period analytics.
	 */
	public static function subset(array $report, array $problems, string $scope, ?callable $match = null): array {
		$report['problems'] = array_values($problems);
		$report['filter_info']['scope'] = $scope;
		$report['truncated'] = false;
		self::aggregate($report);

		if ($report['changes_data'] !== null && $match !== null) {
			$report['changes'] = ChangeAnalytics::compute($report['changes_data'], ChangeAnalytics::GROUP_BY_GROUP, $match);
		}

		if ($report['analytics_data'] !== null && $match !== null) {
			$report['analytics_data'] = PeriodAnalytics::subset($report['analytics_data'], $match);
			$report['analytics'] = PeriodAnalytics::compute($report['analytics_data']);
			$report['unresolved'] = PeriodAnalytics::unresolved($report['problems'], $report['analytics_data']);
		}

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
	public function getSeverityMeta(): array {
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
				// Default (English) names are translated, custom names are kept as they are.
				$name = $settings['severity_name_'.$severity];
				$meta[$severity]['name'] = $name === Severity::NAMES[$severity] ? I18n::t($name) : $name;
			}

			if (!empty($settings['severity_color_'.$severity])) {
				$meta[$severity]['color'] = $settings['severity_color_'.$severity];
			}
		}

		return $meta;
	}

	private function getFilterInfo(array $filter, array $severity_meta): array {
		$info = ['groups' => [], 'hosts' => [], 'severities' => [], 'period' => $filter['period'], 'scope' => '',
			'problem_name' => $filter['problem_name'], 'acknowledged' => $filter['acknowledged'],
			'tags' => $filter['tags'] ? TagFilter::describe($filter['tags'], $filter['evaltype']) : ''
		];

		$access = $filter['access_hostids'] ?? null;

		if ($filter['groupids']) {
			// Only the groups with hosts the recipients may see: the parts of a split report come from this list.
			$groups = $this->api->call('hostgroup.get', [
				'output' => ['groupid', 'name'],
				'groupids' => $filter['groupids']
			] + ($access !== null ? ['hostids' => $access ?: ['0']] : []));
			$info['groups'] = array_column($groups, 'name', 'groupid');
			natcasesort($info['groups']);
		}

		if ($filter['hostids']) {
			$hosts = $this->api->call('host.get', [
				'output' => ['hostid', 'name'],
				'hostids' => $access !== null ? array_values(array_intersect($filter['hostids'], $access)) : $filter['hostids']
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
