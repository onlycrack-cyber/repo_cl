<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * Host permissions of report recipients: a user gets only the problems of the hosts he may see in Zabbix.
 *
 * Rules of Zabbix: a Super admin sees everything; for other users the host group rights of all their user groups are
 * combined - "Deny" in any group wins, otherwise "Read" or "Read-write" gives access. A host is visible if at least one
 * of its groups is readable and none is denied.
 *
 * Users with the same rights share an "access key", so a report is built once per set of rights, not per user.
 * Tag filters of user groups are not applied (documented limitation).
 */
class RecipientAccess {

	// Everything is visible: Super admins and additional addresses typed in the schedule.
	public const FULL = '*';

	private const USER_TYPE_SUPER_ADMIN = 3;
	private const PERM_DENY = 0;
	private const PERM_READ = 2;

	private ApiClientInterface $api;

	/** @var array  access key => string[] hostids */
	private array $hostids = [];

	public function __construct(ApiClientInterface $api) {
		$this->api = $api;
	}

	/**
	 * Access keys of users: [userid => key]. Unknown users (deleted, no rights to read them) get no key.
	 *
	 * @throws ApiException
	 */
	public function keys(array $userids): array {
		$userids = ReportBuilder::ids($userids);

		if (!$userids) {
			return [];
		}

		$users = $this->api->call('user.get', [
			'output' => ['userid'],
			'userids' => $userids,
			'selectRole' => ['type'],
			'selectUsrgrps' => ['usrgrpid']
		]);

		$usrgrpids = [];

		foreach ($users as $user) {
			foreach ($user['usrgrps'] as $group) {
				$usrgrpids[$group['usrgrpid']] = true;
			}
		}

		$rights = [];

		if ($usrgrpids) {
			foreach ($this->api->call('usergroup.get', [
				'output' => ['usrgrpid'],
				'usrgrpids' => array_keys($usrgrpids),
				'selectHostGroupRights' => ['id', 'permission']
			]) as $group) {
				$rights[$group['usrgrpid']] = $group['hostgroup_rights'] ?? [];
			}
		}

		$keys = [];

		foreach ($users as $user) {
			if ((int) ($user['role']['type'] ?? 0) == self::USER_TYPE_SUPER_ADMIN) {
				$keys[(string) $user['userid']] = self::FULL;
				continue;
			}

			$read = [];
			$deny = [];

			foreach ($user['usrgrps'] as $group) {
				foreach ($rights[$group['usrgrpid']] ?? [] as $right) {
					if ((int) $right['permission'] == self::PERM_DENY) {
						$deny[$right['id']] = true;
					}
					elseif ((int) $right['permission'] >= self::PERM_READ) {
						$read[$right['id']] = true;
					}
				}
			}

			$keys[(string) $user['userid']] = self::makeKey(array_keys(array_diff_key($read, $deny)), array_keys($deny));
		}

		return $keys;
	}

	/**
	 * "r:1,2|d:5": readable and denied host groups.
	 */
	public static function makeKey(array $read, array $deny): string {
		$read = array_map('strval', $read);
		$deny = array_map('strval', $deny);
		sort($read, SORT_NUMERIC);
		sort($deny, SORT_NUMERIC);

		return 'r:'.implode(',', $read).'|d:'.implode(',', $deny);
	}

	/**
	 * @return array  [string[] readable groupids, string[] denied groupids]
	 */
	public static function parseKey(string $key): array {
		if (!preg_match('/^r:([\d,]*)\|d:([\d,]*)$/', $key, $m)) {
			return [[], []];
		}

		return [$m[1] === '' ? [] : explode(',', $m[1]), $m[2] === '' ? [] : explode(',', $m[2])];
	}

	/**
	 * Visible hosts of an access key; null - all hosts.
	 *
	 * @throws ApiException
	 */
	public function hostids(string $key): ?array {
		if ($key === self::FULL) {
			return null;
		}

		if (!array_key_exists($key, $this->hostids)) {
			[$read, $deny] = self::parseKey($key);
			$hostids = [];

			if ($read) {
				$deny = array_flip($deny);

				foreach ($this->api->call('host.get', [
					'output' => ['hostid'],
					'groupids' => $read,
					'selectHostGroups' => ['groupid']
				]) as $host) {
					foreach ($host['hostgroups'] ?? [] as $group) {
						if (array_key_exists($group['groupid'], $deny)) {
							continue 2;
						}
					}

					$hostids[] = (string) $host['hostid'];
				}
			}

			$this->hostids[$key] = $hostids;
		}

		return $this->hostids[$key];
	}

	/**
	 * Report filter limited to the hosts of an access key; null - the recipients see none of the hosts of the filter.
	 *
	 * @throws ApiException
	 */
	public function restrictFilter(array $filter, string $key): ?array {
		$visible = $this->hostids($key);

		if ($visible === null) {
			return $filter;
		}

		$hostids = ReportBuilder::ids($filter['hostids'] ?? []);

		if (!($hostids ? array_intersect($hostids, $visible) : $visible)) {
			return null;
		}

		// The filter of the schedule stays as it is (it is shown in the report), the queries are limited.
		$filter['access_hostids'] = $visible;

		return $filter;
	}
}
