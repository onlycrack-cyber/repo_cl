<?php declare(strict_types = 0);

namespace Modules\CustomReports\Tests;

use Modules\CustomReports\Lib\OpenStructure;

class OpenStructureTest extends TestCase {

	private static function problem(int $severity, int $age, int $ack, string $name): array {
		return ['severity' => $severity, 'age' => $age, 'acknowledged' => $ack, 'name' => $name,
			'hosts' => [['hostid' => '1', 'name' => 'h']]
		];
	}

	public function testTargets(): void {
		$targets = [5 => ['ack' => 900, 'resolve' => 3600], 2 => ['ack' => 0, 'resolve' => 86400]];
		$result = OpenStructure::targets([
			self::problem(5, 600, 0, 'fresh'),                 // within both
			self::problem(5, 1200, 0, 'no reaction'),          // ack overdue
			self::problem(5, 1200, 1, 'acknowledged'),         // within
			self::problem(5, 7200, 0, 'both'),                 // both overdue
			self::problem(5, 7200, 1, 'not resolved'),         // resolve overdue
			self::problem(2, 90000, 0, 'old warning'),         // resolve overdue, no ack target
			self::problem(3, 999999, 0, 'no targets')          // severity not checked
		], $targets);

		$this->assertSame(['ack' => 900, 'resolve' => 3600, 'open' => 5, 'ack_overdue' => 2, 'resolve_overdue' => 2,
			'in_time' => 2
		], $result['rows'][5]);
		$this->assertSame(1, $result['rows'][2]['resolve_overdue']);
		$this->assertArrayNotHasKey(3, $result['rows']);
		$this->assertSame(6, $result['checked']);
		$this->assertSame(4, $result['overdue']);

		// Higher severity first, then the bigger excess; "both" for no reaction and not resolved.
		$this->assertSame(['both', 'not resolved', 'no reaction', 'old warning'], array_column($result['top'], 'name'));
		$this->assertSame('both', $result['top'][0]['breach']);
		$this->assertSame(3600, $result['top'][0]['over']);
		$this->assertSame('ack', $result['top'][2]['breach']);
	}
}
