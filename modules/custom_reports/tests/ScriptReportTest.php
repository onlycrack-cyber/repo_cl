<?php declare(strict_types = 0);

namespace Modules\CustomReports\Tests;

use Modules\CustomReports\Lib\ScriptReport;
use RuntimeException;

class ScriptReportTest extends TestCase {

	public function testJsonReport(): void {
		$report = ScriptReport::parse(json_encode([
			'title' => 'Clusters',
			'status' => 'warning',
			'kpi' => ['Hosts' => 42],
			'tables' => [['title' => 'Free', 'columns' => ['Cluster', 'CPU'], 'rows' => [['c1', '10%'], ['c2', '20%']]]]
		]));

		$this->assertSame('json', $report['format']);
		$this->assertSame('Clusters', $report['title']);
		$this->assertSame('warning', $report['status']);
		$this->assertSame('42', $report['kpi'][0]['value']);
		$this->assertCount(1, $report['tables']);
		$this->assertCount(2, $report['tables'][0]['rows']);
	}

	public function testJsonListIsOneTable(): void {
		$report = ScriptReport::parse('[{"host": "a", "disk": 91}, {"host": "b", "disk": 40}]');

		$this->assertCount(2, $report['tables'][0]['rows']);
		$this->assertSame(['host', 'disk'], array_slice(array_map(static function ($column) {
			return is_array($column) ? $column['name'] ?? $column['title'] ?? reset($column) : $column;
		}, $report['tables'][0]['columns']), 0, 2));
	}

	public function testCsvWithSemicolonAndBom(): void {
		$report = ScriptReport::parse("\xEF\xBB\xBFCluster;Free CPU\nc1;10\nc2;20\n");

		$this->assertSame('csv', $report['format']);
		$this->assertCount(2, $report['tables'][0]['rows']);
	}

	public function testRowLimit(): void {
		$rows = [];

		for ($i = 0; $i < 50; $i++) {
			$rows[] = ['n' => $i];
		}

		$report = ScriptReport::parse(json_encode($rows), ScriptReport::FORMAT_AUTO, 10);
		$this->assertCount(10, $report['tables'][0]['rows']);
	}

	public function testPlainText(): void {
		$report = ScriptReport::parse("all good\n");

		$this->assertSame('all good', $report['text']);
		$this->assertSame([], $report['tables']);
	}

	public function testInvalidJsonWhenJsonIsRequired(): void {
		$this->expectException(RuntimeException::class);
		ScriptReport::parse('not json', ScriptReport::FORMAT_JSON);
	}

	public function testScriptNamesCannotLeaveTheDirectory(): void {
		$this->assertTrue(ScriptReport::isValidName('cr_disk_usage.sh'));
		$this->assertFalse(ScriptReport::isValidName('../bin/sh'));
		$this->assertFalse(ScriptReport::isValidName('/etc/passwd'));
	}
}
