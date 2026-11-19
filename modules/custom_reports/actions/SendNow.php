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
use Modules\CustomReports\Lib\ReportBuilder;
use Modules\CustomReports\Lib\ReportRenderer;
use Modules\CustomReports\Lib\ReportSplitter;

/**
 * "Send now" on the dashboard: test delivery of the current report to the current user's own media.
 */
class SendNow extends CController {

	protected function checkInput(): bool {
		$fields = [
			'mediatypeid' =>	'required|db media_type.mediatypeid',
			'format' =>			'required|in '.implode(',', ReportRenderer::FORMATS),
			'split' =>			'required|in '.implode(',', ReportSplitter::MODES),
			'skip_empty' =>		'in 0,1',
			'problem_list' =>	'in '.ReportBuilder::LIST_FULL.','.ReportBuilder::LIST_SUMMARY
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
			$split = $this->getInput('split');
			$filter = ['problem_list' => $this->getInput('problem_list', ReportBuilder::LIST_FULL)]
				+ FrontendHelper::getFilter();
			$report = $service->buildReport($filter, $split);
			$result = $service->sendReport($report, $mediatypeid, $sendto, $this->getInput('format'),
				cr_t('Problem report (test)'), $split, (bool) $this->getInput('skip_empty', 0)
			);

			// Failures first: a part without recipients is counted in "failed", but not in "parts".
			if ($result['failed'] == 0 && $result['parts'] == 0) {
				CMessageHelper::setSuccessTitle(cr_t('Nothing to send: no problems match the filter'));
			}
			elseif ($result['failed'] == 0) {
				CMessageHelper::setSuccessTitle(cr_s('Sent %1$s report(s), %2$s message(s) delivered', $result['parts'],
					$result['sent']
				));
			}
			else {
				CMessageHelper::setErrorTitle(cr_s('Report delivery failed for %1$s of %2$s recipient(s)',
					$result['failed'], $result['sent'] + $result['failed']
				));

				foreach ($result['errors'] as $error) {
					CMessageHelper::addError($error);
				}
			}
		}
		catch (Exception $e) {
			CMessageHelper::setErrorTitle(cr_t('Cannot send report'));
			CMessageHelper::addError($e->getMessage());
		}

		$this->setResponse(new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))->setArgument('action', 'customreports.dashboard')
		));
	}
}
