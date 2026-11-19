<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Actions;

use CController;
use CControllerResponseFatal;
use CControllerResponseRedirect;
use CMessageHelper;
use CUrl;
use CWebUser;
use Exception;
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
 * Saves a report schedule (create or update).
 */
class ScheduleUpdate extends CController {

	protected function checkInput(): bool {
		$fields = [
			'scheduleid' =>			'string',
			'name' =>				'required|string',
			'enabled' =>			'in 0,1',
			'source' =>				'in '.ScheduleHelper::SOURCE_PROBLEMS.','.ScheduleHelper::SOURCE_SCRIPT,
			'media_mode' =>			'in '.ScheduleHelper::MEDIA_FIXED.','.ScheduleHelper::MEDIA_USER,
			'script' =>				'string',
			'script_params' =>		'string',
			'script_format' =>		'in '.implode(',', ScriptReport::FORMATS),
			'groupids' =>			'array_id',
			'hostids' =>			'array_id',
			'severities' =>			'array',
			'period' =>				'required|in '.implode(',', array_keys(Period::LABELS)),
			'show_suppressed' =>	'in 0,1',
			'problem_name' =>		'string',
			'tags' =>				'array',
			'evaltype' =>			'in 0,2',
			'acknowledged' =>		'in '.ReportBuilder::ACK_ALL.','.ReportBuilder::ACK_NO.','.ReportBuilder::ACK_YES,
			'sortfield' =>			'in '.implode(',', ReportBuilder::SORT_FIELDS),
			'sortorder' =>			'in '.ReportBuilder::SORT_ASC.','.ReportBuilder::SORT_DESC,
			'analytics' =>			'in '.implode(',', PeriodAnalytics::PERIODS),
			'problem_list' =>		'in '.ReportBuilder::LIST_FULL.','.ReportBuilder::LIST_SUMMARY,
			'include_changes' =>	'in 0,1',
			'frequency' =>			'required|in '.ScheduleHelper::FREQUENCY_DAILY.','.ScheduleHelper::FREQUENCY_WEEKLY,
			'weekday' =>			'in 1,2,3,4,5,6,7',
			'time' =>				'required|string',
			'userids' =>			'array_id',
			'usrgrpids' =>			'array_id',
			'extra_sendto' =>		'string',
			'mediatypeid' =>		'required|id',
			'format' =>				'required|in '.implode(',', ReportRenderer::FORMATS),
			'split' =>				'required|in '.implode(',', ReportSplitter::MODES),
			'skip_empty' =>			'in 0,1',
			'recipient_access' =>	'in 0,1',
			'group_recipients' =>	'array',
			'host_recipients' =>	'array',
			'common_recipients' =>	'in '.ScheduleHelper::COMMON_ALL.','.ScheduleHelper::COMMON_FALLBACK
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
		$storage = new ScheduleStorage(ModuleConfig::get()['data_dir']);
		$scheduleid = $this->getInput('scheduleid', '');

		$input = [
			'scheduleid' => $scheduleid,
			'enabled' => $this->getInput('enabled', 0),
			'show_suppressed' => $this->getInput('show_suppressed', 0),
			'skip_empty' => $this->getInput('skip_empty', 0),
			'recipient_access' => $this->getInput('recipient_access', 0),
			'include_changes' => $this->getInput('include_changes', 0),
			'created_by' => CWebUser::$data['userid']
		];
		$this->getInputs($input, ['name', 'source', 'script', 'script_params', 'script_format', 'media_mode', 'groupids', 'hostids', 'severities', 'period', 'frequency', 'weekday', 'time',
			'userids', 'usrgrpids', 'extra_sendto', 'mediatypeid', 'format', 'split', 'group_recipients', 'host_recipients', 'problem_name', 'tags', 'evaltype', 'acknowledged',
			'sortfield', 'sortorder', 'analytics', 'problem_list',
			'common_recipients'
		]);

		$schedule = ScheduleHelper::normalize($input);
		$errors = ScheduleHelper::validate($schedule);

		// The script must exist here; the cron sender checks it again when it runs.
		if (!$errors && $schedule['source'] === ScheduleHelper::SOURCE_SCRIPT) {
			try {
				ScriptReport::getPath(ModuleConfig::get(), $schedule['script']);
			}
			catch (Exception $e) {
				$errors[] = $e->getMessage();
			}
		}

		if (!$errors) {
			try {
				if ($scheduleid !== '') {
					$stored = $storage->get($scheduleid);

					if ($stored === null) {
						throw new Exception(cr_t('No permissions to referred object or it does not exist!'));
					}

					$schedule['created_by'] = $stored['created_by'];
				}

				$storage->save($schedule);
			}
			catch (Exception $e) {
				$errors[] = $e->getMessage();
			}
		}

		if ($errors) {
			CMessageHelper::setErrorTitle($scheduleid === '' ? cr_t('Cannot add schedule') : cr_t('Cannot update schedule'));

			foreach ($errors as $error) {
				CMessageHelper::addError($error);
			}

			$response = new CControllerResponseRedirect(
				(new CUrl('zabbix.php'))->setArgument('action', 'customreports.schedule.edit')
			);
			$response->setFormData($this->getInputAll() + ['form_refresh' => 1]);
			$this->setResponse($response);

			return;
		}

		CMessageHelper::setSuccessTitle($scheduleid === '' ? cr_t('Schedule added') : cr_t('Schedule updated'));
		$this->setResponse(new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'customreports.schedule.list')
		));
	}
}
