<?php declare(strict_types = 0);

namespace Modules\CustomReports\Tests;

use Modules\CustomReports\Lib\ReportBuilder;

class ReportBuilderTest extends TestCase {

	private function build(array $filter, int $limit = 1000): array {
		return (new ReportBuilder($this->api(), $limit))->build($filter + ['analytics' => 'none'], self::NOW);
	}

	public function testCountsBySeverity(): void {
		$report = $this->build([]);

		$this->assertSame(6, $report['total']);
		$this->assertSame([0 => 0, 1 => 1, 2 => 1, 3 => 2, 4 => 1, 5 => 1], $report['by_severity']);
		$this->assertFalse($report['truncated']);
	}

	public function testDefaultSortIsBySeverity(): void {
		$severities = array_column($this->build([])['problems'], 'severity');

		$this->assertSame([5, 4, 3, 3, 2, 1], $severities);
	}

	public function testLimitTruncates(): void {
		$report = $this->build([], 3);

		$this->assertCount(3, $report['problems']);
		$this->assertTrue($report['truncated']);
	}

	public function testFilterBySeverityAndGroup(): void {
		$this->assertSame(['Disk failed'], array_column($this->build(['severities' => [5]])['problems'], 'name'));

		$names = array_column($this->build(['groupids' => ['30']])['problems'], 'name');
		$this->assertSame(['Port down'], $names);
	}

	public function testAccessLimitsQueriesButNotTheShownFilter(): void {
		$api = $this->api();
		$report = (new ReportBuilder($api))->build([
			'groupids' => ['10', '20'],
			'access_hostids' => ['101', '102'],
			'analytics' => 'none'
		], self::NOW);

		$this->assertSame(['101', '102'], $api->paramsOf('problem.get')[0]['hostids']);
		// Only the groups with visible hosts are listed (and become parts of a split report).
		$this->assertSame(['10' => 'Web'], $report['filter_info']['groups']);
		// Trigger of web-01 and db-01: db-01 is not visible, so the problem is not either.
		$this->assertNotContains('Replication lag', array_column($report['problems'], 'name'));
		$this->assertSame(['Nginx is down', 'High memory'], array_column($report['problems'], 'name'));
	}

	public function testQueryFilter(): void {
		$this->assertSame(['1', '2'], ReportBuilder::queryFilter(['hostids' => [], 'access_hostids' => ['1', '2']])['hostids']);
		$this->assertSame(['2'], ReportBuilder::queryFilter(['hostids' => ['2', '3'], 'access_hostids' => ['1', '2']])['hostids']);
		$this->assertSame(['0'], ReportBuilder::queryFilter(['hostids' => ['3'], 'access_hostids' => ['1']])['hostids'],
			'nothing visible must not become "all hosts"'
		);
		$this->assertSame(['3'], ReportBuilder::queryFilter(['hostids' => ['3'], 'access_hostids' => null])['hostids']);
	}

	public function testNormalizeFilterDropsGarbage(): void {
		$filter = ReportBuilder::normalizeFilter(['groupids' => ['1', 'x', '0', '1'], 'severities' => [9, 3],
			'period' => 'nonsense', 'sortfield' => 'evil'
		]);

		$this->assertSame(['1'], $filter['groupids']);
		$this->assertSame([3], $filter['severities']);
		$this->assertSame('all', $filter['period']);
		$this->assertSame(ReportBuilder::SORT_SEVERITY, $filter['sortfield']);
		$this->assertNull($filter['access_hostids']);
	}
}
