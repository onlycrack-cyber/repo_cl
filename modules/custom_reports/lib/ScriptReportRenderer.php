<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * Renders a report of an external script (ScriptReport) into HTML (e-mail, PDF, the "Scripts" tab), CSV and short
 * text (Telegram). Inline styles only: the HTML goes to e-mail clients.
 */
class ScriptReportRenderer {

	private const CELL = 'padding:4px 8px;border:1px solid #dfe4e7;text-align:left;vertical-align:top;';
	private const HEAD = 'padding:4px 8px;border:1px solid #dfe4e7;text-align:left;background:#f4f4f4;';

	private const STATUS_COLORS = [
		'ok' => '429e47', 'info' => '4796c4', 'warning' => 'e8a72e', 'problem' => 'e45959', 'error' => 'e45959'
	];

	private const STATUS_ICONS = [
		'ok' => "\u{2705}", 'info' => "\u{2139}\u{FE0F}", 'warning' => "\u{26A0}\u{FE0F}", 'problem' => "\u{1F534}",
		'error' => "\u{274C}"
	];

	// Rows of the first table in the short summary.
	private const TEXT_ROWS = 30;

	public static function statusLabel(string $status): string {
		$labels = [
			'ok' => I18n::t('OK'),
			'info' => I18n::t('Information'),
			'warning' => I18n::t('Warning'),
			'problem' => I18n::t('Problem'),
			'error' => I18n::t('Error')
		];

		return $labels[$status] ?? '';
	}

	public static function subject(array $report, string $title): string {
		if ($report['subject'] !== '') {
			return $title.': '.$report['subject'];
		}

		return I18n::n('%1$s: %2$s record', '%1$s: %2$s records', (int) $report['total'], $title, $report['total']);
	}

	/**
	 * "Script: disk_usage.sh /data; run time: 0.4 s".
	 */
	public static function sourceText(array $report): string {
		$script = trim($report['script'].' '.implode(' ', array_map([self::class, 'quoteParam'], $report['params'])));

		return I18n::s('Script: %1$s; run time: %2$s s', $script, $report['duration']);
	}

	private static function quoteParam(string $param): string {
		return preg_match('/[\s"\']/', $param) ? '"'.str_replace('"', '\"', $param).'"' : $param;
	}

	/**
	 * The report without the document wrapper: used in e-mails, PDF and on the "Scripts" tab.
	 */
	public static function renderSection(array $report): string {
		$html = '<div style="color:#768d99;margin-bottom:10px">'.self::e(I18n::t('Generated').': '.
			date('Y-m-d H:i:s', $report['generated'])).'<br>'.self::e(self::sourceText($report)).'</div>';

		if ($report['status'] !== '') {
			$html .= '<div style="margin-bottom:8px"><span style="display:inline-block;padding:2px 8px;color:#fff;'.
				'background:#'.self::STATUS_COLORS[$report['status']].'">'.self::e(self::statusLabel($report['status'])).
				'</span></div>';
		}

		if ($report['summary'] !== '') {
			$html .= '<div style="margin-bottom:12px;white-space:pre-wrap">'.self::e($report['summary']).'</div>';
		}

		if ($report['kpi']) {
			$html .= '<table style="margin-bottom:12px;border-collapse:collapse"><tr>';

			foreach ($report['kpi'] as $kpi) {
				$background = $kpi['color'] !== '' ? '#'.$kpi['color'] : '#f4f4f4';
				$html .= '<td style="'.self::CELL.'background:'.$background.';text-align:center;min-width:90px">'.
					'<div style="font-size:22px;font-weight:bold">'.self::e($kpi['value']).'</div>'.
					self::e($kpi['label']).'</td>';
			}

			$html .= '</tr></table>';
		}

		foreach ($report['tables'] as $table) {
			if ($table['title'] !== '') {
				// A div, not h3: the frontend styles headings (the "Scripts" tab shows the same HTML).
				$html .= '<div style="margin:10px 0 4px 0;font-size:15px;font-weight:bold">'.self::e($table['title']).'</div>';
			}

			if (!$table['rows']) {
				$html .= '<p style="color:#768d99">'.self::e(I18n::t('No data found')).'</p>';
				continue;
			}

			$html .= '<table style="width:100%;margin-bottom:12px;border-collapse:collapse"><tr>';

			foreach ($table['columns'] as $column) {
				$html .= '<th style="'.self::HEAD.'">'.self::e($column).'</th>';
			}

			$html .= '</tr>';

			foreach ($table['rows'] as $row) {
				$html .= '<tr>';

				foreach ($row as $cell) {
					$html .= '<td style="'.self::CELL.($cell['color'] !== '' ? 'background:#'.$cell['color'].';' : '').
						(self::isNumber($cell['value']) ? 'text-align:right;white-space:nowrap;' : '').'">'.
						self::e($cell['value']).'</td>';
				}

				$html .= '</tr>';
			}

			$html .= '</table>';

			foreach ($table['notes'] ?? [] as $note) {
				$html .= '<p style="color:#b07d1a;margin:-6px 0 10px 0">'.self::e($note).'</p>';
			}

			if ($table['total'] > count($table['rows'])) {
				$html .= '<p style="color:#e45959">'.self::e(I18n::s('Only the first %1$s of %2$s rows are shown '.
					'("script_max_rows").', count($table['rows']), $table['total'])).'</p>';
			}
		}

		if ($report['text'] !== '') {
			$html .= '<pre style="font-family:Consolas,Menlo,monospace;font-size:12px;background:#f4f4f4;padding:8px;'.
				'white-space:pre-wrap;border:1px solid #dfe4e7">'.self::e($report['text']).'</pre>';
		}

		if (!$report['tables'] && $report['text'] === '' && !$report['kpi'] && $report['summary'] === '') {
			$html .= '<p>'.self::e(I18n::t('The script returned no data.')).'</p>';
		}

		return $html;
	}

	/**
	 * @param array $options  'print' => bool: open the print dialog on load.
	 */
	public static function renderHtml(array $report, string $title, array $options = []): string {
		$title = $report['title'] !== '' ? $report['title'] : $title;

		return '<!DOCTYPE html><html lang="'.I18n::lang().'"><head><meta charset="UTF-8"><title>'.self::e($title).
			'</title><style>@page{size:A4 landscape;margin:12mm}@media print{tr{page-break-inside:avoid}}'.
			'body{font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#1f2c33;margin:16px}'.
			'table{border-collapse:collapse}</style></head><body><h2 style="margin:0 0 4px 0">'.self::e($title).'</h2>'.
			self::renderSection($report).
			(!empty($options['print']) ? '<script>window.addEventListener("load",function(){window.print();});</script>' : '').
			'</body></html>';
	}

	/**
	 * Short text (Telegram, e-mail body of the "summary" format).
	 *
	 * @param int  $max_length  Maximum length in characters (0 - unlimited).
	 * @param bool $html        Telegram HTML markup.
	 */
	public static function renderText(array $report, string $title, int $max_length = 0, bool $html = false): string {
		$b = static function (string $text) use ($html): string {
			return $html ? '<b>'.$text.'</b>' : $text;
		};
		$e = static function (string $text) use ($html): string {
			return $html ? htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') : $text;
		};

		$title = $report['title'] !== '' ? $report['title'] : $title;
		$lines = [$b("\u{1F4CB} ".$e($title)), $e(I18n::t('Generated').': '.date('Y-m-d H:i', $report['generated']))];

		if ($report['status'] !== '') {
			$lines[] = self::STATUS_ICONS[$report['status']].' '.$b($e(self::statusLabel($report['status'])));
		}

		if ($report['summary'] !== '') {
			$lines[] = '';
			$lines[] = $e($report['summary']);
		}

		if ($report['kpi']) {
			$lines[] = '';

			foreach ($report['kpi'] as $kpi) {
				$lines[] = $e($kpi['label'].': ').$b($e($kpi['value']));
			}
		}

		$items = [];

		foreach ($report['tables'] as $table) {
			if (!$table['rows']) {
				continue;
			}

			$items[] = "\n".$b($e(($table['title'] !== '' ? $table['title'] : I18n::t('Data')).' ('.
				I18n::n('%1$s row', '%1$s rows', (int) $table['total']).')')).':';
			$items[] = "\n".$e(implode(' | ', $table['columns']));

			foreach (array_slice($table['rows'], 0, self::TEXT_ROWS) as $row) {
				// No colors in text: highlighted cells get a mark.
				$items[] = "\n".$e(implode(' | ', array_map(static function (array $cell): string {
					return $cell['color'] !== '' && $cell['color'] !== '59db8f' ? "\u{2757}".$cell['value'] : $cell['value'];
				}, $row)));
			}

			if ($table['total'] > self::TEXT_ROWS) {
				$items[] = "\n".$e(I18n::s('... and %1$s more', $table['total'] - self::TEXT_ROWS));
			}

			foreach ($table['notes'] ?? [] as $note) {
				$items[] = "\n\u{26A0}\u{FE0F} ".$e($note);
			}
		}

		if ($report['text'] !== '') {
			$items[] = "\n";

			foreach (preg_split('/\r\n|\r|\n/', $report['text']) as $line) {
				$items[] = "\n".$e($line);
			}
		}

		$text = implode("\n", $lines);

		foreach ($items as $index => $item) {
			$tail = "\n".$e(I18n::t('(the full report is in the attachment or the HTML version)'));

			if ($max_length > 0 && mb_strlen($text.$item, 'UTF-8') + mb_strlen($tail, 'UTF-8') > $max_length
					&& $index < count($items) - 1) {
				$text .= $tail;
				break;
			}

			$text .= $item;
		}

		if ($max_length > 0 && mb_strlen($text, 'UTF-8') > $max_length) {
			if ($html) {
				// Cutting HTML could break a tag or an entity: cut the plain text and escape it again.
				return htmlspecialchars(self::renderText($report, $title, $max_length, false),
					ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'
				);
			}

			$text = mb_substr($text, 0, $max_length - 3, 'UTF-8').'...';
		}

		return $text;
	}

	public static function renderCsv(array $report): string {
		$fh = fopen('php://temp', 'r+');
		fwrite($fh, "\xEF\xBB\xBF");
		$put = static function (array $row) use ($fh): void {
			fputcsv($fh, array_map([ReportRenderer::class, 'csvCell'], $row), ',', '"', '');
		};

		$several = count($report['tables']) > 1 || $report['kpi'];

		if ($report['kpi']) {
			foreach ($report['kpi'] as $kpi) {
				$put([$kpi['label'], $kpi['value']]);
			}

			$put([]);
		}

		foreach ($report['tables'] as $index => $table) {
			if ($several && $index > 0) {
				$put([]);
			}

			if ($several && $table['title'] !== '') {
				$put([$table['title']]);
			}

			$put($table['columns']);

			foreach ($table['rows'] as $row) {
				$put(array_column($row, 'value'));
			}
		}

		if (!$report['tables'] && $report['text'] !== '') {
			foreach (preg_split('/\r\n|\r|\n/', $report['text']) as $line) {
				$put([$line]);
			}
		}

		rewind($fh);
		$csv = stream_get_contents($fh);
		fclose($fh);

		return $csv;
	}

	/**
	 * Numbers are right-aligned: "1249.63", "-5", "1 234,5", "93%".
	 */
	private static function isNumber(string $value): bool {
		return (bool) preg_match('/^[-+]?\d[\d\s]*([.,]\d+)?%?$/u', trim($value));
	}

	private static function e(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
