<?php declare(strict_types = 0);

namespace Modules\CustomReports\Tests;

use Modules\CustomReports\Lib\ChangeAnalytics;
use Modules\CustomReports\Lib\PeriodAnalytics;
use Modules\CustomReports\Lib\ReportBuilder;

/**
 * Suppressed problems (hosts in maintenance): "show_suppressed" off (the default) must exclude them not only from
 * the list of open problems (problem.get), but also from the period analytics and "Changes" (event.get) - so that
 * the counters on one page agree with each other.
 */
class SuppressedFilterTest extends TestCase {

	// A normal and a suppressed problem event, both open, both started within the last 24 hours.
	private const EVENTS = [
		['eventid' => '101', 'objectid' => '1001', 'clock' => self::NOW - 3600, 'severity' => '4',
			'name' => 'Nginx is down', 'r_eventid' => '0', 'suppressed' => '0',
			'hosts' => [['hostid' => '101', 'name' => 'web-01']], 'acknowledges' => []
		],
		['eventid' => '102', 'objectid' => '3001', 'clock' => self::NOW - 7200, 'severity' => '3',
			'name' => 'Port down', 'r_eventid' => '0', 'suppressed' => '1',
			'hosts' => [['hostid' => '301', 'name' => 'sw-01']], 'acknowledges' => []
		]
	];

	protected function api(): FakeApi {
		return parent::api()->on('event.get', function (array $params): array {
			// Recovery time lookups and the previous period: nothing.
			if (array_key_exists('eventids', $params) || array_key_exists('time_from', $params)) {
				return [];
			}

			return array_values(array_filter(self::EVENTS, static function (array $event) use ($params): bool {
				return ($params['suppressed'] ?? null) !== false || $event['suppressed'] === '0';
			}));
		});
	}

	/**
	 * event.get calls that select problems (not the recovery lookups).
	 */
	private static function problemQueries(FakeApi $api): array {
		return array_values(array_filter($api->paramsOf('event.get'), static function (array $params): bool {
			return !array_key_exists('eventids', $params);
		}));
	}

	public function testPeriodAnalyticsExcludesSuppressedByDefault(): void {
		$api = $this->api();
		$report = (new ReportBuilder($api))->build(['analytics' => PeriodAnalytics::PERIOD_24H], self::NOW);

		foreach (self::problemQueries($api) as $params) {
			$this->assertFalse($params['suppressed'] ?? null,
				'every event.get of the period analytics must filter suppressed problems out');
		}

		$this->assertSame(1, $report['analytics']['new']);
		$this->assertSame(1, $report['analytics']['open_end']);
	}

	public function testPeriodAnalyticsIncludesSuppressedWhenAsked(): void {
		$api = $this->api();
		$report = (new ReportBuilder($api))->build(['analytics' => PeriodAnalytics::PERIOD_24H,
			'show_suppressed' => 1
		], self::NOW);

		foreach (self::problemQueries($api) as $params) {
			$this->assertArrayNotHasKey('suppressed', $params);
		}

		$this->assertSame(2, $report['analytics']['new']);
		$this->assertSame(2, $report['analytics']['open_end']);
	}

	public function testChangeAnalyticsExcludesSuppressedByDefault(): void {
		$api = $this->api();
		$filter = ChangeAnalytics::normalizeFilter([]);
		$data = (new ChangeAnalytics($api))->collect($filter, self::NOW - 86400, self::NOW);
		$changes = ChangeAnalytics::compute($data, ChangeAnalytics::GROUP_BY_GROUP);

		foreach (self::problemQueries($api) as $params) {
			$this->assertFalse($params['suppressed'] ?? null);
		}

		$this->assertSame(1, $changes['overview']['new']);
		$this->assertSame(1, $changes['overview']['open_end']);
	}

	public function testChangeAnalyticsIncludesSuppressedWhenAsked(): void {
		$api = $this->api();
		$filter = ChangeAnalytics::normalizeFilter(['show_suppressed' => 1]);
		$data = (new ChangeAnalytics($api))->collect($filter, self::NOW - 86400, self::NOW);
		$changes = ChangeAnalytics::compute($data, ChangeAnalytics::GROUP_BY_GROUP);

		foreach (self::problemQueries($api) as $params) {
			$this->assertArrayNotHasKey('suppressed', $params);
		}

		$this->assertSame(2, $changes['overview']['new']);
		$this->assertSame(2, $changes['overview']['open_end']);
	}

	public function testChangesFilterNormalizeCarriesShowSuppressed(): void {
		$this->assertSame(0, ChangeAnalytics::normalizeFilter([])['show_suppressed']);
		$this->assertSame(1, ChangeAnalytics::normalizeFilter(['show_suppressed' => 1])['show_suppressed']);
	}
}
