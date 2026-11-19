<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


/**
 * "Scripts" tab: run an external script and show its output as a report.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\CustomReports\Lib\FrontendHelper;
use Modules\CustomReports\Lib\ScheduleHelper;
use Modules\CustomReports\Lib\ScriptReport;
use Modules\CustomReports\Lib\ScriptReportRenderer;

$params_text = implode("\n", $data['script_params']);

$form = (new CForm('post', (new CUrl('zabbix.php'))->setArgument('action', 'customreports.script')->getUrl()))
	->setName('customreports_script')
	->addVar(CCsrfTokenHelper::CSRF_TOKEN_NAME, CCsrfTokenHelper::get('customreports.script'))
	->addVar('run', '1');

if ($data['scripts']) {
	$script_options = $data['scripts'];

	if ($data['script'] !== '' && !in_array($data['script'], $script_options, true)) {
		array_unshift($script_options, $data['script']);
	}

	$script_field = (new CSelect('script'))
		->setId('script')
		->setFocusableElementId('label-script')
		->addOptions(CSelect::createOptionsFromArray(array_combine($script_options, $script_options)))
		->setValue($data['script']);
}
else {
	$script_field = (new CSpan(cr_s('No executable files in %1$s (set "external_scripts_path" in conf/config.php).',
		$data['scripts_path']
	)))->addClass(ZBX_STYLE_RED);
}

$form_list = (new CFormList())
	->addRow(new CLabel(cr_t('Script'), 'label-script'), [
		$script_field,
		(new CDiv(cr_s('Executable files of %1$s. The script runs on this server as user "%2$s"; schedules run it as the cron user.',
			$data['scripts_path'], $data['user']
		)))->addClass(ZBX_STYLE_GREY)
	])
	->addRow(new CLabel(cr_t('Parameters'), 'script_params'), [
		(new CTextArea('script_params', $params_text))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setRows(3)
			->setAttribute('placeholder', cr_t('one parameter per line')),
		(new CDiv(cr_t('Macros: {DATE}, {TIME}, {PERIOD_FROM}, {PERIOD_TILL} (Unix time), {PERIOD_FROM_DATE}, {PERIOD_TILL_DATE}, {SCHEDULE}. Here the period is the last 24 hours.')))
			->addClass(ZBX_STYLE_GREY)
	])
	->addRow(cr_t('Data format'),
		(new CRadioButtonList('script_format', $data['script_format']))
			->addValue(cr_t('Auto'), ScriptReport::FORMAT_AUTO)
			->addValue('JSON', ScriptReport::FORMAT_JSON)
			->addValue('CSV', ScriptReport::FORMAT_CSV)
			->addValue(cr_t('Text'), ScriptReport::FORMAT_TEXT)
			->setModern(true)
	)
	->addRow('', (new CSubmit('run_button', cr_t('Run')))->setEnabled((bool) $data['scripts']));

$form->addItem((new CTabView())->addTab('script_tab', null, $form_list));

$page = (new CHtmlPage())
	->setTitle(cr_t('Custom problem reports'))
	->addItem(FrontendHelper::makeTabs('customreports.script', true))
	->addItem($form);

if ($data['error'] !== null) {
	$page->addItem(makeMessageBox(ZBX_STYLE_MSG_BAD, [['message' => $data['error']]], cr_t('Cannot run the script'),
		true, false
	));
}

$report = $data['report'];

if ($report !== null) {
	// Export: the script runs again with the same parameters (POST with the CSRF token).
	$export = (new CList())->addClass('cr-script-actions');

	foreach (['csv' => cr_t('Download CSV'), 'html' => cr_t('Download HTML'), 'pdf' => cr_t('Download PDF'),
			'print' => cr_t('Print')] as $format => $label) {
		$export_form = (new CForm('post', (new CUrl('zabbix.php'))
			->setArgument('action', 'customreports.script.export')
			->getUrl()
		))->addClass('cr-inline-form');

		if ($format === 'print') {
			$export_form->setAttribute('target', '_blank');
		}

		$export->addItem(
			$export_form
				->addVar(CCsrfTokenHelper::CSRF_TOKEN_NAME, CCsrfTokenHelper::get('customreports.script.export'))
				->addVar('script', $report['script'])
				->addVar('script_params', $params_text)
				->addVar('script_format', $data['script_format'])
				->addVar('format', $format)
				->addItem((new CSubmit('export', $label))->addClass(ZBX_STYLE_BTN_ALT))
		);
	}

	// A new schedule with this script.
	$export->addItem(
		(new CLink(cr_t('Create schedule'), (new CUrl('zabbix.php'))
			->setArgument('action', 'customreports.schedule.edit')
			->setArgument('form_refresh', 1)
			->setArgument('enabled', 1)
			->setArgument('name', $report['title'] !== '' ? $report['title'] : $report['script'])
			->setArgument('source', ScheduleHelper::SOURCE_SCRIPT)
			->setArgument('script', $report['script'])
			->setArgument('script_params', $params_text)
			->setArgument('script_format', $data['script_format'])
			->getUrl()
		))->addClass(ZBX_STYLE_BTN_LINK)
	);

	$info = [
		cr_s('Format: %1$s', $report['format'] === ScriptReport::FORMAT_TEXT ? cr_t('Text') : strtoupper($report['format'])),
		cr_n('%1$s record', '%1$s records', (int) $report['total']),
		cr_s('run time: %1$s s', $report['duration'])
	];

	$result = (new CDiv([
		(new CDiv([
			new CTag('h4', true, $report['title'] !== '' ? $report['title'] : $report['script']),
			(new CSpan(implode(' · ', $info)))->addClass(ZBX_STYLE_GREY)
		]))->addClass('cr-script-head'),
		$export,
		$report['stderr'] !== ''
			? (new CDiv([
				new CTag('strong', true, cr_t('Messages of the script (stderr)')),
				(new CPre($report['stderr']))->addClass('cr-script-stderr')
			]))->addClass('cr-note')
			: null,
		// Self-contained HTML of the report (inline styles, all values escaped) - the same as in e-mails.
		(new CDiv(new CObject(ScriptReportRenderer::renderSection($report))))->addClass('cr-script-report')
	]))->addClass('cr-script-result');

	$page->addItem($result);
}

$page->show();
