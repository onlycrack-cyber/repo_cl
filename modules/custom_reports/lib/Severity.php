<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * Trigger severities (TRIGGER_SEVERITY_* in the frontend) with default names and colors.
 * Real names and colors are taken from "settings.get" when available.
 */
class Severity {

	public const NOT_CLASSIFIED = 0;
	public const INFORMATION = 1;
	public const WARNING = 2;
	public const AVERAGE = 3;
	public const HIGH = 4;
	public const DISASTER = 5;

	public const NAMES = [
		self::NOT_CLASSIFIED => 'Not classified',
		self::INFORMATION => 'Information',
		self::WARNING => 'Warning',
		self::AVERAGE => 'Average',
		self::HIGH => 'High',
		self::DISASTER => 'Disaster'
	];

	public const COLORS = [
		self::NOT_CLASSIFIED => '97AAB3',
		self::INFORMATION => '7499FF',
		self::WARNING => 'FFC859',
		self::AVERAGE => 'FFA059',
		self::HIGH => 'E97659',
		self::DISASTER => 'E45959'
	];

	public const ICONS = [
		self::NOT_CLASSIFIED => "\u{26AA}",
		self::INFORMATION => "\u{1F535}",
		self::WARNING => "\u{1F7E1}",
		self::AVERAGE => "\u{1F7E0}",
		self::HIGH => "\u{1F534}",
		self::DISASTER => "\u{1F7E5}"
	];

	/**
	 * @return int[]  Severities from the highest to the lowest.
	 */
	public static function descending(): array {
		return [self::DISASTER, self::HIGH, self::AVERAGE, self::WARNING, self::INFORMATION, self::NOT_CLASSIFIED];
	}

	/**
	 * @param mixed $severities
	 *
	 * @return int[]
	 */
	public static function sanitize($severities): array {
		if (!is_array($severities)) {
			return [];
		}

		$result = [];

		foreach ($severities as $severity) {
			if (is_numeric($severity) && array_key_exists((int) $severity, self::NAMES)) {
				$result[(int) $severity] = (int) $severity;
			}
		}

		ksort($result);

		return array_values($result);
	}

	/**
	 * Severity from a number (0-5) or a name in English or Russian ("disaster", "Чрезвычайная", ...).
	 */
	public static function fromText(string $text): ?int {
		$text = mb_strtolower(trim($text), 'UTF-8');

		if (preg_match('/^[0-5]$/', $text)) {
			return (int) $text;
		}

		$names = [
			'not classified' => self::NOT_CLASSIFIED, 'не классифицировано' => self::NOT_CLASSIFIED,
			'information' => self::INFORMATION, 'info' => self::INFORMATION, 'информация' => self::INFORMATION,
			'warning' => self::WARNING, 'предупреждение' => self::WARNING,
			'average' => self::AVERAGE, 'средняя' => self::AVERAGE,
			'high' => self::HIGH, 'высокая' => self::HIGH,
			'disaster' => self::DISASTER, 'чрезвычайная' => self::DISASTER
		];

		return $names[$text] ?? null;
	}
}
