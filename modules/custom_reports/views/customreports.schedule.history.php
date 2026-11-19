<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


/**
 * Delivery history of a schedule.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\CustomReports\Lib\FrontendHelper;
use Modules\CustomReports\Lib\ReportRenderer;
use Modules\CustomReports\Lib\ScheduleHelper;

$schedule = $data['schedule'];
$list_url = (new CUrl('zabbix.php'))->setArgument('action', 'customreports.schedule.list')->getUrl();

// Summary of the schedule.
$summary = (new CFormList())
	->addRow(cr_t('Schedule'), new CLink($schedule['name'],
		(new CUrl('zabbix.php'))
			->setArgument('action', 'customreports.schedule.edit')
			->setArgument('scheduleid', $schedule['scheduleid'])
			->getUrl()
	))
	->addRow(cr_t('Media type'), $data['media_type'] ?? (new CSpan(cr_t('Inaccessible media type')))->addClass(ZBX_STYLE_RED))
	->addRow(cr_t('Last delivery'), FrontendHelper::makeStatus($data['state']['last_status'] ?? null))
	->addRow(cr_t('Next run'), $data['next_run'] !== null
		? zbx_date2str(DATE_TIME_FORMAT, $data['next_run'])
		: (new CSpan(cr_t('Disabled')))->addClass(ZBX_STYLE_RED)
	)
	->addRow('', (new CDiv(cr_t('"OK" means that the mail server or Telegram accepted the message. If a mailbox rejects it later (bounce), the module does not know about it.')))
		->addClass(ZBX_STYLE_GREY)
	);

// Runs, newest first; recipients of a run are shown by "Details".
$table = (new CTableInfo())
	->setHeader([cr_t('Time'), cr_t('Type'), cr_t('Status'), cr_t('Reports'), cr_t('Sent'), cr_t('Failures'), cr_t('Duration'), cr_t('Details')])
	->setNoDataMessage(cr_t('No deliveries yet.'));

$resend_csrf = CCsrfTokenHelper::get('customreports.schedule.resend');
$pending = ScheduleHelper::pendingResends($data['history']);

foreach ($data['history'] as $index => $run) {
	$type = FrontendHelper::triggerLabel($run['trigger']);
	$resend_count = count($pending[$index]);

	// Failed deliveries of the run can be sent again (a fresh report, only to those who did not get it).
	$resend = $resend_count > 0
		? (new CForm('post', (new CUrl('zabbix.php'))->getUrl()))
			->addClass('cr-inline-form')
			->addVar('action', 'customreports.schedule.resend')
			->addVar('scheduleid', $schedule['scheduleid'])
			->addVar('run_time', $run['time'])
			->addVar(CCsrfTokenHelper::CSRF_TOKEN_NAME, $resend_csrf)
			->setAttribute('data-confirm', cr_s('Send the report again to %1$s recipient(s) that did not get it?',
				$resend_count
			))
			->addItem((new CSubmit('resend', cr_s('Resend (%1$s)', $resend_count)))->addClass(ZBX_STYLE_BTN_LINK))
		: null;

	if ($run['user'] !== '') {
		$type .= ' ('.$run['user'].')';
	}

	$table->addRow([
		(new CCol(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $run['time'])))->addClass(ZBX_STYLE_NOWRAP),
		$type,
		FrontendHelper::makeStatus($run['status']),
		$run['parts'],
		$run['sent'],
		$run['failed'] > 0 ? [(new CSpan($run['failed']))->addClass(ZBX_STYLE_RED), $resend !== null ? ' ' : null, $resend] : 0,
		$run['duration'] < 60 ? $run['duration'].cr_t('s') : ReportRenderer::formatDuration($run['duration']),
		($run['deliveries'] || $run['message'] !== '')
			? (new CLinkAction(cr_t('Details')))
				->addClass('js-cr-history-toggle')
				->setAttribute('data-target', 'cr-run-'.$index)
			: ''
	]);

	// Details: the error that stopped the run and one line per recipient.
	$details = [];

	if ($run['message'] !== '') {
		$details[] = (new CDiv($run['message']))->addClass(ZBX_STYLE_RED);
	}

	if ($run['deliveries']) {
		$recipients = (new CTable())
			->addClass('cr-history-recipients')
			->setHeader([cr_t('Report part'), cr_t('Recipient'), cr_t('Status'), cr_t('Time'), cr_t('Error')]);

		foreach ($run['deliveries'] as $delivery) {
			$recipients->addRow([
				$delivery['part'] !== '' ? $delivery['part'] : '-',
				$delivery['recipient'] !== '' ? $delivery['recipient'] : (new CSpan(cr_t('no recipients')))->addClass(ZBX_STYLE_GREY),
				$delivery['status'] === 'sent'
					? (new CSpan(cr_t('Sent')))->addClass(ZBX_STYLE_GREEN)
					: ($delivery['status'] === 'skipped'
						? (new CSpan(cr_t('Skipped')))->addClass(ZBX_STYLE_GREY)
						: (new CSpan(cr_t('Failed')))->addClass(ZBX_STYLE_RED)
					),
				zbx_date2str(DATE_TIME_FORMAT_SECONDS, $delivery['time']),
				[
					(new CSpan($delivery['error']))->addClass($delivery['status'] === 'skipped' ? ZBX_STYLE_GREY : ZBX_STYLE_RED),
					($delivery['attempts'] ?? 1) > 1
						? (new CSpan(' '.cr_s('(attempts: %1$s)', $delivery['attempts'])))->addClass(ZBX_STYLE_GREY)
						: null
				]
			]);
		}

		$details[] = $recipients;

		if ($run['deliveries_total'] > count($run['deliveries'])) {
			$details[] = (new CDiv(cr_s('Only the first %1$s of %2$s recipients are kept.', count($run['deliveries']),
				$run['deliveries_total']
			)))->addClass(ZBX_STYLE_GREY);
		}
	}

	if ($details) {
		$table->addRow(
			(new CRow((new CCol($details))->setColSpan(8)))
				->setId('cr-run-'.$index)
				->addClass('cr-history-details')
				->addStyle('display: none;')
		);
	}
}

(new CHtmlPage())
	->setTitle(cr_s('Delivery history: %1$s', $schedule['name']))
	->setControls(
		(new CTag('nav', true, (new CList())->addItem(new CRedirectButton(cr_t('Back to schedules'), $list_url))))
			->setAttribute('aria-label', cr_t('Content controls'))
	)
	->addItem(FrontendHelper::makeTabs('customreports.schedule.list', true))
	->addItem($summary)
	->addItem((new CDiv(cr_s('Last %1$s runs are kept: scheduled (cron), "Send now", "Resend" and "Test".',
		ScheduleHelper::HISTORY_RUNS
	)))->addClass('cr-note'))
	->addItem($table)
	->show();
