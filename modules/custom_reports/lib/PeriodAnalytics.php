<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * Period analytics from the event history: problems that started within the period (24 hours, 7 or 30 days),
 * compared with the previous period of the same length.
 *
 * collect() reads raw events from the API; compute() turns them into metrics. They are separate so that a
 * report split per host group or host can recompute the metrics for its own events without API calls.
 */
class PeriodAnalytics {

	public const PERIOD_NONE = 'none';
	public const PERIOD_AUTO = 'auto';
	public const PERIOD_24H = '24h';
	public const PERIOD_7D = '7d';
	public const PERIOD_30D = '30d';

	public const PERIODS = [self::PERIOD_NONE, self::PERIOD_AUTO, self::PERIOD_24H, self::PERIOD_7D,
		self::PERIOD_30D
	];

	public const SECONDS = [self::PERIOD_24H => 86400, self::PERIOD_7D => 604800, self::PERIOD_30D => 2592000];

	public const LABELS = [
		self::PERIOD_24H => 'last 24 hours',
		self::PERIOD_7D => 'last 7 days',
		self::PERIOD_30D => 'last 30 days'
	];

	// ZBX_PROBLEM_UPDATE_ACKNOWLEDGE.
	private const ACTION_ACKNOWLEDGE = 0x02;

	private const TOP = 5;

	private ApiClientInterface $api;
	private int $limit;

	public function __construct(ApiClientInterface $api, int $limit = 10000) {
		$this->api = $api;
		$this->limit = max(1, $limit);
	}

	/**
	 * "auto": the period of the schedule (weekly - 7 days, daily - 24 hours).
	 *
	 * @return string|null  Period key or null for no analytics.
	 */
	public static function resolvePeriod(string $period, string $frequency = ''): ?string {
		if ($period === self::PERIOD_AUTO) {
			return $frequency === ScheduleHelper::FREQUENCY_WEEKLY ? self::PERIOD_7D : self::PERIOD_24H;
		}

		return array_key_exists($period, self::SECONDS) ? $period : null;
	}

	/**
	 * Reads problem events of the period and of the previous period.
	 *
	 * @param array  $filter  Normalized report filter (host groups, hosts, severities, problem name are applied).
	 * @param string $period  PERIOD_24H, PERIOD_7D or PERIOD_30D.
	 * @param int    $now
	 *
	 * @throws ApiException
	 */
	public function collect(array $filter, string $period, int $now): array {
		$length = self::SECONDS[$period];
		$from = $now - $length;

		$base = [
			'source' => 0,
			'object' => 0,
			'value' => 1,
			'sortfield' => ['eventid'],
			'sortorder' => 'DESC',
			'limit' => $this->limit + 1
		];

		foreach (['groupids', 'hostids', 'severities'] as $key) {
			if ($filter[$key]) {
				$base[$key] = $filter[$key];
			}
		}

		if ($filter['problem_name'] !== '') {
			$base['search'] = ['name' => $filter['problem_name']];
		}

		$events = $this->api->call('event.get', $base + [
			'output' => ['eventid', 'objectid', 'clock', 'severity', 'name', 'r_eventid'],
			'selectHosts' => ['hostid', 'name'],
			'selectAcknowledges' => ['clock', 'action'],
			'time_from' => $from,
			'time_till' => $now
		]);

		$truncated = count($events) > $this->limit;
		$events = array_slice($events, 0, $this->limit);

		// Time of recovery.
		$recovery = [];
		$r_eventids = array_values(array_filter(array_column($events, 'r_eventid'), static function ($id): bool {
			return $id != 0;
		}));

		foreach (array_chunk($r_eventids, 1000) as $chunk) {
			foreach ($this->api->call('event.get', ['output' => ['eventid', 'clock'], 'eventids' => $chunk]) as $r) {
				$recovery[$r['eventid']] = (int) $r['clock'];
			}
		}

		$data = [
			'period' => $period,
			'from' => $from,
			'till' => $now,
			'truncated' => $truncated,
			'events' => [],
			'prev_events' => []
		];

		foreach ($events as $event) {
			$ack_clock = null;

			foreach ($event['acknowledges'] as $ack) {
				if (((int) $ack['action'] & self::ACTION_ACKNOWLEDGE)
						&& ($ack_clock === null || (int) $ack['clock'] < $ack_clock)) {
					$ack_clock = (int) $ack['clock'];
				}
			}

			$data['events'][] = [
				'triggerid' => $event['objectid'],
				'clock' => (int) $event['clock'],
				'severity' => (int) $event['severity'],
				'name' => $event['name'],
				'hosts' => array_map(static function (array $host): array {
					return ['hostid' => $host['hostid'], 'name' => $host['name']];
				}, $event['hosts']),
				'resolved' => $recovery[$event['r_eventid']] ?? null,
				'ack' => $ack_clock
			];
		}

		// Previous period: only what is needed for the comparison.
		$prev = $this->api->call('event.get', $base + [
			'output' => ['eventid', 'severity'],
			'selectHosts' => ['hostid'],
			'time_from' => $from - $length,
			'time_till' => $from - 1
		]);

		foreach (array_slice($prev, 0, $this->limit) as $event) {
			$data['prev_events'][] = [
				'severity' => (int) $event['severity'],
				'hosts' => array_column($event['hosts'], 'hostid')
			];
		}

		return $data;
	}

	/**
	 * Keeps events of the given hosts only (for split reports).
	 *
	 * @param callable $match  function (string[] $hostids): bool
	 */
	public static function subset(array $data, callable $match): array {
		$data['events'] = array_values(array_filter($data['events'], static function (array $event) use ($match): bool {
			return $match(array_column($event['hosts'], 'hostid'));
		}));
		$data['prev_events'] = array_values(array_filter($data['prev_events'],
			static function (array $event) use ($match): bool {
				return $match($event['hosts']);
			}
		));

		return $data;
	}

	/**
	 * Metrics of the period.
	 */
	public static function compute(array $data): array {
		$events = $data['events'];
		$total = count($events);
		$prev_total = count($data['prev_events']);
		$by_severity = array_fill_keys(array_keys(Severity::NAMES), 0);
		$resolve_times = [];
		$ack_times = [];
		$triggers = [];
		$hosts = [];

		foreach ($events as $event) {
			$by_severity[$event['severity']]++;

			if ($event['resolved'] !== null) {
				$resolve_times[] = max(0, $event['resolved'] - $event['clock']);
			}

			if ($event['ack'] !== null) {
				$ack_times[] = max(0, $event['ack'] - $event['clock']);
			}

			$host_names = implode(', ', array_column($event['hosts'], 'name'));

			if (!array_key_exists($event['triggerid'], $triggers)) {
				$triggers[$event['triggerid']] = ['name' => $event['name'], 'hosts' => $host_names, 'count' => 0,
					'severity' => $event['severity']
				];
			}

			$triggers[$event['triggerid']]['count']++;

			foreach ($event['hosts'] as $host) {
				$hosts[$host['hostid']] = $hosts[$host['hostid']] ?? ['name' => $host['name'], 'count' => 0];
				$hosts[$host['hostid']]['count']++;
			}
		}

		$sort_by_count = static function (array $a, array $b): int {
			return [$b['count'], $a['name']] <=> [$a['count'], $b['name']];
		};
		usort($triggers, $sort_by_count);
		usort($hosts, $sort_by_count);

		return [
			'period' => $data['period'],
			'from' => $data['from'],
			'till' => $data['till'],
			'truncated' => $data['truncated'],
			'new' => $total,
			'new_by_severity' => $by_severity,
			'prev' => $prev_total,
			'change_pct' => $prev_total > 0 ? (int) round(($total - $prev_total) * 100 / $prev_total) : null,
			'resolved' => count($resolve_times),
			'unresolved' => $total - count($resolve_times),
			'mttr_avg' => $resolve_times ? (int) round(array_sum($resolve_times) / count($resolve_times)) : null,
			'mttr_median' => self::median($resolve_times),
			'acknowledged' => count($ack_times),
			'ack_rate' => $total > 0 ? (int) round(count($ack_times) * 100 / $total) : null,
			'mtta_avg' => $ack_times ? (int) round(array_sum($ack_times) / count($ack_times)) : null,
			'buckets' => self::buckets($events, $data['period'], $data['from'], $data['till']),
			'recurring' => array_values(array_filter(array_slice($triggers, 0, self::TOP), static function ($t): bool {
				return $t['count'] > 1;
			})),
			'top_hosts' => array_slice($hosts, 0, self::TOP)
		];
	}

	/**
	 * Problem count per hour (24 hours) or per day (7 and 30 days), in the PHP timezone.
	 *
	 * @return array  List of ['label' => string, 'count' => int].
	 */
	private static function buckets(array $events, string $period, int $from, int $till): array {
		$hourly = $period === self::PERIOD_24H;
		$format = $hourly ? 'Y-m-d H' : 'Y-m-d';
		$step = $hourly ? 3600 : 86400;
		$buckets = [];

		// Every hour/day touched by the period (the first and the last ones may be partial), including empty ones.
		for ($time = $from; $time < $till + $step; $time += $step) {
			$point = min($time, $till);
			$key = date($format, $point);

			if (!array_key_exists($key, $buckets)) {
				$buckets[$key] = ['label' => date($hourly ? 'H:00' : 'd.m', $point), 'count' => 0];
			}
		}

		foreach ($events as $event) {
			$key = date($format, $event['clock']);

			if (array_key_exists($key, $buckets)) {
				$buckets[$key]['count']++;
			}
		}

		return array_values($buckets);
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
