<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


/**
 * @var CView $this
 * @var array $data
 */

use Modules\CustomReports\Lib\FrontendHelper;
use Modules\CustomReports\Lib\Period;
use Modules\CustomReports\Lib\PeriodAnalytics;
use Modules\CustomReports\Lib\ReportBuilder;
use Modules\CustomReports\Lib\ReportRenderer;
use Modules\CustomReports\Lib\ReportSplitter;

$this->addJsFile('multiselect.js');

$filter = $data['filter'];
$report = $data['report'];

$period_labels = [
	Period::ALL => cr_t('All active'),
	Period::LAST_24H => cr_t('Last 24 hours'),
	Period::LAST_7D => cr_t('Last 7 days'),
	Period::LAST_30D => cr_t('Last 30 days'),
	Period::OLDER_1D => cr_t('Older than 1 day'),
	Period::OLDER_7D => cr_t('Older than 7 days')
];

// Filter.
$filter_column_left = (new CFormList())
	->addRow((new CLabel(cr_t('Host groups'), 'filter_groupids__ms')),
		(new CMultiSelect([
			'name' => 'filter_groupids[]',
			'object_name' => 'hostGroup',
			'data' => $data['filter_groups'],
			'popup' => [
				'parameters' => [
					'srctbl' => 'host_groups',
					'srcfld1' => 'groupid',
					'dstfrm' => 'zbx_filter',
					'dstfld1' => 'filter_groupids_',
					'with_hosts' => true,
					'enrich_parent_groups' => true
				]
			]
		]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
	)
	->addRow((new CLabel(cr_t('Hosts'), 'filter_hostids__ms')),
		(new CMultiSelect([
			'name' => 'filter_hostids[]',
			'object_name' => 'hosts',
			'data' => $data['filter_hosts'],
			'popup' => [
				'filter_preselect' => [
					'id' => 'filter_groupids_',
					'submit_as' => 'groupid'
				],
				'parameters' => [
					'srctbl' => 'hosts',
					'srcfld1' => 'hostid',
					'dstfrm' => 'zbx_filter',
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

$period_radio = (new CRadioButtonList('filter_period', $filter['period']))->setModern(true);

foreach ($period_labels as $value => $label) {
	$period_radio->addValue($label, $value);
}

// The age buttons are wide: they go to the left column, which has room for them.
$filter_column_left->addRow(cr_t('Age'), $period_radio);

$filter_column_right = (new CFormList())
	->addRow(cr_t('Severity'),
		(new CCheckBoxList('filter_severities'))
			->setOptions(FrontendHelper::getSeverities())
			->setChecked($filter['severities'])
			->setColumns(2)
			->setVertical()
	)
	->addRow(cr_t('Show suppressed problems'),
		(new CCheckBox('filter_show_suppressed'))->setChecked($filter['show_suppressed'] == 1)
	)
	->addRow(cr_t('Acknowledgement status'),
		(new CRadioButtonList('filter_acknowledged', $filter['acknowledged']))
			->addValue(cr_t('All'), ReportBuilder::ACK_ALL)
			->addValue(cr_t('Unacknowledged'), ReportBuilder::ACK_NO)
			->addValue(cr_t('Acknowledged'), ReportBuilder::ACK_YES)
			->setModern(true)
	)
	->addRow(cr_t('Period analytics'),
		(new CRadioButtonList('filter_analytics', $filter['analytics']))
			->addValue(cr_t('Off'), PeriodAnalytics::PERIOD_NONE)
			->addValue(cr_t('24 hours'), PeriodAnalytics::PERIOD_24H)
			->addValue(cr_t('7 days'), PeriodAnalytics::PERIOD_7D)
			->addValue(cr_t('30 days'), PeriodAnalytics::PERIOD_30D)
			->setModern(true)
	);

$filter_form = (new CFilter())
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'customreports.dashboard'))
	->setProfile('web.customreports.filter')
	->setActiveTab(CProfile::get('web.customreports.filter.active', 1))
	->addVar('action', 'customreports.dashboard')
	->addFilterTab(cr_t('Filter'), [$filter_column_left, $filter_column_right]);

// Export buttons.
$export_url = (new CUrl('zabbix.php'))->setArgument('action', 'customreports.export');

$controls = (new CList())
	->addItem(
		(new CRedirectButton(cr_t('Download CSV'), (clone $export_url)->setArgument('format', 'csv')->getUrl()))
			->addClass(ZBX_STYLE_BTN_ALT)
	)
	->addItem(
		(new CRedirectButton(cr_t('Download HTML'), (clone $export_url)->setArgument('format', 'html')->getUrl()))
			->addClass(ZBX_STYLE_BTN_ALT)
	)
	->addItem(
		(new CSimpleButton(cr_t('Print')))
			->addClass(ZBX_STYLE_BTN_ALT)
			->setAttribute('data-cr-open', (clone $export_url)->setArgument('format', 'print')->getUrl())
	);

// Server-side PDF needs Chrome/Chromium or wkhtmltopdf on the frontend server.
if ($data['pdf_available']) {
	$controls->addItem(
		(new CRedirectButton(cr_t('Download PDF'), (clone $export_url)->setArgument('format', 'pdf')->getUrl()))
			->addClass(ZBX_STYLE_BTN_ALT)
	);
}

// KPI cards.
$kpi = (new CDiv())->addClass('cr-kpi');

$kpi->addItem(
	(new CDiv([
		(new CDiv($report['total']))->addClass('cr-kpi-value'),
		(new CDiv(cr_t('Active problems')))->addClass('cr-kpi-label')
	]))->addClass('cr-kpi-card cr-kpi-total')
);

foreach (array_reverse(FrontendHelper::getSeverities()) as $severity) {
	$kpi->addItem(
		(new CDiv([
			(new CDiv($report['by_severity'][$severity['value']]))->addClass('cr-kpi-value'),
			(new CDiv($severity['label']))->addClass('cr-kpi-label')
		]))
			->addClass('cr-kpi-card')
			->addClass(CSeverityHelper::getStyle((int) $severity['value']))
	);
}

$top_hosts = (new CTag('ol', true))->addClass('cr-top-hosts');

foreach ($report['top_hosts'] as $host) {
	$top_hosts->addItem(new CTag('li', true, [
		(new CLinkAction($host['name']))->setMenuPopup(CMenuPopupHelper::getHost($host['hostid'])),
		(new CSpan($host['count']))->addClass('cr-top-hosts-count')
	]));
}

$kpi->addItem(
	(new CDiv([
		(new CDiv(cr_t('Top 5 problem hosts')))->addClass('cr-kpi-label'),
		$report['top_hosts'] ? $top_hosts : (new CDiv(cr_t('No data found')))->addClass(ZBX_STYLE_GREY)
	]))->addClass('cr-kpi-card cr-kpi-hosts')
);

// How long the open problems have been open.
$stats = $report['open_stats'];
$open_stats = (new CDiv($stats['oldest_age'] === null
	? cr_t('No open problems.')
	: cr_s('Oldest open: %1$s, average age: %2$s; open over 1 day: %3$s, over 7 days: %4$s; unacknowledged: %5$s',
		ReportRenderer::formatDuration($stats['oldest_age']), ReportRenderer::formatDuration($stats['avg_age']),
		$stats['over_1d'], $stats['over_7d'], $stats['unacknowledged']
	)
))->addClass('cr-note');

// Period analytics.
$analytics_block = null;
$analytics = $report['analytics'];

if ($analytics !== null) {
	$period_labels_an = [
		PeriodAnalytics::PERIOD_24H => cr_t('last 24 hours'),
		PeriodAnalytics::PERIOD_7D => cr_t('last 7 days'),
		PeriodAnalytics::PERIOD_30D => cr_t('last 30 days')
	];
	$duration = static function (?int $seconds): string {
		return $seconds === null ? '-' : ReportRenderer::formatDuration($seconds);
	};

	if ($analytics['change_pct'] === null) {
		$change = (new CSpan(cr_s('previous period: %1$s', $analytics['prev'])))->addClass(ZBX_STYLE_GREY);
	}
	else {
		// More problems than in the previous period is bad (red), fewer is good (green).
		$change = (new CSpan(cr_s('%1$s%2$s%% vs previous period (%3$s)', $analytics['change_pct'] > 0 ? '+' : '',
			$analytics['change_pct'], $analytics['prev']
		)))->addClass($analytics['change_pct'] > 0 ? ZBX_STYLE_RED : ZBX_STYLE_GREEN);
	}

	$card = static function (string $value, string $label, $note = null) {
		return (new CDiv([
			(new CDiv($value))->addClass('cr-kpi-value'),
			(new CDiv($label))->addClass('cr-kpi-label'),
			$note !== null ? (new CDiv($note))->addClass('cr-kpi-note') : null
		]))->addClass('cr-kpi-card cr-kpi-period');
	};

	$cards = (new CDiv([
		$card((string) $analytics['new'], cr_t('New problems'), $change),
		$card((string) $analytics['resolved'], cr_t('Resolved'), cr_s('still open: %1$s', $analytics['unresolved'])),
		$card($duration($analytics['mttr_median']), cr_t('Time to resolve (median)'),
			cr_s('average: %1$s', $duration($analytics['mttr_avg']))
		),
		$card($analytics['ack_rate'] === null ? '-' : $analytics['ack_rate'].'%', cr_t('Acknowledged'),
			cr_s('time to acknowledge: %1$s', $duration($analytics['mtta_avg']))
		),
		$card((string) $analytics['open_end'], cr_t('Open problems'), (new CSpan(cr_s('at the start of the period: %1$s (%2$s)',
			$analytics['open_start'], sprintf('%+d', $analytics['open_end'] - $analytics['open_start'])
		)))->addClass($analytics['open_end'] > $analytics['open_start'] ? ZBX_STYLE_RED : ZBX_STYLE_GREEN))
	]))->addClass('cr-kpi');

	$severity_list = (new CList())->addClass('cr-severity-list');

	foreach (array_reverse(FrontendHelper::getSeverities()) as $severity) {
		$severity_list->addItem(
			(new CSpan($severity['label'].': '.$analytics['new_by_severity'][$severity['value']]))
				->addClass(CSeverityHelper::getStyle((int) $severity['value']))
		);
	}

	$unit = $analytics['period'] === PeriodAnalytics::PERIOD_24H ? cr_t('hour') : cr_t('day');
	$plot = 110;

	// Show every n-th axis label when there are many columns (the full label is in the tooltip).
	$label_step = (int) ceil(count($analytics['buckets']) / 16);
	$axis_label = static function (int $index, array $bucket) use ($label_step, $analytics): string {
		return ($index % $label_step == 0 || $index == count($analytics['buckets']) - 1) ? $bucket['label'] : '';
	};

	// Chart 1: new problems per hour/day, stacked by severity (Disaster at the bottom).
	$max = max(1, max(array_column($analytics['buckets'], 'count') ?: [0]));
	$severity_chart = (new CDiv())->addClass('cr-chart');

	foreach ($analytics['buckets'] as $index => $bucket) {
		$stack = (new CDiv())->addClass('cr-stack');
		$hint = [$bucket['label']];

		foreach (array_reverse(FrontendHelper::getSeverities()) as $severity) {
			$count = $bucket['by_severity'][$severity['value']];

			if ($count > 0) {
				$stack->addItem(
					(new CDiv())
						->addClass(CSeverityHelper::getStyle((int) $severity['value']))
						->addStyle('height: '.max(1, round($count * $plot / $max)).'px;')
				);
				$hint[] = $severity['label'].': '.$count;
			}
		}

		$severity_chart->addItem(
			(new CDiv([
				(new CDiv($bucket['count'] > 0 ? $bucket['count'] : ''))->addClass('cr-chart-value'),
				$stack,
				(new CDiv($axis_label($index, $bucket)))->addClass('cr-chart-label')
			]))
				->addClass('cr-chart-col')
				->setTitle(implode("\n", $hint))
		);
	}

	// Chart 2: new vs resolved per hour/day and problems open at its end.
	$max = 1;

	foreach ($analytics['buckets'] as $bucket) {
		$max = max($max, $bucket['count'], $bucket['resolved'], $bucket['open']);
	}

	$flow_chart = (new CDiv())->addClass('cr-chart');

	foreach ($analytics['buckets'] as $index => $bucket) {
		$group = (new CDiv())->addClass('cr-group');

		foreach (['count' => 'cr-bar-new', 'resolved' => 'cr-bar-resolved', 'open' => 'cr-bar-open'] as $key => $class) {
			$group->addItem(
				(new CDiv())
					->addClass($class)
					->addStyle('height: '.($bucket[$key] > 0 ? max(1, round($bucket[$key] * $plot / $max)) : 0).'px;')
			);
		}

		$flow_chart->addItem(
			(new CDiv([
				$group,
				(new CDiv([$axis_label($index, $bucket), BR(), (new CSpan($bucket['open']))->addClass('cr-chart-open')]))
					->addClass('cr-chart-label')
			]))
				->addClass('cr-chart-col')
				->setTitle(cr_s('%1$s: new %2$s, resolved %3$s, open at the end %4$s', $bucket['label'], $bucket['count'],
					$bucket['resolved'], $bucket['open']
				))
		);
	}

	$charts = (new CDiv([
		(new CDiv([
			(new CDiv(cr_s('New problems per %1$s, by severity', $unit)))->addClass('cr-note'),
			$severity_chart
		]))->addClass('cr-chart-box'),
		(new CDiv([
			(new CDiv([
				cr_s('New vs resolved per %1$s, open problems at its end', $unit),
				(new CSpan())->addClass('cr-legend cr-bar-new'), cr_t('New'),
				(new CSpan())->addClass('cr-legend cr-bar-resolved'), cr_t('Resolved'),
				(new CSpan())->addClass('cr-legend cr-bar-open'), cr_t('Open')
			]))->addClass('cr-note'),
			$flow_chart
		]))->addClass('cr-chart-box')
	]))->addClass('cr-charts');

	$recurring = (new CTableInfo())
		->setHeader([cr_t('Host'), cr_t('Recurring problem'), cr_t('Times')])
		->setNoDataMessage(cr_t('No problem occurred more than once.'));

	foreach ($analytics['recurring'] as $trigger) {
		$recurring->addRow([$trigger['hosts'], $trigger['name'], $trigger['count']]);
	}

	$top_new = (new CTableInfo())
		->setHeader([cr_t('Host'), cr_t('New problems')])
		->setNoDataMessage(cr_t('No data found'));

	foreach ($analytics['top_hosts'] as $host) {
		$top_new->addRow([$host['name'], $host['count']]);
	}

	// Unresolved problems: one line per trigger with the alerts of the period.
	$unresolved = (new CTableInfo())
		->setHeader([cr_t('Severity'), cr_t('Host'), cr_t('Problem'), cr_t('Open since'), cr_t('Duration'), cr_t('Alerts sent'),
			cr_t('Fired'), cr_t('Last alert')
		])
		->setNoDataMessage(cr_t('No unresolved problems.'));

	foreach ($report['unresolved'] as $item) {
		$alerts = $item['alerts_sent'] === null ? '-' : [
			$item['alerts_sent'],
			$item['alerts_failed'] ? (new CSpan(' '.cr_s('(%1$s failed)', $item['alerts_failed'])))->addClass(ZBX_STYLE_RED) : null
		];

		$unresolved->addRow([
			CSeverityHelper::makeSeverityCell($item['severity']),
			implode(', ', array_column($item['hosts'], 'name')),
			[$item['name'], $item['acknowledged'] ? (new CSpan(' ('.cr_t('ack').')'))->addClass(ZBX_STYLE_GREEN) : null],
			(new CCol(zbx_date2str(DATE_TIME_FORMAT, $item['since'])))->addClass(ZBX_STYLE_NOWRAP),
			(new CCol([
				ReportRenderer::formatDuration($item['age']),
				$item['whole_period'] ? [BR(), (new CSpan(cr_t('whole period')))->addClass(ZBX_STYLE_RED)] : null
			]))->addClass(ZBX_STYLE_NOWRAP),
			$alerts,
			$item['repeats'] > 0 ? $item['repeats'] : '-',
			(new CSpan($item['last_alert']))->addClass(ZBX_STYLE_GREY)
		]);
	}

	$whole_period = count(array_filter($report['unresolved'], static function (array $item): bool {
		return $item['whole_period'];
	}));

	$analytics_block = (new CDiv([
		new CTag('h4', true, cr_s('Period analytics: %1$s (%2$s - %3$s)', $period_labels_an[$analytics['period']],
			zbx_date2str(DATE_TIME_FORMAT, $analytics['from']), zbx_date2str(DATE_TIME_FORMAT, $analytics['till'])
		)),
		$cards,
		$severity_list,
		$charts,
		(new CDiv([
			(new CDiv($recurring))->addClass('cr-analytics-table'),
			(new CDiv($top_new))->addClass('cr-analytics-table cr-analytics-narrow')
		]))->addClass('cr-analytics-tables'),
		new CTag('h4', true, cr_s('Unresolved problems: %1$s, open the whole period: %2$s', count($report['unresolved']),
			$whole_period
		)),
		(new CDiv(cr_t('One line per problem: alerts and repeated events of the period are counted instead of being listed.')))
			->addClass('cr-note'),
		$unresolved,
		$analytics['truncated']
			? (new CDiv(cr_t('Period analytics is limited by "analytics_limit" events.')))->addClass(ZBX_STYLE_RED)
			: null
	]))->addClass('cr-analytics');
}

// Test delivery.
$send_block = null;

if ($data['can_send']) {
	if ($data['send_media_types']) {
		$send_block = (new CForm('post', (new CUrl('zabbix.php'))->getUrl()))
			->setName('customreports_send')
			->addClass('cr-send')
			->addVar('action', 'customreports.send')
			->addVar(CCsrfTokenHelper::CSRF_TOKEN_NAME, CCsrfTokenHelper::get('customreports.send'))
			->addItem([
				new CLabel(cr_t('Test delivery to my media'), 'label-cr-mediatypeid'),
				(new CSelect('mediatypeid'))
					->setFocusableElementId('label-cr-mediatypeid')
					->addOptions(CSelect::createOptionsFromArray($data['send_media_types'])),
				(new CSelect('format'))
					->setValue(ReportRenderer::FORMAT_SUMMARY)
					->addOptions(CSelect::createOptionsFromArray([
						ReportRenderer::FORMAT_SUMMARY => cr_t('Short summary (Telegram)'),
						ReportRenderer::FORMAT_HTML => cr_t('Detailed HTML'),
						ReportRenderer::FORMAT_CSV => cr_t('CSV attachment'),
						ReportRenderer::FORMAT_PDF => cr_t('PDF attachment')
					])),
				(new CSelect('split'))
					->setValue(ReportSplitter::SPLIT_NONE)
					->addOptions(CSelect::createOptionsFromArray([
						ReportSplitter::SPLIT_NONE => cr_t('One report'),
						ReportSplitter::SPLIT_GROUP => cr_t('Separately per host group'),
						ReportSplitter::SPLIT_HOST => cr_t('Separately per host')
					])),
				(new CSelect('problem_list'))
					->setValue(ReportBuilder::LIST_FULL)
					->addOptions(CSelect::createOptionsFromArray([
						ReportBuilder::LIST_FULL => cr_t('All open problems'),
						ReportBuilder::LIST_SUMMARY => cr_t('Unresolved summary only')
					])),
				(new CCheckBox('skip_empty'))->setChecked(true)->setLabel(cr_t('Skip empty')),
				new CSubmit('send', cr_t('Send now'))
			]);
	}
	else {
		$send_block = (new CDiv(
			cr_t('To send a test report, add an active Email, Telegram or Script media to your user profile.')
		))->addClass('cr-send '.ZBX_STYLE_GREY);
	}
}

// Problems table.
// Clickable column headers: sorting is applied to the table and to exports.
$sort_url = (new CUrl('zabbix.php'))->setArgument('action', 'customreports.dashboard')->getUrl();
$sort_header = static function (string $label, string $field) use ($filter, $sort_url) {
	return make_sorting_header($label, $field, $filter['sortfield'], $filter['sortorder'], $sort_url);
};

$table = (new CTableInfo())->setHeader([
	$sort_header(cr_t('Time'), ReportBuilder::SORT_TIME),
	$sort_header(cr_t('Duration'), ReportBuilder::SORT_DURATION),
	$sort_header(cr_t('Severity'), ReportBuilder::SORT_SEVERITY),
	$sort_header(cr_t('Host'), ReportBuilder::SORT_HOST),
	$sort_header(cr_t('Problem'), ReportBuilder::SORT_PROBLEM),
	$sort_header(cr_t('Ack'), ReportBuilder::SORT_ACK)
]);

foreach ($report['problems'] as $problem) {
	$hosts = [];

	foreach ($problem['hosts'] as $host) {
		if ($hosts) {
			$hosts[] = ', ';
		}

		$hosts[] = (new CLinkAction($host['name']))->setMenuPopup(CMenuPopupHelper::getHost($host['hostid']));
	}

	$table->addRow([
		(new CCol(new CLink(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $problem['clock']),
			(new CUrl('tr_events.php'))
				->setArgument('triggerid', $problem['triggerid'])
				->setArgument('eventid', $problem['eventid'])
				->getUrl()
		)))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol(zbx_date2age($problem['clock'])))->addClass(ZBX_STYLE_NOWRAP),
		CSeverityHelper::makeSeverityCell($problem['severity']),
		$hosts ?: '-',
		$problem['name'],
		$problem['acknowledged']
			? (new CSpan(cr_t('Yes')))->addClass(ZBX_STYLE_GREEN)
			: (new CSpan(cr_t('No')))->addClass(ZBX_STYLE_RED)
	]);
}

$page = (new CHtmlPage())
	->setTitle(cr_t('Custom Problem Reports'))
	->setControls((new CTag('nav', true, $controls))->setAttribute('aria-label', cr_t('Content controls')))
	->addItem(FrontendHelper::makeTabs('customreports.dashboard', $data['is_super_admin']))
	->addItem($filter_form);

if ($data['error'] !== null) {
	$page->addItem(makeMessageBox(ZBX_STYLE_MSG_BAD, [['message' => $data['error']]], cr_t('Cannot load problems'),
		true, false
	));
}

$page->addItem($kpi);
$page->addItem($open_stats);

if ($analytics_block !== null) {
	$page->addItem($analytics_block);
}
elseif ($report['analytics_error'] !== null) {
	$page->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING, [['message' => $report['analytics_error']]],
		cr_t('Period analytics is not available'), true, false
	));
}

if ($send_block !== null) {
	$page->addItem($send_block);
}

if ($report['truncated']) {
	$page->addItem((new CDiv(cr_s('Only the first %1$s problems are shown. Narrow down the filter.', $report['total'])))
		->addClass('cr-note')
	);
}

$page
	->addItem($table)
	->show();
