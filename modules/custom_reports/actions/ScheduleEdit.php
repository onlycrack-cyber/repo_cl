<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Actions;

use API;
use CArrayHelper;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CControllerResponseRedirect;
use CMessageHelper;
use CUrl;
use Exception;
use Modules\CustomReports\Lib\Dispatcher;
use Modules\CustomReports\Lib\ModuleConfig;
use Modules\CustomReports\Lib\Period;
use Modules\CustomReports\Lib\ReportRenderer;
use Modules\CustomReports\Lib\ReportSplitter;
use Modules\CustomReports\Lib\ScheduleHelper;
use Modules\CustomReports\Lib\ScheduleStorage;

/**
 * Create / edit form of a report schedule.
 */
class ScheduleEdit extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'scheduleid' =>			'string',
			'form_refresh' =>		'in 1',
			'name' =>				'string',
			'enabled' =>			'in 0,1',
			'groupids' =>			'array_id',
			'hostids' =>			'array_id',
			'severities' =>			'array',
			'period' =>				'in '.implode(',', array_keys(Period::LABELS)),
			'show_suppressed' =>	'in 0,1',
			'frequency' =>			'in '.ScheduleHelper::FREQUENCY_DAILY.','.ScheduleHelper::FREQUENCY_WEEKLY,
			'weekday' =>			'in 1,2,3,4,5,6,7',
			'time' =>				'string',
			'userids' =>			'array_id',
			'usrgrpids' =>			'array_id',
			'extra_sendto' =>		'string',
			'mediatypeid' =>		'id',
			'format' =>				'in '.implode(',', ReportRenderer::FORMATS),
			'split' =>				'in '.implode(',', ReportSplitter::MODES),
			'skip_empty' =>			'in 0,1',
			'group_recipients' =>	'array',
			'common_recipients' =>	'in '.ScheduleHelper::COMMON_ALL.','.ScheduleHelper::COMMON_FALLBACK,
			'add_group_recipient' =>	'in 1',
			'remove_group_recipient' =>	'int32'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$scheduleid = $this->getInput('scheduleid', '');
		$schedule = ScheduleHelper::defaults();

		if ($scheduleid !== '') {
			try {
				$stored = (new ScheduleStorage(ModuleConfig::get()['data_dir']))->get($scheduleid);
			}
			catch (Exception $e) {
				$stored = null;
				CMessageHelper::addError($e->getMessage());
			}

			if ($stored === null) {
				CMessageHelper::setErrorTitle(_('No permissions to referred object or it does not exist!'));
				$this->setResponse(new CControllerResponseRedirect(
					(new CUrl('zabbix.php'))->setArgument('action', 'customreports.schedule.list')
				));

				return;
			}

			$schedule = $stored;
		}

		// Values submitted before a failed validation in customreports.schedule.update.
		if ($this->hasInput('form_refresh')) {
			$input = $this->getInputAll();
			$input['enabled'] = $this->getInput('enabled', 0);
			$input['show_suppressed'] = $this->getInput('show_suppressed', 0);
			$input['skip_empty'] = $this->getInput('skip_empty', 0);

			foreach (['groupids', 'hostids', 'severities', 'userids', 'usrgrpids'] as $key) {
				$input[$key] = $this->getInput($key, []);
			}

			$schedule = ScheduleHelper::normalize(array_replace($schedule, $input));
		}

		// Rows of the "Recipients per host group" table, including rows that are not filled in yet.
		$rules = $this->hasInput('form_refresh')
			? ScheduleHelper::normalizeGroupRecipients($this->getInput('group_recipients', []), true)
			: $schedule['group_recipients'];

		if ($this->hasInput('remove_group_recipient')) {
			unset($rules[$this->getInput('remove_group_recipient')]);
			$rules = array_values($rules);
		}

		if ($this->hasInput('add_group_recipient')) {
			$rules[] = ['groupids' => [], 'userids' => [], 'usrgrpids' => [], 'extra_sendto' => []];
		}

		$schedule['group_recipients'] = $rules;
		$schedule['scheduleid'] = $scheduleid;

		$rule_ids = ['groupids' => [], 'userids' => [], 'usrgrpids' => []];

		foreach ($rules as $rule) {
			foreach ($rule_ids as $key => $ids) {
				$rule_ids[$key] = array_merge($ids, $rule[$key]);
			}
		}

		$response = new CControllerResponseData([
			'schedule' => $schedule,
			'rule_names' => [
				'groupids' => array_column($this->getMultiselectData('hostgroup', array_unique($rule_ids['groupids'])),
					null, 'id'
				),
				'userids' => array_column($this->getMultiselectData('user', array_unique($rule_ids['userids'])), null,
					'id'
				),
				'usrgrpids' => array_column($this->getMultiselectData('usergroup', array_unique($rule_ids['usrgrpids'])),
					null, 'id'
				)
			],
			'groups' => $this->getMultiselectData('hostgroup', $schedule['groupids']),
			'hosts' => $this->getMultiselectData('host', $schedule['hostids']),
			'users' => $this->getMultiselectData('user', $schedule['userids']),
			'usrgrps' => $this->getMultiselectData('usergroup', $schedule['usrgrpids']),
			'media_types' => $this->getMediaTypes(),
			'timezone' => ModuleConfig::timezone()
		]);
		$response->setTitle($scheduleid === '' ? _('New report schedule') : _('Report schedule'));
		$this->setResponse($response);
	}

	private function getMultiselectData(string $object, array $ids): array {
		if (!$ids) {
			return [];
		}

		switch ($object) {
			case 'hostgroup':
				$rows = API::HostGroup()->get(['output' => ['groupid', 'name'], 'groupids' => $ids]);

				return CArrayHelper::renameObjectsKeys($rows, ['groupid' => 'id']);

			case 'host':
				$rows = API::Host()->get(['output' => ['hostid', 'name'], 'hostids' => $ids]);

				return CArrayHelper::renameObjectsKeys($rows, ['hostid' => 'id']);

			case 'user':
				$rows = API::User()->get(['output' => ['userid', 'username', 'name', 'surname'], 'userids' => $ids]);
				$result = [];

				foreach ($rows as $row) {
					$result[] = ['id' => $row['userid'], 'name' => getUserFullname($row)];
				}

				return $result;

			case 'usergroup':
				$rows = API::UserGroup()->get(['output' => ['usrgrpid', 'name'], 'usrgrpids' => $ids]);

				return CArrayHelper::renameObjectsKeys($rows, ['usrgrpid' => 'id']);
		}

		return [];
	}

	/**
	 * Active media types that the module can deliver through.
	 */
	private function getMediaTypes(): array {
		$media_types = API::MediaType()->get([
			'output' => 'extend',
			'filter' => ['status' => MEDIA_TYPE_STATUS_ACTIVE]
		]);

		$result = [];

		foreach ($media_types as $media_type) {
			$channel = Dispatcher::getChannel($media_type);

			if ($channel !== Dispatcher::CHANNEL_UNSUPPORTED) {
				$result[$media_type['mediatypeid']] = $media_type['name'].' ('.$channel.')';
			}
		}

		natcasesort($result);

		return $result;
	}
}
