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
use Modules\CustomReports\Lib\ReportBuilder;
use Modules\CustomReports\Lib\ReportRenderer;
use Modules\CustomReports\Lib\ReportSplitter;
use Modules\CustomReports\Lib\ScheduleHelper;

$this->addJsFile('multiselect.js');

$schedule = $data['schedule'];
$form_name = 'customreports_schedule';
$list_url = (new CUrl('zabbix.php'))->setArgument('action', 'customreports.schedule.list')->getUrl();

$form = (new CForm('post', (new CUrl('zabbix.php'))->getUrl()))
	->setName($form_name)
	->setId($form_name)
	->addVar('action', 'customreports.schedule.update')
	->addVar('scheduleid', $schedule['scheduleid'])
	->addVar(CCsrfTokenHelper::CSRF_TOKEN_NAME, CCsrfTokenHelper::get('customreports.schedule.update'));

$period = (new CRadioButtonList('period', $schedule['period']))->setModern(true);

foreach ([
	Period::ALL => _('All active'),
	Period::LAST_24H => _('Last 24 hours'),
	Period::LAST_7D => _('Last 7 days'),
	Period::LAST_30D => _('Last 30 days'),
	Period::OLDER_1D => _('Older than 1 day'),
	Period::OLDER_7D => _('Older than 7 days')
] as $value => $label) {
	$period->addValue($label, $value);
}

$weekdays = [1 => _('Monday'), 2 => _('Tuesday'), 3 => _('Wednesday'), 4 => _('Thursday'), 5 => _('Friday'),
	6 => _('Saturday'), 7 => _('Sunday')
];

$media_type_select = (new CSelect('mediatypeid'))
	->setId('mediatypeid')
	->setFocusableElementId('label-mediatypeid')
	->addOptions(CSelect::createOptionsFromArray([0 => _('Select media type')] + $data['media_types']))
	->setValue($schedule['mediatypeid']);

$form_list = (new CFormList('schedule_form_list'))
	->addRow((new CLabel(_('Name'), 'name'))->setAsteriskMark(),
		(new CTextBox('name', $schedule['name'], false, 255))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setAriaRequired()
			->setAttribute('autofocus', 'autofocus')
	)
	->addRow(_('Enabled'), (new CCheckBox('enabled'))->setChecked($schedule['enabled'] == 1))

	// Report filter.
	->addRow(new CTag('h4', true, _('Report filter')))
	->addRow(new CLabel(_('Host groups'), 'groupids__ms'),
		(new CMultiSelect([
			'name' => 'groupids[]',
			'object_name' => 'hostGroup',
			'data' => $data['groups'],
			'popup' => [
				'parameters' => [
					'srctbl' => 'host_groups',
					'srcfld1' => 'groupid',
					'dstfrm' => $form_name,
					'dstfld1' => 'groupids_',
					'with_hosts' => true
				]
			]
		]))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	)
	->addRow(new CLabel(_('Hosts'), 'hostids__ms'),
		(new CMultiSelect([
			'name' => 'hostids[]',
			'object_name' => 'hosts',
			'data' => $data['hosts'],
			'popup' => [
				'filter_preselect' => [
					'id' => 'groupids_',
					'submit_as' => 'groupid'
				],
				'parameters' => [
					'srctbl' => 'hosts',
					'srcfld1' => 'hostid',
					'dstfrm' => $form_name,
					'dstfld1' => 'hostids_'
				]
			]
		]))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	)
	->addRow(_('Severity'),
		(new CCheckBoxList('severities'))
			->setOptions(CSeverityHelper::getSeverities())
			->setChecked($schedule['severities'])
			->setColumns(3)
			->setVertical()
	)
	->addRow(_('Age'), $period)
	->addRow(_('Include suppressed problems'),
		(new CCheckBox('show_suppressed'))->setChecked($schedule['show_suppressed'] == 1)
	)
	->addRow(_('Problem'),
		(new CTextBox('problem_name', $schedule['problem_name'], false, 255))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setAttribute('placeholder', _('part of the problem name'))
	)
	->addRow(_('Acknowledgement status'),
		(new CRadioButtonList('acknowledged', $schedule['acknowledged']))
			->addValue(_('All'), ReportBuilder::ACK_ALL)
			->addValue(_('Unacknowledged'), ReportBuilder::ACK_NO)
			->addValue(_('Acknowledged'), ReportBuilder::ACK_YES)
			->setModern(true)
	)
	->addRow(new CLabel(_('Sort by'), 'label-sortfield'), [
		(new CSelect('sortfield'))
			->setFocusableElementId('label-sortfield')
			->addOptions(CSelect::createOptionsFromArray([
				ReportBuilder::SORT_SEVERITY => _('Severity'),
				ReportBuilder::SORT_TIME => _('Time'),
				ReportBuilder::SORT_DURATION => _('Duration'),
				ReportBuilder::SORT_HOST => _('Host'),
				ReportBuilder::SORT_PROBLEM => _('Problem'),
				ReportBuilder::SORT_ACK => _('Ack')
			]))
			->setValue($schedule['sortfield']),
		(new CDiv())->addClass(ZBX_STYLE_FORM_INPUT_MARGIN),
		(new CRadioButtonList('sortorder', $schedule['sortorder']))
			->addValue(_('Descending'), ReportBuilder::SORT_DESC)
			->addValue(_('Ascending'), ReportBuilder::SORT_ASC)
			->setModern(true)
	])

	// Schedule.
	->addRow(new CTag('h4', true, _('Schedule')))
	->addRow(_('Frequency'),
		(new CRadioButtonList('frequency', $schedule['frequency']))
			->addValue(_('Daily'), ScheduleHelper::FREQUENCY_DAILY)
			->addValue(_('Weekly'), ScheduleHelper::FREQUENCY_WEEKLY)
			->setModern(true)
	)
	->addRow(new CLabel(_('Day of week'), 'label-weekday'),
		(new CSelect('weekday'))
			->setFocusableElementId('label-weekday')
			->addOptions(CSelect::createOptionsFromArray($weekdays))
			->setValue($schedule['weekday']),
		'cr-weekday-row'
	)
	->addRow((new CLabel(_('Time'), 'time'))->setAsteriskMark(), [
		(new CTextBox('time', $schedule['time'], false, 5))
			->setWidth(ZBX_TEXTAREA_TINY_WIDTH)
			->setAttribute('placeholder', 'hh:mm')
			->setAriaRequired(),
		(new CSpan(_s('timezone: %1$s', $data['timezone'])))->addClass(ZBX_STYLE_GREY)->addStyle('margin-left: 10px;')
	])

	// Delivery.
	->addRow(new CTag('h4', true, _('Delivery')))
	->addRow((new CLabel(_('Media type'), 'label-mediatypeid'))->setAsteriskMark(), $media_type_select)
	->addRow(new CLabel(_('Users'), 'userids__ms'),
		(new CMultiSelect([
			'name' => 'userids[]',
			'object_name' => 'users',
			'data' => $data['users'],
			'popup' => [
				'parameters' => [
					'srctbl' => 'users',
					'srcfld1' => 'userid',
					'srcfld2' => 'fullname',
					'dstfrm' => $form_name,
					'dstfld1' => 'userids_'
				]
			]
		]))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	)
	->addRow(new CLabel(_('User groups'), 'usrgrpids__ms'),
		(new CMultiSelect([
			'name' => 'usrgrpids[]',
			'object_name' => 'usersGroups',
			'data' => $data['usrgrps'],
			'popup' => [
				'parameters' => [
					'srctbl' => 'usrgrp',
					'srcfld1' => 'usrgrpid',
					'srcfld2' => 'name',
					'dstfrm' => $form_name,
					'dstfld1' => 'usrgrpids_'
				]
			]
		]))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	)
	->addRow(new CLabel(_('Additional recipients'), 'extra_sendto'), [
		(new CTextArea('extra_sendto', implode("\n", $schedule['extra_sendto'])))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setRows(3)
			->setAttribute('placeholder', _('One address per line: e-mail, Telegram chat ID, etc.')),
		(new CDiv(_('Users receive the report at the active "Send to" addresses of the selected media type.')))
			->addClass(ZBX_STYLE_GREY)
	])
	->addRow(_('Report format'),
		(new CRadioButtonList('format', $schedule['format']))
			->addValue(_('Short summary'), ReportRenderer::FORMAT_SUMMARY)
			->addValue(_('Detailed HTML'), ReportRenderer::FORMAT_HTML)
			->addValue(_('CSV'), ReportRenderer::FORMAT_CSV)
			->setModern(true)
	)
	->addRow(_('Send separately'),
		(new CRadioButtonList('split', $schedule['split']))
			->addValue(_('One report'), ReportSplitter::SPLIT_NONE)
			->addValue(_('Per host group'), ReportSplitter::SPLIT_GROUP)
			->addValue(_('Per host'), ReportSplitter::SPLIT_HOST)
			->setModern(true)
	)
	->addRow('',
		(new CDiv([
			_('Per host group: a separate report for every selected host group (or for every group with problems if no group is selected).'),
			BR(),
			_('Per host: a separate report for every selected host (or for every host with problems if no host is selected).')
		]))->addClass(ZBX_STYLE_GREY)
	)
	->addRow(_('Do not send empty reports'), (new CCheckBox('skip_empty'))->setChecked($schedule['skip_empty'] == 1))
	->addRow('',
		(new CDiv([
			_('Short summary: text with HTML markup, limited to the Telegram message size (4096 characters).'),
			BR(),
			_('Detailed HTML: full table in the e-mail body; for Telegram it is sent as an .html document.'),
			BR(),
			_('CSV: short summary plus a CSV attachment (document for Telegram).')
		]))->addClass(ZBX_STYLE_GREY)
	);

/**
 * Table of per-part recipient rules ("Recipients per host group" / "Recipients per host").
 *
 * @param string $field  Schedule field: "group_recipients" or "host_recipients".
 * @param string $key    Object IDs of a rule: "groupids" or "hostids".
 */
$make_rules_table = static function (string $field, string $key) use ($schedule, $data, $form_name): CTable {
	$is_group = $key === 'groupids';

	$table = (new CTable())
		->setId('cr-'.$field)
		->addClass('cr-rules')
		->setHeader([$is_group ? _('Host groups') : _('Hosts'), _('Recipients'), _('Action')]);

	foreach ($schedule[$field] as $index => $rule) {
		$prefix = $field.'['.$index.']';
		$ms_data = [];

		foreach ([$key, 'userids', 'usrgrpids'] as $id_key) {
			$ms_data[$id_key] = [];

			foreach ($rule[$id_key] as $id) {
				if (array_key_exists($id, $data['rule_names'][$id_key])) {
					$ms_data[$id_key][] = $data['rule_names'][$id_key][$id];
				}
			}
		}

		$objects = $is_group
			? new CMultiSelect([
				'name' => $prefix.'[groupids][]',
				'object_name' => 'hostGroup',
				'data' => $ms_data['groupids'],
				'popup' => [
					'parameters' => [
						'srctbl' => 'host_groups',
						'srcfld1' => 'groupid',
						'dstfrm' => $form_name,
						'dstfld1' => zbx_formatDomId($prefix.'[groupids][]'),
						'with_hosts' => true
					]
				]
			])
			: new CMultiSelect([
				'name' => $prefix.'[hostids][]',
				'object_name' => 'hosts',
				'data' => $ms_data['hostids'],
				'popup' => [
					'parameters' => [
						'srctbl' => 'hosts',
						'srcfld1' => 'hostid',
						'dstfrm' => $form_name,
						'dstfld1' => zbx_formatDomId($prefix.'[hostids][]')
					]
				]
			]);

		$table->addRow([
			(new CCol($objects->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)))->addStyle('vertical-align: top;'),
			[
				(new CMultiSelect([
					'name' => $prefix.'[userids][]',
					'object_name' => 'users',
					'data' => $ms_data['userids'],
					'popup' => [
						'parameters' => [
							'srctbl' => 'users',
							'srcfld1' => 'userid',
							'srcfld2' => 'fullname',
							'dstfrm' => $form_name,
							'dstfld1' => zbx_formatDomId($prefix.'[userids][]')
						]
					]
				]))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH),
				(new CDiv())->addClass(ZBX_STYLE_FORM_INPUT_MARGIN),
				(new CMultiSelect([
					'name' => $prefix.'[usrgrpids][]',
					'object_name' => 'usersGroups',
					'data' => $ms_data['usrgrpids'],
					'popup' => [
						'parameters' => [
							'srctbl' => 'usrgrp',
							'srcfld1' => 'usrgrpid',
							'srcfld2' => 'name',
							'dstfrm' => $form_name,
							'dstfld1' => zbx_formatDomId($prefix.'[usrgrpids][]')
						]
					]
				]))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH),
				(new CDiv())->addClass(ZBX_STYLE_FORM_INPUT_MARGIN),
				(new CTextArea($prefix.'[extra_sendto]', implode("\n", $rule['extra_sendto'])))
					->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)
					->setRows(2)
					->setAttribute('placeholder', _('additional addresses, one per line'))
			],
			(new CCol(
				(new CSimpleButton(_('Remove')))
					->addClass(ZBX_STYLE_BTN_LINK)
					->addClass('js-cr-rule-remove')
					->setAttribute('data-list', $field)
					->setAttribute('data-index', $index)
			))->addStyle('vertical-align: top;')
		]);
	}

	$table->addRow(
		(new CCol(
			(new CSimpleButton(_('Add')))
				->addClass(ZBX_STYLE_BTN_LINK)
				->addClass('js-cr-rule-add')
				->setAttribute('data-list', $field)
		))->setColSpan(3)
	);

	return $table;
};

$form_list
	->addRow(_('Recipients per host group'), [
		(new CDiv($make_rules_table('group_recipients', 'groupids')))
			->addClass(ZBX_STYLE_TABLE_FORMS_SEPARATOR)
			->addStyle('min-width: '.ZBX_TEXTAREA_BIG_WIDTH.'px;'),
		(new CDiv(_('The report of a host group is sent to the recipients of every row that lists this group. The "Users", "User groups" and "Additional recipients" above are common recipients.')))
			->addClass(ZBX_STYLE_GREY)
	], 'cr-group-recipients-row')
	->addRow(_('Recipients per host'), [
		(new CDiv($make_rules_table('host_recipients', 'hostids')))
			->addClass(ZBX_STYLE_TABLE_FORMS_SEPARATOR)
			->addStyle('min-width: '.ZBX_TEXTAREA_BIG_WIDTH.'px;'),
		(new CDiv(_('The report of a host is sent to the recipients of every row that lists this host. The "Users", "User groups" and "Additional recipients" above are common recipients.')))
			->addClass(ZBX_STYLE_GREY)
	], 'cr-host-recipients-row')
	->addRow(_('Common recipients receive'),
		(new CRadioButtonList('common_recipients', $schedule['common_recipients']))
			->addValue(_('All reports'), ScheduleHelper::COMMON_ALL)
			->addValue(_('Only reports without own recipients'), ScheduleHelper::COMMON_FALLBACK)
			->setModern(true),
		'cr-common-recipients-row'
	);

$is_new = $schedule['scheduleid'] === '';

$tab_view = (new CTabView())
	->addTab('schedule_tab', _('Report schedule'), $form_list)
	->setFooter(makeFormFooter(
		new CSubmit('update', $is_new ? _('Add') : _('Update')),
		[(new CRedirectButton(_('Cancel'), $list_url))->setId('cancel')]
	));

$form->addItem($tab_view);

(new CHtmlPage())
	->setTitle($is_new ? _('New report schedule') : _('Report schedule'))
	->addItem(FrontendHelper::makeTabs('customreports.schedule.list', true))
	->addItem($form)
	->show();
