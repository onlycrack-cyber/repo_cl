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

	// ALERT_TYPE_MESSAGE, ALERT_STATUS_SENT, ALERT_STATUS_FAILED.
	private const ALERT_TYPE_MESSAGE = 0;
	private const ALERT_STATUS_SENT = 1;
	private const ALERT_STATUS_FAILED = 2;

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
	 * @param array    $filter            Normalized report filter (host groups, hosts, severities, problem name).
	 * @param string   $period            PERIOD_24H, PERIOD_7D or PERIOD_30D.
	 * @param int      $now
	 * @param string[] $open_eventids     Events of the currently open problems: their alerts are counted too, even if
	 *                                    the problem started before the period.
	 *
	 * @throws ApiException
	 */
	public function collect(array $filter, string $period, int $now, array $open_eventids = []): array {
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
			// Problems active at any time of the period: started in it, or earlier and not resolved before it.
			'problem_time_from' => $from,
			'problem_time_till' => $now
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
			'carried' => [],
			'prev_events' => [],
			'alerts' => null,
			'alerts_truncated' => false
		];

		foreach ($events as $event) {
			// Started before the period and still open at its start: only needed for "open problems" per day.
			if ((int) $event['clock'] < $from) {
				$data['carried'][] = [
					'clock' => (int) $event['clock'],
					'resolved' => $recovery[$event['r_eventid']] ?? null,
					'severity' => (int) $event['severity'],
					'hosts' => array_column($event['hosts'], 'hostid')
				];

				continue;
			}

			$ack_clock = null;

			foreach ($event['acknowledges'] as $ack) {
				if (((int) $ack['action'] & self::ACTION_ACKNOWLEDGE)
						&& ($ack_clock === null || (int) $ack['clock'] < $ack_clock)) {
					$ack_clock = (int) $ack['clock'];
				}
			}

			$data['events'][] = [
				'eventid' => $event['eventid'],
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

		$this->collectAlerts($data, $open_eventids);

		return $data;
	}

	/**
	 * Notifications sent during the period, per problem event: eventid => [sent, failed, last_clock, last_subject].
	 * Optional: without the permission to read alerts the report shows no alert counts.
	 */
	private function collectAlerts(array &$data, array $open_eventids): void {
		$eventids = array_values(array_unique(array_merge(array_column($data['events'], 'eventid'), $open_eventids)));

		if (!$eventids) {
			$data['alerts'] = [];

			return;
		}

		$limit = $this->limit * 5;
		$alerts = [];

		try {
			foreach (array_chunk($eventids, 1000) as $chunk) {
				$alerts = array_merge($alerts, $this->api->call('alert.get', [
					// alert.get cannot filter by "alerttype": remote command results are skipped below.
					'output' => ['eventid', 'clock', 'subject', 'status', 'alerttype'],
					'eventids' => $chunk,
					'time_from' => $data['from'],
					'time_till' => $data['till'],
					'limit' => $limit + 1
				]));
			}
		}
		catch (ApiException $e) {
			return;
		}

		$data['alerts_truncated'] = count($alerts) > $limit;
		$data['alerts'] = [];

		foreach (array_slice($alerts, 0, $limit) as $alert) {
			if ((int) $alert['alerttype'] != self::ALERT_TYPE_MESSAGE) {
				continue;
			}

			$stat = &$data['alerts'][$alert['eventid']];
			$stat = $stat ?? ['sent' => 0, 'failed' => 0, 'last_clock' => 0, 'last_subject' => ''];

			if ((int) $alert['status'] == self::ALERT_STATUS_SENT) {
				$stat['sent']++;
			}
			elseif ((int) $alert['status'] == self::ALERT_STATUS_FAILED) {
				$stat['failed']++;
			}

			if ((int) $alert['clock'] >= $stat['last_clock']) {
				$stat['last_clock'] = (int) $alert['clock'];
				$stat['last_subject'] = $alert['subject'];
			}

			unset($stat);
		}
	}

	/**
	 * Problems that are still unresolved, one line per trigger (no duplicates however often the trigger fired or
	 * how many alerts it sent): open since, whether it was open the whole period, alerts sent during the period,
	 * how many times it fired during the period, the subject of the last alert.
	 *
	 * @param array      $problems  Open problems of the report (ReportBuilder).
	 * @param array|null $data      Result of collect() (or subset()).
	 */
	public static function unresolved(array $problems, ?array $data): array {
		$items = [];

		foreach ($problems as $problem) {
			$id = $problem['triggerid'];

			if (!array_key_exists($id, $items)) {
				$items[$id] = [
					'triggerid' => $id,
					'name' => $problem['name'],
					'hosts' => $problem['hosts'],
					'severity' => $problem['severity'],
					'since' => $problem['clock'],
					'open_count' => 0,
					'acknowledged' => true,
					'eventids' => []
				];
			}

			$item = &$items[$id];
			$item['open_count']++;
			$item['severity'] = max($item['severity'], $problem['severity']);
			$item['acknowledged'] = $item['acknowledged'] && $problem['acknowledged'] == 1;
			$item['eventids'][] = $problem['eventid'];

			if ($problem['clock'] < $item['since']) {
				$item['since'] = $problem['clock'];
			}
			else {
				$item['name'] = $problem['name'];
			}

			unset($item);
		}

		$repeats = [];

		foreach ($data['events'] ?? [] as $event) {
			if (array_key_exists($event['triggerid'], $items)) {
				$repeats[$event['triggerid']] = ($repeats[$event['triggerid']] ?? 0) + 1;
				$items[$event['triggerid']]['eventids'][] = $event['eventid'];
			}
		}

		$from = $data['from'] ?? null;
		$till = $data['till'] ?? time();
		$alerts = $data['alerts'] ?? null;
		$result = [];

		foreach ($items as $id => $item) {
			$sent = null;
			$failed = null;
			$last_subject = '';
			$last_clock = 0;

			if ($alerts !== null) {
				$sent = 0;
				$failed = 0;

				foreach (array_unique($item['eventids']) as $eventid) {
					if (array_key_exists($eventid, $alerts)) {
						$sent += $alerts[$eventid]['sent'];
						$failed += $alerts[$eventid]['failed'];

						if ($alerts[$eventid]['last_clock'] >= $last_clock) {
							$last_clock = $alerts[$eventid]['last_clock'];
							$last_subject = $alerts[$eventid]['last_subject'];
						}
					}
				}
			}

			unset($item['eventids']);
			$result[] = $item + [
				'age' => max(0, $till - $item['since']),
				'whole_period' => $from !== null && $item['since'] <= $from,
				'repeats' => $repeats[$id] ?? 0,
				'alerts_sent' => $sent,
				'alerts_failed' => $failed,
				'last_alert' => $last_subject
			];
		}

		// Problems open the whole period first, then by severity and age.
		usort($result, static function (array $a, array $b): int {
			return [$b['whole_period'], $b['severity'], $b['age']] <=> [$a['whole_period'], $a['severity'], $a['age']];
		});

		return $result;
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
		$data['carried'] = array_values(array_filter($data['carried'] ?? [],
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
			'open_start' => count(array_filter($data['carried'] ?? [], static function (array $p) use ($data): bool {
				return $p['resolved'] === null || $p['resolved'] > $data['from'];
			})),
			'open_end' => count(array_filter(array_merge($events, $data['carried'] ?? []),
				static function (array $p) use ($data): bool {
					return $p['resolved'] === null || $p['resolved'] > $data['till'];
				}
			)),
			'buckets' => self::buckets($data),
			'recurring' => array_values(array_filter(array_slice($triggers, 0, self::TOP), static function ($t): bool {
				return $t['count'] > 1;
			})),
			'top_hosts' => array_slice($hosts, 0, self::TOP)
		];
	}

	/**
	 * Per hour (24 hours) or per day (7 and 30 days), in the PHP timezone:
	 *   count        new problems,
	 *   by_severity  new problems per severity,
	 *   resolved     problems resolved in the hour/day (also those that started before the period),
	 *   open         problems open at the end of the hour/day (at the report time for the last one).
	 *
	 * @return array  List of ['label', 'start', 'end', 'count', 'by_severity', 'resolved', 'open'].
	 */
	private static function buckets(array $data): array {
		$hourly = $data['period'] === self::PERIOD_24H;
		$from = $data['from'];
		$till = $data['till'];
		$step = $hourly ? 3600 : 86400;
		$buckets = [];

		// Every hour/day touched by the period (the first and the last ones may be partial), including empty ones.
		for ($time = $from; $time < $till + $step; $time += $step) {
			$start = self::bucketStart(min($time, $till), $hourly);

			if (!array_key_exists($start, $buckets)) {
				$end = $hourly ? $start + 3600 : strtotime('+1 day', $start);

				$buckets[$start] = [
					'label' => date($hourly ? 'H:00' : 'd.m', $start),
					'start' => max($start, $from),
					'end' => min($end, $till),
					'count' => 0,
					'by_severity' => array_fill_keys(array_keys(Severity::NAMES), 0),
					'resolved' => 0,
					'open' => 0
				];
			}
		}

		foreach ($data['events'] as $event) {
			$start = self::bucketStart($event['clock'], $hourly);

			if (array_key_exists($start, $buckets)) {
				$buckets[$start]['count']++;
				$buckets[$start]['by_severity'][$event['severity']]++;
			}
		}

		$active = array_merge($data['events'], $data['carried'] ?? []);

		foreach ($active as $problem) {
			if ($problem['resolved'] !== null && $problem['resolved'] >= $from && $problem['resolved'] <= $till) {
				$start = self::bucketStart($problem['resolved'], $hourly);

				if (array_key_exists($start, $buckets)) {
					$buckets[$start]['resolved']++;
				}
			}
		}

		foreach ($buckets as &$bucket) {
			foreach ($active as $problem) {
				if ($problem['clock'] <= $bucket['end']
						&& ($problem['resolved'] === null || $problem['resolved'] > $bucket['end'])) {
					$bucket['open']++;
				}
			}
		}
		unset($bucket);

		return array_values($buckets);
	}

	private static function bucketStart(int $time, bool $hourly): int {
		return (int) strtotime(date($hourly ? 'Y-m-d H:00:00' : 'Y-m-d 00:00:00', $time));
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
