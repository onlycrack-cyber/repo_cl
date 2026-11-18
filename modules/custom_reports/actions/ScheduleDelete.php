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

class ScheduleDelete extends CController {

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
		try {
			$deleted = (new ScheduleStorage(ModuleConfig::get()['data_dir']))->delete($this->getInput('scheduleid'));

			if ($deleted) {
				CMessageHelper::setSuccessTitle(_('Schedule deleted'));
			}
			else {
				CMessageHelper::setErrorTitle(_('No permissions to referred object or it does not exist!'));
			}
		}
		catch (Exception $e) {
			CMessageHelper::setErrorTitle(_('Cannot delete schedule'));
			CMessageHelper::addError($e->getMessage());
		}

		$this->setResponse(new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'customreports.schedule.list')
		));
	}
}
