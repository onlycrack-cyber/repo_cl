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
	public static function renderSection(array $changes, array $meta): string {
		$label = I18n::t(self::GROUPING_LABELS[$changes['grouping']]);
		$html = '<h3 style="margin:14px 0 4px 0">'.self::e(I18n::t('Changes')).': '.self::e(self::periodText($changes)).'</h3>'.
			'<div style="margin-bottom:8px">'.self::e(self::totalsText($changes)).'</div>';

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
			self::renderSection($changes, $meta).
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
		$lines = ["\u{1F504} ".I18n::t('Changes vs previous period'), self::totalsText($changes)];
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
