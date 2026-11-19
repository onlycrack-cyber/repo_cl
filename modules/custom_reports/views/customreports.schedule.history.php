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

foreach ($data['history'] as $index => $run) {
	$type = FrontendHelper::triggerLabel($run['trigger']);

	if ($run['user'] !== '') {
		$type .= ' ('.$run['user'].')';
	}

	$table->addRow([
		(new CCol(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $run['time'])))->addClass(ZBX_STYLE_NOWRAP),
		$type,
		FrontendHelper::makeStatus($run['status']),
		$run['parts'],
		$run['sent'],
		$run['failed'] > 0 ? (new CSpan($run['failed']))->addClass(ZBX_STYLE_RED) : 0,
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
					: (new CSpan(cr_t('Failed')))->addClass(ZBX_STYLE_RED),
				zbx_date2str(DATE_TIME_FORMAT_SECONDS, $delivery['time']),
				(new CSpan($delivery['error']))->addClass(ZBX_STYLE_RED)
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
	->addItem((new CDiv(cr_s('Last %1$s runs are kept: scheduled (cron), "Send now" and "Test to me".',
		ScheduleHelper::HISTORY_RUNS
	)))->addClass('cr-note'))
	->addItem($table)
	->show();
