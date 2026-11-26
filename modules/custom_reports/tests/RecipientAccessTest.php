<?php declare(strict_types = 0);

namespace Modules\CustomReports\Tests;

use Modules\CustomReports\Lib\RecipientAccess;

class RecipientAccessTest extends TestCase {

	public function testKeysFollowZabbixRules(): void {
		$keys = (new RecipientAccess($this->api()))->keys(['1', '2', '3', '4', '5', '999']);

		$this->assertSame(RecipientAccess::FULL, $keys['1'], 'Super admin sees everything');
		$this->assertSame('r:10|d:', $keys['2']);
		$this->assertSame('r:|d:', $keys['3'], 'no rights');
		$this->assertSame('r:10,20|d:', $keys['4'], 'rights of all user groups are combined');
		$this->assertSame('r:20|d:10', $keys['5'], 'deny is kept separately');
		$this->assertArrayNotHasKey('999', $keys, 'unknown user');
	}

	public function testUsersWithTheSameRightsShareAKey(): void {
		$this->assertSame(RecipientAccess::makeKey(['20', '10'], []), RecipientAccess::makeKey(['10', '20'], []));
		$this->assertSame([['10', '20'], ['5']], RecipientAccess::parseKey('r:10,20|d:5'));
		$this->assertSame([[], []], RecipientAccess::parseKey('garbage'));
	}

	public function testHostsOfAKey(): void {
		$access = new RecipientAccess($this->api());

		$this->assertNull($access->hostids(RecipientAccess::FULL));
		$this->assertSame(['101', '102', '900'], $access->hostids('r:10|d:'));
		$this->assertSame([], $access->hostids('r:|d:'));
		// "shared" is in Web and DB: denied Web hides it even though DB is readable.
		$this->assertSame(['201'], $access->hostids('r:20|d:10'));
	}

	public function testRestrictFilter(): void {
		$access = new RecipientAccess($this->api());

		$this->assertSame(['hostids' => []], $access->restrictFilter(['hostids' => []], RecipientAccess::FULL));
		$this->assertNull($access->restrictFilter(['hostids' => []], 'r:|d:'), 'sees nothing');
		$this->assertNull($access->restrictFilter(['hostids' => ['301']], 'r:10|d:'), 'sees none of the selected hosts');

		$filter = $access->restrictFilter(['hostids' => ['101', '301']], 'r:10|d:');
		$this->assertSame(['101', '301'], $filter['hostids'], 'the shown filter is not changed');
		$this->assertSame(['101', '102', '900'], $filter['access_hostids']);
	}
}
