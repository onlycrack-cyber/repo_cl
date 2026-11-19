<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


/**
 * "Changes" tab: the selected period compared with the previous one.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\CustomReports\Lib\ChangeAnalytics;
use Modules\CustomReports\Lib\ChangeRenderer;
use Modules\CustomReports\Lib\FrontendHelper;
use Modules\CustomReports\Lib\PeriodAnalytics;
use Modules\CustomReports\Lib\SvgChart;

$this->addJsFile('multiselect.js');
$this->addJsFile('class.calendar.js');

$filter = $data['filter'];
$changes = $data['changes'];
$meta = $data['meta'];

$grouping_labels = [
	ChangeAnalytics::GROUP_BY_GROUP => cr_t('Host groups'),
	ChangeAnalytics::GROUP_BY_HOST => cr_t('Hosts'),
	ChangeAnalytics::GROUP_BY_TRIGGER => cr_t('Triggers')
];
$name_header = [
	ChangeAnalytics::GROUP_BY_GROUP => cr_t('Host group'),
	ChangeAnalytics::GROUP_BY_HOST => cr_t('Host'),
	ChangeAnalytics::GROUP_BY_TRIGGER => cr_t('Trigger')
][$filter['grouping']];

// Filter.
$left = (new CFormList())
	->addRow(new CLabel(cr_t('Host groups'), 'filter_groupids__ms'),
		(new CMultiSelect([
			'name' => 'filter_groupids[]',
			'object_name' => 'hostGroup',
			'data' => $data['filter_groups'],
			'popup' => ['parameters' => ['srctbl' => 'host_groups', 'srcfld1' => 'groupid', 'dstfrm' => 'zbx_filter',
				'dstfld1' => 'filter_groupids_', 'with_hosts' => true, 'enrich_parent_groups' => true
			]]
		]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
	)
	->addRow(new CLabel(cr_t('Hosts'), 'filter_hostids__ms'),
		(new CMultiSelect([
			'name' => 'filter_hostids[]',
			'object_name' => 'hosts',
			'data' => $data['filter_hosts'],
			'popup' => [
				'filter_preselect' => ['id' => 'filter_groupids_', 'submit_as' => 'groupid'],
				'parameters' => ['srctbl' => 'hosts', 'srcfld1' => 'hostid', 'dstfrm' => 'zbx_filter',
					'dstfld1' => 'filter_hostids_'
				]
			]
		]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
	)
	->addRow(cr_t('Problem'),
		(new CTextBox('filter_problem_name', $filter['problem_name']))
			->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
			->setAttribute('placeholder', cr_t('part of the problem name'))
	);

$right = (new CFormList())
	->addRow(cr_t('Severity'),
		(new CCheckBoxList('filter_severities'))
			->setOptions(FrontendHelper::getSeverities())
			->setChecked($filter['severities'])
			->setColumns(3)
			->setVertical()
	)
	->addRow(cr_t('Period'),
		(new CRadioButtonList('filter_period', $filter['period']))
			->addValue(cr_t('24 hours'), PeriodAnalytics::PERIOD_24H)
			->addValue(cr_t('7 days'), PeriodAnalytics::PERIOD_7D)
			->addValue(cr_t('30 days'), PeriodAnalytics::PERIOD_30D)
			->addValue(cr_t('Custom dates'), ChangeAnalytics::PERIOD_CUSTOM)
			->setModern(true)
	)
	->addRow(cr_t('From - to'), [
		(new CDateSelector('filter_date_from', $filter['date_from']))
			->setDateFormat(ZBX_DATE)
			->setPlaceholder(cr_t('YYYY-MM-DD')),
		(new CDiv())->addClass(ZBX_STYLE_FORM_INPUT_MARGIN),
		(new CDateSelector('filter_date_to', $filter['date_to']))
			->setDateFormat(ZBX_DATE)
			->setPlaceholder(cr_t('YYYY-MM-DD'))
	], 'cr-changes-dates')
	->addRow(cr_t('Group by'),
		(new CRadioButtonList('filter_grouping', $filter['grouping']))
			->addValue(cr_t('Host groups'), ChangeAnalytics::GROUP_BY_GROUP)
			->addValue(cr_t('Hosts'), ChangeAnalytics::GROUP_BY_HOST)
			->addValue(cr_t('Triggers'), ChangeAnalytics::GROUP_BY_TRIGGER)
			->setModern(true)
	);

$filter_form = (new CFilter())
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'customreports.changes'))
	->setProfile('web.customreports.changes.filter')
	->setActiveTab(CProfile::get('web.customreports.changes.filter.active', 1))
	->addVar('action', 'customreports.changes')
	->addFilterTab(cr_t('Filter'), [$left, $right]);

// Export.
$export_url = (new CUrl('zabbix.php'))->setArgument('action', 'customreports.changes.export');
$controls = (new CList())
	->addItem((new CRedirectButton(cr_t('Download CSV'), (clone $export_url)->setArgument('format', 'csv')->getUrl()))
		->addClass(ZBX_STYLE_BTN_ALT)
	)
	->addItem((new CRedirectButton(cr_t('Download HTML'), (clone $export_url)->setArgument('format', 'html')->getUrl()))
		->addClass(ZBX_STYLE_BTN_ALT)
	)
	->addItem((new CSimpleButton(cr_t('Print')))
		->addClass(ZBX_STYLE_BTN_ALT)
		->setAttribute('data-cr-open', (clone $export_url)->setArgument('format', 'print')->getUrl())
	);

if ($data['pdf_available']) {
	$controls->addItem(
		(new CRedirectButton(cr_t('Download PDF'), (clone $export_url)->setArgument('format', 'pdf')->getUrl()))
			->addClass(ZBX_STYLE_BTN_ALT)
	);
}

$page = (new CHtmlPage())
	->setTitle(cr_t('Custom Problem Reports'))
	->setControls((new CTag('nav', true, $controls))->setAttribute('aria-label', cr_t('Content controls')))
	->addItem(FrontendHelper::makeTabs('customreports.changes', $data['is_super_admin']))
	->addItem($filter_form);

if ($data['error'] !== null) {
	$page->addItem(makeMessageBox($changes === null ? ZBX_STYLE_MSG_BAD : ZBX_STYLE_MSG_WARNING,
		[['message' => $data['error']]], $changes === null ? cr_t('Cannot load data') : cr_t('Check the period'), true, false
	));
}

if ($changes === null) {
	$page->show();

	return;
}

// Helpers: more problems or longer resolution is worse (red), less is better (green).
$delta = static function (int $value, ?int $pct = null) {
	if ($value == 0) {
		return (new CSpan('0'))->addClass(ZBX_STYLE_GREY);
	}

	return (new CSpan(($value > 0 ? "\u{25B2} " : "\u{25BC} ").ChangeRenderer::deltaText($value, $pct)))
		->addClass($value > 0 ? ZBX_STYLE_RED : ZBX_STYLE_GREEN);
};
$duration_delta = static function (?int $prev, ?int $cur) {
	$text = ChangeRenderer::durationDelta($prev, $cur);

	return $text === ''
		? ''
		: (new CSpan(($cur > $prev ? "\u{25B2} " : "\u{25BC} ").$text))->addClass($cur > $prev ? ZBX_STYLE_RED : ZBX_STYLE_GREEN);
};
$bar = static function (float $ratio, string $class, string $label) {
	return (new CDiv([
		(new CDiv())->addClass('cr-hbar '.$class)->addStyle('width: '.max(0, min(100, round($ratio * 100))).'%;'),
		(new CSpan($label))->addClass('cr-hbar-label')
	]))->addClass('cr-hbar-box');
};
$panel = static function (string $title, $body, string $hint = '', bool $wide = false) {
	return (new CDiv([
		new CTag('h4', true, $title),
		$hint !== '' ? (new CDiv($hint))->addClass('cr-note') : null,
		$body
	]))->addClass('cr-panel'.($wide ? ' cr-panel-wide' : ''));
};
$severity = static function (int $value) {
	return CSeverityHelper::makeSeverityCell($value);
};

// Overview: what is open now, what happened in the period, the trend and the verdict.
$ov = $changes['overview'];
$cur = $changes['totals']['cur'];
$prev = $changes['totals']['prev'];
$pct = static function (int $now, int $was): ?int {
	return $was > 0 ? (int) round(($now - $was) * 100 / $was) : null;
};

$period_line = (new CDiv(cr_s('%1$s - %2$s compared with %3$s - %4$s', zbx_date2str(DATE_TIME_FORMAT, $changes['from']),
	zbx_date2str(DATE_TIME_FORMAT, $changes['till']), zbx_date2str(DATE_TIME_FORMAT, $changes['prev_from']),
	zbx_date2str(DATE_TIME_FORMAT, $changes['from'])
)))->addClass('cr-changes-period');

// Verdict with its reasons (the same words as in e-mails and PDF).
[$verdict_title, $verdict_sentence, $reasons] = ChangeRenderer::verdictTexts($ov);

$verdict = (new CDiv([
	(new CDiv($verdict_title))->addClass('cr-verdict-title'),
	new CDiv($verdict_sentence),
	$reasons ? (new CDiv(implode('; ', $reasons).'.'))->addClass('cr-verdict-reasons') : null
]))->addClass('cr-verdict cr-verdict-'.$ov['verdict']['status']);

// KPI cards: the value, what it was, the change (more problems / slower is red).
$card = static function (string $label, string $value, $note = null, string $class = '') {
	return (new CDiv([
		(new CDiv($value))->addClass('cr-kpi-value'),
		(new CDiv($label))->addClass('cr-kpi-label'),
		$note !== null ? (new CDiv($note))->addClass('cr-kpi-note') : null
	]))->addClass('cr-kpi-card '.$class);
};

$kpi = (new CDiv([
	$card(cr_t('Open now'), (string) $ov['open_end'], [
		cr_s('at the start: %1$s', $ov['open_start']), ' ', $delta($ov['open_end'] - $ov['open_start'])
	], $ov['open_end'] > 0 ? 'cr-kpi-open' : ''),
	$card(cr_t('High and Disaster open'), (string) $ov['open_high'], null, $ov['open_high'] > 0 ? 'cr-kpi-alert' : ''),
	$card(cr_t('New in the period'), (string) $ov['new'], [
		cr_s('was: %1$s', $ov['new_prev']), ' ', $delta($ov['new'] - $ov['new_prev'], $pct($ov['new'], $ov['new_prev']))
	]),
	$card(cr_t('Resolved in the period'), (string) $ov['resolved'], cr_s('was: %1$s', $ov['resolved_prev'])),
	$card(cr_t('Time to resolve (median)'), ChangeRenderer::duration($ov['mttr']), [
		cr_s('was: %1$s', ChangeRenderer::duration($ov['mttr_prev'])), ' ', $duration_delta($ov['mttr_prev'], $ov['mttr'])
	])
]))->addClass('cr-kpi');

// Dynamics: new, resolved and open at the end of every interval; the new problems of the previous period for
// comparison.
$units = ['hour' => cr_t('Dynamics by hour'), 'day' => cr_t('Dynamics by day'), 'week' => cr_t('Dynamics by week')];
$max = 1;

foreach ($ov['buckets'] as $bucket) {
	$max = max($max, $bucket['new'], $bucket['resolved'], $bucket['open'], $bucket['new_prev']);
}

$step = (int) ceil(count($ov['buckets']) / 14);
$points = [];

foreach ($ov['buckets'] as $index => $bucket) {
	$points[] = [
		'label' => $index % $step == 0 || $index == count($ov['buckets']) - 1
			? date($ov['unit'] === 'hour' ? 'H:i' : 'd.m', $bucket['start'])
			: '',
		'title' => cr_s('%1$s: new %2$s, resolved %3$s, open at the end %4$s', date('d.m H:i', $bucket['start']),
			$bucket['new'], $bucket['resolved'], $bucket['open']
		).' ('.cr_s('previous period: %1$s', $bucket['new_prev']).')',
		'new' => $bucket['new'],
		'resolved' => $bucket['resolved'],
		'open' => $bucket['open'],
		'new_prev' => $bucket['new_prev']
	];
}

$chart = new CObject(SvgChart::flow($points, [
	'open' => cr_t('Open problems'),
	'new' => cr_t('New'),
	'resolved' => cr_t('Resolved'),
	'start' => cr_t('at the start of the period')
], $ov['open_start']));

$dynamics = (new CDiv([
	new CTag('h4', true, $units[$ov['unit']]),
	(new CDiv([
		(new CSpan())->addClass('cr-legend cr-legend-line'), cr_t('Open at the end of the interval'),
		(new CSpan())->addClass('cr-legend cr-bar-new'), cr_t('New'),
		(new CSpan())->addClass('cr-legend cr-bar-resolved'), cr_t('Resolved'),
		(new CSpan())->addClass('cr-legend cr-legend-prev'), cr_t('New in the previous period'),
		(new CSpan())->addClass('cr-legend cr-legend-start'), cr_t('Open at the start of the period')
	]))->addClass('cr-note'),
	$chart
]))->addClass('cr-panel cr-panel-wide');

// Open now.
$open_table = (new CTableInfo())
	->setHeader([cr_t('Severity'), cr_t('Host'), cr_t('Problem'), cr_t('Open since'), cr_t('Duration'), cr_t('In the period'),
		cr_t('Occurred'), cr_t('Ack')
	])
	->setNoDataMessage(cr_t('No open problems.'));

foreach ($ov['open_now'] as $row) {
	$open_table->addRow([
		$severity($row['severity']),
		$row['hosts'],
		$row['name'].($row['open_count'] > 1 ? ' ('.cr_s('%1$s open', $row['open_count']).')' : ''),
		(new CCol(zbx_date2str(DATE_TIME_FORMAT, $row['since'])))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol(ChangeRenderer::duration($row['age'])))->addClass(ZBX_STYLE_NOWRAP),
		$row['before_period']
			? (new CSpan(cr_t('open the whole period')))->addClass(ZBX_STYLE_RED)
			: (new CSpan(cr_t('new')))->addClass(ZBX_STYLE_ORANGE),
		$row['occurrences'] > 0 ? $row['occurrences'] : '-',
		$row['acknowledged'] ? (new CSpan(cr_t('Yes')))->addClass(ZBX_STYLE_GREEN) : (new CSpan(cr_t('No')))->addClass(ZBX_STYLE_RED)
	]);
}

$open_panel = $panel(cr_s('Open now: %1$s', $ov['open_now_total']), $open_table,
	cr_t('Problems not resolved at the end of the period, one row per trigger. "Open the whole period" - started before the period; "Occurred" - how many times the problem started in the period.')
		.($ov['open_now_total'] > count($ov['open_now']) ? ' '.cr_s('Top %1$s of %2$s.', count($ov['open_now']), $ov['open_now_total']) : ''),
	true
);

// Resolved in the period.
$closed_table = (new CTableInfo())
	->setHeader([cr_t('Severity'), cr_t('Host'), cr_t('Problem'), cr_t('Times'), cr_t('Time in problem'), cr_t('Last time')])
	->setNoDataMessage(cr_t('No resolved problems.'));

foreach ($ov['closed'] as $row) {
	$closed_table->addRow([
		$severity($row['severity']),
		$row['hosts'],
		$row['name'],
		$row['count'],
		(new CCol(ChangeRenderer::duration($row['time'])))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol(zbx_date2str(DATE_TIME_FORMAT, $row['last']).' - '.zbx_date2str(DATE_TIME_FORMAT, $row['last_resolved'])))
			->addClass(ZBX_STYLE_NOWRAP)
	]);
}

$closed_panel = $panel(cr_s('Occurred in the period and resolved: %1$s', $ov['closed_total']), $closed_table,
	cr_t('Problems that were open during the period and are resolved by its end, one row per trigger.')
		.($ov['closed_total'] > count($ov['closed']) ? ' '.cr_s('Top %1$s of %2$s.', count($ov['closed']), $ov['closed_total']) : ''),
	true
);

$overview_block = (new CDiv([$period_line, $verdict, $kpi, (new CDiv([$dynamics, $open_panel, $closed_panel]))
	->addClass('cr-panels')
]))->addClass('cr-overview');

// Details: the comparison blocks.
$summary = (new CDiv([
	cr_s('New problems: %1$s (previous: %2$s)', $cur['new'], $prev['new']), ' ',
	$delta($cur['new'] - $prev['new'], $pct($cur['new'], $prev['new'])),
	'; ',
	cr_s('median time to resolve: %1$s (previous: %2$s)', ChangeRenderer::duration($cur['mttr']),
		ChangeRenderer::duration($prev['mttr'])
	), ' ', $duration_delta($prev['mttr'], $cur['mttr'])
]))->addClass('cr-changes-summary');

// 1. Previous -> current.
$compare = (new CTableInfo())
	->setHeader([$name_header, cr_t('New (prev)'), cr_t('New (now)'), cr_t('Change'), cr_t('MTTR (prev)'), cr_t('MTTR (now)'),
		cr_t('Change')
	])
	->setNoDataMessage(cr_t('No problems in either period.'));

foreach ($changes['compare'] as $row) {
	$compare->addRow([$row['name'], $row['prev'], $row['cur'], $delta($row['delta'], $row['delta_pct']),
		ChangeRenderer::duration($row['mttr_prev']), ChangeRenderer::duration($row['mttr_cur']),
		$duration_delta($row['mttr_prev'], $row['mttr_cur'])
	]);
}

// 2. Growth / decline.
$movers = [];

foreach (['growth' => cr_t('Biggest growth'), 'decline' => cr_t('Biggest decline')] as $key => $title) {
	$table = (new CTableInfo())
		->setHeader([$name_header, cr_t('Was'), cr_t('Now'), cr_t('Change')])
		->setNoDataMessage(cr_t('None'));

	foreach ($changes[$key] as $row) {
		$table->addRow([$row['name'], $row['prev'], $row['cur'], $delta($row['delta'], $row['delta_pct'])]);
	}

	$movers[$key] = $panel($title, $table);
}

// 3. Appeared / disappeared.
$lists = [];

foreach (['appeared' => [cr_t('New problems'), cr_t('Triggers with problems in this period and none in the previous one'), cr_t('First')],
		'disappeared' => [cr_t('Disappeared problems'), cr_t('Triggers with problems in the previous period and none now'), cr_t('Last')]]
		as $key => [$title, $hint, $time_label]) {
	$table = (new CTableInfo())
		->setHeader([cr_t('Severity'), cr_t('Host'), cr_t('Problem'), cr_t('Times'), $time_label])
		->setNoDataMessage(cr_t('None'));

	foreach ($changes[$key]['list'] as $trigger) {
		$table->addRow([$severity($trigger['severity']), $trigger['hosts'], $trigger['name'], $trigger['count'],
			(new CCol(zbx_date2str(DATE_TIME_FORMAT, $key === 'appeared' ? $trigger['first'] : $trigger['last'])))
				->addClass(ZBX_STYLE_NOWRAP)
		]);
	}

	$lists[$key] = $panel($title.': '.$changes[$key]['total'], $table, $hint);
}

// 4. MTTR by severity.
$max = 1;

foreach ($changes['mttr_by_severity'] as $row) {
	$max = max($max, (int) $row['cur'], (int) $row['prev']);
}

$mttr = (new CTableInfo())
	->setHeader([cr_t('Severity'), cr_t('Previous period'), cr_t('Current period'), cr_t('Change')])
	->setNoDataMessage(cr_t('No resolved problems.'));

foreach ($changes['mttr_by_severity'] as $sev => $row) {
	if ($row['cur_count'] == 0 && $row['prev_count'] == 0) {
		continue;
	}

	$mttr->addRow([$severity($sev),
		$bar((int) $row['prev'] / $max, 'cr-bar-open', ChangeRenderer::duration($row['prev']).' ('.$row['prev_count'].')'),
		$bar((int) $row['cur'] / $max, 'cr-bar-new', ChangeRenderer::duration($row['cur']).' ('.$row['cur_count'].')'),
		$duration_delta($row['prev'], $row['cur'])
	]);
}

// 5. Heatmap.
$heatmap = (new CTable())->addClass('cr-heatmap');
$header = [''];

for ($hour = 0; $hour < 24; $hour++) {
	$header[] = sprintf('%02d', $hour);
}

$heatmap->setHeader($header);
$weekdays = [1 => cr_t('Mon'), 2 => cr_t('Tue'), 3 => cr_t('Wed'), 4 => cr_t('Thu'), 5 => cr_t('Fri'), 6 => cr_t('Sat'), 7 => cr_t('Sun')];

foreach ($changes['heatmap']['cells'] as $day => $hours) {
	$cells = [(new CCol($weekdays[$day]))->addClass('cr-heatmap-day')];

	foreach ($hours as $hour => $count) {
		$cells[] = (new CCol($count > 0 ? $count : ''))
			->addStyle('background: #'.ChangeRenderer::heatColor($count, $changes['heatmap']['max']).';'
				.($count > 0 && $count * 2 > $changes['heatmap']['max'] ? ' color: #fff;' : '')
			)
			->setTitle(cr_s('%1$s %2$s:00 - %3$s problem(s)', $weekdays[$day], sprintf('%02d', $hour), $count));
	}

	$heatmap->addRow($cells);
}

// 6. Noisy triggers.
$noisy = (new CTableInfo())
	->setHeader([cr_t('Severity'), cr_t('Host'), cr_t('Trigger'), cr_t('Problems'), cr_t('Short-lived'), cr_t('Median duration'),
		cr_t('Previous period')
	])
	->setNoDataMessage(cr_t('No noisy triggers.'));

foreach ($changes['noisy'] as $trigger) {
	$noisy->addRow([$severity($trigger['severity']), $trigger['hosts'], $trigger['name'], $trigger['count'],
		$trigger['short'] > 0
			? (new CSpan($trigger['short'].' ('.$trigger['short_pct'].'%)'))->addClass(ZBX_STYLE_RED)
			: '0',
		ChangeRenderer::duration($trigger['median']), $trigger['prev']
	]);
}

// 7. Time in problem.
$time = (new CTableInfo())
	->setHeader([$filter['grouping'] === ChangeAnalytics::GROUP_BY_HOST ? cr_t('Host') : cr_t('Host group'),
		cr_t('Previous period'), cr_t('Current period'), cr_t('Change')
	])
	->setNoDataMessage(cr_t('No High or Disaster problems.'));

foreach ($changes['problem_time'] as $row) {
	$time->addRow([$row['name'],
		$bar($row['prev_pct'] / 100, 'cr-bar-open', ChangeRenderer::duration($row['prev']).' ('.$row['prev_pct'].'%)'),
		$bar($row['cur_pct'] / 100, 'cr-bar-problem', ChangeRenderer::duration($row['cur']).' ('.$row['cur_pct'].'%)'),
		$duration_delta($row['prev'], $row['cur'])
	]);
}

$grid = (new CDiv([
	$panel(ChangeRenderer::compareTitle($filter['grouping']),
		$compare, $changes['compare_total'] > count($changes['compare'])
			? cr_s('Top %1$s of %2$s.', count($changes['compare']), $changes['compare_total'])
			: '',
		true
	),
	$movers['growth'],
	$movers['decline'],
	$lists['appeared'],
	$lists['disappeared'],
	$panel(cr_t('Median time to resolve by severity'), $mttr, cr_t('Number of resolved problems in brackets.')),
	$panel(cr_t('When problems start'), $heatmap, cr_t('New problems of the current period by day of week and hour.')),
	$panel(cr_t('Noisy triggers'), $noisy,
		cr_s('Triggers with frequent problems or problems resolved within %1$s minutes: candidates for hysteresis or other thresholds.',
			ChangeAnalytics::SHORT_PROBLEM / 60
		),
		true
	),
	$panel(cr_t('Time in problem'), $time,
		cr_t('Time with at least one open High or Disaster problem, overlapping problems counted once.'), true
	)
]))->addClass('cr-panels');

$details = (new CTag('details', true, [
	new CTag('summary', true, cr_t('Detailed analysis of changes')),
	(new CDiv(cr_t('Comparison by host group / host / trigger, growth and decline, new and disappeared problems, MTTR by severity, when problems start, noisy triggers, time in problem.')))
		->addClass('cr-note'),
	$summary,
	$grid
]))
	->addClass('cr-details')
	->setId('cr-changes-details');

$page->addItem($overview_block)->addItem($details);

if ($changes['truncated']) {
	$page->addItem((new CDiv(cr_t('The analysis is limited by "analytics_limit": the numbers may be lower than in reality.')))
		->addClass(ZBX_STYLE_RED)
	);
}

$page->show();
