<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


/**
 * "Test" of a schedule: choose who gets the test report.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\CustomReports\Lib\FrontendHelper;
use Modules\CustomReports\Lib\ScheduleHelper;

$this->addJsFile('multiselect.js');

$schedule = $data['schedule'];
$form_name = 'customreports_test';
$list_url = (new CUrl('zabbix.php'))->setArgument('action', 'customreports.schedule.list')->getUrl();
$user_media = ($schedule['media_mode'] ?? ScheduleHelper::MEDIA_FIXED) === ScheduleHelper::MEDIA_USER;

$form = (new CForm('post', (new CUrl('zabbix.php'))->getUrl()))
	->setName($form_name)
	->setId($form_name)
	->addVar('action', 'customreports.schedule.test')
	->addVar('scheduleid', $schedule['scheduleid'])
	->addVar(CCsrfTokenHelper::CSRF_TOKEN_NAME, CCsrfTokenHelper::get('customreports.schedule.test'));

$schedule_media = $user_media
	? cr_t('own media of every user')
	: ($data['media_types'][$schedule['mediatypeid']] ?? cr_t('Inaccessible media type'));

$form_list = (new CFormList())
	->addRow(cr_t('Schedule'), [
		new CLink($schedule['name'], (new CUrl('zabbix.php'))
			->setArgument('action', 'customreports.schedule.edit')
			->setArgument('scheduleid', $schedule['scheduleid'])
			->getUrl()
		),
		(new CDiv(cr_s('The test report is built with all settings of the schedule (filter, format, split); it goes to the delivery history and does not change the status of the schedule. Media of the schedule: %1$s.',
			$schedule_media
		)))->addClass(ZBX_STYLE_GREY)
	])
	->addRow(cr_t('Send to'),
		(new CRadioButtonList('test_target', 'me'))
			->addValue(cr_t('Me'), 'me')
			->addValue(cr_t('Users'), 'users')
			->addValue(cr_t('Address'), 'address')
			->setModern(true)
	)
	->addRow(cr_t('My media'),
		$data['my_media']
			? (new CDiv(implode("\n", $data['my_media'])))
				->addStyle('white-space: pre-line;')
				->addItem((new CDiv($user_media
					? cr_t('The report goes through all your active media listed here.')
					: cr_t('The report goes through your media of the media type of the schedule.')
				))->addClass(ZBX_STYLE_GREY))
			: (new CSpan(cr_t('To send a test report, add an active Email, Telegram or Script media to your user profile.')))
				->addClass(ZBX_STYLE_RED),
		'cr-test-me'
	)
	->addRow(new CLabel(cr_t('Users'), 'test_userids__ms'), [
		(new CMultiSelect([
			'name' => 'test_userids[]',
			'object_name' => 'users',
			'data' => [],
			'popup' => [
				'parameters' => [
					'srctbl' => 'users',
					'srcfld1' => 'userid',
					'srcfld2' => 'fullname',
					'dstfrm' => $form_name,
					'dstfld1' => 'test_userids_'
				]
			]
		]))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH),
		(new CDiv($user_media
			? cr_t('Each user gets the report through his own active media.')
			: cr_t('The users get the report at their addresses of the media type of the schedule.')
		))->addClass(ZBX_STYLE_GREY)
	], 'cr-test-users')
	->addRow(new CLabel(cr_t('Media type'), 'label-test_mediatypeid'), [
		(new CSelect('test_mediatypeid'))
			->setId('test_mediatypeid')
			->setFocusableElementId('label-test_mediatypeid')
			->addOptions(CSelect::createOptionsFromArray([0 => cr_t('Select media type')] + $data['media_types']))
			->setValue($user_media || $schedule['mediatypeid'] === '0' ? 0 : $schedule['mediatypeid'])
	], 'cr-test-address-type')
	->addRow(new CLabel(cr_t('Addresses'), 'test_sendto'), [
		(new CTextArea('test_sendto', ''))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setRows(3)
			->setAttribute('placeholder', cr_t('One address per line: e-mail, Telegram chat ID, etc.')),
		(new CDiv(cr_t('Any active Email, Script or Telegram webhook media type; the address format depends on it (e-mail for Email, chat ID for Telegram).')))
			->addClass(ZBX_STYLE_GREY)
	], 'cr-test-address');

$form->addItem(
	(new CTabView())
		->addTab('test_tab', cr_t('Test delivery'), $form_list)
		->setFooter(makeFormFooter(
			new CSubmit('send', cr_t('Send')),
			[(new CRedirectButton(cr_t('Cancel'), $list_url))->setId('cancel')]
		))
);

(new CHtmlPage())
	->setTitle(cr_t('Test delivery'))
	->addItem(FrontendHelper::makeTabs('customreports.schedule.list', true))
	->addItem($form)
	->show();
