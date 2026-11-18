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
	ReportRenderer::FORMAT_SUMMARY => _('Short summary'),
	ReportRenderer::FORMAT_HTML => _('Detailed HTML'),
	ReportRenderer::FORMAT_CSV => _('CSV'),
	ReportRenderer::FORMAT_PDF => _('PDF')
];

$split_labels = [
	ReportSplitter::SPLIT_NONE => _('One report'),
	ReportSplitter::SPLIT_GROUP => _('Per host group'),
	ReportSplitter::SPLIT_HOST => _('Per host')
];

$weekdays = [1 => _('Monday'), 2 => _('Tuesday'), 3 => _('Wednesday'), 4 => _('Thursday'), 5 => _('Friday'),
	6 => _('Saturday'), 7 => _('Sunday')
];

// Module controllers check the CSRF token against the full action name.
$csrf_tokens = [];

foreach (['customreports.schedule.run', 'customreports.schedule.delete', 'customreports.cron.update'] as $action) {
	$csrf_tokens[$action] = CCsrfTokenHelper::get($action);
}
$edit_url = (new CUrl('zabbix.php'))->setArgument('action', 'customreports.schedule.edit');

$table = (new CTableInfo())->setHeader([
	_('Name'),
	_('Schedule'),
	_('Media type'),
	_('Format'),
	_('Send separately'),
	_('Recipients'),
	_('Status'),
	_('Last run'),
	_('Result'),
	_('Actions')
]);

foreach ($data['schedules'] as $scheduleid => $schedule) {
	$state = $data['state'][$scheduleid] ?? [];

	$when = $schedule['frequency'] === ScheduleHelper::FREQUENCY_WEEKLY
		? _s('Weekly on %1$s at %2$s', $weekdays[$schedule['weekday']], $schedule['time'])
		: _s('Daily at %1$s', $schedule['time']);

	$recipients = [];

	if ($schedule['userids']) {
		$recipients[] = _n('%1$s user', '%1$s users', count($schedule['userids']));
	}

	if ($schedule['usrgrpids']) {
		$recipients[] = _n('%1$s user group', '%1$s user groups', count($schedule['usrgrpids']));
	}

	if ($schedule['extra_sendto']) {
		$recipients[] = _n('%1$s address', '%1$s addresses', count($schedule['extra_sendto']));
	}

	if (($schedule['split'] ?? '') === ReportSplitter::SPLIT_GROUP && !empty($schedule['group_recipients'])) {
		$recipients[] = _n('%1$s host group rule', '%1$s host group rules', count($schedule['group_recipients']));
	}

	if (($schedule['split'] ?? '') === ReportSplitter::SPLIT_HOST && !empty($schedule['host_recipients'])) {
		$recipients[] = _n('%1$s host rule', '%1$s host rules', count($schedule['host_recipients']));
	}

	$last_run = max((int) ($state['last_run'] ?? 0), (int) ($state['last_manual_run'] ?? 0));
	$result = '';

	if (array_key_exists('last_status', $state)) {
		$result = (new CSpan($state['last_status'] === 'ok' ? _('OK') : _('Error')))
			->addClass($state['last_status'] === 'ok' ? ZBX_STYLE_GREEN : ZBX_STYLE_RED)
			->setHint($state['last_message'] ?? '');
	}

	$run_form = (new CForm('post', (new CUrl('zabbix.php'))->getUrl()))
		->addClass('cr-inline-form')
		->addVar('action', 'customreports.schedule.run')
		->addVar('scheduleid', $scheduleid)
		->addVar(CCsrfTokenHelper::CSRF_TOKEN_NAME, $csrf_tokens['customreports.schedule.run'])
		->setAttribute('data-confirm', _s('Send report "%1$s" to all its recipients now?', $schedule['name']))
		->addItem((new CSubmit('run', _('Send now')))->addClass(ZBX_STYLE_BTN_LINK));

	$delete_form = (new CForm('post', (new CUrl('zabbix.php'))->getUrl()))
		->addClass('cr-inline-form')
		->addVar('action', 'customreports.schedule.delete')
		->addVar('scheduleid', $scheduleid)
		->addVar(CCsrfTokenHelper::CSRF_TOKEN_NAME, $csrf_tokens['customreports.schedule.delete'])
		->setAttribute('data-confirm', _s('Delete schedule "%1$s"?', $schedule['name']))
		->addItem((new CSubmit('delete', _('Delete')))->addClass(ZBX_STYLE_BTN_LINK));

	$table->addRow([
		new CLink($schedule['name'], (clone $edit_url)->setArgument('scheduleid', $scheduleid)->getUrl()),
		$when,
		array_key_exists($schedule['mediatypeid'], $data['media_types'])
			? $data['media_types'][$schedule['mediatypeid']]['name']
			: (new CSpan(_('Inaccessible media type')))->addClass(ZBX_STYLE_RED),
		$format_labels[$schedule['format']] ?? $schedule['format'],
		$split_labels[$schedule['split'] ?? ReportSplitter::SPLIT_NONE],
		implode(', ', $recipients),
		$schedule['enabled']
			? (new CSpan(_('Enabled')))->addClass(ZBX_STYLE_GREEN)
			: (new CSpan(_('Disabled')))->addClass(ZBX_STYLE_RED),
		$last_run > 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $last_run) : _('Never'),
		$result,
		(new CCol([$run_form, $delete_form]))->addClass(ZBX_STYLE_NOWRAP)
	]);
}

$page = (new CHtmlPage())
	->setTitle(_('Custom Problem Reports'))
	->setControls(
		(new CTag('nav', true,
			(new CList())->addItem(new CRedirectButton(_('Create schedule'), $edit_url->getUrl()))
		))->setAttribute('aria-label', _('Content controls'))
	)
	->addItem(FrontendHelper::makeTabs('customreports.schedule.list', true));

if ($data['error'] !== null) {
	$page->addItem(makeMessageBox(ZBX_STYLE_MSG_BAD, [['message' => $data['error']]],
		_('Cannot read schedule storage'), true, false
	));
}

// Cron job of the sender.
$cron = $data['cron'];
$heartbeat = $cron['heartbeat'];

if ($heartbeat === null) {
	$cron_status = (new CSpan(_('Never run: the sender is not scheduled in cron yet')))->addClass(ZBX_STYLE_RED);
}
elseif (time() - (int) $heartbeat['time'] <= CronManager::HEARTBEAT_MAX_AGE) {
	$cron_status = (new CSpan(_s('Running: last run %1$s ago as user "%2$s"', zbx_date2age((int) $heartbeat['time']),
		$heartbeat['user'] ?? '?'
	)))->addClass(ZBX_STYLE_GREEN);
}
else {
	$cron_status = (new CSpan(_s('Not running: last run %1$s ago (%2$s)', zbx_date2age((int) $heartbeat['time']),
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
		->setAttribute('data-confirm', _('Remove the cron job? Scheduled reports will stop being sent.'))
		->addItem((new CSubmit('cron', _('Remove cron job')))->addClass(ZBX_STYLE_BTN_ALT));
	$user_status = (new CSpan(_('Installed')))->addClass(ZBX_STYLE_GREEN);
}
else {
	$cron_form
		->addVar('operation', 'install')
		->addItem(new CSubmit('cron', _('Create cron job')));
	$user_status = (new CSpan(_('Not installed')))->addClass(ZBX_STYLE_GREY);
}

$user_row = [$user_status, ' ', $cron_form];

if ($cron['error'] !== null) {
	$user_row[] = (new CDiv(_s('Cannot read crontab: %1$s', $cron['error'])))->addClass(ZBX_STYLE_RED);
}

$cron_list = (new CFormList('cron_form_list'))
	->addRow(_('Sender status'), $cron_status)
	->addRow(_s('Crontab of user "%1$s"', $cron['user']), $user_row)
	->addRow($cron['cron_d_file'], [
		$cron['cron_d_installed']
			? (new CSpan(_('Found')))->addClass(ZBX_STYLE_GREEN)
			: (new CSpan(_('Not found')))->addClass(ZBX_STYLE_GREY),
		(new CDiv(_s('Alternative to the button above: as root, put this line into %1$s', $cron['cron_d_file'])))
			->addClass(ZBX_STYLE_GREY),
		(new CTextArea('cron_d_line', $cron['cron_d_line']))
			->setReadonly(true)
			->setRows(2)
			->setWidth(ZBX_TEXTAREA_BIG_WIDTH)
	]);

if ($cron['user_installed'] && $cron['cron_d_installed']) {
	$cron_list->addRow('', (new CDiv(_s('The sender is scheduled twice (crontab of "%1$s" and %2$s). Reports are not duplicated thanks to locking, but one of the entries should be removed.',
		$cron['user'], $cron['cron_d_file']
	)))->addClass(ZBX_STYLE_ORANGE));
}

$page
	->addItem((new CDiv(_s('Schedule times are evaluated in the "%1$s" timezone by the cron job bin/send_reports.php. Storage: %2$s',
		$data['timezone'], $data['data_dir']
	)))->addClass('cr-note'))
	->addItem($table)
	->addItem((new CDiv([new CTag('h4', true, _('Cron job')), $cron_list]))->addClass('cr-cron'))
	->show();
