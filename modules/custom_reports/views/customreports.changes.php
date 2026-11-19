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

// Summary.
$cur = $changes['totals']['cur'];
$prev = $changes['totals']['prev'];
$summary = (new CDiv([
	(new CDiv(cr_s('%1$s - %2$s compared with %3$s - %4$s', zbx_date2str(DATE_TIME_FORMAT, $changes['from']),
		zbx_date2str(DATE_TIME_FORMAT, $changes['till']), zbx_date2str(DATE_TIME_FORMAT, $changes['prev_from']),
		zbx_date2str(DATE_TIME_FORMAT, $changes['from'])
	)))->addClass('cr-changes-period'),
	(new CDiv([
		cr_s('New problems: %1$s (previous: %2$s)', $cur['new'], $prev['new']), ' ',
		$delta($cur['new'] - $prev['new'], $prev['new'] > 0
			? (int) round(($cur['new'] - $prev['new']) * 100 / $prev['new'])
			: null
		),
		'; ',
		cr_s('median time to resolve: %1$s (previous: %2$s)', ChangeRenderer::duration($cur['mttr']),
			ChangeRenderer::duration($prev['mttr'])
		), ' ', $duration_delta($prev['mttr'], $cur['mttr'])
	]))
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

$page->addItem($summary)->addItem($grid);

if ($changes['truncated']) {
	$page->addItem((new CDiv(cr_t('The analysis is limited by "analytics_limit": the numbers may be lower than in reality.')))
		->addClass(ZBX_STYLE_RED)
	);
}

$page->show();
