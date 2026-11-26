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
use Modules\CustomReports\Lib\ModuleConfig;
use Modules\CustomReports\Lib\ScheduleStorage;

/**
 * Enables / disables a schedule from the list (the "Status" link). POST with the CSRF token.
 * Enabling does not send the occurrences missed while the schedule was disabled (the time of the change counts).
 */
class ScheduleToggle extends CController {

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'scheduleid' => 'required|string|not_empty',
			'enabled' => 'required|in 0,1'
		]);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$enabled = (int) $this->getInput('enabled');

		try {
			$storage = new ScheduleStorage(ModuleConfig::get()['data_dir']);
			$schedule = $storage->get($this->getInput('scheduleid'));

			if ($schedule === null) {
				throw new Exception(cr_t('No permissions to referred object or it does not exist!'));
			}

			$schedule['enabled'] = $enabled;
			$storage->save($schedule);

			CMessageHelper::setSuccessTitle($enabled
				? cr_s('Schedule "%1$s" enabled', $schedule['name'])
				: cr_s('Schedule "%1$s" disabled', $schedule['name'])
			);
		}
		catch (Exception $e) {
			CMessageHelper::setErrorTitle(cr_t('Cannot update schedule'));
			CMessageHelper::addError($e->getMessage());
		}

		$this->setResponse(new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'customreports.schedule.list')
		));
	}
}
