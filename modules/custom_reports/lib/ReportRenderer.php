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
	public const FORMAT_PDF = 'pdf';

	public const FORMATS = [self::FORMAT_SUMMARY, self::FORMAT_HTML, self::FORMAT_CSV, self::FORMAT_PDF];

	private const SPARK = ["\u{2581}", "\u{2582}", "\u{2583}", "\u{2584}", "\u{2585}", "\u{2586}", "\u{2587}",
		"\u{2588}"
	];

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
			'<style>@page{size:A4 landscape;margin:12mm}@media print{.no-print{display:none}'.
			'tr,img{page-break-inside:avoid}}body{font-family:Arial,Helvetica,sans-serif;'.
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
		$html .= '<div style="margin:-6px 0 12px 0">'.self::e(self::openStatsText($report)).'</div>';

		if ($report['analytics'] !== null) {
			$html .= self::renderAnalyticsHtml($report['analytics'], $meta, $cell);
		}
		elseif (($report['analytics_error'] ?? null) !== null) {
			$html .= '<p style="color:#e45959">Period analytics is not available: '.
				self::e($report['analytics_error']).'</p>';
		}

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

		if ($report['problems']) {
			$lines[] = $e(self::openStatsText($report));
		}

		if ($report['analytics'] !== null) {
			$lines[] = '';
			$lines = array_merge($lines, self::renderAnalyticsText($report['analytics'], $b, $e));
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

	/**
	 * "Oldest: 13d 21h; open over 1 day: 12, over 7 days: 5; unacknowledged: 13".
	 */
	public static function openStatsText(array $report): string {
		$stats = $report['open_stats'];

		if ($stats['oldest_age'] === null) {
			return 'No open problems.';
		}

		return sprintf('Oldest open: %s, average age: %s; open over 1 day: %d, over 7 days: %d; unacknowledged: %d',
			self::formatDuration($stats['oldest_age']), self::formatDuration($stats['avg_age']), $stats['over_1d'],
			$stats['over_7d'], $stats['unacknowledged']
		);
	}

	public static function periodTitle(array $analytics): string {
		return sprintf('Period analytics: %s (%s - %s)', PeriodAnalytics::LABELS[$analytics['period']],
			date('Y-m-d H:i', $analytics['from']), date('Y-m-d H:i', $analytics['till'])
		);
	}

	/**
	 * "42 (+12% vs 37 in the previous period)".
	 */
	public static function changeText(array $analytics): string {
		if ($analytics['change_pct'] === null) {
			return sprintf('%d (previous period: %d)', $analytics['new'], $analytics['prev']);
		}

		return sprintf('%d (%s%d%% vs %d in the previous period)', $analytics['new'],
			$analytics['change_pct'] > 0 ? '+' : '', $analytics['change_pct'], $analytics['prev']
		);
	}

	private static function durationOrDash(?int $seconds): string {
		return $seconds === null ? '-' : self::formatDuration($seconds);
	}

	/**
	 * @return array  Label => value of the key period metrics.
	 */
	public static function analyticsMetrics(array $analytics): array {
		return [
			'New problems' => self::changeText($analytics),
			'Resolved' => $analytics['resolved'].' (still open: '.$analytics['unresolved'].')',
			'Time to resolve (MTTR)' => 'median '.self::durationOrDash($analytics['mttr_median']).
				', average '.self::durationOrDash($analytics['mttr_avg']),
			'Acknowledged' => ($analytics['ack_rate'] === null ? '-' : $analytics['ack_rate'].'%').
				' (time to acknowledge, average: '.self::durationOrDash($analytics['mtta_avg']).')'
		];
	}

	private static function renderAnalyticsHtml(array $analytics, array $meta, string $cell): string {
		$html = '<h3 style="margin:8px 0 4px 0">'.self::e(self::periodTitle($analytics)).'</h3>';
		$html .= '<table style="margin-bottom:8px">';

		foreach (self::analyticsMetrics($analytics) as $label => $value) {
			$html .= '<tr><td style="'.$cell.'background:#f4f4f4">'.self::e($label).'</td><td style="'.$cell.'">'.
				self::e($value).'</td></tr>';
		}

		$by_severity = [];

		foreach (Severity::descending() as $severity) {
			$by_severity[] = '<span style="display:inline-block;padding:0 6px;margin-right:4px;background:#'.
				self::e($meta[$severity]['color']).'">'.self::e($meta[$severity]['name']).': '.
				$analytics['new_by_severity'][$severity].'</span>';
		}

		$html .= '<tr><td style="'.$cell.'background:#f4f4f4">New by severity</td><td style="'.$cell.'">'.
			implode('', $by_severity).'</td></tr></table>';

		// Bar chart: one column per hour/day; plain table cells render in e-mail clients and in PDF.
		$max = max(1, max(array_column($analytics['buckets'], 'count') ?: [0]));
		$bars = '';
		$labels = '';

		foreach ($analytics['buckets'] as $bucket) {
			$height = (int) round($bucket['count'] * 70 / $max);
			$bars .= '<td style="vertical-align:bottom;padding:0 1px;text-align:center;font-size:10px;color:#768d99">'.
				($bucket['count'] > 0 ? $bucket['count'] : '').
				'<div style="height:'.max($height, $bucket['count'] > 0 ? 2 : 0).'px;background:#4796c4"></div></td>';
			$labels .= '<td style="padding:2px 1px;font-size:9px;color:#768d99;text-align:center;white-space:nowrap">'.
				self::e($bucket['label']).'</td>';
		}

		$html .= '<div style="margin-bottom:4px;color:#768d99">New problems per '.
			($analytics['period'] === PeriodAnalytics::PERIOD_24H ? 'hour' : 'day').'</div>'.
			'<table style="margin-bottom:12px;border-bottom:1px solid #dfe4e7"><tr style="height:90px">'.$bars.
			'</tr><tr>'.$labels.'</tr></table>';

		if ($analytics['recurring']) {
			$html .= '<h4 style="margin:6px 0 4px 0">Recurring problems</h4><table style="margin-bottom:12px">'.
				'<tr><th style="'.$cell.'background:#f4f4f4">Host</th><th style="'.$cell.'background:#f4f4f4">'.
				'Problem</th><th style="'.$cell.'background:#f4f4f4">Times</th></tr>';

			foreach ($analytics['recurring'] as $trigger) {
				$html .= '<tr><td style="'.$cell.'">'.self::e($trigger['hosts']).'</td><td style="'.$cell.'">'.
					self::e($trigger['name']).'</td><td style="'.$cell.'text-align:right">'.$trigger['count'].
					'</td></tr>';
			}

			$html .= '</table>';
		}

		if ($analytics['top_hosts']) {
			$html .= '<h4 style="margin:6px 0 4px 0">Hosts with most new problems</h4><table style="margin-bottom:12px">';

			foreach ($analytics['top_hosts'] as $i => $host) {
				$html .= '<tr><td style="'.$cell.'">'.($i + 1).'</td><td style="'.$cell.'">'.self::e($host['name']).
					'</td><td style="'.$cell.'text-align:right">'.$host['count'].'</td></tr>';
			}

			$html .= '</table>';
		}

		if ($analytics['truncated']) {
			$html .= '<p style="color:#e45959">Period analytics is limited by "analytics_limit" events.</p>';
		}

		return $html;
	}

	private static function renderAnalyticsText(array $analytics, callable $b, callable $e): array {
		$lines = [$b("\u{1F4C8} ".$e(PeriodAnalytics::LABELS[$analytics['period']]))];

		foreach (self::analyticsMetrics($analytics) as $label => $value) {
			$lines[] = $e($label.': '.$value);
		}

		$counts = array_column($analytics['buckets'], 'count');
		$max = max(1, max($counts ?: [0]));
		$spark = '';

		foreach ($counts as $count) {
			$spark .= self::SPARK[(int) floor($count * (count(self::SPARK) - 1) / $max)];
		}

		$lines[] = $e('Per '.($analytics['period'] === PeriodAnalytics::PERIOD_24H ? 'hour' : 'day').': ').$spark;

		if ($analytics['recurring']) {
			$lines[] = $b('Recurring:');

			foreach ($analytics['recurring'] as $trigger) {
				$lines[] = $e('- '.$trigger['hosts'].': '.$trigger['name'].' x'.$trigger['count']);
			}
		}

		return $lines;
	}

	private static function e(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
