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
use Modules\CustomReports\Lib\ScheduleHelper;
use Modules\CustomReports\Lib\ScriptReport;

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
	Period::ALL => cr_t('All active'),
	Period::LAST_24H => cr_t('Last 24 hours'),
	Period::LAST_7D => cr_t('Last 7 days'),
	Period::LAST_30D => cr_t('Last 30 days'),
	Period::OLDER_1D => cr_t('Older than 1 day'),
	Period::OLDER_7D => cr_t('Older than 7 days')
] as $value => $label) {
	$period->addValue($label, $value);
}

$weekdays = [1 => cr_t('Monday'), 2 => cr_t('Tuesday'), 3 => cr_t('Wednesday'), 4 => cr_t('Thursday'), 5 => cr_t('Friday'),
	6 => cr_t('Saturday'), 7 => cr_t('Sunday')
];

$media_type_select = (new CSelect('mediatypeid'))
	->setId('mediatypeid')
	->setFocusableElementId('label-mediatypeid')
	->addOptions(CSelect::createOptionsFromArray([0 => cr_t('Select media type')] + $data['media_types']))
	->setValue($schedule['mediatypeid']);

// External script: a select of the executable files of "external_scripts_path".
if ($data['scripts']) {
	$script_options = $data['scripts'];

	// A stored script that is gone (renamed, not executable) stays visible in the select.
	if ($schedule['script'] !== '' && !in_array($schedule['script'], $script_options, true)) {
		array_unshift($script_options, $schedule['script']);
	}

	$script_field = (new CSelect('script'))
		->setId('script')
		->setFocusableElementId('label-script')
		->addOptions(CSelect::createOptionsFromArray(array_combine($script_options, $script_options)))
		->setValue($schedule['script'] !== '' ? $schedule['script'] : reset($script_options));
}
else {
	$script_field = [
		new CVar('script', $schedule['script']),
		(new CSpan(cr_s('No executable files in %1$s (set "external_scripts_path" in conf/config.php).',
			$data['scripts_path']
		)))->addClass(ZBX_STYLE_RED)
	];
}

$script_format = (new CRadioButtonList('script_format', $schedule['script_format']))
	->addValue(cr_t('Auto'), ScriptReport::FORMAT_AUTO)
	->addValue('JSON', ScriptReport::FORMAT_JSON)
	->addValue('CSV', ScriptReport::FORMAT_CSV)
	->addValue(cr_t('Text'), ScriptReport::FORMAT_TEXT)
	->setModern(true);

$form_list = (new CFormList('schedule_form_list'))
	->addRow((new CLabel(cr_t('Name'), 'name'))->setAsteriskMark(),
		(new CTextBox('name', $schedule['name'], false, 255))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setAriaRequired()
			->setAttribute('autofocus', 'autofocus')
	)
	->addRow(cr_t('Enabled'), (new CCheckBox('enabled'))->setChecked($schedule['enabled'] == 1))

	// Report data: problems of Zabbix (filter below) or an external script.
	->addRow(new CTag('h4', true, cr_t('Report data')))
	->addRow(cr_t('Data source'),
		(new CRadioButtonList('source', $schedule['source']))
			->addValue(cr_t('Zabbix problems'), ScheduleHelper::SOURCE_PROBLEMS)
			->addValue(cr_t('External script'), ScheduleHelper::SOURCE_SCRIPT)
			->setModern(true)
	)
	->addRow(new CLabel(cr_t('Script'), 'label-script'), [
		$script_field,
		$data['scripts']
			? [
				(new CDiv())->addClass(ZBX_STYLE_FORM_INPUT_MARGIN),
				(new CSimpleButton(cr_t('Run and show')))
					->addClass(ZBX_STYLE_BTN_GREY)
					->addClass('js-cr-script-preview')
					->setAttribute('data-script-url', (new CUrl('zabbix.php'))
						->setArgument('action', 'customreports.script')
						->getUrl()
					)
					->setAttribute('data-csrf-name', CCsrfTokenHelper::CSRF_TOKEN_NAME)
					->setAttribute('data-csrf-token', CCsrfTokenHelper::get('customreports.script'))
			]
			: null,
		(new CDiv(cr_s('Executable files of %1$s (ExternalScripts of Zabbix). The script runs as the web server user here and as the cron user for schedules.',
			$data['scripts_path']
		)))->addClass(ZBX_STYLE_GREY)
	], 'cr-script-row')
	->addRow(new CLabel(cr_t('Parameters'), 'script_params'), [
		(new CTextArea('script_params', implode("\n", $schedule['script_params'])))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setRows(3)
			->setAttribute('placeholder', cr_t('one parameter per line')),
		(new CDiv(cr_t('Macros: {DATE}, {TIME}, {PERIOD_FROM}, {PERIOD_TILL} (Unix time), {PERIOD_FROM_DATE}, {PERIOD_TILL_DATE}, {SCHEDULE}. Period: 24 hours for daily, 7 days for weekly schedules. The same values are in the environment variables CR_DATE, CR_PERIOD_FROM, ...')))
			->addClass(ZBX_STYLE_GREY)
	], 'cr-script-params-row')
	->addRow(cr_t('Data format'), [
		$script_format,
		(new CDiv(cr_t('JSON: a list of rows, or an object with "title", "summary", "status", "kpi", "tables". CSV: the first line is the header. Text: shown as it is. See README.')))
			->addClass(ZBX_STYLE_GREY)
	], 'cr-script-format-row')
	->addRow(new CLabel(cr_t('Host groups'), 'groupids__ms'),
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
	->addRow(new CLabel(cr_t('Hosts'), 'hostids__ms'),
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
	->addRow(cr_t('Severity'),
		(new CCheckBoxList('severities'))
			->setOptions(FrontendHelper::getSeverities())
			->setChecked($schedule['severities'])
			->setColumns(3)
			->setVertical()
	)
	->addRow(cr_t('Age'), $period)
	->addRow(cr_t('Include suppressed problems'),
		(new CCheckBox('show_suppressed'))->setChecked($schedule['show_suppressed'] == 1)
	)
	->addRow(cr_t('Problem'),
		(new CTextBox('problem_name', $schedule['problem_name'], false, 255))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setAttribute('placeholder', cr_t('part of the problem name'))
	)
	->addRow(cr_t('Acknowledgement status'),
		(new CRadioButtonList('acknowledged', $schedule['acknowledged']))
			->addValue(cr_t('All'), ReportBuilder::ACK_ALL)
			->addValue(cr_t('Unacknowledged'), ReportBuilder::ACK_NO)
			->addValue(cr_t('Acknowledged'), ReportBuilder::ACK_YES)
			->setModern(true)
	)
	->addRow(cr_t('Problem list'), [
		(new CRadioButtonList('problem_list', $schedule['problem_list']))
			->addValue(cr_t('All open problems'), ReportBuilder::LIST_FULL)
			->addValue(cr_t('Unresolved summary only'), ReportBuilder::LIST_SUMMARY)
			->setModern(true),
		(new CDiv(cr_t('Unresolved summary: one line per problem with how long it is open, whether it was open the whole period, how many alerts were sent and the last alert, instead of every problem. Needs period analytics.')))
			->addClass(ZBX_STYLE_GREY)
	])
	->addRow(cr_t('Period analytics'), [
		(new CRadioButtonList('analytics', $schedule['analytics']))
			->addValue(cr_t('Auto'), PeriodAnalytics::PERIOD_AUTO)
			->addValue(cr_t('Off'), PeriodAnalytics::PERIOD_NONE)
			->addValue(cr_t('24 hours'), PeriodAnalytics::PERIOD_24H)
			->addValue(cr_t('7 days'), PeriodAnalytics::PERIOD_7D)
			->addValue(cr_t('30 days'), PeriodAnalytics::PERIOD_30D)
			->setModern(true),
		(new CDiv(cr_t('New, resolved and acknowledged problems of the period compared with the previous period, MTTR, MTTA, problems per day, recurring problems. Auto: 24 hours for daily, 7 days for weekly schedules.')))
			->addClass(ZBX_STYLE_GREY)
	])
	->addRow(cr_t('Changes analysis'), [
		(new CCheckBox('include_changes'))
			->setChecked($schedule['include_changes'] == 1)
			->setLabel(cr_t('Compare with the previous period')),
		(new CDiv(cr_t('Adds the blocks of the "Changes" tab to HTML/PDF reports (by host group: previous -> current, growth and decline, new and disappeared problems, MTTR by severity, heatmap, noisy triggers, time in problem) and a few lines to the short summary. Period: the period analytics period, or the schedule period if analytics is off.')))
			->addClass(ZBX_STYLE_GREY)
	])
	->addRow(new CLabel(cr_t('Sort by'), 'label-sortfield'), [
		(new CSelect('sortfield'))
			->setFocusableElementId('label-sortfield')
			->addOptions(CSelect::createOptionsFromArray([
				ReportBuilder::SORT_SEVERITY => cr_t('Severity'),
				ReportBuilder::SORT_TIME => cr_t('Time'),
				ReportBuilder::SORT_DURATION => cr_t('Duration'),
				ReportBuilder::SORT_HOST => cr_t('Host'),
				ReportBuilder::SORT_PROBLEM => cr_t('Problem'),
				ReportBuilder::SORT_ACK => cr_t('Ack')
			]))
			->setValue($schedule['sortfield']),
		(new CDiv())->addClass(ZBX_STYLE_FORM_INPUT_MARGIN),
		(new CRadioButtonList('sortorder', $schedule['sortorder']))
			->addValue(cr_t('Descending'), ReportBuilder::SORT_DESC)
			->addValue(cr_t('Ascending'), ReportBuilder::SORT_ASC)
			->setModern(true)
	])

	// Schedule.
	->addRow(new CTag('h4', true, cr_t('Schedule')))
	->addRow(cr_t('Frequency'),
		(new CRadioButtonList('frequency', $schedule['frequency']))
			->addValue(cr_t('Daily'), ScheduleHelper::FREQUENCY_DAILY)
			->addValue(cr_t('Weekly'), ScheduleHelper::FREQUENCY_WEEKLY)
			->setModern(true)
	)
	->addRow(new CLabel(cr_t('Day of week'), 'label-weekday'),
		(new CSelect('weekday'))
			->setFocusableElementId('label-weekday')
			->addOptions(CSelect::createOptionsFromArray($weekdays))
			->setValue($schedule['weekday']),
		'cr-weekday-row'
	)
	->addRow((new CLabel(cr_t('Time'), 'time'))->setAsteriskMark(), [
		(new CTextBox('time', $schedule['time'], false, 5))
			->setWidth(ZBX_TEXTAREA_TINY_WIDTH)
			->setAttribute('placeholder', 'hh:mm')
			->setAriaRequired(),
		(new CSpan(cr_s('timezone: %1$s', $data['timezone'])))
			->addClass($data['timezone_problem'] === null ? ZBX_STYLE_GREY : ZBX_STYLE_RED)
			->addStyle('margin-left: 10px;'),
		$data['timezone_problem'] === null
			? null
			: (new CSpan(cr_t('(set "timezone" in conf/config.php)')))->addClass(ZBX_STYLE_RED)->addStyle('margin-left: 5px;')
	])

	// Delivery.
	->addRow(new CTag('h4', true, cr_t('Delivery')))
	->addRow(cr_t('Send through'), [
		(new CRadioButtonList('media_mode', $schedule['media_mode']))
			->addValue(cr_t('One media type'), ScheduleHelper::MEDIA_FIXED)
			->addValue(cr_t('Own media of every user'), ScheduleHelper::MEDIA_USER)
			->setModern(true),
		(new CDiv(cr_t('Own media of every user: each selected user (and member of the selected user groups) gets the report through his own active media - Email, Script (e.g. a Telegram script with its own bot) or Telegram webhook. The media type below is then used only for the additional recipients.')))
			->addClass(ZBX_STYLE_GREY)
	])
	->addRow((new CLabel(cr_t('Media type'), 'label-mediatypeid'))->setAsteriskMark(), $media_type_select)
	->addRow(new CLabel(cr_t('Users'), 'userids__ms'),
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
	->addRow(new CLabel(cr_t('User groups'), 'usrgrpids__ms'),
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
	->addRow(new CLabel(cr_t('Additional recipients'), 'extra_sendto'), [
		(new CTextArea('extra_sendto', implode("\n", $schedule['extra_sendto'])))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setRows(3)
			->setAttribute('placeholder', cr_t('One address per line: e-mail, Telegram chat ID, etc.')),
		(new CDiv(cr_t('Users receive the report at the active "Send to" addresses of the selected media type.')))
			->addClass(ZBX_STYLE_GREY)
	])
	->addRow(cr_t('Report format'),
		(new CRadioButtonList('format', $schedule['format']))
			->addValue(cr_t('Short summary'), ReportRenderer::FORMAT_SUMMARY)
			->addValue(cr_t('Detailed HTML'), ReportRenderer::FORMAT_HTML)
			->addValue(cr_t('CSV'), ReportRenderer::FORMAT_CSV)
			->addValue(cr_t('PDF'), ReportRenderer::FORMAT_PDF)
			->setModern(true)
	)
	->addRow(cr_t('Send separately'),
		(new CRadioButtonList('split', $schedule['split']))
			->addValue(cr_t('One report'), ReportSplitter::SPLIT_NONE)
			->addValue(cr_t('Per host group'), ReportSplitter::SPLIT_GROUP)
			->addValue(cr_t('Per host'), ReportSplitter::SPLIT_HOST)
			->setModern(true)
	)
	->addRow('',
		(new CDiv([
			cr_t('Per host group: a separate report for every selected host group (or for every group with problems if no group is selected).'),
			BR(),
			cr_t('Per host: a separate report for every selected host (or for every host with problems if no host is selected).')
		]))->addClass(ZBX_STYLE_GREY),
		'cr-split-hint-row'
	)
	->addRow(cr_t('Do not send empty reports'), (new CCheckBox('skip_empty'))->setChecked($schedule['skip_empty'] == 1))
	->addRow('',
		(new CDiv([
			cr_t('Short summary: text with HTML markup, limited to the Telegram message size (4096 characters).'),
			BR(),
			cr_t('Detailed HTML: full table in the e-mail body; for Telegram it is sent as an .html document.'),
			BR(),
			cr_t('CSV: short summary plus a CSV attachment (document for Telegram).'),
			BR(),
			cr_t('PDF: short summary plus the detailed report as a PDF attachment (document for Telegram). Needs Chrome/Chromium or wkhtmltopdf on the server; without them HTML is attached.')
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
		->setHeader([$is_group ? cr_t('Host groups') : cr_t('Hosts'), cr_t('Recipients'), cr_t('Action')]);

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
					->setAttribute('placeholder', cr_t('additional addresses, one per line'))
			],
			(new CCol(
				(new CSimpleButton(cr_t('Remove')))
					->addClass(ZBX_STYLE_BTN_LINK)
					->addClass('js-cr-rule-remove')
					->setAttribute('data-list', $field)
					->setAttribute('data-index', $index)
			))->addStyle('vertical-align: top;')
		]);
	}

	$table->addRow(
		(new CCol(
			(new CSimpleButton(cr_t('Add')))
				->addClass(ZBX_STYLE_BTN_LINK)
				->addClass('js-cr-rule-add')
				->setAttribute('data-list', $field)
		))->setColSpan(3)
	);

	return $table;
};

$form_list
	->addRow(cr_t('Recipients per host group'), [
		(new CDiv($make_rules_table('group_recipients', 'groupids')))
			->addClass(ZBX_STYLE_TABLE_FORMS_SEPARATOR)
			->addStyle('min-width: '.ZBX_TEXTAREA_BIG_WIDTH.'px;'),
		(new CDiv(cr_t('The report of a host group is sent to the recipients of every row that lists this group. The "Users", "User groups" and "Additional recipients" above are common recipients.')))
			->addClass(ZBX_STYLE_GREY)
	], 'cr-group-recipients-row')
	->addRow(cr_t('Recipients per host'), [
		(new CDiv($make_rules_table('host_recipients', 'hostids')))
			->addClass(ZBX_STYLE_TABLE_FORMS_SEPARATOR)
			->addStyle('min-width: '.ZBX_TEXTAREA_BIG_WIDTH.'px;'),
		(new CDiv(cr_t('The report of a host is sent to the recipients of every row that lists this host. The "Users", "User groups" and "Additional recipients" above are common recipients.')))
			->addClass(ZBX_STYLE_GREY)
	], 'cr-host-recipients-row')
	->addRow(cr_t('Common recipients receive'),
		(new CRadioButtonList('common_recipients', $schedule['common_recipients']))
			->addValue(cr_t('All reports'), ScheduleHelper::COMMON_ALL)
			->addValue(cr_t('Only reports without own recipients'), ScheduleHelper::COMMON_FALLBACK)
			->setModern(true),
		'cr-common-recipients-row'
	);

$is_new = $schedule['scheduleid'] === '';

$tab_view = (new CTabView())
	->addTab('schedule_tab', cr_t('Report schedule'), $form_list)
	->setFooter(makeFormFooter(
		new CSubmit('update', $is_new ? cr_t('Add') : cr_t('Update')),
		[(new CRedirectButton(cr_t('Cancel'), $list_url))->setId('cancel')]
	));

$form->addItem($tab_view);

(new CHtmlPage())
	->setTitle($is_new ? cr_t('New report schedule') : cr_t('Report schedule'))
	->addItem(FrontendHelper::makeTabs('customreports.schedule.list', true))
	->addItem($form)
	->show();
