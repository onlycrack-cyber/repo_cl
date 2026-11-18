<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * Splits one report into separate reports per host group or per host.
 *
 * A problem of a trigger with several hosts (or of a host in several groups) appears in every
 * matching part. The split is done in memory, so the problem list is read from the API once.
 */
class ReportSplitter {

	public const SPLIT_NONE = 'none';
	public const SPLIT_GROUP = 'group';
	public const SPLIT_HOST = 'host';

	public const MODES = [self::SPLIT_NONE, self::SPLIT_GROUP, self::SPLIT_HOST];

	public static function isValid(string $mode): bool {
		return in_array($mode, self::MODES, true);
	}

	/**
	 * @param array  $report      Result of ReportBuilder::build() (with host groups for SPLIT_GROUP).
	 * @param string $mode        SPLIT_*.
	 * @param bool   $skip_empty  Do not return parts without problems.
	 *
	 * @return array  List of ['id' => string, 'name' => string, 'report' => array]; 'id' is a groupid or a hostid,
	 *                'id' and 'name' are empty for SPLIT_NONE.
	 */
	public static function split(array $report, string $mode, bool $skip_empty): array {
		switch ($mode) {
			case self::SPLIT_GROUP:
				$parts = self::splitByGroup($report);
				break;

			case self::SPLIT_HOST:
				$parts = self::splitByHost($report);
				break;

			default:
				$parts = [['id' => '', 'name' => '', 'report' => $report]];
		}

		if ($skip_empty) {
			$parts = array_values(array_filter($parts, static function (array $part): bool {
				return $part['report']['total'] > 0;
			}));
		}

		return $parts;
	}

	private static function splitByGroup(array $report): array {
		// Selected groups define the parts; without a group filter every group that has problems is a part.
		$groups = $report['filter_info']['groups'];
		$problems_by_group = [];

		foreach ($report['problems'] as $problem) {
			$problem_groups = [];

			foreach ($problem['hosts'] as $host) {
				$problem_groups += $report['host_groups'][$host['hostid']] ?? [];
			}

			foreach ($problem_groups as $groupid => $name) {
				if ($report['filter']['groupids'] && !array_key_exists($groupid, $groups)) {
					continue;
				}

				if (!array_key_exists($groupid, $groups)) {
					$groups[$groupid] = $name;
				}

				$problems_by_group[$groupid][] = $problem;
			}
		}

		natcasesort($groups);
		$parts = [];

		foreach ($groups as $groupid => $name) {
			$parts[] = [
				'id' => (string) $groupid,
				'name' => $name,
				'report' => ReportBuilder::subset($report, $problems_by_group[$groupid] ?? [], 'Host group: '.$name)
			];
		}

		return $parts;
	}

	private static function splitByHost(array $report): array {
		// Selected hosts define the parts; otherwise every host that has problems is a part.
		$hosts = $report['filter_info']['hosts'];
		$problems_by_host = [];

		foreach ($report['problems'] as $problem) {
			foreach ($problem['hosts'] as $host) {
				if ($report['filter']['hostids'] && !array_key_exists($host['hostid'], $hosts)) {
					continue;
				}

				if (!array_key_exists($host['hostid'], $hosts)) {
					$hosts[$host['hostid']] = $host['name'];
				}

				$problems_by_host[$host['hostid']][] = $problem;
			}
		}

		natcasesort($hosts);
		$parts = [];

		foreach ($hosts as $hostid => $name) {
			$parts[] = [
				'id' => (string) $hostid,
				'name' => $name,
				'report' => ReportBuilder::subset($report, $problems_by_host[$hostid] ?? [], 'Host: '.$name)
			];
		}

		return $parts;
	}
}
