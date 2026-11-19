<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * Filter by problem tags, the same as in Monitoring -> Problems: a list of [tag, operator, value] and the evaluation
 * type ("And/Or": rows with the same tag are joined with OR, different tags with AND; "Or": any row).
 */
class TagFilter {

	// TAG_EVAL_TYPE_* of Zabbix.
	public const EVAL_AND_OR = 0;
	public const EVAL_OR = 2;

	// TAG_OPERATOR_* of Zabbix.
	public const OPERATOR_LIKE = 0;
	public const OPERATOR_EQUAL = 1;
	public const OPERATOR_NOT_LIKE = 2;
	public const OPERATOR_NOT_EQUAL = 3;
	public const OPERATOR_EXISTS = 4;
	public const OPERATOR_NOT_EXISTS = 5;

	public const OPERATORS = [
		self::OPERATOR_LIKE => 'contains',
		self::OPERATOR_EQUAL => 'equals',
		self::OPERATOR_NOT_LIKE => 'does not contain',
		self::OPERATOR_NOT_EQUAL => 'does not equal',
		self::OPERATOR_EXISTS => 'exists',
		self::OPERATOR_NOT_EXISTS => 'does not exist'
	];

	private const MAX_ROWS = 50;

	/**
	 * Rows with a tag name; the value is dropped for "exists" / "does not exist".
	 *
	 * @param mixed $tags
	 *
	 * @return array  list of ['tag' => string, 'operator' => int, 'value' => string]
	 */
	public static function normalize($tags): array {
		if (!is_array($tags)) {
			return [];
		}

		$result = [];

		foreach ($tags as $row) {
			if (!is_array($row)) {
				continue;
			}

			$tag = trim((string) ($row['tag'] ?? ''));
			$operator = (int) ($row['operator'] ?? self::OPERATOR_LIKE);

			if ($tag === '' || !array_key_exists($operator, self::OPERATORS)) {
				continue;
			}

			$value = in_array($operator, [self::OPERATOR_EXISTS, self::OPERATOR_NOT_EXISTS], true)
				? ''
				: mb_substr((string) ($row['value'] ?? ''), 0, 255, 'UTF-8');

			$result[] = ['tag' => mb_substr($tag, 0, 255, 'UTF-8'), 'operator' => $operator, 'value' => $value];

			if (count($result) >= self::MAX_ROWS) {
				break;
			}
		}

		return $result;
	}

	/**
	 * @param mixed $evaltype
	 */
	public static function normalizeEvaltype($evaltype): int {
		return (int) $evaltype == self::EVAL_OR ? self::EVAL_OR : self::EVAL_AND_OR;
	}

	/**
	 * Parameters of problem.get / event.get; [] without tags.
	 */
	public static function apiParams(array $filter): array {
		$tags = $filter['tags'] ?? [];

		return $tags ? ['tags' => $tags, 'evaltype' => self::normalizeEvaltype($filter['evaltype'] ?? 0)] : [];
	}

	/**
	 * For the report header: "service equals db, team exists (or)".
	 */
	public static function describe(array $tags, int $evaltype): string {
		$parts = [];

		foreach ($tags as $row) {
			$operator = I18n::t(self::OPERATORS[$row['operator']] ?? 'contains');
			$parts[] = in_array($row['operator'], [self::OPERATOR_EXISTS, self::OPERATOR_NOT_EXISTS], true)
				? $row['tag'].' '.$operator
				: $row['tag'].' '.$operator.' "'.$row['value'].'"';
		}

		$text = implode(', ', $parts);

		return $evaltype == self::EVAL_OR && count($tags) > 1 ? $text.' ('.I18n::t('any of them').')' : $text;
	}
}
