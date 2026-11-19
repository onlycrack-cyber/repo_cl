<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * "Changes" analysis: the selected period compared with the previous period of the same length.
 *
 * collect() reads every problem active during both periods with one event.get (problem_time_from/till);
 * compute() builds the blocks from that data, optionally limited to some hosts (split reports):
 *   compare            per host group / host / trigger: new problems and MTTR, previous -> current,
 *   growth, decline    biggest increase / decrease of new problems,
 *   appeared,          triggers with problems in the current period and none in the previous one,
 *   disappeared        ... and the other way round,
 *   mttr_by_severity   median time to resolve per severity, previous -> current,
 *   heatmap            new problems per day of week x hour (current period),
 *   noisy              triggers with short-lived (< 5 min) or frequent problems (current period),
 *   problem_time       time with at least one open High/Disaster problem per host group (or host).
 */
class ChangeAnalytics {

	public const GROUP_BY_GROUP = 'group';
	public const GROUP_BY_HOST = 'host';
	public const GROUP_BY_TRIGGER = 'trigger';

	public const GROUPINGS = [self::GROUP_BY_GROUP, self::GROUP_BY_HOST, self::GROUP_BY_TRIGGER];

	// A problem resolved faster than this is "short-lived" (flapping, too sensitive threshold).
	public const SHORT_PROBLEM = 300;

	// "Time in problem" counts problems of this severity and higher (High, Disaster).
	public const TIME_MIN_SEVERITY = Severity::HIGH;

	private const TOP = 5;
	private const LIST = 20;
	private const NOISY = 15;
	private const MAX_ROWS = 50;
	private const NOISY_MIN_PROBLEMS = 3;
	private const NOISY_MIN_SHORT = 2;

	private ApiClientInterface $api;
	private int $limit;

	public function __construct(ApiClientInterface $api, int $limit = 10000) {
		$this->api = $api;
		$this->limit = max(1, $limit);
	}

	public static function isValidGrouping(string $grouping): bool {
		return in_array($grouping, self::GROUPINGS, true);
	}

	public const PERIOD_CUSTOM = 'custom';
	public const PERIODS = [PeriodAnalytics::PERIOD_24H, PeriodAnalytics::PERIOD_7D, PeriodAnalytics::PERIOD_30D,
		self::PERIOD_CUSTOM
	];

	// Longest custom period: both periods are read at once, keep the event history request reasonable.
	public const MAX_DAYS = 92;

	/**
	 * Filter of the "Changes" tab: the report filter fields plus period and grouping.
	 */
	public static function normalizeFilter(array $filter): array {
		$base = ReportBuilder::normalizeFilter($filter);
		$period = (string) ($filter['period'] ?? '');
		$grouping = (string) ($filter['grouping'] ?? '');

		return [
			'groupids' => $base['groupids'],
			'hostids' => $base['hostids'],
			'severities' => $base['severities'],
			'problem_name' => $base['problem_name'],
			'period' => in_array($period, self::PERIODS, true) ? $period : PeriodAnalytics::PERIOD_7D,
			'date_from' => trim((string) ($filter['date_from'] ?? '')),
			'date_to' => trim((string) ($filter['date_to'] ?? '')),
			'grouping' => self::isValidGrouping($grouping) ? $grouping : self::GROUP_BY_GROUP
		];
	}

	/**
	 * Current period of the filter. Custom dates are whole days (YYYY-MM-DD, the end date included) in the
	 * PHP timezone; the end is capped by $now.
	 *
	 * @return array  ['from' => int, 'till' => int, 'error' => string|null]
	 */
	public static function resolveRange(array $filter, int $now): array {
		if ($filter['period'] !== self::PERIOD_CUSTOM) {
			return ['from' => $now - PeriodAnalytics::SECONDS[$filter['period']], 'till' => $now, 'error' => null];
		}

		$fallback = ['from' => $now - PeriodAnalytics::SECONDS[PeriodAnalytics::PERIOD_7D], 'till' => $now];
		$from = \DateTimeImmutable::createFromFormat('!Y-m-d', $filter['date_from']);
		$to = \DateTimeImmutable::createFromFormat('!Y-m-d', $filter['date_to']);

		if ($from === false || $to === false || $from->format('Y-m-d') !== $filter['date_from']
				|| $to->format('Y-m-d') !== $filter['date_to']) {
			return $fallback + ['error' => I18n::t('Enter both dates as YYYY-MM-DD.')];
		}

		$from_ts = $from->getTimestamp();
		$till_ts = min($to->modify('+1 day')->getTimestamp(), $now);

		if ($from_ts >= $till_ts) {
			return $fallback + ['error' => I18n::t('The start date must be before the end date and not in the future.')];
		}

		if ($till_ts - $from_ts > self::MAX_DAYS * 86400) {
			return $fallback + ['error' => I18n::s('The period cannot be longer than %1$s days.', self::MAX_DAYS)];
		}

		return ['from' => $from_ts, 'till' => $till_ts, 'error' => null];
	}

	/**
	 * @param array $filter  Normalized report filter (host groups, hosts, severities, problem name are applied).
	 * @param int   $from    Start of the current period.
	 * @param int   $till    End of the current period.
	 *
	 * @throws ApiException
	 */
	public function collect(array $filter, int $from, int $till): array {
		$prev_from = $from - ($till - $from);

		$params = [
			'output' => ['eventid', 'objectid', 'clock', 'severity', 'name', 'r_eventid'],
			'source' => 0,
			'object' => 0,
			'value' => 1,
			'selectHosts' => ['hostid', 'name'],
			// Problems active at any time of both periods, including those that started before them.
			'problem_time_from' => $prev_from,
			'problem_time_till' => $till,
			'sortfield' => ['eventid'],
			'sortorder' => 'DESC',
			'limit' => $this->limit + 1
		];

		foreach (['groupids', 'hostids', 'severities'] as $key) {
			if ($filter[$key]) {
				$params[$key] = $filter[$key];
			}
		}

		if ($filter['problem_name'] !== '') {
			$params['search'] = ['name' => $filter['problem_name']];
		}

		$events = $this->api->call('event.get', $params);
		$truncated = count($events) > $this->limit;
		$events = array_slice($events, 0, $this->limit);

		$recovery = [];
		$r_eventids = array_values(array_filter(array_column($events, 'r_eventid'), static function ($id): bool {
			return $id != 0;
		}));

		foreach (array_chunk($r_eventids, 1000) as $chunk) {
			foreach ($this->api->call('event.get', ['output' => ['eventid', 'clock'], 'eventids' => $chunk]) as $r) {
				$recovery[$r['eventid']] = (int) $r['clock'];
			}
		}

		$problems = [];
		$hostids = [];

		foreach ($events as $event) {
			$hosts = [];

			foreach ($event['hosts'] as $host) {
				$hosts[] = ['hostid' => $host['hostid'], 'name' => $host['name']];
				$hostids[$host['hostid']] = true;
			}

			$problems[] = [
				'triggerid' => $event['objectid'],
				'name' => $event['name'],
				'severity' => (int) $event['severity'],
				'clock' => (int) $event['clock'],
				'resolved' => $recovery[$event['r_eventid']] ?? null,
				'hosts' => $hosts
			];
		}

		$host_groups = [];

		if ($hostids) {
			$hosts = $this->api->call('host.get', [
				'output' => ['hostid'],
				'hostids' => array_keys($hostids),
				'selectHostGroups' => ['groupid', 'name']
			]);

			foreach ($hosts as $host) {
				$host_groups[$host['hostid']] = array_column($host['hostgroups'], 'name', 'groupid');
			}
		}

		return [
			'from' => $from,
			'till' => $till,
			'prev_from' => $prev_from,
			'truncated' => $truncated,
			'problems' => $problems,
			'host_groups' => $host_groups,
			// With a host group filter only the selected groups are rows of "by host group" blocks.
			'group_filter' => $filter['groupids']
		];
	}

	/**
	 * @param array         $data      Result of collect().
	 * @param string        $grouping  GROUP_BY_*.
	 * @param callable|null $match     function (string[] $hostids): bool - only problems of these hosts.
	 */
	public static function compute(array $data, string $grouping, ?callable $match = null): array {
		$problems = $data['problems'];

		if ($match !== null) {
			$problems = array_values(array_filter($problems, static function (array $problem) use ($match): bool {
				return $match(array_column($problem['hosts'], 'hostid'));
			}));
		}

		$periods = [
			'cur' => [$data['from'], $data['till']],
			'prev' => [$data['prev_from'], $data['from']]
		];
		$new = ['cur' => [], 'prev' => []];

		foreach ($problems as $problem) {
			foreach ($periods as $key => [$start, $end]) {
				if ($problem['clock'] >= $start && $problem['clock'] < $end) {
					$new[$key][] = $problem;
				}
			}
		}

		$compare = self::compare($new, $data, $grouping);

		return [
			'from' => $data['from'],
			'till' => $data['till'],
			'prev_from' => $data['prev_from'],
			'grouping' => $grouping,
			'truncated' => $data['truncated'],
			'totals' => [
				'cur' => self::totals($new['cur']),
				'prev' => self::totals($new['prev'])
			],
			'compare' => array_slice($compare, 0, self::MAX_ROWS),
			'compare_total' => count($compare),
			'growth' => self::movers($compare, true),
			'decline' => self::movers($compare, false),
			'appeared' => self::appeared($new['cur'], $new['prev']),
			// A trigger whose problem is still open has not "disappeared", even without new problems.
			'disappeared' => self::appeared($new['prev'], array_merge($new['cur'],
				array_filter($problems, static function (array $problem) use ($data): bool {
					return $problem['clock'] < $data['till']
						&& ($problem['resolved'] === null || $problem['resolved'] > $data['till']);
				})
			)),
			'mttr_by_severity' => self::mttrBySeverity($new),
			'heatmap' => self::heatmap($new['cur']),
			'noisy' => self::noisy($new['cur'], $new['prev']),
			'problem_time' => self::problemTime($problems, $periods, $data,
				$grouping === self::GROUP_BY_HOST ? self::GROUP_BY_HOST : self::GROUP_BY_GROUP
			)
		];
	}

	private static function totals(array $problems): array {
		$durations = self::durations($problems);

		return [
			'new' => count($problems),
			'resolved' => count($durations),
			'mttr' => self::median($durations)
		];
	}

	/**
	 * Keys (id => name) of the rows a problem belongs to.
	 */
	private static function rowKeys(array $problem, array $data, string $grouping): array {
		switch ($grouping) {
			case self::GROUP_BY_HOST:
				return array_column($problem['hosts'], 'name', 'hostid');

			case self::GROUP_BY_TRIGGER:
				return [$problem['triggerid'] => implode(', ', array_column($problem['hosts'], 'name')).': '.
					$problem['name']
				];

			default:
				$groups = [];

				foreach ($problem['hosts'] as $host) {
					$groups += $data['host_groups'][$host['hostid']] ?? [];
				}

				if ($data['group_filter']) {
					$groups = array_intersect_key($groups, array_flip($data['group_filter']));
				}

				return $groups;
		}
	}

	private static function compare(array $new, array $data, string $grouping): array {
		$rows = [];

		foreach ($new as $period => $problems) {
			foreach ($problems as $problem) {
				foreach (self::rowKeys($problem, $data, $grouping) as $id => $name) {
					$row = &$rows[$id];
					$row = $row ?? ['id' => (string) $id, 'name' => $name, 'cur' => 0, 'prev' => 0,
						'durations' => ['cur' => [], 'prev' => []]
					];
					$row[$period]++;

					if ($problem['resolved'] !== null) {
						$row['durations'][$period][] = max(0, $problem['resolved'] - $problem['clock']);
					}

					unset($row);
				}
			}
		}

		foreach ($rows as &$row) {
			$row['delta'] = $row['cur'] - $row['prev'];
			$row['delta_pct'] = $row['prev'] > 0 ? (int) round($row['delta'] * 100 / $row['prev']) : null;
			$row['mttr_cur'] = self::median($row['durations']['cur']);
			$row['mttr_prev'] = self::median($row['durations']['prev']);
			unset($row['durations']);
		}
		unset($row);

		usort($rows, static function (array $a, array $b): int {
			return [$b['cur'], $b['prev'], $a['name']] <=> [$a['cur'], $a['prev'], $b['name']];
		});

		return $rows;
	}

	private static function movers(array $rows, bool $growth): array {
		$rows = array_values(array_filter($rows, static function (array $row) use ($growth): bool {
			return $growth ? $row['delta'] > 0 : $row['delta'] < 0;
		}));

		usort($rows, static function (array $a, array $b) use ($growth): int {
			return $growth ? $b['delta'] <=> $a['delta'] : $a['delta'] <=> $b['delta'];
		});

		return array_slice($rows, 0, self::TOP);
	}

	/**
	 * Triggers with problems in $in and none in $not_in (appeared: cur/prev, disappeared: prev/cur and open).
	 */
	private static function appeared(array $in, array $not_in): array {
		$excluded = array_flip(array_column($not_in, 'triggerid'));
		$triggers = [];

		foreach ($in as $problem) {
			if (array_key_exists($problem['triggerid'], $excluded)) {
				continue;
			}

			$trigger = &$triggers[$problem['triggerid']];
			$trigger = $trigger ?? ['name' => $problem['name'], 'severity' => $problem['severity'],
				'hosts' => implode(', ', array_column($problem['hosts'], 'name')), 'count' => 0,
				'first' => $problem['clock'], 'last' => $problem['clock']
			];
			$trigger['count']++;
			$trigger['severity'] = max($trigger['severity'], $problem['severity']);
			$trigger['first'] = min($trigger['first'], $problem['clock']);
			$trigger['last'] = max($trigger['last'], $problem['clock']);
			unset($trigger);
		}

		usort($triggers, static function (array $a, array $b): int {
			return [$b['severity'], $b['count']] <=> [$a['severity'], $a['count']];
		});

		return ['total' => count($triggers), 'list' => array_slice($triggers, 0, self::LIST)];
	}

	private static function mttrBySeverity(array $new): array {
		$result = [];

		foreach (Severity::descending() as $severity) {
			$row = [];

			foreach (['cur', 'prev'] as $period) {
				$durations = self::durations(array_filter($new[$period], static function (array $p) use ($severity): bool {
					return $p['severity'] == $severity;
				}));
				$row[$period] = self::median($durations);
				$row[$period.'_count'] = count($durations);
			}

			$result[$severity] = $row;
		}

		return $result;
	}

	/**
	 * New problems per ISO day of week (1 = Monday) and hour, in the PHP timezone.
	 */
	private static function heatmap(array $problems): array {
		$cells = array_fill(1, 7, array_fill(0, 24, 0));

		foreach ($problems as $problem) {
			$cells[(int) date('N', $problem['clock'])][(int) date('G', $problem['clock'])]++;
		}

		$max = 0;

		foreach ($cells as $hours) {
			$max = max($max, max($hours));
		}

		return ['cells' => $cells, 'max' => $max];
	}

	private static function noisy(array $cur, array $prev): array {
		$prev_counts = array_count_values(array_column($prev, 'triggerid'));
		$triggers = [];

		foreach ($cur as $problem) {
			$trigger = &$triggers[$problem['triggerid']];
			$trigger = $trigger ?? ['triggerid' => $problem['triggerid'], 'name' => $problem['name'],
				'severity' => $problem['severity'], 'hosts' => implode(', ', array_column($problem['hosts'], 'name')),
				'count' => 0, 'short' => 0, 'durations' => []
			];
			$trigger['count']++;

			if ($problem['resolved'] !== null) {
				$duration = max(0, $problem['resolved'] - $problem['clock']);
				$trigger['durations'][] = $duration;

				if ($duration < self::SHORT_PROBLEM) {
					$trigger['short']++;
				}
			}

			unset($trigger);
		}

		$result = [];

		foreach ($triggers as $id => $trigger) {
			if ($trigger['count'] < self::NOISY_MIN_PROBLEMS && $trigger['short'] < self::NOISY_MIN_SHORT) {
				continue;
			}

			$trigger['short_pct'] = (int) round($trigger['short'] * 100 / $trigger['count']);
			$trigger['median'] = self::median($trigger['durations']);
			$trigger['prev'] = $prev_counts[$id] ?? 0;
			unset($trigger['durations']);
			$result[] = $trigger;
		}

		usort($result, static function (array $a, array $b): int {
			return [$b['short'], $b['count']] <=> [$a['short'], $a['count']];
		});

		return array_slice($result, 0, self::NOISY);
	}

	/**
	 * Time with at least one open High/Disaster problem per host group (or host): union of problem intervals
	 * clipped to each period, so overlapping problems are not counted twice.
	 */
	private static function problemTime(array $problems, array $periods, array $data, string $grouping): array {
		$intervals = [];
		$names = [];

		foreach ($problems as $problem) {
			if ($problem['severity'] < self::TIME_MIN_SEVERITY) {
				continue;
			}

			foreach (self::rowKeys($problem, $data, $grouping) as $id => $name) {
				$names[$id] = $name;

				foreach ($periods as $period => [$start, $end]) {
					$from = max($problem['clock'], $start);
					$till = min($problem['resolved'] ?? $end, $end);

					if ($till > $from) {
						$intervals[$id][$period][] = [$from, $till];
					}
				}
			}
		}

		$rows = [];

		foreach ($intervals as $id => $by_period) {
			$row = ['name' => $names[$id]];

			foreach ($periods as $period => [$start, $end]) {
				$seconds = self::unionLength($by_period[$period] ?? []);
				$row[$period] = $seconds;
				$row[$period.'_pct'] = $end > $start ? round($seconds * 100 / ($end - $start), 1) : 0;
			}

			$rows[] = $row;
		}

		usort($rows, static function (array $a, array $b): int {
			return [$b['cur'], $b['prev']] <=> [$a['cur'], $a['prev']];
		});

		return array_slice($rows, 0, self::LIST);
	}

	private static function unionLength(array $intervals): int {
		usort($intervals, static function (array $a, array $b): int {
			return $a[0] <=> $b[0];
		});

		$total = 0;
		$current = null;

		foreach ($intervals as [$from, $till]) {
			if ($current === null || $from > $current[1]) {
				if ($current !== null) {
					$total += $current[1] - $current[0];
				}

				$current = [$from, $till];
			}
			else {
				$current[1] = max($current[1], $till);
			}
		}

		return $total + ($current !== null ? $current[1] - $current[0] : 0);
	}

	private static function durations(array $problems): array {
		$durations = [];

		foreach ($problems as $problem) {
			if ($problem['resolved'] !== null) {
				$durations[] = max(0, $problem['resolved'] - $problem['clock']);
			}
		}

		return $durations;
	}

	private static function median(array $values): ?int {
		if (!$values) {
			return null;
		}

		sort($values);
		$middle = intdiv(count($values), 2);

		return count($values) % 2 ? $values[$middle] : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
	}
}
