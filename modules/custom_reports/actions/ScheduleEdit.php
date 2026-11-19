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
use Modules\CustomReports\Lib\PeriodAnalytics;
use Modules\CustomReports\Lib\ReportBuilder;
use Modules\CustomReports\Lib\ReportRenderer;
use Modules\CustomReports\Lib\ReportSplitter;
use Modules\CustomReports\Lib\ScheduleHelper;
use Modules\CustomReports\Lib\ScheduleStorage;
use Modules\CustomReports\Lib\ScriptReport;

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
			'source' =>				'in '.ScheduleHelper::SOURCE_PROBLEMS.','.ScheduleHelper::SOURCE_SCRIPT,
			'script' =>				'string',
			'script_params' =>		'string',
			'script_format' =>		'in '.implode(',', ScriptReport::FORMATS),
			'groupids' =>			'array_id',
			'hostids' =>			'array_id',
			'severities' =>			'array',
			'period' =>				'in '.implode(',', array_keys(Period::LABELS)),
			'show_suppressed' =>	'in 0,1',
			'problem_name' =>		'string',
			'acknowledged' =>		'in '.ReportBuilder::ACK_ALL.','.ReportBuilder::ACK_NO.','.ReportBuilder::ACK_YES,
			'sortfield' =>			'in '.implode(',', ReportBuilder::SORT_FIELDS),
			'sortorder' =>			'in '.ReportBuilder::SORT_ASC.','.ReportBuilder::SORT_DESC,
			'analytics' =>			'in '.implode(',', PeriodAnalytics::PERIODS),
			'problem_list' =>		'in '.ReportBuilder::LIST_FULL.','.ReportBuilder::LIST_SUMMARY,
			'include_changes' =>	'in 0,1',
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
			'host_recipients' =>	'array',
			'common_recipients' =>	'in '.ScheduleHelper::COMMON_ALL.','.ScheduleHelper::COMMON_FALLBACK,
			'rule_list' =>			'in group_recipients,host_recipients',
			'rule_add' =>			'in 1',
			'rule_remove' =>		'int32'
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
				CMessageHelper::setErrorTitle(cr_t('No permissions to referred object or it does not exist!'));
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
			$input['include_changes'] = $this->getInput('include_changes', 0);

			foreach (['groupids', 'hostids', 'severities', 'userids', 'usrgrpids'] as $key) {
				$input[$key] = $this->getInput($key, []);
			}

			$schedule = ScheduleHelper::normalize(array_replace($schedule, $input));
		}

		// Rows of the "Recipients per host group / per host" tables, including rows that are not filled in yet.
		$rule_ids = ['groupids' => [], 'hostids' => [], 'userids' => [], 'usrgrpids' => []];

		foreach (ScheduleHelper::RECIPIENT_RULES as ['field' => $field, 'key' => $key]) {
			$rules = $this->hasInput('form_refresh')
				? ScheduleHelper::normalizeRecipientRules($this->getInput($field, []), $key, true)
				: $schedule[$field];

			if ($this->getInput('rule_list', '') === $field) {
				if ($this->hasInput('rule_remove')) {
					unset($rules[$this->getInput('rule_remove')]);
					$rules = array_values($rules);
				}

				if ($this->hasInput('rule_add')) {
					$rules[] = [$key => [], 'userids' => [], 'usrgrpids' => [], 'extra_sendto' => []];
				}
			}

			$schedule[$field] = $rules;

			foreach ($rules as $rule) {
				foreach ([$key, 'userids', 'usrgrpids'] as $id_key) {
					$rule_ids[$id_key] = array_merge($rule_ids[$id_key], $rule[$id_key]);
				}
			}
		}

		$schedule['scheduleid'] = $scheduleid;

		$response = new CControllerResponseData([
			'schedule' => $schedule,
			'rule_names' => [
				'groupids' => array_column($this->getMultiselectData('hostgroup', array_unique($rule_ids['groupids'])),
					null, 'id'
				),
				'hostids' => array_column($this->getMultiselectData('host', array_unique($rule_ids['hostids'])), null,
					'id'
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
			'scripts' => ScriptReport::listScripts(ModuleConfig::get()),
			'scripts_path' => (string) ModuleConfig::get()['external_scripts_path'],
			'timezone' => ModuleConfig::timezone(),
			'timezone_problem' => ModuleConfig::timezoneProblem()
		]);
		$response->setTitle($scheduleid === '' ? cr_t('New report schedule') : cr_t('Report schedule'));
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
