<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


/**
 * @var CView $this
 * @var array $data
 */

use Modules\CustomReports\Lib\FrontendHelper;
use Modules\CustomReports\Lib\ReportRenderer;
use Modules\CustomReports\Lib\ScheduleHelper;

$format_labels = [
	ReportRenderer::FORMAT_SUMMARY => _('Short summary'),
	ReportRenderer::FORMAT_HTML => _('Detailed HTML'),
	ReportRenderer::FORMAT_CSV => _('CSV')
];

$weekdays = [1 => _('Monday'), 2 => _('Tuesday'), 3 => _('Wednesday'), 4 => _('Thursday'), 5 => _('Friday'),
	6 => _('Saturday'), 7 => _('Sunday')
];

$csrf_token = CCsrfTokenHelper::get('customreports');
$edit_url = (new CUrl('zabbix.php'))->setArgument('action', 'customreports.schedule.edit');

$table = (new CTableInfo())->setHeader([
	_('Name'),
	_('Schedule'),
	_('Media type'),
	_('Format'),
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
		->addVar(CCsrfTokenHelper::CSRF_TOKEN_NAME, $csrf_token)
		->setAttribute('data-confirm', _s('Send report "%1$s" to all its recipients now?', $schedule['name']))
		->addItem((new CSubmit('run', _('Send now')))->addClass(ZBX_STYLE_BTN_LINK));

	$delete_form = (new CForm('post', (new CUrl('zabbix.php'))->getUrl()))
		->addClass('cr-inline-form')
		->addVar('action', 'customreports.schedule.delete')
		->addVar('scheduleid', $scheduleid)
		->addVar(CCsrfTokenHelper::CSRF_TOKEN_NAME, $csrf_token)
		->setAttribute('data-confirm', _s('Delete schedule "%1$s"?', $schedule['name']))
		->addItem((new CSubmit('delete', _('Delete')))->addClass(ZBX_STYLE_BTN_LINK));

	$table->addRow([
		new CLink($schedule['name'], (clone $edit_url)->setArgument('scheduleid', $scheduleid)->getUrl()),
		$when,
		array_key_exists($schedule['mediatypeid'], $data['media_types'])
			? $data['media_types'][$schedule['mediatypeid']]['name']
			: (new CSpan(_('Inaccessible media type')))->addClass(ZBX_STYLE_RED),
		$format_labels[$schedule['format']] ?? $schedule['format'],
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

$page
	->addItem((new CDiv(_s('Schedule times are evaluated in the "%1$s" timezone by the cron job bin/send_reports.php. Storage: %2$s',
		$data['timezone'], $data['data_dir']
	)))->addClass('cr-note'))
	->addItem($table)
	->show();
