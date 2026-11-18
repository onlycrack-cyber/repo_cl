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
use Modules\CustomReports\Lib\ReportRenderer;

/**
 * "Send now" on the dashboard: test delivery of the current report to the current user's own media.
 */
class SendNow extends CController {

	protected function checkInput(): bool {
		$fields = [
			'mediatypeid' =>	'required|db media_type.mediatypeid',
			'format' =>			'required|in '.implode(',', ReportRenderer::FORMATS)
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
	}

	protected function doAction(): void {
		$mediatypeid = (string) $this->getInput('mediatypeid');
		$sendto = FrontendHelper::getCurrentUserMedia()[$mediatypeid] ?? [];

		try {
			$service = FrontendHelper::createService();
			$report = $service->buildReport(FrontendHelper::getFilter());
			$result = $service->sendReport($report, $mediatypeid, $sendto, $this->getInput('format'),
				_('Problem report (test)')
			);

			if ($result['failed'] == 0) {
				CMessageHelper::setSuccessTitle(_n('Report sent to %1$s recipient', 'Report sent to %1$s recipients',
					$result['sent']
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
			(new CUrl('zabbix.php'))->setArgument('action', 'customreports.dashboard')
		));
	}
}
