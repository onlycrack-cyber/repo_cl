<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * Renders the "Changes" analysis (ChangeAnalytics::compute()) as an HTML section (e-mail clients, PDF), a standalone
 * HTML document, CSV and a few lines of text (Telegram).
 */
class ChangeRenderer {

	private const CELL = 'padding:3px 6px;border:1px solid #dfe4e7;text-align:left;vertical-align:top;';
	private const HEAD = 'padding:3px 6px;border:1px solid #dfe4e7;text-align:left;background:#f4f4f4;';

	public const GROUPING_LABELS = [
		ChangeAnalytics::GROUP_BY_GROUP => 'Host group',
		ChangeAnalytics::GROUP_BY_HOST => 'Host',
		ChangeAnalytics::GROUP_BY_TRIGGER => 'Trigger'
	];

	/**
	 * "By host group: previous period -> current period" (separate texts for proper translation).
	 */
	public static function compareTitle(string $grouping): string {
		switch ($grouping) {
			case ChangeAnalytics::GROUP_BY_HOST:
				return I18n::t('By host: previous period -> current period');

			case ChangeAnalytics::GROUP_BY_TRIGGER:
				return I18n::t('By trigger: previous period -> current period');

			default:
				return I18n::t('By host group: previous period -> current period');
		}
	}

	public static function periodText(array $changes): string {
		return I18n::s('%1$s - %2$s compared with %3$s - %4$s', date('Y-m-d H:i', $changes['from']),
			date('Y-m-d H:i', $changes['till']), date('Y-m-d H:i', $changes['prev_from']),
			date('Y-m-d H:i', $changes['from'])
		);
	}

	/**
	 * "+12 (+40%)" for counts.
	 */
	public static function deltaText(int $delta, ?int $pct = null): string {
		return sprintf('%+d', $delta).($pct !== null ? sprintf(' (%+d%%)', $pct) : '');
	}

	public static function duration(?int $seconds): string {
		return $seconds === null ? '-' : ReportRenderer::formatDuration($seconds);
	}

	/**
	 * Change of a duration: "+1h 5m" / "-20m" / "".
	 */
	public static function durationDelta(?int $prev, ?int $cur): string {
		if ($prev === null || $cur === null || $prev == $cur) {
			return '';
		}

		return ($cur > $prev ? '+' : '-').ReportRenderer::formatDuration(abs($cur - $prev));
	}

	public static function totalsText(array $changes): string {
		$cur = $changes['totals']['cur'];
		$prev = $changes['totals']['prev'];
		$pct = $prev['new'] > 0 ? (int) round(($cur['new'] - $prev['new']) * 100 / $prev['new']) : null;

		return I18n::s('New problems: %1$s (previous period: %2$s, %3$s); median time to resolve: %4$s (previous: %5$s)',
			$cur['new'], $prev['new'], self::deltaText($cur['new'] - $prev['new'], $pct), self::duration($cur['mttr']),
			self::duration($prev['mttr'])
		);
	}

	/**
	 * Section for HTML reports and PDF.
	 */
	/**
	 * Verdict of the period in words: [title, summary sentence, reasons].
	 */
	public static function verdictTexts(array $overview): array {
		$titles = [
			'worse' => I18n::t('Worse than in the previous period'),
			'better' => I18n::t('Better than in the previous period'),
			'same' => I18n::t('No significant changes')
		];
		$reasons = [];

		foreach ($overview['verdict']['reasons'] as [$code, $sign, $now, $was]) {
			switch ($code) {
				case 'open':
					$reasons[] = $sign > 0
						? I18n::s('more open problems: %1$s at the start, %2$s at the end', $was, $now)
						: I18n::s('fewer open problems: %1$s at the start, %2$s at the end', $was, $now);
					break;

				case 'new':
					$reasons[] = $sign > 0
						? I18n::s('more new problems: %1$s against %2$s', $now, $was)
						: I18n::s('fewer new problems: %1$s against %2$s', $now, $was);
					break;

				case 'mttr':
					$reasons[] = $sign > 0
						? I18n::s('problems are resolved slower: median %1$s against %2$s', self::duration($now), self::duration($was))
						: I18n::s('problems are resolved faster: median %1$s against %2$s', self::duration($now), self::duration($was));
					break;
			}
		}

		return [
			$titles[$overview['verdict']['status']],
			I18n::s('Open now: %1$s (High and Disaster: %2$s). In the period: new %3$s, resolved %4$s.',
				$overview['open_end'], $overview['open_high'], $overview['new'], $overview['resolved']
			),
			$reasons
		];
	}

	/**
	 * The overview for e-mails and PDF: verdict, key numbers, dynamics, open now (optional: a problem report lists the
	 * open problems itself), resolved in the period. Table layout and inline styles for e-mail clients.
	 */
	public static function overviewHtml(array $changes, array $meta, bool $with_open): string {
		$ov = $changes['overview'];
		[$title, $sentence, $reasons] = self::verdictTexts($ov);
		$colors = ['worse' => 'e45959', 'better' => '59b36a', 'same' => '9aa7b0'];
		$backgrounds = ['worse' => 'fbe9e9', 'better' => 'eaf6ec', 'same' => 'f2f4f5'];
		$status = $ov['verdict']['status'];

		$html = '<div style="margin:8px 0;padding:8px 12px;border-left:6px solid #'.$colors[$status].';background:#'.
			$backgrounds[$status].'"><div style="font-size:15px;font-weight:bold">'.self::e($title).'</div><div>'.
			self::e($sentence).'</div>'.($reasons ? '<div style="color:#4f5b63">'.self::e(implode('; ', $reasons)).'.</div>' : '').
			'</div>';

		// Key numbers with the previous period.
		$pct = $ov['new_prev'] > 0 ? (int) round(($ov['new'] - $ov['new_prev']) * 100 / $ov['new_prev']) : null;
		$cards = [
			[I18n::t('Open now'), (string) $ov['open_end'], self::e(I18n::s('at the start: %1$s', $ov['open_start'])).' '.
				self::colored(self::deltaText($ov['open_end'] - $ov['open_start']), $ov['open_end'] - $ov['open_start']),
				$ov['open_end'] > 0 ? 'e97659' : ''],
			[I18n::t('High and Disaster open'), (string) $ov['open_high'], '', $ov['open_high'] > 0 ? 'e45959' : ''],
			[I18n::t('New in the period'), (string) $ov['new'], self::e(I18n::s('was: %1$s', $ov['new_prev'])).' '.
				self::colored(self::deltaText($ov['new'] - $ov['new_prev'], $pct), $ov['new'] - $ov['new_prev']), ''],
			[I18n::t('Resolved in the period'), (string) $ov['resolved'], self::e(I18n::s('was: %1$s', $ov['resolved_prev'])), ''],
			[I18n::t('Time to resolve (median)'), self::duration($ov['mttr']), self::e(I18n::s('was: %1$s', self::duration($ov['mttr_prev']))).
				' '.self::colored(self::durationDelta($ov['mttr_prev'], $ov['mttr']),
					$ov['mttr_prev'] !== null && $ov['mttr'] !== null ? $ov['mttr'] - $ov['mttr_prev'] : 0), '']
		];
		$html .= '<table style="margin-bottom:10px;border-collapse:collapse"><tr>';

		foreach ($cards as [$label, $value, $note, $color]) {
			$html .= '<td style="'.self::CELL.'min-width:110px;vertical-align:top"><div style="font-size:22px;font-weight:bold'.
				($color !== '' ? ';color:#'.$color : '').'">'.self::e($value).'</div><div>'.self::e($label).'</div>'.
				($note !== '' ? '<div style="font-size:11px;color:#4f5b63">'.$note.'</div>' : '').'</td>';
		}

		$html .= '</tr></table>';
		$html .= self::dynamicsHtml($ov);

		if ($with_open) {
			$rows = [];

			foreach ($ov['open_now'] as $row) {
				$rows[] = [self::severityCell($row['severity'], $meta), self::e($row['hosts']),
					self::e($row['name'].($row['open_count'] > 1 ? ' ('.I18n::s('%1$s open', $row['open_count']).')' : '')),
					date('Y-m-d H:i', $row['since']), self::duration($row['age']),
					$row['before_period']
						? '<span style="color:#e45959">'.self::e(I18n::t('open the whole period')).'</span>'
						: '<span style="color:#e97659">'.self::e(I18n::t('new')).'</span>',
					$row['occurrences'] > 0 ? $row['occurrences'] : '-',
					self::e(I18n::t($row['acknowledged'] ? 'Yes' : 'No'))
				];
			}

			$html .= self::title(I18n::s('Open now: %1$s', $ov['open_now_total'])).
				self::table(['Severity', 'Host', 'Problem', 'Open since', 'Duration', 'In the period', 'Occurred', 'Ack'], $rows,
					'No open problems.'
				);

			if ($ov['open_now_total'] > count($ov['open_now'])) {
				$html .= self::note(I18n::s('Top %1$s of %2$s.', count($ov['open_now']), $ov['open_now_total']));
			}
		}

		$rows = [];

		foreach ($ov['closed'] as $row) {
			$rows[] = [self::severityCell($row['severity'], $meta), self::e($row['hosts']), self::e($row['name']), $row['count'],
				self::duration($row['time']), date('Y-m-d H:i', $row['last']).' - '.date('Y-m-d H:i', $row['last_resolved'])
			];
		}

		$html .= self::title(I18n::s('Occurred in the period and resolved: %1$s', $ov['closed_total'])).
			self::table(['Severity', 'Host', 'Problem', 'Times', 'Time in problem', 'Last time'], $rows, 'No resolved problems.');

		if ($ov['closed_total'] > count($ov['closed'])) {
			$html .= self::note(I18n::s('Top %1$s of %2$s.', count($ov['closed']), $ov['closed_total']));
		}

		return $html;
	}

	/**
	 * Dynamics chart of the overview: table cells with fixed heights (e-mail clients, PDF).
	 */
	private static function dynamicsHtml(array $ov): string {
		if (!$ov['buckets']) {
			return '';
		}

		$height = 80;
		$max = 1;

		foreach ($ov['buckets'] as $bucket) {
			$max = max($max, $bucket['new'], $bucket['resolved'], $bucket['open'], $bucket['new_prev']);
		}

		$colors = ['new_prev' => 'd5dadd', 'new' => '4796c4', 'resolved' => '59b36a', 'open' => '9aa7b0'];
		$step = (int) ceil(count($ov['buckets']) / 14);
		$bars = '';
		$labels = '';

		foreach ($ov['buckets'] as $index => $bucket) {
			$group = '';

			foreach ($colors as $key => $color) {
				$group .= '<td style="vertical-align:bottom;padding:0;width:25%"><div style="height:'.
					($bucket[$key] > 0 ? max(1, (int) round($bucket[$key] * $height / $max)) : 0).'px;background:#'.$color.
					'"></div></td>';
			}

			$bars .= '<td style="vertical-align:bottom;padding:0 2px"><table style="width:100%;border-collapse:collapse"><tr>'.
				$group.'</tr></table></td>';
			$label = $index % $step == 0 || $index == count($ov['buckets']) - 1
				? date($ov['unit'] === 'hour' ? 'H:i' : 'd.m', $bucket['start'])
				: '';
			$labels .= '<td style="padding:2px 1px;font-size:9px;color:#768d99;text-align:center;white-space:nowrap">'.
				self::e($label).'<br><b style="color:#4f5b63">'.$bucket['open'].'</b></td>';
		}

		$names = ['new_prev' => I18n::t('New in the previous period'), 'new' => I18n::t('New'),
			'resolved' => I18n::t('Resolved'), 'open' => I18n::t('Open at the end (number under the column)')
		];
		$legend = '';

		foreach ($colors as $key => $color) {
			$legend .= '<span style="display:inline-block;width:10px;height:10px;background:#'.$color.
				';margin:0 4px 0 10px"></span>'.self::e($names[$key]);
		}

		$titles = ['hour' => 'Dynamics by hour', 'day' => 'Dynamics by day', 'week' => 'Dynamics by week'];

		return self::title(I18n::t($titles[$ov['unit']])).
			'<div style="margin-bottom:4px;font-size:11px;color:#4f5b63">'.$legend.'</div>'.
			'<table style="width:100%;margin-bottom:12px;border-bottom:1px solid #dfe4e7;table-layout:fixed;border-collapse:collapse">'.
			'<tr style="height:'.($height + 4).'px">'.$bars.'</tr><tr>'.$labels.'</tr></table>';
	}

	/**
	 * @param bool $with_open  Include the "Open now" list (the "Changes" tab export; a problem report has its own list).
	 */
	public static function renderSection(array $changes, array $meta, bool $with_open = false): string {
		$label = I18n::t(self::GROUPING_LABELS[$changes['grouping']]);
		$html = '<h3 style="margin:14px 0 4px 0">'.self::e(I18n::t('Changes')).': '.self::e(self::periodText($changes)).'</h3>';

		if (isset($changes['overview'])) {
			$html .= self::overviewHtml($changes, $meta, $with_open).
				'<h3 style="margin:16px 0 4px 0">'.self::e(I18n::t('Detailed analysis of changes')).'</h3>';
		}

		$html .= '<div style="margin-bottom:8px">'.self::e(self::totalsText($changes)).'</div>';

		// 1. Previous -> current.
		$html .= self::title(self::compareTitle($changes['grouping']));
		$rows = [];

		foreach ($changes['compare'] as $row) {
			$rows[] = [self::e($row['name']), $row['prev'], $row['cur'], self::deltaHtml($row['delta'], $row['delta_pct']),
				self::duration($row['mttr_prev']), self::duration($row['mttr_cur']),
				self::colored(self::durationDelta($row['mttr_prev'], $row['mttr_cur']),
					$row['mttr_prev'] !== null && $row['mttr_cur'] !== null ? $row['mttr_cur'] - $row['mttr_prev'] : 0
				)
			];
		}

		$html .= self::table([$label, 'New (prev)', 'New (now)', 'Change', 'MTTR (prev)', 'MTTR (now)', 'Change'],
			$rows, 'No problems in either period.'
		);

		if ($changes['compare_total'] > count($changes['compare'])) {
			$html .= self::note(I18n::s('Top %1$s of %2$s.', count($changes['compare']), $changes['compare_total']));
		}

		// 2. Growth / decline.
		$movers = [];

		foreach (['growth' => 'Biggest growth', 'decline' => 'Biggest decline'] as $key => $title) {
			$rows = [];

			foreach ($changes[$key] as $row) {
				$rows[] = [self::e($row['name']), $row['prev'], $row['cur'], self::deltaHtml($row['delta'], $row['delta_pct'])];
			}

			$movers[] = self::title(I18n::t($title)).self::table([$label, 'Was', 'Now', 'Change'], $rows, 'None.');
		}

		$html .= self::twoColumns($movers[0], $movers[1]);

		// 3. Appeared / disappeared triggers.
		$lists = [];

		foreach (['appeared' => ['New problems (not in the previous period)', 'First'],
				'disappeared' => ['Disappeared problems (no longer occur)', 'Last']] as $key => [$title, $time_label]) {
			$rows = [];

			foreach ($changes[$key]['list'] as $trigger) {
				$rows[] = [self::severityCell($trigger['severity'], $meta), self::e($trigger['hosts']), self::e($trigger['name']),
					$trigger['count'], date('Y-m-d H:i', $key === 'appeared' ? $trigger['first'] : $trigger['last'])
				];
			}

			$lists[] = self::title(I18n::t($title).': '.$changes[$key]['total']).
				self::table(['Severity', 'Host', 'Problem', 'Times', $time_label], $rows, 'None.');
		}

		$html .= self::twoColumns($lists[0], $lists[1]);

		// 4. MTTR by severity.
		$rows = [];
		$max = 1;

		foreach ($changes['mttr_by_severity'] as $row) {
			$max = max($max, (int) $row['cur'], (int) $row['prev']);
		}

		foreach ($changes['mttr_by_severity'] as $severity => $row) {
			if ($row['cur_count'] == 0 && $row['prev_count'] == 0) {
				continue;
			}

			$rows[] = [self::severityCell($severity, $meta),
				self::bar((int) $row['prev'], $max, '9aa7b0', self::duration($row['prev']).' ('.$row['prev_count'].')'),
				self::bar((int) $row['cur'], $max, '4796c4', self::duration($row['cur']).' ('.$row['cur_count'].')'),
				self::colored(self::durationDelta($row['prev'], $row['cur']),
					$row['prev'] !== null && $row['cur'] !== null ? $row['cur'] - $row['prev'] : 0
				)
			];
		}

		$html .= self::title(I18n::t('Median time to resolve by severity (number of resolved problems)')).
			self::table(['Severity', 'Previous period', 'Current period', 'Change'], $rows, 'No resolved problems.');

		// 5. Heatmap.
		$html .= self::title(I18n::t('When problems start: day of week x hour (current period)')).self::heatmapHtml($changes['heatmap']);

		// 6. Noisy triggers.
		$rows = [];

		foreach ($changes['noisy'] as $trigger) {
			$rows[] = [self::severityCell($trigger['severity'], $meta), self::e($trigger['hosts']), self::e($trigger['name']),
				$trigger['count'], $trigger['short'].' ('.$trigger['short_pct'].'%)', self::duration($trigger['median']),
				$trigger['prev']
			];
		}

		$html .= self::title(I18n::s('Noisy triggers: frequent or short-lived (< %1$s min) problems',
				ChangeAnalytics::SHORT_PROBLEM / 60
			)).
			self::table(['Severity', 'Host', 'Trigger', 'Problems', 'Short-lived', 'Median duration', 'Previous period'],
				$rows, 'No noisy triggers.'
			);

		// 7. Time in problem.
		$rows = [];

		foreach ($changes['problem_time'] as $row) {
			$rows[] = [self::e($row['name']),
				self::bar((int) round($row['prev_pct'] * 10), 1000, '9aa7b0', self::duration($row['prev']).' ('.$row['prev_pct'].'%)'),
				self::bar((int) round($row['cur_pct'] * 10), 1000, 'e45959', self::duration($row['cur']).' ('.$row['cur_pct'].'%)'),
				self::colored(self::durationDelta($row['prev'], $row['cur']), $row['cur'] - $row['prev'])
			];
		}

		$html .= self::title(I18n::t('Time with an open High or Disaster problem (% of the period)')).
			self::table([$changes['grouping'] === ChangeAnalytics::GROUP_BY_HOST ? 'Host' : 'Host group', 'Previous period',
				'Current period', 'Change'], $rows, 'No High or Disaster problems.'
			);

		if ($changes['truncated']) {
			$html .= '<p style="color:#e45959">'.self::e(I18n::t('The analysis is limited by "analytics_limit": the numbers '.
				'may be lower than in reality.')).'</p>';
		}

		return $html;
	}

	public static function renderHtml(array $changes, array $meta, string $title, bool $print = false): string {
		return '<!DOCTYPE html><html lang="'.I18n::lang().'"><head><meta charset="UTF-8"><title>'.self::e($title).'</title><style>'.
			'@page{size:A4 landscape;margin:12mm}@media print{tr{page-break-inside:avoid}}'.
			'body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#1f2c33;margin:16px}'.
			'table{border-collapse:collapse}</style></head><body><h2 style="margin:0 0 4px 0">'.self::e($title).'</h2>'.
			self::renderSection($changes, $meta, true).
			($print ? '<script>window.addEventListener("load",function(){window.print();});</script>' : '').
			'</body></html>';
	}

	public static function renderCsv(array $changes, array $meta): string {
		$fh = fopen('php://temp', 'r+');
		fwrite($fh, "\xEF\xBB\xBF");
		$put = static function (array $row) use ($fh): void {
			fputcsv($fh, array_map([ReportRenderer::class, 'csvCell'], $row), ',', '"', '');
		};
		// Header rows and section names are translated, data rows are not.
		$head = static function (array $row) use ($put): void {
			$put(array_map(static function ($cell) {
				return is_string($cell) ? I18n::t($cell) : $cell;
			}, $row));
		};
		$label = self::GROUPING_LABELS[$changes['grouping']];

		$put([I18n::t('Changes'), self::periodText($changes)]);

		// The overview first: verdict, key numbers, open now, resolved in the period.
		if (isset($changes['overview'])) {
			$ov = $changes['overview'];
			[$title, $sentence, $reasons] = self::verdictTexts($ov);
			$put([$title]);
			$put([$sentence]);

			if ($reasons) {
				$put([implode('; ', $reasons)]);
			}

			$put([]);
			$head(['Open now', 'at the start', 'New in the period', 'was', 'Resolved in the period', 'was',
				'MTTR now (s)', 'MTTR prev (s)'
			]);
			$put([$ov['open_end'], $ov['open_start'], $ov['new'], $ov['new_prev'], $ov['resolved'], $ov['resolved_prev'],
				$ov['mttr'], $ov['mttr_prev']
			]);
			$put([]);
			$head(['Section', 'Severity', 'Host', 'Problem', 'Open since', 'Duration (s)', 'Open the whole period',
				'Occurred', 'Acknowledged'
			]);

			foreach ($ov['open_now'] as $row) {
				$put([I18n::t('Open now'), $meta[$row['severity']]['name'], $row['hosts'], $row['name'],
					date('Y-m-d H:i', $row['since']), $row['age'], I18n::t($row['before_period'] ? 'Yes' : 'No'),
					$row['occurrences'], I18n::t($row['acknowledged'] ? 'Yes' : 'No')
				]);
			}

			$put([]);
			$head(['Section', 'Severity', 'Host', 'Problem', 'Times', 'Time in problem (s)', 'First', 'Last']);

			foreach ($ov['closed'] as $row) {
				$put([I18n::t('Resolved in the period'), $meta[$row['severity']]['name'], $row['hosts'], $row['name'],
					$row['count'], $row['time'], date('Y-m-d H:i', $row['first']),
					date('Y-m-d H:i', $row['last']).' - '.date('Y-m-d H:i', $row['last_resolved'])
				]);
			}

			$put([]);
		}

		$put([self::totalsText($changes)]);
		$put([]);
		$head(['Section', $label, 'New (prev)', 'New (now)', 'Change', 'MTTR prev (s)', 'MTTR now (s)']);

		foreach ($changes['compare'] as $row) {
			$put([I18n::t('Previous -> current'), $row['name'], $row['prev'], $row['cur'], $row['delta'], $row['mttr_prev'],
				$row['mttr_cur']
			]);
		}

		foreach (['growth' => 'Growth', 'decline' => 'Decline'] as $key => $section) {
			foreach ($changes[$key] as $row) {
				$put([I18n::t($section), $row['name'], $row['prev'], $row['cur'], $row['delta']]);
			}
		}

		$put([]);
		$head(['Section', 'Severity', 'Host', 'Problem', 'Times', 'First/last']);

		foreach (['appeared' => 'Appeared', 'disappeared' => 'Disappeared'] as $key => $section) {
			foreach ($changes[$key]['list'] as $t) {
				$put([I18n::t($section), $meta[$t['severity']]['name'], $t['hosts'], $t['name'], $t['count'],
					date('Y-m-d H:i', $key === 'appeared' ? $t['first'] : $t['last'])
				]);
			}
		}

		$put([]);
		$head(['MTTR by severity', 'Severity', 'Previous (s)', 'Resolved (prev)', 'Current (s)', 'Resolved (now)']);

		foreach ($changes['mttr_by_severity'] as $severity => $row) {
			$put([I18n::t('MTTR by severity'), $meta[$severity]['name'], $row['prev'], $row['prev_count'], $row['cur'],
				$row['cur_count']
			]);
		}

		$put([]);
		$head(array_merge(['Heatmap', 'Day'], range(0, 23)));

		foreach ($changes['heatmap']['cells'] as $day => $hours) {
			$put(array_merge([I18n::t('Heatmap'), I18n::t(ScheduleHelper::WEEKDAYS[$day])], $hours));
		}

		$put([]);
		$head(['Noisy triggers', 'Severity', 'Host', 'Trigger', 'Problems', 'Short-lived', 'Median (s)', 'Previous']);

		foreach ($changes['noisy'] as $t) {
			$put([I18n::t('Noisy triggers'), $meta[$t['severity']]['name'], $t['hosts'], $t['name'], $t['count'], $t['short'],
				$t['median'], $t['prev']
			]);
		}

		$put([]);
		$head(['Time in problem', 'Name', 'Previous (s)', 'Previous %', 'Current (s)', 'Current %']);

		foreach ($changes['problem_time'] as $row) {
			$put([I18n::t('Time in problem'), $row['name'], $row['prev'], $row['prev_pct'], $row['cur'], $row['cur_pct']]);
		}

		rewind($fh);
		$csv = stream_get_contents($fh);
		fclose($fh);

		return $csv;
	}

	/**
	 * A few lines for the short summary (Telegram).
	 */
	public static function renderText(array $changes): array {
		$lines = ["\u{1F504} ".I18n::t('Changes vs previous period')];

		if (isset($changes['overview'])) {
			[$title, $sentence, $reasons] = self::verdictTexts($changes['overview']);
			$lines[0] .= ': '.$title;
			$lines[] = $sentence;

			if ($reasons) {
				$lines[] = implode('; ', $reasons).'.';
			}
		}

		$lines[] = self::totalsText($changes);
		$names = static function (array $rows): string {
			return implode(', ', array_map(static function (array $row): string {
				return $row['name'].' '.sprintf('%+d', $row['delta']);
			}, array_slice($rows, 0, 3)));
		};

		if ($changes['growth']) {
			$lines[] = I18n::t('Growth').': '.$names($changes['growth']);
		}

		if ($changes['decline']) {
			$lines[] = I18n::t('Decline').': '.$names($changes['decline']);
		}

		$lines[] = I18n::s('New problems (not seen before): %1$s, disappeared: %2$s', $changes['appeared']['total'],
			$changes['disappeared']['total']
		);

		if ($changes['noisy']) {
			$top = $changes['noisy'][0];
			$lines[] = I18n::s('Noisiest trigger: %1$s: %2$s (%3$s problems, %4$s short-lived)', $top['hosts'], $top['name'],
				$top['count'], $top['short']
			);
		}

		return $lines;
	}

	private static function heatmapHtml(array $heatmap): string {
		$html = '<table style="margin-bottom:10px;font-size:9px"><tr><th style="padding:1px 3px"></th>';

		for ($hour = 0; $hour < 24; $hour++) {
			$html .= '<th style="padding:1px 2px;color:#768d99;font-weight:normal;width:22px">'.sprintf('%02d', $hour).'</th>';
		}

		$html .= '</tr>';

		foreach ($heatmap['cells'] as $day => $hours) {
			$html .= '<tr><td style="padding:1px 4px;color:#768d99;white-space:nowrap">'.
				self::e(I18n::t(substr(ScheduleHelper::WEEKDAYS[$day], 0, 3))).'</td>';

			foreach ($hours as $count) {
				$html .= '<td style="padding:2px;text-align:center;border:1px solid #fff;background:#'.
					self::heatColor($count, $heatmap['max']).';color:'.($count > 0 && $count * 2 > $heatmap['max'] ? '#fff' : '#4f5b63').
					'">'.($count > 0 ? $count : '').'</td>';
			}

			$html .= '</tr>';
		}

		return $html.'</table>';
	}

	/**
	 * Color between light grey (0) and red (max), as hex for e-mail clients.
	 */
	public static function heatColor(int $count, int $max): string {
		if ($count == 0 || $max == 0) {
			return 'f2f4f5';
		}

		$ratio = 0.15 + 0.85 * $count / $max;
		$from = [0xfb, 0xe3, 0xe3];
		$to = [0xd6, 0x3b, 0x3b];
		$rgb = [];

		foreach ($from as $i => $value) {
			$rgb[] = (int) round($value + ($to[$i] - $value) * $ratio);
		}

		return vsprintf('%02x%02x%02x', $rgb);
	}

	private static function table(array $headers, array $rows, string $empty): string {
		if (!$rows) {
			return '<div style="margin-bottom:10px;color:#768d99">'.self::e(I18n::t($empty)).'</div>';
		}

		$html = '<table style="margin-bottom:10px"><tr>';

		foreach ($headers as $header) {
			$html .= '<th style="'.self::HEAD.'">'.self::e(I18n::t($header)).'</th>';
		}

		$html .= '</tr>';

		foreach ($rows as $row) {
			$html .= '<tr>';

			foreach ($row as $cell) {
				$html .= strncmp((string) $cell, '<td', 3) == 0 ? $cell : '<td style="'.self::CELL.'">'.$cell.'</td>';
			}

			$html .= '</tr>';
		}

		return $html.'</table>';
	}

	private static function twoColumns(string $left, string $right): string {
		return '<table style="width:100%"><tr><td style="vertical-align:top;padding:0 12px 0 0;width:50%">'.$left.
			'</td><td style="vertical-align:top;padding:0;width:50%">'.$right.'</td></tr></table>';
	}

	private static function title(string $text): string {
		return '<h4 style="margin:10px 0 4px 0">'.self::e($text).'</h4>';
	}

	private static function note(string $text): string {
		return '<div style="margin:-6px 0 10px 0;color:#768d99">'.self::e($text).'</div>';
	}

	private static function severityCell(int $severity, array $meta): string {
		return '<td style="'.self::CELL.'background:#'.self::e($meta[$severity]['color']).'">'.
			self::e($meta[$severity]['name']).'</td>';
	}

	/**
	 * More problems / longer resolution is worse (red), less is better (green).
	 */
	private static function deltaHtml(int $delta, ?int $pct): string {
		return self::colored(self::deltaText($delta, $pct), $delta);
	}

	private static function colored(string $text, int $sign): string {
		if ($text === '' || $sign == 0) {
			return self::e($text);
		}

		return '<span style="color:'.($sign > 0 ? '#e45959' : '#429e47').'">'.($sign > 0 ? "\u{25B2} " : "\u{25BC} ").
			self::e($text).'</span>';
	}

	private static function bar(int $value, int $max, string $color, string $label): string {
		$width = $max > 0 ? (int) round($value * 120 / $max) : 0;

		return '<div style="white-space:nowrap"><span style="display:inline-block;vertical-align:middle;height:10px;width:'.
			max($value > 0 ? 2 : 0, $width).'px;background:#'.$color.';margin-right:6px"></span>'.self::e($label).'</div>';
	}

	private static function e(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
