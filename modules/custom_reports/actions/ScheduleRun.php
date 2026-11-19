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
use Modules\CustomReports\Lib\FrontendHelper;
use Modules\CustomReports\Lib\ModuleConfig;
use Modules\CustomReports\Lib\ScheduleHelper;
use Modules\CustomReports\Lib\ScheduleStorage;

/**
 * "Send now" for a schedule: delivers the report to all its recipients immediately.
 * The report is built with the permissions of the current (Super admin) user. The run is added to the delivery
 * history and sets the status of the schedule.
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

	/**
	 * "Test to me" (ScheduleTest) sends the same report only to the current user.
	 */
	protected function isTest(): bool {
		return false;
	}

	protected function doAction(): void {
		$storage = new ScheduleStorage(ModuleConfig::get()['data_dir']);
		$test = $this->isTest();
		$trigger = $test ? ScheduleHelper::RUN_TEST : ScheduleHelper::RUN_MANUAL;
		$user = CWebUser::$data['username'];
		$started = time();
		$schedule = null;

		try {
			$schedule = $storage->get($this->getInput('scheduleid'));

			if ($schedule === null) {
				throw new Exception(cr_t('No permissions to referred object or it does not exist!'));
			}

			$service = FrontendHelper::createService();
			$result = $test
				? $service->testSchedule($schedule, FrontendHelper::getCurrentUserMedia())
				: $service->runSchedule($schedule);
			$run = ScheduleHelper::makeRun($trigger, $started, $result, null, $user);
		}
		catch (Exception $e) {
			if ($schedule === null) {
				CMessageHelper::setErrorTitle(cr_t('Cannot send report'));
				CMessageHelper::addError($e->getMessage());
				$this->redirect();

				return;
			}

			$run = ScheduleHelper::makeRun($trigger, $started, null, $e->getMessage(), $user);
		}

		// Every run goes to the delivery history; only real deliveries change the status of the schedule.
		try {
			$storage->addRun($schedule['scheduleid'], $run);

			if (!$test) {
				$storage->updateState($schedule['scheduleid'], [
					'last_manual_run' => $started,
					'last_status' => $run['status'],
					'last_message' => ScheduleHelper::runSummary($run)
				]);
			}
		}
		catch (Exception $e) {
			CMessageHelper::addError(cr_s('Cannot save the delivery history: %1$s', $e->getMessage()));
		}

		$name = $test ? cr_s('Test of "%1$s"', $schedule['name']) : cr_s('Report "%1$s"', $schedule['name']);

		switch ($run['status']) {
			case ScheduleHelper::STATUS_OK:
				CMessageHelper::setSuccessTitle(cr_s('%1$s: %2$s report(s), %3$s message(s) delivered', $name,
					$run['parts'], $run['sent']
				));
				break;

			case ScheduleHelper::STATUS_EMPTY:
				CMessageHelper::setSuccessTitle(cr_s('%1$s: nothing to send, no problems match the filter', $name));
				break;

			default:
				CMessageHelper::setErrorTitle($run['status'] === ScheduleHelper::STATUS_PARTIAL
					? cr_s('%1$s: delivered partially, %2$s of %3$s message(s) failed', $name, $run['failed'],
						$run['sent'] + $run['failed']
					)
					: cr_s('%1$s: delivery failed', $name)
				);

				if ($run['message'] !== '') {
					CMessageHelper::addError($run['message']);
				}

				foreach ($run['deliveries'] as $delivery) {
					if ($delivery['status'] !== 'sent') {
						CMessageHelper::addError(($delivery['recipient'] !== '' ? $delivery['recipient'].': ' : '').
							($delivery['part'] !== '' ? '['.$delivery['part'].'] ' : '').$delivery['error']
						);
					}
				}
		}

		$this->redirect();
	}

	private function redirect(): void {
		$this->setResponse(new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'customreports.schedule.list')
		));
	}
}
