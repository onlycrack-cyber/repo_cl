<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * Renders a report (see ReportBuilder) into CSV, HTML and short text.
 */
class ReportRenderer {

	public const FORMAT_SUMMARY = 'summary';
	public const FORMAT_HTML = 'html';
	public const FORMAT_CSV = 'csv';

	public const FORMATS = [self::FORMAT_SUMMARY, self::FORMAT_HTML, self::FORMAT_CSV];

	public static function formatDuration(int $seconds): string {
		$seconds = max(0, $seconds);
		$days = intdiv($seconds, 86400);
		$hours = intdiv($seconds % 86400, 3600);
		$minutes = intdiv($seconds % 3600, 60);
		$parts = [];

		if ($days > 0) {
			$parts[] = $days.'d';
		}

		if ($hours > 0) {
			$parts[] = $hours.'h';
		}

		if ($minutes > 0 || !$parts) {
			$parts[] = $minutes.'m';
		}

		return implode(' ', $parts);
	}

	public static function hostNames(array $problem): string {
		return $problem['hosts'] ? implode(', ', array_column($problem['hosts'], 'name')) : '-';
	}

	public static function filterDescription(array $report): string {
		$info = $report['filter_info'];
		$parts = [];

		if (($info['scope'] ?? '') !== '') {
			$parts[] = $info['scope'];
		}

		$parts[] = 'Period: '.(Period::LABELS[$info['period']] ?? $info['period']);
		$parts[] = 'Severity: '.($info['severities'] ? implode(', ', $info['severities']) : 'all');

		if (($info['problem_name'] ?? '') !== '') {
			$parts[] = 'Problem: *'.$info['problem_name'].'*';
		}

		if (($info['acknowledged'] ?? ReportBuilder::ACK_ALL) != ReportBuilder::ACK_ALL) {
			$parts[] = $info['acknowledged'] == ReportBuilder::ACK_YES ? 'Acknowledged only' : 'Unacknowledged only';
		}

		if ($info['groups']) {
			$parts[] = 'Host groups: '.implode(', ', $info['groups']);
		}

		if ($info['hosts']) {
			$parts[] = 'Hosts: '.implode(', ', $info['hosts']);
		}

		return implode('; ', $parts);
	}

	public static function fileName(string $title, string $extension, int $time): string {
		$base = preg_replace('/[^A-Za-z0-9_-]+/', '_', $title);
		$base = trim((string) $base, '_');

		return ($base !== '' ? $base : 'problem_report').'_'.date('Ymd_Hi', $time).'.'.$extension;
	}

	public static function renderCsv(array $report): string {
		$fh = fopen('php://temp', 'r+');

		// UTF-8 BOM: lets spreadsheet applications detect the encoding of non-latin names.
		fwrite($fh, "\xEF\xBB\xBF");
		fputcsv($fh, ['Time', 'Duration', 'Severity', 'Host', 'Problem', 'Acknowledged', 'Event ID'], ',', '"', '');

		foreach ($report['problems'] as $problem) {
			fputcsv($fh, [
				date('Y-m-d H:i:s', $problem['clock']),
				self::formatDuration($problem['age']),
				$report['severity_meta'][$problem['severity']]['name'],
				self::hostNames($problem),
				$problem['name'],
				$problem['acknowledged'] ? 'Yes' : 'No',
				$problem['eventid']
			], ',', '"', '');
		}

		rewind($fh);
		$csv = stream_get_contents($fh);
		fclose($fh);

		return $csv;
	}

	/**
	 * Self-contained HTML document with inline styles (suitable for e-mail clients).
	 *
	 * @param array  $options
	 *        bool   $options['print']         Open the print dialog on load ("Save as PDF").
	 *        string $options['frontend_url']  Link to the Zabbix frontend.
	 */
	public static function renderHtml(array $report, string $title, array $options = []): string {
		$meta = $report['severity_meta'];
		$frontend_url = rtrim((string) ($options['frontend_url'] ?? ''), '/');
		$cell = 'padding:4px 8px;border:1px solid #dfe4e7;text-align:left;vertical-align:top;';

		$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>'.self::e($title).'</title>'.
			'<style>@media print{.no-print{display:none}}body{font-family:Arial,Helvetica,sans-serif;'.
			'font-size:13px;color:#1f2c33;margin:16px}table{border-collapse:collapse}</style></head><body>';

		$html .= '<h2 style="margin:0 0 4px 0">'.self::e($title).'</h2>';
		$html .= '<div style="color:#768d99;margin-bottom:12px">Generated: '.date('Y-m-d H:i:s', $report['generated']).
			'<br>'.self::e(self::filterDescription($report)).'</div>';

		// KPI.
		$html .= '<table style="margin-bottom:12px"><tr>';
		$html .= '<td style="'.$cell.'background:#f4f4f4;text-align:center;min-width:90px"><div style="font-size:22px;'.
			'font-weight:bold">'.$report['total'].'</div>Active problems</td>';

		foreach (Severity::descending() as $severity) {
			$html .= '<td style="'.$cell.'background:#'.self::e($meta[$severity]['color']).';text-align:center;'.
				'min-width:90px"><div style="font-size:22px;font-weight:bold">'.$report['by_severity'][$severity].
				'</div>'.self::e($meta[$severity]['name']).'</td>';
		}

		$html .= '</tr></table>';

		// Top hosts.
		if ($report['top_hosts']) {
			$html .= '<h3 style="margin:8px 0 4px 0">Top problem hosts</h3><table style="margin-bottom:12px">';

			foreach ($report['top_hosts'] as $i => $host) {
				$html .= '<tr><td style="'.$cell.'">'.($i + 1).'</td><td style="'.$cell.'">'.self::e($host['name']).
					'</td><td style="'.$cell.'text-align:right">'.$host['count'].'</td></tr>';
			}

			$html .= '</table>';
		}

		// Problems.
		$html .= '<h3 style="margin:8px 0 4px 0">Active problems</h3>';

		if ($report['problems']) {
			$html .= '<table style="width:100%"><tr>';

			foreach (['Time', 'Duration', 'Severity', 'Host', 'Problem', 'Ack'] as $header) {
				$html .= '<th style="'.$cell.'background:#f4f4f4">'.$header.'</th>';
			}

			$html .= '</tr>';

			foreach ($report['problems'] as $problem) {
				$severity = $problem['severity'];
				$name = self::e($problem['name']);

				if ($frontend_url !== '') {
					$name = '<a href="'.self::e($frontend_url.'/tr_events.php?triggerid='.$problem['triggerid'].
						'&eventid='.$problem['eventid']).'">'.$name.'</a>';
				}

				$html .= '<tr>'.
					'<td style="'.$cell.'white-space:nowrap">'.date('Y-m-d H:i:s', $problem['clock']).'</td>'.
					'<td style="'.$cell.'white-space:nowrap">'.self::formatDuration($problem['age']).'</td>'.
					'<td style="'.$cell.'background:#'.self::e($meta[$severity]['color']).'">'.
						self::e($meta[$severity]['name']).'</td>'.
					'<td style="'.$cell.'">'.self::e(self::hostNames($problem)).'</td>'.
					'<td style="'.$cell.'">'.$name.'</td>'.
					'<td style="'.$cell.'color:'.($problem['acknowledged'] ? '#429e47' : '#e45959').'">'.
						($problem['acknowledged'] ? 'Yes' : 'No').'</td>'.
					'</tr>';
			}

			$html .= '</table>';
		}
		else {
			$html .= '<p>No active problems match the filter.</p>';
		}

		if ($report['truncated']) {
			$html .= '<p style="color:#e45959">The report is limited to '.$report['total'].' problems.</p>';
		}

		if ($frontend_url !== '') {
			$html .= '<p style="color:#768d99">Zabbix: <a href="'.self::e($frontend_url).'">'.self::e($frontend_url).
				'</a></p>';
		}

		if (!empty($options['print'])) {
			$html .= '<script>window.addEventListener("load",function(){window.print();});</script>';
		}

		return $html.'</body></html>';
	}

	/**
	 * Short text summary.
	 *
	 * @param int  $max_length  Maximum length in characters (0 - unlimited). Problems that do not fit are
	 *                          replaced with "... and N more".
	 * @param bool $html        Use Telegram-compatible HTML markup (<b>, <i>).
	 */
	public static function renderText(array $report, string $title, int $max_length = 0, bool $html = false): string {
		$meta = $report['severity_meta'];
		$b = static function (string $text) use ($html): string {
			return $html ? '<b>'.$text.'</b>' : $text;
		};
		$e = static function (string $text) use ($html): string {
			return $html ? htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') : $text;
		};

		$lines = [];
		$lines[] = $b("\u{1F4CA} ".$e($title));
		$lines[] = $e('Generated: '.date('Y-m-d H:i', $report['generated']));
		$lines[] = $e(self::filterDescription($report));
		$lines[] = '';
		$lines[] = $b('Active problems: '.$report['total']);

		$counters = [];

		foreach (Severity::descending() as $severity) {
			if ($report['by_severity'][$severity] > 0) {
				$counters[] = Severity::ICONS[$severity].' '.$e($meta[$severity]['name']).': '.
					$report['by_severity'][$severity];
			}
		}

		if ($counters) {
			$lines[] = implode("\n", $counters);
		}

		if ($report['top_hosts']) {
			$lines[] = '';
			$lines[] = $b('Top hosts:');

			foreach ($report['top_hosts'] as $i => $host) {
				$lines[] = ($i + 1).'. '.$e($host['name']).' - '.$host['count'];
			}
		}

		$text = implode("\n", $lines);

		if (!$report['problems']) {
			return $text;
		}

		$text .= "\n\n".$b('Problems:');
		$total = count($report['problems']);

		foreach ($report['problems'] as $index => $problem) {
			$line = "\n".Severity::ICONS[$problem['severity']].' '.$e(self::hostNames($problem)).': '.
				$e($problem['name']).' ('.self::formatDuration($problem['age']).($problem['acknowledged'] ? ', ack' : '').
				')';

			$rest = $total - $index - 1;
			$tail = "\n... and ".($rest + 1).' more';

			if ($max_length > 0
					&& mb_strlen($text.$line, 'UTF-8') + ($rest > 0 ? mb_strlen($tail, 'UTF-8') : 0) > $max_length) {
				$text .= $tail;
				break;
			}

			$text .= $line;
		}

		if ($report['truncated'] && $max_length == 0) {
			$text .= "\n(the report is limited to ".$total.' problems)';
		}

		if ($max_length > 0 && mb_strlen($text, 'UTF-8') > $max_length) {
			$text = mb_substr(strip_tags($text), 0, $max_length - 3, 'UTF-8').'...';
		}

		return $text;
	}

	private static function e(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
