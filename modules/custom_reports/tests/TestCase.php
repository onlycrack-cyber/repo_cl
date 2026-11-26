<?php declare(strict_types = 0);

namespace Modules\CustomReports\Tests;

use Modules\CustomReports\Lib\ModuleConfig;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * A small Zabbix for tests: host groups, hosts, triggers, open problems, users with their rights and media, and an
 * alert script that records what it gets.
 *
 * Groups: 10 Web, 20 DB, 30 Network. Hosts: 101 web-01 and 102 web-02 (Web), 201 db-01 (DB), 301 sw-01 (Network),
 * 900 shared (Web + DB). Trigger 9001 belongs to two hosts (web-01 and db-01).
 *
 * Users: 1 Admin (Super admin), 2 web (Web: read), 3 nobody (no rights), 4 web+db (Web and DB: read),
 * 5 db (DB: read, Web: deny).
 */
abstract class TestCase extends BaseTestCase {

	protected const NOW = 1763550000;
	protected const MEDIATYPE_SCRIPT = '50';

	protected const GROUPS = ['10' => 'Web', '20' => 'DB', '30' => 'Network'];

	protected const HOSTS = [
		'101' => ['name' => 'web-01', 'groups' => ['10']],
		'102' => ['name' => 'web-02', 'groups' => ['10']],
		'201' => ['name' => 'db-01', 'groups' => ['20']],
		'301' => ['name' => 'sw-01', 'groups' => ['30']],
		'900' => ['name' => 'shared', 'groups' => ['10', '20']]
	];

	// triggerid => [hostids, severity, name, age in seconds, acknowledged]
	protected const TRIGGERS = [
		'1001' => [['101'], 4, 'Nginx is down', 3600 * 30, 0],
		'1002' => [['102'], 2, 'High memory', 600, 1],
		'2001' => [['201'], 5, 'Disk failed', 86400 * 9, 0],
		'3001' => [['301'], 3, 'Port down', 7200, 0],
		'9001' => [['101', '201'], 3, 'Replication lag', 1800, 0],
		'9002' => [['900'], 1, 'Shared host info', 300, 0]
	];

	// userid => [Super admin, usrgrpids]
	protected const USERS = [
		'1' => [true, ['7']],
		'2' => [false, ['11']],
		'3' => [false, ['12']],
		'4' => [false, ['11', '14']],
		'5' => [false, ['14', '15']]
	];

	// usrgrpid => [groupid => permission]: 0 deny, 2 read, 3 read-write.
	protected const USER_GROUPS = [
		'7' => [],
		'11' => ['10' => 2],
		'12' => [],
		'14' => ['20' => 3],
		'15' => ['10' => 0]
	];

	protected string $tmp;

	protected function setUp(): void {
		$this->tmp = sys_get_temp_dir().'/cr_test_'.bin2hex(random_bytes(4));
		mkdir($this->tmp.'/alertscripts', 0700, true);
		$this->writeAlertScript();
	}

	protected function tearDown(): void {
		exec('rm -rf '.escapeshellarg($this->tmp));
	}

	/**
	 * Module config for tests: no retry pauses, the test alert script directory.
	 */
	protected function config(array $values = []): array {
		return array_replace(ModuleConfig::DEFAULTS, [
			'data_dir' => $this->tmp,
			'log_file' => $this->tmp.'/test.log',
			'alert_scripts_path' => $this->tmp.'/alertscripts',
			'send_retries' => 0,
			'send_retry_delay' => 0,
			'pdf_converter' => 'none'
		], $values);
	}

	/**
	 * The alert script appends "sendto<TAB>subject<TAB>message" (newlines escaped) to calls.log; while the file
	 * "fail" holds a number > 0 it decrements it and exits with code 1.
	 */
	private function writeAlertScript(): void {
		$script = $this->tmp.'/alertscripts/test.sh';
		file_put_contents($script, <<<'SH'
#!/bin/sh
dir=$(dirname "$0")/..
if [ -f "$dir/fail" ] && [ "$(cat "$dir/fail")" -gt 0 ]; then
	echo $(( $(cat "$dir/fail") - 1 )) > "$dir/fail"
	echo "temporary failure" >&2
	exit 1
fi
printf '%s\t%s\t%s\n' "$1" "$2" "$(printf '%s' "$3" | tr '\n' '|')" >> "$dir/calls.log"
SH
		);
		chmod($script, 0700);
	}

	/**
	 * Calls of the alert script: list of ['sendto', 'subject', 'message'].
	 */
	protected function scriptCalls(): array {
		$file = $this->tmp.'/calls.log';

		if (!is_file($file)) {
			return [];
		}

		return array_map(static function (string $line): array {
			return array_combine(['sendto', 'subject', 'message'], explode("\t", $line, 3));
		}, file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
	}

	protected function failNextScriptCalls(int $count): void {
		file_put_contents($this->tmp.'/fail', (string) $count);
	}

	protected function api(): FakeApi {
		return new FakeApi([
			'problem.get' => function (array $params): array {
				return $this->problems($params);
			},
			'trigger.get' => function (array $params): array {
				$result = [];

				foreach ($params['triggerids'] as $triggerid) {
					$result[$triggerid] = ['triggerid' => $triggerid, 'hosts' => array_map(static function ($hostid) {
						return ['hostid' => $hostid, 'name' => self::HOSTS[$hostid]['name']];
					}, self::TRIGGERS[$triggerid][0])];
				}

				return $result;
			},
			'host.get' => function (array $params): array {
				return $this->hosts($params);
			},
			'hostgroup.get' => function (array $params): array {
				$result = [];

				foreach (self::GROUPS as $groupid => $name) {
					if (isset($params['groupids']) && !in_array((string) $groupid, array_map('strval', $params['groupids']), true)) {
						continue;
					}

					if (isset($params['hostids'])) {
						$has = false;

						foreach ($params['hostids'] as $hostid) {
							$has = $has || in_array((string) $groupid, self::HOSTS[$hostid]['groups'] ?? [], true);
						}

						if (!$has) {
							continue;
						}
					}

					$result[] = ['groupid' => (string) $groupid, 'name' => $name];
				}

				return $result;
			},
			'user.get' => function (array $params): array {
				$result = [];

				foreach ($params['userids'] as $userid) {
					if (!array_key_exists($userid, self::USERS)) {
						continue;
					}

					[$super, $usrgrpids] = self::USERS[$userid];
					$result[] = [
						'userid' => (string) $userid,
						'role' => ['type' => $super ? '3' : '1'],
						'usrgrps' => array_map(static function ($id) {
							return ['usrgrpid' => $id];
						}, $usrgrpids),
						'medias' => [['mediatypeid' => self::MEDIATYPE_SCRIPT, 'sendto' => 'u'.$userid, 'active' => '0']]
					];
				}

				return $result;
			},
			'usergroup.get' => function (array $params): array {
				$result = [];

				foreach ($params['usrgrpids'] as $usrgrpid) {
					$rights = [];

					foreach (self::USER_GROUPS[$usrgrpid] ?? [] as $groupid => $permission) {
						$rights[] = ['id' => (string) $groupid, 'permission' => (string) $permission];
					}

					$users = [];

					foreach (self::USERS as $userid => [, $usrgrpids]) {
						if (in_array($usrgrpid, $usrgrpids, true)) {
							$users[] = ['userid' => (string) $userid];
						}
					}

					$result[] = ['usrgrpid' => $usrgrpid, 'users_status' => '0', 'hostgroup_rights' => $rights,
						'users' => $users
					];
				}

				return $result;
			},
			'mediatype.get' => [[
				'mediatypeid' => self::MEDIATYPE_SCRIPT,
				'name' => 'Test script',
				'type' => '1',
				'status' => '0',
				'exec_path' => 'test.sh',
				'parameters' => [
					['sortorder' => '0', 'value' => '{ALERT.SENDTO}'],
					['sortorder' => '1', 'value' => '{ALERT.SUBJECT}'],
					['sortorder' => '2', 'value' => '{ALERT.MESSAGE}']
				]
			]],
			// Event history (period analytics): none.
			'event.get' => [],
			'alert.get' => []
		]);
	}

	/**
	 * problem.get: a problem matches "hostids" / "groupids" if any host of its trigger does (as in Zabbix).
	 */
	private function problems(array $params): array {
		$result = [];

		foreach (self::TRIGGERS as $triggerid => [$hostids, $severity, $name, $age, $ack]) {
			if (isset($params['hostids']) && !array_intersect($hostids, array_map('strval', $params['hostids']))) {
				continue;
			}

			if (isset($params['groupids'])) {
				$groups = [];

				foreach ($hostids as $hostid) {
					$groups = array_merge($groups, self::HOSTS[$hostid]['groups']);
				}

				if (!array_intersect($groups, array_map('strval', $params['groupids']))) {
					continue;
				}
			}

			if (isset($params['severities']) && !in_array($severity, array_map('intval', $params['severities']), true)) {
				continue;
			}

			$result[] = ['eventid' => (string) ($triggerid * 10), 'objectid' => (string) $triggerid,
				'clock' => (string) (self::NOW - $age), 'name' => $name, 'severity' => (string) $severity,
				'acknowledged' => (string) $ack
			];
		}

		usort($result, static function (array $a, array $b): int {
			return $b['eventid'] <=> $a['eventid'];
		});

		return array_slice($result, 0, $params['limit'] ?? count($result));
	}

	private function hosts(array $params): array {
		$result = [];

		foreach (self::HOSTS as $hostid => $host) {
			$hostid = (string) $hostid;

			if (isset($params['hostids']) && !in_array($hostid, array_map('strval', $params['hostids']), true)) {
				continue;
			}

			if (isset($params['groupids']) && !array_intersect($host['groups'], array_map('strval', $params['groupids']))) {
				continue;
			}

			$result[] = ['hostid' => $hostid, 'name' => $host['name'],
				'hostgroups' => array_map(static function ($groupid) {
					return ['groupid' => $groupid, 'name' => self::GROUPS[$groupid]];
				}, $host['groups'])
			];
		}

		return $result;
	}
}
