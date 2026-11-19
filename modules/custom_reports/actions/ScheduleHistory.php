<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CControllerResponseRedirect;
use CMessageHelper;
use CUrl;
use Exception;
use Modules\CustomReports\Lib\ModuleConfig;
use Modules\CustomReports\Lib\ScheduleHelper;
use Modules\CustomReports\Lib\ScheduleStorage;

/**
 * Delivery history of a schedule: the last runs (scheduled, "Send now", "Test to me") with the result for every
 * recipient.
 */
class ScheduleHistory extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

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
			$history = $schedule !== null ? $storage->getHistory($schedule['scheduleid']) : [];
			$state = $schedule !== null ? ($storage->getState()[$schedule['scheduleid']] ?? []) : [];
		}
		catch (Exception $e) {
			$schedule = null;
			CMessageHelper::addError($e->getMessage());
		}

		if ($schedule === null) {
			CMessageHelper::setErrorTitle(_('No permissions to referred object or it does not exist!'));
			$this->setResponse(new CControllerResponseRedirect(
				(new CUrl('zabbix.php'))->setArgument('action', 'customreports.schedule.list')
			));

			return;
		}

		$media_types = API::MediaType()->get([
			'output' => ['name'],
			'mediatypeids' => [$schedule['mediatypeid']]
		]);
		$timezone = ModuleConfig::timezone();

		$response = new CControllerResponseData([
			'schedule' => $schedule,
			'media_type' => $media_types ? $media_types[0]['name'] : null,
			'history' => $history,
			'state' => $state,
			'next_run' => $schedule['enabled'] ? ScheduleHelper::nextOccurrence($schedule, time(), $timezone) : null
		]);
		$response->setTitle(_('Delivery history'));
		$this->setResponse($response);
	}
}
