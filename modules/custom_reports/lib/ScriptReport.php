<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

use RuntimeException;

/**
 * Reports from external scripts: runs an executable of "external_scripts_path" (ExternalScripts of Zabbix) and turns
 * its output into a report that ScriptReportRenderer shows and Dispatcher sends like a problem report.
 *
 * Output of the script (stdout, UTF-8), see README "Reports from external scripts":
 * - JSON: a list of objects (one table), or an object with "title", "summary", "status", "kpi", "tables"
 *   (or "columns" + "rows"), "text";
 * - CSV: the first line is the header, the delimiter (",", ";", tab, "|") is detected;
 * - text: shown as it is.
 * Exit code other than 0 is an error (stderr is shown).
 */
class ScriptReport {

	public const SOURCE = 'script';

	public const FORMAT_AUTO = 'auto';
	public const FORMAT_JSON = 'json';
	public const FORMAT_CSV = 'csv';
	public const FORMAT_TEXT = 'text';

	public const FORMATS = [self::FORMAT_AUTO, self::FORMAT_JSON, self::FORMAT_CSV, self::FORMAT_TEXT];

	// Status of the data reported by the script ("status" in JSON).
	public const STATUSES = ['ok', 'info', 'warning', 'problem', 'error'];

	public const MAX_PARAMS = 20;
	public const MAX_PARAM_LENGTH = 1024;

	/**
	 * Names of executable files in the scripts directory.
	 *
	 * @return string[]
	 */
	public static function listScripts(array $config): array {
		$dir = (string) $config['external_scripts_path'];
		$names = [];

		if ($dir === '' || !is_dir($dir) || !is_readable($dir)) {
			return [];
		}

		foreach ((array) scandir($dir) as $name) {
			if (self::isValidName((string) $name) && is_file($dir.'/'.$name) && is_executable($dir.'/'.$name)) {
				$names[] = $name;
			}
		}

		natcasesort($names);

		return array_values($names);
	}

	/**
	 * A plain file name: no directories, no hidden files.
	 */
	public static function isValidName(string $name): bool {
		return (bool) preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]{0,254}$/', $name);
	}

	/**
	 * @throws RuntimeException
	 */
	public static function getPath(array $config, string $name): string {
		$dir = rtrim((string) $config['external_scripts_path'], '/');

		if (!self::isValidName($name)) {
			throw new RuntimeException(I18n::s('Incorrect script name "%1$s".', $name));
		}

		$path = $dir.'/'.$name;

		if (!is_file($path)) {
			throw new RuntimeException(I18n::s('Script "%1$s" not found in %2$s.', $name, $dir));
		}

		if (!is_executable($path)) {
			throw new RuntimeException(I18n::s('Script "%1$s" is not executable by user "%2$s".', $name,
				self::currentUser()
			));
		}

		return $path;
	}

	/**
	 * Parameters: one per line in the forms; empty lines are dropped.
	 *
	 * @param mixed $params  string or list
	 *
	 * @return string[]
	 */
	public static function normalizeParams($params): array {
		if (!is_array($params)) {
			$params = preg_split('/\r\n|\r|\n/', (string) $params);
		}

		$result = [];

		foreach ($params as $param) {
			$param = trim((string) $param);

			if ($param !== '') {
				$result[] = mb_substr($param, 0, self::MAX_PARAM_LENGTH, 'UTF-8');
			}
		}

		return array_slice($result, 0, self::MAX_PARAMS);
	}

	/**
	 * Macros in parameters, also passed as environment variables CR_<NAME>.
	 *
	 * @param int    $period  Length of the report period in seconds (24 hours for daily, 7 days for weekly schedules).
	 * @param string $name    Schedule name.
	 */
	public static function macros(int $now, int $period, string $name = ''): array {
		return [
			'{DATE}' => date('Y-m-d', $now),
			'{TIME}' => date('H:i', $now),
			'{PERIOD_FROM}' => (string) ($now - $period),
			'{PERIOD_TILL}' => (string) $now,
			'{PERIOD_FROM_DATE}' => date('Y-m-d H:i', $now - $period),
			'{PERIOD_TILL_DATE}' => date('Y-m-d H:i', $now),
			'{SCHEDULE}' => $name
		];
	}

	/**
	 * Runs a script and parses its output.
	 *
	 * @throws RuntimeException  The script cannot be run, fails, times out or its output cannot be parsed.
	 */
	public static function build(array $config, string $name, array $params, string $format, array $macros): array {
		$path = self::getPath($config, $name);
		$args = array_map(static function (string $param) use ($macros): string {
			return strtr($param, $macros);
		}, $params);

		$env = getenv();
		$env = is_array($env) ? $env : [];

		foreach ($macros as $macro => $value) {
			$env['CR_'.trim($macro, '{}')] = $value;
		}

		$env['CR_LANGUAGE'] = I18n::lang();

		// The same number format in the web interface and in cron: the frontend switches the locale of the process
		// to the language of the user (LC_ALL), and "%.1f" in awk/printf would print "1,5" instead of "1.5".
		unset($env['LC_ALL']);
		$env['LC_NUMERIC'] = 'C';

		$started = microtime(true);
		[$code, $stdout, $stderr] = ProcessRunner::exec(array_merge([$path], $args), max(1, (int) $config['script_timeout']),
			$env, (int) $config['script_max_output']
		);
		$duration = round(microtime(true) - $started, 2);
		$stderr = trim(self::toUtf8($stderr));

		if ($code != 0) {
			throw new RuntimeException(I18n::s('Script "%1$s" exited with code %2$s: %3$s', $name, $code,
				mb_substr($stderr !== '' ? $stderr : trim(self::toUtf8($stdout)), 0, 1000, 'UTF-8')
			));
		}

		$report = self::parse($stdout, $format, max(1, (int) $config['script_max_rows']));
		$report['script'] = $name;
		$report['params'] = $args;
		$report['duration'] = $duration;
		$report['stderr'] = mb_substr($stderr, 0, 2000, 'UTF-8');

		return $report;
	}

	/**
	 * @throws RuntimeException  JSON was requested but the output is not JSON.
	 */
	public static function parse(string $output, string $format = self::FORMAT_AUTO, int $max_rows = 5000): array {
		$output = self::toUtf8($output);
		$output = preg_replace('/^\xEF\xBB\xBF/', '', $output);
		$trimmed = trim($output);

		if ($format === self::FORMAT_AUTO) {
			$format = self::detectFormat($trimmed);
		}

		$report = self::emptyReport();

		switch ($format) {
			case self::FORMAT_JSON:
				$data = json_decode($trimmed, true);

				if (!is_array($data)) {
					throw new RuntimeException(I18n::s('The script output is not valid JSON: %1$s', json_last_error_msg()));
				}

				$report = self::fromJson($data, $report, $max_rows);
				break;

			case self::FORMAT_CSV:
				if ($trimmed !== '') {
					$report['tables'][] = self::fromCsv($trimmed, $max_rows);
				}
				break;

			default:
				$report['text'] = rtrim($output);
		}

		$report['format'] = $format;

		return self::finalize($report);
	}

	public static function emptyReport(): array {
		return [
			'source' => self::SOURCE,
			'generated' => time(),
			'title' => '',
			'subject' => '',
			'summary' => '',
			'status' => '',
			'kpi' => [],
			'tables' => [],
			'text' => '',
			'total' => 0,
			'truncated' => false,
			// Keys used by ReportSplitter / ReportService for any report.
			'analytics' => null,
			'script' => '',
			'params' => [],
			'duration' => 0,
			'stderr' => '',
			'format' => self::FORMAT_TEXT
		];
	}

	private static function detectFormat(string $output): string {
		if ($output !== '' && ($output[0] === '{' || $output[0] === '[')) {
			json_decode($output);

			if (json_last_error() === JSON_ERROR_NONE) {
				return self::FORMAT_JSON;
			}
		}

		if (self::detectDelimiter($output) !== null) {
			return self::FORMAT_CSV;
		}

		return self::FORMAT_TEXT;
	}

	/**
	 * A delimiter that gives the same number (more than one) of fields in the first records; quoted values may
	 * contain line breaks, so records are read as CSV, not as lines.
	 */
	private static function detectDelimiter(string $output): ?string {
		foreach ([',', ';', "\t", '|'] as $delimiter) {
			$fh = fopen('php://temp', 'r+');
			fwrite($fh, $output);
			rewind($fh);
			$counts = [];

			while (count($counts) < 5 && ($row = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
				if ($row !== [null]) {
					$counts[] = count($row);
				}
			}

			fclose($fh);

			if (count($counts) >= 2 && $counts[0] > 1 && count(array_unique($counts)) == 1) {
				return $delimiter;
			}
		}

		return null;
	}

	private static function fromCsv(string $output, int $max_rows): array {
		$delimiter = self::detectDelimiter($output) ?? ',';
		$fh = fopen('php://temp', 'r+');
		fwrite($fh, $output);
		rewind($fh);

		$columns = null;
		$rows = [];
		$total = 0;

		while (($row = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
			if ($row === [null]) {
				continue;
			}

			if ($columns === null) {
				$columns = array_map('trim', array_map('strval', $row));
				continue;
			}

			$total++;

			if (count($rows) < $max_rows) {
				$rows[] = array_map(static function ($value): array {
					return ['value' => (string) $value, 'color' => ''];
				}, $row);
			}
		}

		fclose($fh);

		return ['title' => '', 'columns' => $columns ?? [], 'rows' => $rows, 'total' => $total];
	}

	private static function fromJson(array $data, array $report, int $max_rows): array {
		// A list: rows of one table.
		if (self::isList($data)) {
			$report['tables'][] = self::makeTable(['rows' => $data], $max_rows);

			return $report;
		}

		foreach (['title', 'subject', 'summary', 'text'] as $key) {
			if (isset($data[$key]) && is_scalar($data[$key])) {
				$report[$key] = trim((string) $data[$key]);
			}
		}

		if (isset($data['status']) && is_scalar($data['status'])) {
			$status = strtolower(trim((string) $data['status']));
			$report['status'] = in_array($status, self::STATUSES, true) ? $status : '';
		}

		if (isset($data['kpi']) && is_array($data['kpi'])) {
			foreach ($data['kpi'] as $label => $kpi) {
				// {"Hosts": 42} or [{"label": "Hosts", "value": 42, "color": "e45959"}].
				$kpi = is_array($kpi) ? $kpi : ['label' => $label, 'value' => $kpi];

				$report['kpi'][] = [
					'label' => self::scalar($kpi['label'] ?? ''),
					'value' => self::scalar($kpi['value'] ?? ''),
					'color' => self::color($kpi['color'] ?? '')
				];
			}
		}

		if (isset($data['tables']) && is_array($data['tables'])) {
			foreach ($data['tables'] as $table) {
				if (is_array($table)) {
					$report['tables'][] = self::makeTable($table, $max_rows);
				}
			}
		}
		else {
			foreach (['rows', 'data', 'items'] as $key) {
				if (isset($data[$key]) && is_array($data[$key])) {
					$report['tables'][] = self::makeTable(['columns' => $data['columns'] ?? null, 'rows' => $data[$key]],
						$max_rows
					);
					break;
				}
			}
		}

		return $report;
	}

	/**
	 * Table from {"title", "columns", "rows"}: rows are objects (keys are columns) or lists (in the order of columns,
	 * the first row is the header if "columns" is not given). A cell is a value or {"value", "color"}.
	 */
	private static function makeTable(array $table, int $max_rows): array {
		$rows = is_array($table['rows'] ?? null) ? array_values($table['rows']) : [];
		$columns = is_array($table['columns'] ?? null) ? array_map([self::class, 'scalar'], $table['columns']) : null;

		if ($columns === null) {
			$columns = [];

			foreach ($rows as $row) {
				if (is_array($row) && !self::isList($row) && !self::isCell($row)) {
					foreach (array_keys($row) as $key) {
						if (!in_array((string) $key, $columns, true)) {
							$columns[] = (string) $key;
						}
					}
				}
			}

			// Lists without "columns": the first row is the header.
			if (!$columns && $rows && is_array($rows[0]) && self::isList($rows[0])) {
				$columns = array_map([self::class, 'scalar'], array_shift($rows));
			}
		}

		$result = [];

		foreach (array_slice($rows, 0, $max_rows) as $row) {
			if (!is_array($row) || self::isCell($row)) {
				$row = [$row];
			}

			$cells = [];

			if (self::isList($row)) {
				foreach ($row as $cell) {
					$cells[] = self::cell($cell);
				}
			}
			else {
				foreach ($columns as $column) {
					$cells[] = self::cell($row[$column] ?? '');
				}
			}

			$result[] = $cells;
		}

		if (!$columns && $result) {
			$columns = [I18n::t('Value')];
		}

		return [
			'title' => self::scalar($table['title'] ?? ''),
			'columns' => $columns,
			'rows' => $result,
			'total' => count($rows)
		];
	}

	/**
	 * array_is_list() of PHP 8.1 (Zabbix 7.0 also runs on PHP 8.0).
	 */
	private static function isList(array $value): bool {
		return $value === [] || array_keys($value) === range(0, count($value) - 1);
	}

	private static function isCell(array $value): bool {
		return array_key_exists('value', $value) && count(array_diff(array_keys($value), ['value', 'color'])) == 0;
	}

	private static function cell($cell): array {
		if (is_array($cell) && self::isCell($cell)) {
			return ['value' => self::scalar($cell['value']), 'color' => self::color($cell['color'] ?? '')];
		}

		return ['value' => self::scalar($cell), 'color' => ''];
	}

	private static function scalar($value): string {
		if ($value === null) {
			return '';
		}

		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		if (is_scalar($value)) {
			return (string) $value;
		}

		return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * "e45959", "#e45959" or a severity: "disaster", "high", ..., 0-5.
	 */
	private static function color($color): string {
		$color = strtolower(ltrim(trim(self::scalar($color)), '#'));

		if (preg_match('/^[0-9a-f]{6}$/', $color)) {
			return $color;
		}

		// Status words: green for "ok", red for "problem"/"error".
		$statuses = ['ok' => '59db8f', 'problem' => 'e45959', 'error' => 'e45959'];

		if (array_key_exists($color, $statuses)) {
			return $statuses[$color];
		}

		$severity = Severity::fromText($color);

		return $severity !== null ? strtolower(Severity::COLORS[$severity]) : '';
	}

	private static function finalize(array $report): array {
		$rows = 0;

		foreach ($report['tables'] as $table) {
			$rows += $table['total'];
			$report['truncated'] = $report['truncated'] || $table['total'] > count($table['rows']);
		}

		// Number of records for the subject, the history and "Do not send empty reports".
		if ($report['tables']) {
			$report['total'] = $rows;
		}
		elseif ($report['text'] !== '') {
			$report['total'] = count(array_filter(preg_split('/\r\n|\r|\n/', $report['text']), static function ($line) {
				return trim($line) !== '';
			}));
		}
		else {
			$report['total'] = count($report['kpi']);
		}

		return $report;
	}

	private static function toUtf8(string $text): string {
		return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
	}

	public static function currentUser(): string {
		if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
			$user = posix_getpwuid(posix_geteuid());

			if (is_array($user)) {
				return $user['name'];
			}
		}

		return (string) get_current_user();
	}
}
