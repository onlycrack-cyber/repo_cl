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
use Modules\CustomReports\Lib\CronManager;
use Modules\CustomReports\Lib\FrontendHelper;
use Modules\CustomReports\Lib\ModuleConfig;

/**
 * Installs or removes the report sender job in the crontab of the web server user.
 */
class CronUpdate extends CController {

	protected function checkInput(): bool {
		$ret = $this->validateInput(['operation' => 'required|in install,remove']);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$cron = new CronManager(ModuleConfig::get());
		$user = CronManager::getCurrentUser();
		$logger = FrontendHelper::createLogger();

		try {
			if ($this->getInput('operation') === 'install') {
				$cron->installUserCrontab();
				CMessageHelper::setSuccessTitle(cr_s('Cron job added to the crontab of user "%1$s"', $user));
				$logger->info(sprintf('Cron job installed for "%s": %s', $user, $cron->getUserCrontabLine()));
			}
			else {
				$cron->removeUserCrontab();
				CMessageHelper::setSuccessTitle(cr_s('Cron job removed from the crontab of user "%1$s"', $user));
				$logger->info(sprintf('Cron job removed for "%s".', $user));
			}
		}
		catch (Exception $e) {
			CMessageHelper::setErrorTitle(cr_t('Cannot update cron job'));
			CMessageHelper::addError($e->getMessage());
			CMessageHelper::addError(cr_s('Add the line to %1$s manually (see the "Cron job" section).',
				CronManager::CRON_D_FILE
			));
			$logger->error('Cron job update failed: '.$e->getMessage());
		}

		$this->setResponse(new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'customreports.schedule.list')
		));
	}
}
