<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * Problem age filter.
 */
class Period {

	private const DAY = 86400;

	public const ALL = 'all';
	public const LAST_24H = 'last_24h';
	public const LAST_7D = 'last_7d';
	public const LAST_30D = 'last_30d';
	public const OLDER_1D = 'older_1d';
	public const OLDER_7D = 'older_7d';

	public const LABELS = [
		self::ALL => 'All active',
		self::LAST_24H => 'Last 24 hours',
		self::LAST_7D => 'Last 7 days',
		self::LAST_30D => 'Last 30 days',
		self::OLDER_1D => 'Older than 1 day',
		self::OLDER_7D => 'Older than 7 days'
	];

	public static function isValid(string $period): bool {
		return array_key_exists($period, self::LABELS);
	}

	/**
	 * @return array  [time_from|null, time_till|null] for problem.get.
	 */
	public static function toRange(string $period, int $now): array {
		switch ($period) {
			case self::LAST_24H:
				return [$now - self::DAY, null];

			case self::LAST_7D:
				return [$now - 7 * self::DAY, null];

			case self::LAST_30D:
				return [$now - 30 * self::DAY, null];

			case self::OLDER_1D:
				return [null, $now - self::DAY];

			case self::OLDER_7D:
				return [null, $now - 7 * self::DAY];

			default:
				return [null, null];
		}
	}
}

