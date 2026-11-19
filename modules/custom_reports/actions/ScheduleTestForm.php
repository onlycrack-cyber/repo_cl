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
use Modules\CustomReports\Lib\FrontendHelper;
use Modules\CustomReports\Lib\ModuleConfig;
use Modules\CustomReports\Lib\ScheduleStorage;

/**
 * "Test": form to choose the recipients of a test delivery of a schedule - me, selected users or addresses through
 * any media type. Sends to customreports.schedule.test.
 */
class ScheduleTestForm extends CController {

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
		try {
			$schedule = (new ScheduleStorage(ModuleConfig::get()['data_dir']))->get($this->getInput('scheduleid'));
		}
		catch (Exception $e) {
			$schedule = null;
		}

		if ($schedule === null) {
			CMessageHelper::setErrorTitle(cr_t('No permissions to referred object or it does not exist!'));
			$this->setResponse(new CControllerResponseRedirect(
				(new CUrl('zabbix.php'))->setArgument('action', 'customreports.schedule.list')
			));

			return;
		}

		$media_types = FrontendHelper::getSupportedMediaTypes();
		$my_media = [];

		foreach (FrontendHelper::getCurrentUserMedia() as $mediatypeid => $sendto) {
			if (array_key_exists($mediatypeid, $media_types)) {
				$my_media[] = $media_types[$mediatypeid].': '.implode(', ', $sendto);
			}
		}

		$response = new CControllerResponseData([
			'schedule' => $schedule,
			'media_types' => $media_types,
			'my_media' => $my_media
		]);
		$response->setTitle(cr_t('Test delivery'));
		$this->setResponse($response);
	}
}
