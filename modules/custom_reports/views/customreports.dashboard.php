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
		(new CSimpleButton(_('Print / PDF')))
			->addClass(ZBX_STYLE_BTN_ALT)
			->setAttribute('data-cr-open', (clone $export_url)->setArgument('format', 'pdf')->getUrl())
	);

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

// Test delivery.
$send_block = null;

if ($data['can_send']) {
	if ($data['send_media_types']) {
		$send_block = (new CForm('post', (new CUrl('zabbix.php'))->getUrl()))
			->setName('customreports_send')
			->addClass('cr-send')
			->addVar('action', 'customreports.send')
			->addVar(CCsrfTokenHelper::CSRF_TOKEN_NAME, CCsrfTokenHelper::get('customreports'))
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
						ReportRenderer::FORMAT_CSV => _('CSV attachment')
					])),
				(new CSelect('split'))
					->setValue(ReportSplitter::SPLIT_NONE)
					->addOptions(CSelect::createOptionsFromArray([
						ReportSplitter::SPLIT_NONE => _('One report'),
						ReportSplitter::SPLIT_GROUP => _('Separately per host group'),
						ReportSplitter::SPLIT_HOST => _('Separately per host')
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
$table = (new CTableInfo())->setHeader([
	_('Time'),
	_('Duration'),
	_('Severity'),
	_('Host'),
	_('Problem'),
	_('Ack')
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
