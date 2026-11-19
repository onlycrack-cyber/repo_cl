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
	ChangeAnalytics::GROUP_BY_GROUP => _('Host groups'),
	ChangeAnalytics::GROUP_BY_HOST => _('Hosts'),
	ChangeAnalytics::GROUP_BY_TRIGGER => _('Triggers')
];
$name_header = [
	ChangeAnalytics::GROUP_BY_GROUP => _('Host group'),
	ChangeAnalytics::GROUP_BY_HOST => _('Host'),
	ChangeAnalytics::GROUP_BY_TRIGGER => _('Trigger')
][$filter['grouping']];

// Filter.
$left = (new CFormList())
	->addRow(new CLabel(_('Host groups'), 'filter_groupids__ms'),
		(new CMultiSelect([
			'name' => 'filter_groupids[]',
			'object_name' => 'hostGroup',
			'data' => $data['filter_groups'],
			'popup' => ['parameters' => ['srctbl' => 'host_groups', 'srcfld1' => 'groupid', 'dstfrm' => 'zbx_filter',
				'dstfld1' => 'filter_groupids_', 'with_hosts' => true, 'enrich_parent_groups' => true
			]]
		]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
	)
	->addRow(new CLabel(_('Hosts'), 'filter_hostids__ms'),
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
	->addRow(_('Problem'),
		(new CTextBox('filter_problem_name', $filter['problem_name']))
			->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
			->setAttribute('placeholder', _('part of the problem name'))
	);

$right = (new CFormList())
	->addRow(_('Severity'),
		(new CCheckBoxList('filter_severities'))
			->setOptions(CSeverityHelper::getSeverities())
			->setChecked($filter['severities'])
			->setColumns(3)
			->setVertical()
	)
	->addRow(_('Period'),
		(new CRadioButtonList('filter_period', $filter['period']))
			->addValue(_('24 hours'), PeriodAnalytics::PERIOD_24H)
			->addValue(_('7 days'), PeriodAnalytics::PERIOD_7D)
			->addValue(_('30 days'), PeriodAnalytics::PERIOD_30D)
			->addValue(_('Custom dates'), ChangeAnalytics::PERIOD_CUSTOM)
			->setModern(true)
	)
	->addRow(_('From - to'), [
		(new CDateSelector('filter_date_from', $filter['date_from']))
			->setDateFormat(ZBX_DATE)
			->setPlaceholder(_('YYYY-MM-DD')),
		(new CDiv())->addClass(ZBX_STYLE_FORM_INPUT_MARGIN),
		(new CDateSelector('filter_date_to', $filter['date_to']))
			->setDateFormat(ZBX_DATE)
			->setPlaceholder(_('YYYY-MM-DD'))
	], 'cr-changes-dates')
	->addRow(_('Group by'),
		(new CRadioButtonList('filter_grouping', $filter['grouping']))
			->addValue(_('Host groups'), ChangeAnalytics::GROUP_BY_GROUP)
			->addValue(_('Hosts'), ChangeAnalytics::GROUP_BY_HOST)
			->addValue(_('Triggers'), ChangeAnalytics::GROUP_BY_TRIGGER)
			->setModern(true)
	);

$filter_form = (new CFilter())
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'customreports.changes'))
	->setProfile('web.customreports.changes.filter')
	->setActiveTab(CProfile::get('web.customreports.changes.filter.active', 1))
	->addVar('action', 'customreports.changes')
	->addFilterTab(_('Filter'), [$left, $right]);

// Export.
$export_url = (new CUrl('zabbix.php'))->setArgument('action', 'customreports.changes.export');
$controls = (new CList())
	->addItem((new CRedirectButton(_('Download CSV'), (clone $export_url)->setArgument('format', 'csv')->getUrl()))
		->addClass(ZBX_STYLE_BTN_ALT)
	)
	->addItem((new CRedirectButton(_('Download HTML'), (clone $export_url)->setArgument('format', 'html')->getUrl()))
		->addClass(ZBX_STYLE_BTN_ALT)
	)
	->addItem((new CSimpleButton(_('Print')))
		->addClass(ZBX_STYLE_BTN_ALT)
		->setAttribute('data-cr-open', (clone $export_url)->setArgument('format', 'print')->getUrl())
	);

if ($data['pdf_available']) {
	$controls->addItem(
		(new CRedirectButton(_('Download PDF'), (clone $export_url)->setArgument('format', 'pdf')->getUrl()))
			->addClass(ZBX_STYLE_BTN_ALT)
	);
}

$page = (new CHtmlPage())
	->setTitle(_('Custom Problem Reports'))
	->setControls((new CTag('nav', true, $controls))->setAttribute('aria-label', _('Content controls')))
	->addItem(FrontendHelper::makeTabs('customreports.changes', $data['is_super_admin']))
	->addItem($filter_form);

if ($data['error'] !== null) {
	$page->addItem(makeMessageBox($changes === null ? ZBX_STYLE_MSG_BAD : ZBX_STYLE_MSG_WARNING,
		[['message' => $data['error']]], $changes === null ? _('Cannot load data') : _('Check the period'), true, false
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
	(new CDiv(_s('%1$s - %2$s compared with %3$s - %4$s', zbx_date2str(DATE_TIME_FORMAT, $changes['from']),
		zbx_date2str(DATE_TIME_FORMAT, $changes['till']), zbx_date2str(DATE_TIME_FORMAT, $changes['prev_from']),
		zbx_date2str(DATE_TIME_FORMAT, $changes['from'])
	)))->addClass('cr-changes-period'),
	(new CDiv([
		_s('New problems: %1$s (previous: %2$s)', $cur['new'], $prev['new']), ' ',
		$delta($cur['new'] - $prev['new'], $prev['new'] > 0
			? (int) round(($cur['new'] - $prev['new']) * 100 / $prev['new'])
			: null
		),
		'; ',
		_s('median time to resolve: %1$s (previous: %2$s)', ChangeRenderer::duration($cur['mttr']),
			ChangeRenderer::duration($prev['mttr'])
		), ' ', $duration_delta($prev['mttr'], $cur['mttr'])
	]))
]))->addClass('cr-changes-summary');

// 1. Previous -> current.
$compare = (new CTableInfo())
	->setHeader([$name_header, _('New (prev)'), _('New (now)'), _('Change'), _('MTTR (prev)'), _('MTTR (now)'),
		_('Change')
	])
	->setNoDataMessage(_('No problems in either period.'));

foreach ($changes['compare'] as $row) {
	$compare->addRow([$row['name'], $row['prev'], $row['cur'], $delta($row['delta'], $row['delta_pct']),
		ChangeRenderer::duration($row['mttr_prev']), ChangeRenderer::duration($row['mttr_cur']),
		$duration_delta($row['mttr_prev'], $row['mttr_cur'])
	]);
}

// 2. Growth / decline.
$movers = [];

foreach (['growth' => _('Biggest growth'), 'decline' => _('Biggest decline')] as $key => $title) {
	$table = (new CTableInfo())
		->setHeader([$name_header, _('Was'), _('Now'), _('Change')])
		->setNoDataMessage(_('None'));

	foreach ($changes[$key] as $row) {
		$table->addRow([$row['name'], $row['prev'], $row['cur'], $delta($row['delta'], $row['delta_pct'])]);
	}

	$movers[$key] = $panel($title, $table);
}

// 3. Appeared / disappeared.
$lists = [];

foreach (['appeared' => [_('New problems'), _('Triggers with problems in this period and none in the previous one'), _('First')],
		'disappeared' => [_('Disappeared problems'), _('Triggers with problems in the previous period and none now'), _('Last')]]
		as $key => [$title, $hint, $time_label]) {
	$table = (new CTableInfo())
		->setHeader([_('Severity'), _('Host'), _('Problem'), _('Times'), $time_label])
		->setNoDataMessage(_('None'));

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
	->setHeader([_('Severity'), _('Previous period'), _('Current period'), _('Change')])
	->setNoDataMessage(_('No resolved problems.'));

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
$weekdays = [1 => _('Mon'), 2 => _('Tue'), 3 => _('Wed'), 4 => _('Thu'), 5 => _('Fri'), 6 => _('Sat'), 7 => _('Sun')];

foreach ($changes['heatmap']['cells'] as $day => $hours) {
	$cells = [(new CCol($weekdays[$day]))->addClass('cr-heatmap-day')];

	foreach ($hours as $hour => $count) {
		$cells[] = (new CCol($count > 0 ? $count : ''))
			->addStyle('background: #'.ChangeRenderer::heatColor($count, $changes['heatmap']['max']).';'
				.($count > 0 && $count * 2 > $changes['heatmap']['max'] ? ' color: #fff;' : '')
			)
			->setTitle(_s('%1$s %2$s:00 - %3$s problem(s)', $weekdays[$day], sprintf('%02d', $hour), $count));
	}

	$heatmap->addRow($cells);
}

// 6. Noisy triggers.
$noisy = (new CTableInfo())
	->setHeader([_('Severity'), _('Host'), _('Trigger'), _('Problems'), _('Short-lived'), _('Median duration'),
		_('Previous period')
	])
	->setNoDataMessage(_('No noisy triggers.'));

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
	->setHeader([$filter['grouping'] === ChangeAnalytics::GROUP_BY_HOST ? _('Host') : _('Host group'),
		_('Previous period'), _('Current period'), _('Change')
	])
	->setNoDataMessage(_('No High or Disaster problems.'));

foreach ($changes['problem_time'] as $row) {
	$time->addRow([$row['name'],
		$bar($row['prev_pct'] / 100, 'cr-bar-open', ChangeRenderer::duration($row['prev']).' ('.$row['prev_pct'].'%)'),
		$bar($row['cur_pct'] / 100, 'cr-bar-problem', ChangeRenderer::duration($row['cur']).' ('.$row['cur_pct'].'%)'),
		$duration_delta($row['prev'], $row['cur'])
	]);
}

$grid = (new CDiv([
	$panel(_s('By %1$s: previous period -> current period', mb_strtolower($grouping_labels[$filter['grouping']])),
		$compare, $changes['compare_total'] > count($changes['compare'])
			? _s('Top %1$s of %2$s.', count($changes['compare']), $changes['compare_total'])
			: '',
		true
	),
	$movers['growth'],
	$movers['decline'],
	$lists['appeared'],
	$lists['disappeared'],
	$panel(_('Median time to resolve by severity'), $mttr, _('Number of resolved problems in brackets.')),
	$panel(_('When problems start'), $heatmap, _('New problems of the current period by day of week and hour.')),
	$panel(_('Noisy triggers'), $noisy,
		_s('Triggers with frequent problems or problems resolved within %1$s minutes: candidates for hysteresis or other thresholds.',
			ChangeAnalytics::SHORT_PROBLEM / 60
		),
		true
	),
	$panel(_('Time in problem'), $time,
		_('Time with at least one open High or Disaster problem, overlapping problems counted once.'), true
	)
]))->addClass('cr-panels');

$page->addItem($summary)->addItem($grid);

if ($changes['truncated']) {
	$page->addItem((new CDiv(_('The analysis is limited by "analytics_limit": the numbers may be lower than in reality.')))
		->addClass(ZBX_STYLE_RED)
	);
}

$page->show();
