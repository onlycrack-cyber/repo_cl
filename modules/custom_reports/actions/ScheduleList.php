<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Actions;

use API;
use CController;
use CControllerResponseData;
use Exception;
use Modules\CustomReports\Lib\CronManager;
use Modules\CustomReports\Lib\ModuleConfig;
use Modules\CustomReports\Lib\ScheduleStorage;

/**
 * Tab 2: list of report schedules.
 */
class ScheduleList extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$config = ModuleConfig::get();
		$storage = new ScheduleStorage($config['data_dir']);
		$error = null;

		try {
			$schedules = $storage->getAll();
			$state = $storage->getState();
		}
		catch (Exception $e) {
			$schedules = [];
			$state = [];
			$error = $e->getMessage();
		}

		uasort($schedules, static function (array $a, array $b): int {
			return strnatcasecmp($a['name'], $b['name']);
		});

		$mediatypeids = array_values(array_unique(array_column($schedules, 'mediatypeid')));
		$media_types = $mediatypeids
			? API::MediaType()->get([
				'output' => ['name'],
				'mediatypeids' => $mediatypeids,
				'preservekeys' => true
			])
			: [];

		$cron = new CronManager($config);

		try {
			$cron_user_installed = $cron->isUserCrontabInstalled();
			$cron_error = null;
		}
		catch (Exception $e) {
			$cron_user_installed = false;
			$cron_error = $e->getMessage();
		}

		$response = new CControllerResponseData([
			'cron' => [
				'user' => CronManager::getCurrentUser(),
				'heartbeat' => $cron->getHeartbeat(),
				'user_installed' => $cron_user_installed,
				'cron_d_installed' => $cron->isCronDInstalled(),
				'cron_d_file' => CronManager::CRON_D_FILE,
				'cron_d_line' => $cron->getCronDLine(),
				'error' => $cron_error,
				'zabbix_status_host' => $config['zabbix_status_host']
			],
			'schedules' => $schedules,
			'state' => $state,
			'media_types' => $media_types,
			'timezone' => ModuleConfig::timezone(),
			'timezone_problem' => ModuleConfig::timezoneProblem(),
			'data_dir' => $config['data_dir'],
			'error' => $error
		]);
		$response->setTitle(_('Report schedules'));
		$this->setResponse($response);
	}
}
