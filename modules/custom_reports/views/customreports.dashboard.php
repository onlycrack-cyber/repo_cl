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
	Period::ALL => _('All active'),
	Period::LAST_24H => _('Last 24 hours'),
	Period::LAST_7D => _('Last 7 days'),
	Period::LAST_30D => _('Last 30 days'),
	Period::OLDER_1D => _('Older than 1 day'),
	Period::OLDER_7D => _('Older than 7 days')
];

// Filter.
$filter_column_left = (new CFormList())
	->addRow((new CLabel(_('Host groups'), 'filter_groupids__ms')),
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
	->addRow((new CLabel(_('Hosts'), 'filter_hostids__ms')),
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
	->addRow(_('Problem'),
		(new CTextBox('filter_problem_name', $filter['problem_name']))
			->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
			->setAttribute('placeholder', _('part of the problem name'))
	);

$period_radio = (new CRadioButtonList('filter_period', $filter['period']))->setModern(true);

foreach ($period_labels as $value => $label) {
	$period_radio->addValue($label, $value);
}

$filter_column_right = (new CFormList())
	->addRow(_('Severity'),
		(new CCheckBoxList('filter_severities'))
			->setOptions(CSeverityHelper::getSeverities())
			->setChecked($filter['severities'])
			->setColumns(3)
			->setVertical()
	)
	->addRow(_('Age'), $period_radio)
	->addRow(_('Show suppressed problems'),
		(new CCheckBox('filter_show_suppressed'))->setChecked($filter['show_suppressed'] == 1)
	)
	->addRow(_('Acknowledgement status'),
		(new CRadioButtonList('filter_acknowledged', $filter['acknowledged']))
			->addValue(_('All'), ReportBuilder::ACK_ALL)
			->addValue(_('Unacknowledged'), ReportBuilder::ACK_NO)
			->addValue(_('Acknowledged'), ReportBuilder::ACK_YES)
			->setModern(true)
	)
	->addRow(_('Period analytics'),
		(new CRadioButtonList('filter_analytics', $filter['analytics']))
			->addValue(_('Off'), PeriodAnalytics::PERIOD_NONE)
			->addValue(_('24 hours'), PeriodAnalytics::PERIOD_24H)
			->addValue(_('7 days'), PeriodAnalytics::PERIOD_7D)
			->addValue(_('30 days'), PeriodAnalytics::PERIOD_30D)
			->setModern(true)
	);

$filter_form = (new CFilter())
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'customreports.dashboard'))
	->setProfile('web.customreports.filter')
	->setActiveTab(CProfile::get('web.customreports.filter.active', 1))
	->addVar('action', 'customreports.dashboard')
	->addFilterTab(_('Filter'), [$filter_column_left, $filter_column_right]);

// Export buttons.
$export_url = (new CUrl('zabbix.php'))->setArgument('action', 'customreports.export');

$controls = (new CList())
	->addItem(
		(new CRedirectButton(_('Download CSV'), (clone $export_url)->setArgument('format', 'csv')->getUrl()))
			->addClass(ZBX_STYLE_BTN_ALT)
	)
	->addItem(
		(new CRedirectButton(_('Download HTML'), (clone $export_url)->setArgument('format', 'html')->getUrl()))
			->addClass(ZBX_STYLE_BTN_ALT)
	)
	->addItem(
		(new CSimpleButton(_('Print')))
			->addClass(ZBX_STYLE_BTN_ALT)
			->setAttribute('data-cr-open', (clone $export_url)->setArgument('format', 'print')->getUrl())
	);

// Server-side PDF needs Chrome/Chromium or wkhtmltopdf on the frontend server.
if ($data['pdf_available']) {
	$controls->addItem(
		(new CRedirectButton(_('Download PDF'), (clone $export_url)->setArgument('format', 'pdf')->getUrl()))
			->addClass(ZBX_STYLE_BTN_ALT)
	);
}

// KPI cards.
$kpi = (new CDiv())->addClass('cr-kpi');

$kpi->addItem(
	(new CDiv([
		(new CDiv($report['total']))->addClass('cr-kpi-value'),
		(new CDiv(_('Active problems')))->addClass('cr-kpi-label')
	]))->addClass('cr-kpi-card cr-kpi-total')
);

foreach (array_reverse(CSeverityHelper::getSeverities()) as $severity) {
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
		(new CDiv(_('Top 5 problem hosts')))->addClass('cr-kpi-label'),
		$report['top_hosts'] ? $top_hosts : (new CDiv(_('No data found')))->addClass(ZBX_STYLE_GREY)
	]))->addClass('cr-kpi-card cr-kpi-hosts')
);

// How long the open problems have been open.
$stats = $report['open_stats'];
$open_stats = (new CDiv($stats['oldest_age'] === null
	? _('No open problems.')
	: _s('Oldest open: %1$s, average age: %2$s; open over 1 day: %3$s, over 7 days: %4$s; unacknowledged: %5$s',
		ReportRenderer::formatDuration($stats['oldest_age']), ReportRenderer::formatDuration($stats['avg_age']),
		$stats['over_1d'], $stats['over_7d'], $stats['unacknowledged']
	)
))->addClass('cr-note');

// Period analytics.
$analytics_block = null;
$analytics = $report['analytics'];

if ($analytics !== null) {
	$period_labels_an = [
		PeriodAnalytics::PERIOD_24H => _('last 24 hours'),
		PeriodAnalytics::PERIOD_7D => _('last 7 days'),
		PeriodAnalytics::PERIOD_30D => _('last 30 days')
	];
	$duration = static function (?int $seconds): string {
		return $seconds === null ? '-' : ReportRenderer::formatDuration($seconds);
	};

	if ($analytics['change_pct'] === null) {
		$change = (new CSpan(_s('previous period: %1$s', $analytics['prev'])))->addClass(ZBX_STYLE_GREY);
	}
	else {
		// More problems than in the previous period is bad (red), fewer is good (green).
		$change = (new CSpan(_s('%1$s%2$s%% vs previous period (%3$s)', $analytics['change_pct'] > 0 ? '+' : '',
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
		$card((string) $analytics['new'], _('New problems'), $change),
		$card((string) $analytics['resolved'], _('Resolved'), _s('still open: %1$s', $analytics['unresolved'])),
		$card($duration($analytics['mttr_median']), _('Time to resolve (median)'),
			_s('average: %1$s', $duration($analytics['mttr_avg']))
		),
		$card($analytics['ack_rate'] === null ? '-' : $analytics['ack_rate'].'%', _('Acknowledged'),
			_s('time to acknowledge: %1$s', $duration($analytics['mtta_avg']))
		)
	]))->addClass('cr-kpi');

	$severity_list = (new CList())->addClass('cr-severity-list');

	foreach (array_reverse(CSeverityHelper::getSeverities()) as $severity) {
		$severity_list->addItem(
			(new CSpan($severity['label'].': '.$analytics['new_by_severity'][$severity['value']]))
				->addClass(CSeverityHelper::getStyle((int) $severity['value']))
		);
	}

	// Bar chart of new problems per hour/day.
	$max = max(1, max(array_column($analytics['buckets'], 'count') ?: [0]));
	$chart = (new CDiv())->addClass('cr-chart');

	foreach ($analytics['buckets'] as $bucket) {
		$chart->addItem(
			(new CDiv([
				(new CDiv($bucket['count'] > 0 ? $bucket['count'] : ''))->addClass('cr-chart-value'),
				(new CDiv())
					->addClass('cr-chart-bar')
					->addStyle('height: '.round($bucket['count'] * 70 / $max).'%;'),
				(new CDiv($bucket['label']))->addClass('cr-chart-label')
			]))
				->addClass('cr-chart-col')
				->setTitle($bucket['label'].': '.$bucket['count'])
		);
	}

	$recurring = (new CTableInfo())
		->setHeader([_('Host'), _('Recurring problem'), _('Times')])
		->setNoDataMessage(_('No problem occurred more than once.'));

	foreach ($analytics['recurring'] as $trigger) {
		$recurring->addRow([$trigger['hosts'], $trigger['name'], $trigger['count']]);
	}

	$top_new = (new CTableInfo())
		->setHeader([_('Host'), _('New problems')])
		->setNoDataMessage(_('No data found'));

	foreach ($analytics['top_hosts'] as $host) {
		$top_new->addRow([$host['name'], $host['count']]);
	}

	// Unresolved problems: one line per trigger with the alerts of the period.
	$unresolved = (new CTableInfo())
		->setHeader([_('Severity'), _('Host'), _('Problem'), _('Open since'), _('Duration'), _('Alerts sent'),
			_('Fired'), _('Last alert')
		])
		->setNoDataMessage(_('No unresolved problems.'));

	foreach ($report['unresolved'] as $item) {
		$alerts = $item['alerts_sent'] === null ? '-' : [
			$item['alerts_sent'],
			$item['alerts_failed'] ? (new CSpan(' '._s('(%1$s failed)', $item['alerts_failed'])))->addClass(ZBX_STYLE_RED) : null
		];

		$unresolved->addRow([
			CSeverityHelper::makeSeverityCell($item['severity']),
			implode(', ', array_column($item['hosts'], 'name')),
			[$item['name'], $item['acknowledged'] ? (new CSpan(' ('._('ack').')'))->addClass(ZBX_STYLE_GREEN) : null],
			(new CCol(zbx_date2str(DATE_TIME_FORMAT, $item['since'])))->addClass(ZBX_STYLE_NOWRAP),
			(new CCol([
				ReportRenderer::formatDuration($item['age']),
				$item['whole_period'] ? [BR(), (new CSpan(_('whole period')))->addClass(ZBX_STYLE_RED)] : null
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
		new CTag('h4', true, _s('Period analytics: %1$s (%2$s - %3$s)', $period_labels_an[$analytics['period']],
			zbx_date2str(DATE_TIME_FORMAT, $analytics['from']), zbx_date2str(DATE_TIME_FORMAT, $analytics['till'])
		)),
		$cards,
		$severity_list,
		(new CDiv(_s('New problems per %1$s', $analytics['period'] === PeriodAnalytics::PERIOD_24H ? _('hour') : _('day'))))
			->addClass('cr-note'),
		$chart,
		(new CDiv([
			(new CDiv($recurring))->addClass('cr-analytics-table'),
			(new CDiv($top_new))->addClass('cr-analytics-table cr-analytics-narrow')
		]))->addClass('cr-analytics-tables'),
		new CTag('h4', true, _s('Unresolved problems: %1$s, open the whole period: %2$s', count($report['unresolved']),
			$whole_period
		)),
		(new CDiv(_('One line per problem: alerts and repeated events of the period are counted instead of being listed.')))
			->addClass('cr-note'),
		$unresolved,
		$analytics['truncated']
			? (new CDiv(_('Period analytics is limited by "analytics_limit" events.')))->addClass(ZBX_STYLE_RED)
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
				new CLabel(_('Test delivery to my media'), 'label-cr-mediatypeid'),
				(new CSelect('mediatypeid'))
					->setFocusableElementId('label-cr-mediatypeid')
					->addOptions(CSelect::createOptionsFromArray($data['send_media_types'])),
				(new CSelect('format'))
					->setValue(ReportRenderer::FORMAT_SUMMARY)
					->addOptions(CSelect::createOptionsFromArray([
						ReportRenderer::FORMAT_SUMMARY => _('Short summary (Telegram)'),
						ReportRenderer::FORMAT_HTML => _('Detailed HTML'),
						ReportRenderer::FORMAT_CSV => _('CSV attachment'),
						ReportRenderer::FORMAT_PDF => _('PDF attachment')
					])),
				(new CSelect('split'))
					->setValue(ReportSplitter::SPLIT_NONE)
					->addOptions(CSelect::createOptionsFromArray([
						ReportSplitter::SPLIT_NONE => _('One report'),
						ReportSplitter::SPLIT_GROUP => _('Separately per host group'),
						ReportSplitter::SPLIT_HOST => _('Separately per host')
					])),
				(new CSelect('problem_list'))
					->setValue(ReportBuilder::LIST_FULL)
					->addOptions(CSelect::createOptionsFromArray([
						ReportBuilder::LIST_FULL => _('All open problems'),
						ReportBuilder::LIST_SUMMARY => _('Unresolved summary only')
					])),
				(new CCheckBox('skip_empty'))->setChecked(true)->setLabel(_('Skip empty')),
				new CSubmit('send', _('Send now'))
			]);
	}
	else {
		$send_block = (new CDiv(
			_('To send a test report, add an active Email, Telegram or Script media to your user profile.')
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
	$sort_header(_('Time'), ReportBuilder::SORT_TIME),
	$sort_header(_('Duration'), ReportBuilder::SORT_DURATION),
	$sort_header(_('Severity'), ReportBuilder::SORT_SEVERITY),
	$sort_header(_('Host'), ReportBuilder::SORT_HOST),
	$sort_header(_('Problem'), ReportBuilder::SORT_PROBLEM),
	$sort_header(_('Ack'), ReportBuilder::SORT_ACK)
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
			? (new CSpan(_('Yes')))->addClass(ZBX_STYLE_GREEN)
			: (new CSpan(_('No')))->addClass(ZBX_STYLE_RED)
	]);
}

$page = (new CHtmlPage())
	->setTitle(_('Custom Problem Reports'))
	->setControls((new CTag('nav', true, $controls))->setAttribute('aria-label', _('Content controls')))
	->addItem(FrontendHelper::makeTabs('customreports.dashboard', $data['is_super_admin']))
	->addItem($filter_form);

if ($data['error'] !== null) {
	$page->addItem(makeMessageBox(ZBX_STYLE_MSG_BAD, [['message' => $data['error']]], _('Cannot load problems'),
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
		_('Period analytics is not available'), true, false
	));
}

if ($send_block !== null) {
	$page->addItem($send_block);
}

if ($report['truncated']) {
	$page->addItem((new CDiv(_s('Only the first %1$s problems are shown. Narrow down the filter.', $report['total'])))
		->addClass('cr-note')
	);
}

$page
	->addItem($table)
	->show();
