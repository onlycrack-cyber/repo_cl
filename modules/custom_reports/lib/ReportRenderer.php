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
			$parts[] = $days.I18n::t('d');
		}

		if ($hours > 0) {
			$parts[] = $hours.I18n::t('h');
		}

		if ($minutes > 0 || !$parts) {
			$parts[] = $minutes.I18n::t('m');
		}

		return implode(' ', $parts);
	}

	public static function hostNames(array $problem): string {
		return $problem['hosts'] ? implode(', ', array_column($problem['hosts'], 'name')) : '-';
	}

	/**
	 * @param int $max_items  Maximum number of host groups / hosts to list (0 - all), the rest is "and N more".
	 */
	public static function filterDescription(array $report, int $max_items = 0): string {
		$list = static function (array $names) use ($max_items): string {
			$names = array_values($names);

			if ($max_items > 0 && count($names) > $max_items) {
				return I18n::s('%1$s and %2$s more', implode(', ', array_slice($names, 0, $max_items)),
					count($names) - $max_items
				);
			}

			return implode(', ', $names);
		};
		$info = $report['filter_info'];
		$parts = [];

		if (($info['scope'] ?? '') !== '') {
			$parts[] = $info['scope'];
		}

		$parts[] = I18n::t('Period').': '.(array_key_exists($info['period'], Period::LABELS)
			? I18n::t(Period::LABELS[$info['period']])
			: $info['period']);
		$parts[] = I18n::t('Severity').': '.($info['severities']
			? implode(', ', array_map([I18n::class, 't'], $info['severities']))
			: I18n::t('all'));

		if (($info['problem_name'] ?? '') !== '') {
			$parts[] = I18n::t('Problem').': *'.$info['problem_name'].'*';
		}

		if (($info['tags'] ?? '') !== '') {
			$parts[] = I18n::t('Tags').': '.$info['tags'];
		}

		if (($info['acknowledged'] ?? ReportBuilder::ACK_ALL) != ReportBuilder::ACK_ALL) {
			$parts[] = $info['acknowledged'] == ReportBuilder::ACK_YES ? I18n::t('Acknowledged only')
				: I18n::t('Unacknowledged only');
		}

		if ($info['groups']) {
			$parts[] = I18n::t('Host groups').': '.$list($info['groups']);
		}

		if ($info['hosts']) {
			$parts[] = I18n::t('Hosts').': '.$list($info['hosts']);
		}

		return implode('; ', $parts);
	}

	public static function fileName(string $title, string $extension, int $time): string {
		// Cyrillic and other titles: Latin letters if the intl extension is available.
		if (function_exists('transliterator_transliterate')) {
			$latin = transliterator_transliterate('Any-Latin; Latin-ASCII', $title);
			$title = is_string($latin) ? $latin : $title;
		}

		$base = preg_replace('/[^A-Za-z0-9_-]+/', '_', $title);
		$base = trim((string) $base, '_');

		return ($base !== '' ? $base : 'problem_report').'_'.date('Ymd_Hi', $time).'.'.$extension;
	}

	/**
	 * Protects against CSV formula injection: problem and host names can contain item values (trigger name macros),
	 * and a cell starting with "=", "+", "-", "@", tab or CR would be run as a formula by spreadsheet applications.
	 */
	public static function csvCell($value): string {
		$value = (string) $value;

		return ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false) ? "'".$value : $value;
	}

	public static function renderCsv(array $report): string {
		$fh = fopen('php://temp', 'r+');

		// UTF-8 BOM: lets spreadsheet applications detect the encoding of non-latin names.
		fwrite($fh, "\xEF\xBB\xBF");
		fputcsv($fh, array_map([I18n::class, 't'],
			['Time', 'Duration', 'Severity', 'Host', 'Problem', 'Acknowledged', 'Event ID']
		), ',', '"', '');

		foreach ($report['problems'] as $problem) {
			fputcsv($fh, array_map([self::class, 'csvCell'], [
				date('Y-m-d H:i:s', $problem['clock']),
				self::formatDuration($problem['age']),
				$report['severity_meta'][$problem['severity']]['name'],
				self::hostNames($problem),
				$problem['name'],
				I18n::t($problem['acknowledged'] ? 'Yes' : 'No'),
				$problem['eventid']
			]), ',', '"', '');
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

		$html = '<!DOCTYPE html><html lang="'.I18n::lang().'"><head><meta charset="UTF-8"><title>'.self::e($title).'</title>'.
			'<style>@page{size:A4 landscape;margin:12mm}@media print{.no-print{display:none}'.
			'tr,img{page-break-inside:avoid}}body{font-family:Arial,Helvetica,sans-serif;'.
			'font-size:13px;color:#1f2c33;margin:16px}table{border-collapse:collapse}</style></head><body>';

		$html .= '<h2 style="margin:0 0 4px 0">'.self::e($title).'</h2>';
		$html .= '<div style="color:#768d99;margin-bottom:12px">'.I18n::t('Generated').': '.date('Y-m-d H:i:s', $report['generated']).
			'<br>'.self::e(self::filterDescription($report)).'</div>';

		// KPI.
		$html .= '<table style="margin-bottom:12px"><tr>';
		$html .= '<td style="'.$cell.'background:#f4f4f4;text-align:center;min-width:90px"><div style="font-size:22px;'.
			'font-weight:bold">'.$report['total'].'</div>'.self::e(I18n::t('Active problems')).'</td>';

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
			$html .= '<p style="color:#e45959">'.self::e(I18n::t('Period analytics is not available')).': '.
				self::e($report['analytics_error']).'</p>';
		}

		// Top hosts.
		if ($report['top_hosts']) {
			$html .= '<h3 style="margin:8px 0 4px 0">'.self::e(I18n::t('Top problem hosts')).'</h3><table style="margin-bottom:12px">';

			foreach ($report['top_hosts'] as $i => $host) {
				$html .= '<tr><td style="'.$cell.'">'.($i + 1).'</td><td style="'.$cell.'">'.self::e($host['name']).
					'</td><td style="'.$cell.'text-align:right">'.$host['count'].'</td></tr>';
			}

			$html .= '</table>';
		}

		if ($report['analytics'] !== null) {
			$html .= self::renderUnresolvedHtml($report, $cell);
		}

		if (($report['changes'] ?? null) !== null) {
			$html .= ChangeRenderer::renderSection($report['changes'], $meta);
		}
		elseif (($report['changes_error'] ?? null) !== null) {
			$html .= '<p style="color:#e45959">'.self::e(I18n::t('Changes analysis is not available')).': '.self::e($report['changes_error']).'</p>';
		}

		// Problems (in the "summary" mode the grouped section above replaces the full list).
		$summary_only = self::isSummaryOnly($report);

		if (!$summary_only) {
			$html .= '<h3 style="margin:8px 0 4px 0">'.self::e(I18n::t('Active problems')).'</h3>';
		}

		if ($summary_only) {
			// Nothing: see "Unresolved problems".
		}
		elseif ($report['problems']) {
			$html .= '<table style="width:100%"><tr>';

			foreach (['Time', 'Duration', 'Severity', 'Host', 'Problem', 'Ack'] as $header) {
				$html .= '<th style="'.$cell.'background:#f4f4f4">'.self::e(I18n::t($header)).'</th>';
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
						I18n::t($problem['acknowledged'] ? 'Yes' : 'No').'</td>'.
					'</tr>';
			}

			$html .= '</table>';
		}
		else {
			$html .= '<p>'.self::e(I18n::t('No active problems match the filter.')).'</p>';
		}

		if ($report['truncated'] && !$summary_only) {
			$html .= '<p style="color:#e45959">'.self::e(I18n::s('The report is limited to %1$s problems.', $report['total'])).
				'</p>';
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
		$lines[] = $e(I18n::t('Generated').': '.date('Y-m-d H:i', $report['generated']));
		$lines[] = $e(self::filterDescription($report, 10));
		$lines[] = '';
		$lines[] = $b($e(I18n::t('Active problems')).': '.$report['total']);

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

		if (($report['changes'] ?? null) !== null) {
			$lines[] = '';

			foreach (ChangeRenderer::renderText($report['changes']) as $i => $line) {
				$lines[] = $i == 0 ? $b($e($line)) : $e($line);
			}
		}

		if ($report['top_hosts']) {
			$lines[] = '';
			$lines[] = $b($e(I18n::t('Top hosts')).':');

			foreach ($report['top_hosts'] as $i => $host) {
				$lines[] = ($i + 1).'. '.$e($host['name']).' - '.$host['count'];
			}
		}

		$text = implode("\n", $lines);

		if (!$report['problems']) {
			return $text;
		}

		$summary_only = self::isSummaryOnly($report);

		if ($summary_only) {
			$text .= "\n\n".$b("\u{23F3} ".$e(self::unresolvedTitle($report)).':');
			$items = [];

			foreach ($report['unresolved'] as $item) {
				$items[] = "\n".Severity::ICONS[$item['severity']].' '.$e(self::unresolvedText($item));
			}
		}
		else {
			$text .= "\n\n".$b($e(I18n::t('Problems')).':');
			$items = [];

			foreach ($report['problems'] as $problem) {
				$items[] = "\n".Severity::ICONS[$problem['severity']].' '.$e(self::hostNames($problem)).': '.
					$e($problem['name']).' ('.self::formatDuration($problem['age']).
					($problem['acknowledged'] ? ', '.$e(I18n::t('ack')) : '').')';
			}
		}

		$total = count($items);

		foreach ($items as $index => $line) {
			$rest = $total - $index - 1;
			$tail = "\n".I18n::s('... and %1$s more', $rest + 1);

			if ($max_length > 0
					&& mb_strlen($text.$line, 'UTF-8') + ($rest > 0 ? mb_strlen($tail, 'UTF-8') : 0) > $max_length) {
				$text .= $tail;
				break;
			}

			$text .= $line;
		}

		if ($report['truncated'] && $max_length == 0 && !$summary_only) {
			$text .= "\n".$e(I18n::s('(the report is limited to %1$s problems)', $total));
		}

		if ($max_length > 0 && mb_strlen($text, 'UTF-8') > $max_length) {
			if ($html) {
				// Cutting HTML could split a tag or an entity ("&am...") and Telegram would reject the message:
				// cut the plain text instead and escape it again (escaping does not change the visible length).
				return htmlspecialchars(self::renderText($report, $title, $max_length, false), ENT_NOQUOTES | ENT_SUBSTITUTE,
					'UTF-8'
				);
			}

			$text = mb_substr($text, 0, $max_length - 3, 'UTF-8').'...';
		}

		return $text;
	}

	/**
	 * The grouped "Unresolved problems" section replaces the full problem list (needs period analytics).
	 */
	public static function isSummaryOnly(array $report): bool {
		return ($report['filter']['problem_list'] ?? '') === ReportBuilder::LIST_SUMMARY && $report['analytics'] !== null;
	}

	/**
	 * "Unresolved problems: 7, open the whole period (last 7 days): 3".
	 */
	public static function unresolvedTitle(array $report): string {
		$whole = count(array_filter($report['unresolved'], static function (array $item): bool {
			return $item['whole_period'];
		}));

		return I18n::s('Unresolved problems: %1$s, open the whole period (%2$s): %3$s', count($report['unresolved']),
			I18n::t(PeriodAnalytics::LABELS[$report['analytics']['period']]), $whole
		);
	}

	/**
	 * One line about an unresolved problem, as in an alert:
	 * "db-pg-01: Disk failure - open 9d 2h (the whole period), alerts sent: 100, fired 3 times, last alert: ...".
	 */
	public static function unresolvedText(array $item): string {
		$parts = [I18n::s($item['whole_period'] ? 'open %1$s (the whole period)' : 'open %1$s',
			self::formatDuration($item['age'])
		)];

		if ($item['alerts_sent'] !== null) {
			$parts[] = I18n::s('alerts sent: %1$s', $item['alerts_sent'])
				.($item['alerts_failed'] ? ', '.I18n::s('failed: %1$s', $item['alerts_failed']) : '');
		}

		if ($item['repeats'] > 1) {
			$parts[] = I18n::s('fired %1$s times', $item['repeats']);
		}

		if ($item['open_count'] > 1) {
			$parts[] = I18n::s('%1$s open problems', $item['open_count']);
		}

		if ($item['acknowledged']) {
			$parts[] = I18n::t('ack');
		}

		$text = implode(', ', array_column($item['hosts'], 'name')).': '.$item['name'].' - '.implode(', ', $parts);

		if ($item['last_alert'] !== '' && $item['last_alert'] !== $item['name']) {
			$text .= '. '.I18n::s('Last alert: "%1$s"', $item['last_alert']);
		}

		return $text;
	}

	private static function renderUnresolvedHtml(array $report, string $cell): string {
		$meta = $report['severity_meta'];
		$html = '<h3 style="margin:8px 0 4px 0">'.self::e(self::unresolvedTitle($report)).'</h3>';

		if (!$report['unresolved']) {
			return $html.'<p>'.self::e(I18n::t('No unresolved problems.')).'</p>';
		}

		$html .= '<div style="margin-bottom:4px;color:#768d99">'.self::e(I18n::t('One line per problem: alerts and '.
			'repeated events of the period are counted instead of being listed.')).'</div><table style="width:100%;margin-bottom:12px"><tr>';

		foreach (['Severity', 'Host', 'Problem', 'Open since', 'Duration', 'Alerts sent', 'Fired', 'Last alert'] as $h) {
			$html .= '<th style="'.$cell.'background:#f4f4f4">'.self::e(I18n::t($h)).'</th>';
		}

		$html .= '</tr>';

		foreach ($report['unresolved'] as $item) {
			$alerts = $item['alerts_sent'] === null
				? '-'
				: $item['alerts_sent'].($item['alerts_failed'] ? ' <span style="color:#e45959">('.$item['alerts_failed'].
					' '.self::e(I18n::t('failed')).')</span>' : '');

			$html .= '<tr>'.
				'<td style="'.$cell.'background:#'.self::e($meta[$item['severity']]['color']).'">'.
					self::e($meta[$item['severity']]['name']).'</td>'.
				'<td style="'.$cell.'white-space:nowrap">'.self::e(implode(', ', array_column($item['hosts'], 'name'))).
					'</td>'.
				'<td style="'.$cell.'">'.self::e($item['name']).($item['acknowledged'] ? ' <span style="color:#429e47">'.
					'('.self::e(I18n::t('ack')).')</span>' : '').'</td>'.
				'<td style="'.$cell.'white-space:nowrap">'.date('Y-m-d H:i', $item['since']).'</td>'.
				'<td style="'.$cell.'white-space:nowrap'.($item['whole_period'] ? ';font-weight:bold;color:#e45959' : '').'">'.
					self::formatDuration($item['age']).($item['whole_period'] ? '<br>'.self::e(I18n::t('whole period')) : '').'</td>'.
				'<td style="'.$cell.'text-align:right">'.$alerts.'</td>'.
				'<td style="'.$cell.'text-align:right">'.($item['repeats'] > 0 ? $item['repeats'] : '-').'</td>'.
				'<td style="'.$cell.'color:#768d99">'.self::e($item['last_alert']).'</td>'.
				'</tr>';
		}

		return $html.'</table>';
	}

	/**
	 * "Oldest: 13d 21h; open over 1 day: 12, over 7 days: 5; unacknowledged: 13".
	 */
	public static function openStatsText(array $report): string {
		$stats = $report['open_stats'];

		if ($stats['oldest_age'] === null) {
			return I18n::t('No open problems.');
		}

		return I18n::s('Oldest open: %1$s, average age: %2$s; open over 1 day: %3$s, over 7 days: %4$s; unacknowledged: %5$s',
			self::formatDuration($stats['oldest_age']), self::formatDuration($stats['avg_age']), $stats['over_1d'],
			$stats['over_7d'], $stats['unacknowledged']
		);
	}

	public static function periodTitle(array $analytics): string {
		return I18n::s('Period analytics: %1$s (%2$s - %3$s)', I18n::t(PeriodAnalytics::LABELS[$analytics['period']]),
			date('Y-m-d H:i', $analytics['from']), date('Y-m-d H:i', $analytics['till'])
		);
	}

	/**
	 * "42 (+12% vs 37 in the previous period)".
	 */
	public static function changeText(array $analytics): string {
		if ($analytics['change_pct'] === null) {
			return I18n::s('%1$s (previous period: %2$s)', $analytics['new'], $analytics['prev']);
		}

		return I18n::s('%1$s (%2$s%3$s%% vs %4$s in the previous period)', $analytics['new'],
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
			I18n::t('New problems') => self::changeText($analytics),
			I18n::t('Resolved') => I18n::s('%1$s (still open: %2$s)', $analytics['resolved'], $analytics['unresolved']),
			I18n::t('Open problems') => self::openChangeText($analytics),
			I18n::t('Time to resolve (MTTR)') => I18n::s('median %1$s, average %2$s',
				self::durationOrDash($analytics['mttr_median']), self::durationOrDash($analytics['mttr_avg'])
			),
			I18n::t('Acknowledged') => I18n::s('%1$s (time to acknowledge, average: %2$s)',
				$analytics['ack_rate'] === null ? '-' : $analytics['ack_rate'].'%',
				self::durationOrDash($analytics['mtta_avg'])
			)
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

		$html .= '<tr><td style="'.$cell.'background:#f4f4f4">'.self::e(I18n::t('New by severity')).'</td><td style="'.$cell.'">'.
			implode('', $by_severity).'</td></tr></table>';

		$html .= self::renderSeverityChartHtml($analytics, $meta);
		$html .= self::renderFlowChartHtml($analytics);

		if ($analytics['recurring']) {
			$html .= '<h4 style="margin:6px 0 4px 0">'.self::e(I18n::t('Recurring problems')).'</h4>'.
				'<table style="margin-bottom:12px"><tr>'.
				'<th style="'.$cell.'background:#f4f4f4">'.self::e(I18n::t('Host')).'</th>'.
				'<th style="'.$cell.'background:#f4f4f4">'.self::e(I18n::t('Problem')).'</th>'.
				'<th style="'.$cell.'background:#f4f4f4">'.self::e(I18n::t('Times')).'</th></tr>';

			foreach ($analytics['recurring'] as $trigger) {
				$html .= '<tr><td style="'.$cell.'">'.self::e($trigger['hosts']).'</td><td style="'.$cell.'">'.
					self::e($trigger['name']).'</td><td style="'.$cell.'text-align:right">'.$trigger['count'].
					'</td></tr>';
			}

			$html .= '</table>';
		}

		if ($analytics['top_hosts']) {
			$html .= '<h4 style="margin:6px 0 4px 0">'.self::e(I18n::t('Hosts with most new problems')).'</h4><table style="margin-bottom:12px">';

			foreach ($analytics['top_hosts'] as $i => $host) {
				$html .= '<tr><td style="'.$cell.'">'.($i + 1).'</td><td style="'.$cell.'">'.self::e($host['name']).
					'</td><td style="'.$cell.'text-align:right">'.$host['count'].'</td></tr>';
			}

			$html .= '</table>';
		}

		if ($analytics['truncated']) {
			$html .= '<p style="color:#e45959">'.self::e(I18n::t('Period analytics is limited by "analytics_limit": '.
				'the numbers (including alert counts) may be lower than in reality.')).'</p>';
		}

		return $html;
	}

	/**
	 * "5 at the start of the period -> 19 now (+14)".
	 */
	public static function openChangeText(array $analytics): string {
		$delta = $analytics['open_end'] - $analytics['open_start'];

		return I18n::s('%1$s at the start of the period -> %2$s at the end (%3$s%4$s)', $analytics['open_start'],
			$analytics['open_end'], $delta > 0 ? '+' : '', $delta
		);
	}

	private static function chartUnit(array $analytics): string {
		return $analytics['period'] === PeriodAnalytics::PERIOD_24H ? 'hour' : 'day';
	}

	/**
	 * Chart 1: new problems per hour/day, stacked by severity (Disaster at the bottom).
	 * Built from table cells and blocks with fixed heights: renders in e-mail clients (incl. Outlook) and in PDF.
	 */
	private static function renderSeverityChartHtml(array $analytics, array $meta): string {
		$height = 80;
		$max = max(1, max(array_column($analytics['buckets'], 'count') ?: [0]));
		$bars = '';
		$labels = '';

		foreach ($analytics['buckets'] as $index => $bucket) {
			$stack = '';

			foreach (array_reverse(Severity::descending()) as $severity) {
				$count = $bucket['by_severity'][$severity];

				if ($count > 0) {
					$stack .= '<div style="height:'.max(1, (int) round($count * $height / $max)).'px;background:#'.
						self::e($meta[$severity]['color']).'" title="'.self::e($meta[$severity]['name'].': '.$count).
						'"></div>';
				}
			}

			$bars .= '<td style="vertical-align:bottom;padding:0 1px;text-align:center;font-size:9px;color:#768d99">'.
				($bucket['count'] > 0 ? $bucket['count'] : '').$stack.'</td>';
			$labels .= '<td style="padding:2px 1px;font-size:9px;color:#768d99;text-align:center;white-space:nowrap">'.
				self::e(self::axisLabel($analytics['buckets'], $index)).'</td>';
		}

		return '<div style="margin:6px 0 2px 0;color:#768d99">'.self::e(I18n::s(
				'New problems per %1$s, by severity (colors as above)', I18n::t(self::chartUnit($analytics))
			)).'</div>'.
			'<table style="width:100%;margin-bottom:10px;border-bottom:1px solid #dfe4e7;table-layout:fixed">'.
			'<tr style="height:'.($height + 14).'px">'.$bars.'</tr><tr>'.$labels.'</tr></table>';
	}

	/**
	 * Chart 2: problems open at the end of every hour/day on their own scale (a change from 75 to 77 stays
	 * visible), then new vs resolved per hour/day - whether problems are fixed faster than new ones appear.
	 */
	private static function renderFlowChartHtml(array $analytics): string {
		$labels = [];

		foreach (array_keys($analytics['buckets']) as $index) {
			$labels[] = self::axisLabel($analytics['buckets'], $index);
		}

		return '<div style="margin:6px 0 2px 0;color:#768d99">'.self::e(I18n::s('New vs resolved per %1$s, open '.
				'problems: %2$s', I18n::t(self::chartUnit($analytics)), self::openChangeText($analytics)
			)).'</div>'.
			self::openLineHtml(array_column($analytics['buckets'], 'open'), I18n::t('Open problems')).
			self::flowBarsHtml(array_column($analytics['buckets'], 'count'), array_column($analytics['buckets'], 'resolved'),
				$labels
			);
	}

	/**
	 * Open problems per interval as columns on a scale fitted to their range (e-mail clients, PDF), values on top.
	 */
	public static function openLineHtml(array $values, string $title): string {
		if (!$values) {
			return '';
		}

		[$min, $max] = [min($values), max($values)];
		$low = max(0, $min - max(1, (int) ceil(($max - $min) * 0.5)));
		$height = 40;
		$cells = '';

		foreach ($values as $value) {
			$h = (int) round(6 + ($value - $low) * ($height - 6) / max(1, $max - $low));
			$cells .= '<td style="vertical-align:bottom;padding:0 2px;text-align:center;font-size:9px;color:#4f5b63">'.
				'<b>'.$value.'</b><div style="height:'.$h.'px;background:#f3c3a4;border-top:2px solid #e9894f"></div></td>';
		}

		return '<div style="font-size:11px;color:#4f5b63;margin-top:4px">'.self::e($title).' ('.
			self::e(I18n::s('scale from %1$s', $low)).')</div>'.
			'<table style="width:100%;border-collapse:collapse;table-layout:fixed"><tr style="height:'.($height + 14).'px">'.
			$cells.'</tr></table>';
	}

	/**
	 * New (up) and resolved (down) per interval, one scale, axis labels below.
	 */
	public static function flowBarsHtml(array $new, array $resolved, array $labels): string {
		$half = 34;
		$max = max(1, max($new ?: [0]), max($resolved ?: [0]));
		$up = '';
		$down = '';
		$axis = '';

		foreach ($new as $index => $value) {
			$h_up = $value > 0 ? max(1, (int) round($value * $half / $max)) : 0;
			$h_down = $resolved[$index] > 0 ? max(1, (int) round($resolved[$index] * $half / $max)) : 0;
			$up .= '<td style="vertical-align:bottom;padding:0 3px;text-align:center;font-size:9px;color:#4796c4">'.
				($value > 0 ? $value : '').'<div style="height:'.$h_up.'px;background:#4796c4"></div></td>';
			$down .= '<td style="vertical-align:top;padding:0 3px;text-align:center;font-size:9px;color:#59b36a">'.
				'<div style="height:'.$h_down.'px;background:#59b36a"></div>'.($resolved[$index] > 0 ? $resolved[$index] : '').
				'</td>';
			$axis .= '<td style="padding:2px 1px;font-size:9px;color:#768d99;text-align:center;white-space:nowrap">'.
				self::e($labels[$index] ?? '').'</td>';
		}

		$legend = '<span style="display:inline-block;width:10px;height:10px;background:#4796c4;margin:0 4px 0 0"></span>'.
			self::e(I18n::t('New')).' &#8593; <span style="display:inline-block;width:10px;height:10px;background:#59b36a;'.
			'margin:0 4px 0 10px"></span>'.self::e(I18n::t('Resolved')).' &#8595;';

		return '<div style="font-size:11px;color:#4f5b63;margin-top:6px">'.$legend.'</div>'.
			'<table style="width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:12px">'.
			'<tr style="height:'.($half + 12).'px;border-bottom:1px solid #9aa7b0">'.$up.'</tr>'.
			'<tr style="height:'.($half + 12).'px">'.$down.'</tr><tr>'.$axis.'</tr></table>';
	}

	/**
	 * Axis label of a column: with many columns only every n-th one is labelled, so labels do not overlap
	 * in a narrow e-mail.
	 */
	private static function axisLabel(array $buckets, int $index): string {
		$step = (int) ceil(count($buckets) / 12);

		return ($index % $step == 0 || $index == count($buckets) - 1) ? $buckets[$index]['label'] : '';
	}

	private static function sparkline(array $values): string {
		$max = max(1, max($values ?: [0]));
		$spark = '';

		foreach ($values as $value) {
			$spark .= self::SPARK[(int) floor($value * (count(self::SPARK) - 1) / $max)];
		}

		return $spark;
	}

	private static function renderAnalyticsText(array $analytics, callable $b, callable $e): array {
		$lines = [$b("\u{1F4C8} ".$e(I18n::s('Analytics: %1$s', I18n::t(PeriodAnalytics::LABELS[$analytics['period']]))))];

		foreach (self::analyticsMetrics($analytics) as $label => $value) {
			$lines[] = $e($label.': '.$value);
		}

		if ($analytics['truncated']) {
			$lines[] = $e(I18n::t('(limited by "analytics_limit": the numbers may be lower than in reality)'));
		}

		$unit = self::chartUnit($analytics);
		$lines[] = $e(I18n::t('New per '.$unit).': ').self::sparkline(array_column($analytics['buckets'], 'count'));
		$lines[] = $e(I18n::t('Open at the end of the '.$unit).': ').
			self::sparkline(array_column($analytics['buckets'], 'open')).
			$e(' ('.$analytics['open_start']." \u{2192} ".$analytics['open_end'].')');

		if ($analytics['recurring']) {
			$lines[] = $b($e(I18n::t('Recurring')).':');

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
