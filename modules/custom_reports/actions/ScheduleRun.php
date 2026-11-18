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
use Exception;
use Modules\CustomReports\Lib\FrontendHelper;
use Modules\CustomReports\Lib\ModuleConfig;
use Modules\CustomReports\Lib\ScheduleStorage;

/**
 * "Send now" for a schedule: delivers the report to all its recipients immediately.
 * The report is built with the permissions of the current (Super admin) user.
 */
class ScheduleRun extends CController {

	protected function checkInput(): bool {
		$ret = $this->validateInput(['scheduleid' => 'required|string|not_empty']);

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

		try {
			$schedule = $storage->get($this->getInput('scheduleid'));

			if ($schedule === null) {
				throw new Exception(_('No permissions to referred object or it does not exist!'));
			}

			$result = FrontendHelper::createService()->runSchedule($schedule);

			// Manual runs are recorded too, but do not move "last_run" of the schedule.
			$storage->updateState($schedule['scheduleid'], [
				'last_manual_run' => time(),
				'last_status' => $result['failed'] == 0 ? 'ok' : 'error',
				'last_message' => $result['errors']
					? implode('; ', $result['errors'])
					: _s('Reports: %1$s, messages sent: %2$s', $result['parts'], $result['sent'])
			]);

			if ($result['parts'] == 0) {
				CMessageHelper::setSuccessTitle(_s('Report "%1$s": nothing to send, no problems match the filter',
					$schedule['name']
				));
			}
			elseif ($result['failed'] == 0) {
				CMessageHelper::setSuccessTitle(_s('Report "%1$s": %2$s report(s), %3$s message(s) delivered',
					$schedule['name'], $result['parts'], $result['sent']
				));
			}
			else {
				CMessageHelper::setErrorTitle(_s('Report delivery failed for %1$s of %2$s recipient(s)',
					$result['failed'], $result['sent'] + $result['failed']
				));

				foreach ($result['errors'] as $error) {
					CMessageHelper::addError($error);
				}
			}
		}
		catch (Exception $e) {
			CMessageHelper::setErrorTitle(_('Cannot send report'));
			CMessageHelper::addError($e->getMessage());
		}

		$this->setResponse(new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'customreports.schedule.list')
		));
	}
}
