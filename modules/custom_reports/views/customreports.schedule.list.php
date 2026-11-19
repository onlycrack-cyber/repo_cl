<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


/**
 * @var CView $this
 * @var array $data
 */

use Modules\CustomReports\Lib\FrontendHelper;
use Modules\CustomReports\Lib\CronManager;
use Modules\CustomReports\Lib\ReportRenderer;
use Modules\CustomReports\Lib\ReportSplitter;
use Modules\CustomReports\Lib\ScheduleHelper;

$format_labels = [
	ReportRenderer::FORMAT_SUMMARY => cr_t('Short summary'),
	ReportRenderer::FORMAT_HTML => cr_t('Detailed HTML'),
	ReportRenderer::FORMAT_CSV => cr_t('CSV'),
	ReportRenderer::FORMAT_PDF => cr_t('PDF')
];

$split_labels = [
	ReportSplitter::SPLIT_NONE => cr_t('One report'),
	ReportSplitter::SPLIT_GROUP => cr_t('Per host group'),
	ReportSplitter::SPLIT_HOST => cr_t('Per host')
];

$weekdays = [1 => cr_t('Monday'), 2 => cr_t('Tuesday'), 3 => cr_t('Wednesday'), 4 => cr_t('Thursday'), 5 => cr_t('Friday'),
	6 => cr_t('Saturday'), 7 => cr_t('Sunday')
];

// Module controllers check the CSRF token against the full action name.
$csrf_tokens = [];

foreach (['customreports.schedule.run', 'customreports.schedule.test', 'customreports.schedule.delete',
		'customreports.cron.update'] as $action) {
	$csrf_tokens[$action] = CCsrfTokenHelper::get($action);
}
$edit_url = (new CUrl('zabbix.php'))->setArgument('action', 'customreports.schedule.edit');

$table = (new CTableInfo())->setHeader([
	cr_t('Name'),
	cr_t('Schedule'),
	cr_t('Media type'),
	cr_t('Format'),
	cr_t('Send separately'),
	cr_t('Recipients'),
	cr_t('Status'),
	cr_t('Last delivery'),
	cr_t('Result'),
	cr_t('Next run'),
	cr_t('Actions')
]);

$now = time();

foreach ($data['schedules'] as $scheduleid => $schedule) {
	$state = $data['state'][$scheduleid] ?? [];

	$when = $schedule['frequency'] === ScheduleHelper::FREQUENCY_WEEKLY
		? cr_s('Weekly on %1$s at %2$s', $weekdays[$schedule['weekday']], $schedule['time'])
		: cr_s('Daily at %1$s', $schedule['time']);

	$recipients = [];

	if ($schedule['userids']) {
		$recipients[] = cr_n('%1$s user', '%1$s users', count($schedule['userids']));
	}

	if ($schedule['usrgrpids']) {
		$recipients[] = cr_n('%1$s user group', '%1$s user groups', count($schedule['usrgrpids']));
	}

	if ($schedule['extra_sendto']) {
		$recipients[] = cr_n('%1$s address', '%1$s addresses', count($schedule['extra_sendto']));
	}

	if (($schedule['split'] ?? '') === ReportSplitter::SPLIT_GROUP && !empty($schedule['group_recipients'])) {
		$recipients[] = cr_n('%1$s host group rule', '%1$s host group rules', count($schedule['group_recipients']));
	}

	if (($schedule['split'] ?? '') === ReportSplitter::SPLIT_HOST && !empty($schedule['host_recipients'])) {
		$recipients[] = cr_n('%1$s host rule', '%1$s host rules', count($schedule['host_recipients']));
	}

	$last_run = max((int) ($state['last_run'] ?? 0), (int) ($state['last_manual_run'] ?? 0));
	$history_url = (new CUrl('zabbix.php'))
		->setArgument('action', 'customreports.schedule.history')
		->setArgument('scheduleid', $scheduleid)
		->getUrl();
	$result = FrontendHelper::makeStatus($state['last_status'] ?? null);

	if (($state['last_message'] ?? '') !== '') {
		$result->setHint($state['last_message']);
	}

	$next_run = $schedule['enabled'] && $data['timezone_problem'] !== 'invalid'
		? zbx_date2str(DATE_TIME_FORMAT, ScheduleHelper::nextOccurrence($schedule, $now, $data['timezone']))
		: '-';

	$test_form = (new CForm('post', (new CUrl('zabbix.php'))->getUrl()))
		->addClass('cr-inline-form')
		->addVar('action', 'customreports.schedule.test')
		->addVar('scheduleid', $scheduleid)
		->addVar(CCsrfTokenHelper::CSRF_TOKEN_NAME, $csrf_tokens['customreports.schedule.test'])
		->addItem((new CSubmit('test', cr_t('Test to me')))
			->addClass(ZBX_STYLE_BTN_LINK)
			->setTitle(cr_t('Send this report with all its settings only to your own media of this media type'))
		);

	$run_form = (new CForm('post', (new CUrl('zabbix.php'))->getUrl()))
		->addClass('cr-inline-form')
		->addVar('action', 'customreports.schedule.run')
		->addVar('scheduleid', $scheduleid)
		->addVar(CCsrfTokenHelper::CSRF_TOKEN_NAME, $csrf_tokens['customreports.schedule.run'])
		->setAttribute('data-confirm', cr_s('Send report "%1$s" to all its recipients now?', $schedule['name']))
		->addItem((new CSubmit('run', cr_t('Send now')))->addClass(ZBX_STYLE_BTN_LINK));

	$delete_form = (new CForm('post', (new CUrl('zabbix.php'))->getUrl()))
		->addClass('cr-inline-form')
		->addVar('action', 'customreports.schedule.delete')
		->addVar('scheduleid', $scheduleid)
		->addVar(CCsrfTokenHelper::CSRF_TOKEN_NAME, $csrf_tokens['customreports.schedule.delete'])
		->setAttribute('data-confirm', cr_s('Delete schedule "%1$s"?', $schedule['name']))
		->addItem((new CSubmit('delete', cr_t('Delete')))->addClass(ZBX_STYLE_BTN_LINK));

	$table->addRow([
		[
			new CLink($schedule['name'], (clone $edit_url)->setArgument('scheduleid', $scheduleid)->getUrl()),
			($schedule['source'] ?? '') === ScheduleHelper::SOURCE_SCRIPT
				? (new CDiv(cr_s('Script: %1$s', $schedule['script'])))->addClass(ZBX_STYLE_GREY)
				: null
		],
		$when,
		array_key_exists($schedule['mediatypeid'], $data['media_types'])
			? $data['media_types'][$schedule['mediatypeid']]['name']
			: (new CSpan(cr_t('Inaccessible media type')))->addClass(ZBX_STYLE_RED),
		$format_labels[$schedule['format']] ?? $schedule['format'],
		($schedule['source'] ?? '') === ScheduleHelper::SOURCE_SCRIPT
			? '-'
			: $split_labels[$schedule['split'] ?? ReportSplitter::SPLIT_NONE],
		implode(', ', $recipients),
		$schedule['enabled']
			? (new CSpan(cr_t('Enabled')))->addClass(ZBX_STYLE_GREEN)
			: (new CSpan(cr_t('Disabled')))->addClass(ZBX_STYLE_RED),
		$last_run > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $last_run) : cr_t('Never'),
		$result,
		(new CCol($next_run))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol([$run_form, $test_form, (new CLink(cr_t('History'), $history_url))->addClass('cr-action-link'),
			$delete_form
		]))->addClass('cr-actions')
	]);
}

$page = (new CHtmlPage())
	->setTitle(cr_t('Custom Problem Reports'))
	->setControls(
		(new CTag('nav', true,
			(new CList())->addItem(new CRedirectButton(cr_t('Create schedule'), $edit_url->getUrl()))
		))->setAttribute('aria-label', cr_t('Content controls'))
	)
	->addItem(FrontendHelper::makeTabs('customreports.schedule.list', true));

if ($data['error'] !== null) {
	$page->addItem(makeMessageBox(ZBX_STYLE_MSG_BAD, [['message' => $data['error']]],
		cr_t('Cannot read schedule storage'), true, false
	));
}

if ($data['timezone_problem'] !== null) {
	$page->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING, [[
		'message' => $data['timezone_problem'] === 'invalid'
			? cr_t('The "timezone" in conf/config.php is not a valid timezone: the cron job does not send reports until it is fixed.')
			: cr_t('Set "timezone" in conf/config.php (e.g. "Europe/Moscow"). Until then schedule times are in UTC.')
	]], cr_t('Timezone of schedules is not configured'), true, false));
}

// Cron job of the sender.
$cron = $data['cron'];
$heartbeat = $cron['heartbeat'];

if ($heartbeat === null) {
	$cron_status = (new CSpan(cr_t('Never run: the sender is not scheduled in cron yet')))->addClass(ZBX_STYLE_RED);
}
elseif (time() - (int) $heartbeat['time'] <= CronManager::HEARTBEAT_MAX_AGE) {
	$cron_status = (new CSpan(cr_s('Running: last run %1$s ago as user "%2$s"', zbx_date2age((int) $heartbeat['time']),
		$heartbeat['user'] ?? '?'
	).(!empty($heartbeat['busy']) ? ' '.cr_t('(a previous run is still sending)') : '')))->addClass(ZBX_STYLE_GREEN);
}
else {
	$cron_status = (new CSpan(cr_s('Not running: last run %1$s ago (%2$s)', zbx_date2age((int) $heartbeat['time']),
		zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $heartbeat['time'])
	)))->addClass(ZBX_STYLE_RED);
}

$cron_form = (new CForm('post', (new CUrl('zabbix.php'))->getUrl()))
	->addClass('cr-inline-form')
	->addVar('action', 'customreports.cron.update')
	->addVar(CCsrfTokenHelper::CSRF_TOKEN_NAME, $csrf_tokens['customreports.cron.update']);

if ($cron['user_installed']) {
	$cron_form
		->addVar('operation', 'remove')
		->setAttribute('data-confirm', cr_t('Remove the cron job? Scheduled reports will stop being sent.'))
		->addItem((new CSubmit('cron', cr_t('Remove cron job')))->addClass(ZBX_STYLE_BTN_ALT));
	$user_status = (new CSpan(cr_t('Installed')))->addClass(ZBX_STYLE_GREEN);
}
else {
	$cron_form
		->addVar('operation', 'install')
		->addItem(new CSubmit('cron', cr_t('Create cron job')));
	$user_status = (new CSpan(cr_t('Not installed')))->addClass(ZBX_STYLE_GREY);
}

$user_row = [$user_status, ' ', $cron_form];

if ($cron['error'] !== null) {
	$user_row[] = (new CDiv(cr_s('Cannot read crontab: %1$s', $cron['error'])))->addClass(ZBX_STYLE_RED);
}

// Delivery status pushed to Zabbix (template zabbix/zbx_template_custom_reports.yaml).
$zabbix = $heartbeat['zabbix'] ?? null;

if ($cron['zabbix_status_host'] === '') {
	$zabbix_status = (new CSpan(cr_t('Not configured: import zabbix/zbx_template_custom_reports.yaml, link it to a host and set "zabbix_status_host" in conf/config.php. Then Zabbix raises a problem when a delivery fails or cron stops.')))
		->addClass(ZBX_STYLE_GREY);
}
elseif ($zabbix === null || $zabbix['host'] !== $cron['zabbix_status_host']) {
	$zabbix_status = (new CSpan(cr_s('Host "%1$s": waiting for the next cron run.', $cron['zabbix_status_host'])))
		->addClass(ZBX_STYLE_GREY);
}
elseif ($zabbix['error'] !== '') {
	$zabbix_status = (new CSpan(cr_s('Cannot send the status to host "%1$s" (%2$s): %3$s', $zabbix['host'],
		zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $zabbix['time']), $zabbix['error']
	)))->addClass(ZBX_STYLE_RED);
}
else {
	$zabbix_status = (new CSpan(cr_s('Status sent to host "%1$s" %2$s ago; failed schedules: %3$s', $zabbix['host'],
		zbx_date2age((int) $zabbix['time']), $zabbix['failed'] ?? 0
	)))->addClass(ZBX_STYLE_GREEN);
}

$cron_list = (new CFormList('cron_form_list'))
	->addRow(cr_t('Sender status'), $cron_status)
	->addRow(cr_t('Zabbix monitoring'), $zabbix_status)
	->addRow(cr_s('Crontab of user "%1$s"', $cron['user']), $user_row)
	->addRow($cron['cron_d_file'], [
		$cron['cron_d_installed']
			? (new CSpan(cr_t('Found')))->addClass(ZBX_STYLE_GREEN)
			: (new CSpan(cr_t('Not found')))->addClass(ZBX_STYLE_GREY),
		(new CDiv(cr_s('Alternative to the button above: as root, put this line into %1$s', $cron['cron_d_file'])))
			->addClass(ZBX_STYLE_GREY),
		(new CTextArea('cron_d_line', $cron['cron_d_line']))
			->setReadonly(true)
			->setRows(2)
			->setWidth(ZBX_TEXTAREA_BIG_WIDTH)
	]);

if ($cron['user_installed'] && $cron['cron_d_installed']) {
	$cron_list->addRow('', (new CDiv(cr_s('The sender is scheduled twice (crontab of "%1$s" and %2$s). Reports are not duplicated thanks to locking, but one of the entries should be removed.',
		$cron['user'], $cron['cron_d_file']
	)))->addClass(ZBX_STYLE_ORANGE));
}

$page
	->addItem((new CDiv(cr_s('Schedule times are evaluated in the "%1$s" timezone by the cron job bin/send_reports.php. Storage: %2$s',
		$data['timezone'], $data['data_dir']
	)))->addClass('cr-note'))
	->addItem($table)
	->addItem((new CDiv([new CTag('h4', true, cr_t('Cron job')), $cron_list]))->addClass('cr-cron'))
	->show();
